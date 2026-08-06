<script setup lang="ts">
/**
 * One customer, as the agreed design lays it out.
 *
 * Ten tabs, because the legacy account page had ten and each answered a
 * different question about the same company. Four are backed today — Overview,
 * Contacts, Account Contracts, Activities — plus Travels and Assigned Team,
 * which read links that already existed. Leads, Opportunities, Projects and
 * Support are shown rather than hidden: they are part of the record's shape,
 * and a tab that says what it is waiting for is more honest than one that
 * quietly is not there.
 *
 * Nothing is gated in the UI. The API refuses what the caller may not do and
 * says why — hiding a button as well would mean two definitions of who may
 * edit a customer, and they would drift.
 */
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import EmployeePicker from "../../components/EmployeePicker.vue";

const props = defineProps<{ id: string | number }>();

interface Named { id: number; name: string }
interface Contact {
  id: number; category: string; title: string | null; first_name: string; last_name: string;
  full_name: string; designation: string | null; department: string | null;
  email: string | null; phone: string | null; is_primary: boolean; is_active: boolean;
}
interface Attachment {
  id: number; account_contract_id: number | null; document_type: string;
  label: string; original_name: string; size_bytes: number | null; expiry_date: string | null;
}
interface Contract {
  id: number; reference: string | null; name: string; contract_number: string | null;
  type: string | null; contract_value: string | number | null; billing_cycle: string | null;
  owner: string | null; project_reference: string | null;
  start_date: string; end_date: string; status: string; completed_at: string | null;
  notes: string | null; is_expired: boolean; days_to_expiry: number | null;
  attachments?: Attachment[];
}
interface TeamMember { id: number; employee: { id: number | null; name: string | null }; role: string | null }
interface Activity {
  id: number; kind: string; type: string; body: string | null;
  occurred_at: string; actor: { id: number | null; name: string | null };
}
interface Trip {
  id: number; reference: string | null; traveller: string | null; role: string | null;
  destination: string | null; departure_date: string | null; return_date: string | null; status: string;
}
interface Lead {
  id: number; reference: string | null; name: string; source: string | null;
  status: string; priority: string; owner?: { id: number | null; name: string | null } | null;
}
interface Opportunity {
  id: number; reference: string | null; name: string; amount: string | number | null;
  phase?: { id: number; name: string; outcome: string | null } | null;
  outcome: string | null; is_open: boolean; score: number; score_max: number; score_band: string;
  owner?: { id: number | null; name: string | null } | null;
  assignees?: { id: number; employee: { id: number | null; name: string | null } }[];
}
interface Summary {
  total_deals: number; active_deals: number; closed_won: number; closed_lost: number;
  won_value: number; pipeline_value: number; open_leads: number;
}
interface Account {
  id: number; reference: string | null; name: string; initials: string; status: string;
  country: string | null; physical_location: string | null; tax_pin: string | null;
  vat_registered: boolean; enrolled_on: string | null; last_contact_at: string | null;
  number_of_branches: number | null; number_of_employees: number | null;
  fund_source: string | null; lead_source: string | null; pipeline_stage: string | null;
  details: string | null;
  account_manager?: { id: number | null; name: string | null };
  type?: Named | null; sector?: Named | null; subsector?: Named | null;
  primary_contact?: { id: number; name: string; email: string | null; phone: string | null; role: string | null } | null;
  contacts?: Contact[]; contracts?: Contract[]; team?: TeamMember[]; attachments?: Attachment[];
}

type Tab = "overview" | "contacts" | "contracts" | "activities" | "leads"
         | "opportunities" | "projects" | "support" | "travels" | "team";

const router  = useRouter();
const account = ref<Account | null>(null);
const lookups = ref<{ types: Named[]; sectors: Named[]; subsectors: (Named & { account_sector_id: number })[] }>(
  { types: [], sectors: [], subsectors: [] },
);
const tab     = ref<Tab>("overview");
const loading = ref(true);
const saving  = ref(false);

const org  = () => currentOrgSlug.value;
const base = computed(() => `/api/v1/accounts/${props.id}`);

const tabs: { key: Tab; label: string }[] = [
  { key: "overview",      label: "Overview" },
  { key: "contacts",      label: "Contacts" },
  { key: "contracts",     label: "Account Contracts" },
  { key: "activities",    label: "Activities" },
  { key: "leads",         label: "Leads" },
  { key: "opportunities", label: "Opportunities" },
  { key: "projects",      label: "Projects" },
  { key: "support",       label: "Support" },
  { key: "travels",       label: "Travels" },
  { key: "team",          label: "Assigned Team" },
];

/** What each not-yet-built tab is waiting on, said plainly. */
const pending: Partial<Record<Tab, { title: string; detail: string }>> = {
  projects: {
    title: "Projects are not built yet",
    detail: "A contract can name the project it is for, and that name is kept — it becomes a link once Projects exists.",
  },
  support: {
    title: "Support tickets are not built yet",
    detail: "The legacy system let customers raise and comment on tickets themselves. Nothing here replaces that yet.",
  },
};

// ── Loading ─────────────────────────────────────────────────────────────────

async function load() {
  loading.value = true;
  try {
    const [res, lk] = await Promise.all([
      api<{ data: Account }>("GET", base.value, { org: org() }),
      api<typeof lookups.value>("GET", "/api/v1/accounts/lookups", { org: org() }),
    ]);
    account.value = res.data;
    lookups.value = lk;
    fillForm(res.data);
  } catch (e) {
    notifyFailed("Could not load this customer.", e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
  } finally {
    loading.value = false;
  }
}

const flash = (message: string) => notifySaved(message);

function fail(e: unknown, fallback: string) {
  // The detail is the API's own message — a validation error is far more use
  // than "could not save", and a toast has room for both.
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

// Loaded on demand rather than with the record: none of these belong to the
// account row, and most visits never open these tabs.
const activities = ref<Activity[]>([]);
const trips      = ref<Trip[]>([]);
const leads      = ref<Lead[]>([]);
const deals      = ref<Opportunity[]>([]);
const summary    = ref<Summary | null>(null);

async function loadActivities() {
  try {
    activities.value = (await api<{ data: Activity[] }>("GET", `${base.value}/activities`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load the activity feed."); }
}

async function loadTrips() {
  try {
    trips.value = (await api<{ data: Trip[] }>("GET", `${base.value}/travels`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load trips for this customer."); }
}

async function loadLeads() {
  try {
    leads.value = (await api<{ data: Lead[] }>("GET", "/api/v1/leads", {
      org: org(), params: { account_id: props.id, per_page: 100 },
    })).data;
  } catch (e) { fail(e, "Could not load leads for this customer."); }
}

async function loadDeals() {
  try {
    deals.value = (await api<{ data: Opportunity[] }>("GET", "/api/v1/opportunities", {
      org: org(), params: { account_id: props.id, per_page: 100 },
    })).data;
  } catch (e) { fail(e, "Could not load opportunities for this customer."); }
}

/**
 * The deal figures, counted by the API rather than stored.
 *
 * Failure is swallowed on purpose: somebody who may see the customer but not
 * the pipeline should get a record with blank deal counts, not an error page
 * about a card they were never meant to read.
 */
async function loadSummary() {
  try {
    summary.value = (await api<{ data: Summary }>("GET", `${base.value}/summary`, { org: org() })).data;
  } catch { summary.value = null; }
}

watch(tab, (t) => {
  if (t === "activities" && activities.value.length === 0) void loadActivities();
  if (t === "travels" && trips.value.length === 0) void loadTrips();
  if (t === "leads" && leads.value.length === 0) void loadLeads();
  if (t === "opportunities" && deals.value.length === 0) void loadDeals();
});

// ── Profile ─────────────────────────────────────────────────────────────────

const editing = ref(false);
const form = ref({
  name: "", status: "pending", account_manager_id: null as number | null,
  account_type_id: null as number | null, account_sector_id: null as number | null,
  account_subsector_id: null as number | null,
  country: "", physical_location: "", tax_pin: "", vat_registered: false,
  number_of_branches: null as number | null, number_of_employees: null as number | null,
  fund_source: "", lead_source: "", pipeline_stage: "", enrolled_on: "", details: "",
});

function fillForm(a: Account) {
  form.value = {
    name: a.name, status: a.status,
    account_manager_id: a.account_manager?.id ?? null,
    account_type_id: a.type?.id ?? null,
    account_sector_id: a.sector?.id ?? null,
    account_subsector_id: a.subsector?.id ?? null,
    country: a.country ?? "", physical_location: a.physical_location ?? "",
    tax_pin: a.tax_pin ?? "", vat_registered: a.vat_registered,
    number_of_branches: a.number_of_branches, number_of_employees: a.number_of_employees,
    fund_source: a.fund_source ?? "", lead_source: a.lead_source ?? "",
    pipeline_stage: a.pipeline_stage ?? "", enrolled_on: a.enrolled_on ?? "",
    details: a.details ?? "",
  };
}

/** Sub-sectors belong to a sector; showing all of them would offer nonsense. */
const subsectorOptions = computed(() =>
  form.value.account_sector_id
    ? lookups.value.subsectors.filter((s) => s.account_sector_id === form.value.account_sector_id)
    : [],
);

function onSectorChange() {
  // A sub-sector that no longer belongs to the chosen sector would be rejected
  // by the API anyway; clearing it here says so before the save.
  if (!subsectorOptions.value.some((s) => s.id === form.value.account_subsector_id)) {
    form.value.account_subsector_id = null;
  }
}

async function saveProfile() {
  saving.value = true;
  try {
    const res = await api<{ data: Account }>("PUT", base.value, {
      org: org(),
      body: {
        ...form.value,
        country: form.value.country || null,
        physical_location: form.value.physical_location || null,
        tax_pin: form.value.tax_pin || null,
        fund_source: form.value.fund_source || null,
        lead_source: form.value.lead_source || null,
        pipeline_stage: form.value.pipeline_stage || null,
        enrolled_on: form.value.enrolled_on || null,
        details: form.value.details || null,
      },
    });
    // Merged rather than replaced: update() returns the profile without the
    // contacts, team and contracts the other tabs are showing.
    account.value = { ...account.value, ...res.data };
    fillForm(res.data);
    editing.value = false;
    flash("Saved.");
  } catch (e) { fail(e, "Could not save."); }
  finally { saving.value = false; }
}

// ── Contacts ────────────────────────────────────────────────────────────────

const CATEGORIES = [
  { value: "primary", label: "Primary contact" },
  { value: "secondary", label: "Secondary contact" },
  { value: "technical", label: "Technical contact" },
  { value: "finance", label: "Finance contact" },
  { value: "procurement", label: "Procurement contact" },
  { value: "hr", label: "HR contact" },
  { value: "other", label: "Other" },
];

const blankContact = () => ({
  id: null as number | null, category: "secondary", name: "",
  designation: "", email: "", phone: "",
});
const contactForm  = ref(blankContact());
const showContact  = ref(false);
const contactToDelete = ref<Contact | null>(null);

function editContact(c: Contact) {
  contactForm.value = {
    id: c.id, category: c.category, name: c.full_name,
    designation: c.designation ?? "", email: c.email ?? "", phone: c.phone ?? "",
  };
  showContact.value = true;
}

async function saveContact() {
  saving.value = true;
    const { id, ...body } = contactForm.value;
  try {
    await api(id ? "PUT" : "POST", id ? `${base.value}/contacts/${id}` : `${base.value}/contacts`, {
      org: org(),
      body: {
        ...body,
        designation: body.designation || null,
        email: body.email || null,
        phone: body.phone || null,
      },
    });
    showContact.value = false;
    await load();
    flash(id ? "Contact updated." : "Contact added.");
  } catch (e) { fail(e, "Could not save the contact."); }
  finally { saving.value = false; }
}

async function removeContact() {
  const c = contactToDelete.value;
  if (!c) return;
  try {
    await api("DELETE", `${base.value}/contacts/${c.id}`, { org: org() });
    contactToDelete.value = null;
    await load();
    flash("Contact removed.");
  } catch (e) { fail(e, "Could not remove the contact."); }
}

const categoryLabel = (v: string) =>
  (CATEGORIES.find((c) => c.value === v)?.label ?? "Contact").toUpperCase();

// ── Contracts ───────────────────────────────────────────────────────────────

const CONTRACT_TYPES  = ["subscription", "sla", "service", "licence", "framework", "support"];
const BILLING_CYCLES  = ["one_off", "monthly", "quarterly", "biannual", "annual"];
const CONTRACT_STATUS = ["draft", "in_progress", "active", "completed", "expired", "terminated"];

const blankContract = () => ({
  id: null as number | null, name: "", contract_number: "", type: "",
  contract_value: null as number | null, billing_cycle: "", owner: "",
  project_reference: "", start_date: "", end_date: "", status: "active", notes: "",
});
const contractForm = ref(blankContract());
const showContract = ref(false);
const openContract = ref<Contract | null>(null);

function editContract(c: Contract) {
  contractForm.value = {
    id: c.id, name: c.name, contract_number: c.contract_number ?? "", type: c.type ?? "",
    contract_value: c.contract_value === null ? null : Number(c.contract_value),
    billing_cycle: c.billing_cycle ?? "", owner: c.owner ?? "",
    project_reference: c.project_reference ?? "",
    start_date: c.start_date, end_date: c.end_date, status: c.status, notes: c.notes ?? "",
  };
  showContract.value = true;
}

async function saveContract() {
  saving.value = true;
    const { id, ...body } = contractForm.value;
  try {
    await api(id ? "PUT" : "POST", id ? `${base.value}/contracts/${id}` : `${base.value}/contracts`, {
      org: org(),
      body: {
        ...body,
        contract_number: body.contract_number || null,
        type: body.type || null,
        billing_cycle: body.billing_cycle || null,
        owner: body.owner || null,
        project_reference: body.project_reference || null,
        notes: body.notes || null,
      },
    });
    showContract.value = false;
    await load();
    flash(id ? "Contract updated." : "Contract added.");
  } catch (e) { fail(e, "Could not save the contract."); }
  finally { saving.value = false; }
}

async function completeContract(c: Contract) {
  try {
    await api("POST", `${base.value}/contracts/${c.id}/complete`, { org: org() });
    await load();
    openContract.value = account.value?.contracts?.find((x) => x.id === c.id) ?? null;
    flash("Marked as completed.");
  } catch (e) { fail(e, "Could not complete the contract."); }
}

async function removeContract(c: Contract) {
  if (!confirm(`Remove "${c.name}"? The record of what was agreed goes with it.`)) return;
  try {
    await api("DELETE", `${base.value}/contracts/${c.id}`, { org: org() });
    openContract.value = null;
    await load();
    flash("Contract removed.");
  } catch (e) { fail(e, "Could not remove the contract."); }
}

/**
 * Expiry, said in the terms somebody acts on.
 *
 * A date alone makes the reader work out whether it matters; "in 12 days" does
 * not, and 12 days is when a renewal still has time to be signed.
 */
function standing(c: Contract): { label: string; tone: string } {
  if (c.status === "completed")   return { label: "Completed", tone: "neutral" };
  if (c.status === "terminated")  return { label: "Terminated", tone: "neutral" };
  if (c.status === "draft")       return { label: "Draft", tone: "neutral" };
  if (c.is_expired)               return { label: "Expired", tone: "danger" };
  if (c.status === "in_progress") return { label: "In Progress", tone: "warn" };
  const d = c.days_to_expiry;
  if (d !== null && d <= 90) return { label: `Expires in ${d} day${d === 1 ? "" : "s"}`, tone: "warn" };
  return { label: "Active", tone: "ok" };
}

// ── Attachments ─────────────────────────────────────────────────────────────

const uploading = ref(false);

async function uploadTo(contractId: number | null, event: Event) {
  const input = event.target as HTMLInputElement;
  const file  = input.files?.[0];
  if (!file) return;

  uploading.value = true;
  try {
    const body = new FormData();
    body.append("file", file);
    if (contractId) body.append("account_contract_id", String(contractId));
    body.append("document_type", contractId ? "contract" : "other");

    await api("POST", `${base.value}/attachments`, { org: org(), body });
    await load();
    if (contractId) openContract.value = account.value?.contracts?.find((c) => c.id === contractId) ?? null;
    flash("Document attached.");
  } catch (e) { fail(e, "Could not attach the document."); }
  finally { uploading.value = false; input.value = ""; }
}

async function removeAttachment(a: Attachment) {
  try {
    await api("DELETE", `${base.value}/attachments/${a.id}`, { org: org() });
    const contractId = a.account_contract_id;
    await load();
    if (contractId) openContract.value = account.value?.contracts?.find((c) => c.id === contractId) ?? null;
    flash("Document removed.");
  } catch (e) { fail(e, "Could not remove the document."); }
}

function downloadUrl(a: Attachment): string {
  const root = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");
  return `${root}${base.value}/attachments/${a.id}/download?org=${org()}`;
}

// ── Activities ──────────────────────────────────────────────────────────────

const newActivity = ref({ type: "note", body: "" });

async function logActivity() {
  if (!newActivity.value.body.trim()) return;
  saving.value = true;
  try {
    await api("POST", `${base.value}/activities`, { org: org(), body: { ...newActivity.value } });
    newActivity.value = { type: "note", body: "" };
    await loadActivities();
    // last_contact_at moved, and the header shows it.
    account.value = (await api<{ data: Account }>("GET", base.value, { org: org() })).data;
    flash("Logged.");
  } catch (e) { fail(e, "Could not log that."); }
  finally { saving.value = false; }
}

// ── Leads ───────────────────────────────────────────────────────────────────

const showLead = ref(false);
const newLead  = ref({ name: "", source: "", priority: "medium" });

async function createLead() {
  if (!newLead.value.name.trim()) return;
  saving.value = true;
  try {
    const res = await api<{ data: Lead }>("POST", "/api/v1/leads", {
      org: org(),
      // The customer is not asked for here — the lead is being raised from
      // their record, so it can only be for them.
      body: { ...newLead.value, source: newLead.value.source || null, account_id: Number(props.id) },
    });
    showLead.value = false;
    newLead.value = { name: "", source: "", priority: "medium" };
    // Straight into the record: a lead is raised in order to work it.
    router.push({ name: "lead", params: { id: res.data.id } });
  } catch (e) { fail(e, "Could not raise the lead."); }
  finally { saving.value = false; }
}

const leadTone = (s: string) =>
  s === "converted" ? "ok" : s === "lost" ? "danger" : s === "qualified" ? "ok" : s === "cold" ? "neutral" : "warn";

// ── Team ────────────────────────────────────────────────────────────────────

const newMember = ref({ employee_id: null as number | null, role: "" });

async function addMember() {
  if (!newMember.value.employee_id) return;
  saving.value = true;
  try {
    const res = await api<{ data: Account }>("POST", `${base.value}/team`, {
      org: org(), body: { ...newMember.value, role: newMember.value.role || null },
    });
    account.value = res.data;
    newMember.value = { employee_id: null, role: "" };
    flash("Added to the team.");
  } catch (e) { fail(e, "Could not add them."); }
  finally { saving.value = false; }
}

async function removeMember(m: TeamMember) {
  try {
    account.value = (await api<{ data: Account }>("DELETE", `${base.value}/team/${m.id}`, { org: org() })).data;
  } catch (e) { fail(e, "Could not remove them."); }
}

// ── Formatting ──────────────────────────────────────────────────────────────

const money = (v: string | number | null) =>
  v === null || v === "" ? "—" : `KES ${Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

const titleCase = (v: string | null) =>
  v ? v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase()) : "—";

/** "8 minutes ago" reads as a timeline; a timestamp does not. */
function ago(iso: string): string {
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

/**
 * What we hold under contract today.
 *
 * Real, unlike the deal counts beside it: it is the value of the agreements
 * that are actually running, not a pipeline forecast.
 */
const contractedValue = computed(() =>
  (account.value?.contracts ?? [])
    .filter((c) => c.status === "active" || c.status === "in_progress")
    .reduce((sum, c) => sum + Number(c.contract_value ?? 0), 0),
);

const accountAttachments = computed(() =>
  (account.value?.attachments ?? []).filter((a) => a.account_contract_id === null),
);

const countFor = (t: Tab) =>
  t === "contacts"  ? account.value?.contacts?.length
  : t === "contracts" ? account.value?.contracts?.length
  : t === "team"      ? account.value?.team?.length
  : t === "leads"     ? leads.value.length || undefined
  : t === "opportunities" ? deals.value.length || undefined
  : undefined;

onMounted(() => { void load(); void loadSummary(); });
</script>

<template>
  <div class="ad-wrap">
    <nav class="ad-crumbs">
      <button class="ad-crumb" @click="router.push({ name: 'accounts' })">Accounts</button>
      <span class="ad-crumb-sep">›</span>
      <span class="ad-crumb-current">{{ account?.name ?? "…" }}</span>
    </nav>

    <div v-if="loading" class="ad-loading">Loading…</div>

    <template v-else-if="account">
      <h1 class="ad-title">{{ account.name }}</h1>

      <nav class="ad-tabs" role="tablist">
        <button v-for="t in tabs" :key="t.key" role="tab" :aria-selected="tab === t.key"
                :class="['ad-tab', { active: tab === t.key }]" @click="tab = t.key">
          {{ t.label }}<span v-if="countFor(t.key)" class="ad-count">{{ countFor(t.key) }}</span>
        </button>
      </nav>

      <!-- ── Overview ────────────────────────────────────────────────── -->
      <section v-if="tab === 'overview'" class="ad-panel">
        <div class="ad-card ad-identity">
          <div class="ad-identity-head">
            <div class="ad-avatar">{{ account.initials }}</div>
            <div>
              <h2 class="ad-identity-name">{{ account.name }}</h2>
              <span :class="['pill', account.status === 'active' ? 'pill-ok' : account.status === 'inactive' ? 'pill-neutral' : 'pill-warn']">
                {{ account.status }}
              </span>
            </div>
            <button class="btn-ghost ad-identity-edit" @click="editing = !editing">
              {{ editing ? "Cancel" : "Edit" }}
            </button>
          </div>

          <dl v-if="!editing" class="ad-facts">
            <div><dt>Client ID</dt><dd>{{ account.reference ?? "—" }}</dd></div>
            <!-- The design calls this Industry; it is the sector we already
                 hold, so there is one answer rather than two. -->
            <div><dt>Industry</dt><dd>{{ account.sector?.name ?? "—" }}</dd></div>
            <div><dt>Customer Type</dt><dd>{{ account.type?.name ?? "—" }}</dd></div>
            <div><dt>Status</dt><dd class="cap">{{ account.status }}</dd></div>
            <div><dt>Contact Person</dt><dd>{{ account.primary_contact?.name ?? "—" }}</dd></div>
            <div><dt>Enroled on</dt><dd>{{ date(account.enrolled_on) }}</dd></div>
            <div><dt>Account Manager</dt><dd>{{ account.account_manager?.name ?? "—" }}</dd></div>
            <div><dt>Last Contact</dt><dd>{{ account.last_contact_at ? date(account.last_contact_at) : "Never" }}</dd></div>
          </dl>
        </div>

        <!-- Edit form, folded away until asked for: the record is read far
             more often than it is changed. -->
        <div v-if="editing" class="ad-card">
          <div class="ad-grid">
            <label class="ad-field"><span>Name</span>
              <input v-model="form.name" class="control" /></label>

            <label class="ad-field"><span>Status</span>
              <select v-model="form.status" class="control">
                <option value="pending">Pending</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select></label>

            <div class="ad-field ad-span">
              <span>Account manager</span>
              <EmployeePicker v-model="form.account_manager_id" placeholder="Who owns this relationship?" />
              <p v-if="account.account_manager?.name" class="ad-hint">
                Currently {{ account.account_manager.name }}. Reassignment needs its own
                permission — if the change does not stick, that is why.
              </p>
            </div>

            <label class="ad-field"><span>Customer type</span>
              <select v-model.number="form.account_type_id" class="control">
                <option :value="null">—</option>
                <option v-for="t in lookups.types" :key="t.id" :value="t.id">{{ t.name }}</option>
              </select></label>

            <label class="ad-field"><span>Industry (sector)</span>
              <select v-model.number="form.account_sector_id" class="control" @change="onSectorChange">
                <option :value="null">—</option>
                <option v-for="s in lookups.sectors" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select></label>

            <label class="ad-field"><span>Sub-sector</span>
              <select v-model.number="form.account_subsector_id" class="control"
                      :disabled="!form.account_sector_id || subsectorOptions.length === 0">
                <option :value="null">—</option>
                <option v-for="s in subsectorOptions" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select>
              <p v-if="form.account_sector_id && subsectorOptions.length === 0" class="ad-hint">
                No sub-sectors defined for this sector.
              </p></label>

            <label class="ad-field"><span>Enroled on</span>
              <input v-model="form.enrolled_on" class="control" type="date" /></label>

            <label class="ad-field"><span>Lead source</span>
              <input v-model="form.lead_source" class="control" placeholder="Referral, tender, inbound" /></label>

            <label class="ad-field"><span>Pipeline stage</span>
              <input v-model="form.pipeline_stage" class="control" placeholder="Proposal" /></label>

            <label class="ad-field"><span>Country (ISO code)</span>
              <input v-model="form.country" class="control" maxlength="2" placeholder="KE" /></label>

            <label class="ad-field ad-span"><span>Physical location</span>
              <input v-model="form.physical_location" class="control" placeholder="Westlands, Nairobi" /></label>

            <label class="ad-field"><span>Tax PIN</span>
              <input v-model="form.tax_pin" class="control" /></label>

            <label class="ad-field ad-check">
              <input v-model="form.vat_registered" type="checkbox" />
              <span>VAT registered</span></label>

            <label class="ad-field"><span>Branches</span>
              <input v-model.number="form.number_of_branches" class="control" type="number" min="0" /></label>

            <label class="ad-field"><span>Employees</span>
              <input v-model.number="form.number_of_employees" class="control" type="number" min="0" /></label>

            <label class="ad-field ad-span"><span>Source of funds</span>
              <input v-model="form.fund_source" class="control" placeholder="Donor funded, treasury, private" /></label>

            <label class="ad-field ad-span"><span>Notes</span>
              <textarea v-model="form.details" class="control" rows="4"></textarea></label>
          </div>

          <div class="ad-actions">
            <button class="btn-primary" :disabled="saving" @click="saveProfile">
              {{ saving ? "Saving…" : "Save changes" }}
            </button>
          </div>
        </div>

        <div class="ad-summary">
          <div class="ad-card">
            <h3 class="ad-card-label">Primary Contact</h3>
            <template v-if="account.primary_contact">
              <p class="ad-strong">{{ account.primary_contact.name }}</p>
              <p class="ad-link">{{ account.primary_contact.email ?? "—" }}</p>
              <dl class="ad-mini">
                <div><dt>Phone Number</dt><dd>{{ account.primary_contact.phone ?? "—" }}</dd></div>
                <div><dt>Role</dt><dd>{{ account.primary_contact.role ?? "—" }}</dd></div>
              </dl>
            </template>
            <p v-else class="ad-none-inline">
              Nobody recorded. <button class="link-btn" @click="tab = 'contacts'">Add a contact</button>
            </p>
          </div>

          <div class="ad-card">
            <h3 class="ad-card-label">Account Summary</h3>
            <dl class="ad-mini">
              <!-- Won and pipeline are kept apart so a forecast is never read
                   as revenue. -->
              <div><dt>Account Value (won)</dt><dd>{{ money(summary?.won_value ?? null) }}</dd></div>
              <div><dt>In Pipeline</dt><dd>{{ money(summary?.pipeline_value ?? null) }}</dd></div>
              <div><dt>Contracted Value</dt><dd>{{ money(contractedValue) }}</dd></div>
              <div><dt>Total Deals</dt><dd>{{ summary?.total_deals ?? "—" }}</dd></div>
              <div><dt>Active Deals</dt><dd>{{ summary?.active_deals ?? "—" }}</dd></div>
              <div><dt>Closed Won</dt><dd>{{ summary?.closed_won ?? "—" }}</dd></div>
            </dl>
          </div>

          <div class="ad-card">
            <h3 class="ad-card-label">Pipeline Information</h3>
            <dl class="ad-mini">
              <div><dt>Lead Source</dt><dd>{{ account.lead_source ?? "—" }}</dd></div>
              <div><dt>Current Stage</dt><dd>{{ account.pipeline_stage ?? "—" }}</dd></div>
            </dl>
          </div>
        </div>

        <!-- Customer paperwork that is not tied to one contract — registration
             certificates, tax documents, the things with expiry dates. -->
        <div class="ad-card">
          <div class="ad-card-head">
            <h3 class="ad-card-label">Documents</h3>
            <label class="btn-ghost ad-upload">
              {{ uploading ? "Uploading…" : "Attach" }}
              <input type="file" hidden :disabled="uploading" @change="uploadTo(null, $event)" />
            </label>
          </div>

          <p v-if="accountAttachments.length === 0" class="ad-none-inline">Nothing filed against this customer.</p>
          <ul v-else class="ad-files">
            <li v-for="a in accountAttachments" :key="a.id" class="ad-file">
              <a :href="downloadUrl(a)" class="ad-file-name">{{ a.label }}</a>
              <span v-if="a.expiry_date" class="ad-file-meta">expires {{ date(a.expiry_date) }}</span>
              <button class="link-btn link-danger" @click="removeAttachment(a)">Remove</button>
            </li>
          </ul>
        </div>
      </section>

      <!-- ── Contacts ────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'contacts'" class="ad-panel">
        <div class="ad-panel-actions">
          <button class="btn-primary" @click="contactForm = blankContact(); showContact = true">
            + New Contact
          </button>
        </div>

        <div v-if="!account.contacts?.length" class="ad-empty">
          <h3 class="ad-empty-title">No contacts added yet</h3>
          <p class="ad-empty-sub">Start adding contacts to view them here</p>
          <button class="btn-primary" @click="contactForm = blankContact(); showContact = true">
            + New Contact
          </button>
        </div>

        <div v-else class="ad-contact-grid">
          <div v-for="c in account.contacts" :key="c.id" class="ad-card ad-contact">
            <div class="ad-contact-head">
              <h3 class="ad-card-label">{{ categoryLabel(c.category) }}</h3>
              <div class="ad-contact-tools">
                <button class="icon-btn" aria-label="Edit contact" @click="editContact(c)">✎</button>
                <button class="icon-btn icon-danger" aria-label="Delete contact" @click="contactToDelete = c">🗑</button>
              </div>
            </div>
            <p class="ad-strong">{{ c.full_name }}</p>
            <p class="ad-link">{{ c.email ?? "—" }}</p>
            <dl class="ad-mini">
              <div><dt>Phone Number</dt><dd>{{ c.phone ?? "—" }}</dd></div>
              <div><dt>Role</dt><dd>{{ c.designation ?? "—" }}</dd></div>
            </dl>
          </div>
        </div>
      </section>

      <!-- ── Account Contracts ───────────────────────────────────────── -->
      <section v-else-if="tab === 'contracts'" class="ad-panel">
        <div class="ad-panel-actions">
          <button class="btn-primary" @click="contractForm = blankContract(); showContract = true">
            + Add Contract
          </button>
        </div>

        <p v-if="!account.contracts?.length" class="ad-none">No contracts recorded.</p>

        <div v-else class="ad-card ad-table-card">
          <table class="ad-table">
            <thead>
              <tr><th>ID</th><th>Contract Name</th><th>Type</th><th class="ad-num">Value</th><th>End Date</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="c in account.contracts" :key="c.id">
                <td class="ad-ref">{{ c.reference ?? "—" }}</td>
                <td class="ad-strong-cell">{{ c.name }}</td>
                <td><span class="ad-type"><i class="ad-dot"></i>{{ titleCase(c.type) }}</span></td>
                <td class="ad-num">{{ money(c.contract_value) }}</td>
                <td>{{ date(c.end_date) }}</td>
                <td><span :class="['pill', `pill-${standing(c).tone}`]">{{ standing(c).label }}</span></td>
                <td><button class="link-btn" @click="openContract = c">View</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── Activities ──────────────────────────────────────────────── -->
      <section v-else-if="tab === 'activities'" class="ad-panel">
        <div class="ad-card">
          <h3 class="ad-card-label">Log something</h3>
          <div class="ad-log">
            <select v-model="newActivity.type" class="control ad-log-type">
              <option value="note">Note</option>
              <option value="call">Call</option>
              <option value="meeting">Meeting</option>
              <option value="email">Email</option>
              <option value="visit">Visit</option>
            </select>
            <input v-model="newActivity.body" class="control" placeholder="What happened?"
                   @keyup.enter="logActivity" />
            <button class="btn-primary" :disabled="saving || !newActivity.body.trim()" @click="logActivity">
              Log
            </button>
          </div>
          <p class="ad-hint">
            A call or meeting logged here is what moves “last contact” on the
            customer list.
          </p>
        </div>

        <p v-if="activities.length === 0" class="ad-none">Nothing recorded yet.</p>

        <ul v-else class="ad-feed">
          <li v-for="a in activities" :key="a.id" class="ad-event">
            <div class="ad-event-avatar">{{ (a.actor.name ?? "?").slice(0, 1) }}</div>
            <div class="ad-event-body">
              <p class="ad-event-who">
                <strong>{{ a.actor.name ?? "System" }}</strong>
                <span class="ad-event-when">{{ ago(a.occurred_at) }}</span>
                <span v-if="a.kind === 'system'" class="ad-event-kind">automatic</span>
              </p>
              <p class="ad-event-text">{{ a.body }}</p>
            </div>
          </li>
        </ul>
      </section>

      <!-- ── Leads ───────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'leads'" class="ad-panel">
        <div class="ad-panel-actions">
          <button class="btn-primary" @click="newLead.name = ''; showLead = true">+ New Lead</button>
        </div>

        <p v-if="leads.length === 0" class="ad-none">No leads recorded for this customer.</p>

        <div v-else class="ad-card ad-table-card">
          <table class="ad-table">
            <thead>
              <tr><th>ID</th><th>Lead Name</th><th>Source</th><th>Lead Owner</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="l in leads" :key="l.id">
                <td class="ad-ref">{{ l.reference ?? "—" }}</td>
                <td class="ad-strong-cell">{{ l.name }}</td>
                <td>{{ l.source ?? "—" }}</td>
                <td>{{ l.owner?.name ?? "—" }}</td>
                <td><span :class="['pill', `pill-${leadTone(l.status)}`]">{{ titleCase(l.status) }}</span></td>
                <td>
                  <button class="link-btn" @click="router.push({ name: 'lead', params: { id: l.id } })">View</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── Opportunities ───────────────────────────────────────────── -->
      <section v-else-if="tab === 'opportunities'" class="ad-panel">
        <p v-if="deals.length === 0" class="ad-none">
          No opportunities yet. A deal starts life as a lead and is converted from it.
        </p>

        <div v-else class="ad-card ad-table-card">
          <table class="ad-table">
            <thead>
              <tr><th>ID</th><th>Opportunity Name</th><th class="ad-num">Amount</th><th>Owner</th><th>Assignees</th><th>Phase</th><th>Score</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="o in deals" :key="o.id">
                <td class="ad-ref">{{ o.reference ?? "—" }}</td>
                <td class="ad-strong-cell">{{ o.name }}</td>
                <td class="ad-num">{{ money(o.amount) }}</td>
                <td>{{ o.owner?.name ?? "—" }}</td>
                <td>
                  <span v-if="!o.assignees?.length" class="ad-muted">—</span>
                  <span v-else class="ad-stack">
                    <span v-for="a in o.assignees.slice(0, 4)" :key="a.id" class="ad-chip"
                          :title="a.employee.name ?? ''">
                      {{ (a.employee.name ?? "?").slice(0, 1) }}
                    </span>
                    <span v-if="o.assignees.length > 4" class="ad-chip ad-chip-more">
                      +{{ o.assignees.length - 4 }}
                    </span>
                  </span>
                </td>
                <td>
                  <span :class="['pill', o.outcome === 'won' ? 'pill-ok' : o.outcome === 'lost' ? 'pill-danger' : 'pill-neutral']">
                    {{ o.phase?.name ?? "—" }}
                  </span>
                </td>
                <!-- Out of 80, and banded, because a bare number tells you
                     nothing about whether it is a good one. -->
                <td class="ad-num" :title="`${o.score} of ${o.score_max} — ${o.score_band}`">
                  <span :class="['ad-score', `score-${o.score_band}`]">{{ o.score }}</span>
                </td>
                <td>
                  <button class="link-btn" @click="router.push({ name: 'opportunity', params: { id: o.id } })">View</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── Travels ─────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'travels'" class="ad-panel">
        <p v-if="trips.length === 0" class="ad-none">No trips have been raised for this customer.</p>

        <div v-else class="ad-card ad-table-card">
          <table class="ad-table">
            <thead>
              <tr><th>Trip ID</th><th>Traveller</th><th>Role</th><th>Destination</th><th>Travel Dates</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="t in trips" :key="t.id">
                <td class="ad-ref">{{ t.reference ?? "—" }}</td>
                <td class="ad-strong-cell">{{ t.traveller ?? "—" }}</td>
                <td>{{ t.role ?? "—" }}</td>
                <td>{{ t.destination ?? "—" }}</td>
                <td>{{ date(t.departure_date) }} – {{ date(t.return_date) }}</td>
                <td><span class="pill pill-neutral">{{ titleCase(t.status) }}</span></td>
                <td>
                  <button class="link-btn" @click="router.push({ name: 'travel-request', params: { id: t.id } })">
                    View
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── Assigned Team ───────────────────────────────────────────── -->
      <section v-else-if="tab === 'team'" class="ad-panel">
        <p class="ad-note">
          Ours, not theirs. The account manager owns the relationship; these are
          the people who work it day to day.
        </p>

        <div class="ad-add-row">
          <div class="ad-add-picker">
            <EmployeePicker v-model="newMember.employee_id" placeholder="Add somebody…" />
          </div>
          <input v-model="newMember.role" class="control ad-add-role" placeholder="What they do here (optional)" />
          <button class="btn-primary" :disabled="saving || !newMember.employee_id" @click="addMember">Add</button>
        </div>

        <p v-if="!account.team?.length" class="ad-none">Nobody assigned yet.</p>

        <div v-else class="ad-card ad-table-card">
          <table class="ad-table">
            <thead><tr><th>Person</th><th>Role on this account</th><th></th></tr></thead>
            <tbody>
              <tr v-for="m in account.team" :key="m.id">
                <td class="ad-strong-cell">{{ m.employee.name ?? "—" }}</td>
                <td>{{ m.role ?? "—" }}</td>
                <td><button class="link-btn link-danger" @click="removeMember(m)">Remove</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── Not built yet ───────────────────────────────────────────── -->
      <section v-else class="ad-panel">
        <div class="ad-empty">
          <h3 class="ad-empty-title">{{ pending[tab]?.title }}</h3>
          <p class="ad-empty-sub">{{ pending[tab]?.detail }}</p>
        </div>
      </section>
    </template>

    <!-- Contract drawer -->
    <div v-if="openContract" class="ad-drawer-backdrop" @click.self="openContract = null">
      <aside class="ad-drawer">
        <div class="ad-drawer-head">
          <span class="ad-ref">{{ openContract.reference ?? "—" }}</span>
          <button class="ac-close" aria-label="Close" @click="openContract = null">×</button>
        </div>

        <button v-if="openContract.status !== 'completed'" class="btn-ghost ad-complete"
                @click="completeContract(openContract)">
          ✓ Mark as completed
        </button>

        <h3 class="ad-drawer-title">{{ openContract.name }}</h3>

        <dl class="ad-facts">
          <div><dt>Status</dt><dd><span :class="['pill', `pill-${standing(openContract).tone}`]">{{ standing(openContract).label }}</span></dd></div>
          <div><dt>Value</dt><dd>{{ money(openContract.contract_value) }}</dd></div>
          <div><dt>Start Date</dt><dd>{{ date(openContract.start_date) }}</dd></div>
          <div><dt>End Date</dt><dd>{{ date(openContract.end_date) }}</dd></div>
          <div><dt>Billing Cycle</dt><dd>{{ titleCase(openContract.billing_cycle) }}</dd></div>
          <div><dt>Owner</dt><dd>{{ openContract.owner ?? "—" }}</dd></div>
          <div><dt>Type</dt><dd>{{ titleCase(openContract.type) }}</dd></div>
          <div><dt>Project</dt><dd>{{ openContract.project_reference ?? "—" }}</dd></div>
        </dl>

        <p v-if="openContract.notes" class="ad-drawer-notes">{{ openContract.notes }}</p>

        <div class="ad-card-head">
          <h4 class="ad-card-label">Attachments</h4>
          <label class="btn-ghost ad-upload">
            {{ uploading ? "Uploading…" : "Add attachment" }}
            <input type="file" hidden :disabled="uploading" @change="uploadTo(openContract!.id, $event)" />
          </label>
        </div>

        <p v-if="!openContract.attachments?.length" class="ad-none-inline">Nothing attached.</p>
        <ul v-else class="ad-files">
          <li v-for="a in openContract.attachments" :key="a.id" class="ad-file">
            <a :href="downloadUrl(a)" class="ad-file-name">{{ a.label }}</a>
            <span class="ad-file-meta">{{ a.size_bytes ? `${Math.round(a.size_bytes / 1024)} KB` : "" }}</span>
            <button class="link-btn link-danger" @click="removeAttachment(a)">Remove</button>
          </li>
        </ul>

        <div class="ad-drawer-actions">
          <button class="btn-ghost" @click="editContract(openContract!); openContract = null">Edit</button>
          <button class="btn-ghost btn-danger" @click="removeContract(openContract!)">Remove</button>
        </div>
      </aside>
    </div>

    <!-- New lead -->
    <div v-if="showLead" class="ad-modal-backdrop" @click.self="showLead = false">
      <div class="ad-modal">
        <div class="ad-modal-head">
          <h3 class="ad-modal-title">New Lead</h3>
          <button class="ac-close" aria-label="Close" @click="showLead = false">×</button>
        </div>

        <label class="ad-field"><span>Lead name</span>
          <input v-model="newLead.name" class="control" placeholder="Integrate AI lead scoring module" /></label>

        <label class="ad-field"><span>Source</span>
          <input v-model="newLead.source" class="control" placeholder="Referral, website, LinkedIn" /></label>

        <label class="ad-field"><span>Priority</span>
          <select v-model="newLead.priority" class="control">
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
          </select></label>

        <button class="btn-primary btn-block" :disabled="saving || !newLead.name.trim()" @click="createLead">
          Add Lead
        </button>
      </div>
    </div>

    <!-- Contact dialog -->
    <div v-if="showContact" class="ad-modal-backdrop" @click.self="showContact = false">
      <div class="ad-modal">
        <div class="ad-modal-head">
          <h3 class="ad-modal-title">{{ contactForm.id ? "Edit Contact" : "Add Contact" }}</h3>
          <button class="ac-close" aria-label="Close" @click="showContact = false">×</button>
        </div>

        <label class="ad-field"><span>Contact Category</span>
          <select v-model="contactForm.category" class="control">
            <option v-for="c in CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
          </select></label>

        <label class="ad-field"><span>Contact Name</span>
          <input v-model="contactForm.name" class="control" placeholder="Angie Mumo" /></label>

        <label class="ad-field"><span>Contact Email</span>
          <input v-model="contactForm.email" class="control" type="email" placeholder="angie@kcb.co.ke" /></label>

        <label class="ad-field"><span>Phone Number</span>
          <input v-model="contactForm.phone" class="control" placeholder="+254 712 345 678" /></label>

        <label class="ad-field"><span>Role</span>
          <input v-model="contactForm.designation" class="control" placeholder="Head of IT" /></label>

        <button class="btn-primary btn-block" :disabled="saving || !contactForm.name.trim()" @click="saveContact">
          {{ contactForm.id ? "Save Changes" : "Add Contact" }}
        </button>
      </div>
    </div>

    <!-- Delete contact -->
    <div v-if="contactToDelete" class="ad-modal-backdrop" @click.self="contactToDelete = null">
      <div class="ad-modal ad-modal-sm">
        <div class="ad-warn-mark">🗑</div>
        <h3 class="ad-modal-title">Delete Contact</h3>
        <p class="ad-note">
          Are you sure you want to delete this contact? This action cannot be undone.
        </p>
        <div class="ad-modal-actions">
          <button class="btn-ghost" @click="contactToDelete = null">Cancel</button>
          <button class="btn-danger-solid" @click="removeContact">Delete</button>
        </div>
      </div>
    </div>

    <!-- Contract dialog -->
    <div v-if="showContract" class="ad-modal-backdrop" @click.self="showContract = false">
      <div class="ad-modal ad-modal-lg">
        <div class="ad-modal-head">
          <h3 class="ad-modal-title">{{ contractForm.id ? "Edit contract" : "Add Contract" }}</h3>
          <button class="ac-close" aria-label="Close" @click="showContract = false">×</button>
        </div>

        <div class="ad-grid">
          <label class="ad-field ad-span"><span>Contract name</span>
            <input v-model="contractForm.name" class="control" placeholder="Enterprise SaaS Agreement" /></label>

          <label class="ad-field"><span>Customer reference</span>
            <input v-model="contractForm.contract_number" class="control" /></label>

          <label class="ad-field"><span>Type</span>
            <select v-model="contractForm.type" class="control">
              <option value="">—</option>
              <option v-for="t in CONTRACT_TYPES" :key="t" :value="t">{{ titleCase(t) }}</option>
            </select></label>

          <label class="ad-field"><span>Value</span>
            <input v-model.number="contractForm.contract_value" class="control" type="number" step="0.01" min="0" /></label>

          <label class="ad-field"><span>Billing cycle</span>
            <select v-model="contractForm.billing_cycle" class="control">
              <option value="">—</option>
              <option v-for="b in BILLING_CYCLES" :key="b" :value="b">{{ titleCase(b) }}</option>
            </select></label>

          <label class="ad-field"><span>Status</span>
            <select v-model="contractForm.status" class="control">
              <option v-for="s in CONTRACT_STATUS" :key="s" :value="s">{{ titleCase(s) }}</option>
            </select></label>

          <label class="ad-field"><span>Owner</span>
            <input v-model="contractForm.owner" class="control" placeholder="Konvergenz Networking Solutions" /></label>

          <label class="ad-field"><span>Starts</span>
            <input v-model="contractForm.start_date" class="control" type="date" /></label>

          <label class="ad-field"><span>Ends</span>
            <input v-model="contractForm.end_date" class="control" type="date" /></label>

          <label class="ad-field ad-span"><span>Project</span>
            <input v-model="contractForm.project_reference" class="control" placeholder="Enterprise SaaS" />
            <p class="ad-hint">Recorded as written. It becomes a link once Projects exists.</p></label>

          <label class="ad-field ad-span"><span>Notes</span>
            <textarea v-model="contractForm.notes" class="control" rows="3"></textarea></label>
        </div>

        <div class="ad-modal-actions">
          <button class="btn-ghost" @click="showContract = false">Cancel</button>
          <button class="btn-primary" :disabled="saving" @click="saveContract">Save</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.ad-wrap { padding: var(--space-6); max-width: 76rem; }

.ad-crumbs { @apply flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); margin-bottom: var(--space-3); }
.ad-crumb { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: inherit; color: var(--color-text-muted); }
.ad-crumb:hover { color: var(--color-text); }
.ad-crumb-sep { color: var(--color-text-light); }
.ad-crumb-current { color: var(--color-text); }

.ad-loading { padding: var(--space-6); color: var(--color-text-muted); }
.ad-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-4); }

.ad-tabs { @apply flex flex-wrap gap-1 overflow-x-auto; border-bottom: 1px solid var(--color-border); }
.ad-tab {
  @apply cursor-pointer border-none bg-transparent;
  padding: var(--space-2) var(--space-3);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap;
}
.ad-tab:hover  { color: var(--color-text); }
.ad-tab.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }
.ad-count { margin-left: var(--space-1); font-size: var(--text-xs-size); color: var(--color-text-light); }

.ad-panel { @apply flex flex-col; padding: var(--space-5) 0; gap: var(--space-4); }
.ad-panel-actions { @apply flex justify-end; }

.ad-card {
  padding: var(--space-5);
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
.ad-table-card { padding: 0; overflow-x: auto; }
.ad-card-head { @apply flex items-center justify-between; gap: var(--space-3); margin-bottom: var(--space-3); }
.ad-card-label {
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  text-transform: uppercase; letter-spacing: 0.04em; margin: 0;
}

.ad-identity-head { @apply flex items-center; gap: var(--space-4); margin-bottom: var(--space-4); }
.ad-identity-name { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-1); }
.ad-identity-edit { margin-left: auto; }
.ad-avatar {
  @apply flex items-center justify-center shrink-0;
  width: 3rem; height: 3rem; border-radius: var(--radius-md);
  background: var(--color-brand-subtle); color: var(--color-brand-on-surface);
  font-size: var(--text-sm-size); font-weight: 600; letter-spacing: 0.02em;
}

.ad-facts { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3) var(--space-6); margin: 0; }
.ad-facts > div { @apply flex items-baseline; gap: var(--space-3); }
.ad-facts dt { font-size: var(--text-sm-size); color: var(--color-text-muted); min-width: 8.5rem; }
.ad-facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }
@media (max-width: 44rem) { .ad-facts { grid-template-columns: 1fr; } }

.ad-summary { @apply grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-4); }
@media (max-width: 56rem) { .ad-summary { grid-template-columns: 1fr; } }

.ad-mini { margin: var(--space-3) 0 0; }
.ad-mini > div { @apply flex items-baseline justify-between; gap: var(--space-3); padding: var(--space-1) 0; }
.ad-mini dt { font-size: var(--text-sm-size); color: var(--color-text-muted); }
.ad-mini dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }

.ad-strong { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ad-link   { font-size: var(--text-sm-size); color: var(--color-brand-on-surface); margin: var(--space-1) 0 0; word-break: break-all; }
.cap { text-transform: capitalize; }

.ad-contact-grid { @apply grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-4); }
@media (max-width: 56rem) { .ad-contact-grid { grid-template-columns: 1fr; } }
.ad-contact-head { @apply flex items-start justify-between; gap: var(--space-2); margin-bottom: var(--space-3); }
.ad-contact-tools { @apply flex; gap: var(--space-2); }
.icon-btn { @apply cursor-pointer border-none bg-transparent p-0; font-size: var(--text-sm-size); color: var(--color-text-muted); }
.icon-btn:hover { color: var(--color-text); }
.icon-danger:hover { color: var(--color-danger-text); }

.ad-empty { @apply flex flex-col items-center; padding: var(--space-8) var(--space-6); text-align: center; gap: var(--space-2); }
.ad-empty-title { font-size: var(--text-base-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ad-empty-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0 0 var(--space-3); max-width: 52ch; }

.ad-grid { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4); }
.ad-span { grid-column: 1 / -1; }
@media (max-width: 40rem) { .ad-grid { grid-template-columns: 1fr; } }

.ad-field { @apply flex flex-col; gap: var(--space-1); }
.ad-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.ad-check { @apply flex-row items-center; gap: var(--space-2); align-self: end; padding-bottom: 8px; }
.ad-check span { font-size: var(--text-sm-size); color: var(--color-text); }
.ad-hint { font-size: var(--text-xs-size); color: var(--color-text-light); margin: var(--space-1) 0 0; }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }
.control:disabled { @apply cursor-not-allowed; background: var(--color-surface-sunken); color: var(--color-text-light); }

.ad-actions { @apply flex justify-end; margin-top: var(--space-5); }

.ad-add-row { @apply flex flex-wrap items-start; gap: var(--space-3); }
.ad-add-picker { flex: 1; min-width: 16rem; }
.ad-add-role { max-width: 18rem; }

.ad-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.ad-table th {
  text-align: left; padding: var(--space-3) var(--space-4);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border); white-space: nowrap;
}
.ad-table td { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.ad-table tr:last-child td { border-bottom: none; }
.ad-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.ad-ref { font-variant-numeric: tabular-nums; color: var(--color-text-muted); }
.ad-strong-cell { font-weight: 500; }
.ad-type { @apply inline-flex items-center; gap: var(--space-2); white-space: nowrap; }
.ad-dot { @apply inline-block; width: 6px; height: 6px; border-radius: 999px; background: var(--color-brand-base); }
.ad-none { font-size: var(--text-sm-size); color: var(--color-text-light); padding: var(--space-5) 0; }
.ad-muted { color: var(--color-text-light); }

/* Assignees, as a stack — the names matter less than how many hands are on it. */
.ad-stack { @apply inline-flex items-center; }
.ad-chip {
  @apply inline-flex items-center justify-center;
  width: 1.5rem; height: 1.5rem; margin-right: -0.35rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  border: 2px solid var(--color-surface-raised);
  font-size: 0.65rem; font-weight: 600; text-transform: uppercase;
}
.ad-chip-more { background: var(--color-brand-subtle); color: var(--color-brand-on-surface); }

.ad-score { font-weight: 600; }
.score-strong     { color: var(--color-success-text); }
.score-promising  { color: var(--color-brand-on-surface); }
.score-developing { color: var(--color-warning-text); }
.score-weak       { color: var(--color-text-light); }
.ad-none-inline { font-size: var(--text-sm-size); color: var(--color-text-light); margin: 0; }
.ad-note { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; }

.ad-log { @apply flex flex-wrap; gap: var(--space-2); }
.ad-log .control { flex: 1; min-width: 12rem; }
.ad-log-type { max-width: 9rem; flex: 0 0 auto; }

.ad-feed { @apply list-none; margin: 0; padding: 0; }
.ad-event { @apply flex; gap: var(--space-3); padding: var(--space-3) 0; border-bottom: 1px solid var(--color-border); }
.ad-event:last-child { border-bottom: none; }
.ad-event-avatar {
  @apply flex shrink-0 items-center justify-center;
  width: 2rem; height: 2rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: var(--text-xs-size); font-weight: 600; text-transform: uppercase;
}
.ad-event-body { flex: 1; }
.ad-event-who { @apply flex flex-wrap items-baseline; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }
.ad-event-when { font-size: var(--text-xs-size); color: var(--color-text-light); }
.ad-event-kind { font-size: var(--text-xs-size); color: var(--color-text-light); font-style: italic; }
.ad-event-text { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

.ad-files { @apply list-none; margin: 0; padding: 0; }
.ad-file { @apply flex flex-wrap items-center; gap: var(--space-3); padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border); }
.ad-file:last-child { border-bottom: none; }
.ad-file-name { flex: 1; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.ad-file-meta { font-size: var(--text-xs-size); color: var(--color-text-light); }
.ad-upload { @apply cursor-pointer; }

.pill { display: inline-block; padding: 2px var(--space-3); border-radius: 999px; font-size: var(--text-xs-size); text-transform: capitalize; border: 1px solid transparent; white-space: nowrap; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 30%, transparent); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); border-color: color-mix(in oklab, var(--color-warning-text) 30%, transparent); }
.pill-danger  { background: var(--color-danger-subtle);  color: var(--color-danger-text);  border-color: color-mix(in oklab, var(--color-danger-text) 30%, transparent); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); border-color: var(--color-border); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }
.link-danger { color: var(--color-danger-text); }

.ad-modal-backdrop { @apply fixed inset-0 flex items-center justify-center z-50; background: rgb(0 0 0 / 0.45); padding: var(--space-4); }
.ad-modal {
  @apply flex flex-col w-full overflow-y-auto; max-width: 26rem; max-height: 90vh; gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised);
  border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
}
.ad-modal-lg { max-width: 36rem; }
.ad-modal-sm { max-width: 22rem; }
.ad-modal-head { @apply flex items-center justify-between; }
.ad-modal-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.ad-modal-actions { @apply flex justify-end; gap: var(--space-2); }
.ac-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }
.ad-warn-mark {
  @apply flex items-center justify-center;
  width: 2.5rem; height: 2.5rem; border-radius: 999px;
  background: var(--color-danger-subtle); font-size: var(--text-base-size);
}

.ad-drawer-backdrop { @apply fixed inset-0 flex justify-end z-50; background: rgb(0 0 0 / 0.35); }
.ad-drawer {
  @apply flex flex-col overflow-y-auto; width: min(30rem, 100%); gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised);
  box-shadow: var(--shadow-lg);
}
.ad-drawer-head { @apply flex items-center justify-between; }
.ad-drawer-title { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ad-drawer-notes { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; }
.ad-drawer-actions { @apply flex justify-end; gap: var(--space-2); margin-top: auto; padding-top: var(--space-4); }
.ad-complete { align-self: flex-start; }

.btn-primary, .btn-ghost, .btn-danger-solid {
  @apply inline-flex cursor-pointer items-center justify-center;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-ghost   { background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-danger  { color: var(--color-danger-text); border-color: color-mix(in oklab, var(--color-danger-text) 40%, transparent); }
.btn-danger-solid { @apply border-none; background: var(--color-danger-text); color: var(--color-on-brand); }
.btn-block   { width: 100%; }
.btn-primary:disabled, .btn-ghost:disabled { @apply cursor-not-allowed opacity-50; }

</style>
