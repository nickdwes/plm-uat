# Naming Conventions

Consistent naming improves readability and reduces onboarding friction.

## General Rules

- Prefer descriptive names over abbreviations.
- Avoid spaces in file and folder names.
- Use lowercase names unless language ecosystems strongly prefer otherwise.
- Keep names stable over time to avoid noisy refactors.

## Folders

- Use `kebab-case` for all folders.
- Folder names should be nouns that communicate purpose.
- Examples:
  - `src`
  - `integration-tests`
  - `build-tools`

## Files

- Documentation: `kebab-case.md`
- Shell scripts: `kebab-case.sh`
- Configuration files: follow tool standards (`pyproject.toml`, `tsconfig.json`, `.editorconfig`)

## Source Code Naming

Follow the language convention in use:

- Frappe/Python: `snake_case.py`; Frappe app/module names should use `snake_case`
- Vue.js: component files in `PascalCase.vue`; utility modules in project-standard style (commonly `kebab-case` or `camelCase`)
- React Native: component files in `PascalCase`; hooks in `camelCase` prefixed with `use` (example: `useAuth.ts`)
- JavaScript/TypeScript support files: keep one chosen style project-wide and document it in the app README

## Branch Naming (Recommended)

- `feature/<short-description>`
- `fix/<short-description>`
- `chore/<short-description>`

Examples:

- `feature/add-auth-middleware`
- `fix/null-check-in-parser`
- `chore/update-ci-cache`
