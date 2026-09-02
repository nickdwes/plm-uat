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
import { canAny } from "../../stores/permissions";
import { notifySaved, notifyFailed } from "../../lib/toast";
import EmployeePicker from "../../components/EmployeePicker.vue";

const props = defineProps<{ id: string | number }>();

interface Named { id: number; name: string }
interface Contact {
  id: number; category: string; title: string | null; first_name: string; last_name: string;
  full_name: string; designation: string | null; department: string | null;
  email: string | null; phone: string | null; is_primary: boolean; is_active: boolean;
  /** False = email/phone arrived masked (caller lacks accounts.view_contacts). */
  contacts_visible?: boolean;
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
  commercials_visible: boolean;
  total_deals: number; active_deals: number; closed_won: number; closed_lost: number;
  won_value: number | null; pipeline_value: number | null; open_leads: number;
  won_value_last_year: number | null; won_value_this_year: number | null;
  renewal: { date: string; days_out: number; name: string; value: number | null } | null;
  last_contact: { at: string | null; type: string | null; person: string | null } | null;
  contracts: { contracted_value: number | null; active: number; total: number; next_expiry: string | null };
  counts: Record<string, number>;
}
interface Account {
  id: number; reference: string | null; name: string; initials: string; logo_url: string | null; status: string;
  country: string | null; city: string | null; physical_location: string | null; tax_pin: string | null;
  vat_registered: boolean; enrolled_on: string | null; last_contact_at: string | null;
  number_of_branches: number | null; number_of_employees: number | null;
  fund_source: string | null; lead_source: string | null; pipeline_stage: string | null;
  details: string | null;
  account_manager?: { id: number | null; name: string | null };
  type?: Named | null; sector?: Named | null; subsector?: Named | null;
  primary_contact?: { id: number; name: string; email: string | null; phone: string | null; role: string | null } | null;
  contacts?: Contact[]; contracts?: Contract[]; team?: TeamMember[]; attachments?: Attachment[];
  is_following?: boolean;
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

/*
 * A tab's permission is the permission its API route checks — the invariant
 * that keeps a hidden tab and a refused endpoint the same rule. Tabs with no
 * entry belong to this module: reaching the customer at all already proved
 * `accounts.view`/`view_own`, so Overview, Contacts, Contracts, Activities
 * and Team ask nothing further. The cross-module tabs ask what their own
 * module's list endpoints ask.
 */
const TAB_PERMISSIONS: Partial<Record<Tab, string[]>> = {
  leads:         ["pipeline.view", "pipeline.view_team", "pipeline.view_own"],
  opportunities: ["pipeline.view", "pipeline.view_own"],
  projects:      ["projects.view", "projects.view_own"],
  support:       ["support.view", "support.view_own"],
  travels:       ["travel.view", "travel.view_own"],
};

const ALL_TABS: { key: Tab; label: string }[] = [
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

const tabs = computed(() =>
  ALL_TABS.filter((t) => {
    const required = TAB_PERMISSIONS[t.key];
    return !required || canAny(...required);
  }));

/*
 * Every tab is built now — the `pending` map that named what each unbuilt one
 * was waiting on is gone. Projects and Support carried "not built yet" long
 * after both modules shipped and both started naming an account: one customer
 * alone had 13 projects and 39 tickets sitting behind those two placeholders.
 */

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

// The client's logo — click the identity badge to add or replace it.
const logoInput = ref<HTMLInputElement | null>(null);
async function onLogoPicked(e: Event) {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  input.value = "";
  if (!file || !account.value) return;
  const fd = new FormData();
  fd.append("logo", file);
  try {
    const res = await api<{ data: Account }>("POST", `${base.value}/logo`, { org: org(), formData: fd });
    account.value = res.data;
    flash("Logo updated.");
  } catch (e) {
    notifyFailed("Could not upload the logo.", e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
  }
}

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
  if (t === "projects" && projects.value.length === 0) void loadProjects();
  if (t === "support" && tickets.value.length === 0) void loadTickets();
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

// Editing a card whose reach fields arrived masked: the server ignores
// email/phone from this caller (it must — the values ARE the mask), and the
// dialog locks those inputs so nobody wonders why their edit didn't take.
const editingMaskedContact = ref(false);

function editContact(c: Contact) {
  editingMaskedContact.value = c.contacts_visible === false;
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

/** "Client since Jul 2023" — the year matters, the day does not. */
const monthYear = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { month: "short", year: "numeric" }) : "—";

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


const accountAttachments = computed(() =>
  (account.value?.attachments ?? []).filter((a) => a.account_contract_id === null),
);

/*
 * Tab badges, counted server-side.
 *
 * They used to come from whichever collections happened to be loaded, so a tab
 * you had not opened had no number and one you had showed the page size rather
 * than the total. Counting them in the summary means every badge is the real
 * figure from first paint.
 */
const countFor = (t: Tab) => summary.value?.counts?.[t] || undefined;

// ── The header's four figures ───────────────────────────────────────────────

/** Abbreviated for the tiles; tables keep the full figure. */
function short(v: number | null | undefined): string {
  if (v === null || v === undefined) return "—";
  const abs = Math.abs(v);
  if (abs >= 1_000_000_000) return `KES ${(v / 1_000_000_000).toFixed(2)}B`;
  if (abs >= 1_000_000)     return `KES ${(v / 1_000_000).toFixed(2)}M`;
  if (abs >= 1_000)         return `KES ${(v / 1_000).toFixed(1)}K`;
  return `KES ${v.toFixed(0)}`;
}

/**
 * Won this year against won last year.
 *
 * Null rather than 0% when last year was nothing: everything is up infinitely
 * on nothing, and an arrow claiming so is worse than no arrow.
 */
const wonDelta = computed(() => {
  const now = summary.value?.won_value_this_year;
  const then = summary.value?.won_value_last_year;
  if (now === null || now === undefined || !then) return null;
  return Math.round(((now - then) / then) * 100);
});

/** How the renewal reads, and whether it is close enough to worry about. */
const renewalSoon = computed(() => {
  const days = summary.value?.renewal?.days_out;
  return days !== undefined && days !== null && days <= 60;
});

/*
 * The open deals, sized against each other for the stacked bar.
 *
 * Share is by value where values exist and evenly otherwise — a bar drawn from
 * amounts that are all null is three invisible segments, which reads as no
 * pipeline rather than as pipeline nobody has priced.
 */
const STAGE_COLOURS = ["var(--color-brand-base)", "var(--color-brand-hover)", "var(--color-teal)",
                       "var(--color-amber)", "var(--color-brand-light)"];

const PIPELINE_SHOWN = 5;

const openDeals = computed(() => {
  // Biggest first, capped: this is the summary of a pipeline, and "View all"
  // beside it goes to the tab that is the whole of it.
  const open = deals.value
    .filter((d) => d.is_open)
    .sort((a, b) => Number(b.amount ?? 0) - Number(a.amount ?? 0))
    .slice(0, PIPELINE_SHOWN);

  const total = open.reduce((sum, d) => sum + Number(d.amount ?? 0), 0);

  return open.map((d, i) => ({
    ...d,
    share: total > 0 ? (Number(d.amount ?? 0) / total) * 100 : 100 / Math.max(open.length, 1),
    colour: STAGE_COLOURS[i % STAGE_COLOURS.length],
  }));
});

/** How many open deals there are in total, for the "View all" link. */
const openCount = computed(() => deals.value.filter((d) => d.is_open).length);

/** A deal's strength as a percentage of what it could score. */
const strengthPct = (d: Opportunity) =>
  d.score_max ? Math.round((d.score / d.score_max) * 100) : 0;

/** The newest four. The Activities tab holds the rest. */
const recentActivities = computed(() => activities.value.slice(0, 4));

/** Contracts actually running, soonest expiry first. */
const activeContracts = computed(() =>
  (account.value?.contracts ?? [])
    .filter((c) => c.status === "active" && !c.completed_at)
    .sort((a, b) => a.end_date.localeCompare(b.end_date))
    .slice(0, 5));

/** Initials from a name, for the contact avatar. */
const initialsOf = (name: string) =>
  name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase() ?? "").join("");

interface ProjectRow {
  id: number; reference: string | null; name: string; status: string; rag: string | null;
  deadline: string | null; delivered_on: string | null; lead: string | null;
  tasks_total: number; tasks_done: number; progress: number | null;
}
interface TicketRow {
  id: number; reference: string; subject: string; status: string | null; state: string | null;
  priority: string | null; assignee: string | null; updated_at: string | null; breaching: boolean;
}
interface SupportStats {
  open: number; breaching: number; avg_response_minutes: number | null;
  satisfaction: number | null; rated: number; responded: number;
}

const projects = ref<ProjectRow[]>([]);
const tickets  = ref<TicketRow[]>([]);
const support  = ref<SupportStats | null>(null);

async function loadProjects() {
  try {
    projects.value = (await api<{ data: ProjectRow[] }>("GET", `${base.value}/projects`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load projects for this customer."); }
}

async function loadTickets() {
  try {
    const res = await api<{ data: TicketRow[]; stats: SupportStats }>(
      "GET", `${base.value}/support`, { org: org() });
    tickets.value = res.data;
    support.value = res.stats;
  } catch (e) { fail(e, "Could not load support tickets for this customer."); }
}

/** "2h 14m" reads as a response time; 134 does not. */
function duration(minutes: number | null): string {
  if (minutes === null) return "—";
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return h ? `${h}h ${m}m` : `${m}m`;
}

/** Health from the project's own RAG where set, else from its progress. */
function projectPill(p: ProjectRow): string {
  if (p.delivered_on) return "pill-info";
  if (p.rag === "red") return "pill-danger";
  if (p.rag === "amber") return "pill-warn";
  if (p.rag === "green") return "pill-ok";
  return "pill-neutral";
}

const PRIORITY_CLASS: Record<string, string> = {
  Critical: "crit", High: "high", Medium: "med", Low: "low",
};

const followBusy = ref(false);

/** A personal bookmark. Optimistic, because it is one bit and cheap to undo. */
async function toggleFollow() {
  if (!account.value || followBusy.value) return;
  const wanted = !account.value.is_following;
  followBusy.value = true;
  account.value.is_following = wanted;
  try {
    await api(wanted ? "POST" : "DELETE", `${base.value}/follow`, { org: org() });
  } catch (e) {
    account.value.is_following = !wanted;
    notifyFailed("Could not change that.", e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
  } finally { followBusy.value = false; }
}

/*
 * The Overview shows the pipeline and the newest activity, so both are fetched
 * on load rather than waiting for their tab. Everything else stays lazy.
 */
onMounted(() => { void load(); void loadSummary(); void loadDeals(); void loadActivities(); });
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
      <!-- ── Header band ──────────────────────────────────────────────────
           Identity and the four figures somebody opens this record to check.
           The old page led with a title and then eight equally-weighted rows,
           most of them em-dashes; you could not tell a healthy account from a
           dormant one without reading all of it. -->
      <header class="ad-band">
        <div class="ad-band-top">
          <button type="button" class="ad-logo" :class="{ empty: !account.logo_url }"
                  :title="account.logo_url ? 'Change logo' : 'Add a logo'" @click="logoInput?.click()">
            <img v-if="account.logo_url" :src="account.logo_url" alt="" class="ad-logo-img" />
            <span v-else>{{ account.initials }}</span>
            <span class="ad-logo-edit" aria-hidden="true">+</span>
          </button>
          <input ref="logoInput" type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp"
                 class="ad-hidden-file" @change="onLogoPicked" />

          <div class="ad-band-id">
            <div class="ad-band-name">
              <h1 class="ad-title">{{ account.name }}</h1>
              <span :class="['pill', account.status === 'active' ? 'pill-ok'
                             : account.status === 'inactive' ? 'pill-neutral' : 'pill-warn']">
                <span v-if="account.status === 'active'" class="ad-status-dot" aria-hidden="true"></span>{{ titleCase(account.status) }}
              </span>
            </div>

            <!-- Only what is actually known. A meta line of four separators
                 with nothing between them is worse than a short one. -->
            <p class="ad-band-meta">
              <template v-if="account.sector?.name"><span>{{ account.sector.name }}</span></template>
              <template v-if="account.physical_location || account.city">
                <span>{{ [account.city, account.physical_location].filter(Boolean).join(", ") }}</span>
              </template>
              <template v-if="account.enrolled_on"><span>Client since {{ monthYear(account.enrolled_on) }}</span></template>
              <template v-if="account.account_manager?.name">
                <span>Owner <strong>{{ account.account_manager.name }}</strong></span>
              </template>
              <span v-if="!account.sector?.name && !account.account_manager?.name" class="ad-band-thin">
                Industry and owner not set — <button class="link-btn" @click="editing = true">fill them in</button>
              </span>
            </p>
          </div>

          <div class="ad-band-actions">
            <button class="btn-ghost" :disabled="followBusy" @click="toggleFollow">
              {{ account.is_following ? "★ Following" : "☆ Follow" }}
            </button>
            <button class="btn-ghost" @click="editing = !editing">{{ editing ? "Cancel" : "Edit" }}</button>
          </div>
        </div>

        <!-- KPI strip: hairline-separated cells, per the handoff. -->
        <div class="ad-kpis">
          <div class="ad-kpi">
            <span class="ad-kpi-label">Closed won (lifetime)</span>
            <span class="ad-kpi-value">{{ summary?.commercials_visible === false ? "—" : short(summary?.won_value) }}</span>
            <span v-if="wonDelta !== null" :class="['ad-kpi-sub', wonDelta >= 0 ? 'up' : 'down']">
              {{ wonDelta >= 0 ? "↑" : "↓" }} {{ Math.abs(wonDelta) }}% vs last year
            </span>
            <span v-else-if="summary?.commercials_visible === false" class="ad-kpi-sub">Not shown to you</span>
            <span v-else class="ad-kpi-sub">{{ summary?.closed_won ?? 0 }} deals won</span>
          </div>

          <div class="ad-kpi">
            <span class="ad-kpi-label">Open pipeline</span>
            <span class="ad-kpi-value">{{ summary?.commercials_visible === false ? "—" : short(summary?.pipeline_value) }}</span>
            <span class="ad-kpi-sub">{{ summary?.active_deals ?? 0 }} active deals</span>
          </div>

          <div class="ad-kpi">
            <span class="ad-kpi-label">Renewal date</span>
            <span class="ad-kpi-value">{{ summary?.renewal ? date(summary.renewal.date) : "None due" }}</span>
            <span v-if="summary?.renewal" :class="['ad-kpi-sub', { warn: renewalSoon }]">
              {{ summary.renewal.days_out }} days out
            </span>
            <span v-else class="ad-kpi-sub">No contract waiting to renew</span>
          </div>

          <div class="ad-kpi">
            <span class="ad-kpi-label">Last contact</span>
            <span class="ad-kpi-value">{{ summary?.last_contact?.at ? ago(summary.last_contact.at) : "Never" }}</span>
            <span v-if="summary?.last_contact" class="ad-kpi-sub">
              {{ [titleCase(summary.last_contact.type), summary.last_contact.person].filter(Boolean).join(" · ") }}
            </span>
            <!-- Nothing recorded is a thing to do, not a blank. -->
            <span v-else class="ad-kpi-sub">
              <button class="link-btn" @click="tab = 'activities'">Log an activity</button>
            </span>
          </div>
        </div>
      </header>

      <nav class="ad-tabs" role="tablist">
        <button v-for="t in tabs" :key="t.key" role="tab" :aria-selected="tab === t.key"
                :class="['ad-tab', { active: tab === t.key }]" @click="tab = t.key">
          {{ t.label }}<span v-if="countFor(t.key)" class="ad-count">{{ countFor(t.key) }}</span>
        </button>
      </nav>

      <!-- ── Overview ────────────────────────────────────────────────── -->
      <!-- ── Overview ─────────────────────────────────────────────────────
           Two columns: what the account is and what it is doing on the left,
           who to call and what needs attention on the right. -->
      <section v-if="tab === 'overview'" class="ad-panel">
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
        <div class="ad-two">
          <!-- ── Left ───────────────────────────────────────────────── -->
          <div class="ad-col">
            <!-- Pipeline: the deals themselves, not a count of them. -->
            <div class="ad-card">
              <div class="ad-card-head">
                <h3 class="ad-card-label">Pipeline</h3>
                <button v-if="deals.length" class="link-btn" @click="tab = 'opportunities'">
                  View all {{ deals.length }}
                </button>
              </div>

              <p v-if="!openCount" class="ad-none-inline">
                No deals open against this customer.
              </p>

              <template v-else>
                <div class="ad-stagebar" role="img" :aria-label="`Largest ${openDeals.length} of ${openCount} open deals by value`">
                  <span v-for="d in openDeals" :key="d.id" class="ad-stagebar-seg"
                        :style="{ width: `${d.share}%`, background: d.colour }"></span>
                </div>

                <ul class="ad-deals">
                  <li v-for="d in openDeals" :key="d.id" class="ad-deal"
                      @click="router.push({ name: 'opportunity', params: { id: d.id } })">
                    <span class="ad-deal-main">
                      <span class="ad-deal-name">{{ d.name }}</span>
                      <span class="ad-deal-sub">
                        {{ d.phase?.name ?? `${titleCase(d.score_band)} strength` }}
                      </span>
                    </span>
                    <span class="ad-deal-value">{{ money(d.amount) }}</span>
                    <!-- Strength, not probability: the app has no probability
                         field, and inventing one from the stage would be a
                         number nobody could account for. -->
                    <span class="ad-deal-bar" :title="`Strength ${d.score} of ${d.score_max}`">
                      <span class="ad-deal-track">
                        <span class="ad-deal-fill" :style="{ width: `${strengthPct(d)}%` }"></span>
                      </span>
                      <span class="ad-deal-pct">{{ strengthPct(d) }}%</span>
                    </span>
                  </li>
                </ul>

                <!-- No silent truncation: the card says what it is not
                     showing, and the link goes to the rest. -->
                <p v-if="openCount > openDeals.length" class="ad-hint ad-more">
                  Showing the largest {{ openDeals.length }} of {{ openCount }} open deals.
                </p>
              </template>
            </div>

            <!-- Account details -->
            <div class="ad-card">
              <div class="ad-card-head">
                <h3 class="ad-card-label">Account details</h3>
                <button class="link-btn" @click="editing = !editing">{{ editing ? "Cancel" : "Edit" }}</button>
              </div>

              <!-- Inside this card an em-dash is honest: the row still says
                   which field is unfilled, which a missing row would not. -->
              <dl v-if="!editing" class="ad-facts">
                <div><dt>Client ID</dt><dd>{{ account.reference ?? "—" }}</dd></div>
                <!-- The design calls this Industry; it is the sector we already
                     hold, so there is one answer rather than two. -->
                <div><dt>Industry</dt><dd>{{ account.sector?.name ?? "—" }}</dd></div>
                <div><dt>Customer type</dt><dd>{{ account.type?.name ?? "—" }}</dd></div>
                <div><dt>Employees</dt><dd>{{ account.number_of_employees?.toLocaleString() ?? "—" }}</dd></div>
                <div><dt>Contact person</dt><dd>{{ account.primary_contact?.name ?? "—" }}</dd></div>
                <div><dt>Enrolled on</dt><dd>{{ date(account.enrolled_on) }}</dd></div>
                <div><dt>Account manager</dt><dd>{{ account.account_manager?.name ?? "—" }}</dd></div>
                <div><dt>Lead source</dt><dd>{{ account.lead_source ?? "—" }}</dd></div>
              </dl>
            </div>


            <!-- Recent activity -->
            <div class="ad-card">
              <div class="ad-card-head">
                <h3 class="ad-card-label">Recent activity</h3>
                <button class="link-btn" @click="tab = 'activities'">Log activity</button>
              </div>

              <p v-if="!recentActivities.length" class="ad-none-inline">
                Nothing logged yet.
                <button class="link-btn" @click="tab = 'activities'">Record a call or a meeting</button>
              </p>

              <ol v-else class="ad-timeline">
                <li v-for="(a, i) in recentActivities" :key="a.id" class="ad-tl">
                  <span :class="['ad-tl-dot', { newest: i === 0 }]" aria-hidden="true"></span>
                  <span class="ad-tl-body">
                    <span class="ad-tl-text"><strong>{{ titleCase(a.type) }}</strong> — {{ a.body }}</span>
                    <span class="ad-tl-meta">
                      {{ date(a.occurred_at) }}<template v-if="a.actor?.name"> · {{ a.actor.name }}</template>
                    </span>
                  </span>
                </li>
              </ol>
            </div>
          </div>

          <!-- ── Right ──────────────────────────────────────────────── -->
          <div class="ad-col">
            <!-- Primary contact -->
            <div class="ad-card">
              <div class="ad-card-head">
                <h3 class="ad-card-label">Primary contact</h3>
                <button v-if="account.contacts?.length" class="link-btn" @click="tab = 'contacts'">
                  All {{ account.contacts.length }}
                </button>
              </div>

              <template v-if="account.primary_contact">
                <div class="ad-primary">
                  <span class="ad-contact-avatar">{{ initialsOf(account.primary_contact.name) }}</span>
                  <span class="ad-contact-id">
                    <span class="ad-strong">{{ account.primary_contact.name }}</span>
                    <span class="ad-contact-role">{{ account.primary_contact.role ?? "Contact" }}</span>
                  </span>
                </div>

                <dl class="ad-mini">
                  <div><dt>Email</dt>
                    <dd><a v-if="account.primary_contact.email" :href="`mailto:${account.primary_contact.email}`"
                           class="link-btn">{{ account.primary_contact.email }}</a><span v-else>—</span></dd></div>
                  <div><dt>Phone</dt><dd>{{ account.primary_contact.phone ?? "—" }}</dd></div>
                </dl>

                <div class="ad-contact-actions">
                  <a class="btn-primary ad-half" :href="account.primary_contact.email ? `mailto:${account.primary_contact.email}` : undefined"
                     :aria-disabled="!account.primary_contact.email">Email</a>
                  <a class="btn-ghost ad-half" :href="account.primary_contact.phone ? `tel:${account.primary_contact.phone}` : undefined"
                     :aria-disabled="!account.primary_contact.phone">Call</a>
                </div>
              </template>

              <!-- Never an em-dash here: the card says what is missing and
                   offers the action instead. -->
              <div v-else class="ad-none-block">
                <p class="ad-none-inline">No primary contact set.</p>
                <button class="btn-primary" @click="tab = 'contacts'">Add contact</button>
              </div>
            </div>

            <!-- Renewal alert. Shown only when one is actually close. -->
            <div v-if="summary?.renewal && renewalSoon" class="ad-alert">
              <p class="ad-alert-title">
                <span class="ad-alert-dot" aria-hidden="true"></span>
                Renewal in {{ summary.renewal.days_out }} days
              </p>
              <p class="ad-alert-body">
                {{ summary.renewal.name }} ends {{ date(summary.renewal.date) }}.
              </p>
              <div class="ad-alert-actions">
                <button class="ad-alert-btn" @click="tab = 'contracts'">Open the contract</button>
              </div>
            </div>

            <!-- Active contracts, the thing the renewal date comes from. -->
            <div class="ad-card">
              <div class="ad-card-head">
                <h3 class="ad-card-label">Active contracts</h3>
                <button v-if="account.contracts?.length" class="link-btn" @click="tab = 'contracts'">
                  All {{ account.contracts.length }}
                </button>
              </div>

              <p v-if="!activeContracts.length" class="ad-none-inline">Nothing running right now.</p>
              <ul v-else class="ad-rows">
                <li v-for="c in activeContracts" :key="c.id" class="ad-row">
                  <span class="ad-row-name">{{ c.name }}</span>
                  <span class="ad-row-meta">exp. {{ date(c.end_date) }}</span>
                </li>
              </ul>
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
          </div>
        </div>
      </section>

      <!-- ── Contacts ────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'contacts'" class="ad-panel">
        <div class="ad-panel-actions">
          <button class="btn-primary" @click="contactForm = blankContact(); editingMaskedContact = false; showContact = true">
            + New Contact
          </button>
        </div>

        <div v-if="!account.contacts?.length" class="ad-empty">
          <h3 class="ad-empty-title">No contacts added yet</h3>
          <p class="ad-empty-sub">Start adding contacts to view them here</p>
          <button class="btn-primary" @click="contactForm = blankContact(); editingMaskedContact = false; showContact = true">
            + New Contact
          </button>
        </div>

        <!-- A table, not cards. Four people with the same five fields are
             read down a column, not hunted for across four boxes — and a card
             narrow enough to fit three across is narrow enough to break an
             email address one character per line. -->
        <div v-else class="ad-card ad-table-card">
          <table class="ad-table">
            <thead>
              <tr>
                <th>Name</th><th>Role</th><th>Email</th><th>Phone</th><th>Type</th><th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in account.contacts" :key="c.id">
                <td>
                  <span class="ad-person">
                    <span class="ad-person-avatar">{{ initialsOf(c.full_name) }}</span>
                    <span class="ad-person-id">
                      <span class="ad-person-name">{{ c.full_name }}</span>
                      <!-- The one flag worth carrying into the row, because it
                           decides who the Overview shows. -->
                      <span v-if="c.is_primary" class="ad-person-flag">Primary</span>
                    </span>
                  </span>
                </td>
                <td class="ad-cell-wrap">{{ c.designation ?? "—" }}</td>
                <td>
                  <a v-if="c.email && c.contacts_visible !== false" :href="`mailto:${c.email}`"
                     class="link-btn ad-truncate" :title="c.email">{{ c.email }}</a>
                  <span v-else-if="c.email" class="ad-truncate" title="Hidden — your role does not include customer contact details">{{ c.email }}</span>
                  <span v-else>—</span>
                </td>
                <td class="ad-nowrap">
                  <a v-if="c.phone && c.contacts_visible !== false" :href="`tel:${c.phone}`" class="link-btn">{{ c.phone }}</a>
                  <span v-else-if="c.phone">{{ c.phone }}</span>
                  <span v-else>—</span>
                </td>
                <td class="ad-muted-cell">{{ categoryLabel(c.category) }}</td>
                <td class="ad-row-tools">
                  <button class="icon-btn" aria-label="Edit contact" @click="editContact(c)">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                      <path d="M4 16h3l8-8-3-3-8 8v3Z" /><path d="M12 5l3 3" />
                    </svg>
                  </button>
                  <button class="icon-btn icon-danger" aria-label="Delete contact" @click="contactToDelete = c">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                      <path d="M4 6h12M8 6V4h4v2M6 6l1 10h6l1-10M9 9v5M11 9v5" />
                    </svg>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
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
                <td>
                  <span class="ad-person">
                    <span class="ad-person-avatar">{{ initialsOf(m.employee.name ?? "?") }}</span>
                    <span class="ad-person-name">{{ m.employee.name ?? "—" }}</span>
                  </span>
                </td>
                <td>{{ m.role ?? "—" }}</td>
                <td><button class="link-btn link-danger" @click="removeMember(m)">Remove</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── Projects ────────────────────────────────────────────────────
           A card each rather than a table: a project is read for its state,
           not compared column by column against its siblings. -->
      <section v-else-if="tab === 'projects'" class="ad-panel">
        <p v-if="!projects.length" class="ad-none-inline">
          No project has been raised for this customer.
        </p>

        <div v-else class="ad-cards">
          <article v-for="p in projects" :key="p.id" class="ad-project"
                   @click="router.push({ name: 'project', params: { id: p.id } })">
            <div class="ad-project-head">
              <div class="ad-project-id">
                <h4 class="ad-project-name">{{ p.name }}</h4>
                <p class="ad-project-sub">
                  <span v-if="p.reference" class="ad-ref">{{ p.reference }}</span>
                  <template v-if="p.reference && p.lead"> · </template>
                  <template v-if="p.lead">{{ p.lead }}</template>
                  <template v-if="!p.reference && !p.lead">No lead assigned</template>
                </p>
              </div>
              <span :class="['pill', projectPill(p)]">
                {{ p.delivered_on ? "Delivered" : titleCase(p.status) }}
              </span>
            </div>

            <div class="ad-project-progress">
              <span class="ad-deal-track">
                <span class="ad-deal-fill" :style="{ width: `${p.progress ?? 0}%` }"></span>
              </span>
              <!-- Null, not 0%: a project nobody has planned is not one that
                   has done nothing. -->
              <span class="ad-deal-pct">{{ p.progress === null ? "—" : `${p.progress}%` }}</span>
            </div>

            <div class="ad-project-foot">
              <span>{{ p.deadline ? `Due ${date(p.deadline)}` : "No deadline" }}</span>
              <span>{{ p.tasks_total ? `${p.tasks_done} / ${p.tasks_total} tasks` : "No tasks yet" }}</span>
            </div>
          </article>
        </div>
      </section>

      <!-- ── Support ─────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'support'" class="ad-panel">
        <div v-if="support" class="ad-stats">
          <div class="ad-stat">
            <span class="ad-kpi-label">Open</span>
            <span class="ad-stat-value">{{ support.open }}</span>
          </div>
          <div class="ad-stat">
            <span class="ad-kpi-label">Breaching SLA</span>
            <span :class="['ad-stat-value', { bad: support.breaching > 0 }]">{{ support.breaching }}</span>
          </div>
          <div class="ad-stat">
            <span class="ad-kpi-label">Avg. first response</span>
            <span class="ad-stat-value">{{ duration(support.avg_response_minutes) }}</span>
            <!-- An average of three is not the same claim as an average of
                 three hundred, and the imported tickets stamped at the moment
                 of raising are excluded from it. -->
            <span v-if="support.responded" class="ad-kpi-sub">from {{ support.responded }} measured</span>
            <span v-else class="ad-kpi-sub">none measured</span>
          </div>
          <div class="ad-stat">
            <span class="ad-kpi-label">Satisfaction</span>
            <span :class="['ad-stat-value', { good: (support.satisfaction ?? 0) >= 4 }]">
              {{ support.satisfaction === null ? "Not rated" : `${support.satisfaction} / 5` }}
            </span>
            <!-- An average of two ratings is not the same claim as one of forty. -->
            <span v-if="support.rated" class="ad-kpi-sub">from {{ support.rated }} rated</span>
          </div>
        </div>

        <p v-if="!tickets.length" class="ad-none-inline">
          Nothing has been raised against this customer.
        </p>

        <div v-else class="ad-card ad-table-card">
          <table class="ad-table">
            <thead><tr><th>Ref</th><th>Subject</th><th>Priority</th><th>Assignee</th><th>Updated</th></tr></thead>
            <tbody>
              <tr v-for="t in tickets" :key="t.id" class="ad-clickable"
                  @click="router.push({ name: 'ticket-detail', params: { id: t.id } })">
                <td class="ad-ref">{{ t.reference }}</td>
                <td>
                  {{ t.subject }}
                  <span v-if="t.breaching" class="pill pill-danger ad-inline-pill">Breaching</span>
                </td>
                <td>
                  <span v-if="t.priority" :class="['ad-prio', PRIORITY_CLASS[t.priority] ?? 'low']">
                    <span class="ad-prio-dot" aria-hidden="true"></span>{{ t.priority }}
                  </span>
                  <span v-else>—</span>
                </td>
                <td>{{ t.assignee ?? "Unassigned" }}</td>
                <td>{{ t.updated_at ? ago(t.updated_at) : "—" }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Every tab above is built. This catches a tab added to the list
           without a panel, rather than rendering nothing at all. -->
      <section v-else class="ad-panel">
        <div class="ad-empty">
          <h3 class="ad-empty-title">Nothing to show here</h3>
          <p class="ad-empty-sub">This tab has no panel behind it yet.</p>
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
          <input v-model="contactForm.email" class="control" type="email" placeholder="angie@kcb.co.ke"
                 :disabled="editingMaskedContact" /></label>

        <label class="ad-field"><span>Phone Number</span>
          <input v-model="contactForm.phone" class="control" placeholder="+254 712 345 678"
                 :disabled="editingMaskedContact" /></label>
        <p v-if="editingMaskedContact" class="ad-hint">
          Email and phone are hidden from your role, so they cannot be edited here —
          the rest of the card saves normally.
        </p>

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
        <div class="ad-warn-mark" aria-hidden="true">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7"
               stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 6h12M8 6V4h4v2M6 6l1 10h6l1-10M9 9v5M11 9v5" />
          </svg>
        </div>
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

.ad-tabs { @apply flex flex-wrap gap-1; border-bottom: 1px solid var(--color-border); }
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

.ad-hidden-file { display: none; }

.ad-facts { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3) var(--space-6); margin: 0; }
.ad-facts > div { @apply flex items-baseline; gap: var(--space-3); }
.ad-facts dt { font-size: var(--text-sm-size); color: var(--color-text-muted); min-width: 8.5rem; }
.ad-facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }
@media (max-width: 44rem) { .ad-facts { grid-template-columns: 1fr; } }


.ad-mini { margin: var(--space-3) 0 0; }
.ad-mini > div { @apply flex items-baseline justify-between; gap: var(--space-3); padding: var(--space-1) 0; }
.ad-mini dt { font-size: var(--text-sm-size); color: var(--color-text-muted); }
.ad-mini dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }

.ad-strong { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.cap { text-transform: capitalize; }

/* SVG, not `✎` and `🗑` — both render as tofu in Google Sans, the same way
   the fullwidth plus did. */
.icon-btn { @apply inline-flex cursor-pointer items-center justify-center border-none bg-transparent p-0;
  color: var(--color-text-muted); }
.icon-btn svg { width: 1rem; height: 1rem; }
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
.ad-ref { font-variant-numeric: tabular-nums; color: var(--color-text-muted); white-space: nowrap; }
.ad-clickable { @apply cursor-pointer; transition: background-color 120ms ease; }
.ad-clickable:hover { background: var(--color-neutral-100); }
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
  background: var(--color-danger-subtle); color: var(--color-danger-text);
}
.ad-warn-mark svg { width: 1.15rem; height: 1.15rem; }

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

/* ── Header band ──────────────────────────────────────────────────────────
   Identity, then four figures. The KPI cells are hairline-separated by a 1px
   grid gap over the border colour, which is how the handoff draws them —
   cheaper than four bordered boxes that double up on every seam. */
.ad-band {
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--space-5) var(--space-6);
  margin-bottom: var(--space-4);
}

.ad-band-top { @apply flex flex-wrap items-start; gap: var(--space-4); }

.ad-logo {
  @apply relative flex shrink-0 cursor-pointer items-center justify-center overflow-hidden;
  width: 3.7rem; height: 3.7rem; border-radius: var(--radius-lg);
  border: 1px solid var(--color-border); background: var(--color-surface-sunken);
  font-size: var(--text-sm-size); font-weight: 700; color: var(--color-text-muted);
}
.ad-logo.empty { border-style: dashed; }
.ad-logo-img { @apply h-full w-full object-contain; padding: 4px; }
.ad-logo-edit {
  @apply absolute opacity-0; inset: auto 2px 2px auto;
  width: 1rem; height: 1rem; line-height: 1rem; text-align: center;
  border-radius: var(--radius-full); background: var(--color-brand-base); color: var(--color-on-brand);
  font-size: var(--text-xs-size); font-weight: 600;
}
.ad-logo:hover .ad-logo-edit, .ad-logo:focus-visible .ad-logo-edit { @apply opacity-100; }

.ad-band-id   { @apply flex flex-col; gap: var(--space-2); flex: 1; min-width: 14rem; }
.ad-band-name { @apply flex flex-wrap items-center; gap: var(--space-2); }
.ad-status-dot {
  @apply inline-block; width: 5px; height: 5px; border-radius: var(--radius-full);
  background: currentColor; margin-right: var(--space-1);
}

/* Separators are drawn between the spans that survived, so an unknown field
   leaves no orphan dot behind it. */
.ad-band-meta { @apply flex flex-wrap items-center; gap: var(--space-2); margin: 0;
  font-size: var(--text-sm-size); color: var(--color-text-muted); }
.ad-band-meta > span + span::before { content: "·"; color: var(--color-text-light); margin-right: var(--space-2); }
.ad-band-meta strong { color: var(--color-text); font-weight: 600; }
.ad-band-thin { color: var(--color-text-light); }

.ad-band-actions { @apply flex flex-wrap items-center; gap: var(--space-2); }

.ad-kpis {
  @apply grid; grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1px; margin-top: var(--space-5);
  background: var(--color-border);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  overflow: hidden;
}
@media (max-width: 60rem) { .ad-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 32rem) { .ad-kpis { grid-template-columns: 1fr; } }

.ad-kpi { @apply flex flex-col; gap: 2px; padding: var(--space-3) var(--space-4); background: var(--color-surface-raised); }
.ad-kpi-label {
  font-size: var(--text-xs-size); font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.07em; color: var(--color-text-light);
}
.ad-kpi-value { font-size: var(--text-xl-size); font-weight: 600; letter-spacing: -0.5px; color: var(--color-text); }
.ad-kpi-sub   { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.ad-kpi-sub.up   { color: var(--color-success-text); }
.ad-kpi-sub.down { color: var(--color-danger-text); }
.ad-kpi-sub.warn { color: var(--color-warning-text); }

/* ── Overview: two columns ────────────────────────────────────────────────
   The wider one carries what the account is and what it is doing; the narrower
   one, who to call and what needs attention. `align-items: start` so a short
   right column does not stretch to match a long left one. */
.ad-two {
  @apply grid; gap: var(--space-4); align-items: start;
  grid-template-columns: minmax(0, 1.62fr) minmax(0, 1fr);
}
@media (max-width: 72rem) { .ad-two { grid-template-columns: 1fr; } }
.ad-col { @apply flex flex-col; gap: var(--space-4); }

/* Pipeline */
.ad-stagebar { @apply flex overflow-hidden; height: 7px; border-radius: var(--radius-full);
  background: var(--color-surface-sunken); gap: 1px; margin-bottom: var(--space-3); }
.ad-stagebar-seg { @apply block; height: 100%; }

.ad-deals { @apply list-none flex flex-col; margin: 0; padding: 0; }
.ad-deal {
  @apply flex flex-wrap items-center cursor-pointer; gap: var(--space-3);
  padding: var(--space-2) var(--space-3); border-radius: var(--radius-md);
  transition: background-color 120ms ease;
}
.ad-deal:hover { background: var(--color-neutral-100); }
.ad-deal-main  { @apply flex flex-col; gap: 1px; flex: 1; min-width: 10rem; }
.ad-deal-name  { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); }
.ad-deal-sub   { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.ad-deal-value { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text);
  text-align: right; min-width: 7rem; font-variant-numeric: tabular-nums; }
.ad-deal-bar   { @apply flex items-center; gap: var(--space-2); width: 7rem; flex-shrink: 0; }
.ad-deal-track { @apply block overflow-hidden; flex: 1; height: 5px; border-radius: var(--radius-full);
  background: var(--color-surface-sunken); }
.ad-deal-fill  { @apply block; height: 100%; background: var(--color-brand-base); }
.ad-deal-pct   { font-size: var(--text-xs-size); color: var(--color-text-muted);
  width: 2.4rem; text-align: right; font-variant-numeric: tabular-nums; }

/* Timeline */
.ad-timeline { @apply list-none flex flex-col; margin: 0; padding: 0; }
.ad-tl { @apply flex; gap: var(--space-3); padding-bottom: var(--space-4); position: relative; }
.ad-tl:last-child { padding-bottom: 0; }
/* The connector stops at the last entry, so the line does not trail into
   nothing below the final event. */
.ad-tl:not(:last-child)::before {
  content: ""; position: absolute; left: 4px; top: 14px; bottom: 0;
  width: 1px; background: var(--color-border);
}
.ad-tl-dot { @apply block shrink-0; width: 9px; height: 9px; margin-top: 4px;
  border-radius: var(--radius-full); background: var(--color-neutral-300); z-index: 1; }
.ad-tl-dot.newest { background: var(--color-brand-base); }
.ad-tl-body { @apply flex flex-col; gap: 1px; min-width: 0; }
.ad-tl-text { font-size: var(--text-sm-size); color: var(--color-text); }
.ad-tl-meta { font-size: var(--text-xs-size); color: var(--color-text-light); }

/* Primary contact */
/* Named `ad-primary`, not `ad-contact`: the Contacts tab's cards already
   carried `ad-contact` as a bare marker with no rule of its own, so defining
   one here turned every card into a squashed flex row — emails wrapping one
   character per line. Grep the style block before naming a class. */
.ad-primary { @apply flex items-center; gap: var(--space-3); margin-bottom: var(--space-3); }
.ad-contact-avatar {
  @apply flex shrink-0 items-center justify-center; width: 2.6rem; height: 2.6rem;
  border-radius: var(--radius-full); background: var(--color-brand-light);
  color: var(--color-brand-on-surface); font-size: var(--text-sm-size); font-weight: 600;
}
.ad-contact-id   { @apply flex flex-col; gap: 1px; min-width: 0; }
.ad-contact-role { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.ad-contact-actions { @apply grid; grid-template-columns: 1fr 1fr; gap: var(--space-2); margin-top: var(--space-3); }
.ad-half { justify-content: center; }
.ad-half[aria-disabled="true"] { @apply pointer-events-none opacity-50; }

.ad-none-block { @apply flex flex-col items-start; gap: var(--space-3); }

/* Renewal alert — its own surface, because it is the one card asking for
   something rather than reporting something. */
.ad-alert {
  background: var(--color-warning-subtle);
  border: 1px solid color-mix(in oklab, var(--color-warning-text) 32%, transparent);
  border-radius: var(--radius-lg); padding: var(--space-4);
}
.ad-alert-title { @apply flex items-center; gap: var(--space-2); margin: 0 0 var(--space-2);
  font-size: var(--text-sm-size); font-weight: 600; color: var(--color-warning-text); }
.ad-alert-dot { @apply block; width: 7px; height: 7px; border-radius: var(--radius-full);
  background: var(--color-warning); }
.ad-alert-body { margin: 0; font-size: var(--text-sm-size); line-height: 1.5; color: var(--color-warning-text); }
.ad-alert-actions { margin-top: var(--space-3); }
.ad-alert-btn {
  @apply cursor-pointer; padding: 5px var(--space-3); border-radius: var(--radius-md);
  background: color-mix(in oklab, var(--color-warning-text) 12%, transparent);
  border: 1px solid color-mix(in oklab, var(--color-warning-text) 32%, transparent);
  color: var(--color-warning-text); font-family: inherit;
  font-size: var(--text-xs-size); font-weight: 600;
}

/* Simple name/meta rows, for active contracts */
.ad-rows { @apply list-none; margin: 0; padding: 0; }
.ad-row  { @apply flex items-center justify-between; gap: var(--space-3); padding: var(--space-2) 0; }
.ad-row + .ad-row { border-top: 1px solid var(--color-border); }
.ad-row-name { font-size: var(--text-sm-size); color: var(--color-text); }
.ad-row-meta { font-size: var(--text-xs-size); color: var(--color-text-muted); white-space: nowrap; }

.ad-more { margin-top: var(--space-2); }
/* ── Projects: a card each ───────────────────────────────────────────────── */
.ad-cards { @apply grid; gap: var(--space-4); grid-template-columns: repeat(2, minmax(0, 1fr)); }
@media (max-width: 60rem) { .ad-cards { grid-template-columns: 1fr; } }

.ad-project {
  @apply flex flex-col cursor-pointer; gap: var(--space-3);
  padding: var(--space-4) var(--space-5);
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border); border-radius: var(--radius-lg);
  transition: border-color 120ms ease;
}
.ad-project:hover { border-color: var(--color-border-strong); }
.ad-project-head { @apply flex items-start justify-between; gap: var(--space-3); }
.ad-project-id   { @apply flex flex-col; gap: 2px; min-width: 0; }
.ad-project-name { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ad-project-sub  { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; }
.ad-project-progress { @apply flex items-center; gap: var(--space-2); }
.ad-project-foot {
  @apply flex items-center justify-between; gap: var(--space-3);
  font-size: var(--text-xs-size); color: var(--color-text-light);
}

/* ── Support: the four figures a review asks about ───────────────────────── */
.ad-stats {
  @apply grid; grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1px; margin-bottom: var(--space-4);
  background: var(--color-border); border: 1px solid var(--color-border);
  border-radius: var(--radius-md); overflow: hidden;
}
@media (max-width: 60rem) { .ad-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.ad-stat { @apply flex flex-col; gap: 2px; padding: var(--space-3) var(--space-4);
  background: var(--color-surface-raised); }
.ad-stat-value { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); }
.ad-stat-value.bad  { color: var(--color-danger-text); }
.ad-stat-value.good { color: var(--color-success-text); }

/* Colour carries the priority as well as the word, so a queue reads at a
   glance rather than being read line by line. */
.ad-prio { @apply inline-flex items-center; gap: var(--space-2);
  font-size: var(--text-xs-size); font-weight: 600; }
.ad-prio-dot { @apply block; width: 6px; height: 6px; border-radius: var(--radius-full); background: currentColor; }
.ad-prio.crit { color: var(--color-danger-text); }
.ad-prio.high { color: var(--color-warning-text); }
.ad-prio.med  { color: var(--color-brand-on-surface); }
.ad-prio.low  { color: var(--color-text-muted); }

.ad-inline-pill { margin-left: var(--space-2); }

/* ── People in a table ────────────────────────────────────────────────────
   Shared by Contacts and Assigned Team: a row per person, the name carrying
   an avatar so a list of eight is scannable by shape as well as by reading. */
.ad-person { @apply flex items-center; gap: var(--space-3); min-width: 0; }
.ad-person-avatar {
  @apply flex shrink-0 items-center justify-center; width: 1.9rem; height: 1.9rem;
  border-radius: var(--radius-full); background: var(--color-brand-light);
  color: var(--color-brand-on-surface); font-size: var(--text-xs-size); font-weight: 600;
}
.ad-person-id   { @apply flex flex-col; gap: 1px; min-width: 0; }
.ad-person-name { font-weight: 600; color: var(--color-text); }
.ad-person-flag {
  font-size: var(--text-xs-size); color: var(--color-brand-on-surface);
  text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600;
}

/* An address is one thing, not a column of letters: it truncates rather than
   breaking, with the whole of it on hover. */
.ad-truncate { @apply block truncate; max-width: 18rem; }
.ad-nowrap   { white-space: nowrap; }
.ad-cell-wrap { min-width: 8rem; }
.ad-muted-cell { color: var(--color-text-muted); font-size: var(--text-xs-size); }
.ad-row-tools  { @apply flex justify-end; gap: var(--space-3); white-space: nowrap; }
</style>
