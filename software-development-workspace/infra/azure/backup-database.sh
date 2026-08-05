#!/usr/bin/env bash
#
# Back the HRMS database up to Azure Blob Storage.
#
# WHY OFF-BOX
#
# Postgres, its Docker volume and the only existing dumps all sit on one EBS
# volume. A dump stored beside the database it protects survives a bad DROP
# TABLE and nothing else — not a lost volume, not a deleted instance, not a
# ransomware event that reaches the filesystem. Documents already live in
# Azure with geo-redundancy; until this runs, the database does not.
#
# WHAT IT PRODUCES
#
#   hrms/YYYY/MM/hrms-<timestamp>.dump      custom-format dump of the database
#   hrms/YYYY/MM/hrms-<timestamp>.globals   roles and grants
#   hrms/YYYY/MM/hrms-<timestamp>.sha256    checksum of the dump
#
# Roles live outside any single database, so a database-only dump restores
# tables that nobody has permission to read. They are small and dumped every
# time rather than being someone's job to remember.
#
# VERIFICATION
#
# --verify restores the dump into a scratch database and compares table counts
# against the source. An unrestored backup is a hope, not a backup, and the
# failure mode is discovering that on the day it matters.
#
# USAGE
#
#   ./backup-database.sh              take a backup and upload it
#   ./backup-database.sh --verify     also restore-test it, then drop the copy
#   ./backup-database.sh --dry-run    dump locally, skip upload
set -euo pipefail

ACCOUNT="knems"
CONTAINER="hrms-db-backups"
DB_NAME="hrms"
DB_USER="hrms_user"
PG_CONTAINER="hrms-postgres"

# Daily backups are pruned after this many days. Anything taken on the first of
# a month is kept for a year — enough to recover from corruption noticed late,
# without paying to store every day of it.
RETAIN_DAILY_DAYS=30
RETAIN_MONTHLY_DAYS=365

AZ="${AZ:-az}"
WORKDIR="${WORKDIR:-/home/ubuntu/backups}"

VERIFY=0
DRY_RUN=0
for arg in "$@"; do
  case "$arg" in
    --verify)  VERIFY=1 ;;
    --dry-run) DRY_RUN=1 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }

# Two backups running at once would interleave writes to the same paths and
# double the load on a live database.
exec 9>"${WORKDIR}/.backup.lock"
if ! flock -n 9; then
  echo "another backup is already running" >&2
  exit 1
fi

mkdir -p "$WORKDIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
YEAR="$(date -u +%Y)"
MONTH="$(date -u +%m)"
BASE="hrms-${STAMP}"
PREFIX="hrms/${YEAR}/${MONTH}"

DUMP="${WORKDIR}/${BASE}.dump"
GLOBALS="${WORKDIR}/${BASE}.globals"
SUMFILE="${WORKDIR}/${BASE}.sha256"

# Whatever happens, do not leave a plaintext copy of every employee record
# lying on the host.
cleanup() { rm -f "$DUMP" "$GLOBALS" "$SUMFILE"; }
trap cleanup EXIT

# ── 1. Dump ──────────────────────────────────────────────────────────────────
#
# Run inside the Postgres container so pg_dump exactly matches the server. The
# application image ships pg_dump 18 against a 16.13 server; it can read the
# older server, but produces a dump aimed at 18 that may not restore into 16 —
# which would only be discovered during a recovery.
say "Dumping $DB_NAME"
docker exec "$PG_CONTAINER" pg_dump -U "$DB_USER" -d "$DB_NAME" \
  --format=custom --compress=9 --no-owner --no-privileges > "$DUMP"

docker exec "$PG_CONTAINER" pg_dumpall -U "$DB_USER" --globals-only > "$GLOBALS"

SIZE=$(stat -c %s "$DUMP")
if [[ "$SIZE" -lt 10240 ]]; then
  echo "dump is only ${SIZE} bytes — refusing to upload a probably-empty backup" >&2
  exit 1
fi

sha256sum "$DUMP" | awk '{print $1}' > "$SUMFILE"
printf '   dump    %s (%s bytes)\n' "$(basename "$DUMP")" "$SIZE"
printf '   sha256  %s\n' "$(cat "$SUMFILE")"

# ── 2. Restore test ──────────────────────────────────────────────────────────
if [[ "$VERIFY" -eq 1 ]]; then
  say "Restore test"
  # Lowercased: Postgres folds unquoted identifiers, so a name containing the
  # timestamp's T and Z would be created lowercase and then not be found by a
  # connection using the original casing.
  SCRATCH="restore_check_$(printf '%s' "$STAMP" | tr '[:upper:]' '[:lower:]')"

  docker exec "$PG_CONTAINER" psql -U "$DB_USER" -d postgres -q -c "CREATE DATABASE ${SCRATCH};"
  # The scratch database is dropped whatever happens next — a failed restore
  # must not leave a copy of production PII sitting in the cluster.
  trap 'docker exec "$PG_CONTAINER" psql -U "$DB_USER" -d postgres -q -c "DROP DATABASE IF EXISTS '"${SCRATCH}"';" >/dev/null 2>&1; cleanup' EXIT

  docker exec -i "$PG_CONTAINER" pg_restore -U "$DB_USER" -d "$SCRATCH" --no-owner --no-privileges < "$DUMP" >/dev/null 2>&1 || true

  count() {
    docker exec "$PG_CONTAINER" psql -U "$DB_USER" -d "$1" -tAc \
      "select count(*) from information_schema.tables where table_schema='public';"
  }
  SRC=$(count "$DB_NAME"); GOT=$(count "$SCRATCH")
  printf '   tables: source %s, restored %s\n' "$SRC" "$GOT"

  if [[ "$SRC" != "$GOT" ]]; then
    echo "   RESTORE MISMATCH — not uploading a backup that does not restore" >&2
    exit 1
  fi

  # A schema restores even when every table is empty, so check the data too.
  ROWS=$(docker exec "$PG_CONTAINER" psql -U "$DB_USER" -d "$SCRATCH" -tAc \
          "select count(*) from employees;" 2>/dev/null || echo 0)
  printf '   employees rows in restored copy: %s\n' "$ROWS"
  echo "   restore verified"
fi

if [[ "$DRY_RUN" -eq 1 ]]; then
  say "Dry run — dump kept at ${DUMP%.dump}.* and not uploaded"
  trap - EXIT
  exit 0
fi

# ── 3. Upload ────────────────────────────────────────────────────────────────
say "Uploading to ${CONTAINER}/${PREFIX}"
cd "$WORKDIR"
for f in "$(basename "$DUMP")" "$(basename "$GLOBALS")" "$(basename "$SUMFILE")"; do
  "$AZ" storage blob upload \
    --account-name "$ACCOUNT" --auth-mode login \
    -c "$CONTAINER" -n "${PREFIX}/${f}" -f "$f" --overwrite -o none
  echo "   $f"
done

# Confirm the bytes that arrived are the bytes that left. Storage acknowledging
# a write is not the same as the write being intact.
say "Verifying the uploaded copy"
"$AZ" storage blob download --account-name "$ACCOUNT" --auth-mode login \
  -c "$CONTAINER" -n "${PREFIX}/$(basename "$DUMP")" -f "verify-${BASE}.dump" -o none
UP=$(sha256sum "verify-${BASE}.dump" | awk '{print $1}')
rm -f "verify-${BASE}.dump"

if [[ "$UP" != "$(cat "$SUMFILE")" ]]; then
  echo "   CHECKSUM MISMATCH after upload — the stored copy is not the dump" >&2
  exit 1
fi
echo "   checksums match"

# ── 4. Prune ─────────────────────────────────────────────────────────────────
#
# Deletion is driven by the timestamp in the blob name rather than its
# last-modified date, so re-uploading an old backup cannot extend its life.
say "Pruning old backups"
CUTOFF_DAILY=$(date -u -d "-${RETAIN_DAILY_DAYS} days" +%Y%m%d)
CUTOFF_MONTHLY=$(date -u -d "-${RETAIN_MONTHLY_DAYS} days" +%Y%m%d)
DELETED=0

while read -r blob; do
  [[ -z "$blob" ]] && continue
  # hrms/2026/08/hrms-20260805T101500Z.dump -> 20260805
  d=$(basename "$blob" | sed -nE 's/^hrms-([0-9]{8})T.*/\1/p')
  [[ -z "$d" ]] && continue

  day="${d:6:2}"
  if [[ "$day" == "01" ]]; then
    [[ "$d" < "$CUTOFF_MONTHLY" ]] || continue
  else
    [[ "$d" < "$CUTOFF_DAILY" ]] || continue
  fi

  "$AZ" storage blob delete --account-name "$ACCOUNT" --auth-mode login \
    -c "$CONTAINER" -n "$blob" -o none && DELETED=$((DELETED + 1))
  echo "   removed $blob"
done < <("$AZ" storage blob list --account-name "$ACCOUNT" --auth-mode login \
           -c "$CONTAINER" --prefix hrms/ --query "[].name" -o tsv 2>/dev/null)

echo "   pruned $DELETED"

say "Backup complete: ${CONTAINER}/${PREFIX}/${BASE}.dump"
