<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { api, ApiError } from "../lib/api";
import { authStore, currentOrgSlug } from "../stores/auth";
import type { Paginator, Employee, LeaveRequest, PayrollPeriod, LeaveBalanceResponse } from "../lib/types";

interface Summary {
  employeeTotal: number | null;
  pendingLeave: number | null;
  approvedLeave: number | null;
  balances: { leave_type: string; available_days: number }[];
  recentPeriods: PayrollPeriod[];
}

const summary = ref<Summary>({
  employeeTotal:  null,
  pendingLeave:   null,
  approvedLeave:  null,
  balances:       [],
  recentPeriods:  [],
});
const loading = ref(false);
const errors  = ref<string[]>([]);

function org(): string | null { return currentOrgSlug.value; }

async function load(): Promise<void> {
  if (!org()) return;
  loading.value = true;
  errors.value  = [];

  const results = await Promise.allSettled([
    // Employees count (HR/manager only — 403 handled gracefully)
    api<Paginator<Employee>>("GET", "/api/v1/employees", { org: org(), params: { per_page: 1 } }),
    // My pending leave count
    api<Paginator<LeaveRequest>>("GET", "/api/v1/leave", { org: org(), params: { status: "pending", per_page: 1 } }),
    // My approved leave count
    api<Paginator<LeaveRequest>>("GET", "/api/v1/leave", { org: org(), params: { status: "approved", per_page: 1 } }),
    // My leave balances
    api<LeaveBalanceResponse>("GET", "/api/v1/leave/balance", { org: org() }),
    // Recent payroll periods (HR only)
    api<Paginator<PayrollPeriod>>("GET", "/api/v1/payroll", { org: org(), params: { per_page: 5 } }),
  ]);

  const [emp, pending, approved, balanceRes, payroll] = results;

  summary.value.employeeTotal = emp.status === "fulfilled" ? emp.value.meta.total : null;
  summary.value.pendingLeave  = pending.status === "fulfilled" ? pending.value.meta.total : null;
  summary.value.approvedLeave = approved.status === "fulfilled" ? approved.value.meta.total : null;
  summary.value.balances = balanceRes.status === "fulfilled"
    ? balanceRes.value.data.map((b) => ({ leave_type: b.leave_type, available_days: b.available_days }))
    : [];
  summary.value.recentPeriods = payroll.status === "fulfilled" ? payroll.value.data : [];

  // Surface only genuine unexpected server errors.
  // 401/403 are silently swallowed (scoped access).
  // 501 means "not yet implemented" — expected while the backend is in development.
  results.forEach((r) => {
    if (
      r.status === "rejected" &&
      r.reason instanceof ApiError &&
      r.reason.status >= 500 &&
      r.reason.status !== 501
    ) {
      errors.value.push(r.reason.message);
    }
  });

  loading.value = false;
}

onMounted(load);
watch(currentOrgSlug, load);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <h2>Dashboard</h2>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-for="e in errors" :key="e" class="error">{{ e }}</p>

    <!-- Coming-soon notice shown while API is under construction -->
    <div
      v-if="!loading && summary.employeeTotal === null && summary.pendingLeave === null"
      class="dash-coming-soon"
    >
      <div class="dash-coming-soon-icon">🚧</div>
      <p class="dash-coming-soon-title">Live stats coming soon</p>
      <p class="dash-coming-soon-sub">
        The dashboard metrics are being wired up. Use the navigation or quick actions below to get started.
      </p>
    </div>

    <!-- Metric cards -->
    <div class="metrics-grid">
      <article v-if="summary.employeeTotal !== null" class="metric-card">
        <p class="metric-label">Employees</p>
        <p class="metric-value">{{ summary.employeeTotal }}</p>
        <p class="metric-sub">{{ authStore.currentOrg?.name }}</p>
      </article>

      <article v-if="summary.pendingLeave !== null" class="metric-card">
        <p class="metric-label">Pending Leave</p>
        <p class="metric-value">{{ summary.pendingLeave }}</p>
        <p class="metric-sub">Awaiting approval</p>
      </article>

      <article v-if="summary.approvedLeave !== null" class="metric-card">
        <p class="metric-label">Approved Leave</p>
        <p class="metric-value">{{ summary.approvedLeave }}</p>
        <p class="metric-sub">This organisation</p>
      </article>
    </div>

    <!-- Leave balances -->
    <div v-if="summary.balances.length > 0" class="card" style="margin-bottom:20px">
      <h3 class="card-title">My Leave Balances</h3>
      <div class="balance-list">
        <div v-for="b in summary.balances" :key="b.leave_type" class="balance-item">
          <div class="balance-header">
            <span class="balance-name">{{ b.leave_type }}</span>
            <span class="balance-numbers">{{ b.available_days }} days available</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Recent payroll periods -->
    <div v-if="summary.recentPeriods.length > 0" class="card">
      <h3 class="card-title">Recent Payroll Periods</h3>
      <table>
        <thead>
          <tr>
            <th>Period</th>
            <th>Start</th>
            <th>End</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in summary.recentPeriods" :key="p.id" @click="$router.push({ name: 'payroll' })">
            <td>{{ p.name }}</td>
            <td>{{ p.start_date }}</td>
            <td>{{ p.end_date }}</td>
            <td>
              <span :class="['badge', {
                'badge-gray': p.status === 'draft',
                'badge-blue': p.status === 'processing',
                'badge-green': p.status === 'approved' || p.status === 'paid',
              }]">{{ p.status }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Quick actions -->
    <div style="margin-top:24px">
      <p class="section-title">Quick Actions</p>
      <div class="actions-grid">
        <router-link :to="{ name: 'leave' }" class="action-card">
          <span class="action-icon">🌴</span>
          <span>Apply Leave</span>
        </router-link>
        <router-link :to="{ name: 'employees' }" class="action-card">
          <span class="action-icon">👥</span>
          <span>Employees</span>
        </router-link>
        <router-link :to="{ name: 'payroll' }" class="action-card">
          <span class="action-icon">💰</span>
          <span>Payroll</span>
        </router-link>
      </div>
    </div>
  </div>
</template>
