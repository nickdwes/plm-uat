<script setup lang="ts">
/**
 * Projects Manager Dashboard.
 *
 * The manager's-eye view of the delivery portfolio, rebuilt from the old PLM's
 * dashboard: headline task counts, a per-project health matrix (time / cost /
 * issues / escalations / progress), completion breakdown, issue and task
 * distributions, and billing by currency. Every number comes from one
 * aggregate endpoint; the charts are hand-rolled SVG (the app carries no chart
 * library).
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";

interface HealthRow {
  id: number; reference: string | null; name: string; status: string; lead: string | null;
  time: string; cost: string; issues: number; escalations: number; tasks: number; progress: number | null;
}
interface CompletionRow { id: number; name: string; progress: number; completed: number; pending: number; ongoing: number; overdue: number }
interface FinancialRow { id: number; name: string; by_currency: Record<string, { budget: number; spent: number }> }

interface Dashboard {
  kpis: { visible_projects: number; visible_status: string; pending_tasks: number; ongoing_tasks: number; overdue_tasks: number };
  health_matrix: HealthRow[];
  completion: CompletionRow[];
  issues_breakdown: { pending: number; ongoing: number; resolved: number };
  task_distribution: { pending: number; ongoing: number; completed: number; overdue: number };
  financials: FinancialRow[];
  financial_totals: Record<string, { budget: number; spent: number }>;
  status_counts: Record<string, number>;
  logistics: {
    tasks_with_requirements: number;
    pending_approval: number;
    needs: Record<string, number>;
  };
}

const router = useRouter();
function org() { return currentOrgSlug.value; }

const data = ref<Dashboard | null>(null);
const loading = ref(false);
const error = ref("");
const statusFilter = ref("");

const STATUS_LABELS: Record<string, string> = {
  "": "Active portfolio", kickoff: "Kickoff", in_flight: "In flight", on_hold: "On hold",
  delivered: "Delivered", cancelled: "Cancelled",
};

async function load(): Promise<void> {
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: Dashboard }>("GET", "/api/v1/projects/dashboard", {
      org: org(), params: { status: statusFilter.value || undefined },
    });
    data.value = res.data;
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not load the dashboard.";
  } finally { loading.value = false; }
}

function openProject(id: number): void { router.push({ name: "project", params: { id } }); }

// ── Health dot tone ────────────────────────────────────────────────────────
const DOT: Record<string, string> = { ok: "#16a34a", warn: "#f59e0b", bad: "#dc2626", neutral: "#cbd5e1", done: "#64748b" };
function dot(v: string): string { return DOT[v] ?? DOT.neutral; }

const STATUS_TONE: Record<string, string> = {
  kickoff: "#6366f1", in_flight: "#2563eb", on_hold: "#f59e0b", delivered: "#16a34a", cancelled: "#94a3b8",
};

// ── Hand-rolled SVG donut ──────────────────────────────────────────────────
interface Slice { label: string; value: number; color: string }
function donutSegments(slices: Slice[]) {
  const total = slices.reduce((s, x) => s + x.value, 0) || 1;
  const R = 60, C = 2 * Math.PI * R;
  let offset = 0;
  return slices.map((s) => {
    const frac = s.value / total;
    const seg = { ...s, dash: frac * C, gap: C - frac * C, offset: -offset * C, pct: Math.round(frac * 100) };
    offset += frac;
    return seg;
  });
}
const issuesSlices = computed<Slice[]>(() => {
  const b = data.value?.issues_breakdown ?? { pending: 0, ongoing: 0, resolved: 0 };
  return [
    { label: "Resolved", value: b.resolved, color: "#2563eb" },
    { label: "Ongoing", value: b.ongoing, color: "#f59e0b" },
    { label: "Pending", value: b.pending, color: "#dc2626" },
  ];
});
const taskSlices = computed<Slice[]>(() => {
  const t = data.value?.task_distribution ?? { pending: 0, ongoing: 0, completed: 0, overdue: 0 };
  return [
    { label: "Completed", value: t.completed, color: "#16a34a" },
    { label: "Ongoing", value: t.ongoing, color: "#f59e0b" },
    { label: "Pending", value: t.pending, color: "#2563eb" },
    { label: "Overdue", value: t.overdue, color: "#dc2626" },
  ];
});
const issuesTotal = computed(() => issuesSlices.value.reduce((s, x) => s + x.value, 0));
const tasksTotal = computed(() => taskSlices.value.reduce((s, x) => s + x.value, 0));

const NEED_LABELS: Record<string, string> = {
  needs_travel: "Travel", needs_client_access: "Client site access", needs_accommodation: "Accommodation",
  needs_asset: "Asset / equipment", needs_software: "Software / license", needs_procurement: "Procurement",
  needs_external_vendors: "External vendor", needs_professional_services: "Professional services",
  needs_subcontractors: "Contractor / subcontractor",
};

function money(n: number): string {
  return n >= 1000 ? n.toLocaleString(undefined, { maximumFractionDigits: 0 }) : String(n);
}

onMounted(load);
</script>

<template>
  <div class="view pd">
    <div class="pd-head">
      <div>
        <h2>Projects Manager Dashboard</h2>
        <p class="pd-sub">The delivery portfolio at a glance — health, tasks, issues and billing.</p>
      </div>
      <div class="pd-head-actions">
        <label class="pd-filter">Status
          <select v-model="statusFilter" @change="load">
            <option value="">Active portfolio</option>
            <option value="kickoff">Kickoff</option>
            <option value="in_flight">In flight</option>
            <option value="on_hold">On hold</option>
            <option value="delivered">Delivered</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </label>
        <button class="btn btn-secondary btn-sm" @click="router.push({ name: 'projects-pm-definitions' })">Define PM tasks</button>
        <button class="btn btn-secondary btn-sm" @click="router.push({ name: 'projects' })">Projects list</button>
      </div>
    </div>

    <p v-if="error" class="pd-error">{{ error }}</p>
    <p v-else-if="loading && !data" class="pd-loading">Loading…</p>

    <template v-if="data">
      <!-- KPI cards -->
      <div class="pd-kpis">
        <div class="pd-kpi pd-kpi-blue">
          <span class="pd-kpi-label">Visible Projects</span>
          <span class="pd-kpi-n">{{ data.kpis.visible_projects }}</span>
          <span class="pd-kpi-tag">{{ STATUS_LABELS[data.kpis.visible_status] ?? data.kpis.visible_status }}</span>
        </div>
        <div class="pd-kpi pd-kpi-amber">
          <span class="pd-kpi-label">Pending Tasks</span>
          <span class="pd-kpi-n">{{ data.kpis.pending_tasks }}</span>
          <span class="pd-kpi-tag">Queued</span>
        </div>
        <div class="pd-kpi pd-kpi-cyan">
          <span class="pd-kpi-label">Ongoing Tasks</span>
          <span class="pd-kpi-n">{{ data.kpis.ongoing_tasks }}</span>
          <span class="pd-kpi-tag">In execution</span>
        </div>
        <div class="pd-kpi pd-kpi-red">
          <span class="pd-kpi-label">Overdue Tasks</span>
          <span class="pd-kpi-n">{{ data.kpis.overdue_tasks }}</span>
          <span class="pd-kpi-tag">Needs attention</span>
        </div>
      </div>

      <!-- Health matrix + completion -->
      <div class="pd-row2">
        <section class="pd-card">
          <h4 class="pd-h4">Active Projects Health Matrix <span class="pd-count">{{ data.health_matrix.length }}</span></h4>
          <div class="pd-scroll">
            <table class="pd-table">
              <thead>
                <tr><th>Project</th><th>Time</th><th>Cost</th><th>Issues</th><th>Escal.</th><th>Tasks</th><th class="pd-prog-h">Progress</th></tr>
              </thead>
              <tbody>
                <tr v-for="p in data.health_matrix" :key="p.id" class="pd-trow" @click="openProject(p.id)">
                  <td class="pd-proj">
                    <span class="pd-status-dot" :style="{ background: STATUS_TONE[p.status] ?? '#94a3b8' }"></span>
                    {{ p.name }}
                  </td>
                  <td><span class="pd-dot" :style="{ background: dot(p.time) }"></span></td>
                  <td><span class="pd-dot" :style="{ background: dot(p.cost) }"></span></td>
                  <td><span class="pd-badge" :class="{ 'pd-badge-red': p.issues > 0 }">{{ p.issues }}</span></td>
                  <td><span class="pd-badge" :class="{ 'pd-badge-red': p.escalations > 0 }">{{ p.escalations }}</span></td>
                  <td><span class="pd-badge pd-badge-cyan">{{ p.tasks }}</span></td>
                  <td>
                    <div class="pd-prog">
                      <div class="pd-prog-bar"><span class="pd-prog-fill" :style="{ width: (p.progress ?? 0) + '%' }"></span></div>
                      <span class="pd-prog-n">{{ p.progress === null ? "—" : p.progress + "%" }}</span>
                    </div>
                  </td>
                </tr>
                <tr v-if="!data.health_matrix.length"><td colspan="7" class="pd-empty">No projects in scope.</td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="pd-card">
          <h4 class="pd-h4">Completion &amp; Task Breakdown</h4>
          <div class="pd-scroll pd-completion">
            <div v-for="c in data.completion" :key="c.id" class="pd-comp">
              <div class="pd-comp-head">
                <span class="pd-comp-name">{{ c.name }}</span>
                <span class="pd-comp-pct">{{ c.progress }}%</span>
              </div>
              <div class="pd-stack">
                <span class="pd-seg" :style="{ width: c.completed + '%', background: '#16a34a' }" :title="'Completed ' + c.completed + '%'"></span>
                <span class="pd-seg" :style="{ width: c.ongoing + '%', background: '#f59e0b' }" :title="'Ongoing ' + c.ongoing + '%'"></span>
                <span class="pd-seg" :style="{ width: c.pending + '%', background: '#2563eb' }" :title="'Pending ' + c.pending + '%'"></span>
                <span class="pd-seg" :style="{ width: c.overdue + '%', background: '#dc2626' }" :title="'Overdue ' + c.overdue + '%'"></span>
              </div>
            </div>
            <p v-if="!data.completion.length" class="pd-empty">No projects in scope.</p>
          </div>
          <div class="pd-legend">
            <span><i style="background:#16a34a"></i>Completed</span><span><i style="background:#f59e0b"></i>Ongoing</span>
            <span><i style="background:#2563eb"></i>Pending</span><span><i style="background:#dc2626"></i>Overdue</span>
          </div>
        </section>
      </div>

      <!-- Charts row: issues pie, financials, tasks donut -->
      <div class="pd-row3">
        <section class="pd-card pd-chart-card">
          <h4 class="pd-h4">Project Issues Breakdown</h4>
          <div v-if="issuesTotal" class="pd-donut-wrap">
            <svg viewBox="0 0 160 160" class="pd-donut">
              <circle cx="80" cy="80" r="60" fill="none" stroke="var(--color-surface-sunken)" stroke-width="20" />
              <circle v-for="s in donutSegments(issuesSlices)" :key="s.label" cx="80" cy="80" r="60" fill="none"
                      :stroke="s.color" stroke-width="20"
                      :stroke-dasharray="`${s.dash} ${s.gap}`" :stroke-dashoffset="s.offset"
                      transform="rotate(-90 80 80)" />
              <text x="80" y="76" text-anchor="middle" class="pd-donut-n">{{ issuesTotal }}</text>
              <text x="80" y="94" text-anchor="middle" class="pd-donut-l">issues</text>
            </svg>
            <ul class="pd-slices">
              <li v-for="s in issuesSlices" :key="s.label"><i :style="{ background: s.color }"></i>{{ s.label }}<span>{{ s.value }}</span></li>
            </ul>
          </div>
          <p v-else class="pd-empty">No issues raised on projects in scope.</p>
        </section>

        <section class="pd-card">
          <h4 class="pd-h4">Financial — Budget vs Billed</h4>
          <div v-if="Object.keys(data.financial_totals).length" class="pd-fin">
            <div v-for="(t, cur) in data.financial_totals" :key="cur" class="pd-fin-cur">
              <div class="pd-fin-row">
                <span class="pd-fin-label">{{ cur }} Agreed</span>
                <div class="pd-prog-bar"><span class="pd-prog-fill" style="background:#16a34a" :style="{ width: '100%' }"></span></div>
                <span class="pd-fin-v">{{ money(t.budget) }}</span>
              </div>
              <div class="pd-fin-row">
                <span class="pd-fin-label">{{ cur }} Billed</span>
                <div class="pd-prog-bar"><span class="pd-prog-fill" style="background:#2563eb"
                      :style="{ width: (t.budget ? Math.min(100, t.spent / t.budget * 100) : 0) + '%' }"></span></div>
                <span class="pd-fin-v">{{ money(t.spent) }}</span>
              </div>
            </div>
          </div>
          <p v-else class="pd-empty">No billing milestones recorded on projects in scope. Agreed and billed values appear here once milestones are added.</p>
        </section>

        <section class="pd-card pd-chart-card">
          <h4 class="pd-h4">Global Tasks Distribution</h4>
          <div v-if="tasksTotal" class="pd-donut-wrap">
            <svg viewBox="0 0 160 160" class="pd-donut">
              <circle cx="80" cy="80" r="60" fill="none" stroke="var(--color-surface-sunken)" stroke-width="20" />
              <circle v-for="s in donutSegments(taskSlices)" :key="s.label" cx="80" cy="80" r="60" fill="none"
                      :stroke="s.color" stroke-width="20"
                      :stroke-dasharray="`${s.dash} ${s.gap}`" :stroke-dashoffset="s.offset"
                      transform="rotate(-90 80 80)" />
              <text x="80" y="76" text-anchor="middle" class="pd-donut-n">{{ tasksTotal }}</text>
              <text x="80" y="94" text-anchor="middle" class="pd-donut-l">tasks</text>
            </svg>
            <ul class="pd-slices">
              <li v-for="s in taskSlices" :key="s.label"><i :style="{ background: s.color }"></i>{{ s.label }}<span>{{ s.value }}</span></li>
            </ul>
          </div>
          <p v-else class="pd-empty">No tasks yet.</p>
        </section>
      </div>

      <!-- ── Requirements & Logistics — planning, budgeting, procurement ── -->
      <section class="pd-card pd-logi-card">
        <h4 class="pd-h4">Requirements &amp; Logistics</h4>
        <div class="pd-logi-kpis">
          <div class="pd-logi-kpi">
            <span class="pd-logi-n">{{ data.logistics.tasks_with_requirements }}</span>
            <span class="pd-logi-l">tasks with requirements</span>
          </div>
          <div class="pd-logi-kpi">
            <span class="pd-logi-n">{{ data.logistics.pending_approval }}</span>
            <span class="pd-logi-l">need approval</span>
          </div>
        </div>
        <ul class="pd-logi-needs">
          <li v-for="(cnt, key) in data.logistics.needs" :key="key">
            <span>{{ NEED_LABELS[key] ?? key }}</span><b>{{ cnt }}</b>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.pd-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
.pd-head h2 { margin: 0; }
.pd-sub { font-size: .85rem; color: var(--color-text-light); margin: 2px 0 0; }
.pd-head-actions { display: flex; align-items: flex-end; gap: 10px; }
.pd-filter { display: flex; flex-direction: column; gap: 3px; font-size: .72rem; font-weight: 600; color: var(--color-text-muted); }
.pd-filter select { font-size: .84rem; padding: 7px 9px; border: 1px solid var(--color-border-strong); border-radius: 8px; background: var(--color-surface); color: var(--color-text); }

.pd-error { font-size: .85rem; color: var(--color-error); background: var(--color-error-light); border-radius: 8px; padding: 9px 12px; }
.pd-loading { color: var(--color-text-light); padding: 24px 0; }

/* KPI cards */
.pd-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 14px; margin-bottom: 16px; }
.pd-logi-card { margin-top: 16px; }
.pd-logi-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 12px; margin-bottom: 12px; }
.pd-logi-kpi { display: flex; flex-direction: column; gap: 2px; padding: 12px 14px; background: var(--color-surface-sunken); border-radius: 10px; }
.pd-logi-n { font-size: var(--text-lg-size); font-weight: 700; color: var(--color-text); font-variant-numeric: tabular-nums; }
.pd-logi-l { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.pd-logi-needs { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 6px 18px; list-style: none; padding: 0; margin: 0; }
.pd-logi-needs li { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: var(--text-sm-size); color: var(--color-text); padding: 4px 0; border-bottom: 1px solid var(--color-border); }
.pd-logi-needs b { font-variant-numeric: tabular-nums; }
.pd-kpi { display: flex; flex-direction: column; gap: 4px; padding: 16px 18px; border-radius: 14px; border: 1px solid var(--color-border); border-left-width: 4px; background: var(--color-surface); }
.pd-kpi-label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--color-text-muted); }
.pd-kpi-n { font-size: 2.1rem; font-weight: 700; line-height: 1; }
.pd-kpi-tag { align-self: flex-start; font-size: .68rem; font-weight: 600; padding: 2px 9px; border-radius: 999px; margin-top: 4px; }
.pd-kpi-blue  { border-left-color: #2563eb; background: #eff6ff; } .pd-kpi-blue  .pd-kpi-n { color: #2563eb; } .pd-kpi-blue  .pd-kpi-tag { background: #dbeafe; color: #1e40af; }
.pd-kpi-amber { border-left-color: #f59e0b; background: #fffbeb; } .pd-kpi-amber .pd-kpi-n { color: #b45309; } .pd-kpi-amber .pd-kpi-tag { background: #fef3c7; color: #92400e; }
.pd-kpi-cyan  { border-left-color: #0891b2; background: #ecfeff; } .pd-kpi-cyan  .pd-kpi-n { color: #0e7490; } .pd-kpi-cyan  .pd-kpi-tag { background: #cffafe; color: #155e75; }
.pd-kpi-red   { border-left-color: #dc2626; background: #fef2f2; } .pd-kpi-red   .pd-kpi-n { color: #dc2626; } .pd-kpi-red   .pd-kpi-tag { background: #fee2e2; color: #991b1b; }

.pd-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 14px; padding: 16px 18px; }
.pd-h4 { margin: 0 0 12px; font-size: .92rem; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.pd-count { font-size: .68rem; font-weight: 700; padding: 1px 8px; border-radius: 999px; background: var(--color-brand-subtle); color: var(--color-brand-base); }

.pd-row2 { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 14px; margin-bottom: 16px; }
.pd-row3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(19rem, 1fr)); gap: 14px; }
@media (max-width: 62rem) { .pd-row2 { grid-template-columns: 1fr; } }

.pd-scroll { max-height: 22rem; overflow-y: auto; }
.pd-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.pd-table th { text-align: left; font-size: .64rem; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-muted); padding: 6px 8px; position: sticky; top: 0; background: var(--color-surface); border-bottom: 1px solid var(--color-border); }
.pd-table td { padding: 7px 8px; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
.pd-prog-h { min-width: 8rem; }
.pd-trow { cursor: pointer; }
.pd-trow:hover { background: var(--color-surface-sunken); }
.pd-proj { display: flex; align-items: center; gap: 7px; min-width: 12rem; color: var(--color-brand-base); font-weight: 500; }
.pd-status-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.pd-dot { display: inline-block; width: 11px; height: 11px; border-radius: 50%; }
.pd-badge { display: inline-block; min-width: 20px; text-align: center; font-size: .72rem; font-weight: 600; padding: 1px 7px; border-radius: 999px; background: var(--color-surface-sunken); color: var(--color-text-light); }
.pd-badge-red { background: var(--color-error-light); color: var(--color-error); }
.pd-badge-cyan { background: #cffafe; color: #155e75; }
.pd-prog { display: flex; align-items: center; gap: 8px; }
.pd-prog-bar { flex: 1; height: 7px; border-radius: 999px; background: var(--color-surface-sunken); overflow: hidden; min-width: 4rem; }
.pd-prog-fill { display: block; height: 100%; border-radius: 999px; background: var(--color-brand-base); }
.pd-prog-n { font-size: .74rem; font-weight: 600; font-variant-numeric: tabular-nums; min-width: 2.4rem; text-align: right; }

.pd-completion { display: flex; flex-direction: column; gap: 12px; }
.pd-comp-head { display: flex; justify-content: space-between; font-size: .8rem; margin-bottom: 4px; }
.pd-comp-name { color: var(--color-text); }
.pd-comp-pct { font-weight: 600; }
.pd-stack { display: flex; height: 9px; border-radius: 999px; overflow: hidden; background: var(--color-surface-sunken); }
.pd-seg { height: 100%; }
.pd-legend { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 12px; font-size: .72rem; color: var(--color-text-light); }
.pd-legend span { display: inline-flex; align-items: center; gap: 5px; }
.pd-legend i, .pd-slices i { width: 9px; height: 9px; border-radius: 2px; display: inline-block; }

.pd-chart-card { display: flex; flex-direction: column; }
.pd-donut-wrap { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.pd-donut { width: 150px; height: 150px; flex-shrink: 0; }
.pd-donut-n { font-size: 1.5rem; font-weight: 700; fill: var(--color-text); }
.pd-donut-l { font-size: .6rem; fill: var(--color-text-light); text-transform: uppercase; letter-spacing: .05em; }
.pd-slices { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; font-size: .8rem; }
.pd-slices li { display: flex; align-items: center; gap: 7px; }
.pd-slices li span { margin-left: auto; font-weight: 600; font-variant-numeric: tabular-nums; }

.pd-fin { display: flex; flex-direction: column; gap: 14px; }
.pd-fin-cur { display: flex; flex-direction: column; gap: 7px; }
.pd-fin-row { display: grid; grid-template-columns: 6rem 1fr auto; align-items: center; gap: 10px; font-size: .8rem; }
.pd-fin-label { color: var(--color-text-light); }
.pd-fin-v { font-weight: 600; font-variant-numeric: tabular-nums; }

.pd-empty { font-size: .82rem; color: var(--color-text-light); text-align: center; padding: 20px; }
</style>
