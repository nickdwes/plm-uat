<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from "vue";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import type { TeamLocationEntry, WorkLocationType } from "../lib/types";

// ── Types ─────────────────────────────────────────────────────────────────────

interface EmploymentType { type: string; count: number }
interface GenderRow      { gender: string; count: number }
interface DeptRow        { department: string; headcount: number }
interface MonthlyHire    { month: string; count: number }

interface DashboardSummary {
  totalActive:       number;
  totalExited:       number;
  newHiresThisMonth: number;
  newHiresLastMonth: number;
  pendingLeave:      number;
  approvedLeave:     number;
  rejectedLeave:     number;
  onLeaveToday:      number;
  leaveRatio:        number;
  headcountByDept:   DeptRow[];
  employmentTypes:   EmploymentType[];
  genderBreakdown:   GenderRow[];
  monthlyHires:      MonthlyHire[];
}

// ── State ─────────────────────────────────────────────────────────────────────

const summary       = ref<DashboardSummary | null>(null);
const loading       = ref(false);
const error         = ref("");
const teamLocations = ref<TeamLocationEntry[] | null>(null);
const schedTab      = ref<"meetings" | "events" | "holidays">("meetings");
const weekOffset    = ref(0); // -1 = prev week, 0 = this week, +1 = next

function org(): string | null { return currentOrgSlug.value; }

// ── API ───────────────────────────────────────────────────────────────────────

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
    // Scoped by permission on the server — a manager gets their reports, HR
    // gets everybody — so there is no separate "team" endpoint to drift.
    const today = new Date().toISOString().slice(0, 10);
    const res = await api<{ data: TeamLocationEntry[] }>("GET", "/api/v1/attendance", {
      org: org(), params: { from: today, to: today },
    });
    teamLocations.value = res.data;
  } catch {
    teamLocations.value = null;
  }
}

onMounted(() => { load(); loadTeamLocations(); });
watch(currentOrgSlug, () => { load(); loadTeamLocations(); });

// ── Colour maps ───────────────────────────────────────────────────────────────

// Note: donut segment colours are intentional palette choices, not brand tokens.
// "permanent" and "male" both use Figma's sky-blue #0c8ef4 which matches our
// brand base exactly — if the brand ever shifts these will need updating too.
const BRAND = "#0c8ef4";
const typeColours: Record<string, string> = {
  permanent:   BRAND,
  contract:    "#F59E0B",
  contractor:  "#F59E0B",
  intern:      "#D1D5DB",
  internship:  "#D1D5DB",
  attachee:    "#6366F1",
  probation:   "#EC4899",
  unspecified: "#D1D5DB",
};
const genderColours: Record<string, string> = {
  male:        BRAND,
  female:      "#D1D5DB",
  other:       "#8B5CF6",
  unspecified: "#E5E7EB",
};
const deptBarColours = ["#7C3AED","#F59E0B","#0D9488","#8B5CF6","#0c8ef4","#10B981","#EC4899","#64748B"];

function typeColour(t: string):   string { return typeColours[t.toLowerCase()]   ?? "#D1D5DB"; }
function genderColour(g: string): string { return genderColours[g.toLowerCase()] ?? "#D1D5DB"; }

// ── Donut computation (stroke-dasharray) ──────────────────────────────────────

interface DonutSeg { label: string; pct: number; color: string; dashArray: string; dashOffset: number; }

function computeDonut(items: { label: string; value: number; color: string }[], r = 44): DonutSeg[] {
  const circ  = 2 * Math.PI * r;
  const total = items.reduce((s, i) => s + i.value, 0) || 1;
  const gap   = items.length > 1 ? 2 : 0;
  let acc     = 0;
  return items.map(item => {
    const pct    = item.value / total;
    const len    = Math.max(0, circ * pct - gap);
    const offset = circ - acc;
    acc += circ * pct;
    return { label: item.label, pct: Math.round(pct * 100), color: item.color,
             dashArray: `${len.toFixed(2)} ${(circ - len).toFixed(2)}`, dashOffset: offset };
  });
}

const genderSegs = computed((): DonutSeg[] =>
  computeDonut((summary.value?.genderBreakdown ?? []).map(g => ({ label: g.gender, value: g.count, color: genderColour(g.gender) })))
);
const typeSegs = computed((): DonutSeg[] =>
  computeDonut((summary.value?.employmentTypes ?? []).map(e => ({ label: e.type, value: e.count, color: typeColour(e.type) })))
);

// ── Line chart ────────────────────────────────────────────────────────────────

const CPAD = { top: 20, right: 16, bottom: 52, left: 52 };
const CH = 230;
const CIH = CH - CPAD.top - CPAD.bottom;

/*
 * The viewBox width tracks the card; its height never does.
 *
 * This was a fixed 560x230 box on an svg set to width:100% with no height,
 * which means the browser derives the height from the width to preserve the
 * ratio. On a full-width dashboard card that is about 1750px across, and a
 * 230-tall chart rendered itself 720px tall — a single data point occupying
 * most of a screen.
 *
 * Capping the height alone would not fix it: with preserveAspectRatio the
 * drawing would shrink to fit and sit in the middle of the card with empty
 * gutters either side. Measuring instead keeps the grid spanning the full
 * width, the height constant, and the labels at their intended size rather
 * than scaled up with everything else.
 */
const chartWrap = ref<HTMLElement | null>(null);
const CW = ref(560);
const CIW = computed(() => CW.value - CPAD.left - CPAD.right);

let chartResize: ResizeObserver | null = null;

onMounted(() => {
  if (!chartWrap.value || typeof ResizeObserver === "undefined") return;
  chartResize = new ResizeObserver(([entry]) => {
    // A floor keeps the axis labels from colliding once the card is narrow
    // enough that the wrapper starts scrolling instead.
    CW.value = Math.max(360, Math.round(entry.contentRect.width));
  });
  chartResize.observe(chartWrap.value);
});

onBeforeUnmount(() => {
  chartResize?.disconnect();
  chartResize = null;
});

const chartYMax = computed(() => {
  const max = Math.max(1, ...(summary.value?.monthlyHires?.map(h => h.count) ?? [0]));
  if (max <= 10) return 10;
  const mag = Math.pow(10, Math.floor(Math.log10(max)));
  return Math.ceil(max / mag) * mag;
});

const chartGridLines = computed(() =>
  Array.from({ length: 6 }, (_, i) => ({
    y: CPAD.top + i * (CIH / 5),
    label: String(Math.round(chartYMax.value * (1 - i / 5))),
  }))
);

const chartPoints = computed(() => {
  const hires = summary.value?.monthlyHires ?? [];
  if (!hires.length) return [];
  const ymax = chartYMax.value;
  return hires.map((h, i) => ({
    x: CPAD.left + (hires.length === 1 ? CIW.value / 2 : (i / (hires.length - 1)) * CIW.value),
    y: CPAD.top  + CIH - (h.count / ymax) * CIH,
    label: formatMonth(h.month),
    count: h.count,
  }));
});

function smoothPath(pts: { x: number; y: number }[]): string {
  if (pts.length < 2) return "";
  let d = `M ${pts[0].x.toFixed(1)} ${pts[0].y.toFixed(1)}`;
  for (let i = 0; i < pts.length - 1; i++) {
    const mx = ((pts[i].x + pts[i + 1].x) / 2).toFixed(1);
    d += ` C ${mx} ${pts[i].y.toFixed(1)} ${mx} ${pts[i + 1].y.toFixed(1)} ${pts[i + 1].x.toFixed(1)} ${pts[i + 1].y.toFixed(1)}`;
  }
  return d;
}

const chartLinePath  = computed(() => smoothPath(chartPoints.value));
const chartAreaPath  = computed(() => {
  const pts = chartPoints.value;
  if (!pts.length) return "";
  const bottom = (CPAD.top + CIH).toFixed(1);
  return `${smoothPath(pts)} L ${pts[pts.length-1].x.toFixed(1)} ${bottom} L ${pts[0].x.toFixed(1)} ${bottom} Z`;
});

const maxDeptHeadcount = computed(() =>
  Math.max(1, ...(summary.value?.headcountByDept?.map(d => d.headcount) ?? [0]))
);

const totalEmployees = computed(() => {
  const s = summary.value;
  return s ? s.totalActive + s.totalExited : 0;
});

// ── Week calendar ─────────────────────────────────────────────────────────────

const NOW = new Date();

const calendarMonth = computed(() => {
  const base = new Date(NOW);
  base.setDate(base.getDate() + weekOffset.value * 7);
  return base.toLocaleString("default", { month: "long", year: "numeric" });
});

const weekDays = computed(() => {
  const base = new Date(NOW);
  base.setDate(base.getDate() + weekOffset.value * 7);
  const dow = base.getDay();
  const mon = new Date(base);
  mon.setDate(base.getDate() - (dow === 0 ? 6 : dow - 1));
  return ["Mon", "Tue", "Wed", "Thu", "Fri"].map((lbl, i) => {
    const d = new Date(mon);
    d.setDate(mon.getDate() + i);
    return { label: lbl, date: d.getDate(), isToday: d.toDateString() === NOW.toDateString() };
  });
});

const todayStr = NOW.toLocaleDateString("en-GB", {
  weekday: "long", day: "numeric", month: "long", year: "numeric",
});

// ── Team location helpers ─────────────────────────────────────────────────────

function fmtTime(d: string | null | undefined): string {
  if (!d) return "—";
  try { return new Date(d).toLocaleTimeString("en-KE", { hour: "2-digit", minute: "2-digit" }); }
  catch { return d; }
}

function wlBadgeClass(type: WorkLocationType): string {
  return ({ office: "badge-blue", home: "badge-teal", customer_site: "badge-amber" } as Record<string, string>)[type] ?? "badge-gray";
}
/** The customer is now a linked account rather than a typed name. */
function wlLabel(type: WorkLocationType, customer?: string | null): string {
  const base = ({ office: "Office", home: "Work From Home", customer_site: "Customer Site" } as Record<string, string>)[type] ?? type;
  return type === "customer_site" && customer ? `${base}: ${customer}` : base;
}

function formatMonth(ym: string): string {
  const [y, m] = ym.split("-");
  return new Date(Number(y), Number(m) - 1).toLocaleString("default", { month: "short" });
}
</script>

<template>
  <div class="view dash-root">

    <!-- ── Page header ──────────────────────────────────────────────────── -->
    <div class="dash-header">
      <div>
        <h2 class="dash-title">HR Dashboard</h2>
        <p class="dash-sub">{{ todayStr }}</p>
      </div>
      <div class="dash-header-pills">
        <!--
          The chart is a page rather than a pill: it is the one thing on this
          screen people leave the dashboard to look at, and it wants the width.
        -->
        <router-link :to="{ name: 'org-chart' }" class="dash-chart-link">
          <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path d="M9 2h2a1 1 0 011 1v2a1 1 0 01-1 1h-.5v2H14a1 1 0 011 1v1.5h.5a1 1 0 011 1v2a1 1 0 01-1 1h-2a1 1 0 01-1-1v-2a1 1 0 011-1H14V10h-3v1.5h.5a1 1 0 011 1v2a1 1 0 01-1 1h-2a1 1 0 01-1-1v-2a1 1 0 011-1H10V10H7v1.5h.5a1 1 0 011 1v2a1 1 0 01-1 1h-2a1 1 0 01-1-1v-2a1 1 0 011-1H6V10a1 1 0 011-1h2.5V7H9a1 1 0 01-1-1V3a1 1 0 011-1z"/>
          </svg>
          Org Chart
        </router-link>
      </div>

      <div class="dash-header-pills" v-if="summary">
        <span class="dash-pill">
          <svg viewBox="0 0 20 20" fill="currentColor"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zm8 0a3 3 0 11-6 0 3 3 0 016 0zM.458 18C1.64 14.435 5.08 12 9 12s7.36 2.435 8.542 6H.458z"/></svg>
          Employees {{ summary.totalActive }}
        </span>
        <span class="dash-pill">
          <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
          On Leave Today: {{ summary.onLeaveToday }}
        </span>
      </div>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error"   class="error">{{ error }}</p>

    <template v-if="summary">

      <!-- ── Summary strip ─────────────────────────────────────────────── -->
      <div class="sum-strip">

        <!-- 1 · Employee Summary -->
        <div class="sum-card">
          <div class="sum-eyebrow">
            <span class="sum-icon-wrap sum-icon-blue">
              <svg viewBox="0 0 20 20" fill="currentColor"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zm8 0a3 3 0 11-6 0 3 3 0 016 0zM.458 18C1.64 14.435 5.08 12 9 12s7.36 2.435 8.542 6H.458z"/></svg>
            </span>
            Employee Summary
          </div>
          <div class="sum-stats">
            <div class="sum-stat">
              <p class="sum-lbl">All</p>
              <p class="sum-val">{{ totalEmployees }}</p>
            </div>
            <div class="sum-divider"></div>
            <div class="sum-stat">
              <p class="sum-lbl">On Leave</p>
              <p class="sum-val">{{ summary.onLeaveToday }}</p>
            </div>
            <div class="sum-divider"></div>
            <div class="sum-stat">
              <p class="sum-lbl">Exited</p>
              <p class="sum-val">{{ summary.totalExited }}</p>
            </div>
          </div>
        </div>

        <!-- 2 · Leave Overview (maps to Talent Requisition layout) -->
        <div class="sum-card">
          <div class="sum-eyebrow">
            <span class="sum-icon-wrap sum-icon-purple">
              <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
            </span>
            Leave Overview
          </div>
          <div class="sum-stats">
            <div class="sum-stat">
              <p class="sum-lbl">All</p>
              <p class="sum-val">{{ summary.pendingLeave + summary.approvedLeave + summary.rejectedLeave }}</p>
            </div>
            <div class="sum-divider"></div>
            <div class="sum-stat">
              <p class="sum-lbl">Pending</p>
              <p class="sum-val">{{ summary.pendingLeave }}</p>
            </div>
            <div class="sum-divider"></div>
            <div class="sum-stat">
              <p class="sum-lbl">Approved</p>
              <p class="sum-val">{{ summary.approvedLeave }}</p>
            </div>
            <div class="sum-divider"></div>
            <div class="sum-stat">
              <p class="sum-lbl">Rejected</p>
              <p class="sum-val">{{ summary.rejectedLeave }}</p>
            </div>
          </div>
        </div>

        <!-- 3 · New Hires (maps to Work Today layout) -->
        <div class="sum-card">
          <div class="sum-eyebrow">
            <span class="sum-icon-wrap sum-icon-amber">
              <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
            </span>
            New Hires
          </div>
          <div class="sum-stats">
            <div class="sum-stat">
              <p class="sum-lbl">This Month</p>
              <p class="sum-val">{{ summary.newHiresThisMonth }}</p>
            </div>
            <div class="sum-divider"></div>
            <div class="sum-stat">
              <p class="sum-lbl">Last Month</p>
              <p class="sum-val">{{ summary.newHiresLastMonth }}</p>
            </div>
          </div>
        </div>

      </div><!-- /sum-strip -->

      <!-- ── Main two-column grid ──────────────────────────────────────── -->
      <div class="dash-main">

        <!-- ── LEFT column ────────────────────────────────────────────── -->
        <div class="dash-left">

          <!-- Donut charts row -->
          <div class="donut-row">

            <!-- Gender Distribution -->
            <div class="card donut-card">
              <div class="card-head">
                <h3 class="card-title">Gender Distribution</h3>
                <svg class="more-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
              </div>
              <div class="donut-body" v-if="summary.genderBreakdown?.length">
                <div class="donut-wrap">
                  <svg viewBox="0 0 100 100" class="donut-svg">
                    <circle cx="50" cy="50" r="44" fill="none" stroke="var(--color-neutral-200)" stroke-width="12"/>
                    <circle
                      v-for="seg in genderSegs" :key="seg.label"
                      cx="50" cy="50" r="44" fill="none"
                      :stroke="seg.color" stroke-width="12"
                      :stroke-dasharray="seg.dashArray" :stroke-dashoffset="seg.dashOffset"
                      transform="rotate(-90 50 50)"
                    />
                  </svg>
                </div>
                <div class="donut-legend">
                  <div v-for="seg in genderSegs" :key="seg.label" class="leg-row">
                    <span class="leg-dot" :style="{ background: seg.color }"></span>
                    <span class="leg-label">{{ seg.label }}</span>
                    <span class="leg-pct">{{ seg.pct }}%</span>
                  </div>
                </div>
              </div>
              <p v-else class="dash-empty">No data.</p>
            </div>

            <!-- Employee Category -->
            <div class="card donut-card">
              <div class="card-head">
                <h3 class="card-title">Employee Category</h3>
                <svg class="more-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
              </div>
              <div class="donut-body" v-if="summary.employmentTypes?.length">
                <div class="donut-wrap">
                  <svg viewBox="0 0 100 100" class="donut-svg">
                    <circle cx="50" cy="50" r="44" fill="none" stroke="var(--color-neutral-200)" stroke-width="12"/>
                    <circle
                      v-for="seg in typeSegs" :key="seg.label"
                      cx="50" cy="50" r="44" fill="none"
                      :stroke="seg.color" stroke-width="12"
                      :stroke-dasharray="seg.dashArray" :stroke-dashoffset="seg.dashOffset"
                      transform="rotate(-90 50 50)"
                    />
                  </svg>
                </div>
                <div class="donut-legend">
                  <div v-for="seg in typeSegs" :key="seg.label" class="leg-row">
                    <span class="leg-dot" :style="{ background: seg.color }"></span>
                    <span class="leg-label">{{ seg.label }}</span>
                    <span class="leg-pct">{{ seg.pct }}%</span>
                  </div>
                </div>
              </div>
              <p v-else class="dash-empty">No data.</p>
            </div>

          </div><!-- /donut-row -->

          <!-- Department Headcount Ranking -->
          <div class="card dept-card">
            <div class="card-head">
              <h3 class="card-title">Department Headcount Ranking</h3>
            </div>
            <div class="dept-list">
              <div v-for="(row, i) in summary.headcountByDept" :key="row.department" class="dept-row">
                <span class="dept-num">{{ i + 1 }}.</span>
                <span class="dept-name">{{ row.department || "Unassigned" }}</span>
                <div class="dept-track">
                  <div
                    class="dept-bar"
                    :style="{
                      width: ((row.headcount / maxDeptHeadcount) * 100) + '%',
                      background: deptBarColours[i % deptBarColours.length]
                    }"
                  ></div>
                </div>
                <span class="dept-count">{{ row.headcount }}</span>
              </div>
              <p v-if="!summary.headcountByDept.length" class="dash-empty">
                No departments configured.
                <router-link :to="{ name: 'org' }">Set up →</router-link>
              </p>
            </div>
            <router-link v-if="summary.headcountByDept.length" :to="{ name: 'employees' }" class="see-more">
              See more
            </router-link>
          </div>

          <!-- Monthly Hiring Trend — line chart -->
          <div v-if="summary.monthlyHires?.length" class="card trend-card">
            <div class="card-head">
              <h3 class="card-title">Monthly Hiring Trend</h3>
              <span class="trend-period-badge">{{ summary.monthlyHires.length }} months</span>
            </div>
            <div ref="chartWrap" class="trend-chart-wrap">
              <svg :viewBox="`0 0 ${CW} ${CH}`" class="trend-svg" preserveAspectRatio="xMidYMid meet">
                <!-- Grid lines -->
                <line
                  v-for="gl in chartGridLines" :key="gl.y"
                  :x1="CPAD.left" :y1="gl.y" :x2="CW - CPAD.right" :y2="gl.y"
                  stroke="var(--color-neutral-200)" stroke-width="1"
                />
                <!-- Y-axis labels -->
                <text
                  v-for="gl in chartGridLines" :key="`l${gl.y}`"
                  :x="CPAD.left - 8" :y="gl.y + 4"
                  class="chart-axis-label" text-anchor="end"
                >{{ gl.label }}</text>
                <!-- X-axis labels -->
                <text
                  v-for="(pt, i) in chartPoints" :key="`x${i}`"
                  :x="pt.x" :y="CPAD.top + CIH + 20"
                  class="chart-axis-label" text-anchor="middle"
                >{{ pt.label }}</text>
                <!-- Y-axis label -->
                <text
                  :x="12" :y="CPAD.top + CIH / 2"
                  class="chart-axis-label chart-axis-rotate"
                  text-anchor="middle"
                  :transform="`rotate(-90 12 ${CPAD.top + CIH / 2})`"
                >New Hires</text>
                <!-- Area fill -->
                <path :d="chartAreaPath" class="chart-area"/>
                <!-- Line -->
                <path :d="chartLinePath" class="chart-line" fill="none" stroke="var(--color-brand-base)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                <!-- Data points -->
                <circle
                  v-for="(pt, i) in chartPoints" :key="`dot${i}`"
                  :cx="pt.x" :cy="pt.y" r="3.5"
                  fill="var(--color-brand-base)" stroke="white" stroke-width="1.5"
                >
                  <title>{{ pt.label }}: {{ pt.count }}</title>
                </circle>
              </svg>
            </div>
          </div>

        </div><!-- /dash-left -->

        <!-- ── RIGHT column — Schedule ─────────────────────────────────── -->
        <div class="dash-right">
          <div class="card sched-card">

            <!-- Schedule header -->
            <div class="card-head">
              <h3 class="card-title">
                <svg viewBox="0 0 20 20" fill="currentColor" class="sched-cal-icon"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
                Schedule
              </h3>
              <router-link :to="{ name: 'leave' }" class="see-all-link">See all</router-link>
            </div>

            <!-- Month navigator -->
            <div class="cal-month-row">
              <button class="cal-nav-btn" @click="weekOffset--">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
              </button>
              <span class="cal-month-label">{{ calendarMonth }}</span>
              <button class="cal-nav-btn" @click="weekOffset++">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
              </button>
            </div>

            <!-- Week strip with nav -->
            <div class="cal-week-row">
              <button class="cal-nav-btn cal-nav-sm" @click="weekOffset--">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
              </button>
              <div class="cal-week">
                <div v-for="day in weekDays" :key="day.date" :class="['cal-day', { 'cal-today': day.isToday }]">
                  <span class="cal-day-lbl">{{ day.label }}</span>
                  <span class="cal-day-num">{{ day.date }}</span>
                </div>
              </div>
              <button class="cal-nav-btn cal-nav-sm" @click="weekOffset++">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
              </button>
            </div>

            <!-- Tabs -->
            <div class="sched-tabs">
              <button :class="['sched-tab', { active: schedTab === 'meetings' }]" @click="schedTab = 'meetings'">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"/></svg>
                Meetings
              </button>
              <button :class="['sched-tab', { active: schedTab === 'events' }]" @click="schedTab = 'events'">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
                Events
              </button>
              <button :class="['sched-tab', { active: schedTab === 'holidays' }]" @click="schedTab = 'holidays'">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                Holidays
              </button>
            </div>

            <!-- Meetings content -->
            <div v-if="schedTab === 'meetings'" class="sched-entries">
              <template v-if="teamLocations?.length">
                <div
                  v-for="(entry, idx) in teamLocations"
                  :key="entry.id"
                  :class="['sched-entry', {
                    'entry-top':    idx === 0,
                    'entry-bottom': idx === teamLocations.length - 1,
                    'entry-mid':    idx > 0 && idx < teamLocations.length - 1
                  }]"
                >
                  <div class="entry-time">
                    <p class="entry-time-main">{{ entry.first_clock_in_at ? fmtTime(entry.first_clock_in_at) : '—' }}</p>
                    <p class="entry-time-end">{{ entry.last_clock_out_at ? fmtTime(entry.last_clock_out_at) : '' }}</p>
                  </div>
                  <div class="entry-divider"></div>
                  <div class="entry-body">
                    <p class="entry-title">{{ entry.employee?.name ?? "—" }}</p>
                    <p class="entry-sub">
                      <template v-if="entry.actual_location">
                        <span :class="['badge', wlBadgeClass(entry.actual_location!)]">
                          {{ wlLabel(entry.actual_location!, entry.account?.name) }}
                        </span>
                      </template>
                      <template v-else>Not checked in</template>
                    </p>
                  </div>
                  <button class="entry-chevron">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                  </button>
                </div>
              </template>
              <div v-else class="sched-empty">
                <p>No team check-ins today</p>
              </div>
            </div>

            <!-- Events content -->
            <div v-if="schedTab === 'events'" class="sched-empty">
              <p>No events scheduled</p>
            </div>

            <!-- Holidays content -->
            <div v-if="schedTab === 'holidays'" class="sched-entries">
              <router-link :to="{ name: 'public-holidays' }" class="sched-holiday-link">
                View public holidays calendar →
              </router-link>
            </div>

          </div><!-- /sched-card -->
        </div><!-- /dash-right -->

      </div><!-- /dash-main -->

    </template>

    <!-- Empty state -->
    <template v-if="!loading && !summary && !error">
      <div class="dash-empty-state">
        <svg viewBox="0 0 48 48" fill="none"><rect x="4" y="8" width="40" height="36" rx="4" stroke="currentColor" stroke-width="2"/><path d="M4 18h40M14 4v8M34 4v8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <p class="des-title">Select an organisation to view the dashboard</p>
        <p class="des-sub">Workforce metrics, leave stats and hiring trends will appear here.</p>
      </div>
    </template>

  </div>
</template>

<style scoped>
/* ─────────────────────────────────────────────────────────────────────────────
   Root — fill the full available content area, no artificial width cap.
   The parent .app-content already handles horizontal padding.
   ───────────────────────────────────────────────────────────────────────────── */
.dash-root { max-width: 100%; }

/* ── Page header ────────────────────────────────────────────────────────────── */
.dash-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  margin-bottom: 20px; flex-wrap: wrap; gap: 10px;
}
.dash-title { font-size: 22px; font-weight: 700; color: var(--color-text); letter-spacing: -.3px; }
.dash-sub   { font-size: 13px; color: var(--color-text-muted); margin-top: 3px; }

.dash-header-pills { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.dash-pill {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 500; color: var(--color-text-muted);
  background: var(--color-surface); border: 1px solid var(--color-border);
  padding: 5px 12px; border-radius: 20px;
}
.dash-pill svg { width: 14px; height: 14px; flex-shrink: 0; }

/* ── Summary strip ──────────────────────────────────────────────────────────── */
.sum-strip {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 14px;
  margin-bottom: 16px;
}
@media (max-width: 700px) { .sum-strip { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 440px) { .sum-strip { grid-template-columns: 1fr; } }

.sum-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 16px 20px;
  box-shadow: var(--shadow-xs);
}

.sum-eyebrow {
  display: flex; align-items: center; gap: 8px;
  font-size: 13px; font-weight: 600; color: var(--color-text);
  margin-bottom: 16px;
}
.sum-icon-wrap {
  width: 28px; height: 28px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.sum-icon-wrap svg { width: 14px; height: 14px; }
.sum-icon-blue   { background: #DBEAFE; color: var(--color-brand-base); }
.sum-icon-purple { background: #EDE9FE; color: #7C3AED; }
.sum-icon-amber  { background: #FEF3C7; color: #D97706; }

.sum-stats {
  display: flex; align-items: stretch; gap: 0;
}
.sum-stat  { flex: 1; min-width: 0; padding: 0 10px; }
.sum-stat:first-child { padding-left: 0; }
.sum-stat:last-child  { padding-right: 0; }

.sum-divider {
  width: 1px; background: var(--color-border);
  align-self: stretch; flex-shrink: 0;
}

.sum-lbl {
  font-size: 11px; color: var(--color-text-muted); margin-bottom: 4px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sum-val {
  font-size: clamp(22px, 2.5vw, 32px);
  font-weight: 700; color: var(--color-text);
  line-height: 1.1; font-variant-numeric: tabular-nums;
}

/* ── Main two-column grid ────────────────────────────────────────────────────── */
.dash-main {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 16px;
  align-items: start;
}

/* Tablet: narrow the schedule, keep two columns */
@media (max-width: 1024px) and (min-width: 769px) {
  .dash-main { grid-template-columns: 1fr 280px; }
}

/* Mobile: single column, reorder schedule after main content */
@media (max-width: 768px) {
  .dash-main {
    grid-template-columns: 1fr;
  }
  .dash-right { order: 2; }
  .dash-left  { order: 1; }
}

.dash-left { display: flex; flex-direction: column; gap: 14px; }

/* ── Sticky schedule sidebar ─────────────────────────────────────────────────── */
.dash-right {
  position: sticky;
  top: 0;
  align-self: start;           /* sticky only works on start-aligned flex/grid items */
}

.sched-card {
  /* Height = viewport minus app header (60px) minus app-content top padding (32px) */
  max-height: calc(100vh - 100px);
  display: flex;
  flex-direction: column;
  margin-bottom: 0 !important;  /* override .card global margin */
  overflow: hidden;
}

/* On mobile unstick and remove fixed height */
@media (max-width: 768px) {
  .dash-right { position: static; }
  .sched-card { max-height: none; }
}

/* The entries list fills remaining card height and scrolls internally */
.sched-entries {
  flex: 1;
  overflow-y: auto;
  min-height: 0;           /* required for flex children to respect overflow */
  display: flex;
  flex-direction: column;
  gap: 0;
}

/* ── Card head ──────────────────────────────────────────────────────────────── */
.card-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 16px; flex-shrink: 0;
}
.card-head .card-title { margin-bottom: 0; display: flex; align-items: center; gap: 6px; font-size: 15px; }
.more-icon { width: 16px; height: 16px; color: var(--color-text-muted); opacity: .5; cursor: pointer; }
.more-icon:hover { opacity: 1; }

/* ── Donut row ──────────────────────────────────────────────────────────────── */
.donut-row {
  display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
}
@media (max-width: 540px) { .donut-row { grid-template-columns: 1fr; } }

.donut-body {
  display: flex; align-items: center; gap: 20px;
}
.donut-wrap { flex-shrink: 0; width: 120px; height: 120px; }
.donut-svg  { width: 100%; height: 100%; display: block; }

.donut-legend { flex: 1; display: flex; flex-direction: column; gap: 9px; }
.leg-row {
  display: flex; align-items: center; gap: 8px;
  font-size: 12px;
}
.leg-dot    { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.leg-label  { flex: 1; color: var(--color-text-muted); text-transform: capitalize; }
.leg-pct    { font-weight: 700; color: var(--color-text); font-variant-numeric: tabular-nums; min-width: 32px; text-align: right; }

/* ── Department ranking ─────────────────────────────────────────────────────── */
.dept-list { display: flex; flex-direction: column; gap: 10px; }
.dept-row  { display: flex; align-items: center; gap: 8px; }
.dept-num  { font-size: 12px; color: var(--color-text-muted); flex-shrink: 0; width: 22px; }
.dept-name {
  font-size: 13px; color: var(--color-text); flex-shrink: 0;
  width: clamp(120px, 20%, 200px);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.dept-track { flex: 1; height: 8px; background: var(--color-neutral-200); border-radius: 4px; overflow: hidden; }
.dept-bar   { height: 100%; border-radius: 4px; transition: width .5s ease; }
.dept-count {
  font-size: 13px; font-weight: 600; color: var(--color-text-muted);
  width: 28px; text-align: right; flex-shrink: 0; font-variant-numeric: tabular-nums;
}

.see-more {
  display: inline-block; margin-top: 14px;
  font-size: 13px; font-weight: 600; color: var(--color-brand-base);
  text-decoration: none;
}
.see-more:hover { text-decoration: underline; }

/* ── Monthly Hiring Trend — line chart ──────────────────────────────────────── */
.trend-period-badge {
  font-size: 11px; font-weight: 600; padding: 3px 10px;
  border: 1px solid var(--color-border); border-radius: 20px;
  color: var(--color-text-muted);
}

.trend-chart-wrap { overflow-x: auto; }
/* Height is fixed and the viewBox width is measured to match the card, so the
   chart keeps one size on a phone and on a 1440 display. */
.trend-svg        { width: 100%; min-width: 360px; height: 230px; display: block; }

.chart-axis-label {
  font-size: 10px; fill: var(--color-text-muted);
  font-family: var(--font-family);
}
.chart-area  { fill: var(--color-brand-base); opacity: .12; }
.chart-line  {
  stroke: var(--color-brand-base); stroke-width: 2.5;
  fill: none; stroke-linecap: round; stroke-linejoin: round;
}

/* ── Schedule card — flex column so it fills sticky height ───────────────────── */
.sched-cal-icon { width: 16px; height: 16px; color: var(--color-brand-base); flex-shrink: 0; }
.see-all-link   { font-size: 12px; font-weight: 600; color: var(--color-brand-base); text-decoration: none; flex-shrink: 0; }
.see-all-link:hover { text-decoration: underline; }

/* Month nav row */
.cal-month-row {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 14px; flex-shrink: 0;
}
.cal-month-label { font-size: 13px; font-weight: 600; color: var(--color-text); }

.cal-nav-btn {
  background: none; border: none; cursor: pointer; padding: 4px;
  color: var(--color-text-muted); display: flex; align-items: center; justify-content: center;
  border-radius: 6px; transition: background .12s, color .12s;
}
.cal-nav-btn svg  { width: 16px; height: 16px; }
.cal-nav-btn:hover { background: var(--color-neutral-100); color: var(--color-text); }
.cal-nav-sm svg   { width: 14px; height: 14px; }

/* Week strip */
.cal-week-row {
  display: flex; align-items: center; gap: 4px;
  margin-bottom: 14px; flex-shrink: 0;
}
.cal-week {
  flex: 1; display: grid; grid-template-columns: repeat(5, 1fr); gap: 2px;
}
.cal-day {
  display: flex; flex-direction: column; align-items: center; gap: 3px;
  padding: 4px 0;
}
.cal-day-lbl {
  font-size: 10px; font-weight: 500;
  color: var(--color-text-muted); text-transform: uppercase; letter-spacing: .03em;
}
.cal-day-num {
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; font-weight: 500; color: var(--color-text);
  border-radius: 8px;
}
.cal-today .cal-day-lbl { color: var(--color-brand-base); font-weight: 700; }
.cal-today .cal-day-num {
  background: var(--color-brand-base); color: #ffffff; font-weight: 700;
}

/* Tabs — flex-shrink:0 so they don't collapse inside flex sched-card */
.sched-tabs {
  display: flex; border-bottom: 1px solid var(--color-border);
  margin-bottom: 0; flex-shrink: 0;
}
.sched-tab {
  flex: 1; background: none; border: none; cursor: pointer;
  padding: 7px 4px 9px;
  display: flex; align-items: center; justify-content: center; gap: 4px;
  font-size: 12px; font-weight: 500; color: var(--color-text-muted);
  border-bottom: 2px solid transparent; margin-bottom: -1px;
  transition: color .15s, border-color .15s;
  font-family: inherit;
}
.sched-tab svg { width: 13px; height: 13px; flex-shrink: 0; }
.sched-tab.active { color: var(--color-brand-base); border-bottom-color: var(--color-brand-base); font-weight: 600; }
.sched-tab:hover:not(.active) { color: var(--color-text); }

/* Meeting entries */
.sched-entry {
  display: flex; align-items: center; gap: 10px;
  background: var(--color-neutral-50);
  border-left: 1px solid var(--color-border);
  border-right: 1px solid var(--color-border);
  padding: 12px 10px;
  flex-shrink: 0;
}
.entry-top    { border-top: 1px solid var(--color-border); border-radius: 10px 10px 0 0; }
.entry-bottom { border-bottom: 1px solid var(--color-border); border-radius: 0 0 10px 10px; margin-bottom: 8px; }

.entry-top + .entry-mid,
.entry-top + .entry-bottom,
.entry-mid + .entry-mid,
.entry-mid + .entry-bottom { border-top: 1px solid var(--color-border); }

.entry-time      { flex-shrink: 0; width: 50px; }
.entry-time-main { font-size: 12px; font-weight: 600; color: var(--color-text); line-height: 1.3; }
.entry-time-end  { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }

.entry-divider {
  width: 2px; height: 34px; background: var(--color-brand-base);
  opacity: .35; border-radius: 1px; flex-shrink: 0;
}

.entry-body  { flex: 1; min-width: 0; }
.entry-title { font-size: 13px; font-weight: 600; color: var(--color-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.entry-sub   { margin-top: 3px; font-size: 11px; color: var(--color-text-muted); display: flex; align-items: center; gap: 4px; }

.entry-chevron {
  flex-shrink: 0; background: none; border: none; cursor: pointer;
  padding: 4px; color: var(--color-text-muted); display: flex;
  border-radius: 4px; transition: background .12s;
}
.entry-chevron svg { width: 14px; height: 14px; }
.entry-chevron:hover { background: var(--color-neutral-100); color: var(--color-text); }

.sched-empty {
  flex: 1; display: flex; align-items: center; justify-content: center;
  color: var(--color-text-muted); font-size: 13px; padding: 24px 0;
}
.sched-holiday-link {
  display: block; padding: 16px 0; font-size: 13px; font-weight: 600;
  color: var(--color-brand-base); text-decoration: none;
}
.sched-holiday-link:hover { text-decoration: underline; }

/* ── Empty state ────────────────────────────────────────────────────────────── */
.dash-empty {
  font-size: 13px; color: var(--color-text-muted); text-align: center; padding: 16px 0;
}
.dash-empty-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  min-height: 240px; gap: 10px; color: var(--color-text-muted);
}
.dash-empty-state svg { width: 48px; height: 48px; opacity: .25; }
.des-title { font-size: 15px; font-weight: 600; color: var(--color-text); text-align: center; }
.des-sub   { font-size: 13px; color: var(--color-text-muted); text-align: center; }

/* ── Mobile overrides ────────────────────────────────────────────────────────── */
@media (max-width: 480px) {
  .dash-title { font-size: 18px; }
  .sum-card   { padding: 14px 16px; }
  .sum-lbl    { font-size: 10px; }
  .sum-stat   { padding: 0 8px; }
  .donut-wrap { width: 100px; height: 100px; }
  .dept-name  { width: 110px; }
}

.dash-chart-link { display: inline-flex; align-items: center; gap: 7px;
  padding: 7px 14px; border-radius: 999px; text-decoration: none;
  font-size: .85rem; font-weight: 500;
  background: var(--color-brand-subtle); color: var(--color-brand-base);
  border: 1px solid var(--color-brand-light); }
.dash-chart-link:hover { background: var(--color-brand-light); }
.dash-chart-link svg { width: 16px; height: 16px; }
</style>
