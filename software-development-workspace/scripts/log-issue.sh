#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 2 ]]; then
  echo "Usage: ./scripts/log-issue.sh <service-name> \"<message>\" [level]" >&2
  exit 1
fi

service_name="$1"
shift
message="$1"
shift || true
level="${1:-ERROR}"

timestamp="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
log_dir="logs/runtime"
log_file="${log_dir}/${service_name}-$(date -u +"%Y-%m-%d").log"

mkdir -p "${log_dir}"
printf '%s level=%s service=%s event=issue message="%s"\n' "${timestamp}" "${level}" "${service_name}" "${message}" >> "${log_file}"

echo "Wrote log entry to ${log_file}"
