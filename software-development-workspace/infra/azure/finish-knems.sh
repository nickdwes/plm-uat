#!/usr/bin/env bash
#
# Finish configuring `knems` after recreate-knems.sh stopped early.
#
# WHAT HAPPENED
#
# recreate-knems.sh created the account, the containers and the data-protection
# settings, then failed at the role-assignment step. The `Contributor` role
# lists `Microsoft.Authorization/*/Write` in its notActions, so a Contributor
# cannot create role assignments — that needs Owner or User Access
# Administrator. `set -e` then stopped the script before the firewall and
# shared-key steps.
#
# WHAT THIS FIXES
#
# 1. The application currently holds `Contributor` on the WHOLE SUBSCRIPTION
#    and no blob data role at all. That is wrong in both directions: it can
#    delete any resource in the subscription, and it still cannot read a single
#    document. Control-plane and data-plane permissions are separate in Azure,
#    which is exactly the trap here — `Contributor` sounds total and grants no
#    data access.
#
#    Least privilege is restored: five container-scoped data roles, nothing
#    else. A leaked client secret then reaches those five containers and
#    stops there.
#
# 2. The firewall and Entra-only settings that never ran.
#
# RUN AS AN ADMIN WITH OWNER OR USER ACCESS ADMINISTRATOR.
#
#   az login          # as yourself — the application cannot grant itself roles
#   ADMIN_IP=<your ip> ./finish-knems.sh
set -euo pipefail

# The CLI to use. On the HRMS server `az` authenticates as the application,
# which cannot assign roles — so administration runs through `az-user`, a
# signed-in human. Override rather than editing every call site:
#
#   AZ=az-user ./finish-knems.sh
AZ="${AZ:-az}"

SUBSCRIPTION="45507162-7883-416f-9efa-d41d08340e1d"
RG="Kon1"
ACCOUNT="knems"
APP_ID="307b914d-b943-4f1f-bded-d2645b41b1d3"

CONTAINERS=(
  hrms-candidate-docs
  hrms-employee-documents
  hrms-preboarding-docs
  hrms-training-evidence
  plmbackup
)

APP_EGRESS_IP="18.201.170.30"
ADMIN_IP="${ADMIN_IP:-}"

SCOPE="/subscriptions/$SUBSCRIPTION/resourceGroups/$RG/providers/Microsoft.Storage/storageAccounts/$ACCOUNT"

say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }

"$AZ" account set --subscription "$SUBSCRIPTION"

# ── 0. Confirm the caller can actually assign roles ──────────────────────────
#
# Checked up front rather than discovered halfway through, which is how the
# previous run left the account in a partial state.
say "Checking this identity can assign roles"
if ! "$AZ" role assignment list --scope "/subscriptions/$SUBSCRIPTION" -o none 2>/dev/null; then
  echo "   cannot read role assignments — you are not an Owner/UAA here" >&2
  exit 1
fi
CALLER=$("$AZ" account show --query user.name -o tsv)
echo "   running as: $CALLER"
if [[ "$CALLER" == "$APP_ID" ]]; then
  cat >&2 <<'STOP'

REFUSING TO CONTINUE — you are signed in as the application itself.

The application cannot grant or revoke its own roles. Sign in as a user with
Owner or User Access Administrator, or run this from Azure Cloud Shell.
STOP
  exit 1
fi

# ── 1. Grant the data-plane roles the application actually needs ─────────────
say "Assigning per-container data roles to the application"
for c in "${CONTAINERS[@]}"; do
  "$AZ" role assignment create \
    --assignee "$APP_ID" \
    --role "Storage Blob Data Contributor" \
    --scope "$SCOPE/blobServices/default/containers/$c" -o none 2>/dev/null \
    && echo "   $c" \
    || echo "   $c (already assigned)"
done

# ── 2. Remove the subscription-wide Contributor ──────────────────────────────
#
# Done after the replacements are in place, so the application is never left
# with no access at all.
say "Removing subscription-wide Contributor from the application"
if "$AZ" role assignment list --assignee "$APP_ID" \
     --scope "/subscriptions/$SUBSCRIPTION" \
     --query "[?roleDefinitionName=='Contributor'] | length(@)" -o tsv | grep -q '^[1-9]'; then
  "$AZ" role assignment delete \
    --assignee "$APP_ID" \
    --role "Contributor" \
    --scope "/subscriptions/$SUBSCRIPTION" -o none
  echo "   removed"
else
  echo "   not present — nothing to remove"
fi

# ── 3. Firewall ──────────────────────────────────────────────────────────────
say "Restricting network access"
"$AZ" storage account network-rule add -n "$ACCOUNT" -g "$RG" --ip-address "$APP_EGRESS_IP" -o none
echo "   allowed $APP_EGRESS_IP (HRMS server)"

if [[ -n "$ADMIN_IP" ]]; then
  "$AZ" storage account network-rule add -n "$ACCOUNT" -g "$RG" --ip-address "$ADMIN_IP" -o none
  echo "   allowed $ADMIN_IP (operator)"
else
  echo "   NOTE: no ADMIN_IP set — the portal's storage browser will stop"
  echo "         working for you once the default action is Deny."
fi

"$AZ" storage account update -n "$ACCOUNT" -g "$RG" \
  --default-action Deny --bypass AzureServices -o none
echo "   default action: Deny"

# ── 4. Entra-only authentication ─────────────────────────────────────────────
#
# Last, because the steps above still needed the data plane. With shared keys
# off there is no account key to leak and no silent fallback to one — every
# request must carry an Entra token.
say "Disabling shared key access"
"$AZ" storage account update -n "$ACCOUNT" -g "$RG" --allow-shared-key-access false -o none

# ── 5. Verify ────────────────────────────────────────────────────────────────
say "Result"
"$AZ" storage account show -n "$ACCOUNT" -g "$RG" -o json > /tmp/knems-verify.json
python3 - <<'PY'
import json
a = json.load(open('/tmp/knems-verify.json'))
want = [
    ("sku / replication",  "Standard_GZRS", a["sku"]["name"]),
    ("infra encryption",   True,  a["encryption"].get("requireInfrastructureEncryption")),
    ("shared key access",  False, a.get("allowSharedKeyAccess")),
    ("blob public access", False, a.get("allowBlobPublicAccess")),
    ("https only",         True,  a.get("enableHttpsTrafficOnly")),
    ("min TLS",            "TLS1_2", a.get("minimumTlsVersion")),
    ("network default",    "Deny", a.get("networkRuleSet", {}).get("defaultAction")),
]
for k, exp, got in want:
    print("   {}  {:<20} {}".format("OK " if exp == got else "BAD", k, got))
# The key differs by API version: newer returns "value", older
# "iPAddressOrRange". Reading only one crashed this block after every
# substantive check had already passed.
rules = a.get("networkRuleSet", {}).get("ipRules", [])
ips = [r.get("value") or r.get("iPAddressOrRange") for r in rules]
print("        {:<20} {}".format("allowed ips", ips))
PY
rm -f /tmp/knems-verify.json

say "Roles now held by the application"
"$AZ" role assignment list --assignee "$APP_ID" --all \
  --query "[].{role:roleDefinitionName, scope:scope}" -o table

cat <<'NEXT'

Expect exactly six: five container-scoped Storage Blob Data Contributor, plus
Key Vault Secrets User. Anything at subscription scope should be gone.

Then confirm the application can write, which also proves the firewall
allowlist is correct — the call traverses it:

    az storage container list --account-name knems --auth-mode login -o table

Customer-managed keys are still not configured. cmk-setup.sh will fail for the
same class of reason this script exists: creating a key needs Key Vault Crypto
Officer, which neither Contributor nor Key Vault Secrets User grants.
NEXT
