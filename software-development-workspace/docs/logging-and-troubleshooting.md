# Logging and Troubleshooting Guide

This guide standardizes logs for faster diagnosis and reliable follow-up.

## Log Locations

- Runtime logs: `logs/runtime/`
- Incident reports: `logs/incidents/`
- Session prompt history: `logs/session-prompts/`

## Runtime Log Naming

Use this format:

- `<service-name>-YYYY-MM-DD.log`

Examples:

- `backend-frappe-2026-03-13.log`
- `web-vue-2026-03-13.log`

## Log Entry Format

Use a single-line structured format for easy searching.

Example:

`2026-03-13T08:35:00Z level=ERROR service=backend-frappe event=db-timeout message="Database query timed out"`

## Incident Reports

Create an incident markdown note in `logs/incidents/` when recurring or production-impacting issues occur.

Suggested sections:

- Summary
- Impact
- Timeline (UTC)
- Root cause
- Fix implemented
- Follow-up actions

## Operational Rules

- Do not delete logs related to active issues.
- Keep UTC timestamps for all entries.
- Add enough context in each message to reproduce or correlate the issue.
- Ensure logs do not include secrets or sensitive credentials.
