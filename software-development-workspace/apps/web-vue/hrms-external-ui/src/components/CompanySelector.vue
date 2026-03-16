<script setup lang="ts">
import { ref } from "vue";
import { authStore, isGlobalHR, setCompany, setSession } from "../stores/auth";
import { callFrappeMethod } from "../lib/frappe-client";

const refreshing = ref(false);

async function refreshCompanies() {
  refreshing.value = true;
  try {
    const result = await callFrappeMethod<{ items: { name: string }[] }>(
      { baseUrl: authStore.baseUrl, apiKey: authStore.apiKey, apiSecret: authStore.apiSecret },
      "bitwyse_hrms_backend.api.list_companies",
      {},
    );
    const names = result.items.map((c) => c.name);
    setSession(authStore.role!, authStore.username!, names, authStore.company ?? names[0] ?? null);
  } catch {
    // silently ignore
  } finally {
    refreshing.value = false;
  }
}
</script>

<template>
  <div v-if="authStore.companies.length > 1 || isGlobalHR" class="company-selector">
    <label for="company-select">Company</label>
    <select
      id="company-select"
      :value="authStore.company ?? ''"
      @change="setCompany(($event.target as HTMLSelectElement).value || null)"
    >
      <option v-if="isGlobalHR" value="">All companies</option>
      <option v-for="c in authStore.companies" :key="c" :value="c">{{ c }}</option>
    </select>
    <button class="refresh-btn" :disabled="refreshing" @click="refreshCompanies" title="Reload company list">
      {{ refreshing ? "…" : "⟳" }}
    </button>
  </div>
</template>

<style scoped>
.refresh-btn {
  margin-left: 6px;
  background: none;
  border: 1px solid currentColor;
  border-radius: 4px;
  cursor: pointer;
  padding: 2px 7px;
  font-size: 1rem;
  opacity: 0.7;
}
.refresh-btn:hover { opacity: 1; }
.refresh-btn:disabled { cursor: wait; }
</style>
