<script setup lang="ts">
import CompanySelector from "./CompanySelector.vue";
import { authStore } from "../stores/auth";
import { useRoute } from "vue-router";
import { computed } from "vue";

const route = useRoute();
const pageTitle = computed(() => {
  const names: Record<string, string> = {
    dashboard: "Dashboard", people: "People", org: "Organisation",
    leave: "Leave", payroll: "Payroll", admin: "Admin",
  };
  return names[String(route.name)] ?? String(route.name ?? "");
});
</script>

<template>
  <header class="app-header">
    <h1 class="page-title">{{ pageTitle }}</h1>
    <div class="header-right">
      <CompanySelector />
      <div class="header-avatar">{{ (authStore.username || 'U')[0].toUpperCase() }}</div>
    </div>
  </header>
</template>

<style scoped>
.app-header {
  height: 60px;
  background: #fff;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 28px;
  flex-shrink: 0;
}
.page-title { font-size: 20px; font-weight: 700; color: var(--text); margin: 0; }
.header-right { display: flex; align-items: center; gap: 16px; }
.header-avatar {
  width: 36px; height: 36px;
  background: var(--blue);
  color: white;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 14px; cursor: pointer;
}
</style>
