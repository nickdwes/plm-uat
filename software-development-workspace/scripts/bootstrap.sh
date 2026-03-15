#!/usr/bin/env bash
set -euo pipefail

# Initialize git for the workspace if needed.
if [[ ! -d ".git" ]]; then
  git init >/dev/null 2>&1
  echo "Initialized git repository."
else
  echo "Git repository already initialized."
fi

echo "Workspace bootstrap complete."
