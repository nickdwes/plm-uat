<script setup lang="ts">
import { computed } from "vue";
import { useRouter, useRoute } from "vue-router";
import { authStore, clearAuth } from "../stores/auth";
import { api } from "../lib/api";
import defaultLogo from "../assets/logo.png";

const router = useRouter();
const route = useRoute();

const logoSrc = computed(() =>
  authStore.currentOrg?.logo_url ?? defaultLogo
);

function isActive(name: string): boolean {
  return String(route.name ?? "").startsWith(name);
}

async function logout(): Promise<void> {
  try {
    await api("POST", "/api/logout");
  } catch {
    /* ignore — clear session regardless */
  }
  clearAuth();
  void router.push({ name: "login" });
}
</script>

<template>
  <aside class="app-sidebar">
    <div class="sidebar-logo">
      <img :src="logoSrc" alt="Organisation Logo" class="sidebar-logo-img" />
    </div>

    <nav class="sidebar-nav">
      <router-link :to="{ name: 'hr-dashboard' }" :class="['nav-item', { active: isActive('hr-dashboard') }]">
        <!-- Grid icon -->
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path d="M3 4a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm0 8a1 1 0 011-1h5a1 1 0 011 1v4a1 1 0 01-1 1H4a1 1 0 01-1-1v-4zm8-8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V4zm0 8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
        </svg>
        Dashboard
      </router-link>

      <router-link :to="{ name: 'employees' }" :class="['nav-item', { active: isActive('employee') }]">
        <!-- People icon -->
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zm8 0a3 3 0 11-6 0 3 3 0 016 0zM.458 18C1.64 14.435 5.08 12 9 12s7.36 2.435 8.542 6H.458z" />
        </svg>
        Employees
      </router-link>

      <router-link :to="{ name: 'onboard' }" :class="['nav-item', { active: isActive('onboard') }]">
        <!-- Plus icon -->
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd" />
        </svg>
        Onboard
      </router-link>

      <router-link :to="{ name: 'leave' }" :class="['nav-item', { active: route.name === 'leave' }]">
        <!-- Calendar icon -->
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zM4 8h12v8H4V8z" />
        </svg>
        Leave
      </router-link>

      <router-link :to="{ name: 'leave-approvals' }" :class="['nav-item', { active: isActive('leave-approvals') }]">
        <!-- Check icon -->
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414L8.414 15l-5.121-5.121a1 1 0 011.414-1.414L8.414 12.172l6.879-6.879a1 1 0 011.414 0z" clip-rule="evenodd" />
        </svg>
        Approvals
      </router-link>

      <router-link :to="{ name: 'payroll' }" :class="['nav-item', { active: isActive('payroll') }]">
        <!-- Money icon -->
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.077 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.077-2.354-1.253V5z" clip-rule="evenodd" />
        </svg>
        Payroll
      </router-link>

      <router-link :to="{ name: 'performance' }" :class="['nav-item', { active: isActive('performance') }]">
        <!-- Chart icon -->
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path d="M2 10a8 8 0 018-8v8h8a8 8 0 11-16 0z" />
          <path d="M12 2.252A8.014 8.014 0 0117.748 8H12V2.252z" />
        </svg>
        Performance
      </router-link>

      <router-link :to="{ name: 'goals' }" :class="['nav-item', { active: isActive('goals') }]">
        <!-- Target/Goals icon -->
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm0-2a6 6 0 100-12 6 6 0 000 12zm0-2a4 4 0 100-8 4 4 0 000 8zm0-2a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
        </svg>
        Goals
      </router-link>

      <router-link :to="{ name: 'roster' }" :class="['nav-item', { active: isActive('roster') }]">
        <!-- Roster / calendar-clock icon -->
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zM4 8h12v8H4V8zm5 2a1 1 0 000 2h2a1 1 0 100-2H9zm-1 4a1 1 0 011-1h4a1 1 0 110 2H9a1 1 0 01-1-1z" clip-rule="evenodd" />
        </svg>
        Roster
      </router-link>

      <router-link :to="{ name: 'recruitment' }" :class="['nav-item', { active: isActive('recruitment') || isActive('vacanc') || isActive('candidate') }]">
        <!-- Briefcase icon -->
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v3.57A22.952 22.952 0 0110 13a22.95 22.95 0 01-8-1.43V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z" clip-rule="evenodd" />
          <path d="M2 13.692V16a2 2 0 002 2h12a2 2 0 002-2v-2.308A24.974 24.974 0 0110 15c-2.796 0-5.487-.46-8-1.308z" />
        </svg>
        Recruitment
      </router-link>

      <router-link :to="{ name: 'preboarding' }" :class="['nav-item', { active: isActive('preboarding') }]">
        <!-- User-check / preboarding icon -->
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0z" />
          <path fill-rule="evenodd" d="M17.707 9.293a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0l-2-2a1 1 0 011.414-1.414L13 12.586l3.293-3.293a1 1 0 011.414 0z" clip-rule="evenodd" />
          <path d="M11 5.072A4.992 4.992 0 007 3a4.992 4.992 0 00-4 2 6 6 0 00-2 4.5V10a1 1 0 001 1h10a1 1 0 001-1v-.5A6 6 0 0011 5.072z" />
        </svg>
        Pre-Onboarding
      </router-link>

      <!-- Divider before support/settings -->
      <div class="sidebar-divider" style="margin-top: auto;"></div>

      <router-link :to="{ name: 'org' }" :class="['nav-item', { active: isActive('org') }]">
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1h2v2H7V5zm2 4H7v2h2V9zm2-4h2v2h-2V5zm2 4h-2v2h2V9z" clip-rule="evenodd" />
        </svg>
        Organisation
      </router-link>
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <div class="user-avatar">{{ (authStore.user?.name ?? "U")[0].toUpperCase() }}</div>
        <div class="user-info">
          <div class="user-name">{{ authStore.user?.name ?? "User" }}</div>
          <div class="user-role">{{ authStore.currentOrg?.name ?? "" }}</div>
        </div>
        <button class="logout-btn" title="Sign out" @click="logout">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 001 1h7a1 1 0 000-2H4V5h6a1 1 0 000-2H3zm10.293 4.293a1 1 0 011.414 0l3 3a1 1 0 010 1.414l-3 3a1 1 0 01-1.414-1.414L14.586 11H8a1 1 0 110-2h6.586l-1.293-1.293a1 1 0 010-1.414z" clip-rule="evenodd" />
          </svg>
        </button>
      </div>
    </div>
  </aside>
</template>
