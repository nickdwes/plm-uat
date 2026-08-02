/**
 * Theme resolution.
 *
 * Writes `data-theme` onto the root element so `[data-theme="dark"]` in
 * tokens.css is the single place dark values are defined.
 *
 * Previously the dark palette existed twice — once under `[data-theme="dark"]`
 * and again under `@media (prefers-color-scheme: dark)` — and nothing ever set
 * the attribute, so only the media copy was live. The two drifted: the media
 * block defined three sidebar tokens against the other's ten, leaving the rest
 * to fall back to their light values. Section headings ended up near-black on a
 * near-black ground.
 *
 * Duplicating a palette across two selectors will always drift. Setting the
 * attribute here means it is written once.
 */
import { ref } from "vue";

export type ThemePreference = "light" | "dark" | "system";

const STORAGE_KEY = "hrms_theme";

/** What the user asked for. */
export const themePreference = ref<ThemePreference>(readStored());

/** What is actually applied once "system" is resolved. */
export const resolvedTheme = ref<"light" | "dark">("light");

function readStored(): ThemePreference {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw === "light" || raw === "dark" ? raw : "system";
  } catch {
    return "system";
  }
}

function systemPrefersDark(): boolean {
  return typeof window !== "undefined"
    && window.matchMedia?.("(prefers-color-scheme: dark)").matches === true;
}

function apply(): void {
  const dark = themePreference.value === "dark"
    || (themePreference.value === "system" && systemPrefersDark());

  resolvedTheme.value = dark ? "dark" : "light";
  document.documentElement.setAttribute("data-theme", resolvedTheme.value);
}

export function setTheme(preference: ThemePreference): void {
  themePreference.value = preference;

  try {
    // "system" is the absence of a choice, so it is stored as one.
    preference === "system"
      ? localStorage.removeItem(STORAGE_KEY)
      : localStorage.setItem(STORAGE_KEY, preference);
  } catch {
    /* private browsing — the theme still applies for this session */
  }

  apply();
}

/**
 * Call once at start-up, before the app mounts, so the first paint is already
 * the right theme rather than flashing light and correcting itself.
 */
export function initTheme(): void {
  apply();

  // Follow the OS while the preference is "system" — someone switching their
  // machine to dark at dusk should not have to reload.
  window.matchMedia?.("(prefers-color-scheme: dark)")
    .addEventListener?.("change", () => {
      if (themePreference.value === "system") apply();
    });
}
