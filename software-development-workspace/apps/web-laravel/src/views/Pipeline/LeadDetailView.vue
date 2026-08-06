<script setup lang="ts">
/**
 * One lead.
 *
 * Five tabs, because they are maintained by different people and answer
 * different questions: what the opportunity is (Overview), who we would be
 * selling to (Company), what has happened (Activities), whether it is worth
 * pursuing (Qualifications), and what we have been given (Documents).
 *
 * The qualification here is not the opportunity's score. That one is a
 * weighted checklist about a deal in flight; this one grades fit across five
 * dimensions, because "does the solution match" is not a yes-or-no question
 * and pretending it is throws away what everyone already knows.
 */
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import EmployeePicker from "../../components/EmployeePicker.vue";

const props = defineProps<{ id: string | number }>();

interface Person { id: number | null; name: string | null }
interface TeamMember { id: number; employee: Person; role: string | null }
interface Stakeholder {
  id: number; name: string; role: string | null; email: string | null;
  phone: string | null; influence: string | null;
}
interface Attachment {
  id: number; label: string; document_type: string; original_name: string;
  size_bytes: number | null; uploaded_at: string | null;
}
interface Activity {
  id: number; kind: string; type: string; body: string | null;
  occurred_at: string; owner: Person;
}
interface Dimension { key: string; label: string; value: number | null }
interface Qualification {
  dimensions: Dimension[];
  score: number | null; band: string | null; completeness: number; percentile: number | null;
  summary: {
    budget_availability: string | null; stakeholder_engagement: string | null;
    timeline_estimate: string | null; technical_readiness: string | null;
    decision_authority_access: string | null;
  };
  notes: string | null;
}
interface Contact {
  id: number; category: string; full_name: string; designation: string | null;
  email: string | null; phone: string | null;
}
interface Account {
  id: number; reference: string | null; name: string; initials: string; status: string;
  country: string | null; city: string | null; enrolled_on: string | null;
  existing_products: string | null; budget_range: string | null; organization_size: string | null;
  type?: { id: number; name: string } | null;
  sector?: { id: number; name: string } | null;
  contacts?: Contact[];
}
interface Lead {
  id: number; reference: string | null; name: string; summary: string | null;
  source: string | null; status: string; priority: string; deadline: string | null;
  website: string | null; problem_statement: string | null; proposed_solution: string | null;
  account?: { id: number; name: string; initials: string } | null;
  owner?: Person | null;
  team?: TeamMember[];
  stakeholders?: Stakeholder[];
  attachments?: Attachment[];
  qualification: Qualification;
  opportunity?: { id: number; reference: string | null; name: string } | null;
  created_at: string | null;
}

type Tab = "overview" | "company" | "activities" | "qualifications" | "documents";

const router  = useRouter();
const lead    = ref<Lead | null>(null);
const company = ref<Account | null>(null);
const tab     = ref<Tab>("overview");
const loading = ref(true);
const saving  = ref(false);

const org  = () => currentOrgSlug.value;
const base = computed(() => `/api/v1/leads/${props.id}`);

const tabs: { key: Tab; label: string }[] = [
  { key: "overview",       label: "Overview" },
  { key: "company",        label: "Company" },
  { key: "activities",     label: "Activities" },
  { key: "qualifications", label: "Qualifications" },
  { key: "documents",      label: "Documents" },
];

function fail(e: unknown, fallback: string) {
  // The detail is the API's own message — a validation error is far more use
  // than "could not save", and a toast has room for both.
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

const flash = (message: string) => notifySaved(message);

async function load() {
  loading.value = true;
  try {
    lead.value = (await api<{ data: Lead }>("GET", base.value, { org: org() })).data;
    fillForm(lead.value);
    fillQualification(lead.value.qualification);
  } catch (e) { fail(e, "Could not load this lead."); }
  finally { loading.value = false; }
}

/**
 * The customer, fetched only when the Company tab is opened.
 *
 * Failure is swallowed: somebody who may work a lead but not read the customer
 * master should get an empty tab with a note, not an error page about a record
 * they were never meant to open.
 */
const activities = ref<Activity[]>([]);

async function loadCompany() {
  if (!lead.value?.account) return;
  try {
    company.value = (await api<{ data: Account }>("GET", `/api/v1/accounts/${lead.value.account.id}`,
      { org: org() })).data;
  } catch { company.value = null; }
}

async function loadActivities() {
  try {
    activities.value = (await api<{ data: Activity[] }>("GET", `${base.value}/activities`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load the activity log."); }
}

watch(tab, (t) => {
  if (t === "company" && !company.value) void loadCompany();
  if (t === "activities" && activities.value.length === 0) void loadActivities();
});

// ── Editing the lead ────────────────────────────────────────────────────────

const editing = ref(false);
const form = ref({
  name: "", summary: "", source: "", status: "new", priority: "medium",
  deadline: "", website: "", problem_statement: "", proposed_solution: "",
  owner_id: null as number | null,
});

function fillForm(l: Lead) {
  form.value = {
    name: l.name, summary: l.summary ?? "", source: l.source ?? "",
    status: l.status, priority: l.priority,
    deadline: l.deadline ?? "", website: l.website ?? "",
    problem_statement: l.problem_statement ?? "", proposed_solution: l.proposed_solution ?? "",
    owner_id: l.owner?.id ?? null,
  };
}

async function save() {
  saving.value = true;
  try {
    lead.value = (await api<{ data: Lead }>("PUT", base.value, {
      org: org(),
      body: {
        ...form.value,
        summary: form.value.summary || null,
        source: form.value.source || null,
        deadline: form.value.deadline || null,
        website: form.value.website || null,
        problem_statement: form.value.problem_statement || null,
        proposed_solution: form.value.proposed_solution || null,
        account_id: lead.value?.account?.id ?? null,
      },
    })).data;
    editing.value = false;
    flash("Saved.");
  } catch (e) { fail(e, "Could not save."); }
  finally { saving.value = false; }
}

// ── Qualification ───────────────────────────────────────────────────────────

const qualifying = ref(false);
const quality = ref({
  fit_company: null as number | null, fit_budget: null as number | null,
  fit_solution: null as number | null, fit_timeline: null as number | null,
  fit_stakeholder: null as number | null,
  budget_availability: "", stakeholder_engagement: "", timeline_estimate: "",
  technical_readiness: "", decision_authority_access: "", qualification_notes: "",
});

function fillQualification(q: Qualification) {
  const byKey = Object.fromEntries(q.dimensions.map((d) => [d.key, d.value]));
  quality.value = {
    fit_company: byKey.fit_company ?? null,
    fit_budget: byKey.fit_budget ?? null,
    fit_solution: byKey.fit_solution ?? null,
    fit_timeline: byKey.fit_timeline ?? null,
    fit_stakeholder: byKey.fit_stakeholder ?? null,
    budget_availability: q.summary.budget_availability ?? "",
    stakeholder_engagement: q.summary.stakeholder_engagement ?? "",
    timeline_estimate: q.summary.timeline_estimate ?? "",
    technical_readiness: q.summary.technical_readiness ?? "",
    decision_authority_access: q.summary.decision_authority_access ?? "",
    qualification_notes: q.notes ?? "",
  };
}

async function saveQualification() {
  saving.value = true;
  try {
    lead.value = (await api<{ data: Lead }>("POST", `${base.value}/qualify`, {
      org: org(),
      body: {
        ...quality.value,
        budget_availability: quality.value.budget_availability || null,
        stakeholder_engagement: quality.value.stakeholder_engagement || null,
        timeline_estimate: quality.value.timeline_estimate || null,
        technical_readiness: quality.value.technical_readiness || null,
        decision_authority_access: quality.value.decision_authority_access || null,
        qualification_notes: quality.value.qualification_notes || null,
      },
    })).data;
    fillQualification(lead.value.qualification);
    qualifying.value = false;
    flash("Assessment saved.");
  } catch (e) { fail(e, "Could not save the assessment."); }
  finally { saving.value = false; }
}

// ── Stakeholders ────────────────────────────────────────────────────────────

const showStakeholder = ref(false);
const newStakeholder  = ref({ name: "", role: "", email: "", influence: "decision_maker" });

async function addStakeholder() {
  if (!newStakeholder.value.name.trim()) return;
  saving.value = true;
  try {
    lead.value = (await api<{ data: Lead }>("POST", `${base.value}/stakeholders`, {
      org: org(),
      body: {
        ...newStakeholder.value,
        role: newStakeholder.value.role || null,
        email: newStakeholder.value.email || null,
      },
    })).data;
    newStakeholder.value = { name: "", role: "", email: "", influence: "decision_maker" };
    showStakeholder.value = false;
  } catch (e) { fail(e, "Could not add them."); }
  finally { saving.value = false; }
}

async function removeStakeholder(s: Stakeholder) {
  try {
    lead.value = (await api<{ data: Lead }>("DELETE", `${base.value}/stakeholders/${s.id}`, { org: org() })).data;
  } catch (e) { fail(e, "Could not remove them."); }
}

// ── Activities ──────────────────────────────────────────────────────────────

const newActivity = ref({ type: "meeting", body: "" });

async function logActivity() {
  if (!newActivity.value.body.trim()) return;
  saving.value = true;
  try {
    await api("POST", `${base.value}/activities`, { org: org(), body: { ...newActivity.value } });
    newActivity.value = { type: "meeting", body: "" };
    await loadActivities();
  } catch (e) { fail(e, "Could not log that."); }
  finally { saving.value = false; }
}

// ── Documents ───────────────────────────────────────────────────────────────

const showDoc   = ref(false);
const uploading = ref(false);
const docForm   = ref({ label: "", document_type: "client" });
const docFile   = ref<File | null>(null);

function pickFile(event: Event) {
  docFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

async function uploadDoc() {
  if (!docFile.value) return;
  uploading.value = true;
  try {
    const body = new FormData();
    body.append("file", docFile.value);
    body.append("document_type", docForm.value.document_type);
    if (docForm.value.label) body.append("label", docForm.value.label);

    lead.value = (await api<{ data: Lead }>("POST", `${base.value}/attachments`, { org: org(), body })).data;
    showDoc.value = false;
    docForm.value = { label: "", document_type: "client" };
    docFile.value = null;
    flash("Document added.");
  } catch (e) { fail(e, "Could not add the document."); }
  finally { uploading.value = false; }
}

async function removeDoc(a: Attachment) {
  try {
    lead.value = (await api<{ data: Lead }>("DELETE", `${base.value}/attachments/${a.id}`, { org: org() })).data;
  } catch (e) { fail(e, "Could not remove the document."); }
}

function downloadUrl(a: Attachment): string {
  const root = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");
  return `${root}${base.value}/attachments/${a.id}/download?org=${org()}`;
}

// ── Team ────────────────────────────────────────────────────────────────────

const showTeam  = ref(false);
const newMember = ref({ employee_id: null as number | null, role: "" });

async function addMember() {
  if (!newMember.value.employee_id) return;
  saving.value = true;
  try {
    lead.value = (await api<{ data: Lead }>("POST", `${base.value}/team`, {
      org: org(), body: { ...newMember.value, role: newMember.value.role || null },
    })).data;
    newMember.value = { employee_id: null, role: "" };
  } catch (e) { fail(e, "Could not add them."); }
  finally { saving.value = false; }
}

async function removeMember(m: TeamMember) {
  try {
    lead.value = (await api<{ data: Lead }>("DELETE", `${base.value}/team/${m.id}`, { org: org() })).data;
  } catch (e) { fail(e, "Could not remove them."); }
}

// ── Presentation ────────────────────────────────────────────────────────────

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

const titleCase = (v: string | null) =>
  v ? v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase()) : "—";

const initials = (name: string | null | undefined) =>
  (name ?? "?").split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

const kb = (b: number | null) => (b === null ? "" : `${Math.round(b / 1024)} KB`);

function ago(iso: string | null): string {
  if (!iso) return "";
  const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  const steps: [number, Intl.RelativeTimeFormatUnit][] =
    [[60, "second"], [3600, "minute"], [86400, "hour"], [604800, "day"], [2629800, "week"], [31557600, "month"]];
  const fmt = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });
  let prev = 1;
  for (const [limit, unit] of steps) {
    if (secs < limit) return fmt.format(-Math.floor(secs / prev), unit);
    prev = limit;
  }
  return fmt.format(-Math.floor(secs / 31557600), "year");
}

const BAND_LABELS: Record<string, string> = {
  high_fit: "High Fit", moderate_fit: "Moderate Fit", low_fit: "Low Fit", poor_fit: "Poor Fit",
};

/** The gauge arc: a three-quarter sweep, filled to the score. */
const GAUGE = { radius: 70, circumference: 2 * Math.PI * 70 * 0.75 };

const gaugeOffset = computed(() => {
  const score = lead.value?.qualification.score ?? 0;
  return GAUGE.circumference * (1 - score / 100);
});

onMounted(load);
</script>

<template>
  <div class="ld-wrap">
    <nav class="ld-crumbs">
      <button class="ld-crumb" @click="router.push({ name: 'leads' })">Leads</button>
      <span class="ld-sep">›</span>
      <span class="ld-current">{{ lead?.reference ?? "Lead" }}</span>
    </nav>

    <div v-if="loading" class="ld-loading">Loading…</div>

    <template v-else-if="lead">
      <header class="ld-head">
        <div>
          <h1 class="ld-title">{{ lead.name }}</h1>
          <p class="ld-byline">
            <span class="ld-avatar sm">{{ initials(lead.owner?.name) }}</span>
            <span v-if="lead.owner?.name"><strong>{{ lead.owner.name }}</strong> created this lead</span>
            <span v-else>Created</span>
            <span class="ld-dot">•</span>{{ ago(lead.created_at) }}
          </p>
        </div>
        <button class="btn-ghost" @click="editing = !editing">{{ editing ? "Cancel" : "Edit" }}</button>
      </header>

      <div v-if="lead.opportunity" class="ld-banner">
        This lead became
        <button class="link-btn" @click="router.push({ name: 'opportunity', params: { id: lead.opportunity.id } })">
          {{ lead.opportunity.reference }} — {{ lead.opportunity.name }}
        </button>
      </div>

      <div class="ld-facts-row">
        <dl class="ld-facts">
          <div><dt>Deadline</dt><dd>📅 {{ date(lead.deadline) }}</dd></div>
          <div><dt>Status</dt><dd class="ld-status">{{ titleCase(lead.status) }}</dd></div>
          <div><dt>Priority</dt>
            <dd><span :class="['ld-priority', `prio-${lead.priority}`]">⚑ {{ titleCase(lead.priority) }} priority</span></dd></div>
          <div><dt>Lead Team</dt>
            <dd>
              <button class="ld-stack-btn" @click="showTeam = true">
                <span v-if="!lead.team?.length" class="ld-muted">Add</span>
                <span v-else class="ld-stack">
                  <span v-for="m in lead.team.slice(0, 4)" :key="m.id" class="ld-avatar"
                        :title="m.employee.name ?? ''">{{ initials(m.employee.name) }}</span>
                  <span v-if="lead.team.length > 4" class="ld-avatar more">+{{ lead.team.length - 4 }}</span>
                </span>
              </button>
            </dd></div>
        </dl>

        <dl class="ld-facts">
          <div><dt>Company</dt>
            <dd>
              <button v-if="lead.account" class="link-btn ld-company"
                      @click="router.push({ name: 'account', params: { id: lead.account.id } })">
                <span class="ld-avatar brand">{{ lead.account.initials }}</span>{{ lead.account.name }}
              </button>
              <span v-else class="ld-muted">Not yet on file</span>
            </dd></div>
          <div><dt>Website</dt>
            <dd><a v-if="lead.website" :href="lead.website" class="link-btn" target="_blank" rel="noopener">{{ lead.website }}</a>
              <span v-else>—</span></dd></div>
          <div><dt>Lead Owner</dt>
            <dd><span v-if="lead.owner?.name" class="ld-person">
              <span class="ld-avatar">{{ initials(lead.owner.name) }}</span>{{ lead.owner.name }}</span>
              <span v-else>—</span></dd></div>
          <div><dt>Lead Source</dt><dd>{{ lead.source ?? "-" }}</dd></div>
        </dl>
      </div>

      <nav class="ld-tabs" role="tablist">
        <button v-for="t in tabs" :key="t.key" role="tab" :aria-selected="tab === t.key"
                :class="['ld-tab', { active: tab === t.key }]" @click="tab = t.key">{{ t.label }}</button>
      </nav>

      <!-- ── Overview ────────────────────────────────────────────────── -->
      <section v-if="tab === 'overview'" class="ld-panel">
        <template v-if="!editing">
          <div class="ld-block">
            <h3 class="ld-block-title">Problem Statement</h3>
            <p v-if="lead.problem_statement" class="ld-prose">{{ lead.problem_statement }}</p>
            <p v-else class="ld-muted">Not written up yet.</p>
          </div>

          <div class="ld-block">
            <h3 class="ld-block-title">Proposed Solution Scope</h3>
            <p v-if="lead.proposed_solution" class="ld-prose">{{ lead.proposed_solution }}</p>
            <p v-else class="ld-muted">Not written up yet.</p>
          </div>
        </template>

        <div v-else class="ld-card">
          <div class="ld-grid">
            <label class="ld-field ld-span"><span>Lead name</span>
              <input v-model="form.name" class="control" /></label>

            <label class="ld-field ld-span"><span>One-line summary</span>
              <input v-model="form.summary" class="control" placeholder="Shown on the board card" /></label>

            <label class="ld-field"><span>Status</span>
              <select v-model="form.status" class="control">
                <option value="new">New</option>
                <option value="contacted">Contacted</option>
                <option value="qualified">Qualified</option>
                <option value="unqualified">Unqualified</option>
              </select></label>

            <label class="ld-field"><span>Priority</span>
              <select v-model="form.priority" class="control">
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
              </select></label>

            <label class="ld-field"><span>Source</span>
              <input v-model="form.source" class="control" placeholder="Website, referral, LinkedIn" /></label>

            <label class="ld-field"><span>Deadline</span>
              <input v-model="form.deadline" class="control" type="date" /></label>

            <label class="ld-field ld-span"><span>Website</span>
              <input v-model="form.website" class="control" placeholder="https://www.kcbgroup.com" /></label>

            <div class="ld-field ld-span"><span>Lead owner</span>
              <EmployeePicker v-model="form.owner_id" placeholder="Who is working this?" /></div>

            <label class="ld-field ld-span"><span>Problem statement</span>
              <textarea v-model="form.problem_statement" class="control" rows="10"></textarea></label>

            <label class="ld-field ld-span"><span>Proposed solution scope</span>
              <textarea v-model="form.proposed_solution" class="control" rows="8"></textarea></label>
          </div>

          <div class="ld-actions">
            <button class="btn-primary" :disabled="saving" @click="save">
              {{ saving ? "Saving…" : "Save changes" }}
            </button>
          </div>
        </div>
      </section>

      <!-- ── Company ─────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'company'" class="ld-panel">
        <div v-if="!lead.account" class="ld-empty">
          <h3 class="ld-empty-title">No customer on file</h3>
          <p class="ld-empty-sub">
            This lead is not attached to a customer yet. One is needed before it can
            become a deal — revenue has to be against somebody.
          </p>
        </div>

        <template v-else-if="company">
          <div class="ld-card">
            <div class="ld-company-head">
              <span class="ld-avatar xl brand">{{ company.initials }}</span>
              <div>
                <h2 class="ld-company-name">{{ company.name }}</h2>
                <span :class="['pill', company.status === 'active' ? 'pill-ok' : 'pill-warn']">
                  {{ titleCase(company.status) }}
                </span>
              </div>
              <button class="btn-ghost ld-company-open"
                      @click="router.push({ name: 'account', params: { id: company.id } })">
                Open customer
              </button>
            </div>

            <dl class="ld-company-facts">
              <div><dt>Client ID</dt><dd>{{ company.reference ?? "—" }}</dd></div>
              <div><dt>Industry</dt><dd>{{ company.sector?.name ?? "—" }}</dd></div>
              <div><dt>Customer Type</dt><dd>{{ company.type?.name ?? "—" }}</dd></div>
              <!-- A band read off the headcount, so it cannot disagree with it. -->
              <div><dt>Organization Size</dt><dd>{{ company.organization_size ?? "—" }}</dd></div>
              <div><dt>Country</dt><dd>{{ company.country ?? "—" }}</dd></div>
              <div><dt>City</dt><dd>{{ company.city ?? "—" }}</dd></div>
              <div><dt>Existing Product</dt><dd>{{ company.existing_products ?? "—" }}</dd></div>
              <div><dt>Status</dt><dd><span class="pill pill-ok">{{ titleCase(company.status) }}</span></dd></div>
              <div><dt>Budget Range</dt><dd class="ld-budget">{{ company.budget_range ?? "—" }}</dd></div>
              <div><dt>Enrolled on</dt><dd>📅 {{ date(company.enrolled_on) }}</dd></div>
            </dl>
          </div>

          <div v-if="company.contacts?.length" class="ld-contact-grid">
            <div v-for="c in company.contacts" :key="c.id" class="ld-card">
              <h3 class="ld-block-label">{{ titleCase(c.category) }} Contact</h3>
              <div class="ld-person lg">
                <span class="ld-avatar">{{ initials(c.full_name) }}</span>
                <div>
                  <p class="ld-strong">{{ c.full_name }}</p>
                  <p class="ld-link">{{ c.email ?? "—" }}</p>
                </div>
              </div>
              <dl class="ld-mini">
                <div><dt>Phone Number</dt><dd>{{ c.phone ?? "—" }}</dd></div>
                <div><dt>Role</dt><dd>{{ c.designation ?? "—" }}</dd></div>
              </dl>
            </div>
          </div>
          <p v-else class="ld-muted">No contacts recorded on the customer.</p>
        </template>

        <p v-else class="ld-muted">
          The customer record is not readable with your permissions.
        </p>
      </section>

      <!-- ── Activities ──────────────────────────────────────────────── -->
      <section v-else-if="tab === 'activities'" class="ld-panel">
        <div class="ld-card">
          <div class="ld-log">
            <select v-model="newActivity.type" class="control ld-log-type">
              <option value="meeting">Meeting</option>
              <option value="call">Call</option>
              <option value="email">Email</option>
              <option value="demo">Demo</option>
              <option value="proposal">Proposal</option>
              <option value="site_visit">Site visit</option>
              <option value="note">Note</option>
            </select>
            <input v-model="newActivity.body" class="control" placeholder="What happened?"
                   @keyup.enter="logActivity" />
            <button class="btn-primary" :disabled="saving || !newActivity.body.trim()" @click="logActivity">Log</button>
          </div>
        </div>

        <p v-if="activities.length === 0" class="ld-muted">Nothing recorded on this lead yet.</p>

        <div v-else class="ld-card ld-table-card">
          <table class="ld-table">
            <thead><tr><th>Date</th><th>Activity</th><th>Owner</th></tr></thead>
            <tbody>
              <tr v-for="a in activities" :key="a.id">
                <td class="ld-nowrap">{{ date(a.occurred_at) }}</td>
                <td>{{ a.body }}</td>
                <td>
                  <span class="ld-person">
                    <span class="ld-avatar">{{ initials(a.owner.name) }}</span>{{ a.owner.name ?? "—" }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── Qualifications ──────────────────────────────────────────── -->
      <section v-else-if="tab === 'qualifications'" class="ld-panel">
        <div class="ld-panel-actions">
          <button class="btn-ghost" @click="qualifying = !qualifying">
            {{ qualifying ? "Cancel" : lead.qualification.score === null ? "Assess this lead" : "Update assessment" }}
          </button>
        </div>

        <template v-if="!qualifying">
          <div v-if="lead.qualification.score === null" class="ld-empty">
            <h3 class="ld-empty-title">Not assessed yet</h3>
            <p class="ld-empty-sub">
              Grade the five dimensions to see how this lead compares with the rest
              of the book. Nothing is assumed — an unassessed lead has no score,
              rather than a score of zero.
            </p>
            <button class="btn-primary" @click="qualifying = true">Assess this lead</button>
          </div>

          <div v-else class="ld-qual-grid">
            <!-- Score -->
            <div class="ld-card">
              <h3 class="ld-block-label">Qualification Score</h3>
              <div class="ld-gauge-row">
                <svg class="ld-gauge" viewBox="0 0 180 180" role="img"
                     :aria-label="`${lead.qualification.score} out of 100`">
                  <circle class="ld-gauge-track" cx="90" cy="90" :r="GAUGE.radius"
                          :stroke-dasharray="`${GAUGE.circumference} ${GAUGE.circumference * 2}`" />
                  <circle :class="['ld-gauge-fill', `band-${lead.qualification.band}`]" cx="90" cy="90" :r="GAUGE.radius"
                          :stroke-dasharray="`${GAUGE.circumference} ${GAUGE.circumference * 2}`"
                          :stroke-dashoffset="gaugeOffset" />
                  <text x="90" y="84" class="ld-gauge-label">
                    {{ BAND_LABELS[lead.qualification.band ?? ""] ?? "" }}
                  </text>
                  <text x="90" y="118" class="ld-gauge-value">{{ lead.qualification.score }}%</text>
                </svg>

                <div class="ld-gauge-note">
                  <p class="ld-prose">
                    Fit across company, budget, solution, timeline and stakeholder access.
                  </p>
                  <!-- Said out loud: 90 from one dimension and 90 from five are
                       not the same claim. -->
                  <p class="ld-hint">
                    Based on {{ lead.qualification.completeness }}% of the assessment.
                  </p>
                  <span v-if="lead.qualification.percentile !== null" class="pill pill-ok">
                    Top {{ Math.max(lead.qualification.percentile, 1) }}% of all leads
                  </span>
                  <span v-else class="ld-hint">
                    Too few assessed leads to rank this one yet.
                  </span>
                </div>
              </div>
            </div>

            <!-- Summary -->
            <div class="ld-card">
              <h3 class="ld-block-label">Qualification Summary</h3>
              <dl class="ld-mini">
                <div><dt>Budget Availability</dt>
                  <dd><span :class="['pill', lead.qualification.summary.budget_availability === 'available' ? 'pill-ok' : 'pill-warn']">
                    {{ titleCase(lead.qualification.summary.budget_availability) }}</span></dd></div>
                <div><dt>Stakeholder Engagement</dt>
                  <dd><span :class="['pill', lead.qualification.summary.stakeholder_engagement === 'high' ? 'pill-ok' : 'pill-warn']">
                    {{ titleCase(lead.qualification.summary.stakeholder_engagement) }}</span></dd></div>
                <div><dt>Timeline</dt><dd>{{ lead.qualification.summary.timeline_estimate ?? "—" }}</dd></div>
                <div><dt>Technical Readiness</dt>
                  <dd><span class="pill pill-info">{{ titleCase(lead.qualification.summary.technical_readiness) }}</span></dd></div>
                <div><dt>Decision Authority Access</dt>
                  <dd><span :class="['pill', lead.qualification.summary.decision_authority_access === 'available' ? 'pill-ok' : 'pill-warn']">
                    {{ titleCase(lead.qualification.summary.decision_authority_access) }}</span></dd></div>
              </dl>
            </div>

            <!-- Breakdown -->
            <div class="ld-card">
              <h3 class="ld-block-label">Qualification Breakdown</h3>
              <ul class="ld-bars">
                <li v-for="d in lead.qualification.dimensions" :key="d.key">
                  <span class="ld-bar-label">{{ d.label }}</span>
                  <span class="ld-bar-track">
                    <span class="ld-bar-fill" :style="{ width: `${d.value ?? 0}%` }"></span>
                  </span>
                  <span class="ld-bar-value">{{ d.value === null ? "—" : `${d.value}%` }}</span>
                </li>
              </ul>
              <p v-if="lead.qualification.notes" class="ld-prose">{{ lead.qualification.notes }}</p>
            </div>

            <!-- Decision makers -->
            <div class="ld-card">
              <div class="ld-card-head">
                <h3 class="ld-block-label">Key Decision Makers</h3>
              </div>

              <p v-if="!lead.stakeholders?.length" class="ld-muted">Nobody named yet.</p>

              <table v-else class="ld-table flush">
                <thead><tr><th>Name</th><th>Role</th><th>Actions</th></tr></thead>
                <tbody>
                  <tr v-for="s in lead.stakeholders" :key="s.id">
                    <td>
                      <span class="ld-person">
                        <span class="ld-avatar">{{ initials(s.name) }}</span>{{ s.name }}
                      </span>
                    </td>
                    <td>{{ s.role ?? "—" }}</td>
                    <td>
                      <button class="icon-btn icon-danger" aria-label="Remove"
                              @click="removeStakeholder(s)">🗑</button>
                    </td>
                  </tr>
                </tbody>
              </table>

              <button class="btn-primary ld-add-stake" @click="showStakeholder = true">+ Add Stakeholder</button>
            </div>
          </div>
        </template>

        <!-- Assessment form -->
        <div v-else class="ld-card">
          <p class="ld-hint">
            Grade each dimension from 0 to 100, or leave one blank if nobody has
            looked at it — a blank is skipped rather than counted as zero.
          </p>

          <div class="ld-grid">
            <label class="ld-field"><span>Company Fit</span>
              <input v-model.number="quality.fit_company" class="control" type="number" min="0" max="100" /></label>
            <label class="ld-field"><span>Budget Alignment</span>
              <input v-model.number="quality.fit_budget" class="control" type="number" min="0" max="100" /></label>
            <label class="ld-field"><span>Solution Match</span>
              <input v-model.number="quality.fit_solution" class="control" type="number" min="0" max="100" /></label>
            <label class="ld-field"><span>Timeline Fit</span>
              <input v-model.number="quality.fit_timeline" class="control" type="number" min="0" max="100" /></label>
            <label class="ld-field"><span>Stakeholder Access</span>
              <input v-model.number="quality.fit_stakeholder" class="control" type="number" min="0" max="100" /></label>
          </div>

          <h4 class="ld-block-label">Summary</h4>
          <div class="ld-grid">
            <label class="ld-field"><span>Budget availability</span>
              <select v-model="quality.budget_availability" class="control">
                <option value="">—</option><option value="available">Available</option>
                <option value="limited">Limited</option><option value="none">None</option>
              </select></label>
            <label class="ld-field"><span>Stakeholder engagement</span>
              <select v-model="quality.stakeholder_engagement" class="control">
                <option value="">—</option><option value="high">High</option>
                <option value="medium">Medium</option><option value="low">Low</option>
              </select></label>
            <label class="ld-field"><span>Timeline</span>
              <input v-model="quality.timeline_estimate" class="control" placeholder="3–4 Months" /></label>
            <label class="ld-field"><span>Technical readiness</span>
              <select v-model="quality.technical_readiness" class="control">
                <option value="">—</option><option value="high">High</option>
                <option value="medium">Medium</option><option value="low">Low</option>
              </select></label>
            <label class="ld-field"><span>Decision authority access</span>
              <select v-model="quality.decision_authority_access" class="control">
                <option value="">—</option><option value="available">Available</option>
                <option value="limited">Limited</option><option value="none">None</option>
              </select></label>
            <label class="ld-field ld-span"><span>Notes</span>
              <textarea v-model="quality.qualification_notes" class="control" rows="3"></textarea></label>
          </div>

          <div class="ld-actions">
            <button class="btn-primary" :disabled="saving" @click="saveQualification">
              {{ saving ? "Saving…" : "Save assessment" }}
            </button>
          </div>
        </div>
      </section>

      <!-- ── Documents ───────────────────────────────────────────────── -->
      <section v-else class="ld-panel">
        <div class="ld-panel-actions">
          <button class="btn-primary" @click="showDoc = true">+ Add</button>
        </div>

        <p v-if="!lead.attachments?.length" class="ld-muted">Nothing filed against this lead.</p>

        <div v-else class="ld-card ld-table-card">
          <table class="ld-table">
            <thead><tr><th>Date</th><th>Document</th><th>Type</th><th>Action</th></tr></thead>
            <tbody>
              <tr v-for="a in lead.attachments" :key="a.id">
                <td class="ld-nowrap">{{ date(a.uploaded_at) }}</td>
                <td>
                  <span class="ld-file">
                    <span class="ld-file-ext">{{ (a.original_name.split(".").pop() ?? "").toUpperCase() }}</span>
                    <span>
                      <span class="ld-strong">{{ a.label }}</span>
                      <span class="ld-file-size">{{ kb(a.size_bytes) }}</span>
                    </span>
                  </span>
                </td>
                <td>{{ titleCase(a.document_type) }} Document</td>
                <td class="ld-row-actions">
                  <a :href="downloadUrl(a)" class="link-btn">View</a>
                  <button class="link-btn link-danger" @click="removeDoc(a)">Remove</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <!-- Add document -->
    <div v-if="showDoc" class="ld-modal-backdrop" @click.self="showDoc = false">
      <div class="ld-modal">
        <div class="ld-modal-head">
          <h3 class="ld-modal-title">Add Document</h3>
          <button class="ld-close" aria-label="Close" @click="showDoc = false">×</button>
        </div>

        <label class="ld-field"><span>Document Name</span>
          <input v-model="docForm.label" class="control" placeholder="Document Name" /></label>

        <label class="ld-field"><span>Type</span>
          <select v-model="docForm.document_type" class="control">
            <option value="client">Client Document</option>
            <option value="internal">Internal Document</option>
            <option value="financial">Financial Document</option>
            <option value="technical">Technical Document</option>
          </select></label>

        <label class="ld-drop">
          <span class="ld-drop-icon">⤒</span>
          <span><strong class="ld-drop-link">Click to upload</strong> or drag and drop</span>
          <span class="ld-drop-hint">PDF, DOCX, XLSX, PPTX or images (max. 20 MB)</span>
          <span v-if="docFile" class="ld-drop-file">{{ docFile.name }}</span>
          <input type="file" hidden @change="pickFile" />
        </label>

        <button class="btn-primary btn-block" :disabled="uploading || !docFile" @click="uploadDoc">
          {{ uploading ? "Uploading…" : "Add Document" }}
        </button>
      </div>
    </div>

    <!-- Add stakeholder -->
    <div v-if="showStakeholder" class="ld-modal-backdrop" @click.self="showStakeholder = false">
      <div class="ld-modal">
        <div class="ld-modal-head">
          <h3 class="ld-modal-title">Add Stakeholder</h3>
          <button class="ld-close" aria-label="Close" @click="showStakeholder = false">×</button>
        </div>

        <label class="ld-field"><span>Name</span>
          <input v-model="newStakeholder.name" class="control" placeholder="John Omondi" /></label>
        <label class="ld-field"><span>Role</span>
          <input v-model="newStakeholder.role" class="control" placeholder="Head of Digital Sales" /></label>
        <label class="ld-field"><span>Email</span>
          <input v-model="newStakeholder.email" class="control" type="email" /></label>
        <label class="ld-field"><span>Influence</span>
          <select v-model="newStakeholder.influence" class="control">
            <option value="decision_maker">Decision maker</option>
            <option value="influencer">Influencer</option>
            <option value="blocker">Blocker</option>
            <option value="user">End user</option>
          </select></label>

        <button class="btn-primary btn-block" :disabled="saving || !newStakeholder.name.trim()" @click="addStakeholder">
          Add Stakeholder
        </button>
      </div>
    </div>

    <!-- Lead team -->
    <div v-if="showTeam" class="ld-modal-backdrop" @click.self="showTeam = false">
      <div class="ld-modal">
        <div class="ld-modal-head">
          <h3 class="ld-modal-title">Lead Team</h3>
          <button class="ld-close" aria-label="Close" @click="showTeam = false">×</button>
        </div>

        <p class="ld-hint">Ours — the people researching and shaping this lead.</p>

        <div class="ld-add-row">
          <EmployeePicker v-model="newMember.employee_id" placeholder="Add somebody…" />
          <button class="btn-primary" :disabled="saving || !newMember.employee_id" @click="addMember">Add</button>
        </div>

        <ul v-if="lead?.team?.length" class="ld-people">
          <li v-for="m in lead.team" :key="m.id">
            <span class="ld-person">
              <span class="ld-avatar">{{ initials(m.employee.name) }}</span>{{ m.employee.name }}
            </span>
            <button class="link-btn link-danger" @click="removeMember(m)">Remove</button>
          </li>
        </ul>
        <p v-else class="ld-muted">Nobody assigned yet.</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.ld-wrap { padding: var(--space-6); max-width: 76rem; }

.ld-crumbs { @apply flex flex-wrap items-center; gap: var(--space-2); font-size: var(--text-sm-size); margin-bottom: var(--space-3); }
.ld-crumb { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: inherit; color: var(--color-text-muted); }
.ld-crumb:hover { color: var(--color-text); }
.ld-sep { color: var(--color-text-light); }
.ld-current { color: var(--color-text); }

.ld-loading { padding: var(--space-6); color: var(--color-text-muted); }

.ld-head { @apply flex items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-4); }
.ld-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ld-byline { @apply flex flex-wrap items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-2) 0 0; }
.ld-dot { color: var(--color-text-light); }

.ld-banner {
  padding: var(--space-3) var(--space-4); border-radius: var(--radius-md);
  background: var(--color-success-subtle); color: var(--color-success-text);
  font-size: var(--text-sm-size); margin-bottom: var(--space-4);
}

.ld-facts-row { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3) var(--space-8); margin-bottom: var(--space-5); }
@media (max-width: 48rem) { .ld-facts-row { grid-template-columns: 1fr; } }
.ld-facts { margin: 0; }
.ld-facts > div { @apply flex items-center; gap: var(--space-3); padding: var(--space-1) 0; min-height: 2rem; }
.ld-facts dt { font-size: var(--text-sm-size); color: var(--color-text-muted); min-width: 7rem; }
.ld-facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }
.ld-status { border-left: 2px solid var(--color-text-light); padding-left: var(--space-2); }
.ld-priority { font-size: var(--text-sm-size); }
.prio-high   { color: var(--color-danger-text); font-weight: 500; }
.prio-medium { color: var(--color-warning-text); }
.prio-low    { color: var(--color-text-muted); }

.ld-tabs { @apply flex flex-wrap gap-1 overflow-x-auto; border-bottom: 1px solid var(--color-border); }
.ld-tab {
  @apply cursor-pointer border-none bg-transparent;
  padding: var(--space-2) var(--space-3);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap;
}
.ld-tab:hover  { color: var(--color-text); }
.ld-tab.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }

.ld-panel { @apply flex flex-col; padding: var(--space-5) 0; gap: var(--space-4); }
.ld-panel-actions { @apply flex justify-end; }

.ld-block-title { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-2); }
.ld-block { margin-bottom: var(--space-2); }
.ld-block-label {
  font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0;
}
.ld-prose { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; white-space: pre-wrap; line-height: 1.65; }
.ld-strong { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ld-link   { font-size: var(--text-sm-size); color: var(--color-brand-on-surface); margin: 0; }
.ld-muted  { font-size: var(--text-sm-size); color: var(--color-text-light); margin: 0; }
.ld-hint   { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0; }
.ld-nowrap { white-space: nowrap; }

.ld-card {
  @apply flex flex-col; gap: var(--space-3);
  padding: var(--space-5);
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
.ld-table-card { padding: 0; overflow-x: auto; }
.ld-card-head { @apply flex items-center justify-between; gap: var(--space-3); }

.ld-empty { @apply flex flex-col items-center; padding: var(--space-8) var(--space-6); text-align: center; gap: var(--space-2); }
.ld-empty-title { font-size: var(--text-base-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ld-empty-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0 0 var(--space-2); max-width: 52ch; }

/* ── Company ───────────────────────────────────────────────────────────── */
.ld-company-head { @apply flex items-center; gap: var(--space-4); }
.ld-company-name { font-size: var(--text-base-size); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-1); }
.ld-company-open { margin-left: auto; }
.ld-company-facts { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3) var(--space-8); margin: var(--space-2) 0 0; }
@media (max-width: 48rem) { .ld-company-facts { grid-template-columns: 1fr; } }
.ld-company-facts > div { @apply flex items-baseline; gap: var(--space-3); }
.ld-company-facts dt { font-size: var(--text-sm-size); color: var(--color-text-muted); min-width: 9rem; }
.ld-company-facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }
.ld-budget { color: var(--color-brand-on-surface); font-weight: 500; }
.ld-company { @apply inline-flex items-center; gap: var(--space-2); }

.ld-contact-grid { @apply grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-4); }
@media (max-width: 56rem) { .ld-contact-grid { grid-template-columns: 1fr; } }

/* ── Qualification ─────────────────────────────────────────────────────── */
.ld-qual-grid { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4); }
@media (max-width: 56rem) { .ld-qual-grid { grid-template-columns: 1fr; } }

.ld-gauge-row { @apply flex flex-wrap items-center; gap: var(--space-4); }
.ld-gauge { width: 11rem; height: 11rem; transform: rotate(135deg); flex-shrink: 0; }
.ld-gauge-track { fill: none; stroke: var(--color-surface-sunken); stroke-width: 18; stroke-linecap: round; }
.ld-gauge-fill  { fill: none; stroke-width: 18; stroke-linecap: round; transition: stroke-dashoffset 400ms ease; }
.band-high_fit     { stroke: var(--color-success-text); }
.band-moderate_fit { stroke: var(--color-brand-base); }
.band-low_fit      { stroke: var(--color-warning-text); }
.band-poor_fit     { stroke: var(--color-danger-text); }
.ld-gauge-label, .ld-gauge-value {
  text-anchor: middle; transform: rotate(-135deg); transform-origin: 90px 90px;
  fill: var(--color-text);
}
.ld-gauge-label { font-size: 13px; fill: var(--color-text-muted); }
.ld-gauge-value { font-size: 30px; font-weight: 700; }
.ld-gauge-note { @apply flex flex-col; gap: var(--space-2); flex: 1; min-width: 12rem; align-items: flex-start; }

.ld-bars { @apply list-none; margin: 0; padding: 0; }
.ld-bars li { @apply flex items-center; gap: var(--space-3); padding: var(--space-2) 0; }
.ld-bar-label { font-size: var(--text-sm-size); color: var(--color-text-muted); width: 9rem; flex-shrink: 0; }
.ld-bar-track { @apply block; flex: 1; height: 8px; border-radius: 999px; background: var(--color-surface-sunken); overflow: hidden; }
.ld-bar-fill  { @apply block; height: 100%; border-radius: 999px; background: var(--color-brand-base); }
.ld-bar-value { font-size: var(--text-xs-size); color: var(--color-brand-on-surface); width: 3rem; text-align: right; font-variant-numeric: tabular-nums; }

.ld-add-stake { align-self: flex-start; }

/* ── Documents ─────────────────────────────────────────────────────────── */
.ld-file { @apply inline-flex items-center; gap: var(--space-3); }
.ld-file-ext {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 2rem; height: 2.25rem; border-radius: var(--radius-sm);
  background: var(--color-danger-subtle); color: var(--color-danger-text);
  font-size: 0.55rem; font-weight: 700;
}
.ld-file-size { @apply block; font-size: var(--text-xs-size); color: var(--color-text-light); }

.ld-drop {
  @apply flex cursor-pointer flex-col items-center;
  gap: var(--space-1); padding: var(--space-5);
  border: 1px dashed var(--color-border-strong); border-radius: var(--radius-md);
  font-size: var(--text-sm-size); color: var(--color-text-muted); text-align: center;
}
.ld-drop:hover { border-color: var(--color-brand-base); }
.ld-drop-icon { font-size: var(--text-lg-size); }
.ld-drop-link { color: var(--color-brand-on-surface); }
.ld-drop-hint { font-size: var(--text-xs-size); color: var(--color-text-light); }
.ld-drop-file { font-size: var(--text-xs-size); color: var(--color-text); margin-top: var(--space-2); }

/* ── Shared ────────────────────────────────────────────────────────────── */
.ld-grid { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4); }
.ld-span { grid-column: 1 / -1; }
@media (max-width: 40rem) { .ld-grid { grid-template-columns: 1fr; } }

.ld-field { @apply flex flex-col; gap: var(--space-1); }
.ld-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.ld-actions { @apply flex justify-end; }

.ld-log { @apply flex flex-wrap; gap: var(--space-2); }
.ld-log .control { flex: 1; min-width: 12rem; }
.ld-log-type { max-width: 10rem; flex: 0 0 auto; }

.ld-mini { margin: 0; }
.ld-mini > div { @apply flex items-center justify-between; gap: var(--space-3); padding: var(--space-2) 0; }
.ld-mini dt { font-size: var(--text-sm-size); color: var(--color-text-muted); }
.ld-mini dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }

.ld-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.ld-table th {
  text-align: left; padding: var(--space-3) var(--space-4);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  background: var(--color-surface-sunken);
  border-bottom: 1px solid var(--color-border); white-space: nowrap;
}
.ld-table td { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.ld-table tr:last-child td { border-bottom: none; }
.ld-table.flush th, .ld-table.flush td { padding-left: 0; padding-right: 0; }
.ld-table.flush th { background: transparent; }
.ld-row-actions { @apply flex; gap: var(--space-3); }

.ld-person { @apply inline-flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }
.ld-person.lg { gap: var(--space-3); }
.ld-avatar {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 1.5rem; height: 1.5rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: 0.6rem; font-weight: 600;
}
.ld-avatar.sm  { width: 1.25rem; height: 1.25rem; font-size: 0.55rem; }
.ld-avatar.xl  { width: 3rem; height: 3rem; font-size: var(--text-sm-size); border-radius: var(--radius-md); }
.ld-avatar.brand { background: var(--color-brand-subtle); color: var(--color-brand-on-surface); }
.ld-avatar.more  { background: var(--color-brand-subtle); color: var(--color-brand-on-surface); }

.ld-stack-btn { @apply cursor-pointer border-none bg-transparent p-0; }
.ld-stack { @apply inline-flex items-center; }
.ld-stack .ld-avatar { margin-right: -0.35rem; border: 2px solid var(--color-surface); }

.ld-add-row { @apply flex items-start; gap: var(--space-2); }
.ld-add-row > div:first-child { flex: 1; }
.ld-people { @apply list-none; margin: 0; padding: 0; }
.ld-people li { @apply flex items-center justify-between; gap: var(--space-3); padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border); }
.ld-people li:last-child { border-bottom: none; }

.pill { display: inline-block; padding: 2px var(--space-3); border-radius: 999px; font-size: var(--text-xs-size); border: 1px solid transparent; white-space: nowrap; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 30%, transparent); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); border-color: color-mix(in oklab, var(--color-warning-text) 30%, transparent); }
.pill-info    { background: var(--color-brand-subtle);   color: var(--color-brand-on-surface); border-color: color-mix(in oklab, var(--color-brand-base) 30%, transparent); }

.icon-btn { @apply cursor-pointer border-none bg-transparent p-0; font-size: var(--text-sm-size); color: var(--color-text-muted); }
.icon-danger:hover { color: var(--color-danger-text); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); text-align: left; }
.link-btn:hover { @apply underline; }
.link-danger { color: var(--color-danger-text); }

.ld-modal-backdrop { @apply fixed inset-0 flex items-center justify-center z-50; background: rgb(0 0 0 / 0.45); padding: var(--space-4); }
.ld-modal {
  @apply flex flex-col w-full overflow-y-auto; max-width: 26rem; max-height: 90vh; gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised);
  border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
}
.ld-modal-head { @apply flex items-center justify-between; }
.ld-modal-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.ld-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

.btn-primary, .btn-ghost {
  @apply inline-flex cursor-pointer items-center justify-center;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-ghost   { background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-block   { width: 100%; }
.btn-primary:disabled, .btn-ghost:disabled { @apply cursor-not-allowed opacity-50; }

</style>
