<script setup lang="ts">
import { useRouter, useRoute } from "vue-router";
import { authStore, isCompanyHR, isManager, clearSession } from "../stores/auth";

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
  <aside class="app-sidebar">
    <div class="sidebar-logo">
      <div class="logo-icon">B</div>
      <span class="logo-text">Bitwyse HRMS</span>
    </div>

    <nav class="sidebar-nav">
      <router-link :to="{ name: 'dashboard' }" :class="['nav-item', { active: isActive('dashboard') }]">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M3 4a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm0 8a1 1 0 011-1h5a1 1 0 011 1v4a1 1 0 01-1 1H4a1 1 0 01-1-1v-4zm8-8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V4zm0 8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/></svg>
        Dashboard
      </router-link>
      <router-link v-if="isManager" :to="{ name: 'people' }" :class="['nav-item', { active: isActive('people') }]">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zm8 0a3 3 0 11-6 0 3 3 0 016 0zM.458 18C1.64 14.435 5.08 12 9 12s7.36 2.435 8.542 6H.458z"/></svg>
        People
      </router-link>
      <router-link v-if="isCompanyHR" :to="{ name: 'org' }" :class="['nav-item', { active: isActive('org') }]">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm3 1h2v2H7V5zm0 4h2v2H7V9zm0 4h2v2H7v-2zm4-8h2v2h-2V5zm0 4h2v2h-2V9zm0 4h2v2h-2v-2z"/></svg>
        Organisation
      </router-link>
      <router-link :to="{ name: 'leave' }" :class="['nav-item', { active: isActive('leave') }]">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zM4 8h12v8H4V8z"/></svg>
        Leave
      </router-link>
      <router-link v-if="isCompanyHR" :to="{ name: 'payroll' }" :class="['nav-item', { active: isActive('payroll') }]">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"/><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.077 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.077-2.354-1.253V5z" clip-rule="evenodd"/></svg>
        Payroll
      </router-link>
      <router-link v-if="isCompanyHR" :to="{ name: 'admin' }" :class="['nav-item', { active: isActive('admin') }]">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
        Admin
      </router-link>
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <div class="user-avatar">{{ (authStore.username || 'U')[0].toUpperCase() }}</div>
        <div class="user-info">
          <div class="user-name">{{ authStore.username || 'User' }}</div>
          <div class="user-role">{{ authStore.role || '' }}</div>
        </div>
      </div>
      <button class="sign-out-btn" @click="logout">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z" clip-rule="evenodd"/></svg>
        Sign Out
      </button>
    </div>
  </aside>
</template>

<style scoped>
.app-sidebar {
  width: 220px;
  min-width: 220px;
  height: 100vh;
  background: #fff;
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  position: sticky;
  top: 0;
}
.sidebar-logo {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 20px 16px 16px;
  border-bottom: 1px solid var(--border);
}
.logo-icon {
  width: 32px; height: 32px;
  background: var(--blue);
  color: white;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 18px;
}
.logo-text { font-weight: 700; font-size: 15px; color: var(--text); }

.sidebar-nav {
  flex: 1;
  padding: 12px 8px;
  display: flex;
  flex-direction: column;
  gap: 2px;
  overflow-y: auto;
}
.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
  border-radius: 8px;
  color: var(--text-muted);
  text-decoration: none;
  font-size: 14px;
  font-weight: 500;
  transition: background 0.15s, color 0.15s;
  border-left: 3px solid transparent;
}
.nav-item svg { width: 18px; height: 18px; flex-shrink: 0; }
.nav-item:hover { background: #f9fafb; color: var(--text); }
.nav-item.active {
  background: var(--blue-light);
  color: var(--blue);
  border-left-color: var(--blue);
}

.sidebar-footer {
  padding: 12px 8px;
  border-top: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.sidebar-user {
  display: flex; align-items: center; gap: 10px;
  padding: 8px;
}
.user-avatar {
  width: 32px; height: 32px;
  background: var(--blue-light);
  color: var(--blue);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 13px;
  flex-shrink: 0;
}
.user-info { min-width: 0; }
.user-name { font-size: 13px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-role { font-size: 11px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sign-out-btn {
  display: flex; align-items: center; gap: 8px;
  width: 100%; padding: 8px 12px;
  background: none; border: none; border-radius: 8px;
  color: var(--text-muted); font-size: 14px; cursor: pointer;
  transition: background 0.15s;
}
.sign-out-btn svg { width: 16px; height: 16px; }
.sign-out-btn:hover { background: #fee2e2; color: #ef4444; }
</style>
