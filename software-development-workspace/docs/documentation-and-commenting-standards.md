# Documentation and Commenting Standards

This standard ensures code is understandable, maintainable, and professional.

## Core Principles

- Write comments to explain intent and tradeoffs, not obvious syntax.
- Keep comments current with the code they describe.
- Prefer self-explanatory names first, comments second.
- Use consistent tone, grammar, and punctuation.

## Required Documentation

- Public modules, classes, and exported functions must include documentation.
- Complex logic blocks must include short rationale comments.
- API boundaries must document input, output, and failure behavior.
- Any non-obvious dependency or side effect must be documented.

## Language Guidance

- Python and Frappe:
  - Use docstrings for public functions, classes, and modules.
  - Document arguments, return values, and raised exceptions.
- Vue.js:
  - Document component purpose and key props.
  - Add short comments where business rules are non-trivial.
- React Native:
  - Document component purpose, external dependencies, and side effects.
  - Document custom hooks with input/output expectations.

## Comment Quality Checklist

- Is the comment still true after this change?
- Does it explain why this exists?
- Is the wording concise and professional?
- Can a new engineer understand it quickly?

## Promptness Rule

Documentation and code comments must be updated in the same change where behavior is introduced or modified. Do not defer documentation work.
