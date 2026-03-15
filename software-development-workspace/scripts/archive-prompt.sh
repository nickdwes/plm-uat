#!/usr/bin/env bash
set -euo pipefail

source_type="user"

if [[ "${1:-}" == "--source" ]]; then
  if [[ $# -lt 3 ]]; then
    echo "Usage: ./scripts/archive-prompt.sh [--source user|assistant] \"<prompt text>\"" >&2
    exit 1
  fi
  source_type="$2"
  shift 2
fi

if [[ $# -lt 1 ]]; then
  echo "Usage: ./scripts/archive-prompt.sh [--source user|assistant] \"<prompt text>\"" >&2
  exit 1
fi

if [[ "${source_type}" != "user" && "${source_type}" != "assistant" ]]; then
  echo "Error: source must be 'user' or 'assistant'." >&2
  exit 1
fi

timestamp="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
archive_dir="logs/session-prompts"
archive_file="${archive_dir}/$(date -u +"%Y-%m-%d").prompts.md"
prompt_text="$*"

mkdir -p "${archive_dir}"

if [[ ! -f "${archive_file}" ]]; then
  {
    echo "# Session Prompts for $(date -u +"%Y-%m-%d")"
    echo
  } > "${archive_file}"
fi

{
  echo "## ${timestamp}"
  echo
  echo "- source: ${source_type}"
  echo "- prompt: ${prompt_text}"
  echo
} >> "${archive_file}"

echo "Archived prompt in ${archive_file}"
