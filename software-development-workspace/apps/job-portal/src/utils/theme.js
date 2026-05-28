/**
 * Converts a hex color to its RGB components.
 */
function hexToRgb(hex) {
  const h = hex.replace('#', '')
  const n = parseInt(h.length === 3 ? h.split('').map(c => c + c).join('') : h, 16)
  return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 }
}

/**
 * Lightens or darkens a hex color by mixing with white/black.
 * amount: 0–1 where positive = mix with white, negative = mix with black
 */
function adjustColor(hex, amount) {
  const { r, g, b } = hexToRgb(hex)
  const mix = amount > 0 ? 255 : 0
  const a = Math.abs(amount)
  const nr = Math.round(r + (mix - r) * a)
  const ng = Math.round(g + (mix - g) * a)
  const nb = Math.round(b + (mix - b) * a)
  return `#${[nr, ng, nb].map(v => v.toString(16).padStart(2, '0')).join('')}`
}

/**
 * Applies an organization's branding colors to CSS custom properties on :root.
 * Call with null to reset to defaults.
 */
export function applyOrgTheme(org) {
  const root = document.documentElement
  const primary = org?.primary_color || '#004C97'

  root.style.setProperty('--brand', primary)
  root.style.setProperty('--brand-dark', adjustColor(primary, -0.25))
  root.style.setProperty('--brand-light', adjustColor(primary, 0.9))

  const { r, g, b } = hexToRgb(primary)
  root.style.setProperty('--brand-rgb', `${r}, ${g}, ${b}`)
}

/**
 * Returns a contrasting text color (#fff or #000) for a given background hex.
 */
export function contrastColor(hex) {
  const { r, g, b } = hexToRgb(hex)
  // Perceived luminance
  const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255
  return lum > 0.55 ? '#1e293b' : '#ffffff'
}
