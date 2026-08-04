#!/usr/bin/env bash
#
# Recreate the `knems` storage account with the settings that can only be
# chosen at creation, then re-apply everything that is lost with the account.
#
# WHY THIS EXISTS
#
# Two properties cannot be changed after an account is created:
#
#   * infrastructure encryption — a second, independent AES-256 layer beneath
#     the standard one. Off today.
#   * replication — the account is Standard_LRS, meaning three copies inside a
#     single datacentre. Losing that facility loses every HR record.
#
# Everything else here could be set in place. These two cannot, which is the
# whole reason for deleting and recreating rather than updating. Doing it while
# the account is empty costs minutes; doing it once employee documents are
# stored costs a migration with downtime.
#
# RUN THIS AS AN ADMIN, NOT AS THE APPLICATION.
#
# The `plmv2 dev` service principal holds data-plane roles only, by design. It
# cannot delete or create accounts, and it cannot grant roles. Sign in as a
# user with Owner or User Access Administrator on the subscription:
#
#   az login
#   ./recreate-knems.sh
#
# The script refuses to run if any container holds data.
set -euo pipefail

# The CLI to use. On the HRMS server `az` authenticates as the application,
# which cannot assign roles — so administration runs through `az-user`, a
# signed-in human. Override rather than editing every call site:
#
#   AZ=az-user ./recreate-knems.sh
AZ="${AZ:-az}"

# ── Settings ─────────────────────────────────────────────────────────────────
SUBSCRIPTION="45507162-7883-416f-9efa-d41d08340e1d"
RG="Kon1"
ACCOUNT="knems"
LOCATION="southafricanorth"
VAULT="hrms"

# The application that reads and writes documents.
APP_ID="307b914d-b943-4f1f-bded-d2645b41b1d3"

# Containers, recreated in this order. Keep `plmbackup` — it predates the HRMS
# work and is not ours to drop.
CONTAINERS=(
  hrms-candidate-docs
  hrms-employee-documents
  hrms-preboarding-docs
  hrms-training-evidence
  plmbackup
)

# The HRMS server's egress address. Confirmed to be an Elastic IP, so it
# survives stop/start — an ephemeral address here would break uploads silently
# the first time the instance restarted.
APP_EGRESS_IP="18.201.170.30"

# Your own address, so the portal's storage browser keeps working once the
# firewall denies by default. Without it the portal reports what looks like a
# permissions failure, and the usual reaction is to start changing roles that
# were never wrong. Leave empty to skip.
ADMIN_IP="${ADMIN_IP:-}"

say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }

# ── 0. Refuse to destroy data ────────────────────────────────────────────────
say "Checking every container is empty"
"$AZ" account set --subscription "$SUBSCRIPTION"

TOTAL=0
for c in "${CONTAINERS[@]}"; do
  n=$("$AZ" storage blob list --account-name "$ACCOUNT" --auth-mode login \
        -c "$c" --query "length(@)" -o tsv 2>/dev/null || echo 0)
  printf '   %-26s %s blob(s)\n' "$c" "$n"
  TOTAL=$(( TOTAL + n ))
done

if [[ "$TOTAL" -ne 0 ]]; then
  cat >&2 <<'STOP'

REFUSING TO CONTINUE — the account holds data.

Deleting it would destroy those blobs permanently. If the data is expendable,
remove it deliberately and re-run. If it is not, the account must be migrated
rather than recreated, and infrastructure encryption is no longer available
without copying every blob to a new account.
STOP
  exit 1
fi
echo "   nothing stored — safe to recreate"

# ── 1. Delete ────────────────────────────────────────────────────────────────
say "Deleting $ACCOUNT"
"$AZ" storage account delete -n "$ACCOUNT" -g "$RG" --yes

# The name is globally unique and is not always released immediately.
say "Waiting for the name to become available again"
for i in $(seq 1 30); do
  free=$("$AZ" storage account check-name --name "$ACCOUNT" --query nameAvailable -o tsv)
  [[ "$free" == "true" ]] && { echo "   available after ${i}0s"; break; }
  sleep 10
done

# ── 2. Recreate with the settings that cannot be changed later ───────────────
say "Creating $ACCOUNT (GZRS + infrastructure encryption)"
"$AZ" storage account create \
  -n "$ACCOUNT" -g "$RG" -l "$LOCATION" \
  --sku Standard_GZRS \
  --kind StorageV2 \
  --require-infrastructure-encryption \
  --encryption-services blob file \
  --min-tls-version TLS1_2 \
  --https-only true \
  --allow-blob-public-access false \
  --assign-identity \
  --public-network-access Enabled \
  -o none

# Shared key access stays ON for now: containers are created over the data
# plane, and turning it off before the admin has a data role would lock this
# script out of its own next step. It is disabled at the end.

# ── 3. Give the operator a data-plane role ───────────────────────────────────
#
# Owner does NOT imply the ability to read or write blobs. The management and
# data planes are separate, which is the single most common surprise here:
# a subscription Owner sees "permission denied" listing a container and
# concludes the account is broken.
say "Granting the signed-in operator a data-plane role"
ME=$("$AZ" ad signed-in-user show --query id -o tsv 2>/dev/null || true)
SCOPE="/subscriptions/$SUBSCRIPTION/resourceGroups/$RG/providers/Microsoft.Storage/storageAccounts/$ACCOUNT"

if [[ -n "$ME" ]]; then
  "$AZ" role assignment create --assignee-object-id "$ME" \
    --assignee-principal-type User \
    --role "Storage Blob Data Contributor" --scope "$SCOPE" -o none 2>/dev/null || true
  echo "   granted; waiting for RBAC to propagate"
  sleep 45
else
  echo "   could not resolve signed-in user (service principal?) — container"
  echo "   creation below may fail until a data role is assigned"
fi

# ── 4. Containers ────────────────────────────────────────────────────────────
say "Creating containers"
for c in "${CONTAINERS[@]}"; do
  for attempt in 1 2 3 4 5; do
    if "$AZ" storage container create --account-name "$ACCOUNT" --auth-mode login \
         -n "$c" --public-access off -o none 2>/dev/null; then
      echo "   $c"
      break
    fi
    # Fresh role assignments take a little while to be honoured.
    sleep 20
    [[ $attempt -eq 5 ]] && { echo "   FAILED: $c" >&2; exit 1; }
  done
done

# ── 5. Data protection ───────────────────────────────────────────────────────
#
# Soft delete covers deletion; versioning covers overwrite, which is the more
# likely accident with documents — a corrected upload silently replacing the
# original leaves nothing to recover.
say "Enabling versioning and soft delete"
"$AZ" storage account blob-service-properties update \
  --account-name "$ACCOUNT" -g "$RG" \
  --enable-versioning true \
  --enable-delete-retention true --delete-retention-days 30 \
  --enable-container-delete-retention true --container-delete-retention-days 30 \
  -o none

# ── 6. Re-apply the application's roles ──────────────────────────────────────
#
# Role assignments are scoped to the container resources, so deleting the
# account deleted them. Per-container scoping is deliberate: a compromise of
# the application reaches these five containers and nothing else.
say "Re-applying per-container roles for the application"
for c in "${CONTAINERS[@]}"; do
  "$AZ" role assignment create \
    --assignee "$APP_ID" \
    --role "Storage Blob Data Contributor" \
    --scope "$SCOPE/blobServices/default/containers/$c" -o none
  echo "   $c"
done

# ── 7. Firewall ──────────────────────────────────────────────────────────────
say "Restricting network access"
"$AZ" storage account network-rule add -n "$ACCOUNT" -g "$RG" --ip-address "$APP_EGRESS_IP" -o none
echo "   allowed $APP_EGRESS_IP (HRMS server)"

if [[ -n "$ADMIN_IP" ]]; then
  "$AZ" storage account network-rule add -n "$ACCOUNT" -g "$RG" --ip-address "$ADMIN_IP" -o none
  echo "   allowed $ADMIN_IP (operator)"
else
  echo "   NOTE: no ADMIN_IP set — the portal's storage browser will stop"
  echo "         working for you. Re-run with ADMIN_IP=<your ip> to add it."
fi

"$AZ" storage account update -n "$ACCOUNT" -g "$RG" \
  --default-action Deny --bypass AzureServices -o none
echo "   default action set to Deny (Azure services still permitted)"

# ── 8. Entra-only authentication ─────────────────────────────────────────────
#
# Last, because everything above needed the data plane. With shared keys off
# there is no account key to leak, and no fallback to one: every request must
# carry an Entra token, which is the point of the whole arrangement.
say "Disabling shared key access"
"$AZ" storage account update -n "$ACCOUNT" -g "$RG" --allow-shared-key-access false -o none

# ── 9. Verify ────────────────────────────────────────────────────────────────
say "Result"
"$AZ" storage account show -n "$ACCOUNT" -g "$RG" -o json | python3 -c '
import json, sys
a = json.load(sys.stdin)
want = {
    "sku / replication":   ("Standard_GZRS", a["sku"]["name"]),
    "infra encryption":    (True,  a["encryption"].get("requireInfrastructureEncryption")),
    "shared key access":   (False, a.get("allowSharedKeyAccess")),
    "blob public access":  (False, a.get("allowBlobPublicAccess")),
    "https only":          (True,  a.get("enableHttpsTrafficOnly")),
    "min TLS":             ("TLS1_2", a.get("minimumTlsVersion")),
    "network default":     ("Deny", a.get("networkRuleSet", {}).get("defaultAction")),
    "managed identity":    ("SystemAssigned", (a.get("identity") or {}).get("type")),
}
bad = 0
for k, (exp, got) in want.items():
    ok = exp == got
    bad += not ok
    print(f"   {'OK ' if ok else 'BAD'}  {k:<20} {got}")
ips = [r["value"] for r in a.get("networkRuleSet", {}).get("ipRules", [])]
pid = (a.get("identity") or {}).get("principalId")
print("        {:<20} {}".format("allowed ips", ips))
print("        {:<20} {}".format("principal id", pid))
sys.exit(1 if bad else 0)
'

say "Done — the application must be re-verified before use"
cat <<'NEXT'
Confirm the app can still reach the containers:

    "$AZ" storage container list --account-name knems --auth-mode login -o table

That call now traverses the firewall, so it also proves the allowlist is right.
If it fails, the likely cause is RBAC propagation — wait a minute and retry
before changing anything.

Customer-managed keys are NOT configured here. See cmk-setup.sh: it requires
enabling purge protection on the vault, which is irreversible, so it is a
deliberate separate step rather than a side effect of this script.
NEXT
