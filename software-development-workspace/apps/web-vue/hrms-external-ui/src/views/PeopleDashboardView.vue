<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import { callFrappeMethod, type FrappeAuthConfig } from "../lib/frappe-client";
import { authStore } from "../stores/auth";
import CompanySelector from "../components/CompanySelector.vue";

const router = useRouter();

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface DeptStat { department: string; cnt: number }
interface TypeStat { employment_type: string; cnt: number }
interface RecentJoiner {
  name: string;
  employee_name: string;
  designation: string | null;
  department: string | null;
  date_of_joining: string | null;
}
interface CompanyStat { company: string; total: number; active: number; inactive: number }
interface Totals { total: number; active: number; inactive: number }
interface SingleSummary extends Totals {
  company: string;
  departments: DeptStat[];
  employment_types: TypeStat[];
  recent_joiners: RecentJoiner[];
}
type DashboardData =
  | { mode: "all_companies"; totals: Totals; companies: CompanyStat[] }
  | { mode: "single_company"; summary: SingleSummary };

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

const dashData = ref<DashboardData | null>(null);
const loading = ref(false);
const error = ref("");

function authConfig(): FrappeAuthConfig {
  return {
    baseUrl: authStore.baseUrl,
    apiKey: authStore.apiKey || undefined,
    apiSecret: authStore.apiSecret || undefined,
  };
}

// ---------------------------------------------------------------------------
// Data loading
// ---------------------------------------------------------------------------

async function load(): Promise<void> {
  loading.value = true;
  error.value = "";
  dashData.value = null;
  try {
    dashData.value = await callFrappeMethod<DashboardData>(
      authConfig(),
      "bitwyse_hrms_backend.api.people_dashboard",
      authStore.company ? { company: authStore.company } : {},
    );
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load people dashboard.";
  } finally {
    loading.value = false;
  }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function activeRate(active: number, total: number): number {
  if (!total) return 0;
  return Math.round((active / total) * 100);
}

function barWidth(count: number, items: { cnt: number }[]): string {
  const max = items.reduce((m, i) => Math.max(m, i.cnt), 1);
  return (count / max) * 100 + "%";
}

function formatDate(d: string | null): string {
  if (!d) return "—";
  return new Date(d).toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
}

onMounted(load);
</script>

<template>
  <div class="view">

    <!-- ── Header ──────────────────────────────────────────── -->
    <div class="view-header">
      <div>
        <h2>People Dashboard</h2>
        <p v-if="dashData" class="pd-subtitle">
          <template v-if="dashData.mode === 'all_companies'">All Companies overview</template>
          <template v-else>{{ dashData.summary.company }}</template>
        </p>
      </div>
      <div class="pd-header-actions">
        <CompanySelector @change="load" />
        <router-link :to="{ name: 'people' }" class="btn ghost small">
          View Employee List →
        </router-link>
      </div>
    </div>

    <!-- ── Loading ─────────────────────────────────────────── -->
    <div v-if="loading" class="pd-loading">
      <div class="pd-spinner"></div>
      <span>Loading people data…</span>
    </div>

    <!-- ── Error ───────────────────────────────────────────── -->
    <p v-if="error" class="error">{{ error }}</p>

    <!-- ════════════════════════════════════════════════════════
         ALL COMPANIES MODE
         ════════════════════════════════════════════════════════ -->
    <template v-if="dashData && dashData.mode === 'all_companies'">

      <!-- Global totals -->
      <div class="pd-stat-tiles">
        <article class="pd-stat-tile pd-tile-blue">
          <p class="pd-tile-label">Total Employees</p>
          <p class="pd-tile-value">{{ dashData.totals.total }}</p>
          <p class="pd-tile-sub">all companies combined</p>
        </article>
        <article class="pd-stat-tile pd-tile-green">
          <p class="pd-tile-label">Active</p>
          <p class="pd-tile-value">{{ dashData.totals.active }}</p>
          <p class="pd-tile-sub">
            {{ activeRate(dashData.totals.active, dashData.totals.total) }}% of workforce
          </p>
        </article>
        <article class="pd-stat-tile pd-tile-amber">
          <p class="pd-tile-label">Inactive / Left</p>
          <p class="pd-tile-value">{{ dashData.totals.inactive }}</p>
          <p class="pd-tile-sub">
            {{ activeRate(dashData.totals.inactive, dashData.totals.total) }}% of workforce
          </p>
        </article>
        <article class="pd-stat-tile pd-tile-purple">
          <p class="pd-tile-label">Companies</p>
          <p class="pd-tile-value">{{ dashData.companies.length }}</p>
          <p class="pd-tile-sub">with employee records</p>
        </article>
      </div>

      <!-- Company cards -->
      <h3 class="pd-section-title">Breakdown by Company</h3>
      <div v-if="dashData.companies.length === 0" class="pd-empty">
        No employee records found in any company.
      </div>
      <div v-else class="pd-company-grid">
        <article
          v-for="co in dashData.companies"
          :key="co.company"
          class="pd-company-card"
        >
          <div class="pd-company-header">
            <div class="pd-company-icon">{{ co.company[0].toUpperCase() }}</div>
            <div class="pd-company-name">{{ co.company }}</div>
          </div>

          <div class="pd-company-stats">
            <span class="pd-cs-item pd-cs-total">{{ co.total }} total</span>
            <span class="pd-cs-item pd-cs-active">{{ co.active }} active</span>
            <span v-if="co.inactive" class="pd-cs-item pd-cs-inactive">
              {{ co.inactive }} inactive
            </span>
          </div>

          <div class="pd-bar-track">
            <div
              class="pd-bar-fill"
              :style="{ width: activeRate(co.active, co.total) + '%' }"
              :title="`${activeRate(co.active, co.total)}% active`"
            ></div>
          </div>
          <p class="pd-bar-label">{{ activeRate(co.active, co.total) }}% Active</p>
        </article>
      </div>

    </template>

    <!-- ════════════════════════════════════════════════════════
         SINGLE COMPANY MODE
         ════════════════════════════════════════════════════════ -->
    <template v-if="dashData && dashData.mode === 'single_company'">

      <!-- Stat tiles -->
      <div class="pd-stat-tiles">
        <article class="pd-stat-tile pd-tile-blue">
          <p class="pd-tile-label">Total Employees</p>
          <p class="pd-tile-value">{{ dashData.summary.total }}</p>
        </article>
        <article class="pd-stat-tile pd-tile-green">
          <p class="pd-tile-label">Active</p>
          <p class="pd-tile-value">{{ dashData.summary.active }}</p>
          <p class="pd-tile-sub">
            {{ activeRate(dashData.summary.active, dashData.summary.total) }}% of workforce
          </p>
        </article>
        <article class="pd-stat-tile pd-tile-amber">
          <p class="pd-tile-label">Inactive / Left</p>
          <p class="pd-tile-value">{{ dashData.summary.inactive }}</p>
        </article>
        <article class="pd-stat-tile pd-tile-purple">
          <p class="pd-tile-label">Departments</p>
          <p class="pd-tile-value">{{ dashData.summary.departments.length }}</p>
        </article>
      </div>

      <!-- Department + Employment type panels -->
      <div class="pd-panels">

        <!-- Department breakdown -->
        <section class="pd-panel">
          <h3 class="pd-panel-title">By Department</h3>
          <div v-if="dashData.summary.departments.length === 0" class="pd-empty-small">
            No department data available.
          </div>
          <ul v-else class="pd-bar-list">
            <li
              v-for="d in dashData.summary.departments"
              :key="d.department"
              class="pd-bar-row"
            >
              <span class="pd-bar-name" :title="d.department">{{ d.department }}</span>
              <div class="pd-bar-track pd-bar-track-sm">
                <div
                  class="pd-bar-fill"
                  :style="{ width: barWidth(d.cnt, dashData.summary.departments) }"
                ></div>
              </div>
              <span class="pd-bar-count">{{ d.cnt }}</span>
            </li>
          </ul>
        </section>

        <!-- Employment type breakdown -->
        <section class="pd-panel">
          <h3 class="pd-panel-title">By Employment Type</h3>
          <div v-if="dashData.summary.employment_types.length === 0" class="pd-empty-small">
            No employment type data available.
          </div>
          <ul v-else class="pd-type-list">
            <li
              v-for="t in dashData.summary.employment_types"
              :key="t.employment_type"
              class="pd-type-row"
            >
              <span class="pd-type-dot"></span>
              <span class="pd-type-name">{{ t.employment_type }}</span>
              <span class="pd-type-count">{{ t.cnt }}</span>
              <span class="pd-type-pct">
                {{ activeRate(t.cnt, dashData.summary.total) }}%
              </span>
            </li>
          </ul>
        </section>

      </div>

      <!-- Recent joiners -->
      <section class="pd-recent">
        <h3 class="pd-section-title">Recent Joiners</h3>
        <div v-if="dashData.summary.recent_joiners.length === 0" class="pd-empty">
          No recent joiners found.
        </div>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Department</th>
                <th>Designation</th>
                <th>Date Joined</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="emp in dashData.summary.recent_joiners"
                :key="emp.name"
                class="clickable"
                @click="router.push({ name: 'people-detail', params: { id: emp.name } })"
              >
                <td>{{ emp.employee_name }}</td>
                <td>{{ emp.department ?? "—" }}</td>
                <td>{{ emp.designation ?? "—" }}</td>
                <td>{{ formatDate(emp.date_of_joining) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

    </template>

  </div>
</template>
