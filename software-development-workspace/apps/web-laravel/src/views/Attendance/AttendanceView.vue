<script setup lang="ts">
/**
 * Attendance Tracker — where people worked, and whether the day happened.
 *
 * Three cards, then the month as a calendar or as a list. Both read the same
 * endpoint, because they are two pictures of the same days and two endpoints
 * would eventually disagree about one of them.
 *
 * Two decisions the designs imply and are worth naming:
 *
 *   A day is not absent until it is over. Today's empty square is empty, not
 *   red — a red square on the calendar of somebody sitting at their desk is how
 *   people learn to distrust the whole page.
 *
 *   Planned and actual are both shown. The gap between them is the single most
 *   useful thing a hybrid-working report can say, and a calendar that only
 *   shows one of the two throws it away.
 */
import { ref, computed, onMounted } from "vue";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import AccountPicker from "../../components/AccountPicker.vue";

interface Punch {
  id: number; in: string; out: string | null; minutes: number | null;
  location_type: string | null; is_manual: boolean; note: string | null;
}
/**
 * Something somebody said they would finish today, and how it went.
 *
 * Not a slot on a clock. `outcome` null means nobody has said yet — which on a
 * finished day is itself the finding, and is not the same as it going well.
 */
interface Commitment {
  id: number; title: string; kind: string; position: number; note: string | null;
  outcome: "done" | "not_done" | null;
  reason: string | null;
  is_explained: boolean;
  reported_at: string | null;
  carried_to: { id: number; work_date: string | null } | null;
  starts_at: string | null; ends_at: string | null;
  task: { id: number; reference: string | null; status: string; priority: string; is_done: boolean } | null;
}
interface Day {
  id: number; work_date: string; status: string | null;
  employee: { id: number; name: string; department: string | null } | null;
  planned_location: string | null; actual_location: string | null;
  plan_was_kept: boolean | null;
  account: { id: number; name: string } | null;
  location_note: string | null;
  first_clock_in_at: string | null; last_clock_out_at: string | null;
  worked_minutes: number | null; overtime_minutes: number | null;
  is_clocked_in: boolean | null;
  notes: string | null;
  punches: Punch[];
  commitments: Commitment[];
  /** Promised, delivered, missed — and missed with nobody told. */
  tally: { promised: number; done: number; missed: number; unexplained: number; open: number };
  is_reported: boolean;
  audits?: { field: string; from: string | null; to: string | null; at: string | null; by: string | null }[];
}
interface Summary {
  present: { on_time: number; late: number; early: number };
  not_present: { absent: number; no_clock_in: number; no_clock_out: number };
  away: { on_leave: number; holiday: number };
  locations: { office: number; home: number; customer_site: number };
  work: { promised: number; done: number; missed: number; unexplained: number };
}

const days    = ref<Day[]>([]);
const summary = ref<Summary | null>(null);
const loading = ref(true);
const saving  = ref(false);

const view   = ref<"calendar" | "list">("calendar");
/**
 * Month, week or day — the same records at three zoom levels.
 *
 * Month answers "what did this period look like"; week and day answer "what am
 * I doing", which is a different question and needs the clock down the side.
 */
const zoom   = ref<"month" | "week" | "day">("month");
const cursor = ref(new Date());
const search = ref("");

const org = () => currentOrgSlug.value;

function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

const iso = (d: Date) => {
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

/** Monday of whichever week the cursor sits in. */
function mondayOf(d: Date): Date {
  const out = new Date(d);
  out.setDate(out.getDate() - ((out.getDay() + 6) % 7));
  return out;
}

/** What is on screen, as the API wants it. */
const range = computed(() => {
  const c = cursor.value;

  if (zoom.value === "day") return { from: iso(c), to: iso(c) };

  if (zoom.value === "week") {
    const start = mondayOf(c);
    const end = new Date(start);
    end.setDate(end.getDate() + 6);
    return { from: iso(start), to: iso(end) };
  }

  const y = c.getFullYear();
  const m = c.getMonth();
  return { from: iso(new Date(y, m, 1)), to: iso(new Date(y, m + 1, 0)) };
});

async function load() {
  loading.value = true;
  try {
    const [list, cards] = await Promise.all([
      api<{ data: Day[] }>("GET", "/api/v1/attendance", { org: org(), params: range.value }),
      api<Summary>("GET", "/api/v1/attendance/summary", { org: org(), params: range.value }),
    ]);
    days.value = list.data;
    summary.value = cards;
  } catch (e) { fail(e, "Could not load attendance."); }
  finally { loading.value = false; }
}

/** Step by whatever unit is on screen, so the arrows always mean "next". */
function step(by: number) {
  const d = new Date(cursor.value);
  if (zoom.value === "month") d.setMonth(d.getMonth() + by);
  else if (zoom.value === "week") d.setDate(d.getDate() + 7 * by);
  else d.setDate(d.getDate() + by);
  cursor.value = d;
  load();
}

function setZoom(z: "month" | "week" | "day") {
  zoom.value = z;
  load();
}

// ── The clock ───────────────────────────────────────────────────────────────

const today = computed(() =>
  days.value.find((d) => d.work_date === new Date().toISOString().slice(0, 10)) ?? null);

const clockedIn = computed(() => today.value?.is_clocked_in === true);

async function clock(direction: "in" | "out") {
  saving.value = true;
  try {
    await api("POST", `/api/v1/attendance/clock-${direction}`, {
      org: org(), body: direction === "in" ? { location_type: reporting.value.actual_location } : {},
    });
    notifySaved(direction === "in" ? "Clocked in." : "Clocked out.");
    await load();
  } catch (e) { fail(e, `Could not clock ${direction}.`); }
  finally { saving.value = false; }
}

// ── Saying where you are working ────────────────────────────────────────────

const showReport = ref(false);
const reporting = ref({
  work_date: new Date().toISOString().slice(0, 10),
  planned_location: "office" as string | null,
  actual_location: null as string | null,
  account_id: null as number | null,
  location_note: "",
});

function openReport(date?: string) {
  reporting.value = {
    work_date: date ?? new Date().toISOString().slice(0, 10),
    planned_location: "office", actual_location: null,
    account_id: null, location_note: "",
  };
  showReport.value = true;
}

async function saveReport() {
  saving.value = true;
  try {
    await api("POST", "/api/v1/attendance/record", {
      org: org(),
      body: { ...reporting.value, location_note: reporting.value.location_note || null },
    });
    showReport.value = false;
    notifySaved("Recorded.");
    await load();
  } catch (e) { fail(e, "Could not record that."); }
  finally { saving.value = false; }
}

// ── Hours after the fact ────────────────────────────────────────────────────

const showHours = ref(false);
const hours = ref({ work_date: new Date().toISOString().slice(0, 10), from: "", to: "", note: "" });

async function saveHours() {
  if (!hours.value.from || !hours.value.to) return;
  saving.value = true;
  try {
    await api("POST", "/api/v1/attendance/add-hours", {
      org: org(), body: { ...hours.value, note: hours.value.note || null },
    });
    showHours.value = false;
    notifySaved("Hours added.");
    await load();
  } catch (e) { fail(e, "Could not add those hours."); }
  finally { saving.value = false; }
}

// ── Planning the day ────────────────────────────────────────────────────────

/**
 * Adding a block picks work that already exists.
 *
 * Project narrows the task list; the task itself is what gets the slot. Nothing
 * here creates a task — that would put a second copy of everything scheduled
 * into a list the Tasks module cannot see.
 */
const showPlan = ref(false);
const planning = ref({
  work_date: "", task_id: null as number | null,
  title: "", kind: "work", starts_at: "09:00", ends_at: "10:00",
});
const projects = ref<{ id: number; name: string }[]>([]);
const pickable = ref<{ id: number; title: string }[]>([]);
const projectFilter = ref<number | null>(null);

async function loadProjects() {
  try {
    projects.value = (await api<{ data: { id: number; name: string }[] }>(
      "GET", "/api/v1/projects", { org: org(), params: { per_page: 100 } },
    )).data;
  } catch { projects.value = []; }
}

async function loadPickableTasks() {
  try {
    pickable.value = (await api<{ data: { id: number; title: string }[] }>(
      "GET", "/api/v1/tasks",
      { org: org(), params: { project_id: projectFilter.value || undefined, state: "open", per_page: 100 } },
    )).data;
  } catch { pickable.value = []; }
}

function openPlan(date: string) {
  planning.value = {
    work_date: date, task_id: null, title: "", kind: "work",
    starts_at: "", ends_at: "",
  };
  projectFilter.value = null;
  showPlan.value = true;
  loadProjects();
  loadPickableTasks();
}

async function savePlan() {
  const p = planning.value;
  if (!p.task_id && !p.title.trim()) return;

  saving.value = true;
  try {
    await api("POST", "/api/v1/attendance/commitments", {
      org: org(),
      body: {
        work_date: p.work_date, task_id: p.task_id,
        title: p.title.trim() || null, kind: p.kind,
        // Optional, and only for the people who want them.
        starts_at: p.starts_at || null, ends_at: p.ends_at || null,
      },
    });
    showPlan.value = false;
    notifySaved("Added to the day.");
    await load();
  } catch (e) { fail(e, "Could not add that to the day."); }
  finally { saving.value = false; }
}

async function removeCommitment(c: Commitment) {
  try {
    await api("DELETE", `/api/v1/attendance/commitments/${c.id}`, { org: org() });
    notifySaved("Removed.");
    await load();
  } catch (e) { fail(e, "Could not remove that."); }
}

// ── Saying how it went ──────────────────────────────────────────────────────

/**
 * Done needs nothing. Not done needs a reason, so the form asks for one before
 * it will save — asked on the day by the person who knows, rather than a
 * fortnight later when the answer is "can't remember".
 */
const explaining = ref<Commitment | null>(null);
const explanation = ref({ reason: "", carry_to: "" });

async function markDone(c: Commitment) {
  saving.value = true;
  try {
    await api("POST", `/api/v1/attendance/commitments/${c.id}/report`, {
      org: org(), body: { outcome: "done" },
    });
    await load();
  } catch (e) { fail(e, "Could not record that."); }
  finally { saving.value = false; }
}

function openExplain(c: Commitment) {
  explaining.value = c;
  explanation.value = { reason: c.reason ?? "", carry_to: "" };
}

async function saveExplanation() {
  if (!explaining.value || !explanation.value.reason.trim()) return;
  saving.value = true;
  try {
    await api("POST", `/api/v1/attendance/commitments/${explaining.value.id}/report`, {
      org: org(),
      body: {
        outcome: "not_done",
        reason: explanation.value.reason.trim(),
        carry_to: explanation.value.carry_to || null,
      },
    });
    explaining.value = null;
    notifySaved("Recorded, and your manager has been told.");
    await load();
  } catch (e) { fail(e, "Could not record that."); }
  finally { saving.value = false; }
}

// ── One day ─────────────────────────────────────────────────────────────────

const open = ref<Day | null>(null);

async function openDay(d: Day) {
  try {
    open.value = (await api<{ data: Day }>("GET", `/api/v1/attendance/${d.id}`, { org: org() })).data;
  } catch (e) { fail(e, "Could not open the day."); }
}

// ── Presentation ────────────────────────────────────────────────────────────

const LOCATIONS = [
  { key: "office", label: "Office" },
  { key: "home", label: "Home (remote)" },
  { key: "customer_site", label: "Customer site" },
];

const STATUS_LABEL: Record<string, string> = {
  on_time: "On time", late: "Late", early: "Early",
  absent: "Absent", no_clock_in: "No clock in", no_clock_out: "No clock out",
  on_leave: "On Leave", holiday: "Holiday", weekend: "Weekend",
};

const statusTone = (s: string | null) =>
  s === "on_time" || s === "early" ? "ok"
    : s === "late" || s === "no_clock_out" ? "warn"
      : s === "absent" || s === "no_clock_in" ? "bad"
        : s === "on_leave" || s === "holiday" ? "info" : "neutral";

const locationLabel = (v: string | null) =>
  v ? (LOCATIONS.find((l) => l.key === v)?.label ?? v) : "—";

/** Hours as people say them: "8hrs 44min". */
function hoursOf(minutes: number | null): string {
  if (minutes === null) return "—";
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m ? `${h}hrs ${m}min` : `${h}hrs`;
}

const time = (v: string | null) =>
  v ? new Date(v).toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" }) : "—";

const date = (v: string) =>
  new Date(v + "T00:00:00").toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" });

/** Whatever period is on screen, named the way people say it. */
const periodLabel = computed(() => {
  const c = cursor.value;

  if (zoom.value === "day") {
    return c.toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" });
  }

  if (zoom.value === "week") {
    const start = mondayOf(c);
    const end = new Date(start);
    end.setDate(end.getDate() + 6);
    const fmt = (d: Date) => d.toLocaleDateString(undefined, { day: "numeric", month: "short" });
    return `${fmt(start)} – ${fmt(end)}, ${end.getFullYear()}`;
  }

  return c.toLocaleDateString(undefined, { month: "short", year: "numeric" });
});

const byDate = computed(() => {
  const map: Record<string, Day> = {};
  for (const d of days.value) map[d.work_date] = d;
  return map;
});

/**
 * The month laid out Monday-first, padded to whole weeks.
 *
 * Null cells rather than dates from the neighbouring months, so nothing in a
 * grey square is ever clickable — an accidental click that records attendance
 * against the wrong month is a hard mistake to notice.
 */
const weeks = computed(() => {
  const y = cursor.value.getFullYear();
  const m = cursor.value.getMonth();
  const first = new Date(y, m, 1);
  const lead = (first.getDay() + 6) % 7;          // Monday-first
  const total = new Date(y, m + 1, 0).getDate();
  const pad = (n: number) => String(n).padStart(2, "0");

  const cells: (string | null)[] = Array(lead).fill(null);
  for (let d = 1; d <= total; d++) cells.push(`${y}-${pad(m + 1)}-${pad(d)}`);
  while (cells.length % 7) cells.push(null);

  const out: (string | null)[][] = [];
  for (let i = 0; i < cells.length; i += 7) out.push(cells.slice(i, i + 7));
  return out;
});

const isToday = (d: string) => d === new Date().toISOString().slice(0, 10);

/** The seven dates of the week on screen, whatever the cursor sits on. */
const weekDates = computed(() => {
  const start = mondayOf(cursor.value);
  return Array.from({ length: 7 }, (_, i) => {
    const d = new Date(start);
    d.setDate(d.getDate() + i);
    return iso(d);
  });
});

const dayName = (d: string) =>
  new Date(d + "T00:00:00").toLocaleDateString(undefined, { weekday: "short", day: "numeric" });

const commitmentsOn = (d: string) => byDate.value[d]?.commitments ?? [];
const tallyOn = (d: string) => byDate.value[d]?.tally ?? null;

/**
 * How a commitment reads at a glance.
 *
 * Unanswered on a day that is over is its own state, and the one worth
 * chasing — showing it the same as "went fine" is how the record stops being
 * believed.
 */
function commitmentTone(c: Commitment, date: string): string {
  if (c.outcome === "done") return "ok";
  if (c.outcome === "not_done") return c.is_explained ? "warn" : "bad";
  return date < new Date().toISOString().slice(0, 10) ? "bad" : "neutral";
}

const listRows = computed(() => {
  const term = search.value.trim().toLowerCase();
  if (!term) return days.value;
  return days.value.filter((d) =>
    (d.employee?.name ?? "").toLowerCase().includes(term)
    || (d.account?.name ?? "").toLowerCase().includes(term));
});

onMounted(load);
</script>

<template>
  <div class="at-wrap">
    <header class="at-header">
      <div>
        <h1 class="at-title">Attendance Tracker</h1>
        <p class="at-sub">Analyze your attendance record</p>
      </div>
      <div class="at-actions">
        <button class="btn-ghost" @click="showHours = true">Add Hours</button>
        <button v-if="!clockedIn" class="btn-primary" :disabled="saving" @click="clock('in')">
          ▶ Clock In
        </button>
        <button v-else class="btn-primary danger" :disabled="saving" @click="clock('out')">
          ■ Clock Out
        </button>
        <button class="btn-primary" @click="openReport()">+ Add Attendance</button>
      </div>
    </header>

    <!-- ── The three cards ──────────────────────────────────────────────── -->
    <div v-if="summary" class="at-cards">
      <section class="at-card">
        <h2 class="at-card-title">Present Summary</h2>
        <div class="at-figures">
          <div><span class="at-fig-label">On time</span><span class="at-fig">{{ summary.present.on_time }}</span></div>
          <div><span class="at-fig-label">Late clock in</span><span class="at-fig">{{ summary.present.late }}</span></div>
          <div><span class="at-fig-label">Early clock in</span><span class="at-fig">{{ summary.present.early }}</span></div>
        </div>
      </section>

      <section class="at-card">
        <h2 class="at-card-title">Not Present Summary</h2>
        <div class="at-figures">
          <div><span class="at-fig-label">Absent</span><span class="at-fig">{{ summary.not_present.absent }}</span></div>
          <div><span class="at-fig-label">No clock in</span><span class="at-fig">{{ summary.not_present.no_clock_in }}</span></div>
          <!-- Somebody who came and forgot to close the day. A different
               failure from not turning up, so counted apart from it. -->
          <div><span class="at-fig-label">No clock out</span><span class="at-fig">{{ summary.not_present.no_clock_out }}</span></div>
        </div>
      </section>

      <section class="at-card">
        <h2 class="at-card-title">Away Summary</h2>
        <div class="at-figures">
          <div><span class="at-fig-label">On Leave</span><span class="at-fig">{{ summary.away.on_leave }}</span></div>
          <div><span class="at-fig-label">Holiday</span><span class="at-fig">{{ summary.away.holiday }}</span></div>
        </div>
      </section>

      <!--
        How the work went.

        `unexplained` leads because it is the only figure anybody has to act on.
        Work slips; a manager reacting to slippage is reacting to noise. Work
        that slipped with nobody told is the thing that was uncountable before.
      -->
      <section class="at-card">
        <h2 class="at-card-title">Work Summary</h2>
        <div class="at-figures">
          <div><span class="at-fig-label">Committed</span><span class="at-fig">{{ summary.work.promised }}</span></div>
          <div><span class="at-fig-label">Completed</span><span class="at-fig">{{ summary.work.done }}</span></div>
          <div>
            <span class="at-fig-label">Unexplained</span>
            <span :class="['at-fig', { late: summary.work.unexplained }]">{{ summary.work.unexplained }}</span>
          </div>
        </div>
      </section>

      <!--
        Where the work happened.

        Not one of the designs' three cards, but it is the whole point of
        asking people to report a location — collecting it and never showing it
        teaches people the question does not matter.
      -->
      <section class="at-card">
        <h2 class="at-card-title">Where</h2>
        <div class="at-figures">
          <div><span class="at-fig-label">Office</span><span class="at-fig">{{ summary.locations.office }}</span></div>
          <div><span class="at-fig-label">Home</span><span class="at-fig">{{ summary.locations.home }}</span></div>
          <div><span class="at-fig-label">Customer site</span><span class="at-fig">{{ summary.locations.customer_site }}</span></div>
        </div>
      </section>
    </div>

    <!-- ── View switch ──────────────────────────────────────────────────── -->
    <div class="at-panel">
      <nav class="at-tabs">
        <button :class="['at-tab', { active: view === 'calendar' }]" @click="view = 'calendar'">Calendar view</button>
        <button :class="['at-tab', { active: view === 'list' }]" @click="view = 'list'">List view</button>
      </nav>

      <div class="at-toolbar">
        <input v-model="search" class="control at-search" type="search" placeholder="Search" />
        <div class="at-month">
          <button class="at-nav" aria-label="Previous" @click="step(-1)">‹</button>
          <span class="at-month-label">{{ periodLabel }}</span>
          <button class="at-nav" aria-label="Next" @click="step(1)">›</button>

          <!-- Month answers "what did this period look like"; week and day
               answer "what am I doing", which needs the clock down the side. -->
          <select v-if="view === 'calendar'" :value="zoom" class="control at-zoom"
                  @change="setZoom(($event.target as HTMLSelectElement).value as 'month' | 'week' | 'day')">
            <option value="month">Monthly View</option>
            <option value="week">Weekly View</option>
            <option value="day">Daily View</option>
          </select>
        </div>
      </div>

      <p v-if="loading" class="at-quiet">Loading…</p>

      <!-- ── Calendar ───────────────────────────────────────────────────── -->
      <table v-else-if="view === 'calendar' && zoom === 'month'" class="at-cal">
        <thead>
          <tr><th v-for="d in ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']" :key="d">{{ d }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="(week, i) in weeks" :key="i">
            <td v-for="(iso, j) in week" :key="j"
                :class="['at-cell', { outside: !iso, today: iso && isToday(iso) }]"
                @click="iso && (byDate[iso] ? openDay(byDate[iso]) : openReport(iso))">
              <template v-if="iso">
                <span class="at-daynum">{{ Number(iso.slice(8)) }}</span>
                <!-- What was promised and how much of it landed. The
                     unexplained count leads, because that is the only part
                     anybody has to act on. -->
                <span v-if="byDate[iso]?.tally?.unexplained" class="at-chip chip-bad">
                  {{ byDate[iso].tally.unexplained }} unexplained
                </span>
                <span v-else-if="byDate[iso]?.tally?.promised" class="at-chip chip-plan">
                  {{ byDate[iso].tally.done }}/{{ byDate[iso].tally.promised }} done
                </span>

                <span v-if="byDate[iso]?.worked_minutes"
                      :class="['at-chip', `chip-${statusTone(byDate[iso].status)}`]">
                  {{ hoursOf(byDate[iso].worked_minutes) }}
                </span>
                <span v-else-if="byDate[iso]?.status"
                      :class="['at-chip', `chip-${statusTone(byDate[iso].status)}`]">
                  {{ STATUS_LABEL[byDate[iso].status!] }}
                </span>
                <!-- Planned but not yet worked. Shown differently from a day
                     that happened, so a plan is never mistaken for a record. -->
                <span v-else-if="byDate[iso]?.planned_location" class="at-chip chip-plan">
                  {{ locationLabel(byDate[iso].planned_location) }} · planned
                </span>
              </template>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- ── Week / day: what was promised, and how it went ─────────────── -->
      <div v-else-if="view === 'calendar'" class="at-days">
        <section v-for="d in (zoom === 'week' ? weekDates : [iso(cursor)])" :key="d"
                 :class="['at-day', { today: isToday(d) }]">
          <header class="at-day-head">
            <span class="at-day-name">{{ dayName(d) }}</span>

            <!-- Not "how much slipped" — work slips. What is worth showing is
                 whether anybody was told. -->
            <span v-if="tallyOn(d)?.promised" class="at-day-tally">
              {{ tallyOn(d)!.done }}/{{ tallyOn(d)!.promised }} done
              <span v-if="tallyOn(d)!.unexplained" class="at-chip chip-bad">
                {{ tallyOn(d)!.unexplained }} unexplained
              </span>
            </span>

            <button class="at-day-add" aria-label="Add to this day" @click="openPlan(d)">+</button>
          </header>

          <p v-if="!commitmentsOn(d).length" class="at-day-empty">Nothing committed.</p>

          <ul v-else class="at-commitments">
            <li v-for="c in commitmentsOn(d)" :key="c.id"
                :class="['at-commit', `tone-${commitmentTone(c, d)}`]">
              <span class="at-commit-mark">
                {{ c.outcome === "done" ? "✓" : c.outcome === "not_done" ? "✗" : "○" }}
              </span>

              <span class="at-commit-body">
                <span class="at-commit-title">{{ c.title }}</span>
                <span v-if="c.starts_at" class="at-commit-time">{{ c.starts_at }}–{{ c.ends_at }}</span>
                <!-- The explanation, shown beside the miss rather than buried
                     in a record nobody opens. -->
                <span v-if="c.reason" class="at-commit-reason">“{{ c.reason }}”</span>
                <span v-if="c.carried_to" class="at-commit-reason">
                  moved to {{ c.carried_to.work_date }}
                </span>
              </span>

              <span v-if="!c.outcome" class="at-commit-actions">
                <button class="link-btn" :disabled="saving" @click="markDone(c)">Done</button>
                <button class="link-btn" :disabled="saving" @click="openExplain(c)">Not done</button>
                <button class="link-btn at-commit-x" aria-label="Remove"
                        @click="removeCommitment(c)">×</button>
              </span>
            </li>
          </ul>
        </section>
      </div>

      <!-- ── List ───────────────────────────────────────────────────────── -->
      <div v-else class="at-table-wrap">
        <table class="at-table">
          <thead>
            <tr>
              <th>Date</th><th>Location</th><th>Clock in – Clock out</th>
              <th>Hours</th><th>Overtime</th><th>Status</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in listRows" :key="d.id">
              <td class="at-nowrap">{{ date(d.work_date) }}</td>
              <td>
                {{ locationLabel(d.actual_location ?? d.planned_location) }}
                <span v-if="d.account" class="at-muted">— {{ d.account.name }}</span>
                <!-- Said office, worked from home. Recoverable only because
                     both are kept. -->
                <span v-if="d.plan_was_kept === false" class="at-chip chip-warn">changed</span>
              </td>
              <td class="at-nowrap">{{ time(d.first_clock_in_at) }} → {{ time(d.last_clock_out_at) }}</td>
              <td class="at-nowrap">{{ hoursOf(d.worked_minutes) }}</td>
              <td class="at-nowrap">{{ d.overtime_minutes ? hoursOf(d.overtime_minutes) : "—" }}</td>
              <td>
                <span v-if="d.status" :class="['pill', `pill-${statusTone(d.status)}`]">
                  {{ STATUS_LABEL[d.status] }}
                </span>
                <span v-else class="at-muted">—</span>
              </td>
              <td><button class="link-btn" @click="openDay(d)">View</button></td>
            </tr>
            <tr v-if="!listRows.length"><td colspan="7" class="at-empty">Nothing recorded this month.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── One day ──────────────────────────────────────────────────────── -->
    <div v-if="open" class="at-backdrop" @click.self="open = null">
      <aside class="at-sheet" role="dialog" aria-modal="true" aria-label="Attendance day">
        <div class="at-sheet-head">
          <div>
            <h3 class="at-sheet-title">{{ date(open.work_date) }}</h3>
            <p class="at-sub">
              {{ open.employee?.name }}
              <span v-if="open.status" :class="['pill', `pill-${statusTone(open.status)}`]">
                {{ STATUS_LABEL[open.status] }}
              </span>
            </p>
          </div>
          <button class="at-close" aria-label="Close" @click="open = null">×</button>
        </div>

        <dl class="at-facts">
          <div><dt>Clock in</dt><dd>{{ time(open.first_clock_in_at) }}</dd></div>
          <div><dt>Clock out</dt><dd>{{ time(open.last_clock_out_at) }}</dd></div>
          <div><dt>Hours worked</dt><dd>{{ hoursOf(open.worked_minutes) }}</dd></div>
          <div><dt>Overtime</dt><dd>{{ open.overtime_minutes ? hoursOf(open.overtime_minutes) : "—" }}</dd></div>
          <div><dt>Planned</dt><dd>{{ locationLabel(open.planned_location) }}</dd></div>
          <div><dt>Worked from</dt><dd>{{ locationLabel(open.actual_location) }}</dd></div>
          <div v-if="open.account"><dt>Customer</dt><dd>{{ open.account.name }}</dd></div>
        </dl>

        <section v-if="open.commitments.length">
          <h4 class="at-sheet-sub">Committed</h4>
          <ul class="at-list">
            <li v-for="c in open.commitments" :key="c.id" class="at-punch">
              <span :class="['at-chip', `chip-${commitmentTone(c, open.work_date)}`]">
                {{ c.outcome === "done" ? "done" : c.outcome === "not_done" ? "not done" : "open" }}
              </span>
              <strong>{{ c.title }}</strong>
              <span v-if="c.reason" class="at-muted">“{{ c.reason }}”</span>
            </li>
          </ul>
          <p class="at-hint">
            {{ open.tally.done }} of {{ open.tally.promised }} finished.
            <template v-if="open.tally.unexplained">
              {{ open.tally.unexplained }} went unexplained.
            </template>
          </p>
        </section>

        <section v-if="open.punches.length">
          <h4 class="at-sheet-sub">Clock records</h4>
          <ul class="at-list">
            <li v-for="p in open.punches" :key="p.id" class="at-punch">
              {{ time(p.in) }} → {{ p.out ? time(p.out) : "still running" }}
              <span class="at-muted">{{ p.minutes !== null ? hoursOf(p.minutes) : "" }}</span>
              <!-- Typed rather than clocked. Shown, because a figure somebody
                   remembered and one the clock recorded are different evidence. -->
              <span v-if="p.is_manual" class="at-chip chip-plan">entered by hand</span>
            </li>
          </ul>
        </section>

        <section v-if="open.audits?.length">
          <h4 class="at-sheet-sub">Changes</h4>
          <ul class="at-list">
            <li v-for="(a, i) in open.audits" :key="i" class="at-audit">
              {{ a.field.replace(/_/g, " ") }}: {{ a.from ?? "—" }} → {{ a.to ?? "—" }}
              <span class="at-muted">{{ a.by ?? "someone" }}</span>
            </li>
          </ul>
        </section>
      </aside>
    </div>

    <!-- ── Record where you are working ─────────────────────────────────── -->
    <div v-if="showReport" class="at-modal-backdrop" @click.self="showReport = false">
      <div class="at-modal" role="dialog" aria-modal="true" aria-label="Record attendance">
        <div class="at-sheet-head">
          <h3 class="at-sheet-title">Where are you working?</h3>
          <button class="at-close" aria-label="Close" @click="showReport = false">×</button>
        </div>

        <label class="at-field"><span>Date</span>
          <input v-model="reporting.work_date" class="control" type="date" /></label>

        <label class="at-field"><span>Planned location</span>
          <select v-model="reporting.planned_location" class="control">
            <option :value="null">—</option>
            <option v-for="l in LOCATIONS" :key="l.key" :value="l.key">{{ l.label }}</option>
          </select></label>

        <label class="at-field"><span>Where you actually worked</span>
          <select v-model="reporting.actual_location" class="control">
            <option :value="null">Not yet</option>
            <option v-for="l in LOCATIONS" :key="l.key" :value="l.key">{{ l.label }}</option>
          </select>
          <span class="at-hint">
            Leave this until the day itself. Keeping the plan beside what
            happened is what makes hybrid working reportable at all.
          </span>
        </label>

        <div v-if="reporting.planned_location === 'customer_site' || reporting.actual_location === 'customer_site'"
             class="at-field">
          <span>Which customer</span>
          <AccountPicker v-model="reporting.account_id" placeholder="Which customer's site?" />
          <span class="at-hint">Required — a site visit nobody named cannot be counted.</span>
        </div>

        <label class="at-field"><span>Note</span>
          <input v-model="reporting.location_note" class="control" placeholder="Optional" /></label>

        <button class="btn-primary btn-block" :disabled="saving" @click="saveReport">
          {{ saving ? "Saving…" : "Save" }}
        </button>
      </div>
    </div>

    <!-- ── Add to the day ───────────────────────────────────────────────── -->
    <div v-if="showPlan" class="at-modal-backdrop" @click.self="showPlan = false">
      <div class="at-modal" role="dialog" aria-modal="true" aria-label="Add to the day">
        <div class="at-sheet-head">
          <h3 class="at-sheet-title">Commit to something</h3>
          <button class="at-close" aria-label="Close" @click="showPlan = false">×</button>
        </div>

        <p class="at-hint">
          What will you finish today? Pick work that already exists — committing
          to a day does not create tasks, or the task list fills with a second
          copy of everything anybody ever planned.
        </p>

        <label class="at-field"><span>Project Name</span>
          <select v-model="projectFilter" class="control" @change="loadPickableTasks">
            <option :value="null">Any project</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select></label>

        <label class="at-field"><span>Task Name</span>
          <select v-model="planning.task_id" class="control">
            <option :value="null">— none, just a block —</option>
            <option v-for="t in pickable" :key="t.id" :value="t.id">{{ t.title }}</option>
          </select></label>

        <!-- Break and Lunch are real parts of a day, and forcing a task behind
             each one would fill the task list with rubbish. -->
        <label v-if="!planning.task_id" class="at-field"><span>What is it</span>
          <input v-model="planning.title" class="control" placeholder="Lunch, Break, Team sync…" /></label>

        <label class="at-field"><span>Kind</span>
          <select v-model="planning.kind" class="control">
            <option value="work">Work</option><option value="meeting">Meeting</option>
            <option value="break">Break</option><option value="admin">Admin</option>
            <option value="enablement">Enablement</option>
          </select></label>

        <!-- Optional, and nothing is built on them. A grid you must fill in
             is a timesheet, and timesheets get completed on Friday from
             memory. -->
        <details class="at-times">
          <summary>Add a time (optional)</summary>
          <div class="at-row">
            <label class="at-field"><span>From</span>
              <input v-model="planning.starts_at" class="control" type="time" /></label>
            <label class="at-field"><span>To</span>
              <input v-model="planning.ends_at" class="control" type="time" /></label>
          </div>
        </details>

        <button class="btn-primary btn-block"
                :disabled="saving || (!planning.task_id && !planning.title.trim())"
                @click="savePlan">
          Add to the day
        </button>
      </div>
    </div>

    <!-- ── Why it did not land ──────────────────────────────────────────── -->
    <div v-if="explaining" class="at-modal-backdrop" @click.self="explaining = null">
      <div class="at-modal" role="dialog" aria-modal="true" aria-label="What happened">
        <div class="at-sheet-head">
          <h3 class="at-sheet-title">What happened?</h3>
          <button class="at-close" aria-label="Close" @click="explaining = null">×</button>
        </div>

        <p class="at-hint">
          <strong>{{ explaining.title }}</strong> — work slips, and that is fine.
          What is not fine is nobody knowing. Your manager sees this, and so does
          whoever owns the task.
        </p>

        <label class="at-field"><span>Why it did not land</span>
          <textarea v-model="explanation.reason" class="control" rows="3"
                    placeholder="Waiting on pricing from the OEM."></textarea></label>

        <label class="at-field"><span>Move it to</span>
          <input v-model="explanation.carry_to" class="control" type="date" />
          <span class="at-hint">
            Optional. Moving it carries the reason across, so tomorrow's list
            says why it is back rather than reading as a fresh promise.
          </span>
        </label>

        <button class="btn-primary btn-block"
                :disabled="saving || !explanation.reason.trim()" @click="saveExplanation">
          Record and tell them
        </button>
      </div>
    </div>

    <!-- ── Add hours ────────────────────────────────────────────────────── -->
    <div v-if="showHours" class="at-modal-backdrop" @click.self="showHours = false">
      <div class="at-modal" role="dialog" aria-modal="true" aria-label="Add hours">
        <div class="at-sheet-head">
          <h3 class="at-sheet-title">Add Hours</h3>
          <button class="at-close" aria-label="Close" @click="showHours = false">×</button>
        </div>

        <p class="at-hint">
          For a day you worked without clocking in. Recorded as entered by hand
          rather than dressed up as a clock reading.
        </p>

        <label class="at-field"><span>Date</span>
          <input v-model="hours.work_date" class="control" type="date" /></label>

        <div class="at-row">
          <label class="at-field"><span>From</span>
            <input v-model="hours.from" class="control" type="time" /></label>
          <label class="at-field"><span>To</span>
            <input v-model="hours.to" class="control" type="time" /></label>
        </div>

        <label class="at-field"><span>Note</span>
          <input v-model="hours.note" class="control" placeholder="Why it was not clocked" /></label>

        <button class="btn-primary btn-block" :disabled="saving || !hours.from || !hours.to" @click="saveHours">
          + Add hours
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.at-wrap { padding: var(--space-6); max-width: 90rem; }

.at-header { @apply flex flex-wrap items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-5); }
.at-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.at-sub { @apply flex flex-wrap items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }
.at-actions { @apply flex flex-wrap items-center; gap: var(--space-2); }

.at-cards { @apply grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: var(--space-4); margin-bottom: var(--space-5); }
.at-card { padding: var(--space-4) var(--space-5); border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface-raised); }
.at-card-title { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-3); }
.at-figures { @apply grid; grid-template-columns: repeat(auto-fit, minmax(5rem, 1fr)); gap: var(--space-3); }
.at-figures > div { @apply flex flex-col; gap: 2px; }
.at-fig-label { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.at-fig { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); }

.at-panel { border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface-raised); }
.at-tabs { @apply flex; gap: var(--space-5); padding: 0 var(--space-5); border-bottom: 1px solid var(--color-border); }
.at-tab {
  @apply cursor-pointer border-none bg-transparent; padding: var(--space-3) 0; margin-bottom: -1px;
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent;
}
.at-tab.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }

.at-toolbar { @apply flex flex-wrap items-center justify-between; gap: var(--space-3); padding: var(--space-4) var(--space-5); }
.at-search { max-width: 20rem; }
.at-month { @apply flex items-center; gap: var(--space-2); }
.at-month-label { font-size: var(--text-sm-size); color: var(--color-text); min-width: 6rem; text-align: center; }
.at-nav { @apply cursor-pointer; width: 1.75rem; height: 1.75rem; border-radius: var(--radius-md); border: 1px solid var(--color-border-strong); background: var(--color-surface); color: var(--color-text-muted); font-family: inherit; }

.at-quiet { padding: var(--space-6); color: var(--color-text-muted); }
.at-empty { padding: var(--space-6); text-align: center; color: var(--color-text-muted); }
.at-muted { color: var(--color-text-light); font-size: var(--text-xs-size); }

.at-cal { @apply w-full; border-collapse: collapse; table-layout: fixed; }
.at-cal th { padding: var(--space-3); font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); border-bottom: 1px solid var(--color-border); }
.at-cell {
  @apply cursor-pointer align-top; height: 5rem; padding: var(--space-2);
  border: 1px solid var(--color-border); vertical-align: top;
}
/* Nothing in a grey square is clickable — an accidental click recording
   attendance against the wrong month is a hard mistake to notice. */
.at-cell.outside { @apply cursor-default; background: repeating-linear-gradient(45deg, transparent, transparent 6px, var(--color-surface-sunken) 6px, var(--color-surface-sunken) 7px); }
.at-cell.today { background: var(--color-brand-subtle); }
.at-daynum { display: block; font-size: var(--text-xs-size); color: var(--color-text-muted); margin-bottom: var(--space-2); }

.at-chip { display: inline-block; padding: 1px var(--space-2); border-radius: var(--radius-sm); font-size: var(--text-xs-size); white-space: nowrap; }
.chip-ok   { background: var(--color-success-subtle); color: var(--color-success-text); }
.chip-warn { background: var(--color-warning-subtle); color: var(--color-warning-text); }
.chip-bad  { background: var(--color-danger-subtle);  color: var(--color-danger-text); }
.chip-info { background: var(--color-brand-subtle);   color: var(--color-brand-on-surface); }
.chip-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); }
/* A plan reads differently from a record, so one is never taken for the other. */
.chip-plan { background: transparent; color: var(--color-text-muted); border: 1px dashed var(--color-border-strong); }

.at-zoom { max-width: 10rem; }

.at-days { @apply grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: var(--space-4); padding: 0 var(--space-5) var(--space-5); }
.at-day { border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface); }
.at-day.today { border-color: var(--color-brand-base); }
.at-day-head { @apply flex flex-wrap items-center; gap: var(--space-2); padding: var(--space-3); border-bottom: 1px solid var(--color-border); }
.at-day-name { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); }
.at-day-tally { @apply flex flex-wrap items-center; gap: var(--space-1); font-size: var(--text-xs-size); color: var(--color-text-muted); }
.at-day-add { @apply ml-auto cursor-pointer border-none bg-transparent p-0; font-size: 1.1rem; line-height: 1; color: var(--color-text-muted); }
.at-day-empty { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0; padding: var(--space-3); }

.at-commitments { @apply flex flex-col; list-style: none; margin: 0; padding: 0; }
.at-commit { @apply flex items-start; gap: var(--space-2); padding: var(--space-3); border-bottom: 1px solid var(--color-border); }
.at-commit:last-child { border-bottom: none; }
.at-commit-mark { font-weight: 600; line-height: 1.4; }
.at-commit-body { @apply flex flex-1 flex-col; gap: 2px; min-width: 0; }
.at-commit-title { font-size: var(--text-sm-size); color: var(--color-text); }
.at-commit-time { font-size: var(--text-xs-size); color: var(--color-text-muted); }
/* The explanation sits beside the miss, not buried in a record nobody opens. */
.at-commit-reason { font-size: var(--text-xs-size); color: var(--color-text-muted); font-style: italic; }
.at-commit-actions { @apply flex shrink-0 items-center; gap: var(--space-2); }
.at-commit-x { color: var(--color-text-light); }

.at-commit.tone-ok .at-commit-mark   { color: var(--color-success-text); }
.at-commit.tone-ok .at-commit-title  { text-decoration: line-through; opacity: 0.7; }
.at-commit.tone-warn .at-commit-mark { color: var(--color-warning-text); }
/* Missed with nobody told, or a finished day left unanswered. The only state
   anybody has to act on, so it is the only one that shouts. */
.at-commit.tone-bad { background: color-mix(in oklab, var(--color-danger-subtle) 40%, transparent); }
.at-commit.tone-bad .at-commit-mark { color: var(--color-danger-text); }
.at-commit.tone-neutral .at-commit-mark { color: var(--color-text-light); }

.at-times { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.at-times summary { @apply cursor-pointer; }
.at-times .at-row { margin-top: var(--space-2); }

.at-table-wrap { overflow-x: auto; }
.at-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.at-table th { text-align: left; padding: var(--space-3) var(--space-5); font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); background: var(--color-surface-sunken); }
.at-table td { padding: var(--space-3) var(--space-5); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.at-nowrap { white-space: nowrap; }

.at-backdrop, .at-modal-backdrop { @apply fixed inset-0 z-50; background: rgb(0 0 0 / 0.28); }
.at-backdrop { @apply flex justify-end; }
.at-modal-backdrop { @apply flex items-center justify-center; padding: var(--space-4); }
.at-sheet {
  @apply flex flex-col overflow-y-auto; width: min(28rem, 100%); gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised); box-shadow: var(--shadow-lg);
}
.at-modal {
  @apply flex flex-col overflow-y-auto; width: min(24rem, 100%); max-height: 90vh; gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised);
  border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
}
.at-sheet-head { @apply flex items-start justify-between; gap: var(--space-3); }
.at-sheet-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.at-sheet-sub { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); margin: 0 0 var(--space-2); text-transform: uppercase; letter-spacing: 0.04em; }
.at-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

.at-facts { @apply grid; grid-template-columns: 1fr 1fr; gap: var(--space-3); margin: 0; }
.at-facts dt { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.at-facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 2px 0 0; }

.at-list { @apply flex flex-col; gap: var(--space-2); list-style: none; margin: 0; padding: 0; }
.at-punch, .at-audit { @apply flex flex-wrap items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }

.at-field { @apply flex flex-col; gap: var(--space-1); }
.at-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.at-hint { font-size: var(--text-xs-size); color: var(--color-text-light); }
.at-row { @apply grid; grid-template-columns: 1fr 1fr; gap: var(--space-3); }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.pill { display: inline-block; padding: 2px var(--space-3); border-radius: 999px; font-size: var(--text-xs-size); border: 1px solid transparent; white-space: nowrap; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 30%, transparent); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); border-color: color-mix(in oklab, var(--color-warning-text) 30%, transparent); }
.pill-bad     { background: var(--color-danger-subtle);  color: var(--color-danger-text);  border-color: color-mix(in oklab, var(--color-danger-text) 30%, transparent); }
.pill-info    { background: var(--color-brand-subtle);   color: var(--color-brand-on-surface); border-color: color-mix(in oklab, var(--color-brand-base) 30%, transparent); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); border-color: var(--color-border); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }

.btn-primary {
  @apply inline-flex cursor-pointer items-center justify-center border-none;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
  background: var(--color-brand-base); color: var(--color-on-brand);
}
.btn-primary.danger { background: var(--color-danger-text); }
.btn-block { width: 100%; }
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }

.btn-ghost {
  @apply inline-flex cursor-pointer items-center justify-center;
  padding: 8px var(--space-4); border-radius: var(--radius-md);
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text);
}
</style>
