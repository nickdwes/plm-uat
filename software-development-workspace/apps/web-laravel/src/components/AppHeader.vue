<script setup lang="ts">
import { authStore } from "../stores/auth";
import OrgSelector from "./OrgSelector.vue";
import { useRouter } from "vue-router";

const router = useRouter();
</script>

<template>
  <header class="app-header">
    <div class="header-left">
      <OrgSelector />
    </div>
    <div class="header-right">
      <!-- Notification bell — navigates to Notifications page -->
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
