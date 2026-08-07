<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { authStore } from "../stores/auth";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import OrgSelector from "./OrgSelector.vue";
import { useRouter } from "vue-router";
import { themePreference, resolvedTheme, setTheme, type ThemePreference } from "../stores/theme";

const router = useRouter();

// ── Theme ──────────────────────────────────────────────────────────────────
//
// Cycles light -> dark -> system. "System" is in the rotation rather than
// something you leave and cannot return to: someone who tries the other two
// out of curiosity should be able to hand the decision back to their OS.
//
// A single icon showing one of three states is not self-explanatory, so the
// label spells out both where you are and what a click does. It is the
// accessible name as well as the tooltip — an icon-only control that only
// announces "theme" tells a screen-reader user nothing about its state.
const THEME_ORDER: ThemePreference[] = ["light", "dark", "system"];

const THEME_LABEL: Record<ThemePreference, string> = {
  light:  "Light theme",
  dark:   "Dark theme",
  system: "Matching your system",
};

const themeLabel = computed(() => {
  const next = THEME_ORDER[(THEME_ORDER.indexOf(themePreference.value) + 1) % THEME_ORDER.length];
  const current = themePreference.value === "system"
    ? `Matching your system (${resolvedTheme.value})`
    : THEME_LABEL[themePreference.value];
  return `${current}. Switch to ${THEME_LABEL[next].toLowerCase()}.`;
});

function cycleTheme(): void {
  const i = THEME_ORDER.indexOf(themePreference.value);
  setTheme(THEME_ORDER[(i + 1) % THEME_ORDER.length]);
}

// ── Clock In / Out ─────────────────────────────────────────────────────────
const checkedIn    = ref(false);
const clockLoading = ref(false);

async function loadClockStatus(): Promise<void> {
  if (!currentOrgSlug.value) return;
  try {
    // Today's attendance day. `is_clocked_in` is answered by the server from
    // the punches, rather than the client inferring it from a missing time.
    const today = new Date().toISOString().slice(0, 10);
    const res = await api<{ data: { is_clocked_in: boolean | null }[] }>(
      "GET", "/api/v1/attendance", { org: currentOrgSlug.value, params: { from: today, to: today } }
    );
    checkedIn.value = res.data.some((d) => d.is_clocked_in === true);
  } catch {
    checkedIn.value = false;
  }
}

async function toggleClock(): Promise<void> {
  if (!currentOrgSlug.value || clockLoading.value) return;
  clockLoading.value = true;
  try {
    if (checkedIn.value) {
      await api("POST", "/api/v1/attendance/clock-out", { org: currentOrgSlug.value, body: {} });
      checkedIn.value = false;
    } else {
      await api("POST", "/api/v1/attendance/clock-in", {
        org: currentOrgSlug.value,
        body: { location_type: "office" },
      });
      checkedIn.value = true;
    }
  } catch {
    // ignore — button stays in current state
  } finally {
    clockLoading.value = false;
  }
}

onMounted(loadClockStatus);
</script>

<template>
  <header class="app-header">
    <div class="header-left">
      <OrgSelector />
    </div>
    <div class="header-right">
      <!-- Clock In / Clock Out -->
      <button
        class="clock-btn"
        :class="{ 'clock-out': checkedIn }"
        :disabled="clockLoading"
        type="button"
        @click="toggleClock"
      >
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path v-if="!checkedIn" fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
          <path v-else fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8 7a1 1 0 00-1 1v4a1 1 0 001 1h4a1 1 0 001-1V8a1 1 0 00-1-1H8z" clip-rule="evenodd"/>
        </svg>
        {{ checkedIn ? "Clock Out" : "Clock In" }}
      </button>

      <!-- Notification bell -->
      <button class="header-icon-btn" title="Notifications" type="button" @click="router.push({ name: 'notifications' })">
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" />
        </svg>
      </button>
      <!-- Theme -->
      <button
        class="header-icon-btn"
        type="button"
        :title="themeLabel"
        :aria-label="themeLabel"
        @click="cycleTheme"
      >
        <!-- Light: sun -->
        <svg v-if="themePreference === 'light'" viewBox="0 0 20 20" fill="none"
             stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
          <circle cx="10" cy="10" r="3.5" />
          <path d="M10 1.5v2M10 16.5v2M18.5 10h-2M3.5 10h-2M16 4l-1.4 1.4M5.4 14.6L4 16M16 16l-1.4-1.4M5.4 5.4L4 4" />
        </svg>
        <!-- Dark: moon -->
        <svg v-else-if="themePreference === 'dark'" viewBox="0 0 20 20" fill="none"
             stroke="currentColor" stroke-width="1.5" stroke-linejoin="round">
          <path d="M17 11.7A7.5 7.5 0 018.3 3a7.5 7.5 0 108.7 8.7z" />
        </svg>
        <!-- System: display -->
        <svg v-else viewBox="0 0 20 20" fill="none"
             stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <rect x="2.5" y="3.5" width="15" height="10" rx="1.5" />
          <path d="M7 17h6M10 13.5V17" />
        </svg>
      </button>
      <!-- Settings -->
      <button class="header-icon-btn" title="Settings" type="button">
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" />
        </svg>
      </button>
      <!-- User avatar -->
      <div class="header-avatar" :title="authStore.user?.name ?? 'User'">
        {{ (authStore.user?.name ?? "U")[0].toUpperCase() }}
      </div>
      <span class="header-username">{{ authStore.user?.name ?? "User" }}</span>
    </div>
  </header>
</template>

<style scoped>
.clock-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 14px;
  height: 34px;
  border-radius: var(--radius-lg);
  border: none;
  background: var(--color-brand-base);
  color: #ffffff;
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  font-family: inherit;
  cursor: pointer;
  transition: background var(--transition-fast), opacity var(--transition-fast);
  white-space: nowrap;
}

.clock-btn svg { width: 14px; height: 14px; flex-shrink: 0; }

.clock-btn:hover:not(:disabled) {
  background: var(--color-brand-hover);
}

.clock-btn:disabled {
  opacity: 0.6;
  cursor: default;
}

.clock-btn.clock-out {
  background: var(--color-surface);
  color: var(--color-brand-base);
  border: 1px solid var(--color-border);
}

.clock-btn.clock-out:hover:not(:disabled) {
  background: var(--color-neutral-50);
}
</style>
