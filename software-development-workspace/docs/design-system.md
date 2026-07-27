# Konvergenz Design System — Frontend Guidelines

The rules for colour, typography and spacing in `apps/web-laravel`. Read this before
writing any component styling.

A rendered visual reference with live swatches, type specimens and the full contrast
audit accompanies this document. This file is the authoritative text version.

## Source of Truth

Tokens flow in one direction. Never skip a tier.

```
src/tokens.css   →   src/theme.css   →   component
  (definitions)      (Tailwind bridge)   (consumption)
```

- `src/tokens.css` — every colour, size, radius and shadow. The only file where a
  literal hex value may appear.
- `src/theme.css` — maps semantic tokens onto Tailwind utilities so `bg-surface` and
  `var(--color-surface)` resolve to the same value.
- Components — consume tokens or utilities. Never literals.

## Token Tiers

| Tier | Example | Who may use it |
| --- | --- | --- |
| 1 · Primitive | `--ramp-brand-700` | `tokens.css` only |
| 2 · Semantic | `--color-brand-base` | Components and hand-written CSS |
| 3 · Utility | `bg-brand`, `text-content` | Templates |

Components reference tier 2 or 3 exclusively. Tier 1 exists so the ramp can be retuned
in one place without touching consumers.

## The Rule That Matters Most

**Never hard-code a colour in a component.**

```
/* Correct — themes itself */
background: var(--color-surface);
color: var(--color-text);

<div class="bg-surface text-content border border-border-default">
```

```
/* Wrong — locked to light mode, invisible to the design system */
background: #ffffff;
color: #101828;

<div class="bg-white text-slate-900 border-slate-200">
```

This is not a style preference. Semantic tokens redefine themselves under
`[data-theme="dark"]`, so a component written against them themes correctly with no
extra CSS. A hard-coded hex cannot.

### Current drift

At the time of writing, 40 of 62 components use tokens and **50 hard-code hex values**.
Those values are largely stock Tailwind slate rather than the brand palette:

| Value | Occurrences | What it is |
| --- | --- | --- |
| `#004C97` | 140 | Brand blue, but written literally instead of as a token |
| `#e2e8f0` | 126 | slate-200 — not in the Konvergenz palette |
| `#64748b` | 100 | slate-500 — not in the Konvergenz palette |
| `#f1f5f9` | 74 | slate-100 — not in the Konvergenz palette |
| `#dc2626` | 68 | red-600 — the palette specifies `#D32F2F` |

This drift, not the absence of a utility framework, is what makes the interface look
inconsistent. Reducing it is the goal of the migration.

## Colour

### Brand

| Token | Light | Dark |
| --- | --- | --- |
| `--color-brand-base` | `#004C97` | `#3B82F6` |
| `--color-brand-hover` | `#1565C0` | `#60A5FA` |
| `--color-brand-dark` | `#0A3573` | `#1565C0` |
| `--color-brand-light` | `#DBEAFE` | `rgba(96,165,250,.16)` |

### Neutral

The dark ramp inverts. `900` always means *highest contrast against the current
background*, so `--color-neutral-900` stays legible in both themes.

| Step | Light | Dark |
| --- | --- | --- |
| 900 | `#101828` | `#E5E7EB` |
| 800 | `#1E293B` | `#CBD5E1` |
| 700 | `#4B5563` | `#94A3B8` |
| 500 | `#6B7280` | `#64748B` |
| 300 | `#D1D5DB` | `#334155` |
| 200 | `#E5E7EB` | `#1E293B` |
| 100 | `#F3F4F6` | `#0F172A` |
| 50 | `#F9FAFB` | `#0B1220` |

### Surface and text roles

| Token | Light | Dark | Use for |
| --- | --- | --- | --- |
| `--color-bg` | `#F9FAFB` | `#0B1220` | Page canvas |
| `--color-surface` | `#FFFFFF` | `#0F172A` | Cards, tables, panels |
| `--color-surface-raised` | `#FFFFFF` | `#1E293B` | Modals, popovers |
| `--color-border` | `#E5E7EB` | `#334155` | Default hairline |
| `--color-text` | `#101828` | `#E5E7EB` | Primary copy |
| `--color-text-muted` | `#4B5563` | `#94A3B8` | Secondary copy |
| `--color-text-light` | `#6B7280` | `#64748B` | Hints, timestamps |

### Feedback and accent

| Token | Light | Dark |
| --- | --- | --- |
| `--color-success` | `#10B981` | `#34D399` |
| `--color-success-light` | `#D1FAE5` | `#064E3B` |
| `--color-warning` | `#ED6C02` | `#FBBF24` |
| `--color-warning-light` | `#FEF3C7` | `#78350F` |
| `--color-error` | `#D32F2F` | `#F87171` |
| `--color-error-light` | `#FEE2E2` | `#7F1D1D` |
| `--color-info` | `#0288D1` | `#60A5FA` |
| `--color-info-light` | `#E0F2FE` | `#1E40AF` |
| `--color-teal` | `#0D9488` | `#2DD4BF` |
| `--color-indigo` | `#6366F1` | `#818CF8` |
| `--color-amber` | `#F59E0B` | `#FCD34D` |

## Accessibility Constraints

Contrast ratios below are computed to WCAG 2.1, not estimated. Thresholds are 4.5:1 for
body text, 3:1 for text at 24px+ or bold 19px+, and 3:1 for UI boundaries.

**The `AA 4.55` labels in the Figma library are placeholder text.** Every swatch carries
the same value, including combinations that cannot be 4.55:1. Do not rely on them.

### Hard rules

- **Never place white text on a success or amber fill in light mode.** `#10B981` and
  `#F59E0B` against white measure 2.54:1 and 2.15:1. Use `--color-brand-base` for
  primary buttons.
- **Coloured text on its own tinted background is the riskiest pattern** — the badge and
  pill style. Success on success-light is 2.24:1 and warning on warning-light is 2.80:1;
  both fail. Darken the text rather than lightening the background.
- **Feedback colours are safe as fills, icons and status stripes**, where the 3:1
  non-text threshold applies. They are not safe as small text.
- **Never encode meaning in hue alone.** Pair every status colour with a label or icon.

### Known failures in light mode

| Pairing | Ratio | Result |
| --- | --- | --- |
| Success on white | 2.54:1 | Fail |
| Success on success-light | 2.24:1 | Fail |
| White on success fill | 2.54:1 | Fail |
| Amber on white | 2.15:1 | Fail |
| Warning on warning-light | 2.80:1 | Fail |
| Warning on white | 3.11:1 | Large text only |
| Info on white | 3.86:1 | Large text only |
| Teal on white | 3.74:1 | Large text only |
| Indigo on white | 4.47:1 | Large text only |

Dark mode is materially healthier: no outright failures, and only two large-text-only
pairings.

## Typography

Google Sans, loaded from Google Fonts, falling back to the host UI face.

```
'Google Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif
```

**Google Sans publishes 400, 500, 600 and 700 only. There is no 800 weight.** Requesting
one produces browser-synthesised faux bold. `--font-weight-extrabold` is retained as an
alias mapping to 700 and should not be used in new work.

### Display scale

Page titles, empty states, marketing. Sizes 36px and above carry −2% tracking.

| Token | Size | Line height | Tracking |
| --- | --- | --- | --- |
| `display-2xl` | 72px / 4.5rem | 90px / 5.625rem | −2% |
| `display-xl` | 60px / 3.75rem | 72px / 4.5rem | −2% |
| `display-lg` | 48px / 3rem | 60px / 3.75rem | −2% |
| `display-md` | 36px / 2.25rem | 44px / 2.75rem | −2% |
| `display-sm` | 30px / 1.875rem | 38px / 2.375rem | 0 |
| `display-xs` | 24px / 1.5rem | 32px / 2rem | 0 |

### Text scale

Product UI. `text-sm` at 14px is the application default — this is a dense admin
interface, so body copy sits below a typical marketing 16px.

| Token | Size | Line height | Use for |
| --- | --- | --- | --- |
| `text-2xl` | 24px | 32px | Section headings |
| `text-xl` | 20px | 30px | Card titles, modal headings |
| `text-lg` | 18px | 28px | Subheadings |
| `text-md` | 16px | 24px | Long-form reading copy |
| `text-sm` | 14px | 20px | **Default** — tables, forms, buttons, nav |
| `text-xs` | 12px | 18px | Hints, timestamps, badges, column headers |

The legacy px sizes (`--font-size-xs` through `--font-size-3xl`) remain in `tokens.css`
so unmigrated components keep rendering. New work uses the rem scale above. The 11px
legacy step is below the practical floor for readable UI text and must not be carried
forward.

## Spacing

4px base grid. This is Tailwind's default scale unchanged, so `p-4` is 16px and `gap-6`
is 24px. No configuration was required.

| Token | rem | px |
| --- | --- | --- |
| 1 | 0.25 | 4 |
| 2 | 0.5 | 8 |
| 3 | 0.75 | 12 |
| 4 | 1 | 16 |
| 5 | 1.25 | 20 |
| 6 | 1.5 | 24 |
| 8 | 2 | 32 |
| 10 | 2.5 | 40 |
| 12 | 3 | 48 |
| 16 | 4 | 64 |
| 20 | 5 | 80 |
| 24 | 6 | 96 |
| 32 | 8 | 128 |
| 40 | 10 | 160 |
| 48 | 12 | 192 |
| 56 | 14 | 224 |
| 64 | 16 | 256 |

## Radius and Elevation

| Token | Value | Use for |
| --- | --- | --- |
| `--radius-sm` | 4px | Inputs |
| `--radius-md` | 6px | Buttons |
| `--radius-lg` | 8px | Cards |
| `--radius-xl` | 10px | Panels |
| `--radius-2xl` | 12px | Modals |
| `--radius-3xl` | 16px | Feature surfaces |
| `--radius-full` | 9999px | Pills, avatars |

Shadows run `--shadow-xs` through `--shadow-lg`. Dark mode deepens every shadow to
40–65% alpha, since shadows barely read on a dark ground; separation there comes mostly
from `--color-border` and the step between `--color-surface` and
`--color-surface-raised`.

## Tailwind Preflight Is Deliberately Disabled

`src/theme.css` imports Tailwind's theme and utilities layers but **not** Preflight.

Preflight resets `h1..h6` to `font-size: inherit; font-weight: inherit`. This app has
213 heading tags, 89 of them unclassed, and `style.css` sizes headings in only two
places. Enabling Preflight before components are migrated flattens headings across
nearly every screen.

Re-enable it as part of the final migration pass, not before:

```css
@import "tailwindcss/preflight.css" layer(base);
```

Re-verify every view in the same pass.

## Deviations From the Figma Library

Each is deliberate and needs design sign-off before the library is treated as
authoritative again.

| Item | Figma | Shipped | Reason |
| --- | --- | --- | --- |
| Dark brand ramp | `#004C97` | `#3B82F6` | Figma value measures 2.11:1 on dark canvas |
| Neutral 500 | `#687280` | `#6B7280` | Figma value matches no standard ramp |
| Info light | `#0288D1` | `#E0F2FE` | Figma repeats the base value in the light slot |
| Neutral steps | two 400s, two 100s | 500 / 300, 100 / 50 | Duplicate labels resolved by ramp position |
| Weight 800 | not shown | removed | Google Sans has no 800 weight |

Three transcription errors were also corrected in `tokens.css`, all the same `B` → `8`
slip: `#485563` → `#4B5563`, `#108981` → `#10B981`, `#F59E08` → `#F59E0B`.

### Open questions for design

- Should the success and warning ramps darken in light mode so badges reach 4.5:1?
- Is the dark brand blue acceptable, or should a Konvergenz-specific dark tint be drawn
  rather than adopting Tailwind's blue-500?
- The text scale below `text-6xl` is proposed and needs confirming against the library.

## Migration Status

The Tailwind foundation is in place and building. Component migration has not started
and is being taken step by step.

Suggested order when it begins:

1. Shared components first — `AppNav`, `AppHeader`, `OrgSelector`, modals.
2. Then views by module, prioritising the 50 files carrying hard-coded hex.
3. Enable Preflight last, re-verifying every view.

Every migrated component must end with zero literal hex values in its `<style>` block.
