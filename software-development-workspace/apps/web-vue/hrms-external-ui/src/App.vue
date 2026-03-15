<script setup lang="ts">
import { computed, ref } from "vue";

import { callFrappeMethod, type FrappeAuthConfig } from "./lib/frappe-client";
import type { DashboardSummary, EmployeeListData } from "./lib/types";

const baseUrl = ref(import.meta.env.VITE_FRAPPE_BASE_URL ?? "");
const apiKey = ref("");
const apiSecret = ref("");
const search = ref("");

const summary = ref<DashboardSummary | null>(null);
const employees = ref<EmployeeListData | null>(null);
const loadingSummary = ref(false);
const loadingEmployees = ref(false);
const errorMessage = ref("");

const limitStart = ref(0);
const pageLength = ref(20);

const hasPreviousPage = computed(() => limitStart.value > 0);
const canQueryApi = computed(() => baseUrl.value.trim().length > 0);

function authConfig(): FrappeAuthConfig {
  return {
    baseUrl: baseUrl.value,
    apiKey: apiKey.value.trim() || undefined,
    apiSecret: apiSecret.value.trim() || undefined,
  };
}

function asMessage(error: unknown): string {
  if (error instanceof Error) {
    return error.message;
  }
  return "Request failed with an unknown error.";
}

async function fetchSummary(): Promise<void> {
  if (!canQueryApi.value) {
    errorMessage.value = "Set a Frappe backend URL before loading data.";
    return;
  }

  loadingSummary.value = true;
  errorMessage.value = "";

  try {
    summary.value = await callFrappeMethod<DashboardSummary>(
      authConfig(),
      "bitwyse_hrms_backend.api.hrms_dashboard_summary",
    );
  } catch (error) {
    errorMessage.value = asMessage(error);
  } finally {
    loadingSummary.value = false;
  }
}

async function fetchEmployees(resetPage = false): Promise<void> {
  if (!canQueryApi.value) {
    errorMessage.value = "Set a Frappe backend URL before loading data.";
    return;
  }

  if (resetPage) {
    limitStart.value = 0;
  }

  loadingEmployees.value = true;
  errorMessage.value = "";

  try {
    employees.value = await callFrappeMethod<EmployeeListData>(
      authConfig(),
      "bitwyse_hrms_backend.api.list_employees",
      {
        search: search.value.trim() || undefined,
        limit_start: limitStart.value,
        limit_page_length: pageLength.value,
      },
    );
  } catch (error) {
    errorMessage.value = asMessage(error);
  } finally {
    loadingEmployees.value = false;
  }
}

function nextPage(): void {
  limitStart.value += pageLength.value;
  void fetchEmployees(false);
}

function previousPage(): void {
  limitStart.value = Math.max(0, limitStart.value - pageLength.value);
  void fetchEmployees(false);
}
</script>

<template>
  <main class="app-shell">
    <section class="hero">
      <p class="tag">Bitwyse HRMS Workspace</p>
      <h1>External HRMS UI</h1>
      <p class="lead">
        This frontend consumes custom Frappe endpoints so your UI can evolve independently from ERPNext screens.
      </p>
    </section>

    <section class="panel">
      <h2>Connection</h2>
      <div class="grid two-col">
        <label>
          Frappe Base URL
          <input v-model="baseUrl" type="url" placeholder="https://hrms-be.bitwyse.co.ke" />
        </label>

        <label>
          Search Employee
          <input v-model="search" type="text" placeholder="Name, employee ID, or company" />
        </label>

        <label>
          API Key (optional if session-authenticated)
          <input v-model="apiKey" type="text" placeholder="api_key" />
        </label>

        <label>
          API Secret (optional if session-authenticated)
          <input v-model="apiSecret" type="password" placeholder="api_secret" />
        </label>
      </div>

      <div class="actions">
        <button class="primary" :disabled="loadingSummary" @click="fetchSummary">
          {{ loadingSummary ? "Loading summary..." : "Load HRMS Summary" }}
        </button>
        <button class="secondary" :disabled="loadingEmployees" @click="fetchEmployees(true)">
          {{ loadingEmployees ? "Loading employees..." : "Load Employees" }}
        </button>
      </div>

      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
    </section>

    <section class="metrics panel">
      <h2>Summary</h2>
      <div class="metric-grid">
        <article class="metric-card">
          <p class="metric-label">Company</p>
          <p class="metric-value">{{ summary?.company ?? "-" }}</p>
        </article>
        <article class="metric-card">
          <p class="metric-label">Employees</p>
          <p class="metric-value">{{ summary?.total_employees ?? 0 }}</p>
        </article>
        <article class="metric-card">
          <p class="metric-label">Salary Structures</p>
          <p class="metric-value">{{ summary?.total_salary_structures ?? 0 }}</p>
        </article>
        <article class="metric-card">
          <p class="metric-label">Payroll Periods</p>
          <p class="metric-value">{{ summary?.total_payroll_periods ?? 0 }}</p>
        </article>
      </div>
    </section>

    <section class="panel">
      <div class="table-head">
        <h2>Employees</h2>
        <div class="actions compact">
          <button class="ghost" :disabled="!hasPreviousPage || loadingEmployees" @click="previousPage">Previous</button>
          <button class="ghost" :disabled="loadingEmployees" @click="nextPage">Next</button>
        </div>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Employee ID</th>
              <th>Name</th>
              <th>Company</th>
              <th>Department</th>
              <th>Designation</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="employee in employees?.items ?? []" :key="employee.name">
              <td>{{ employee.name }}</td>
              <td>{{ employee.employee_name }}</td>
              <td>{{ employee.company }}</td>
              <td>{{ employee.department || "-" }}</td>
              <td>{{ employee.designation || "-" }}</td>
              <td>{{ employee.status }}</td>
            </tr>
            <tr v-if="(employees?.items?.length ?? 0) === 0">
              <td colspan="6" class="empty">No employee records found for this query.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</template>
