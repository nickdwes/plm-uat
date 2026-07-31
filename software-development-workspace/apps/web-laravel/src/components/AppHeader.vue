<script setup lang="ts">
import { ref, onMounted } from "vue";
import { authStore } from "../stores/auth";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import OrgSelector from "./OrgSelector.vue";
import { useRouter } from "vue-router";

const router = useRouter();

// ── Clock In / Out ─────────────────────────────────────────────────────────
const checkedIn    = ref(false);
const clockLoading = ref(false);

async function loadClockStatus(): Promise<void> {
  if (!currentOrgSlug.value) return;
  try {
    const res = await api<{ data: { checked_out_at: string | null } | null }>(
      "GET", "/api/v1/work-location/today", { org: currentOrgSlug.value }
    );
    checkedIn.value = !!(res.data && !res.data.checked_out_at);
  } catch {
    checkedIn.value = false;
  }
}

async function toggleClock(): Promise<void> {
  if (!currentOrgSlug.value || clockLoading.value) return;
  clockLoading.value = true;
  try {
    if (checkedIn.value) {
      await api("POST", "/api/v1/work-location/check-out", { org: currentOrgSlug.value, body: {} });
      checkedIn.value = false;
    } else {
      await api("POST", "/api/v1/work-location/check-in", {
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
