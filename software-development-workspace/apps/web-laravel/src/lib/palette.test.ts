import { describe, it, expect } from 'vitest';
import { deriveBrandPalette, contrastRatio, readableOn } from './palette';

/**
 * The contrast floor is the whole point of this module.
 *
 * Before it existed, an organisation's colour was written straight onto the
 * brand tokens and three of the four live tenants shipped primary buttons
 * below the 4.5:1 WCAG requires — Teedu Academy's amber at 1.88:1. Nothing
 * failed; the buttons were simply hard to read, in production, for everyone in
 * those organisations.
 *
 * These are the colours actually in the database, so a regression here is a
 * regression for a real tenant rather than a hypothetical one.
 */
const LIVE_TENANTS = {
  Konvergenz: '#0c8ef4',
  'Teedu Academy': '#f9ae40',
  'Visibility Technology Solutions': '#de7809',
  'Tiberbu Healthnet': '#b71d28',
};

const SURFACE = { light: '#FFFFFF', dark: '#0B1622' };

describe('brand palette', () => {
  describe.each(['light', 'dark'] as const)('%s theme', (theme) => {
    it.each(Object.entries(LIVE_TENANTS))(
      '%s: text on a brand fill meets 4.5:1',
      (_name, hex) => {
        const p = deriveBrandPalette(hex, theme)!;
        expect(contrastRatio(p.onBrand, p.base)).toBeGreaterThanOrEqual(4.5);
      },
    );

    it.each(Object.entries(LIVE_TENANTS))(
      '%s: the accent meets 3:1 against the surface it sits on',
      (_name, hex) => {
        const p = deriveBrandPalette(hex, theme)!;
        expect(contrastRatio(p.accent, SURFACE[theme])).toBeGreaterThanOrEqual(3);
      },
    );

    it.each(Object.entries(LIVE_TENANTS))(
      '%s: hover stays legible too',
      (_name, hex) => {
        // A hover state that drops below the floor is worse than no hover
        // state: it fails only while being interacted with.
        const p = deriveBrandPalette(hex, theme)!;
        expect(contrastRatio(p.onBrand, p.hover)).toBeGreaterThanOrEqual(4.5);
      },
    );
  });

  it('leaves a colour alone when it already passes', () => {
    // Tiberbu's red clears 4.5:1 unaided. Darkening it anyway would move an
    // organisation off its own brand for no benefit.
    const p = deriveBrandPalette('#b71d28', 'light')!;
    expect(p.base.toLowerCase()).toBe('#b71d28');
  });

  it('keeps the accent recognisably the colour that was chosen', () => {
    // The point is a contrast floor, not a redesign: an organisation that
    // picks amber should still see amber.
    const p = deriveBrandPalette('#f9ae40', 'light')!;
    const hueOf = (hex: string) => {
      const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
      return Math.atan2(Math.sqrt(3) * (g - b), 2 * r - g - b);
    };
    expect(Math.abs(hueOf(p.accent) - hueOf('#f9ae40'))).toBeLessThan(0.35);
  });

  it('rejects input it cannot parse rather than guessing', () => {
    // A malformed value must leave the design system's palette in place; a
    // partially applied theme is harder to diagnose than none at all.
    expect(deriveBrandPalette('not-a-colour', 'light')).toBeNull();
    expect(deriveBrandPalette('#12345', 'light')).toBeNull();
  });

  it('accepts a colour with or without the leading hash', () => {
    expect(deriveBrandPalette('0c8ef4', 'light')).not.toBeNull();
  });

  it('derives different fills for light and dark themes', () => {
    // Inline styles on the root element outrank [data-theme="dark"] rules, so
    // a single derivation would pin one theme's colour into both.
    const light = deriveBrandPalette('#b71d28', 'light')!;
    const dark = deriveBrandPalette('#b71d28', 'dark')!;
    expect(light.base).not.toBe(dark.base);
  });

  describe('readableOn', () => {
    it('makes a colour legible against a tinted row', () => {
      const c = readableOn('#f9ae40', '#F1F5F9')!;
      expect(contrastRatio(c, '#F1F5F9')).toBeGreaterThanOrEqual(4.5);
    });

    it('returns null for unparseable input', () => {
      expect(readableOn('nope', '#FFFFFF')).toBeNull();
    });
  });
});

describe('the shipped defaults', () => {
  it('the default brand fill carries white text', () => {
    // tokens.css hard-codes this value; if it is edited back to the Figma
    // accent #0c8ef4, every default-theme button drops to 3.39:1.
    expect(contrastRatio('#FFFFFF', '#0077db')).toBeGreaterThanOrEqual(4.5);
  });

  it('the Figma accent is fine for non-text use', () => {
    expect(contrastRatio('#0c8ef4', '#FFFFFF')).toBeGreaterThanOrEqual(3);
  });
});
