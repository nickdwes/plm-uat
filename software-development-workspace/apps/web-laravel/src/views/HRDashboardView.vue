<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug, authStore } from "../stores/auth";
import type { TeamLocationEntry, WorkLocationType } from "../lib/types";

interface EmploymentType { type: string; count: number }
interface GenderRow      { gender: string; count: number }
interface DeptRow        { department: string; headcount: number }
interface MonthlyHire    { month: string; count: number }

interface DashboardSummary {
  totalActive:        number;
  totalExited:        number;
  newHiresThisMonth:  number;
  newHiresLastMonth:  number;
  pendingLeave:       number;
  approvedLeave:      number;
  rejectedLeave:      number;
  onLeaveToday:       number;
  leaveRatio:         number;
  headcountByDept:    DeptRow[];
  employmentTypes:    EmploymentType[];
  genderBreakdown:    GenderRow[];
  monthlyHires:       MonthlyHire[];
}

const summary = ref<DashboardSummary | null>(null);
const loading = ref(false);
const error   = ref("");

const teamLocations = ref<TeamLocationEntry[] | null>(null);

function org(): string | null { return currentOrgSlug.value; }

async function load(): Promise<void> {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true;
  error.value   = "";
  try {
    summary.value = await api<DashboardSummary>("GET", "/api/v1/dashboard", { org: org() });
  } catch (err) {
    if (!(err instanceof ApiError && err.status === 501)) {
      error.value = err instanceof Error ? err.message : "Failed to load dashboard.";
    }
  } finally {
    loading.value = false;
  }
}

async function loadTeamLocations(): Promise<void> {
  if (!org()) { teamLocations.value = null; return; }
  try {
    const res = await api<{ data: TeamLocationEntry[] }>("GET", "/api/v1/work-location/team", { org: org() });
    teamLocations.value = res.data;
  } catch {
    teamLocations.value = null; // non-managers get a 403 — hide the card silently
  }
}

onMounted(() => { load(); loadTeamLocations(); });
watch(currentOrgSlug, () => { load(); loadTeamLocations(); });

// ── Computed helpers ──────────────────────────────────────────────────────────

const maxDeptHeadcount = computed(() =>
  Math.max(1, ...(summary.value?.headcountByDept?.map((d) => d.headcount) ?? [0]))
);

const totalByType = computed(() =>
  (summary.value?.employmentTypes ?? []).reduce((s, e) => s + e.count, 0) || 1
);

const totalGender = computed(() =>
  (summary.value?.genderBreakdown ?? []).reduce((s, g) => s + g.count, 0) || 1
);

const maxMonthlyHires = computed(() =>
  Math.max(1, ...(summary.value?.monthlyHires?.map((m) => m.count) ?? [0]))
);

const totalLeaveRequests = computed(() => {
  const s = summary.value;
  return s ? s.pendingLeave + s.approvedLeave + s.rejectedLeave : 1;
});

const hiresChange = computed(() => {
  const s = summary.value;
  if (!s) return null;
  const diff = s.newHiresThisMonth - s.newHiresLastMonth;
  return { diff, positive: diff >= 0 };
});

const typeColours: Record<string, string> = {
  permanent:    "#004C97",
  intern:       "#0D9488",
  contractor:   "#F59E08",
  attachee:     "#6366F1",
  unspecified:  "#9CA3AF",
};

const genderColours: Record<string, string> = {
  male:         "#004C97",
  female:       "#EC4899",
  other:        "#8B5CF6",
  unspecified:  "#9CA3AF",
};

function typeColour(type: string): string {
  return typeColours[type.toLowerCase()] ?? "#9CA3AF";
}

function genderColour(g: string): string {
  return genderColours[g.toLowerCase()] ?? "#9CA3AF";
}

function formatMonth(ym: string): string {
  const [y, m] = ym.split("-");
  return new Date(Number(y), Number(m) - 1).toLocaleString("default", { month: "short", year: "2-digit" });
}

function fmtTime(d: string | null | undefined): string {
  if (!d) return "—";
  try { return new Date(d).toLocaleTimeString("en-KE", { hour: "2-digit", minute: "2-digit" }); }
  catch { return d; }
}

function teamInitials(name: string): string {
  const parts = name.trim().split(/\s+/);
  return ((parts[0]?.[0] ?? "") + (parts[1]?.[0] ?? "")).toUpperCase() || "?";
}

function teamWlBadgeClass(type: WorkLocationType): string {
  return { office: "badge-blue", home: "badge-teal", customer_site: "badge-amber" }[type] ?? "badge-gray";
}

function teamWlLabel(c: { location_type: WorkLocationType; customer_name: string | null }): string {
  const base = { office: "Office", home: "Work From Home", customer_site: "Customer Site" }[c.location_type] ?? c.location_type;
  return c.location_type === "customer_site" && c.customer_name ? `${base}: ${c.customer_name}` : base;
}

const today = new Date().toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
</script>

<template>
  <div class="view dash-view">

    <!-- ── Page header ───────────────────────────────────────────────────── -->
    <div class="dash-page-header">
      <div>
        <h2 class="dash-page-title">HR Dashboard</h2>
        <p class="dash-page-sub">{{ today }}</p>
      </div>
      <span v-if="authStore.currentOrg" class="badge badge-blue dash-org-badge">
        {{ authStore.currentOrg.name }}
      </span>
    </div>

    <p v-if="loading" class="status">Loading dashboard…</p>
    <p v-if="error"   class="error">{{ error }}</p>

    <template v-if="summary">

      <!-- ── KPI strip ──────────────────────────────────────────────────── -->
      <div class="kpi-strip">

        <article class="kpi-card kpi-blue">
          <div class="kpi-icon-wrap kpi-icon-blue">
            <svg viewBox="0 0 20 20" fill="currentColor"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zm8 0a3 3 0 11-6 0 3 3 0 016 0zM.458 18C1.64 14.435 5.08 12 9 12s7.36 2.435 8.542 6H.458z"/></svg>
          </div>
          <div class="kpi-body">
            <p class="kpi-label">Active Staff</p>
            <p class="kpi-value">{{ summary.totalActive }}</p>
            <p class="kpi-hint">Across all departments</p>
          </div>
        </article>

        <article class="kpi-card kpi-green">
          <div class="kpi-icon-wrap kpi-icon-green">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
          </div>
          <div class="kpi-body">
            <p class="kpi-label">New Hires</p>
            <p class="kpi-value">{{ summary.newHiresThisMonth }}</p>
            <p class="kpi-hint" v-if="hiresChange">
              <span :class="hiresChange.positive ? 'trend-up' : 'trend-down'">
                {{ hiresChange.positive ? "▲" : "▼" }} {{ Math.abs(hiresChange.diff) }} vs last month
              </span>
            </p>
          </div>
        </article>

        <article class="kpi-card kpi-amber">
          <div class="kpi-icon-wrap kpi-icon-amber">
            <svg viewBox="0 0 20 20" fill="currentColor"><path d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zM4 8h12v8H4V8z"/></svg>
          </div>
          <div class="kpi-body">
            <p class="kpi-label">On Leave Today</p>
            <p class="kpi-value">{{ summary.onLeaveToday }}</p>
            <p class="kpi-hint">{{ summary.leaveRatio }}% of workforce</p>
          </div>
        </article>

        <article class="kpi-card kpi-indigo">
          <div class="kpi-icon-wrap kpi-icon-indigo">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
          </div>
          <div class="kpi-body">
            <p class="kpi-label">Pending Leave</p>
            <p class="kpi-value">{{ summary.pendingLeave }}</p>
            <p class="kpi-hint">Awaiting approval</p>
          </div>
        </article>

        <article class="kpi-card kpi-red">
          <div class="kpi-icon-wrap kpi-icon-red">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524L13.477 14.89zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"/></svg>
          </div>
          <div class="kpi-body">
            <p class="kpi-label">Exited Staff</p>
            <p class="kpi-value">{{ summary.totalExited }}</p>
            <p class="kpi-hint">Total on record</p>
          </div>
        </article>

      </div><!-- /kpi-strip -->

      <!-- ── Row 2: Department ranking + Leave stats ────────────────────── -->
      <div class="dash-row">

        <!-- Department headcount ranking -->
        <div class="card dash-dept-card">
          <div class="card-head">
            <h3 class="card-title">Department Headcount Ranking</h3>
            <span class="card-badge">{{ summary.headcountByDept.length }} depts</span>
          </div>
          <div class="dept-list">
            <div
              v-for="(row, i) in summary.headcountByDept"
              :key="row.department"
              class="dept-row"
            >
              <span class="dept-rank">{{ i + 1 }}</span>
              <span class="dept-name">{{ row.department || "Unassigned" }}</span>
              <div class="dept-bar-wrap">
                <div
                  class="dept-bar"
                  :style="{ width: ((row.headcount / maxDeptHeadcount) * 100) + '%' }"
                ></div>
              </div>
              <span class="dept-count">{{ row.headcount }}</span>
            </div>
            <p v-if="!summary.headcountByDept.length" class="dash-empty">
              No departments configured.
              <router-link :to="{ name: 'org' }">Set up departments →</router-link>
            </p>
          </div>
        </div>

        <!-- Leave statistics panel -->
        <div class="card dash-leave-card">
          <div class="card-head">
            <h3 class="card-title">Leave Statistics</h3>
          </div>

          <!-- Leave ratio ring -->
          <div class="leave-ring-wrap">
            <svg class="leave-ring" viewBox="0 0 80 80">
              <circle cx="40" cy="40" r="32" fill="none" stroke="#E5E7EB" stroke-width="10"/>
              <circle
                cx="40" cy="40" r="32" fill="none"
                stroke="#F59E08" stroke-width="10"
                stroke-dasharray="201"
                :stroke-dashoffset="201 - (201 * summary.leaveRatio / 100)"
                transform="rotate(-90 40 40)"
                stroke-linecap="round"
              />
            </svg>
            <div class="leave-ring-label">
              <p class="leave-ring-pct">{{ summary.leaveRatio }}%</p>
              <p class="leave-ring-sub">Leave ratio</p>
            </div>
          </div>

          <div class="leave-stat-grid">
            <div class="leave-stat-item leave-stat-pending">
              <p class="leave-stat-val">{{ summary.pendingLeave }}</p>
              <p class="leave-stat-lbl">Pending</p>
            </div>
            <div class="leave-stat-item leave-stat-approved">
              <p class="leave-stat-val">{{ summary.approvedLeave }}</p>
              <p class="leave-stat-lbl">Approved</p>
            </div>
            <div class="leave-stat-item leave-stat-today">
              <p class="leave-stat-val">{{ summary.onLeaveToday }}</p>
              <p class="leave-stat-lbl">Out today</p>
            </div>
            <div class="leave-stat-item leave-stat-rejected">
              <p class="leave-stat-val">{{ summary.rejectedLeave }}</p>
              <p class="leave-stat-lbl">Rejected</p>
            </div>
          </div>

          <!-- Leave request progress bar -->
          <div v-if="totalLeaveRequests > 0" class="leave-progress-wrap">
            <p class="leave-progress-title">Leave Requests Breakdown</p>
            <div class="leave-progress-bar">
              <div
                class="lp-seg lp-approved"
                :style="{ width: ((summary.approvedLeave / totalLeaveRequests) * 100) + '%' }"
                :title="`Approved: ${summary.approvedLeave}`"
              ></div>
              <div
                class="lp-seg lp-pending"
                :style="{ width: ((summary.pendingLeave / totalLeaveRequests) * 100) + '%' }"
                :title="`Pending: ${summary.pendingLeave}`"
              ></div>
              <div
                class="lp-seg lp-rejected"
                :style="{ width: ((summary.rejectedLeave / totalLeaveRequests) * 100) + '%' }"
                :title="`Rejected: ${summary.rejectedLeave}`"
              ></div>
            </div>
            <div class="lp-legend">
              <span class="lp-dot lp-dot-approved"></span> Approved
              <span class="lp-dot lp-dot-pending" style="margin-left:12px"></span> Pending
              <span class="lp-dot lp-dot-rejected" style="margin-left:12px"></span> Rejected
            </div>
          </div>
        </div>

      </div><!-- /dash-row -->

      <!-- ── Row 3: Employment category + Gender breakdown ─────────────── -->
      <div class="dash-row">

        <!-- Employment category -->
        <div class="card dash-type-card">
          <div class="card-head">
            <h3 class="card-title">Employee Category</h3>
            <span class="card-badge">{{ summary.totalActive }} active</span>
          </div>
          <div v-if="summary.employmentTypes?.length" class="emp-type-list">
            <div
              v-for="et in summary.employmentTypes"
              :key="et.type"
              class="emp-type-row"
            >
              <div class="emp-type-meta">
                <span class="emp-type-dot" :style="{ background: typeColour(et.type) }"></span>
                <span class="emp-type-name">{{ et.type }}</span>
                <span class="emp-type-pct">{{ Math.round((et.count / totalByType) * 100) }}%</span>
              </div>
              <div class="emp-type-bar-wrap">
                <div
                  class="emp-type-bar"
                  :style="{
                    width: ((et.count / totalByType) * 100) + '%',
                    background: typeColour(et.type)
                  }"
                ></div>
              </div>
              <span class="emp-type-count">{{ et.count }}</span>
            </div>
          </div>
          <p v-else class="dash-empty">No employment type data available.</p>
        </div>

        <!-- Gender breakdown -->
        <div class="card dash-gender-card">
          <div class="card-head">
            <h3 class="card-title">Gender Distribution</h3>
          </div>
          <div v-if="summary.genderBreakdown?.length" class="gender-list">
            <div
              v-for="g in summary.genderBreakdown"
              :key="g.gender"
              class="gender-row"
            >
              <div class="gender-meta">
                <span class="gender-dot" :style="{ background: genderColour(g.gender) }"></span>
                <span class="gender-label">{{ g.gender }}</span>
                <span class="gender-pct">{{ Math.round((g.count / totalGender) * 100) }}%</span>
              </div>
              <div class="gender-bar-wrap">
                <div
                  class="gender-bar"
                  :style="{
                    width: ((g.count / totalGender) * 100) + '%',
                    background: genderColour(g.gender)
                  }"
                ></div>
              </div>
              <span class="gender-count">{{ g.count }}</span>
            </div>
          </div>
          <p v-else class="dash-empty">No gender data available.</p>
        </div>

      </div><!-- /dash-row -->

      <!-- ── Row 4: Monthly hiring trend ───────────────────────────────── -->
      <div v-if="summary.monthlyHires?.length" class="card dash-trend-card">
        <div class="card-head">
          <h3 class="card-title">Monthly Hiring Trend</h3>
          <span class="card-badge">Last 6 months</span>
        </div>
        <div class="trend-chart">
          <div
            v-for="m in summary.monthlyHires"
            :key="m.month"
            class="trend-col"
          >
            <span class="trend-val">{{ m.count }}</span>
            <div class="trend-bar-wrap">
              <div
                class="trend-bar"
                :style="{ height: ((m.count / maxMonthlyHires) * 100) + '%' }"
              ></div>
            </div>
            <span class="trend-month">{{ formatMonth(m.month) }}</span>
          </div>
        </div>
      </div>

      <!-- ── Team Work Locations (managers/HR only) ─────────────────────── -->
      <div v-if="teamLocations" class="card dash-team-loc-card">
        <div class="card-head">
          <h3 class="card-title">Team Work Locations — Today</h3>
          <span class="card-badge">{{ teamLocations.length }} member{{ teamLocations.length === 1 ? "" : "s" }}</span>
        </div>
        <div v-if="teamLocations.length" class="team-loc-list">
          <div v-for="entry in teamLocations" :key="entry.employee.id" class="team-loc-row">
            <div class="team-loc-avatar">{{ teamInitials(entry.employee.full_name) }}</div>
            <div class="team-loc-meta">
              <p class="team-loc-name">{{ entry.employee.full_name }}</p>
              <p class="team-loc-num">{{ entry.employee.employee_number }}</p>
            </div>
            <div class="team-loc-status">
              <span v-if="entry.check_in" :class="['badge', teamWlBadgeClass(entry.check_in.location_type)]">
                {{ teamWlLabel(entry.check_in) }}
              </span>
              <span v-else class="badge badge-gray">Not checked in</span>
              <span v-if="entry.check_in" class="team-loc-time">since {{ fmtTime(entry.check_in.checked_in_at) }}</span>
            </div>
          </div>
        </div>
        <p v-else class="dash-empty">No team members found.</p>
      </div>

      <!-- ── HR Modules quick-access ────────────────────────────────────── -->
      <div class="dash-modules-section">
        <p class="section-title">HR Modules</p>
        <div class="actions-grid">
          <router-link :to="{ name: 'employees' }" class="action-card">
            <span class="action-icon">👥</span><span>Employees</span>
          </router-link>
          <router-link :to="{ name: 'leave' }" class="action-card">
            <span class="action-icon">🌴</span><span>Leave</span>
          </router-link>
          <router-link :to="{ name: 'leave-approvals' }" class="action-card">
            <span class="action-icon">✅</span><span>Approvals</span>
          </router-link>
          <router-link :to="{ name: 'leave-admin' }" class="action-card">
            <span class="action-icon">⚙️</span><span>Leave Admin</span>
          </router-link>
          <router-link :to="{ name: 'payroll' }" class="action-card">
            <span class="action-icon">💰</span><span>Payroll</span>
          </router-link>
          <router-link :to="{ name: 'performance' }" class="action-card">
            <span class="action-icon">📈</span><span>Performance</span>
          </router-link>
          <router-link :to="{ name: 'recruitment' }" class="action-card">
            <span class="action-icon">🔍</span><span>Recruitment</span>
          </router-link>
          <router-link :to="{ name: 'org' }" class="action-card">
            <span class="action-icon">🏢</span><span>Organisation</span>
          </router-link>
        </div>
      </div>

    </template>

    <!-- Empty state when no data yet -->
    <template v-if="!loading && !summary && !error">
      <div class="dash-coming-soon">
        <div class="dash-coming-soon-icon">🚧</div>
        <p class="dash-coming-soon-title">Dashboard metrics loading…</p>
        <p class="dash-coming-soon-sub">Select an organisation to view live statistics.</p>
      </div>
    </template>

  </div>
</template>

<style scoped>
/* ── Page header ───────────────────────────────────────────────────────────── */
.dash-view { max-width: 1200px; }

.dash-page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 28px;
  flex-wrap: wrap;
  gap: 12px;
}
.dash-page-title { font-size: 22px; font-weight: 700; color: var(--color-text); letter-spacing: -0.3px; }
.dash-page-sub   { font-size: 13px; color: var(--color-text-muted); margin-top: 2px; }
.dash-org-badge  { align-self: flex-start; margin-top: 4px; }

/* ── KPI strip ─────────────────────────────────────────────────────────────── */
.kpi-strip {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

.kpi-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 16px;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  box-shadow: 0 1px 3px rgba(0,0,0,.06);
  transition: box-shadow .15s, transform .15s;
}
.kpi-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.10); transform: translateY(-1px); }

.kpi-icon-wrap {
  width: 40px; height: 40px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.kpi-icon-wrap svg { width: 20px; height: 20px; }

.kpi-icon-blue   { background: #DBEAFE; color: #004C97; }
.kpi-icon-green  { background: #D1FAE5; color: #108981; }
.kpi-icon-amber  { background: #FEF3C7; color: #D97706; }
.kpi-icon-indigo { background: #EDE9FE; color: #6366F1; }
.kpi-icon-red    { background: #FEE2E2; color: #D32F2F; }

.kpi-body { flex: 1; min-width: 0; }
.kpi-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--color-text-muted); margin-bottom: 2px; }
.kpi-value { font-size: 28px; font-weight: 700; color: var(--color-text); line-height: 1.2; }
.kpi-hint  { font-size: 12px; color: var(--color-text-light); margin-top: 2px; }

.trend-up   { color: #108981; font-weight: 600; }
.trend-down { color: #D32F2F; font-weight: 600; }

/* ── Two-column row ────────────────────────────────────────────────────────── */
.dash-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 16px;
}
@media (max-width: 800px) { .dash-row { grid-template-columns: 1fr; } }

/* ── Card head ─────────────────────────────────────────────────────────────── */
.card-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 16px;
}
.card-head .card-title { margin-bottom: 0; }
.card-badge {
  font-size: 11px; font-weight: 600; padding: 3px 8px;
  background: var(--color-neutral-100); border-radius: 20px;
  color: var(--color-text-muted);
}

/* ── Department ranking ────────────────────────────────────────────────────── */
.dept-list { display: flex; flex-direction: column; gap: 10px; }
.dept-row  { display: flex; align-items: center; gap: 10px; }

.dept-rank { font-size: 11px; font-weight: 700; color: var(--color-text-muted); width: 18px; text-align: center; flex-shrink: 0; }
.dept-name { font-size: 13px; color: var(--color-text); width: 140px; flex-shrink: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dept-bar-wrap { flex: 1; height: 8px; background: var(--color-neutral-200); border-radius: 4px; overflow: hidden; }
.dept-bar { height: 100%; background: var(--color-brand-base); border-radius: 4px; transition: width .4s ease; }
.dept-count { font-size: 13px; font-weight: 600; color: var(--color-text); width: 28px; text-align: right; flex-shrink: 0; }

/* ── Leave card ────────────────────────────────────────────────────────────── */
.leave-ring-wrap {
  position: relative;
  width: 100px; height: 100px;
  margin: 0 auto 20px;
}
.leave-ring { width: 100%; height: 100%; }
.leave-ring-label {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  text-align: center;
}
.leave-ring-pct { font-size: 18px; font-weight: 700; color: var(--color-text); line-height: 1; }
.leave-ring-sub { font-size: 10px; color: var(--color-text-muted); margin-top: 2px; }

.leave-stat-grid {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 10px; margin-bottom: 20px;
}
.leave-stat-item {
  border-radius: 10px; padding: 12px;
  text-align: center;
}
.leave-stat-val { font-size: 22px; font-weight: 700; line-height: 1; }
.leave-stat-lbl { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; margin-top: 4px; }

.leave-stat-pending  { background: #FEF3C7; }
.leave-stat-pending .leave-stat-val { color: #D97706; }
.leave-stat-pending .leave-stat-lbl { color: #92400E; }

.leave-stat-approved { background: #D1FAE5; }
.leave-stat-approved .leave-stat-val { color: #108981; }
.leave-stat-approved .leave-stat-lbl { color: #065F46; }

.leave-stat-today    { background: #DBEAFE; }
.leave-stat-today .leave-stat-val { color: #004C97; }
.leave-stat-today .leave-stat-lbl { color: #1E3A5F; }

.leave-stat-rejected { background: #FEE2E2; }
.leave-stat-rejected .leave-stat-val { color: #D32F2F; }
.leave-stat-rejected .leave-stat-lbl { color: #7F1D1D; }

.leave-progress-wrap  { margin-top: 4px; }
.leave-progress-title { font-size: 12px; font-weight: 600; color: var(--color-text-muted); margin-bottom: 6px; }
.leave-progress-bar   { height: 10px; border-radius: 6px; overflow: hidden; background: var(--color-neutral-200); display: flex; }
.lp-seg               { height: 100%; transition: width .4s; }
.lp-approved          { background: #108981; }
.lp-pending           { background: #F59E08; }
.lp-rejected          { background: #D32F2F; }

.lp-legend { font-size: 11px; color: var(--color-text-muted); margin-top: 6px; display: flex; align-items: center; }
.lp-dot    { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; }
.lp-dot-approved { background: #108981; }
.lp-dot-pending  { background: #F59E08; }
.lp-dot-rejected { background: #D32F2F; }

/* ── Employment category ───────────────────────────────────────────────────── */
.emp-type-list { display: flex; flex-direction: column; gap: 12px; }
.emp-type-row  { display: flex; align-items: center; gap: 10px; }

.emp-type-meta  { display: flex; align-items: center; gap: 6px; width: 160px; flex-shrink: 0; }
.emp-type-dot   { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.emp-type-name  { font-size: 13px; color: var(--color-text); text-transform: capitalize; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.emp-type-pct   { font-size: 11px; color: var(--color-text-muted); font-weight: 600; flex-shrink: 0; }

.emp-type-bar-wrap { flex: 1; height: 8px; background: var(--color-neutral-200); border-radius: 4px; overflow: hidden; }
.emp-type-bar      { height: 100%; border-radius: 4px; transition: width .4s ease; }
.emp-type-count    { font-size: 13px; font-weight: 600; color: var(--color-text); width: 28px; text-align: right; flex-shrink: 0; }

/* ── Gender ────────────────────────────────────────────────────────────────── */
.gender-list { display: flex; flex-direction: column; gap: 14px; }
.gender-row  { display: flex; align-items: center; gap: 10px; }

.gender-meta  { display: flex; align-items: center; gap: 6px; width: 140px; flex-shrink: 0; }
.gender-dot   { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.gender-label { font-size: 13px; color: var(--color-text); text-transform: capitalize; flex: 1; }
.gender-pct   { font-size: 11px; color: var(--color-text-muted); font-weight: 600; }

.gender-bar-wrap { flex: 1; height: 10px; background: var(--color-neutral-200); border-radius: 5px; overflow: hidden; }
.gender-bar      { height: 100%; border-radius: 5px; transition: width .4s ease; }
.gender-count    { font-size: 13px; font-weight: 600; color: var(--color-text); width: 28px; text-align: right; flex-shrink: 0; }

/* ── Monthly trend bar chart ───────────────────────────────────────────────── */
.dash-trend-card { margin-bottom: 24px; }
.trend-chart {
  display: flex;
  align-items: flex-end;
  gap: 10px;
  height: 120px;
  padding-bottom: 4px;
}
.trend-col {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  height: 100%;
}
.trend-val { font-size: 11px; font-weight: 600; color: var(--color-text); margin-bottom: 4px; }
.trend-bar-wrap {
  flex: 1; width: 100%; display: flex; align-items: flex-end;
  background: var(--color-neutral-100); border-radius: 4px 4px 0 0; overflow: hidden;
}
.trend-bar {
  width: 100%;
  background: var(--color-brand-base);
  border-radius: 4px 4px 0 0;
  transition: height .4s ease;
  min-height: 4px;
}
.trend-month { font-size: 11px; color: var(--color-text-muted); margin-top: 4px; white-space: nowrap; }

/* ── Team work locations ───────────────────────────────────────────────────── */
.dash-team-loc-card { margin-bottom: 24px; }
.team-loc-list { display: flex; flex-direction: column; gap: 10px; }
.team-loc-row  { display: flex; align-items: center; gap: 12px; }

.team-loc-avatar {
  width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: var(--color-neutral-100); color: var(--color-brand-base);
  font-size: 12px; font-weight: 700;
}

.team-loc-meta { flex: 1; min-width: 0; }
.team-loc-name { font-size: 13px; font-weight: 600; color: var(--color-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.team-loc-num  { font-size: 11px; color: var(--color-text-muted); margin-top: 1px; }

.team-loc-status { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.team-loc-time   { font-size: 11px; color: var(--color-text-muted); white-space: nowrap; }

/* ── Modules ───────────────────────────────────────────────────────────────── */
.dash-modules-section { margin-top: 8px; }

/* ── Empty state ───────────────────────────────────────────────────────────── */
.dash-empty {
  font-size: 13px;
  color: var(--color-text-muted);
  text-align: center;
  padding: 20px 0;
}
</style>


