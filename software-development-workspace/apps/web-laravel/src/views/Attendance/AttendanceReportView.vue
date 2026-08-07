<script setup lang="ts">
/**
 * What the workforce actually did.
 *
 * The temptation with a reporting screen is a wall of figures. The discipline
 * is deciding which of them anybody acts on, and putting that one first.
 *
 * Here that is `unexplained` — work that slipped with nobody told. Slippage
 * itself is noise: work slips, and a manager reacting to every missed day is
 * reacting to weather. Silence is the thing worth a conversation, so the table
 * leads with it and sorts by it rather than alphabetically.
 *
 * Rates, not counts, wherever people sit beside each other. Somebody on leave
 * half the month has fewer of everything, and a column of raw totals quietly
 * ranks people by how much they were at work.
 */
import { ref, computed, onMounted } from "vue";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifyFailed } from "../../lib/toast";

interface PersonRow {
  employee: { id: number; name: string };
  department: string | null;
  attendance: {
    worked: number; on_time: number; late: number; absent: number; on_leave: number;
    punctuality: number | null; hours: number | null;
  };
  location: { office: number; home: number; customer_site: number };
  work: { promised: number; done: number; missed: number; unexplained: number; completion: number | null };
  enablement_days: number;
}
interface Trends {
  weeks: { week: string; office: number; home: number; customer_site: number; total: number }[];
  plan_adherence: { stated: number; kept: number; rate: number | null };
}
interface Distribution {
  by_project: { label: string; count: number }[];
  by_account: { label: string; count: number }[];
  enablement: { days: number; promised: number; done: number; days_with_nothing_recorded: number };
}

const people = ref<PersonRow[]>([]);
const trends = ref<Trends | null>(null);
const spread = ref<Distribution | null>(null);
const loading = ref(true);

const monthsBack = ref(1);

const org = () => currentOrgSlug.value;

function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

const range = computed(() => {
  const to = new Date();
  const from = new Date();
  from.setMonth(from.getMonth() - monthsBack.value);
  const iso = (d: Date) => d.toISOString().slice(0, 10);
  return { from: iso(from), to: iso(to) };
});

async function load() {
  loading.value = true;
  try {
    const [p, t, d] = await Promise.all([
      api<{ data: PersonRow[] }>("GET", "/api/v1/attendance/analytics/people", { org: org(), params: range.value }),
      api<Trends>("GET", "/api/v1/attendance/analytics/trends", { org: org(), params: range.value }),
      api<Distribution>("GET", "/api/v1/attendance/analytics/distribution", { org: org(), params: range.value }),
    ]);
    people.value = p.data;
    trends.value = t;
    spread.value = d;
  } catch (e) { fail(e, "Could not load the report."); }
  finally { loading.value = false; }
}

// ── Presentation ────────────────────────────────────────────────────────────

/** A missing rate is missing, not zero — nothing to divide by is not failure. */
const pct = (v: number | null) => (v === null ? "—" : `${v}%`);

const weekLabel = (iso: string) =>
  new Date(iso + "T00:00:00").toLocaleDateString(undefined, { day: "numeric", month: "short" });

/** The widest bar in a set, so the bars are comparable within their own chart. */
const peak = (rows: { count: number }[]) => Math.max(1, ...rows.map((r) => r.count));

const totals = computed(() => ({
  unexplained: people.value.reduce((n, r) => n + r.work.unexplained, 0),
  promised:    people.value.reduce((n, r) => n + r.work.promised, 0),
  done:        people.value.reduce((n, r) => n + r.work.done, 0),
}));

/** Nobody to compare against is not a reason to hide the shape of the week. */
const hasTrends = computed(() => (trends.value?.weeks.length ?? 0) > 0);

onMounted(load);
</script>

<template>
  <div class="rp-wrap">
    <header class="rp-header">
      <div>
        <h1 class="rp-title">Attendance Report</h1>
        <p class="rp-sub">Attendance, location, and what the work came to</p>
      </div>
      <select v-model.number="monthsBack" class="control rp-range" @change="load">
        <option :value="1">Last month</option>
        <option :value="3">Last 3 months</option>
        <option :value="6">Last 6 months</option>
        <option :value="12">Last 12 months</option>
      </select>
    </header>

    <p v-if="loading" class="rp-quiet">Loading…</p>

    <template v-else>
      <!--
        The one figure anybody acts on, and the only one large enough to see
        from across the room. Not "how much slipped" — work slips, and a
        manager reacting to that is reacting to weather.
      -->
      <div class="rp-headline">
        <div :class="['rp-big', { bad: totals.unexplained }]">
          <span class="rp-big-value">{{ totals.unexplained }}</span>
          <span class="rp-big-label">commitments went unexplained</span>
        </div>
        <p class="rp-big-note">
          Work slips; that is ordinary. What is worth a conversation is work
          that slipped with nobody told.
          <template v-if="totals.promised">
            {{ totals.done }} of {{ totals.promised }} committed items landed.
          </template>
        </p>
      </div>

      <!-- ── A row per person ───────────────────────────────────────────── -->
      <section class="rp-card">
        <h2 class="rp-card-title">By person</h2>
        <div class="rp-table-wrap">
          <table class="rp-table">
            <thead>
              <tr>
                <th>Employee</th><th>Department</th>
                <th>Days</th><th>On time</th><th>Hours</th>
                <th>Office</th><th>Home</th><th>Customer</th>
                <th>Committed</th><th>Completed</th><th>Unexplained</th>
                <th>Enablement</th>
              </tr>
            </thead>
            <tbody>
              <!-- Sorted by what needs a conversation. A report people have to
                   scan for the problem is one they stop opening. -->
              <tr v-for="r in people" :key="r.employee.id" :class="{ flagged: r.work.unexplained }">
                <td class="rp-name">{{ r.employee.name }}</td>
                <td class="rp-muted">{{ r.department ?? "—" }}</td>
                <td>{{ r.attendance.worked }}</td>
                <td>{{ pct(r.attendance.punctuality) }}</td>
                <td>{{ r.attendance.hours ?? "—" }}</td>
                <td>{{ r.location.office }}</td>
                <td>{{ r.location.home }}</td>
                <td>{{ r.location.customer_site }}</td>
                <td>{{ r.work.promised }}</td>
                <td>{{ pct(r.work.completion) }}</td>
                <td>
                  <span v-if="r.work.unexplained" class="rp-chip bad">{{ r.work.unexplained }}</span>
                  <span v-else class="rp-muted">—</span>
                </td>
                <td>{{ r.enablement_days }}</td>
              </tr>
              <tr v-if="!people.length"><td colspan="12" class="rp-empty">Nothing recorded in this period.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="rp-grid">
        <!-- ── Where the work happened ──────────────────────────────────── -->
        <section class="rp-card">
          <h2 class="rp-card-title">Where the work happened</h2>

          <p v-if="!hasTrends" class="rp-quiet">No locations reported yet.</p>

          <div v-else class="rp-weeks">
            <div v-for="w in trends!.weeks" :key="w.week" class="rp-week">
              <div class="rp-stack" :title="`${w.total} day(s)`">
                <span class="rp-seg office" :style="{ height: `${(w.office / w.total) * 100}%` }"></span>
                <span class="rp-seg home" :style="{ height: `${(w.home / w.total) * 100}%` }"></span>
                <span class="rp-seg site" :style="{ height: `${(w.customer_site / w.total) * 100}%` }"></span>
              </div>
              <span class="rp-week-label">{{ weekLabel(w.week) }}</span>
            </div>
          </div>

          <p class="rp-legend">
            <span><i class="dot office"></i> Office</span>
            <span><i class="dot home"></i> Home</span>
            <span><i class="dot site"></i> Customer site</span>
          </p>

          <!-- Whether the location reporting is worth anything at all. A plan
               nobody keeps should not be driving desk planning. -->
          <p v-if="trends?.plan_adherence.rate !== null" class="rp-note">
            <strong>{{ pct(trends!.plan_adherence.rate) }}</strong>
            of stated plans were kept ({{ trends!.plan_adherence.kept }} of
            {{ trends!.plan_adherence.stated }}). Below about two thirds and the
            planned location is not worth booking desks against.
          </p>
        </section>

        <!-- ── Where it was pointed ─────────────────────────────────────── -->
        <section class="rp-card">
          <h2 class="rp-card-title">What the work was for</h2>

          <h3 class="rp-sub-title">By project</h3>
          <p v-if="!spread?.by_project.length" class="rp-quiet">Nothing committed against a project.</p>
          <ul v-else class="rp-bars">
            <li v-for="p in spread!.by_project" :key="p.label">
              <span class="rp-bar-label">{{ p.label }}</span>
              <span class="rp-bar"><i :style="{ width: `${(p.count / peak(spread!.by_project)) * 100}%` }"></i></span>
              <span class="rp-bar-value">{{ p.count }}</span>
            </li>
          </ul>

          <h3 class="rp-sub-title">By customer</h3>
          <p v-if="!spread?.by_account.length" class="rp-quiet">Nothing committed against a customer.</p>
          <ul v-else class="rp-bars">
            <li v-for="a in spread!.by_account" :key="a.label">
              <span class="rp-bar-label">{{ a.label }}</span>
              <span class="rp-bar"><i :style="{ width: `${(a.count / peak(spread!.by_account)) * 100}%` }"></i></span>
              <span class="rp-bar-value">{{ a.count }}</span>
            </li>
          </ul>
        </section>

        <!-- ── Enablement ───────────────────────────────────────────────── -->
        <section v-if="spread" class="rp-card">
          <h2 class="rp-card-title">Enablement</h2>
          <div class="rp-figures">
            <div><span class="rp-fig-label">Days taken</span><span class="rp-fig">{{ spread.enablement.days }}</span></div>
            <div><span class="rp-fig-label">Activities</span><span class="rp-fig">{{ spread.enablement.promised }}</span></div>
            <div><span class="rp-fig-label">Completed</span><span class="rp-fig">{{ spread.enablement.done }}</span></div>
            <div>
              <span class="rp-fig-label">Nothing recorded</span>
              <span :class="['rp-fig', { bad: spread.enablement.days_with_nothing_recorded }]">
                {{ spread.enablement.days_with_nothing_recorded }}
              </span>
            </div>
          </div>
          <!-- The answer nobody wants but everybody needs. -->
          <p v-if="spread.enablement.days_with_nothing_recorded" class="rp-note">
            An approved enablement day with nothing recorded against it is a day
            off by another name. Worth asking about before the scheme is judged
            on the ones that were used.
          </p>
        </section>
      </div>
    </template>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.rp-wrap { padding: var(--space-6); max-width: 96rem; }

.rp-header { @apply flex flex-wrap items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-5); }
.rp-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.rp-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }
.rp-range { max-width: 12rem; }
.rp-quiet { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0 0 var(--space-3); }
.rp-empty { padding: var(--space-6); text-align: center; color: var(--color-text-muted); }
.rp-muted { color: var(--color-text-light); }

.rp-headline {
  @apply flex flex-wrap items-center; gap: var(--space-5);
  padding: var(--space-5); margin-bottom: var(--space-5);
  border: 1px solid var(--color-border); border-radius: var(--radius-lg);
  background: var(--color-surface-raised);
}
.rp-big { @apply flex items-baseline; gap: var(--space-3); }
.rp-big-value { font-size: 2.5rem; font-weight: 700; color: var(--color-text); line-height: 1; }
.rp-big.bad .rp-big-value { color: var(--color-danger-text); }
.rp-big-label { font-size: var(--text-sm-size); color: var(--color-text-muted); }
.rp-big-note { flex: 1 1 20rem; font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; }

.rp-card {
  padding: var(--space-5); margin-bottom: var(--space-4);
  border: 1px solid var(--color-border); border-radius: var(--radius-lg);
  background: var(--color-surface-raised);
}
.rp-card-title { font-size: var(--text-base-size); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-4); }
.rp-sub-title { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); margin: var(--space-4) 0 var(--space-2); text-transform: uppercase; letter-spacing: 0.04em; }
.rp-grid { @apply grid; grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr)); gap: var(--space-4); }
.rp-grid > .rp-card { margin-bottom: 0; }

.rp-table-wrap { overflow-x: auto; }
.rp-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.rp-table th { text-align: left; padding: var(--space-2) var(--space-3); font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); background: var(--color-surface-sunken); white-space: nowrap; }
.rp-table td { padding: var(--space-3); border-bottom: 1px solid var(--color-border); color: var(--color-text); white-space: nowrap; }
.rp-table tr:last-child td { border-bottom: none; }
.rp-name { font-weight: 500; }
/* The rows that need a conversation, marked so the table is scannable. */
.rp-table tr.flagged td { background: color-mix(in oklab, var(--color-danger-subtle) 35%, transparent); }

.rp-chip { display: inline-block; padding: 1px var(--space-2); border-radius: var(--radius-sm); font-size: var(--text-xs-size); }
.rp-chip.bad { background: var(--color-danger-subtle); color: var(--color-danger-text); }

.rp-weeks { @apply flex items-end; gap: var(--space-2); height: 9rem; }
.rp-week { @apply flex flex-1 flex-col items-center; gap: var(--space-1); min-width: 2rem; }
.rp-stack { @apply flex w-full flex-col justify-end overflow-hidden; height: 7rem; border-radius: var(--radius-sm); background: var(--color-surface-sunken); }
.rp-seg { display: block; width: 100%; }
.rp-seg.office { background: var(--color-brand-base); }
.rp-seg.home   { background: color-mix(in oklab, var(--color-success-text) 60%, var(--color-surface)); }
.rp-seg.site   { background: var(--color-warning-text); }
.rp-week-label { font-size: 0.6rem; color: var(--color-text-muted); }

.rp-legend { @apply flex flex-wrap; gap: var(--space-4); margin: var(--space-3) 0 0; font-size: var(--text-xs-size); color: var(--color-text-muted); }
.rp-legend .dot { @apply inline-block; width: 0.6rem; height: 0.6rem; border-radius: 2px; margin-right: 4px; }
.rp-legend .dot.office { background: var(--color-brand-base); }
.rp-legend .dot.home   { background: color-mix(in oklab, var(--color-success-text) 60%, var(--color-surface)); }
.rp-legend .dot.site   { background: var(--color-warning-text); }

.rp-note { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: var(--space-3) 0 0; }

.rp-bars { @apply flex flex-col; gap: var(--space-2); list-style: none; margin: 0; padding: 0; }
.rp-bars li { @apply grid items-center; grid-template-columns: minmax(6rem, 10rem) 1fr 2rem; gap: var(--space-2); font-size: var(--text-xs-size); }
.rp-bar-label { color: var(--color-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rp-bar { @apply block; height: 0.6rem; border-radius: 999px; background: var(--color-surface-sunken); }
.rp-bar i { @apply block; height: 100%; border-radius: 999px; background: var(--color-brand-base); }
.rp-bar-value { color: var(--color-text-muted); text-align: right; }

.rp-figures { @apply grid; grid-template-columns: repeat(auto-fit, minmax(6rem, 1fr)); gap: var(--space-3); }
.rp-figures > div { @apply flex flex-col; gap: 2px; }
.rp-fig-label { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.rp-fig { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); }
.rp-fig.bad { color: var(--color-danger-text); }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }
</style>
