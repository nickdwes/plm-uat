# Software Development Workspace

This workspace is a clean starting point for professional multi-app software development with Frappe, Vue.js, and React Native.

## Goals

- Keep project layout clear and predictable.
- Enforce consistent naming conventions.
- Separate backend, web, mobile, shared code, infrastructure, and operational scripts.

## Directory Layout

```
software-development-workspace/
|-- .github/
|   |-- agents/
|   |   `-- environment-setup.agent.md
|   `-- workflows/
|-- .vscode/
|-- apps/
|   |-- backend-frappe/
|   |   `-- apps/
|   |       `-- bitwyse_hrms_backend/
|   |-- mobile-react-native/
|   `-- web-vue/
|       `-- hrms-external-ui/
|-- assets/
|-- config/
|-- docs/
|-- examples/
|-- infrastructure/
|-- logs/
|   |-- incidents/
|   |-- runtime/
|   `-- session-prompts/
|-- packages/
|   `-- shared/
|-- scripts/
|-- src/
|-- tests/
|-- tools/
|-- .editorconfig
|-- .gitignore
`-- README.md
```

## Naming Standards

- Folders: `kebab-case`
- Documentation files: `kebab-case.md`
- Shell scripts: `kebab-case.sh`
- Frappe/Python source files: `snake_case.py`
- Vue components: `PascalCase.vue`
- React Native components: `PascalCase` files, hooks in `camelCase`

See `docs/naming-conventions.md` for details.

## Documentation, Comments, and Troubleshooting

- Documentation and commenting standard: `docs/documentation-and-commenting-standards.md`
- Logging and troubleshooting guide: `docs/logging-and-troubleshooting.md`
- Session prompt archive process: `docs/session-prompt-archive.md`

### What is outstanding

Anything found and consciously not fixed is written down on the day it is found,
rather than left in a commit body where no list can be got out of it.

- Everything deferred, across modules: `docs/deferred-work.md`
- Assets against the EAM brief: `docs/assets-eam-coverage.md`

## New Integration Scaffolds

- Backend API app: `apps/backend-frappe/apps/bitwyse_hrms_backend/`
- External Vue frontend: `apps/web-vue/hrms-external-ui/`
