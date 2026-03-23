<script setup lang="ts">
/**
 * OnboardingListView — shows onboarding records with role-appropriate filters.
 * HR sees all with full controls. Managers see their department's records.
 * Reachable from the People tab.
 */
import { ref, onMounted, computed } from "vue";
import { useRouter } from "vue-router";
import { callFrappeMethod, type FrappeAuthConfig } from "../lib/frappe-client";
import { authStore, isCompanyHR, isManager } from "../stores/auth";
import type { OnboardingRecord, OnboardingDashboard, PaginationMeta } from "../lib/types";
import CompanySelector from "../components/CompanySelector.vue";

const router = useRouter();
const records = ref<OnboardingRecord[]>([]);
const dashboard = ref<OnboardingDashboard | null>(null);
const meta = ref<PaginationMeta | null>(null);
const loading = ref(false);
const error = ref("");
const statusFilter = ref("");
const limitStart = ref(0);
const pageLength = 20;

const hasPrev = computed(() => limitStart.value > 0);
const hasNext = computed(() => !!meta.value && limitStart.value + pageLength < meta.value.total);

const STATUS_COLORS: Record<string, string> = {
  Draft: "badge-gray",
  Submitted: "badge-yellow",
  Approved: "badge-blue",
  Rejected: "badge-red",
  Onboarded: "badge-green",
};

function authConfig(): FrappeAuthConfig {
  return {
    baseUrl: authStore.baseUrl,
    apiKey: authStore.apiKey || undefined,
    apiSecret: authStore.apiSecret || undefined,
  };
}

async function load(reset = false): Promise<void> {
  if (reset) limitStart.value = 0;
  loading.value = true;
  error.value = "";
  try {
    const [listRes, dashRes] = await Promise.all([
      callFrappeMethod<{ items: OnboardingRecord[] }>(
        authConfig(),
        "bitwyse_hrms_backend.api.list_onboarding_records",
        {
          company: authStore.company ?? undefined,
          status: statusFilter.value || undefined,
          limit_start: limitStart.value,
          limit_page_length: pageLength,
        },
      ),
      isCompanyHR.value
        ? callFrappeMethod<OnboardingDashboard>(
            authConfig(),
            "bitwyse_hrms_backend.api.get_onboarding_dashboard",
            { company: authStore.company ?? undefined },
          )
        : Promise.resolve(null),
    ]);
    records.value = listRes.items ?? [];
    dashboard.value = dashRes;
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load onboarding records.";
  } finally {
    loading.value = false;
  }
}

function formatDate(d: string | null): string {
  if (!d) return "—";
  return new Date(d).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
}

onMounted(() => load(true));
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <h2>Employee Onboarding</h2>
        <p class="view-sub">Manage new hire onboarding across the group</p>
      </div>
      <div class="header-actions">
        <CompanySelector @change="load(true)" />
        <button
          v-if="isCompanyHR || isManager"
          class="primary"
          @click="router.push({ name: 'onboarding-create' })"
        >
          + New Onboarding
        </button>
      </div>
    </div>

    <!-- Dashboard summary tiles (HR only) -->
    <div v-if="isCompanyHR && dashboard" class="ob-stat-row">
      <div v-for="(count, status) in dashboard.by_status" :key="status" class="ob-stat-card">
        <p class="ob-stat-label">{{ status }}</p>
        <p class="ob-stat-value">{{ count }}</p>
      </div>
    </div>

    <!-- Pending approvals alert (HR) -->
    <div
      v-if="isCompanyHR && dashboard && dashboard.pending_approval.length"
      class="pending-alert"
    >
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
      <strong>{{ dashboard.pending_approval.length }} record(s) pending approval — </strong>
      <span
        v-for="p in dashboard.pending_approval.slice(0, 3)"
        :key="p.name"
        class="pending-name"
        @click="router.push({ name: 'onboarding-detail', params: { id: p.name } })"
      >{{ p.full_name }}</span>
      <span v-if="dashboard.pending_approval.length > 3">…</span>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <select v-model="statusFilter" @change="load(true)">
        <option value="">All Statuses</option>
        <option v-for="s in ['Draft','Submitted','Approved','Rejected','Onboarded']" :key="s" :value="s">
          {{ s }}
        </option>
      </select>
    </div>

    <p v-if="error" class="error-banner">{{ error }}</p>

    <div v-if="loading" class="loading-state">
      <span class="spinner" />
      Loading onboarding records…
    </div>

    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Company</th>
            <th>Department</th>
            <th>Date of Hire</th>
            <th>Step</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="rec in records" :key="rec.name" class="clickable" @click="router.push({ name: 'onboarding-detail', params: { id: rec.name } })">
            <td class="mono">{{ rec.name }}</td>
            <td>{{ rec.full_name || "—" }}</td>
            <td>{{ rec.company }}</td>
            <td>{{ rec.department }}</td>
            <td>{{ formatDate(rec.date_of_hire) }}</td>
            <td>{{ rec.current_step }} / 7</td>
            <td>
              <span :class="['badge', STATUS_COLORS[rec.status] ?? 'badge-gray']">{{ rec.status }}</span>
            </td>
            <td>
              <button
                class="small secondary"
                @click.stop="router.push({ name: 'onboarding-detail', params: { id: rec.name } })"
              >View</button>
            </td>
          </tr>
          <tr v-if="!records.length">
            <td colspan="8" class="empty-row">No onboarding records found.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="!loading && records.length" class="pagination">
      <button :disabled="!hasPrev" @click="limitStart -= pageLength; load()">← Prev</button>
      <span>{{ limitStart + 1 }}–{{ limitStart + records.length }}</span>
      <button :disabled="!hasNext" @click="limitStart += pageLength; load()">Next →</button>
    </div>
  </div>
</template>

<style scoped>
.view-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
.view-sub { font-size: 0.85rem; color: var(--text-muted); margin-top: 0.2rem; }
.header-actions { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.ob-stat-row { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.ob-stat-card { flex: 1; min-width: 110px; padding: 0.85rem 1rem; background: #fff; border: 1px solid var(--border); border-radius: 10px; text-align: center; }
.ob-stat-label { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
.ob-stat-value { font-size: 1.6rem; font-weight: 700; color: var(--primary); margin-top: 0.25rem; }
.pending-alert {
  display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;
  padding: 0.65rem 1rem; background: #fffaed;
  border: 1px solid #f6e05e; border-radius: 8px;
  font-size: 0.85rem; color: #744210; margin-bottom: 1rem;
}
.pending-alert svg { width: 16px; height: 16px; color: #d69e2e; flex-shrink: 0; }
.pending-name { color: var(--primary); cursor: pointer; text-decoration: underline; margin: 0 0.2rem; }
.toolbar { display: flex; gap: 0.75rem; margin-bottom: 1rem; }
select { padding: 0.4rem 0.6rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem; background: #fff; }
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
th { background: #f7f8fa; padding: 0.6rem 0.85rem; text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); border-bottom: 1px solid var(--border); }
td { padding: 0.65rem 0.85rem; border-bottom: 1px solid var(--border); }
tr.clickable:hover td { background: #f9fafb; cursor: pointer; }
.mono { font-family: monospace; font-size: 0.8rem; color: var(--text-muted); }
.badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.73rem; font-weight: 600; }
.badge-gray { background: #edf2f7; color: #4a5568; }
.badge-yellow { background: #fefcbf; color: #744210; }
.badge-blue { background: #ebf8ff; color: #2b6cb0; }
.badge-green { background: #f0fff4; color: #276749; }
.badge-red { background: #fff5f5; color: #c53030; }
.empty-row { text-align: center; color: var(--text-muted); padding: 2rem; }
.loading-state { display: flex; align-items: center; gap: 0.5rem; padding: 2rem; color: var(--text-muted); font-size: 0.875rem; }
.spinner { width: 16px; height: 16px; border: 2px solid var(--border); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.pagination { display: flex; justify-content: center; align-items: center; gap: 1rem; padding: 1rem; font-size: 0.875rem; }
.error-banner { background: #fff5f5; color: #c53030; padding: 0.75rem 1rem; border-radius: 6px; font-size: 0.875rem; margin-bottom: 1rem; }
button.primary { background: var(--primary); color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.875rem; cursor: pointer; }
button.secondary { background: #fff; border: 1px solid var(--border); padding: 0.3rem 0.6rem; border-radius: 5px; font-size: 0.8rem; cursor: pointer; }
button:disabled { opacity: 0.45; cursor: not-allowed; }
</style>
