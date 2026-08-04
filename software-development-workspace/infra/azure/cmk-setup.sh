#!/usr/bin/env bash
#
# Encrypt the `knems` storage account with a key you control, held in the
# `hrms` key vault.
#
# WHAT THIS BUYS
#
# Azure already encrypts everything at rest with Microsoft-managed keys, so
# this is not the difference between encrypted and not. What it changes is who
# holds the key. With a customer-managed key you can rotate it on your own
# schedule, you get an audit trail of every use, and — the part that matters
# for a breach — you can REVOKE it. Revoking renders the stored data
# unreadable, including to Microsoft. For employee passports and payroll that
# is an actual incident-response control rather than a compliance checkbox.
#
# READ THIS BEFORE RUNNING
#
# Customer-managed keys require purge protection on the vault, and purge
# protection CANNOT BE TURNED OFF. Once enabled, neither you nor Microsoft can
# permanently delete the vault or its keys until the retention period expires
# — 90 days by default. That is the point: it stops an attacker (or a mistake)
# destroying the key and taking the data with it. But it is a one-way door,
# and a vault you cannot delete is a vault you keep paying for and managing.
#
# The other failure mode worth understanding: if the key becomes unavailable —
# deleted, disabled, or the account loses access to it — the storage account
# stops serving data until it is restored. Encryption with a key you control
# means outages you can cause.
#
# Run as an admin, after recreate-knems.sh.
set -euo pipefail

# The CLI to use. On the HRMS server `az` authenticates as the application,
# which cannot assign roles — so administration runs through `az-user`, a
# signed-in human. Override rather than editing every call site:
#
#   AZ=az-user ./cmk-setup.sh
AZ="${AZ:-az}"

SUBSCRIPTION="45507162-7883-416f-9efa-d41d08340e1d"
RG="Kon1"
ACCOUNT="knems"
VAULT="hrms"
KEY_NAME="knems-cmk"

say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }

"$AZ" account set --subscription "$SUBSCRIPTION"

# ── 1. Confirm the operator understands the one-way door ─────────────────────
say "Purge protection"
CURRENT=$("$AZ" keyvault show --name "$VAULT" \
            --query "properties.enablePurgeProtection" -o tsv 2>/dev/null || echo "")

if [[ "$CURRENT" == "true" ]]; then
  echo "   already enabled"
else
  cat <<'WARN'
   Purge protection is currently OFF and must be ON for customer-managed keys.

   Enabling it is PERMANENT. The vault and its keys cannot be purged before
   the retention period expires, even by Microsoft, even by you.
WARN
  read -r -p "   Type ENABLE to proceed: " reply
  [[ "$reply" == "ENABLE" ]] || { echo "   aborted"; exit 1; }

  "$AZ" keyvault update --name "$VAULT" --enable-purge-protection true -o none
  echo "   enabled"
fi

# ── 2. The key ───────────────────────────────────────────────────────────────
#
# RSA 3072 rather than 2048: this key protects records with statutory retention
# measured in years, and it is no more expensive to hold a larger one.
say "Creating the key"
if "$AZ" keyvault key show --vault-name "$VAULT" --name "$KEY_NAME" -o none 2>/dev/null; then
  echo "   $KEY_NAME already exists — reusing"
else
  "$AZ" keyvault key create --vault-name "$VAULT" --name "$KEY_NAME" \
    --kty RSA --size 3072 --ops wrapKey unwrapKey -o none
  echo "   created $KEY_NAME"
fi

# ── 3. Let the storage account read the key ──────────────────────────────────
#
# This is the identity that trips people up. The grant belongs to the STORAGE
# ACCOUNT's own managed identity, not to the application service principal and
# not to you. The application never touches the encryption key — it asks for
# blobs, and the platform decrypts them on its behalf.
say "Granting the storage account's managed identity access to the key"
PRINCIPAL=$("$AZ" storage account show -n "$ACCOUNT" -g "$RG" \
              --query "identity.principalId" -o tsv)

if [[ -z "$PRINCIPAL" || "$PRINCIPAL" == "null" ]]; then
  echo "   the account has no managed identity; assigning one" >&2
  "$AZ" storage account update -n "$ACCOUNT" -g "$RG" --assign-identity -o none
  PRINCIPAL=$("$AZ" storage account show -n "$ACCOUNT" -g "$RG" \
                --query "identity.principalId" -o tsv)
fi
echo "   principal: $PRINCIPAL"

# The vault uses RBAC (enableRbacAuthorization = true), so this is a role
# assignment. On an access-policy vault it would be `az keyvault set-policy`
# instead, and a role assignment would appear to succeed while granting nothing.
"$AZ" role assignment create \
  --assignee-object-id "$PRINCIPAL" \
  --assignee-principal-type ServicePrincipal \
  --role "Key Vault Crypto Service Encryption User" \
  --scope "/subscriptions/$SUBSCRIPTION/resourceGroups/$RG/providers/Microsoft.KeyVault/vaults/$VAULT" \
  -o none 2>/dev/null || echo "   (role already assigned)"

echo "   waiting for the assignment to propagate"
sleep 45

# ── 4. Switch the account over ───────────────────────────────────────────────
#
# No key VERSION is pinned, so Azure follows the current version automatically.
# Pinning a version means every rotation requires updating the account, and an
# account still pointing at a retired key is a silent outage waiting to happen.
say "Pointing $ACCOUNT at the customer-managed key"
VAULT_URI=$("$AZ" keyvault show --name "$VAULT" --query "properties.vaultUri" -o tsv)

"$AZ" storage account update -n "$ACCOUNT" -g "$RG" \
  --encryption-key-source Microsoft.Keyvault \
  --encryption-key-vault "$VAULT_URI" \
  --encryption-key-name "$KEY_NAME" \
  -o none

# ── 5. Verify ────────────────────────────────────────────────────────────────
say "Result"
"$AZ" storage account show -n "$ACCOUNT" -g "$RG" \
  --query "{keySource:encryption.keySource, keyName:encryption.keyVaultProperties.keyName, keyVault:encryption.keyVaultProperties.keyVaultUri, autoRotation:encryption.keyVaultProperties.currentVersionedKeyIdentifier}" \
  -o json

cat <<'NEXT'

Confirm the application still works — encryption changes are transparent when
correct and total when not:

    az storage container list --account-name knems --auth-mode login -o table

If that fails, the key grant has not propagated or the role went to the wrong
identity. Check that the assignee is the STORAGE ACCOUNT's principal id above,
not the application's.
NEXT
