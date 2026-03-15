# Session Prompt Archive

This process preserves all prompts issued during sessions for follow-up and audit.

## Archive Location

- `logs/session-prompts/YYYY-MM-DD.prompts.md`

## Entry Format

Each entry should include:

- UTC timestamp
- Source (`user` or `assistant`)
- Prompt text

## How To Archive Prompt Entries

Use the helper script:

`./scripts/archive-prompt.sh "<prompt text>"`

Optional source metadata:

`./scripts/archive-prompt.sh --source user "<prompt text>"`

`./scripts/archive-prompt.sh --source assistant "<prompt text>"`

The script appends entries to the current UTC date file and creates it if missing.

## Follow-Up Workflow

- Review archived prompts before starting new work.
- Link unresolved prompts to action items or incident notes.
- Keep prompt files committed for team continuity.
