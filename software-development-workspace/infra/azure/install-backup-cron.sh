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

# Independent freshness check, four hours after the backup window.
#
# The backup alerts when it fails. It cannot alert when it never runs — cron
# disabled, script moved, Docker down — and "never ran" produces exactly the
# same silence as "nothing to report". This check asks the only question that
# matters: is last night's dump actually in the container?
CHECK_SCHEDULE="0 6 * * *"

# Weekly confirmation that the alerting itself still works. Alerting that never
# fires is indistinguishable from alerting that is broken; if this stops
# arriving, nobody is watching the backups any more.
HEARTBEAT_SCHEDULE="0 7 * * 1"

[[ -x "$SCRIPT" ]] || { echo "not executable: $SCRIPT" >&2; exit 1; }

# Cron runs with a minimal PATH that excludes /usr/local/bin, where `az` lives.
ARTISAN="docker exec hrms-backend php artisan"

ENTRIES=(
  "${SCHEDULE} PATH=/usr/local/bin:/usr/bin:/bin ${SCRIPT} --verify >> ${LOG} 2>&1"
  "${CHECK_SCHEDULE} ${ARTISAN} backup:check >> ${LOG} 2>&1"
  "${HEARTBEAT_SCHEDULE} ${ARTISAN} backup:check --heartbeat >> ${LOG} 2>&1"
)

CURRENT="$(crontab -l 2>/dev/null || true)"

NEW="$CURRENT"
ADDED=0

for entry in "${ENTRIES[@]}"; do
  # Match on the command rather than the whole line, so adjusting a schedule
  # replaces the entry instead of installing a second one that also runs.
  cmd="${entry#* * * * * }"
  grep -Fq "$cmd" <<<"$NEW" && continue
  NEW="${NEW}"$'\n'"${entry}"
  ADDED=$((ADDED + 1))
done

if [[ "$ADDED" -eq 0 ]]; then
  echo "Already scheduled:"
  crontab -l | grep -E "backup-database|backup:check" | sed 's/^/  /'
  exit 0
fi

grep -v '^$' <<<"$NEW" | crontab -

echo "Scheduled:"
crontab -l | grep -E "backup-database|backup:check" | sed 's/^/  /'
cat <<'NEXT'

The log is at /home/ubuntu/backups/backup.log and nothing rotates it, so check
it occasionally or add it to logrotate.

Failures are emailed by `backup:check`, and the daily freshness check catches
the case the backup script cannot report on: never having run at all.

What none of this survives is the host itself being gone. Both the backup and
its watchdog run here, so a dead instance produces no backup and no alert. An
off-box dead-man's-switch is the only thing that closes that, and it is worth
adding before this is treated as fully covered.

To check the current state by hand:

    az storage blob list --account-name knems --auth-mode login \
      -c hrms-db-backups --prefix hrms/ --query "[].name" -o tsv | tail -5
NEXT
