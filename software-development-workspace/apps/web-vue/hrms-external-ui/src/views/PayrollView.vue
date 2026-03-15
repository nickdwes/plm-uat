<script setup lang="ts">
import { ref, onMounted } from "vue";
import { callFrappeMethod, type FrappeAuthConfig } from "../lib/frappe-client";
import { authStore } from "../stores/auth";
import type { PayrollRunRecord, PayslipRecord } from "../lib/types";
import CompanySelector from "../components/CompanySelector.vue";

type Tab = "runs" | "payslips";
const tab = ref<Tab>("runs");

const runs = ref<PayrollRunRecord[]>([]);
const payslips = ref<PayslipRecord[]>([]);
const loading = ref(false);
const error = ref("");
const selectedRun = ref<string | null>(null);

function authConfig(): FrappeAuthConfig {
  return {
    baseUrl: authStore.baseUrl,
    apiKey: authStore.apiKey || undefined,
    apiSecret: authStore.apiSecret || undefined,
  };
}

async function loadRuns(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    const result = await callFrappeMethod<{ items: PayrollRunRecord[] }>(
      authConfig(),
      "bitwyse_hrms_backend.api.list_payroll_runs",
      { company: authStore.company ?? undefined },
    );
    runs.value = result.items ?? [];
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load payroll runs.";
  } finally {
    loading.value = false;
  }
}

async function loadPayslips(runName?: string): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    const result = await callFrappeMethod<{ items: PayslipRecord[] }>(
      authConfig(),
      "bitwyse_hrms_backend.api.list_payslips",
      {
        company: authStore.company ?? undefined,
        payroll_run: runName ?? undefined,
      },
    );
    payslips.value = result.items ?? [];
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load payslips.";
  } finally {
    loading.value = false;
  }
}

function viewRunPayslips(runName: string): void {
  selectedRun.value = runName;
  tab.value = "payslips";
  void loadPayslips(runName);
}

function switchTab(t: Tab): void {
  tab.value = t;
  if (t === "runs") void loadRuns();
  if (t === "payslips") void loadPayslips();
}

function statusBadge(status: string): string {
  if (status === "Submitted") return "badge-green";
  if (status === "Cancelled") return "badge-red";
  return "badge-grey";
}

onMounted(loadRuns);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <h2>Payroll</h2>
      <CompanySelector @change="loadRuns" />
    </div>

    <div class="tab-bar">
      <button :class="{ active: tab === 'runs' }" @click="switchTab('runs')">Payroll Runs</button>
      <button :class="{ active: tab === 'payslips' }" @click="switchTab('payslips')">
        Payslips
        <span v-if="selectedRun" class="badge badge-grey">{{ selectedRun }}</span>
      </button>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error" class="error">{{ error }}</p>

    <!-- RUNS TAB -->
    <template v-if="tab === 'runs'">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Run ID</th><th>Company</th><th>Period</th><th>Start</th><th>End</th><th>Employees</th><th>Status</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="run in runs" :key="run.name">
              <td>{{ run.name }}</td>
              <td>{{ run.company }}</td>
              <td>{{ run.payroll_period ?? "-" }}</td>
              <td>{{ run.start_date }}</td>
              <td>{{ run.end_date }}</td>
              <td>{{ run.employee_count }}</td>
              <td>
                <span :class="['badge', statusBadge(run.status)]">{{ run.status }}</span>
              </td>
              <td>
                <button class="ghost small" @click="viewRunPayslips(run.name)">
                  View Payslips
                </button>
              </td>
            </tr>
            <tr v-if="runs.length === 0 && !loading">
              <td colspan="8" class="empty">No payroll runs found.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- PAYSLIPS TAB -->
    <template v-if="tab === 'payslips'">
      <div v-if="selectedRun" class="filter-bar">
        Showing payslips for run: <strong>{{ selectedRun }}</strong>
        <button class="ghost small" @click="selectedRun = null; loadPayslips()">Clear filter</button>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Payslip ID</th><th>Employee</th><th>Company</th><th>Gross</th><th>Net</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="slip in payslips" :key="slip.name">
              <td>{{ slip.name }}</td>
              <td>{{ slip.employee_name }}</td>
              <td>{{ slip.company }}</td>
              <td>{{ slip.gross_pay.toLocaleString("en-KE", { style: "currency", currency: "KES" }) }}</td>
              <td>{{ slip.net_pay.toLocaleString("en-KE", { style: "currency", currency: "KES" }) }}</td>
              <td>
                <span :class="['badge', statusBadge(slip.docstatus === 1 ? 'Submitted' : slip.docstatus === 2 ? 'Cancelled' : 'Draft')]">
                  {{ slip.docstatus === 1 ? "Submitted" : slip.docstatus === 2 ? "Cancelled" : "Draft" }}
                </span>
              </td>
            </tr>
            <tr v-if="payslips.length === 0 && !loading">
              <td colspan="6" class="empty">No payslips found.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
