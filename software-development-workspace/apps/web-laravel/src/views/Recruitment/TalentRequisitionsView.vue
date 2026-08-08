<script setup lang="ts">
/**
 * Talent Requisition — asking for a role, before advertising one.
 *
 * The list, its five counts and its tabs all read the same query, so a tab
 * showing eleven rows above a card saying ten cannot happen.
 *
 * One thing the designs get right and worth keeping: the Status column becomes
 * a Priority column once a status tab is chosen. On the Open tab every row is
 * Open, and a column of identical pills is a column that has stopped saying
 * anything — priority is what distinguishes them there.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";

interface Named { id: number; name: string }
interface Row {
  id: number; reference: string; job_title: string;
  department: Named | null;
  positions: number; priority: string; status: string;
  target_hire_date: string | null; created_at: string | null;
  requester: { id: number; name: string; email: string | null } | null;
}
interface Summary {
  total: number; open: number; in_approval: number; on_hold: number;
  closed_this_month: number; awaiting_me: number;
}
interface PolicyStep { position: number; label: string; resolver: string }

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
    const res = await api<{ data: Row[]; meta: { last_page: number } }>("GET", "/api/v1/requisitions", {
      org: org(),
      params: {
        status: tab.value || undefined,
        search: search.value || undefined,
        page: page.value, per_page: 10,
      },
    });
    rows.value = res.data;
    lastPage.value = res.meta.last_page ?? 1;
  } catch (e) { fail(e, "Could not load requisitions."); }
  finally { loading.value = false; }
}

async function loadSummary() {
  try {
    summary.value = await api<Summary>("GET", "/api/v1/requisitions/summary", { org: org() });
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

// ── Raising one ─────────────────────────────────────────────────────────────

const showNew = ref(false);
const departments = ref<Named[]>([]);
const chain = ref<PolicyStep[] | null>(null);

const blank = () => ({
  job_title: "", department_id: null as number | null,
  positions: 1, priority: "medium",
  target_hire_date: "", employment_type: "permanent", period: "",
  job_summary: "", key_responsibilities: "", required_qualifications: "",
  skills: [] as string[],
});
const form = ref(blank());
const skillDraft = ref("");

async function loadDepartments() {
  try {
    departments.value = (await api<{ data: Named[] }>("GET", "/api/v1/departments", {
      org: org(), params: { per_page: 200 },
    })).data ?? [];
  } catch { departments.value = []; }
}

/**
 * Who will have to agree, shown before the form is submitted.
 *
 * A requisition that vanishes into an approval chain nobody described is one
 * people chase by email — which is the behaviour the chain exists to replace.
 */
async function loadChain() {
  chain.value = null;
  if (!form.value.department_id) return;
  try {
    const res = await api<{ data: { steps: PolicyStep[] } | null }>("GET", "/api/v1/requisitions/policy", {
      org: org(), params: { department_id: form.value.department_id },
    });
    chain.value = res.data?.steps ?? [];
  } catch { chain.value = null; }
}

function openNew() {
  form.value = blank();
  skillDraft.value = "";
  chain.value = null;
  showNew.value = true;
  loadDepartments();
}

function addSkill() {
  const s = skillDraft.value.trim();
  if (!s || form.value.skills.includes(s)) { skillDraft.value = ""; return; }
  form.value.skills.push(s);
  skillDraft.value = "";
}

async function create() {
  if (!form.value.job_title.trim() || !form.value.department_id) return;
  saving.value = true;
  try {
    const res = await api<{ data: Row }>("POST", "/api/v1/requisitions", {
      org: org(),
      body: {
        ...form.value,
        target_hire_date: form.value.target_hire_date || null,
        period: form.value.period || null,
      },
    });
    showNew.value = false;
    notifySaved(`${res.data.reference} raised. It is now with the approvers.`);
    router.push({ name: "requisition", params: { id: res.data.id } });
  } catch (e) { fail(e, "Could not raise the requisition."); }
  finally { saving.value = false; }
}

// ── Presentation ────────────────────────────────────────────────────────────

const TABS = [
  { key: "",            label: "Total Requisitions" },
  { key: "open",        label: "Open" },
  { key: "in_approval", label: "In approval" },
  { key: "on_hold",     label: "On Hold" },
  { key: "closed",      label: "Closed" },
];

const STATUS_LABEL: Record<string, string> = {
  draft: "Draft", in_approval: "In Approval", approved: "Approved",
  open: "Open", on_hold: "On Hold", declined: "Declined", closed: "Closed",
};

const statusTone = (s: string) =>
  s === "open" || s === "approved" ? "ok"
    : s === "in_approval" ? "info"
      : s === "on_hold" ? "warn"
        : s === "declined" ? "bad" : "neutral";

const priorityTone = (p: string) => (p === "high" ? "high" : p === "medium" ? "medium" : "low");

const date = (v: string | null) =>
  v ? new Date(v + (v.length === 10 ? "T00:00:00" : "")).toLocaleDateString(undefined, {
    day: "2-digit", month: "short", year: "numeric",
  }) : "—";

const initials = (n: string | null | undefined) =>
  (n ?? "?").split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

const pages = computed(() => {
  const out: number[] = [];
  const from = Math.max(1, Math.min(page.value - 2, lastPage.value - 4));
  for (let p = from; p <= Math.min(lastPage.value, from + 4); p++) out.push(p);
  return out;
});

onMounted(refresh);
</script>

<template>
  <div class="tr-wrap">
    <header class="tr-header">
      <div>
        <h1 class="tr-title">Talent Requisition</h1>
        <p class="tr-sub">Create, manage and track all talent requisitions across the organisation</p>
      </div>
      <div class="tr-actions">
        <button class="btn-ghost" @click="router.push({ name: 'requisition-inbox' })">
          Awaiting me
          <span v-if="summary?.awaiting_me" class="tr-badge">{{ summary.awaiting_me }}</span>
        </button>
        <button class="btn-primary" @click="openNew">+ Create Requisition</button>
      </div>
    </header>

    <!-- ── The counts ───────────────────────────────────────────────────── -->
    <div v-if="summary" class="tr-stats">
      <div class="tr-stat"><span class="tr-stat-label">Total Requisitions</span><span class="tr-stat-value">{{ summary.total }}</span></div>
      <div class="tr-stat"><span class="tr-stat-label">Open Requisitions</span><span class="tr-stat-value">{{ summary.open }}</span></div>
      <div class="tr-stat"><span class="tr-stat-label">In Approval</span><span class="tr-stat-value">{{ summary.in_approval }}</span></div>
      <div class="tr-stat"><span class="tr-stat-label">On Hold</span><span class="tr-stat-value">{{ summary.on_hold }}</span></div>
      <div class="tr-stat">
        <span class="tr-stat-label">Closed (This month)</span>
        <span class="tr-stat-value">{{ summary.closed_this_month }}</span>
      </div>
    </div>

    <div class="tr-panel">
      <nav class="tr-tabs">
        <button v-for="t in TABS" :key="t.key" :class="['tr-tab', { active: tab === t.key }]"
                @click="setTab(t.key)">{{ t.label }}</button>
      </nav>

      <div class="tr-toolbar">
        <input v-model="search" class="control tr-search" type="search"
               placeholder="Search by name, email, id" @keyup.enter="page = 1; load()" />
      </div>

      <p v-if="loading" class="tr-quiet">Loading…</p>

      <div v-else class="tr-table-wrap">
        <table class="tr-table">
          <thead>
            <tr>
              <th>Requisition ID</th><th>Job Title</th><th>Requested By</th><th>No. of Pos</th>
              <!-- On a status tab every row shares the status, and a column of
                   identical pills has stopped saying anything. -->
              <th>{{ tab ? "Priority" : "Status" }}</th>
              <th>Date Created</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rows" :key="r.id">
              <td class="tr-ref">{{ r.reference }}</td>
              <td>
                <p class="tr-job">{{ r.job_title }}</p>
                <p class="tr-dept">{{ r.department?.name ?? "—" }}</p>
              </td>
              <td>
                <span v-if="r.requester" class="tr-person">
                  <span class="tr-avatar">{{ initials(r.requester.name) }}</span>
                  <span>
                    <span class="tr-person-name">{{ r.requester.name }}</span>
                    <span class="tr-person-mail">{{ r.requester.email }}</span>
                  </span>
                </span>
                <span v-else class="tr-muted">—</span>
              </td>
              <td>{{ r.positions }}</td>
              <td>
                <span v-if="!tab" :class="['pill', `pill-${statusTone(r.status)}`]">
                  {{ STATUS_LABEL[r.status] ?? r.status }}
                </span>
                <span v-else :class="['tr-flag', priorityTone(r.priority)]">
                  ⚑ {{ r.priority.charAt(0).toUpperCase() + r.priority.slice(1) }} priority
                </span>
              </td>
              <td class="tr-nowrap">{{ date(r.created_at) }}</td>
              <td>
                <button class="link-btn" @click="router.push({ name: 'requisition', params: { id: r.id } })">
                  View
                </button>
              </td>
            </tr>
            <tr v-if="!rows.length"><td colspan="7" class="tr-empty">No requisitions here yet.</td></tr>
          </tbody>
        </table>

        <nav v-if="lastPage > 1" class="tr-pager">
          <span class="tr-page-of">Page {{ page }} of {{ lastPage }}</span>
          <button class="link-btn" :disabled="page === 1" @click="go(page - 1)">← Previous</button>
          <button v-for="p in pages" :key="p" :class="['tr-page', { active: p === page }]" @click="go(p)">
            {{ p }}
          </button>
          <button class="link-btn" :disabled="page === lastPage" @click="go(page + 1)">Next →</button>
        </nav>
      </div>
    </div>

    <!-- ── Create Requisition ───────────────────────────────────────────── -->
    <div v-if="showNew" class="tr-modal-backdrop" @click.self="showNew = false">
      <div class="tr-modal" role="dialog" aria-modal="true" aria-label="Create requisition">
        <div class="tr-modal-head">
          <h3 class="tr-modal-title">Create Requisition</h3>
          <button class="tr-close" aria-label="Close" @click="showNew = false">×</button>
        </div>

        <section>
          <h4 class="tr-section">1. Requisition Information</h4>
          <div class="tr-grid">
            <label class="tr-field"><span>Job Title *</span>
              <input v-model="form.job_title" class="control" placeholder="Job Title" /></label>

            <label class="tr-field"><span>Department *</span>
              <select v-model="form.department_id" class="control" @change="loadChain">
                <option :value="null">Department</option>
                <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
              </select></label>

            <label class="tr-field"><span>Number of Positions *</span>
              <input v-model.number="form.positions" class="control" type="number" min="1" max="999" /></label>

            <label class="tr-field"><span>Priority</span>
              <select v-model="form.priority" class="control">
                <option value="low">Low priority</option>
                <option value="medium">Medium priority</option>
                <option value="high">High priority</option>
              </select></label>

            <label class="tr-field"><span>Target Hire Date *</span>
              <input v-model="form.target_hire_date" class="control" type="date" /></label>

            <label class="tr-field"><span>Employment Type *</span>
              <select v-model="form.employment_type" class="control">
                <option value="permanent">Permanent</option><option value="contract">Contract</option>
                <option value="temporary">Temporary</option><option value="intern">Intern</option>
                <option value="consultant">Consultant</option>
              </select></label>

            <label class="tr-field"><span>Period</span>
              <input v-model="form.period" class="control" placeholder="1 Year" />
              <span class="tr-hint">Only for fixed-term work.</span>
            </label>
          </div>

          <!--
            Who will have to agree, shown before submitting.

            A requisition that vanishes into a chain nobody described is one
            people chase by email — the behaviour the chain replaces.
          -->
          <div v-if="chain" class="tr-chain">
            <template v-if="chain.length">
              <span class="tr-chain-label">This will go to:</span>
              <span v-for="s in chain" :key="s.position" class="tr-chain-step">{{ s.label }}</span>
            </template>
            <span v-else class="tr-chain-none">
              No approval chain is set up for this department — a requisition cannot be raised against it yet.
            </span>
          </div>
        </section>

        <section>
          <h4 class="tr-section">2. Job Information</h4>

          <label class="tr-field"><span>Job Summary *</span>
            <textarea v-model="form.job_summary" class="control" rows="4"
                      placeholder="What the role is for."></textarea></label>

          <div class="tr-grid">
            <label class="tr-field"><span>Key Responsibilities *</span>
              <textarea v-model="form.key_responsibilities" class="control" rows="5"
                        placeholder="One per line."></textarea></label>

            <label class="tr-field"><span>Required Qualification *</span>
              <textarea v-model="form.required_qualifications" class="control" rows="5"
                        placeholder="One per line."></textarea></label>
          </div>

          <label class="tr-field"><span>Skills</span>
            <span class="tr-skill-row">
              <input v-model="skillDraft" class="control" placeholder="Type a skill and press enter"
                     @keyup.enter="addSkill" />
              <button class="btn-ghost btn-sm" @click="addSkill">+ Add Skill</button>
            </span>
            <span v-if="form.skills.length" class="tr-skills">
              <span v-for="s in form.skills" :key="s" class="tr-skill">
                {{ s }}
                <button class="tr-skill-x" aria-label="Remove"
                        @click="form.skills = form.skills.filter((x) => x !== s)">×</button>
              </span>
            </span>
          </label>
        </section>

        <div class="tr-modal-actions">
          <button class="btn-ghost" @click="showNew = false">Cancel</button>
          <button class="btn-primary"
                  :disabled="saving || !form.job_title.trim() || !form.department_id || chain?.length === 0"
                  @click="create">
            {{ saving ? "Submitting…" : "Submit Request" }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.tr-wrap { padding: var(--space-6); max-width: 96rem; }

.tr-header { @apply flex flex-wrap items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-5); }
.tr-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.tr-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }
.tr-actions { @apply flex flex-wrap items-center; gap: var(--space-2); }
.tr-badge {
  @apply inline-flex items-center justify-center; min-width: 1.25rem; height: 1.25rem;
  margin-left: var(--space-2); padding: 0 5px; border-radius: 999px;
  background: var(--color-brand-base); color: var(--color-on-brand);
  font-size: var(--text-xs-size); font-weight: 600;
}

.tr-stats {
  @apply grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
  background: var(--color-surface-raised); border: 1px solid var(--color-border);
  border-radius: var(--radius-lg); margin-bottom: var(--space-5);
}
.tr-stat { @apply flex flex-col; gap: var(--space-1); padding: var(--space-4) var(--space-5); border-right: 1px solid var(--color-border); }
.tr-stat:last-child { border-right: none; }
.tr-stat-label { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tr-stat-value { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); }

.tr-panel { border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface-raised); }
.tr-tabs { @apply flex flex-wrap; gap: var(--space-5); padding: 0 var(--space-5); border-bottom: 1px solid var(--color-border); }
.tr-tab {
  @apply cursor-pointer border-none bg-transparent; padding: var(--space-3) 0; margin-bottom: -1px;
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent; white-space: nowrap;
}
.tr-tab.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }

.tr-toolbar { @apply flex justify-end; padding: var(--space-4) var(--space-5); }
.tr-search { max-width: 22rem; }
.tr-quiet { padding: var(--space-6); color: var(--color-text-muted); }
.tr-empty { padding: var(--space-6); text-align: center; color: var(--color-text-muted); }
.tr-muted { color: var(--color-text-light); }

.tr-table-wrap { overflow-x: auto; }
.tr-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.tr-table th {
  text-align: left; padding: var(--space-3) var(--space-5);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  background: var(--color-surface-sunken); white-space: nowrap;
}
.tr-table td { padding: var(--space-3) var(--space-5); border-bottom: 1px solid var(--color-border); color: var(--color-text); vertical-align: middle; }
.tr-table tr:last-child td { border-bottom: none; }
.tr-ref { font-variant-numeric: tabular-nums; color: var(--color-text-muted); white-space: nowrap; }
.tr-job { font-weight: 500; margin: 0; }
.tr-dept { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; }
.tr-nowrap { white-space: nowrap; }

.tr-person { @apply inline-flex items-center; gap: var(--space-2); }
.tr-person-name { display: block; }
.tr-person-mail { display: block; font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tr-avatar {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 1.75rem; height: 1.75rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: 0.6rem; font-weight: 600;
}

/* Priority reads as a flag, coloured as the designs have it. */
.tr-flag { font-size: var(--text-xs-size); white-space: nowrap; }
.tr-flag.high   { color: var(--color-danger-text); }
.tr-flag.medium { color: var(--color-warning-text); }
.tr-flag.low    { color: var(--color-text-muted); }

.tr-pager { @apply flex flex-wrap items-center justify-end; gap: var(--space-2); padding: var(--space-4) var(--space-5); }
.tr-page-of { @apply mr-auto; font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tr-page {
  @apply inline-flex cursor-pointer items-center justify-center;
  min-width: 2rem; height: 2rem; border-radius: var(--radius-md);
  border: 1px solid transparent; background: transparent;
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
}
.tr-page.active { border-color: var(--color-brand-base); color: var(--color-brand-on-surface); }

.tr-modal-backdrop { @apply fixed inset-0 flex items-center justify-center z-50; background: rgb(0 0 0 / 0.32); padding: var(--space-4); }
.tr-modal {
  @apply flex flex-col overflow-y-auto; width: min(52rem, 100%); max-height: 92vh; gap: var(--space-5);
  padding: var(--space-5); background: var(--color-surface-raised);
  border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
}
.tr-modal-head { @apply flex items-center justify-between; }
.tr-modal-title { font-size: var(--text-lg-size); font-weight: 600; margin: 0; color: var(--color-text); }
.tr-modal-actions { @apply flex justify-between; gap: var(--space-3); }
.tr-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

.tr-section { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-3); }
.tr-grid { @apply grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: var(--space-3); }
.tr-field { @apply flex flex-col; gap: var(--space-1); margin-bottom: var(--space-3); }
.tr-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tr-hint { font-size: var(--text-xs-size); color: var(--color-text-light); }

.tr-chain { @apply flex flex-wrap items-center; gap: var(--space-2); padding: var(--space-3); border-radius: var(--radius-md); background: var(--color-surface-sunken); }
.tr-chain-label { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tr-chain-step { padding: 1px var(--space-3); border-radius: 999px; background: var(--color-brand-subtle); color: var(--color-brand-on-surface); font-size: var(--text-xs-size); }
.tr-chain-none { font-size: var(--text-xs-size); color: var(--color-danger-text); }

.tr-skill-row { @apply flex; gap: var(--space-2); }
.tr-skills { @apply flex flex-wrap; gap: var(--space-2); margin-top: var(--space-2); }
.tr-skill { @apply inline-flex items-center; gap: 4px; padding: 2px var(--space-3); border-radius: 999px; border: 1px solid var(--color-border); font-size: var(--text-xs-size); color: var(--color-text); }
.tr-skill-x { @apply cursor-pointer border-none bg-transparent p-0; color: var(--color-text-muted); }

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
.link-btn:disabled { @apply cursor-not-allowed no-underline opacity-40; }

.btn-primary {
  @apply inline-flex cursor-pointer items-center justify-center border-none;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
  background: var(--color-brand-base); color: var(--color-on-brand);
}
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }

.btn-ghost {
  @apply inline-flex cursor-pointer items-center justify-center;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text);
}
.btn-sm { padding: 5px var(--space-3); font-size: var(--text-xs-size); }
</style>
