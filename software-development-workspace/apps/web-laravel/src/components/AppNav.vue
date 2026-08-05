<script setup lang="ts">
import { computed, ref, watch } from "vue";
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

// ── Collapsible groups ────────────────────────────────────────────────────────
// Each group auto-opens when a child route is active
const employeesOpen  = ref(isActive('employee') || isActive('onboard') || isActive('preboarding') || isActive('roster') || isActive('payroll') || route.name === 'my-info');
const leaveOpen      = ref(route.name === 'leave' || isActive('leave-approvals') || route.name === 'leave-admin');
const performanceOpen = ref(isActive('performance') || isActive('goals') || route.name === 'okr-cycles');
const travelOpen      = ref(isActive('travel'));
const trainingOpen   = ref(isActive('training'));
const vendorOpen     = ref(isActive('vendor'));
const orgOpen        = ref(isActive('org'));

// Re-expand groups when navigating to a child route directly
watch(() => route.name, () => {
  if (isActive('employee') || isActive('onboard') || isActive('preboarding') || isActive('roster') || isActive('payroll') || route.name === 'my-info') employeesOpen.value = true;
  if (route.name === 'leave' || isActive('leave-approvals') || route.name === 'leave-admin') leaveOpen.value = true;
  if (isActive('performance') || isActive('goals') || route.name === 'okr-cycles') performanceOpen.value = true;
  if (isActive('training')) trainingOpen.value = true;
  if (isActive('vendor')) vendorOpen.value = true;
  if (isActive('org')) orgOpen.value = true;
});
</script>

<template>
  <aside class="app-sidebar">
    <div class="sidebar-logo">
      <img :src="logoSrc" alt="Organisation Logo" class="sidebar-logo-img" />
    </div>

    <nav class="sidebar-nav">
      <!-- Dashboard -->
      <router-link :to="{ name: 'hr-dashboard' }" :class="['nav-item', { active: isActive('hr-dashboard') }]">
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path d="M3 4a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm0 8a1 1 0 011-1h5a1 1 0 011 1v4a1 1 0 01-1 1H4a1 1 0 01-1-1v-4zm8-8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V4zm0 8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
        </svg>
        Dashboard
      </router-link>

      <!-- Notifications sits with Dashboard rather than under People: it is
           cross-cutting, and will carry Projects, Assets and Travel events
           once those modules land. -->
      <router-link :to="{ name: 'notifications' }" :class="['nav-item', { active: route.name === 'notifications' }]">
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zm0 16a2 2 0 001.732-1H8.268A2 2 0 0010 18z" />
        </svg>
        Notifications
      </router-link>

      <!-- ── Section: People ─────────────────────────────────────────────────── -->
      <div class="sidebar-divider"></div>
      <div class="sidebar-section">People</div>

      <!-- ── Employees ──────────────────────────────────────────────────────── -->
      <button
        :class="['nav-item', 'nav-group-toggle', { 'group-active': isActive('employee') || isActive('onboard') || isActive('preboarding') || isActive('roster') || isActive('payroll') || route.name === 'my-info' }]"
        @click="employeesOpen = !employeesOpen"
      >
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zm8 0a3 3 0 11-6 0 3 3 0 016 0zM.458 18C1.64 14.435 5.08 12 9 12s7.36 2.435 8.542 6H.458z" />
        </svg>
        <span>Employees</span>
        <svg class="chevron" :class="{ open: employeesOpen }" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
      <div class="nav-group-children" :class="{ open: employeesOpen }">
        <router-link :to="{ name: 'my-info' }" :class="['nav-item', 'nav-child', { active: route.name === 'my-info' }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
          </svg>
          My Info
        </router-link>
        <router-link :to="{ name: 'employees' }" :class="['nav-item', 'nav-child', { active: isActive('employee') }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zm8 0a3 3 0 11-6 0 3 3 0 016 0zM.458 18C1.64 14.435 5.08 12 9 12s7.36 2.435 8.542 6H.458z" />
          </svg>
          All Employees
        </router-link>
        <router-link :to="{ name: 'onboard' }" :class="['nav-item', 'nav-child', { active: isActive('onboard') }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd" />
          </svg>
          Onboard
        </router-link>
        <router-link :to="{ name: 'preboarding' }" :class="['nav-item', 'nav-child', { active: isActive('preboarding') }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0z" />
            <path fill-rule="evenodd" d="M17.707 9.293a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0l-2-2a1 1 0 011.414-1.414L13 12.586l3.293-3.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            <path d="M11 5.072A4.992 4.992 0 007 3a4.992 4.992 0 00-4 2 6 6 0 00-2 4.5V10a1 1 0 001 1h10a1 1 0 001-1v-.5A6 6 0 0011 5.072z" />
          </svg>
          Pre-Onboarding
        </router-link>
        <router-link :to="{ name: 'roster' }" :class="['nav-item', 'nav-child', { active: isActive('roster') }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zM4 8h12v8H4V8zm5 2a1 1 0 000 2h2a1 1 0 100-2H9zm-1 4a1 1 0 011-1h4a1 1 0 110 2H9a1 1 0 01-1-1z" clip-rule="evenodd" />
          </svg>
          Roster
        </router-link>
        <router-link :to="{ name: 'payroll' }" :class="['nav-item', 'nav-child', { active: isActive('payroll') }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.077 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.077-2.354-1.253V5z" clip-rule="evenodd" />
          </svg>
          Payroll
        </router-link>
      </div>

      <!-- ── Leave ──────────────────────────────────────────────────────────── -->
      <button
        :class="['nav-item', 'nav-group-toggle', { 'group-active': route.name === 'leave' || isActive('leave-approvals') || route.name === 'leave-admin' }]"
        @click="leaveOpen = !leaveOpen"
      >
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zM4 8h12v8H4V8z" />
        </svg>
        <span>Leave</span>
        <svg class="chevron" :class="{ open: leaveOpen }" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
      <div class="nav-group-children" :class="{ open: leaveOpen }">
        <router-link :to="{ name: 'leave' }" :class="['nav-item', 'nav-child', { active: route.name === 'leave' }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zM4 8h12v8H4V8z" />
          </svg>
          My Leave
        </router-link>
        <router-link :to="{ name: 'leave-approvals' }" :class="['nav-item', 'nav-child', { active: isActive('leave-approvals') }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414L8.414 15l-5.121-5.121a1 1 0 011.414-1.414L8.414 12.172l6.879-6.879a1 1 0 011.414 0z" clip-rule="evenodd" />
          </svg>
          Approvals
        </router-link>
        <router-link :to="{ name: 'leave-admin' }" :class="['nav-item', 'nav-child', { active: route.name === 'leave-admin' }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" />
          </svg>
          Leave Admin
        </router-link>
      </div>

      <!-- ── Performance ────────────────────────────────────────────────────── -->
      <button
        :class="['nav-item', 'nav-group-toggle', { 'group-active': isActive('performance') || isActive('goals') || route.name === 'okr-cycles' }]"
        @click="performanceOpen = !performanceOpen"
      >
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path d="M2 10a8 8 0 018-8v8h8a8 8 0 11-16 0z" />
          <path d="M12 2.252A8.014 8.014 0 0117.748 8H12V2.252z" />
        </svg>
        <span>Performance</span>
        <svg class="chevron" :class="{ open: performanceOpen }" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
      <div class="nav-group-children" :class="{ open: performanceOpen }">
        <router-link :to="{ name: 'performance' }" :class="['nav-item', 'nav-child', { active: isActive('performance') && !isActive('goals') }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path d="M2 10a8 8 0 018-8v8h8a8 8 0 11-16 0z" />
            <path d="M12 2.252A8.014 8.014 0 0117.748 8H12V2.252z" />
          </svg>
          Overview
        </router-link>
        <router-link :to="{ name: 'goals' }" :class="['nav-item', 'nav-child', { active: isActive('goals') }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm0-2a6 6 0 100-12 6 6 0 000 12zm0-2a4 4 0 100-8 4 4 0 000 8zm0-2a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
          </svg>
          Goals
        </router-link>
        <router-link :to="{ name: 'okr-cycles' }" :class="['nav-item', 'nav-child', { active: route.name === 'okr-cycles' }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path d="M9 4.804A7.968 7.968 0 005.5 4c-1.255 0-2.443.29-3.5.804v10A7.969 7.969 0 015.5 14c1.669 0 3.218.51 4.5 1.385A7.962 7.962 0 0114.5 14c1.255 0 2.443.29 3.5.804v-10A7.968 7.968 0 0014.5 4c-1.255 0-2.443.29-3.5.804V12a1 1 0 11-2 0V4.804z" />
          </svg>
          OKR Cycles
        </router-link>
      </div>

      <!-- ── Recruitment ─────────────────────────────────────────────────────── -->
      <router-link :to="{ name: 'recruitment' }" :class="['nav-item', { active: isActive('recruitment') || isActive('vacanc') || isActive('candidate') }]">
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v3.57A22.952 22.952 0 0110 13a22.95 22.95 0 01-8-1.43V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z" clip-rule="evenodd" />
          <path d="M2 13.692V16a2 2 0 002 2h12a2 2 0 002-2v-2.308A24.974 24.974 0 0110 15c-2.796 0-5.487-.46-8-1.308z" />
        </svg>
        Recruitment
      </router-link>

      <!-- ── Training & Competency ───────────────────────────────────────────── -->
      <button
        :class="['nav-item', 'nav-group-toggle', { 'group-active': isActive('training') }]"
        @click="trainingOpen = !trainingOpen"
      >
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path d="M6 4a2 2 0 012-2h6a2 2 0 012 2v14a2 2 0 01-2 2H8a2 2 0 01-2-2V4zm2 0h6v14H8V4z" />
          <path d="M5 13a1 1 0 10-2 0v4a1 1 0 102 0v-4z" />
        </svg>
        <span>Training & Competency</span>
        <svg class="chevron" :class="{ open: trainingOpen }" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
      <div class="nav-group-children" :class="{ open: trainingOpen }">
        <router-link :to="{ name: 'training' }" :class="['nav-item', 'nav-child', { active: route.name === 'training' }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path d="M6 4a2 2 0 012-2h6a2 2 0 012 2v14a2 2 0 01-2 2H8a2 2 0 01-2-2V4zm2 0h6v14H8V4z" />
            <path d="M5 13a1 1 0 10-2 0v4a1 1 0 102 0v-4z" />
          </svg>
          Catalog
        </router-link>
        <router-link :to="{ name: 'training-approvals' }" :class="['nav-item', 'nav-child', { active: isActive('training-approvals') }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414L8.414 15l-5.121-5.121a1 1 0 011.414-1.414L8.414 12.172l6.879-6.879a1 1 0 011.414 0z" clip-rule="evenodd" />
          </svg>
          Approvals
        </router-link>
      </div>

      <!-- ── Section: Operations ─────────────────────────────────────────────── -->
      <!-- Modules ported from Plmv2. Travel, then Projects and Tasks, follow
           here as they land — see docs/plm-modules-implementation-plan.md. -->
      <div class="sidebar-divider"></div>
      <div class="sidebar-section">Operations</div>

      <!-- ── Travel ─────────────────────────────────────────────────────────── -->
      <button
        :class="['nav-item', 'nav-group-toggle', { 'group-active': isActive('travel') }]"
        @click="travelOpen = !travelOpen"
      >
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor"
             stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M2.5 11.5l15-5-5 15-2.5-6.5-7.5-3.5z" />
        </svg>
        <span>Travel</span>
        <svg class="chevron" :class="{ open: travelOpen }" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
      <div class="nav-group-children" :class="{ open: travelOpen }">
        <router-link :to="{ name: 'travel-requests' }" :class="['nav-item', 'nav-child', { active: route.name === 'travel-requests' || route.name === 'travel-request' || route.name === 'travel-request-new' }]">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor"
               stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M2.5 11.5l15-5-5 15-2.5-6.5-7.5-3.5z" />
          </svg>
          Travel Requests
        </router-link>
        <router-link :to="{ name: 'travel-settings' }" :class="['nav-item', 'nav-child', { active: route.name === 'travel-settings' }]">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor"
               stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="10" cy="10" r="2.5" />
            <path d="M10 2v2M10 16v2M18 10h-2M4 10H2M15.7 4.3l-1.4 1.4M5.7 14.3l-1.4 1.4M15.7 15.7l-1.4-1.4M5.7 5.7L4.3 4.3" />
          </svg>
          Configuration
        </router-link>
      </div>
      <router-link :to="{ name: 'assets' }" :class="['nav-item', { active: isActive('asset') }]">
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path d="M3 5a2 2 0 012-2h10a2 2 0 012 2v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5zm2 10a1 1 0 00-1 1 1 1 0 001 1h10a1 1 0 001-1 1 1 0 00-1-1H5z" />
        </svg>
        Assets
      </router-link>

      <!-- ── Section: Vendor Management ─────────────────────────────────────── -->
      <div class="sidebar-divider"></div>
      <div class="sidebar-section">Vendor Management</div>

      <button
        :class="['nav-item', 'nav-group-toggle', { 'group-active': isActive('vendor') }]"
        @click="vendorOpen = !vendorOpen"
      >
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1h2v2H7V5zm2 4H7v2h2V9zm2-4h2v2h-2V5zm2 4h-2v2h2V9z" clip-rule="evenodd"/>
        </svg>
        <span>Vendor Management</span>
        <svg class="chevron" :class="{ open: vendorOpen }" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
      <div class="nav-group-children" :class="{ open: vendorOpen }">
        <router-link :to="{ name: 'vendors' }" :class="['nav-item', 'nav-child', { active: isActive('vendor') && !isActive('vendor-compliance') }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1h2v2H7V5zm2 4H7v2h2V9zm2-4h2v2h-2V5zm2 4h-2v2h2V9z" clip-rule="evenodd"/>
          </svg>
          All Vendors
        </router-link>
        <router-link :to="{ name: 'vendor-compliance' }" :class="['nav-item', 'nav-child', { active: isActive('vendor-compliance') }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z" />
            <path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm9.707 5.707a1 1 0 00-1.414-1.414L9 12.586l-1.293-1.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
          </svg>
          Compliance
        </router-link>
      </div>

      <!-- Divider before settings -->
      <div class="sidebar-divider" style="margin-top: auto;"></div>

      <!-- ── Organisation ──────────────────────────────────────────────────────── -->
      <button
        :class="['nav-item', 'nav-group-toggle', { 'group-active': isActive('org') }]"
        @click="orgOpen = !orgOpen"
      >
        <svg viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1h2v2H7V5zm2 4H7v2h2V9zm2-4h2v2h-2V5zm2 4h-2v2h2V9z" clip-rule="evenodd" />
        </svg>
        <span>Organisation</span>
        <svg class="chevron" :class="{ open: orgOpen }" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
      <div class="nav-group-children" :class="{ open: orgOpen }">
        <router-link :to="{ name: 'org' }" :class="['nav-item', 'nav-child', { active: route.name === 'org' || isActive('org-profile') }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1h2v2H7V5zm2 4H7v2h2V9zm2-4h2v2h-2V5zm2 4h-2v2h2V9z" clip-rule="evenodd" />
          </svg>
          Organisation
        </router-link>
        <router-link :to="{ name: 'benefit-plans' }" :class="['nav-item', 'nav-child', { active: route.name === 'benefit-plans' }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z" clip-rule="evenodd" />
          </svg>
          Benefit Plans
        </router-link>
        <router-link :to="{ name: 'public-holidays' }" :class="['nav-item', 'nav-child', { active: route.name === 'public-holidays' }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd" />
          </svg>
          Public Holidays
        </router-link>
        <router-link :to="{ name: 'role-assignments' }" :class="['nav-item', 'nav-child', { active: route.name === 'role-assignments' }]">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
          </svg>
          Role Assignments
        </router-link>
      </div>
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

<style scoped>
/*
 * Light sidebar, implementing the Figma nav spec.
 *
 * The spec's literal hex values are mapped onto sidebar tokens rather than
 * inlined, so the nav themes with the rest of the application instead of
 * being a white panel pinned to a dark surface in dark mode.
 *
 * `@reference` gives this scoped block the theme so `@apply` resolves.
 * Repeated patterns stay as named classes — the nav renders 25+ rows, and a
 * dozen utilities per row would obscure the markup.
 */
@reference "../theme.css";

/* ── Shell ─────────────────────────────────────────────────────────────── */
.app-sidebar {
  @apply flex flex-col shrink-0 overflow-hidden;
  width: var(--sidebar-width);          /* 260px */
  background: var(--color-sidebar-bg);
  border-right: 1px solid var(--color-sidebar-divider);
}

/* Logo band: fixed 72px, its own rule off the rest. */
.sidebar-logo {
  @apply flex shrink-0 flex-col justify-center;
  height: 72px;
  padding: 0 20px;
  border-bottom: 1px solid var(--color-sidebar-divider);
}

.sidebar-logo-img {
  @apply h-auto object-contain;
  width: 182px;
  max-height: 44px;
}

.sidebar-nav {
  @apply flex flex-1 flex-col overflow-y-auto;
  gap: var(--space-1);
  padding: var(--space-2) var(--space-3) var(--space-4);
}

/*
 * The spec groups items into bordered bands. The existing markup uses a
 * divider element between flat groups instead, so the band effect is produced
 * with a full-bleed rule rather than restructuring 25 nav rows — the visual
 * result is the same and the navigation keeps working throughout.
 */
.sidebar-divider {
  @apply shrink-0;
  height: 1px;
  margin: var(--space-2) calc(var(--space-3) * -1) 0;
  background: var(--color-sidebar-divider);
}

.sidebar-section {
  @apply font-semibold uppercase;
  padding: var(--space-2) var(--space-3) 0;
  font-size: 12px;
  line-height: 18px;
  letter-spacing: -0.03em;
  color: var(--color-sidebar-section);
}

/* ── Nav items ─────────────────────────────────────────────────────────── */
.nav-item {
  @apply relative flex items-center font-medium no-underline;
  gap: var(--space-2);
  height: 40px;
  padding: 10px var(--space-3);
  border-radius: 12px;
  font-size: 14px;
  line-height: 20px;
  color: var(--color-sidebar-text);
  transition: background var(--transition-fast), color var(--transition-fast);
}

.nav-item svg {
  @apply size-5 shrink-0;
}

.nav-item:hover {
  background: var(--color-sidebar-hover-bg);
  color: var(--color-sidebar-text-hover);
}

/*
 * Active state carries three signals — rail, fill and colour — so it does not
 * depend on hue alone. The rail sits outside the rounded box, matching the
 * spec's separate 3px rectangle.
 */
.nav-item.active {
  background: var(--color-sidebar-active-bg);
  color: var(--color-sidebar-text-active);
}

.nav-item.active::before {
  @apply absolute;
  content: '';
  left: -8px;
  top: 2px;
  bottom: 2px;
  width: 3px;
  border-radius: 2px;
  background: var(--color-sidebar-active-border);
}

.nav-item.active svg { color: var(--color-sidebar-accent); }

.nav-item:focus-visible {
  @apply outline-2 outline-offset-[-2px];
  outline-color: var(--color-sidebar-active-border);
}

/* ── Collapsible groups ────────────────────────────────────────────────── */
.nav-group-toggle {
  @apply w-full cursor-pointer border-none bg-transparent text-left;
  font-family: inherit;
  font-size: inherit;
}

.nav-group-toggle.group-active {
  @apply font-semibold;
  color: var(--color-sidebar-text-active);
}

.nav-group-toggle > span { @apply flex-1; }

.chevron {
  @apply size-4 shrink-0 opacity-60;
  transition: transform var(--transition-base);
}

.chevron.open { @apply rotate-180; }

.nav-group-children {
  @apply flex max-h-0 flex-col overflow-hidden;
  gap: var(--space-1);
  transition: max-height 250ms ease;
}

.nav-group-children.open {
  max-height: 520px;
  margin-top: var(--space-1);
}

/* Children indent under their parent rather than carrying a bullet — the
   spec has no child treatment, and a dot competes with the active rail. */
.nav-child { padding-left: calc(var(--space-3) + 28px); }

/* ── Badge (hidden until a count is supplied) ──────────────────────────── */
.nav-badge {
  @apply ml-auto inline-flex items-center rounded-full font-medium;
  padding: 2px var(--space-2);
  font-size: 12px;
  line-height: 18px;
  background: var(--color-sidebar-active-bg);
  border: 1px solid var(--color-border-strong);
  color: var(--color-sidebar-text);
}

/* ── Footer ────────────────────────────────────────────────────────────── */
.sidebar-footer {
  @apply mt-auto flex shrink-0 flex-col;
  gap: var(--space-1);
  padding: var(--space-2) var(--space-3);
  border-top: 1px solid var(--color-sidebar-divider);
}

.sidebar-user {
  @apply flex items-center;
  gap: var(--space-2);
  padding: 10px var(--space-3);
}

.user-avatar {
  @apply flex size-8 shrink-0 items-center justify-center rounded-full font-semibold;
  font-size: 12px;
  background: var(--color-brand-light);
  color: var(--color-brand-dark);
}

.user-info { @apply min-w-0 flex-1; }

.user-name {
  @apply truncate font-medium;
  font-size: 14px;
  line-height: 20px;
  color: var(--color-sidebar-text-hover);
}

.user-role {
  @apply truncate;
  font-size: 12px;
  line-height: 18px;
  color: var(--color-sidebar-text);
}

.logout-btn {
  @apply flex w-full cursor-pointer items-center border-none bg-transparent font-medium;
  gap: var(--space-2);
  height: 40px;
  padding: 10px var(--space-3);
  border-radius: 12px;
  font-family: inherit;
  font-size: 14px;
  line-height: 20px;
  color: var(--color-sidebar-text);
  transition: background var(--transition-fast), color var(--transition-fast);
}

.logout-btn:hover {
  background: var(--color-sidebar-hover-bg);
  color: var(--color-sidebar-text-hover);
}

.logout-btn:focus-visible {
  @apply outline-2 outline-offset-[-2px];
  outline-color: var(--color-sidebar-active-border);
}

.logout-btn svg { @apply size-5 shrink-0; }

@media (prefers-reduced-motion: reduce) {
  .chevron, .nav-group-children, .nav-item, .logout-btn { transition: none; }
}
</style>

