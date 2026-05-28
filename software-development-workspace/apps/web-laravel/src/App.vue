<script setup lang="ts">
import { computed, watchEffect } from "vue";
import { useRoute } from "vue-router";
import AppNav from "./components/AppNav.vue";
import AppHeader from "./components/AppHeader.vue";
import { isAuthenticated, authStore } from "./stores/auth";

const route = useRoute();
const showShell = computed(() => isAuthenticated.value && route.name !== "login");

// ── Per-org theme ────────────────────────────────────────────────────────────

function hexToRgb(hex: string): { r: number; g: number; b: number } | null {
  const m = hex.match(/^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i);
  return m
    ? { r: parseInt(m[1], 16), g: parseInt(m[2], 16), b: parseInt(m[3], 16) }
    : null;
}

function applyOrgTheme(color: string | null | undefined): void {
  const root = document.documentElement;
  const PROPS = [
    "--color-brand-base", "--color-brand-hover", "--color-brand-dark",
    "--color-brand-light", "--color-brand-subtle",
    "--color-sidebar-bg", "--color-sidebar-hover-bg",
    "--color-sidebar-active-border", "--color-sidebar-active-bg",
  ];

  if (!color) {
    PROPS.forEach((p) => root.style.removeProperty(p));
    return;
  }

  const rgb = hexToRgb(color);
  if (!rgb) return;
  const { r, g, b } = rgb;

  root.style.setProperty("--color-brand-base",  color);
  root.style.setProperty("--color-brand-hover",  `rgb(${Math.round(r * 0.82)},${Math.round(g * 0.82)},${Math.round(b * 0.82)})`);
  root.style.setProperty("--color-brand-dark",   `rgb(${Math.round(r * 0.62)},${Math.round(g * 0.62)},${Math.round(b * 0.62)})`);
  root.style.setProperty("--color-brand-light",  `rgba(${r},${g},${b},0.14)`);
  root.style.setProperty("--color-brand-subtle", `rgba(${r},${g},${b},0.08)`);

  // Sidebar background: a dark tint of the primary colour (38% brightness)
  // keeps white text readable while reflecting the org brand
  root.style.setProperty("--color-sidebar-bg",           `rgb(${Math.round(r * 0.38)},${Math.round(g * 0.38)},${Math.round(b * 0.38)})`);
  root.style.setProperty("--color-sidebar-hover-bg",     `rgba(${r},${g},${b},0.14)`);
  root.style.setProperty("--color-sidebar-active-border", color);
  root.style.setProperty("--color-sidebar-active-bg",    `rgba(${r},${g},${b},0.25)`);
}

watchEffect(() => applyOrgTheme(authStore.currentOrg?.primary_color));
</script>

<template>
  <div :class="showShell ? 'app-layout' : 'app-login-wrapper'">
    <AppNav v-if="showShell" />
    <div v-if="showShell" class="app-main">
      <AppHeader />
      <div class="app-content">
        <router-view />
      </div>
    </div>
    <router-view v-else />
  </div>
</template>
