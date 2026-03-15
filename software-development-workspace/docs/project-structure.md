# Project Structure Guide

This workspace follows a separation-of-concerns model.

## Top-Level Directories

- `apps/`: deployable applications by platform.
	- `apps/backend-frappe/`: Frappe backend and app logic.
	- `apps/web-vue/`: Vue.js web frontend.
	- `apps/mobile-react-native/`: React Native mobile app.
- `packages/shared/`: reusable code shared across backend, web, and mobile.
- `infrastructure/`: deployment and environment provisioning artifacts.
- `src/`: shared or legacy production code not tied to one app.
- `tests/`: unit, integration, and end-to-end tests across apps.
- `docs/`: architecture, decisions, operating procedures.
- `logs/`: runtime logs, incident notes, and archived session prompts for troubleshooting and follow-up.
- `scripts/`: developer and CI helper scripts.
- `config/`: project configuration templates and shared config files.
- `tools/`: internal utilities used by the team.
- `assets/`: static resources.
- `examples/`: sample implementations and reference usage.

## Principle

Keep production code grouped by deployable app under `apps/` and place only cross-cutting modules in `packages/shared/`. Avoid mixing product code with test, docs, or tooling files. Every directory should communicate clear ownership and intent.
