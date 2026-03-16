<script setup lang="ts">
import { ref, onMounted } from "vue";
import { callFrappeMethod, type FrappeAuthConfig } from "../lib/frappe-client";
import { authStore, isCompanyHR } from "../stores/auth";
import type { DashboardSummary } from "../lib/types";
import CompanySelector from "../components/CompanySelector.vue";

const summary = ref<DashboardSummary | null>(null);
const loading = ref(false);
const error = ref("");

function authConfig(): FrappeAuthConfig {
  return {
    baseUrl: authStore.baseUrl,
    apiKey: authStore.apiKey || undefined,
    apiSecret: authStore.apiSecret || undefined,
  };
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    summary.value = await callFrappeMethod<DashboardSummary>(
      authConfig(),
      "bitwyse_hrms_backend.api.hrms_dashboard_summary",
      authStore.company ? { company: authStore.company } : {},
    );
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load summary.";
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <h2>Dashboard</h2>
      <CompanySelector @change="load" />
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error" class="error">{{ error }}</p>

    <div v-if="summary" class="metrics-grid">
      <article class="metric-card">
        <p class="metric-label">Company</p>
        <p class="metric-value">{{ summary.company ?? "All" }}</p>
      </article>
      <article class="metric-card">
        <p class="metric-label">Employees</p>
        <p class="metric-value">{{ summary.total_employees }}</p>
      </article>
      <article class="metric-card">
        <p class="metric-label">Salary Structures</p>
        <p class="metric-value">{{ summary.total_salary_structures }}</p>
      </article>
      <article class="metric-card">
        <p class="metric-label">Payroll Periods</p>
        <p class="metric-value">{{ summary.total_payroll_periods }}</p>
      </article>
    </div>

    <div v-if="isCompanyHR" class="quick-actions">
      <h3 class="section-title">Quick Actions</h3>
      <div class="actions-grid">
        <router-link :to="{ name: 'people' }" class="action-card">
          <span class="action-icon">👥</span>
          <span class="action-label">View Employees</span>
        </router-link>
        <router-link :to="{ name: 'leave' }" class="action-card">
          <span class="action-icon">🌴</span>
          <span class="action-label">Leave Requests</span>
        </router-link>
        <router-link :to="{ name: 'payroll' }" class="action-card">
          <span class="action-icon">💰</span>
          <span class="action-label">Payroll Runs</span>
        </router-link>
        <router-link :to="{ name: 'org' }" class="action-card">
          <span class="action-icon">🏢</span>
          <span class="action-label">Organisation</span>
        </router-link>
      </div>
    </div>
  </div>
</template>
