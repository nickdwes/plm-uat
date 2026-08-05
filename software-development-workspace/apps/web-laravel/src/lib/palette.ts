/**
 * Brand palette derivation with a contrast floor.
 *
 * An organisation supplies one colour. Everything the interface needs is
 * derived from it here, and nothing derived is allowed to be unreadable.
 *
 * The previous approach multiplied RGB channels — `r * 0.82` for hover,
 * `r * 0.62` for emphasis — and applied the org's colour to brand tokens
 * unchanged. Two problems followed from that.
 *
 * Channel multiplication is not a lightness operation. It slides a colour
 * toward black, dragging chroma and hue with it: on a saturated amber it
 * produces mud, on a blue a navy, and the perceived size of the step differs
 * wildly between hues. Working in OKLCH instead means hue and chroma are held
 * while lightness moves, so every organisation gets a ramp with the same
 * rhythm whatever colour they picked.
 *
 * The second problem was that nothing checked the result. Measured against the
 * four live organisations, three produced primary buttons below the 4.5:1
 * needed for white text — one at 1.88:1. The colour an organisation types into
 * a settings form should not be able to make the application unreadable, so
 * fills are darkened here until they pass.
 *
 * Two roles, because one colour cannot serve both:
 *
 *   fill    a background with text on it       needs 4.5:1
 *   accent  borders, icons, indicators         needs 3:1
 *
 * An organisation that picks amber still sees amber accents; its buttons use a
 * deepened amber that white text can actually sit on.
 */

export interface BrandPalette {
  /** Solid backgrounds carrying text. Contrast-clamped. */
  base: string;
  /** Hover state for a filled control — one lightness step from base. */
  hover: string;
  /** Heavier emphasis: headings on tinted grounds, pressed states. */
  dark: string;
  /** Borders, icons, focus rings — non-text UI, clamped to 3:1. */
  accent: string;
  /** Tinted background, no text contrast requirement of its own. */
  light: string;
  /** Barely-there wash, used behind focus rings. */
  subtle: string;
  /** What to put on top of `base`. */
  onBrand: string;
}

// ── sRGB ↔ OKLab ─────────────────────────────────────────────────────────────
//
// Björn Ottosson's OKLab. Chosen over HSL because HSL's "lightness" is not
// perceptual: #0000FF and #FFFF00 are both L=50% while looking nothing alike,
// so an HSL ramp lands in a different place for every hue.

function srgbToLinear(c: number): number {
  return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
}

function linearToSrgb(c: number): number {
  return c <= 0.0031308 ? c * 12.92 : 1.055 * c ** (1 / 2.4) - 0.055;
}

interface Lab { L: number; a: number; b: number }

function hexToLab(hex: string): Lab | null {
  const m = hex.trim().match(/^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i);
  if (!m) return null;

  const [r, g, b] = [1, 2, 3].map((i) => srgbToLinear(parseInt(m[i], 16) / 255));

  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m_ = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);

  return {
    L: 0.2104542553 * l + 0.7936177850 * m_ - 0.0040720468 * s,
    a: 1.9779984951 * l - 2.4285922050 * m_ + 0.4505937099 * s,
    b: 0.0259040371 * l + 0.7827717662 * m_ - 0.8086757660 * s,
  };
}

function labToHex({ L, a, b }: Lab): string {
  const l_ = (L + 0.3963377774 * a + 0.2158037573 * b) ** 3;
  const m_ = (L - 0.1055613458 * a - 0.0638541728 * b) ** 3;
  const s_ = (L - 0.0894841775 * a - 1.2914855480 * b) ** 3;

  const rgb = [
    4.0767416621 * l_ - 3.3077115913 * m_ + 0.2309699292 * s_,
    -1.2684380046 * l_ + 2.6097574011 * m_ - 0.3413193965 * s_,
    -0.0041960863 * l_ - 0.7034186147 * m_ + 1.7076147010 * s_,
  ];

  return '#' + rgb
    .map((c) => Math.round(Math.min(1, Math.max(0, linearToSrgb(c))) * 255))
    .map((v) => v.toString(16).padStart(2, '0'))
    .join('');
}

/** Move lightness while holding hue and chroma. */
function withLightness(lab: Lab, L: number): Lab {
  return { L: Math.min(1, Math.max(0, L)), a: lab.a, b: lab.b };
}

// ── WCAG contrast ────────────────────────────────────────────────────────────

function relativeLuminance(hex: string): number {
  const m = hex.match(/^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i);
  if (!m) return 0;
  const [r, g, b] = [1, 2, 3].map((i) => srgbToLinear(parseInt(m[i], 16) / 255));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrastRatio(a: string, b: string): number {
  const x = relativeLuminance(a);
  const y = relativeLuminance(b);
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

/**
 * Push a colour's lightness until it meets `target` against `against`.
 *
 * Binary search on OKLab lightness rather than a fixed step, because how far a
 * colour must move to clear a threshold depends entirely on where it starts —
 * a pale yellow needs a great deal, a mid navy almost none.
 *
 * Direction is chosen by which side of the reference the colour sits on, so
 * the same function works for dark text on light fills and the reverse.
 */
function clampToContrast(lab: Lab, against: string, target: number): Lab {
  if (contrastRatio(labToHex(lab), against) >= target) return lab;

  // Move away from the reference. A lighter reference — white text — means the
  // fill has to get darker; a dark one means it has to get lighter.
  const goDarker = relativeLuminance(against) > relativeLuminance(labToHex(lab));

  let lo = goDarker ? 0 : lab.L;
  let hi = goDarker ? lab.L : 1;

  let best = withLightness(lab, goDarker ? 0 : 1);

  // 24 iterations resolves lightness far finer than 8-bit output can express.
  for (let i = 0; i < 24; i++) {
    const mid = (lo + hi) / 2;
    const candidate = withLightness(lab, mid);

    if (contrastRatio(labToHex(candidate), against) >= target) {
      // Keep the least extreme colour that still passes, so the result stays
      // as close to the organisation's actual brand as the rule allows.
      best = candidate;
      if (goDarker) lo = mid; else hi = mid;
    } else if (goDarker) {
      hi = mid;
    } else {
      lo = mid;
    }
  }

  return best;
}

// ── Derivation ───────────────────────────────────────────────────────────────

/** WCAG 2.1: 4.5:1 for normal text, 3:1 for non-text UI. */
const TEXT_CONTRAST = 4.5;
const UI_CONTRAST = 3;

/**
 * The nearest colour to `hex` that is legible on `background`.
 *
 * For the places a brand colour is used as text on something other than a
 * plain surface — an active navigation label on its tinted row, say — where
 * neither `base` nor `accent` is measured against the right background.
 */
export function readableOn(hex: string, background: string, ratio = TEXT_CONTRAST): string | null {
  const lab = hexToLab(hex);
  return lab ? labToHex(clampToContrast(lab, background, ratio)) : null;
}

export function deriveBrandPalette(
  hex: string,
  theme: 'light' | 'dark' = 'light',
): BrandPalette | null {
  const lab = hexToLab(hex);
  if (!lab) return null;

  // What sits on a brand fill, and what the accent must be legible against.
  const onBrand = theme === 'dark' ? '#06121F' : '#FFFFFF';
  const surface = theme === 'dark' ? '#0B1622' : '#FFFFFF';

  // On a dark ground a brand colour needs to be lighter, not darker, so the
  // ramp runs the other way. Deriving both from the same input keeps an
  // organisation recognisably itself in either theme.
  const step = theme === 'dark' ? +0.06 : -0.06;

  const base   = clampToContrast(lab, onBrand, TEXT_CONTRAST);
  const hover  = withLightness(base, base.L + step);
  const dark   = withLightness(base, base.L + step * 2);
  const accent = clampToContrast(lab, surface, UI_CONTRAST);

  const { r, g, b } = hexToRgbTuple(labToHex(accent));

  return {
    base:   labToHex(base),
    hover:  labToHex(hover),
    dark:   labToHex(dark),
    accent: labToHex(accent),
    // Tints keep the organisation's own colour: they sit behind content rather
    // than carrying it, so they are not contrast-bearing.
    light:  `rgba(${r}, ${g}, ${b}, ${theme === 'dark' ? 0.16 : 0.14})`,
    subtle: `rgba(${r}, ${g}, ${b}, ${theme === 'dark' ? 0.10 : 0.08})`,
    onBrand,
  };
}

function hexToRgbTuple(hex: string): { r: number; g: number; b: number } {
  const m = hex.match(/^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i);
  return m
    ? { r: parseInt(m[1], 16), g: parseInt(m[2], 16), b: parseInt(m[3], 16) }
    : { r: 0, g: 0, b: 0 };
}
