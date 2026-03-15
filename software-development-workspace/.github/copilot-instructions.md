# Copilot Workspace Instructions

## Documentation and Comments

- Write professional, concise comments that explain intent, assumptions, and tradeoffs.
- Update documentation in the same change whenever behavior changes.
- For public APIs, include clear input, output, and failure details.

## Logging and Troubleshooting

- Keep troubleshooting logs in `logs/runtime/` with UTC timestamps.
- Use `./scripts/log-issue.sh` for standardized issue entries.
- Create incident summaries in `logs/incidents/` for recurring or high-impact problems.

## Prompt Preservation

- Preserve all user prompts for follow-up in `logs/session-prompts/YYYY-MM-DD.prompts.md`.
- Append each new prompt with UTC timestamp and source metadata.
- Use `./scripts/archive-prompt.sh --source user "<prompt text>"` to standardize entry format.
