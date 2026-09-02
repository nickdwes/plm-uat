<script setup lang="ts">
import { computed, watchEffect } from "vue";
import { useRoute } from "vue-router";
import AppNav from "./components/AppNav.vue";
import AppHeader from "./components/AppHeader.vue";
import ToastHost from "./components/ToastHost.vue";
import { isAuthenticated, authStore } from "./stores/auth";
import { resolvedTheme } from "./stores/theme";
import { deriveBrandPalette, readableOn } from "./lib/palette";

const route = useRoute();
/*
 * The portal renders bare even for a signed-in member of staff. A customer view
 * wrapped in the internal navigation is confusing to them and shows an outsider
 * the shape of the system when the link is forwarded.
 */
const showShell = computed(() =>
  isAuthenticated.value
  && route.name !== "login"
  && !String(route.name ?? "").startsWith("portal-"));

/**
 * Switching organisation reloads whatever is on screen.
 *
 * Every screen fetches its data once, on mount, for the organisation that was
 * in context at the time. Changing the selector used to update the store and
 * nothing else, so the page carried on showing the previous tenant's records —
 * or, more often, an empty board, because the new tenant has none of them and
 * nothing had gone back to ask.
 *
 * Keying the view on the organisation remounts it, which re-runs exactly the
 * load every screen already does. A full `location.reload()` would work too and
 * costs a white flash, a bundle re-parse and the sign-in state being read back
 * off disk — for the same result.
 */
const orgKey = computed(() => authStore.currentOrg?.slug ?? "no-org");

// ── Per-org theme ────────────────────────────────────────────────────────────

/*
 * An organisation chooses an accent, not a theme.
 *
 * Only brand tokens are overridden here. Surfaces, borders, text and the
 * feedback ramps stay as the design system defines them, and so does the
 * sidebar: this used to repaint it with the org colour at 38% brightness,
 * which is why it rendered dark navy instead of the white the design calls
 * for. Structural colour carries the product's legibility, and handing it to
 * whatever hex an administrator types into a settings form is how three of
 * four tenants ended up with unreadable buttons.
 *
 * What the organisation does get is the accent — active indicators, icons,
 * focus rings, filled controls — derived through `deriveBrandPalette`, which
 * enforces the contrast floor. They can make the product theirs; they cannot
 * make it unreadable.
 */
const BRAND_PROPS = [
  "--color-brand-base", "--color-brand-hover", "--color-brand-dark",
  "--color-brand-light", "--color-brand-subtle", "--color-brand-on-surface",
  "--color-on-brand",
  "--color-sidebar-active-border", "--color-sidebar-accent",
  "--color-sidebar-text-active",
] as const;

function applyOrgTheme(color: string | null | undefined, theme: "light" | "dark"): void {
  const root = document.documentElement;
  const palette = color ? deriveBrandPalette(color, theme) : null;

  if (!palette) {
    // No colour, or one that cannot be parsed: leave the design system's own
    // palette intact rather than a half-applied mixture of the two.
    BRAND_PROPS.forEach((prop) => root.style.removeProperty(prop));
    return;
  }

  root.style.setProperty("--color-brand-base", palette.base);
  root.style.setProperty("--color-brand-hover", palette.hover);
  root.style.setProperty("--color-brand-dark", palette.dark);
  root.style.setProperty("--color-brand-light", palette.light);
  root.style.setProperty("--color-brand-subtle", palette.subtle);
  root.style.setProperty("--color-brand-on-surface", palette.accent);
  root.style.setProperty("--color-on-brand", palette.onBrand);

  // The sidebar keeps its own background; only the marks identifying the
  // current page take the organisation's colour.
  root.style.setProperty("--color-sidebar-active-border", palette.accent);
  root.style.setProperty("--color-sidebar-accent", palette.accent);

  // The active label sits on the tinted active row rather than the plain
  // surface, so it is measured against that instead.
  const activeRow = theme === "dark" ? "#16202E" : "#F1F5F9";
  const activeText = readableOn(color as string, activeRow);
  if (activeText) root.style.setProperty("--color-sidebar-text-active", activeText);
}

// Re-runs when the organisation changes and when the theme does — a colour
// tuned for a white ground is not the one to use on a near-black one.
watchEffect(() => applyOrgTheme(authStore.currentOrg?.primary_color, resolvedTheme.value));
</script>

<template>
  <div :class="showShell ? 'app-layout' : 'app-login-wrapper'">
    <AppNav v-if="showShell" />
    <div v-if="showShell" class="app-main">
      <AppHeader />
      <div class="app-content">
        <router-view :key="orgKey" />
      </div>
    </div>
    <router-view v-else />

    <!-- Mounted on the shell, not per screen: a notification raised during
         navigation has to survive the route change that caused it. -->
    <ToastHost />
  </div>
</template>
