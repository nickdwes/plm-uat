# Security runbook

Deferred to the data-migration window, deliberately. Every item here touches
either the storage account or the encryption keys, and doing that while the
legacy book is being imported means one change window rather than two, with the
backups already verified because the import demanded it.

Nothing in this file has been executed. Each step says how to check it worked
and how to undo it.

---

## 0. Before anything — the document encryption key

**Do this first.** Documents written from the encryption change onward are
sealed with `DOCUMENT_ENCRYPTION_KEY`. It is not in Key Vault yet.

```bash
# Generate
docker compose exec backend php artisan documents:key

# Store it
security/az keyvault secret set \
  --vault-name kv-knems \
  --name document-encryption-key \
  --value 'base64:…'
```

Then set it in the backend environment and redeploy.

**Why not APP_KEY:** APP_KEY gets rotated to invalidate sessions and signed
URLs. Doing that must not make six years of contracts unreadable.

**Losing this key loses the documents.** There is no recovery path — that is
what makes the encryption worth having. Confirm the secret reads back before
uploading anything real:

```bash
security/az keyvault secret show --vault-name kv-knems --name document-encryption-key --query value -o tsv
```

### 0b. Convert what is already stored

Files uploaded before the change are plaintext and are served as-is, so nothing
breaks. Convert them once the key is in place:

```bash
docker compose exec backend php artisan documents:encrypt-existing --dry-run
docker compose exec backend php artisan documents:encrypt-existing
```

It rewrites in place, so **take a storage snapshot first** (§3). It is
idempotent and safe to interrupt — already-sealed files are skipped.

---

## 1. Retention lifecycle — `infra/azure/apply-lifecycle.sh`

Six-month expiry on candidate CVs. Safe now that hired candidates' CVs are
archived out of that container.

```bash
security/az storage account management-policy show \
  --account-name knems --query 'policy.rules[].name' -o tsv   # what is there now
bash infra/azure/apply-lifecycle.sh
```

**Verify:** re-run the `show` above; the rule should be listed. Azure evaluates
lifecycle daily, so nothing is deleted immediately — there is a day to change
your mind.

**Undo:** `security/az storage account management-policy delete --account-name knems`

**Watch for:** the rule matches by container and prefix. If CV storage moves,
the rule silently stops matching and stops expiring anything. It fails open.

---

## 2. `ADMIN_IP` in the storage firewall

Currently the **server's** IP, not yours. That means the firewall permits the
box that already has a managed identity, and does not permit you — so it adds
no protection and blocks your own `az storage` calls.

```bash
curl -s https://ifconfig.me                       # your actual address
security/az storage account network-rule list --account-name knems -o table
security/az storage account network-rule add --account-name knems --ip-address <yours>
security/az storage account network-rule remove --account-name knems --ip-address <server-ip>
```

**Do not remove the server rule until the app is confirmed working through its
managed identity** — otherwise uploads start failing. Check with an upload
through the UI before removing.

**If you lock yourself out:** the rule can be removed from the Azure portal,
which is not subject to the storage firewall.

---

## 3. Backups — the dead man's switch

`backup:check` currently runs on the same box it is checking. If that box dies,
nothing reports the backups stopped — which is the failure it exists to catch.

Two options, in order of preference:

1. **Healthchecks.io (or equivalent) ping.** The backup script pings a URL on
   success; the service alerts if the ping does not arrive. Off-box, free, five
   minutes' work. Add to `infra/azure/backup-database.sh` after a successful
   upload:
   ```bash
   curl -fsS -m 10 --retry 3 "$HEALTHCHECK_URL" > /dev/null
   ```

2. **Azure Monitor alert on blob count** in `hrms-db-backups` — no new
   dependency, but it is the same subscription, so a subscription-level problem
   silences both.

**Verify either way by breaking it on purpose:** disable the cron for a day and
confirm you are told.

---

## 4. Customer-managed keys — `infra/azure/cmk-setup.sh`

Blocked: it needs an identity holding **Key Vault Crypto Officer** on
`kv-knems`, which the current service principal does not have.

```bash
security/az role assignment create \
  --role "Key Vault Crypto Officer" \
  --assignee <object-id> \
  --scope /subscriptions/<sub>/resourceGroups/<rg>/providers/Microsoft.KeyVault/vaults/kv-knems
bash infra/azure/cmk-setup.sh
```

**Honest note on value:** with §0 in place, application-level encryption is
already protecting the documents, and CMK protects the same bytes a second time
with a key you rotate instead of Microsoft. It is worth doing for compliance
answers ("do you control the keys") more than for threat reduction. **Sequence
it last.**

---

## 5. Recommended order in the migration window

1. §0 key into Key Vault, deploy, verify an upload round-trips.
2. §3 backup ping — before touching anything else, so you are covered.
3. Take a storage snapshot.
4. §0b convert existing documents.
5. Import the legacy book.
6. §2 firewall correction.
7. §1 lifecycle policy.
8. §4 CMK, once the role assignment exists.

Steps 1–4 are prerequisites for the import: converting documents after an
import means converting far more of them.
