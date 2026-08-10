<script setup lang="ts">
/**
 * Onboarding — everybody between an accepted offer and a first day.
 *
 * The counts across the top are onboarding states, not the requisition ones the
 * mock inherited: there is no approval chain here and nothing to hold. What
 * somebody reading this page can act on is a submission waiting on them, and a
 * start date that has arrived with the details still unfinished.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";

interface Row {
  id: number; first_name: string; last_name: string;
  email: string; position: string | null;
  employee_type: string | null; status: string;
  expected_start_date: string | null;
  submitted_at: string | null;
  created_at: string | null;
}
interface Summary {
  awaiting_submission: number; in_progress: number;
  submitted: number; overdue: number; completed_this_month: number;
}

const router  = useRouter();
const rows    = ref<Row[]>([]);
const summary = ref<Summary | null>(null);
const loading = ref(true);
const saving  = ref(false);

const tab      = ref("");
const search   = ref("");
const page     = ref(1);
const lastPage = ref(1);

const org = () => currentOrgSlug.value;

function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

async function load() {
  loading.value = true;
  try {
    const res = await api<{ data: Row[]; meta: { last_page: number } }>("GET", "/api/v1/preboarding", {
      org: org(),
      params: {
        status: tab.value || undefined,
        search: search.value || undefined,
        page: page.value, per_page: 10,
      },
    });
    rows.value = res.data;
    lastPage.value = res.meta.last_page ?? 1;
  } catch (e) { fail(e, "Could not load onboarding."); }
  finally { loading.value = false; }
}

async function loadSummary() {
  try {
    summary.value = await api<Summary>("GET", "/api/v1/preboarding/summary", { org: org() });
  } catch (e) { fail(e, "Could not load the counts."); }
}

function refresh() { load(); loadSummary(); }

function setTab(key: string) {
  tab.value = key;
  page.value = 1;
  load();
}

function go(p: number) {
  if (p < 1 || p > lastPage.value) return;
  page.value = p;
  load();
}

// ── Initiating ──────────────────────────────────────────────────────────────

/**
 * What to ask the pre-hire for.
 *
 * Every section is on by default except dependants, which the designs grey out
 * until medical cover applies — asking somebody to list their children when the
 * company is not covering them is a question with no purpose.
 */
const SECTIONS = [
  { key: "self_intro",  label: "Self Induction" },
  { key: "personal",    label: "Personal Details" },
  { key: "contact",     label: "Contact Details" },
  { key: "emergency",   label: "Emergency Contacts" },
  { key: "dependents",  label: "Dependants", needsMedical: true },
  { key: "immigration", label: "Immigration" },
  { key: "qualifications", label: "Qualifications" },
  { key: "memberships", label: "Memberships" },
  { key: "attachments", label: "Forms & Attachments" },
];

const showNew = ref(false);
const blank = () => ({
  first_name: "", last_name: "", email: "", phone: "",
  position: "", employee_type: "permanent",
  expected_start_date: "", link_expiry_days: 7,
  medical_cover_eligible: false,
  enabled_sections: SECTIONS.filter((s) => !s.needsMedical).map((s) => s.key),
});
const form = ref(blank());

function openNew() {
  form.value = blank();
  showNew.value = true;
}

/** Dependants can only be asked for when there is cover to attach them to. */
function onMedicalChanged() {
  const on = form.value.medical_cover_eligible;
  const has = form.value.enabled_sections.includes("dependents");

  if (on && !has) form.value.enabled_sections.push("dependents");
  if (!on && has) {
    form.value.enabled_sections = form.value.enabled_sections.filter((k) => k !== "dependents");
  }
}

function toggleSection(key: string) {
  const list = form.value.enabled_sections;
  const at = list.indexOf(key);
  if (at === -1) list.push(key); else list.splice(at, 1);
}

async function initiate() {
  if (!form.value.first_name.trim() || !form.value.email.trim()) return;
  saving.value = true;
  try {
    await api("POST", "/api/v1/preboarding", {
      org: org(),
      body: {
        ...form.value,
        phone: form.value.phone || null,
        position: form.value.position || null,
        expected_start_date: form.value.expected_start_date || null,
      },
    });
    showNew.value = false;
    notifySaved("Onboarding initiated. The link has been sent.");
    refresh();
  } catch (e) { fail(e, "Could not initiate onboarding."); }
  finally { saving.value = false; }
}

// ── Presentation ────────────────────────────────────────────────────────────

const TABS = [
  { key: "",            label: "All" },
  { key: "pending",     label: "Awaiting submission" },
  { key: "in_progress", label: "In progress" },
  { key: "submitted",   label: "Submitted" },
  { key: "converted",   label: "Completed" },
];

const STATUS_LABEL: Record<string, string> = {
  pending: "Awaiting submission", in_progress: "In progress",
  submitted: "Submitted", completed: "Completed", converted: "Completed",
};

const statusTone = (s: string) =>
  s === "converted" || s === "completed" ? "ok"
    : s === "submitted" ? "info"
      : s === "in_progress" ? "warn" : "neutral";

const date = (v: string | null) =>
  v ? new Date(v + (v.length === 10 ? "T00:00:00" : "")).toLocaleDateString(undefined, {
    day: "2-digit", month: "short", year: "numeric",
  }) : "—";

const initials = (r: Row) =>
  `${r.first_name?.[0] ?? ""}${r.last_name?.[0] ?? ""}`.toUpperCase();

/** Their first day has arrived and they are still not on the system. */
const isOverdue = (r: Row) =>
  !!r.expected_start_date
  && !["converted", "completed"].includes(r.status)
  && new Date(r.expected_start_date + "T00:00:00") <= new Date();

const pages = computed(() => {
  const out: number[] = [];
  const from = Math.max(1, Math.min(page.value - 2, lastPage.value - 4));
  for (let p = from; p <= Math.min(lastPage.value, from + 4); p++) out.push(p);
  return out;
});

onMounted(refresh);
</script>

<template>
  <div class="ob-wrap">
    <header class="ob-header">
      <div>
        <h1 class="ob-title">Onboarding</h1>
        <p class="ob-sub">Manage and complete all onboarding processes organization-wide</p>
      </div>
      <!--
        Two ways in, because they are genuinely different jobs.

        Initiating onboarding sends somebody a form to fill in themselves and
        waits for it to come back. Onboarding directly is HR typing the whole
        record in one sitting — for a transfer, a rehire, or anybody already on
        their first day. The second used to be its own nav entry.
      -->
      <div class="ob-header-actions">
        <router-link :to="{ name: 'onboard' }" class="btn-ghost">Onboard directly</router-link>
        <button class="btn-primary" @click="openNew">+ Initiate Onboarding</button>
      </div>
    </header>

    <!-- ── The counts ───────────────────────────────────────────────────── -->
    <div v-if="summary" class="ob-stats">
      <div class="ob-stat">
        <span class="ob-stat-label">Awaiting submission</span>
        <span class="ob-stat-value">{{ summary.awaiting_submission }}</span>
      </div>
      <div class="ob-stat">
        <span class="ob-stat-label">In progress</span>
        <span class="ob-stat-value">{{ summary.in_progress }}</span>
      </div>
      <!-- Back with HR. The one that is somebody's to act on today. -->
      <button class="ob-stat ob-stat-btn" @click="setTab('submitted')">
        <span class="ob-stat-label">Submitted</span>
        <span :class="['ob-stat-value', { actionable: summary.submitted }]">{{ summary.submitted }}</span>
      </button>
      <div class="ob-stat">
        <span class="ob-stat-label">Overdue</span>
        <span :class="['ob-stat-value', { late: summary.overdue }]">{{ summary.overdue }}</span>
      </div>
      <div class="ob-stat">
        <span class="ob-stat-label">Completed (This month)</span>
        <span class="ob-stat-value">{{ summary.completed_this_month }}</span>
      </div>
    </div>

    <div class="ob-panel">
      <nav class="ob-tabs">
        <button v-for="t in TABS" :key="t.key" :class="['ob-tab', { active: tab === t.key }]"
                @click="setTab(t.key)">{{ t.label }}</button>
      </nav>

      <div class="ob-toolbar">
        <input v-model="search" class="control ob-search" type="search"
               placeholder="Search by name, email, id" @keyup.enter="page = 1; load()" />
      </div>

      <p v-if="loading" class="ob-quiet">Loading…</p>

      <div v-else class="ob-table-wrap">
        <table class="ob-table">
          <thead>
            <tr><th>Name</th><th>Position</th><th>Start date</th><th>Status</th><th>Added</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <tr v-for="r in rows" :key="r.id" :class="{ overdue: isOverdue(r) }">
              <td>
                <span class="ob-person">
                  <span class="ob-avatar">{{ initials(r) }}</span>
                  <span>
                    <span class="ob-name">{{ r.first_name }} {{ r.last_name }}</span>
                    <span class="ob-mail">{{ r.email }}</span>
                  </span>
                </span>
              </td>
              <td>
                <p class="ob-role">{{ r.position ?? "—" }}</p>
                <p class="ob-type">{{ r.employee_type ?? "" }}</p>
              </td>
              <td class="ob-nowrap">
                {{ date(r.expected_start_date) }}
                <!-- The date has arrived and they are still not on the system. -->
                <span v-if="isOverdue(r)" class="ob-late">overdue</span>
              </td>
              <td><span :class="['pill', `pill-${statusTone(r.status)}`]">{{ STATUS_LABEL[r.status] ?? r.status }}</span></td>
              <td class="ob-nowrap">{{ date(r.created_at) }}</td>
              <td>
                <button class="link-btn"
                        @click="router.push({ name: 'preboarding-detail', params: { id: r.id } })">
                  View
                </button>
              </td>
            </tr>
            <tr v-if="!rows.length"><td colspan="6" class="ob-empty">Nobody is being onboarded here yet.</td></tr>
          </tbody>
        </table>

        <nav v-if="lastPage > 1" class="ob-pager">
          <span class="ob-page-of">Page {{ page }} of {{ lastPage }}</span>
          <button class="link-btn" :disabled="page === 1" @click="go(page - 1)">← Previous</button>
          <button v-for="p in pages" :key="p" :class="['ob-page', { active: p === page }]" @click="go(p)">
            {{ p }}
          </button>
          <button class="link-btn" :disabled="page === lastPage" @click="go(page + 1)">Next →</button>
        </nav>
      </div>
    </div>

    <!-- ── Initiate Onboarding ──────────────────────────────────────────── -->
    <div v-if="showNew" class="ob-sheet-backdrop" @click.self="showNew = false">
      <aside class="ob-sheet" role="dialog" aria-modal="true" aria-label="Initiate onboarding">
        <div class="ob-sheet-head">
          <h3 class="ob-sheet-title">Initiate Onboarding</h3>
          <button class="ob-close" aria-label="Close" @click="showNew = false">×</button>
        </div>

        <div class="ob-grid">
          <label class="ob-field"><span>First name *</span>
            <input v-model="form.first_name" class="control" placeholder="Sarah" /></label>
          <label class="ob-field"><span>Last name *</span>
            <input v-model="form.last_name" class="control" placeholder="Kim" /></label>

          <label class="ob-field"><span>Email *</span>
            <input v-model="form.email" class="control" type="email" placeholder="sarah@example.com" /></label>
          <label class="ob-field"><span>Phone</span>
            <input v-model="form.phone" class="control" placeholder="0712 345 678" /></label>

          <label class="ob-field"><span>Position / Role</span>
            <input v-model="form.position" class="control" placeholder="Senior Product Designer" /></label>
          <label class="ob-field"><span>Employee Type</span>
            <select v-model="form.employee_type" class="control">
              <option value="permanent">Permanent</option><option value="contract">Contract</option>
              <option value="intern">Intern</option><option value="probation">Probation</option>
            </select></label>

          <label class="ob-field"><span>Expected Start Date</span>
            <input v-model="form.expected_start_date" class="control" type="date" /></label>
          <label class="ob-field"><span>Link Valid For (days)</span>
            <input v-model.number="form.link_expiry_days" class="control" type="number" min="1" max="30" />
            <span class="ob-hint">The pre-hire must complete within this period.</span>
          </label>
        </div>

        <label class="ob-check">
          <input v-model="form.medical_cover_eligible" type="checkbox" @change="onMedicalChanged" />
          <span>Medical Cover Eligibility</span>
        </label>

        <section>
          <h4 class="ob-sheet-sub">Form Sections to collect</h4>
          <div class="ob-sections">
            <!--
              Dependants stays off until medical cover applies. Asking somebody
              to list their children when the company is not covering them is a
              question with no purpose — and it is personal data collected for
              nothing, which the Data Protection Act takes a view on.
            -->
            <label v-for="s in SECTIONS" :key="s.key"
                   :class="['ob-check', { disabled: s.needsMedical && !form.medical_cover_eligible }]">
              <input type="checkbox"
                     :checked="form.enabled_sections.includes(s.key)"
                     :disabled="s.needsMedical && !form.medical_cover_eligible"
                     @change="toggleSection(s.key)" />
              <span>{{ s.label }}</span>
            </label>
          </div>
          <p class="ob-hint">
            The pre-hire will only see and complete the selected sections.
            Dependants requires medical cover eligibility.
          </p>
        </section>

        <div class="ob-sheet-actions">
          <button class="btn-ghost" @click="showNew = false">Cancel</button>
          <button class="btn-primary"
                  :disabled="saving || !form.first_name.trim() || !form.email.trim()"
                  @click="initiate">
            {{ saving ? "Sending…" : "Initiate Onboarding" }}
          </button>
        </div>
      </aside>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.ob-wrap { padding: var(--space-6); max-width: 90rem; }

.ob-header { @apply flex flex-wrap items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-5); }
.ob-header-actions { @apply flex flex-wrap items-center; gap: var(--space-2); }
.ob-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ob-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

.ob-stats {
  @apply grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
  background: var(--color-surface-raised); border: 1px solid var(--color-border);
  border-radius: var(--radius-lg); margin-bottom: var(--space-5);
}
.ob-stat { @apply flex flex-col; gap: var(--space-1); padding: var(--space-4) var(--space-5); border-right: 1px solid var(--color-border); text-align: left; }
.ob-stat:last-child { border-right: none; }
.ob-stat-btn { @apply cursor-pointer border-none; background: transparent; font-family: inherit; border-right: 1px solid var(--color-border); }
.ob-stat-label { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.ob-stat-value { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); }
.ob-stat-value.actionable { color: var(--color-brand-on-surface); }
.ob-stat-value.late { color: var(--color-danger-text); }

.ob-panel { border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface-raised); }
.ob-tabs { @apply flex flex-wrap; gap: var(--space-5); padding: 0 var(--space-5); border-bottom: 1px solid var(--color-border); }
.ob-tab {
  @apply cursor-pointer border-none bg-transparent; padding: var(--space-3) 0; margin-bottom: -1px;
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent; white-space: nowrap;
}
.ob-tab.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }

.ob-toolbar { @apply flex justify-end; padding: var(--space-4) var(--space-5); }
.ob-search { max-width: 22rem; }
.ob-quiet { padding: var(--space-6); color: var(--color-text-muted); }
.ob-empty { padding: var(--space-6); text-align: center; color: var(--color-text-muted); }

.ob-table-wrap { overflow-x: auto; }
.ob-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.ob-table th {
  text-align: left; padding: var(--space-3) var(--space-5);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  background: var(--color-surface-sunken); white-space: nowrap;
}
.ob-table td { padding: var(--space-3) var(--space-5); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.ob-table tr:last-child td { border-bottom: none; }
/* A start date that has passed reads as late without comparing two dates. */
.ob-table tr.overdue td { background: color-mix(in oklab, var(--color-danger-subtle) 35%, transparent); }
.ob-late { margin-left: var(--space-2); padding: 1px var(--space-2); border-radius: var(--radius-sm); background: var(--color-danger-subtle); color: var(--color-danger-text); font-size: var(--text-xs-size); }
.ob-nowrap { white-space: nowrap; }

.ob-person { @apply inline-flex items-center; gap: var(--space-2); }
.ob-name { display: block; font-weight: 500; }
.ob-mail { display: block; font-size: var(--text-xs-size); color: var(--color-text-muted); }
.ob-role { margin: 0; }
.ob-type { margin: 0; font-size: var(--text-xs-size); color: var(--color-text-muted); text-transform: capitalize; }
.ob-avatar {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 1.75rem; height: 1.75rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: 0.6rem; font-weight: 600;
}

.ob-pager { @apply flex flex-wrap items-center justify-end; gap: var(--space-2); padding: var(--space-4) var(--space-5); }
.ob-page-of { @apply mr-auto; font-size: var(--text-xs-size); color: var(--color-text-muted); }
.ob-page {
  @apply inline-flex cursor-pointer items-center justify-center;
  min-width: 2rem; height: 2rem; border-radius: var(--radius-md);
  border: 1px solid transparent; background: transparent;
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
}
.ob-page.active { border-color: var(--color-brand-base); color: var(--color-brand-on-surface); }

.ob-sheet-backdrop { @apply fixed inset-0 flex justify-end z-50; background: rgb(0 0 0 / 0.32); }
.ob-sheet {
  @apply flex flex-col overflow-y-auto; width: min(34rem, 100%); gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised); box-shadow: var(--shadow-lg);
}
.ob-sheet-head { @apply flex items-center justify-between; }
.ob-sheet-title { font-size: var(--text-lg-size); font-weight: 600; margin: 0; color: var(--color-text); }
.ob-sheet-sub { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); margin: 0 0 var(--space-2); text-transform: uppercase; letter-spacing: 0.04em; }
.ob-sheet-actions { @apply flex justify-between; gap: var(--space-3); margin-top: auto; padding-top: var(--space-4); }
.ob-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

.ob-grid { @apply grid; grid-template-columns: 1fr 1fr; gap: var(--space-3); }
.ob-field { @apply flex flex-col; gap: var(--space-1); }
.ob-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.ob-hint { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0; }

.ob-sections { @apply grid; grid-template-columns: 1fr 1fr; gap: var(--space-2); }
.ob-check { @apply flex cursor-pointer items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }
.ob-check.disabled { @apply cursor-not-allowed opacity-50; }

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
.pill-info    { background: var(--color-brand-subtle);   color: var(--color-brand-on-surface); border-color: color-mix(in oklab, var(--color-brand-base) 30%, transparent); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); border-color: var(--color-border); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }
.link-btn:disabled { @apply cursor-not-allowed no-underline opacity-40; }

.btn-primary {
  @apply inline-flex cursor-pointer items-center justify-center border-none;
  padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
  background: var(--color-brand-base); color: var(--color-on-brand);
}
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }

.btn-ghost {
  /* no-underline because this one is a router-link, which otherwise inherits
     the global anchor styling and reads as a text link beside a button. */
  @apply inline-flex cursor-pointer items-center justify-center no-underline;
  padding: 8px var(--space-4); border-radius: var(--radius-md);
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text);
}
</style>
