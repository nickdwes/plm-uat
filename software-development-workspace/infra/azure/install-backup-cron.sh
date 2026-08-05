#!/usr/bin/env bash
#
# Schedule the nightly database backup.
#
# Installed as a user crontab rather than /etc/cron.d because the backup needs
# the invoking user's Docker access, and the `az` wrapper resolves credentials
# from a file owned by that user.
#
# Runs at 02:15 UTC — after midnight batch work, before the working day in
# East Africa (05:15 EAT).
#
# --verify is deliberately on. A restore test costs a few seconds on a database
# this size, and a backup nobody has restored is not a backup. When the
# database grows enough for that to hurt, reduce the frequency of verification
# rather than dropping it: an unverified nightly backup is worth less than a
# verified weekly one.
set -euo pipefail

SCRIPT="/home/ubuntu/software-development-workspace/infra/azure/backup-database.sh"
LOG="/home/ubuntu/backups/backup.log"
SCHEDULE="15 2 * * *"

[[ -x "$SCRIPT" ]] || { echo "not executable: $SCRIPT" >&2; exit 1; }

# Cron runs with a minimal PATH that excludes /usr/local/bin, where `az` lives.
ENTRY="${SCHEDULE} PATH=/usr/local/bin:/usr/bin:/bin ${SCRIPT} --verify >> ${LOG} 2>&1"

CURRENT="$(crontab -l 2>/dev/null || true)"

if grep -Fq "$SCRIPT" <<<"$CURRENT"; then
  echo "Already scheduled:"
  grep -F "$SCRIPT" <<<"$CURRENT" | sed 's/^/  /'
  exit 0
fi

printf '%s\n%s\n' "$CURRENT" "$ENTRY" | grep -v '^$' | crontab -

echo "Scheduled:"
crontab -l | grep -F "$SCRIPT" | sed 's/^/  /'
cat <<'NEXT'

The log is at /home/ubuntu/backups/backup.log and nothing rotates it, so check
it occasionally or add it to logrotate.

Nothing here alerts on failure. Cron will email a failed run to the local user,
which on this box means nowhere anyone reads. Until that is wired to something
you actually see, a silently failing backup looks exactly like a working one —
so verify periodically that last night's dump is in the container:

    az storage blob list --account-name knems --auth-mode login \
      -c hrms-db-backups --prefix hrms/ --query "[].name" -o tsv | tail -5
NEXT
