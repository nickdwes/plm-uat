<script setup lang="ts">
import { useRouter, useRoute } from "vue-router";
import {
  authStore,
  isCompanyHR,
  isManager,
  isGlobalHR,
  clearSession,
} from "../stores/auth";

const router = useRouter();
const route = useRoute();

function isActive(name: string): boolean {
  return route.name === name || String(route.name ?? "").startsWith(name);
}

function logout(): void {
  clearSession();
  void router.push({ name: "login" });
}
</script>

<template>
  <nav class="app-nav">
    <div class="nav-header">
      <span class="nav-logo">⚡ Bitwyse HRMS</span>
      <span v-if="authStore.company" class="nav-company">{{ authStore.company }}</span>
    </div>

    <ul class="nav-links">
      <li>
        <router-link :to="{ name: 'dashboard' }" :class="{ active: isActive('dashboard') }">
          🏠 Dashboard
        </router-link>
      </li>
      <li v-if="isManager">
        <router-link :to="{ name: 'people' }" :class="{ active: isActive('people') }">
          👥 People
        </router-link>
      </li>
      <li v-if="isCompanyHR">
        <router-link :to="{ name: 'org' }" :class="{ active: isActive('org') }">
          🏢 Organisation
        </router-link>
      </li>
      <li>
        <router-link :to="{ name: 'leave' }" :class="{ active: isActive('leave') }">
          🌴 Leave
        </router-link>
      </li>
      <li v-if="isCompanyHR">
        <router-link :to="{ name: 'payroll' }" :class="{ active: isActive('payroll') }">
          💰 Payroll
        </router-link>
      </li>
      <li v-if="isCompanyHR">
        <router-link :to="{ name: 'admin' }" :class="{ active: isActive('admin') }">
          ⚙️ Admin
        </router-link>
      </li>
    </ul>

    <div class="nav-footer">
      <span v-if="authStore.username" class="nav-user">{{ authStore.username }}</span>
      <span v-if="authStore.role" class="nav-role">{{ authStore.role }}</span>
      <button class="ghost small" @click="logout">Sign out</button>
    </div>
  </nav>
</template>
