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
import AccountPicker from "../../components/AccountPicker.vue";

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
/** One answer on offer, and what it is worth. Authored server-side. */
interface Answer { value: string; points: number; label: string; help: string }
interface Dimension {
  key: string; label: string; question: string;
  options: Answer[]; value: number | null; answer: Answer | null;
}
interface Qualification {
  dimensions: Dimension[];
  score: number | null; band: string | null; completeness: number; percentile: number | null;
  unanswered: string[];
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
  opportunity?: {
    id: number; reference: string | null; name: string;
    status: string; strength: number; qualified_at: string | null;
  } | null;
  created_at: string | null;
}

type Tab = "overview" | "company" | "activities" | "qualifications" | "correspondence" | "documents";
type Thread = "meetings" | "emails" | "requests";

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
  { key: "correspondence", label: "Correspondence" },
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
  if (t === "correspondence") void loadThread(thread.value);
});


// ── Editing the lead ────────────────────────────────────────────────────────

const editing = ref(false);
const form = ref({
  name: "", summary: "", source: "", status: "new", priority: "medium",
  deadline: "", website: "", problem_statement: "", proposed_solution: "",
  owner_id: null as number | null,
  account_id: null as number | null,
});

function fillForm(l: Lead) {
  form.value = {
    name: l.name, summary: l.summary ?? "", source: l.source ?? "",
    status: l.status, priority: l.priority,
    deadline: l.deadline ?? "", website: l.website ?? "",
    problem_statement: l.problem_statement ?? "", proposed_solution: l.proposed_solution ?? "",
    owner_id: l.owner?.id ?? null,
    account_id: l.account?.id ?? null,
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
      },
    })).data;
    editing.value = false;
    flash("Saved.");
  } catch (e) { fail(e, "Could not save."); }
  finally { saving.value = false; }
}

// ── Qualification ───────────────────────────────────────────────────────────

/*
 * Answering happens one question at a time, in place.
 *
 * The form this replaced saved all five at once behind an "Assess this lead"
 * button, and across 160 live leads it was never once used — including the 80
 * that were converted anyway. Asking for everything before recording anything
 * makes a partial answer feel like a failed one, when a partial answer is
 * exactly what the arithmetic is built to report.
 *
 * `answering` holds the key of the question whose options are open, so only one
 * expands at a time and the page does not turn back into a form.
 */
const answering = ref<string | null>(null);
const notes = ref("");
const answeringKey = ref<string | null>(null);   // which question is saving

function fillQualification(q: Qualification) {
  notes.value = q.notes ?? "";
}

/** Pick an answer. Saves immediately; picking the current one takes it back. */
async function answer(dimension: Dimension, option: Answer | null) {
  if (answeringKey.value) return;

  // Clicking the answer already recorded means "I am no longer sure", which
  // is different from answering with the worst option — that one is a finding.
  const chosen = option && option.value === dimension.answer?.value ? null : option?.value ?? null;

  answeringKey.value = dimension.key;
  try {
    lead.value = (await api<{ data: Lead }>("POST", `${base.value}/qualify`, {
      org: org(), body: { [dimension.key]: chosen },
    })).data;
    fillQualification(lead.value.qualification);
    answering.value = null;
  } catch (e) { fail(e, "Could not record that answer."); }
  finally { answeringKey.value = null; }
}

async function saveNotes() {
  if ((lead.value?.qualification.notes ?? "") === notes.value) return;
  saving.value = true;
  try {
    lead.value = (await api<{ data: Lead }>("POST", `${base.value}/qualify`, {
      org: org(), body: { qualification_notes: notes.value || null },
    })).data;
    flash("Note saved.");
  } catch (e) { fail(e, "Could not save the note."); }
  finally { saving.value = false; }
}

/**
 * Qualify, and become a deal.
 *
 * Qualifying is not a state a lead rests in — it is the act of turning into an
 * opportunity, so the button does that rather than moving a card into a column
 * that held nothing in 160 leads. The name, customer, owner, description and
 * the five answers all travel with it; nothing is retyped.
 *
 * The decision lives here rather than only on the board because assessing in
 * one place and deciding in another is most of why the assessment got skipped.
 */
async function qualifyIntoDeal() {
  if (!confirm(`Qualify "${lead.value?.name}" and open it as a deal? The assessment goes with it.`)) return;

  saving.value = true;
  try {
    const deal = (await api<{ data: { id: number } }>("POST", `${base.value}/convert`, {
      org: org(), body: {},
    })).data;
    flash("Qualified — the deal is open.");
    router.push({ name: "opportunity", params: { id: deal.id } });
  } catch (e) { fail(e, "Could not qualify this lead."); }
  finally { saving.value = false; }
}

/** Write it off. Stays on the board, in the column that says why. */
async function writeOff() {
  saving.value = true;
  try {
    lead.value = (await api<{ data: Lead }>("POST", `${base.value}/status`, {
      org: org(), body: { status: "unqualified" },
    })).data;
    flash("Lead written off.");
  } catch (e) { fail(e, "Could not update the lead."); }
  finally { saving.value = false; }
}

// ── Correspondence ──────────────────────────────────────────────────────────
//
// Three lists under one tab: what we arranged, what arrived, and what pursuing
// this is costing. They are separate requests because they are separate
// records — and because a mailbox pull is slow and nobody should wait for it
// to see a meeting.

interface Attendee { name: string | null; address: string; side?: string }
interface Meeting {
  id: number; subject: string; scheduled_at: string | null; duration_minutes: number;
  agenda: string | null; attendees: Attendee[]; join_url: string | null;
  recording_url: string | null; has_transcript: boolean; status: string;
  cancelled_at: string | null; created_by: string | null; is_past: boolean;
}
interface Email {
  id: number; subject: string | null; from_name: string | null; from_email: string | null;
  body_preview: string | null; body_html: string | null; has_attachments: boolean;
  received_at: string | null; matched_address: string | null;
}
interface ResourceRequest {
  id: number; reference: string | null; kind: string; kind_label: string;
  purpose: string; location: string | null; needed_on: string | null; people: number | null;
  estimated_cost: string | null; currency: string; notes: string | null; status: string;
  decision_comment: string | null; decided_at: string | null; decided_by: string | null;
  requested_by: string | null; created_at: string | null;
}

const thread = ref<Thread>("meetings");
const threads: { key: Thread; label: string }[] = [
  { key: "meetings", label: "Meetings" },
  { key: "emails",   label: "Emails" },
  { key: "requests", label: "Requests" },
];

const meetings   = ref<Meeting[]>([]);
const emails     = ref<Email[]>([]);
const requests   = ref<ResourceRequest[]>([]);
const suggested  = ref<Attendee[]>([]);
const correspondents = ref<string[]>([]);
const kinds      = ref<{ value: string; label: string }[]>([]);
const loaded     = ref<Record<Thread, boolean>>({ meetings: false, emails: false, requests: false });
const busy       = ref(false);
const openEmail  = ref<number | null>(null);

// Each thread is fetched when first opened, not on mount: three requests for a
// tab most readers never open is three requests wasted.
watch(thread, (t) => { if (tab.value === "correspondence") void loadThread(t); });

async function loadThread(which: Thread, force = false) {
  if (loaded.value[which] && !force) return;
  busy.value = true;
  try {
    if (which === "meetings") {
      const res = await api<{ data: Meeting[]; suggested_attendees: Attendee[] }>(
        "GET", `${base.value}/meetings`, { org: org() });
      meetings.value = res.data;
      suggested.value = res.suggested_attendees;
    } else if (which === "emails") {
      const res = await api<{ data: Email[]; correspondents: string[] }>(
        "GET", `${base.value}/emails`, { org: org() });
      emails.value = res.data;
      correspondents.value = res.correspondents;
    } else {
      const res = await api<{ data: ResourceRequest[]; kinds: { value: string; label: string }[] }>(
        "GET", `${base.value}/requests`, { org: org() });
      requests.value = res.data;
      kinds.value = res.kinds;
    }
    loaded.value[which] = true;
  } catch (e) { fail(e, "Could not load correspondence."); }
  finally { busy.value = false; }
}

// ── Meetings ────────────────────────────────────────────────────────────────

const showMeeting = ref(false);
const meetingForm = ref({ subject: "", scheduled_at: "", duration_minutes: 45, agenda: "" });

/*
 * The invitation list, built rather than merely ticked.
 *
 * The first cut offered only the people already recorded against the lead, so
 * a lead with nobody named on it produced an empty list and a button that could
 * never enable — a meeting scheduler you cannot schedule a meeting with. Three
 * ways in now: the suggestions, any colleague by name, and anybody else by
 * address, because the person you most want at a first call is often somebody
 * nobody has filed yet.
 */
const invitees   = ref<Attendee[]>([]);
const guestEmail = ref("");
const guestName  = ref("");
const colleague  = ref<number | null>(null);

const invited = (address: string) =>
  invitees.value.some((a) => a.address.toLowerCase() === address.toLowerCase());

function invite(a: Attendee) {
  if (!invited(a.address)) invitees.value.push(a);
}

function uninvite(address: string) {
  invitees.value = invitees.value.filter((a) => a.address !== address);
}

function toggleInvite(a: Attendee) {
  invited(a.address) ? uninvite(a.address) : invite(a);
}

/** A colleague picked by name. Their work address is what Teams needs. */
function inviteColleague(m: { full_name: string; work_email: string | null } | null) {
  colleague.value = null;
  if (!m) return;

  if (!m.work_email) {
    notifyFailed(`${m.full_name} has no work email on file, so there is nowhere to send the invitation.`);
    return;
  }

  invite({ name: m.full_name, address: m.work_email, side: "ours" });
}

function inviteGuest() {
  const address = guestEmail.value.trim();
  if (!address) return;

  invite({ name: guestName.value.trim() || null, address, side: "theirs" });
  guestEmail.value = "";
  guestName.value = "";
}

function openMeetingForm() {
  meetingForm.value = {
    subject: lead.value ? `${lead.value.name} — introduction` : "",
    scheduled_at: "", duration_minutes: 45, agenda: "",
  };
  // Everybody already named, pre-invited: the common case is "all of them",
  // and removing one is less work than finding five addresses.
  invitees.value = [...suggested.value];
  guestEmail.value = "";
  guestName.value = "";
  colleague.value = null;
  showMeeting.value = true;
}

async function scheduleMeeting() {
  saving.value = true;
  try {
    const created = (await api<{ data: Meeting }>("POST", `${base.value}/meetings`, {
      org: org(),
      body: {
        ...meetingForm.value,
        attendees: invitees.value.map((a) => ({ name: a.name, address: a.address })),
      },
    })).data;
    meetings.value = [created, ...meetings.value];
    showMeeting.value = false;
    flash("Teams meeting created — the invitation is out.");
  } catch (e) { fail(e, "Could not schedule the meeting."); }
  finally { saving.value = false; }
}

async function cancelMeeting(m: Meeting) {
  if (!confirm(`Cancel "${m.subject}"? Everyone invited will be told.`)) return;
  saving.value = true;
  try {
    const updated = (await api<{ data: Meeting }>("POST", `${base.value}/meetings/${m.id}/cancel`, { org: org() })).data;
    meetings.value = meetings.value.map((x) => (x.id === updated.id ? updated : x));
    flash("Meeting cancelled.");
  } catch (e) { fail(e, "Could not cancel the meeting."); }
  finally { saving.value = false; }
}

// ── Emails ──────────────────────────────────────────────────────────────────

async function syncEmails() {
  busy.value = true;
  try {
    const res = await api<{ message: string; data: Email[] }>(
      "POST", `${base.value}/emails/sync`, { org: org() });
    emails.value = res.data;
    flash(res.message);
  } catch (e) { fail(e, "Could not check the mailbox."); }
  finally { busy.value = false; }
}

// ── Requests ────────────────────────────────────────────────────────────────

const showRequest = ref(false);
const requestForm = ref({
  kind: "transport", purpose: "", location: "", needed_on: "",
  people: null as number | null, estimated_cost: null as number | null, notes: "",
});

function openRequestForm() {
  requestForm.value = {
    kind: "transport", purpose: "", location: "", needed_on: "",
    people: null, estimated_cost: null, notes: "",
  };
  showRequest.value = true;
}

async function submitRequest() {
  saving.value = true;
  try {
    const created = (await api<{ data: ResourceRequest }>("POST", `${base.value}/requests`, {
      org: org(),
      body: {
        ...requestForm.value,
        location: requestForm.value.location || null,
        needed_on: requestForm.value.needed_on || null,
        notes: requestForm.value.notes || null,
      },
    })).data;
    requests.value = [created, ...requests.value];
    showRequest.value = false;
    flash("Request raised.");
  } catch (e) { fail(e, "Could not raise the request."); }
  finally { saving.value = false; }
}

async function decideRequest(r: ResourceRequest, status: "approved" | "declined") {
  // A refusal has to say why, so it is asked for rather than assumed.
  const comment = status === "declined"
    ? prompt(`Declining "${r.purpose}". Why?`)
    : prompt(`Approving "${r.purpose}". Any note? (optional)`, "") ?? "";

  if (status === "declined" && !comment) return;

  saving.value = true;
  try {
    const updated = (await api<{ data: ResourceRequest }>("POST", `${base.value}/requests/${r.id}/decide`, {
      org: org(), body: { status, decision_comment: comment || null },
    })).data;
    requests.value = requests.value.map((x) => (x.id === updated.id ? updated : x));
    flash(status === "approved" ? "Approved." : "Declined.");
  } catch (e) { fail(e, "Could not record the decision."); }
  finally { saving.value = false; }
}

async function fulfilRequest(r: ResourceRequest) {
  saving.value = true;
  try {
    const updated = (await api<{ data: ResourceRequest }>("POST", `${base.value}/requests/${r.id}/fulfil`, { org: org() })).data;
    requests.value = requests.value.map((x) => (x.id === updated.id ? updated : x));
    flash("Marked as provided.");
  } catch (e) { fail(e, "Could not update the request."); }
  finally { saving.value = false; }
}

async function withdrawRequest(r: ResourceRequest) {
  if (!confirm(`Withdraw the request for ${r.kind_label.toLowerCase()}?`)) return;
  saving.value = true;
  try {
    await api("DELETE", `${base.value}/requests/${r.id}`, { org: org() });
    requests.value = requests.value.filter((x) => x.id !== r.id);
    flash("Request withdrawn.");
  } catch (e) { fail(e, "Could not withdraw the request."); }
  finally { saving.value = false; }
}

/** 45000.00 is a number to decode; 45,000 is one to read. */
const money = (v: string | null) =>
  v === null ? null : Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 });

const REQUEST_PILL: Record<string, string> = {
  pending: "pill-warn", approved: "pill-ok", declined: "pill-danger", fulfilled: "pill-info",
};

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

const STATUS_BADGES: Record<string, string> = {
  new: "badge-blue", contacted: "badge-indigo", qualified: "badge-green",
  unqualified: "badge-gray", converted: "badge-teal",
};
const statusBadge = (status: string) => STATUS_BADGES[status] ?? "badge-gray";

const PRIORITY_BADGES: Record<string, string> = { low: "badge-gray", medium: "badge-amber", high: "badge-red" };
const priorityBadge = (priority: string) => PRIORITY_BADGES[priority] ?? "badge-gray";

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

      <!-- Where it got to. Naming the deal is pointless here — on a converted
           lead the deal carries the lead's own title, so the old banner read
           "this became itself". What is worth knowing is how far it has run. -->
      <div v-if="lead.opportunity" class="ld-banner">
        This lead became
        <button class="link-btn" @click="router.push({ name: 'opportunity', params: { id: lead.opportunity.id } })">
          {{ lead.opportunity.reference ?? "the deal" }}
        </button>
        <span class="ld-dot">•</span>
        <span>{{ lead.opportunity.qualified_at ? "qualified" : titleCase(lead.opportunity.status) }}</span>
        <span class="ld-dot">•</span>
        <span>strength {{ lead.opportunity.strength }}/100</span>
      </div>

      <!-- One strip rather than two panels. Eight facts, six of them usually
           blank, do not need half the screen each; wrapping them lets the page
           get to the tabs. The calendar emoji that used to sit on Deadline
           rendered as tofu in Google Sans — the label already says Deadline. -->
      <div class="ld-facts-row">
        <dl class="ld-facts">
          <div><dt>Deadline</dt><dd>{{ date(lead.deadline) }}</dd></div>
          <div><dt>Status</dt><dd><span class="badge" :class="statusBadge(lead.status)">{{ titleCase(lead.status) }}</span></dd></div>
          <div><dt>Priority</dt>
            <dd><span class="badge" :class="priorityBadge(lead.priority)">⚑ {{ titleCase(lead.priority) }}</span></dd></div>
          <div><dt>Lead Team</dt>
            <dd>
              <button class="ld-stack-btn" @click="showTeam = true">
                <span v-if="!lead.team?.length" class="ld-add-inline">+ Add</span>
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
          <div class="ld-card ld-block">
            <h3 class="ld-block-title">Problem Statement</h3>
            <p v-if="lead.problem_statement" class="ld-prose">{{ lead.problem_statement }}</p>
            <p v-else class="ld-muted">Not written up yet.</p>
          </div>

          <div class="ld-card ld-block">
            <h3 class="ld-block-title">Proposed Solution Scope</h3>
            <p v-if="lead.proposed_solution" class="ld-prose">{{ lead.proposed_solution }}</p>
            <p v-else class="ld-muted">Not written up yet.</p>
          </div>
        </template>

        <div v-else class="ld-card">
          <div class="ld-grid">
            <div class="ld-field ld-span">
              <span>Customer <em class="ld-req">required</em></span>
              <AccountPicker v-model="form.account_id" placeholder="Which customer is this for?" />
            </div>

            <label class="ld-field ld-span"><span>Lead name</span>
              <input v-model="form.name" class="control" /></label>

            <label class="ld-field ld-span"><span>One-line summary</span>
              <input v-model="form.summary" class="control" placeholder="Shown on the board card" /></label>

            <!-- No "Qualified" here: qualifying opens a deal, which is an act
                 with a consequence, not a value on a dropdown. It lives at the
                 foot of the assessment where the reasoning is. -->
            <label class="ld-field"><span>Status</span>
              <select v-model="form.status" class="control">
                <option value="new">New</option>
                <option value="contacted">Contacted</option>
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
            <button class="btn-primary" :disabled="saving || !form.account_id || !form.name.trim()" @click="save">
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

      <!-- ── Qualifications ──────────────────────────────────────────────
           Read first, answer in place. Five questions, each standing on its
           own: answering one records it and leaves the rest alone. -->
      <section v-else-if="tab === 'qualifications'" class="ld-panel">
        <div class="ld-qual-grid">
          <!-- Score. Present whether or not anything has been answered, so
               the page does not reflow the moment the first one lands. -->
          <div class="ld-card ld-qual-score">
            <h3 class="ld-block-label">Qualification Score</h3>

            <div class="ld-gauge-row">
              <svg class="ld-gauge" viewBox="0 0 180 180" role="img"
                   :aria-label="lead.qualification.score === null
                     ? 'Not assessed yet'
                     : `${lead.qualification.score} out of 100`">
                <circle class="ld-gauge-track" cx="90" cy="90" :r="GAUGE.radius"
                        :stroke-dasharray="`${GAUGE.circumference} ${GAUGE.circumference * 2}`" />
                <circle v-if="lead.qualification.score !== null"
                        :class="['ld-gauge-fill', `band-${lead.qualification.band}`]" cx="90" cy="90" :r="GAUGE.radius"
                        :stroke-dasharray="`${GAUGE.circumference} ${GAUGE.circumference * 2}`"
                        :stroke-dashoffset="gaugeOffset" />
                <text x="90" y="84" class="ld-gauge-label">
                  {{ BAND_LABELS[lead.qualification.band ?? ""] ?? "Unanswered" }}
                </text>
                <text x="90" y="118" class="ld-gauge-value">
                  {{ lead.qualification.score === null ? "—" : `${lead.qualification.score}%` }}
                </text>
              </svg>

              <div class="ld-gauge-note">
                <p class="ld-prose">
                  Fit across company, budget, solution, timeline and stakeholder access.
                </p>

                <template v-if="lead.qualification.score === null">
                  <p class="ld-hint">
                    Nothing answered yet. An unassessed lead has no score rather
                    than a score of zero — answer whichever questions you can.
                  </p>
                </template>

                <template v-else>
                  <!-- Said out loud: one answer and five answers are not the
                       same claim. -->
                  <p class="ld-hint">
                    Based on {{ lead.qualification.completeness }}% of the assessment.
                  </p>
                  <p v-if="lead.qualification.unanswered.length" class="ld-hint">
                    Still open: {{ lead.qualification.unanswered.join(", ") }}.
                  </p>
                  <span v-if="lead.qualification.percentile !== null" class="pill pill-ok">
                    Top {{ Math.max(lead.qualification.percentile, 1) }}% of all leads
                  </span>
                  <span v-else class="ld-hint">
                    Too few assessed leads to rank this one yet.
                  </span>
                </template>
              </div>
            </div>
          </div>

          <!-- The five questions -->
          <div class="ld-card ld-qual-assess">
            <h3 class="ld-block-label">Assessment</h3>

            <ul class="ld-questions">
              <li v-for="d in lead.qualification.dimensions" :key="d.key" class="ld-question">
                <button class="ld-question-head" :aria-expanded="answering === d.key"
                        @click="answering = answering === d.key ? null : d.key">
                  <span class="ld-question-text">
                    <span class="ld-question-label">{{ d.label }}</span>
                    <span class="ld-question-ask">{{ d.question }}</span>
                  </span>

                  <span class="ld-question-state">
                    <span v-if="d.answer" class="pill pill-info">{{ d.answer.label }}</span>
                    <span v-else class="ld-unanswered">Not answered</span>
                    <span class="ld-question-points">{{ d.value === null ? "—" : `${d.value}%` }}</span>
                  </span>
                </button>

                <!-- The bar reads the answer at a glance once the row is shut. -->
                <span class="ld-bar-track">
                  <span class="ld-bar-fill" :style="{ width: `${d.value ?? 0}%` }"></span>
                </span>

                <div v-if="answering === d.key" class="ld-options">
                  <button v-for="o in d.options" :key="o.value"
                          :class="['ld-option', { chosen: d.answer?.value === o.value }]"
                          :disabled="answeringKey === d.key"
                          @click="answer(d, o)">
                    <span class="ld-option-label">{{ o.label }}</span>
                    <span class="ld-option-help">{{ o.help }}</span>
                    <span class="ld-option-points">{{ o.points }}</span>
                  </button>

                  <p class="ld-hint ld-option-note">
                    Choosing the answer already recorded takes it back — which is
                    not the same as answering with the last option, since that one
                    is itself a finding.
                  </p>
                </div>
              </li>
            </ul>

            <label class="ld-field ld-notes"><span>Notes</span>
              <textarea v-model="notes" class="control" rows="3"
                        placeholder="What the answers above do not capture."
                        @blur="saveNotes"></textarea></label>

            <!-- The decision, on the screen that informed it. -->
            <div class="ld-decide">
              <p class="ld-hint">
                <template v-if="lead.opportunity">
                  Already qualified — this lead is now a deal.
                </template>
                <template v-else-if="lead.status === 'unqualified'">This lead was written off.</template>
                <template v-else-if="lead.qualification.score === null">
                  Qualifying opens this as a deal. You can do it without answering
                  anything — but then the decision is the only record of why.
                </template>
                <template v-else>
                  Assessed at {{ lead.qualification.score }}%. Qualifying opens this
                  as a deal and takes the answers with it.
                </template>
              </p>

              <div v-if="!lead.opportunity" class="ld-decide-actions">
                <button class="btn-ghost" :disabled="saving || lead.status === 'unqualified'"
                        @click="writeOff">Not worth pursuing</button>
                <button class="btn-primary" :disabled="saving" @click="qualifyIntoDeal">
                  Qualify — open as a deal
                </button>
              </div>
            </div>
          </div>

          <!-- Decision makers -->
          <div class="ld-card ld-qual-people">
            <div class="ld-card-head">
              <h3 class="ld-block-label">Key Decision Makers</h3>
              <button class="btn-ghost" @click="showStakeholder = true">+ Add</button>
            </div>

            <p v-if="!lead.stakeholders?.length" class="ld-muted">
              Nobody named yet. Stakeholder Access above asks who we are talking
              to; this is where you say who they are.
            </p>

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
          </div>
        </div>
      </section>


      <!-- ── Correspondence ──────────────────────────────────────────────
           What we arranged, what arrived, and what pursuing this costs. -->
      <section v-else-if="tab === 'correspondence'" class="ld-panel">
        <nav class="ld-threads" role="tablist">
          <button v-for="t in threads" :key="t.key" role="tab" :aria-selected="thread === t.key"
                  :class="['ld-thread', { active: thread === t.key }]"
                  @click="thread = t.key">{{ t.label }}</button>
        </nav>

        <!-- Meetings -->
        <template v-if="thread === 'meetings'">
          <div class="ld-panel-actions">
            <button class="btn-primary" :disabled="busy" @click="openMeetingForm">+ Schedule a meeting</button>
          </div>

          <p v-if="busy && !loaded.meetings" class="ld-muted">Loading…</p>

          <div v-else-if="!meetings.length" class="ld-empty">
            <h3 class="ld-empty-title">No meetings yet</h3>
            <p class="ld-empty-sub">
              A Teams invitation goes out from the company calendar, to whoever
              you pick from the people named on this lead.
            </p>
            <button class="btn-primary" @click="openMeetingForm">Schedule a meeting</button>
          </div>

          <ul v-else class="ld-corr-list">
            <li v-for="m in meetings" :key="m.id" :class="['ld-corr', { spent: m.status === 'cancelled' }]">
              <div class="ld-corr-main">
                <h4 class="ld-corr-title">{{ m.subject }}</h4>
                <p class="ld-corr-meta">
                  {{ date(m.scheduled_at) }} · {{ m.duration_minutes }} min
                  <template v-if="m.attendees.length">
                    · {{ m.attendees.length }} invited
                  </template>
                  <template v-if="m.created_by"> · by {{ m.created_by }}</template>
                </p>
                <p v-if="m.agenda" class="ld-corr-body">{{ m.agenda }}</p>
              </div>

              <div class="ld-corr-side">
                <span v-if="m.status === 'cancelled'" class="pill pill-danger">Cancelled</span>
                <span v-else-if="m.is_past" class="pill pill-info">Held</span>
                <span v-else class="pill pill-ok">Scheduled</span>

                <a v-if="m.join_url && m.status !== 'cancelled'" :href="m.join_url"
                   class="link-btn" target="_blank" rel="noopener">Join</a>
                <a v-if="m.recording_url" :href="m.recording_url" class="link-btn"
                   target="_blank" rel="noopener">Recording</a>
                <button v-if="m.status !== 'cancelled' && !m.is_past" class="btn-ghost btn-sm"
                        :disabled="saving" @click="cancelMeeting(m)">Cancel</button>
              </div>
            </li>
          </ul>
        </template>

        <!-- Emails -->
        <template v-else-if="thread === 'emails'">
          <div class="ld-panel-actions">
            <button class="btn-primary" :disabled="busy" @click="syncEmails">
              {{ busy ? "Checking…" : "Check the mailbox" }}
            </button>
          </div>

          <!-- Said out loud, because an empty list means one of two very
               different things: nobody wrote, or nobody has been named. -->
          <p class="ld-hint ld-corr-note">
            <template v-if="correspondents.length">
              Searching the company mailbox for mail involving
              {{ correspondents.join(", ") }}.
            </template>
            <template v-else>
              Nobody is named on this lead, so there is nothing to search for.
              Add a decision maker under Qualifications, or contacts on the customer.
            </template>
          </p>

          <p v-if="busy && !loaded.emails" class="ld-muted">Loading…</p>

          <div v-else-if="!emails.length" class="ld-empty">
            <h3 class="ld-empty-title">Nothing filed yet</h3>
            <p class="ld-empty-sub">
              Mail is not watched — it is pulled when you ask for it, so what is
              here is what was here at the last check.
            </p>
          </div>

          <ul v-else class="ld-corr-list">
            <li v-for="e in emails" :key="e.id" class="ld-corr">
              <div class="ld-corr-main">
                <h4 class="ld-corr-title">{{ e.subject ?? "(no subject)" }}</h4>
                <p class="ld-corr-meta">
                  {{ e.from_name ?? e.from_email ?? "Unknown sender" }}
                  <template v-if="e.received_at"> · {{ date(e.received_at) }}</template>
                  <template v-if="e.has_attachments"> · has attachments</template>
                </p>

                <p v-if="openEmail !== e.id" class="ld-corr-body">{{ e.body_preview }}</p>
                <div v-else class="ld-corr-body ld-email-body" v-html="e.body_html ?? e.body_preview ?? ''"></div>

                <button class="link-btn ld-corr-more"
                        @click="openEmail = openEmail === e.id ? null : e.id">
                  {{ openEmail === e.id ? "Show less" : "Read it" }}
                </button>
              </div>

              <div class="ld-corr-side">
                <!-- Why this message is on this lead at all. -->
                <span v-if="e.matched_address" class="ld-hint">via {{ e.matched_address }}</span>
              </div>
            </li>
          </ul>
        </template>

        <!-- Requests -->
        <template v-else>
          <div class="ld-panel-actions">
            <button class="btn-primary" :disabled="busy" @click="openRequestForm">+ Raise a request</button>
          </div>

          <p v-if="busy && !loaded.requests" class="ld-muted">Loading…</p>

          <div v-else-if="!requests.length" class="ld-empty">
            <h3 class="ld-empty-title">Nothing asked for yet</h3>
            <p class="ld-empty-sub">
              Transport, travel, lunch or a site survey — what pursuing this lead
              costs before it is a deal. Someone else approves it, which is the
              point: the money goes on something that may never close.
            </p>
            <button class="btn-primary" @click="openRequestForm">Raise a request</button>
          </div>

          <ul v-else class="ld-corr-list">
            <li v-for="r in requests" :key="r.id" class="ld-corr">
              <div class="ld-corr-main">
                <h4 class="ld-corr-title">{{ r.kind_label }} — {{ r.purpose }}</h4>
                <p class="ld-corr-meta">
                  <span v-if="r.reference" class="ld-ref">{{ r.reference }}</span>
                  <template v-if="r.needed_on"> · needed {{ date(r.needed_on) }}</template>
                  <template v-if="r.location"> · {{ r.location }}</template>
                  <template v-if="r.people"> · {{ r.people }} people</template>
                  <template v-if="r.estimated_cost"> · {{ r.currency }} {{ money(r.estimated_cost) }}</template>
                  <template v-if="r.requested_by"> · asked by {{ r.requested_by }}</template>
                </p>
                <p v-if="r.notes" class="ld-corr-body">{{ r.notes }}</p>
                <p v-if="r.decision_comment" class="ld-corr-body ld-corr-decision">
                  {{ r.decided_by ?? "Decided" }}: {{ r.decision_comment }}
                </p>
              </div>

              <div class="ld-corr-side">
                <span :class="['pill', REQUEST_PILL[r.status] ?? 'pill-info']">{{ titleCase(r.status) }}</span>

                <template v-if="r.status === 'pending'">
                  <button class="btn-ghost btn-sm" :disabled="saving" @click="decideRequest(r, 'declined')">Decline</button>
                  <button class="btn-primary btn-sm" :disabled="saving" @click="decideRequest(r, 'approved')">Approve</button>
                  <button class="link-btn" :disabled="saving" @click="withdrawRequest(r)">Withdraw</button>
                </template>
                <button v-else-if="r.status === 'approved'" class="btn-ghost btn-sm"
                        :disabled="saving" @click="fulfilRequest(r)">Mark provided</button>
              </div>
            </li>
          </ul>
        </template>
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
    <!-- Schedule a meeting -->
    <div v-if="showMeeting" class="ld-modal-backdrop" @click.self="showMeeting = false">
      <div class="ld-modal ld-modal-lg ld-modal-picker" role="dialog" aria-modal="true" aria-label="Schedule a meeting">
        <div class="ld-modal-head">
          <h3 class="ld-modal-title">Schedule a meeting</h3>
          <button class="ld-close" aria-label="Close" @click="showMeeting = false">×</button>
        </div>

        <label class="ld-field"><span>Subject</span>
          <input v-model="meetingForm.subject" class="control" /></label>

        <div class="ld-grid">
          <label class="ld-field"><span>When</span>
            <input v-model="meetingForm.scheduled_at" class="control" type="datetime-local" /></label>
          <label class="ld-field"><span>Minutes</span>
            <input v-model.number="meetingForm.duration_minutes" class="control" type="number" min="5" max="480" /></label>
        </div>

        <label class="ld-field"><span>Agenda</span>
          <textarea v-model="meetingForm.agenda" class="control" rows="3"
                    placeholder="What this call is for."></textarea></label>

        <div class="ld-field">
          <span>Who to invite</span>

          <!-- Suggested: our side and theirs, both offered, because the first
               version listed only the customer's people and a lead with none
               could never book anything. -->
          <ul v-if="suggested.length" class="ld-invitees ld-invitees-scroll">
            <li v-for="a in suggested" :key="a.address">
              <label class="ld-invitee">
                <input type="checkbox" :checked="invited(a.address)" @change="toggleInvite(a)" />
                <span class="ld-invitee-name">{{ a.name ?? a.address }}</span>
                <span class="ld-invitee-side">{{ a.side === "ours" ? "ours" : "theirs" }}</span>
                <span class="ld-invitee-address">{{ a.address }}</span>
              </label>
            </li>
          </ul>

          <div class="ld-add-row">
            <EmployeePicker v-model="colleague" placeholder="Add a colleague by name…"
                            @chosen="inviteColleague" />
          </div>

          <div class="ld-guest-row">
            <input v-model="guestName" class="control" placeholder="Name (optional)" />
            <input v-model="guestEmail" class="control" type="email" placeholder="anyone@else.com"
                   @keydown.enter.prevent="inviteGuest" />
            <button class="btn-ghost" :disabled="!guestEmail.trim()" @click="inviteGuest">Add</button>
          </div>
        </div>

        <!-- What is actually going out, whichever way each person got here. -->
        <div class="ld-field">
          <span>Invitation goes to</span>
          <p v-if="!invitees.length" class="ld-hint">
            Nobody yet — tick somebody above, pick a colleague, or type an address.
          </p>
          <ul v-else class="ld-chips ld-chips-scroll">
            <li v-for="a in invitees" :key="a.address" class="ld-chip">
              {{ a.name ?? a.address }}
              <button class="ld-chip-x" :aria-label="`Remove ${a.name ?? a.address}`"
                      @click="uninvite(a.address)">×</button>
            </li>
          </ul>
        </div>

        <p class="ld-hint">
          A Microsoft Teams meeting is created on the company calendar and the
          join link goes out with the invitation.
        </p>

        <div class="ld-actions">
          <button class="btn-primary"
                  :disabled="saving || !meetingForm.subject || !meetingForm.scheduled_at || !invitees.length"
                  @click="scheduleMeeting">
            {{ saving ? "Creating the Teams meeting…" : "Create the Teams meeting" }}
          </button>
        </div>
      </div>
    </div>

    <!-- Raise a request -->
    <div v-if="showRequest" class="ld-modal-backdrop" @click.self="showRequest = false">
      <div class="ld-modal ld-modal-lg" role="dialog" aria-modal="true" aria-label="Raise a request">
        <div class="ld-modal-head">
          <h3 class="ld-modal-title">Raise a request</h3>
          <button class="ld-close" aria-label="Close" @click="showRequest = false">×</button>
        </div>

        <div class="ld-grid">
          <label class="ld-field"><span>What is needed</span>
            <select v-model="requestForm.kind" class="control">
              <option v-for="k in kinds" :key="k.value" :value="k.value">{{ k.label }}</option>
            </select></label>
          <label class="ld-field"><span>When</span>
            <input v-model="requestForm.needed_on" class="control" type="date" /></label>
        </div>

        <label class="ld-field ld-span"><span>What it is for</span>
          <input v-model="requestForm.purpose" class="control"
                 placeholder="Survey the Nakuru branch" /></label>

        <div class="ld-grid">
          <label class="ld-field"><span>Where</span>
            <input v-model="requestForm.location" class="control" placeholder="Nakuru" /></label>
          <label class="ld-field"><span>How many people</span>
            <input v-model.number="requestForm.people" class="control" type="number" min="1" /></label>
          <label class="ld-field"><span>Estimated cost (KES)</span>
            <input v-model.number="requestForm.estimated_cost" class="control" type="number" min="0" /></label>
        </div>

        <label class="ld-field ld-span"><span>Anything else</span>
          <textarea v-model="requestForm.notes" class="control" rows="3"></textarea></label>

        <p class="ld-hint">
          This goes to somebody else to approve — pre-sales money is spent on a
          deal that may never close, which is why it is asked for rather than
          claimed afterwards.
        </p>

        <div class="ld-actions">
          <button class="btn-primary" :disabled="saving || !requestForm.purpose" @click="submitRequest">
            {{ saving ? "Sending…" : "Raise it" }}
          </button>
        </div>
      </div>
    </div>

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
      <div class="ld-modal ld-modal-picker">
        <div class="ld-modal-head">
          <h3 class="ld-modal-title">Lead Team</h3>
          <button class="ld-close" aria-label="Close" @click="showTeam = false">×</button>
        </div>

        <p class="ld-hint">Ours — the people researching and shaping this lead.</p>

        <div class="ld-add-row">
          <EmployeePicker v-model="newMember.employee_id" placeholder="Add somebody…" />
          <button class="btn-primary" :disabled="saving || !newMember.employee_id" @click="addMember">Add</button>
        </div>

        <ul v-if="lead?.team?.length" class="ld-people ld-people-scroll">
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

.ld-banner { @apply flex flex-wrap items-center; gap: var(--space-2);
  padding: var(--space-3) var(--space-4); border-radius: var(--radius-md);
  background: var(--color-success-subtle); color: var(--color-success-text);
  font-size: var(--text-sm-size); margin-bottom: var(--space-4);
}

/* One strip, two columns inside it. The chrome moved from each list to the
   row around them: eight facts about one lead are one thing to read, and two
   bordered panels made them look like two. */
.ld-facts-row {
  @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0; margin-bottom: var(--space-5); overflow: hidden;
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
.ld-facts { margin: 0; padding: var(--space-4) var(--space-5); }
.ld-facts + .ld-facts { border-left: 1px solid var(--color-border); }
@media (max-width: 48rem) {
  .ld-facts-row { grid-template-columns: 1fr; }
  .ld-facts + .ld-facts { border-left: none; border-top: 1px solid var(--color-border); }
}
.ld-facts > div { @apply flex items-center; gap: var(--space-3); padding: var(--space-2) 0; min-height: 2rem; }
.ld-facts > div + div { border-top: 1px solid var(--color-border); }
.ld-facts dt { font-size: var(--text-sm-size); color: var(--color-text-muted); min-width: 7rem; }
.ld-facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }

/* No overflow-x: the bar wraps, so it never scrolls sideways — and asking
   for overflow on one axis computes the other to auto, which is what put a
   vertical scrollbar beside five tabs that fit. */
.ld-tabs { @apply flex flex-wrap gap-1; border-bottom: 1px solid var(--color-border); }
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
.ld-block-label {
  font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0;
}
.ld-prose { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; white-space: pre-wrap; line-height: 1.65; }
.ld-strong { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ld-link   { font-size: var(--text-sm-size); color: var(--color-brand-on-surface); margin: 0; }
.ld-muted  { font-size: var(--text-sm-size); color: var(--color-text-light); margin: 0; }
/* It sits inside a button; it should look like one thing you can press. */
.ld-add-inline { font-size: var(--text-sm-size); color: var(--color-brand-on-surface); font-weight: 500; }
.ld-hint   { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0; }
.ld-req    { font-style: normal; color: var(--color-danger-text); }
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
/* Score and decision-makers stack down the narrow column; the questions —
   the thing you came to answer — take the wide one beside them for their
   whole height, so opening a question never pushes another card around. */
.ld-qual-grid {
  @apply grid; gap: var(--space-4); align-items: start;
  grid-template-columns: minmax(0, 22rem) minmax(0, 1fr);
}
.ld-qual-score  { grid-column: 1; grid-row: 1; }
.ld-qual-people { grid-column: 1; grid-row: 2; }
.ld-qual-assess { grid-column: 2; grid-row: 1 / span 2; }
/* One column: the explicit placement above has to be released too, or the
   cards keep trying for a column 2 that no longer exists. */
@media (max-width: 56rem) {
  .ld-qual-grid { grid-template-columns: 1fr; }
  .ld-qual-score, .ld-qual-people, .ld-qual-assess { grid-column: auto; grid-row: auto; }
  /* Answer first; the score is a consequence of the answers. */
  .ld-qual-assess { order: -1; }
}

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

.ld-bar-track { @apply block; flex: 1; height: 8px; border-radius: 999px; background: var(--color-surface-sunken); overflow: hidden; }
.ld-bar-fill  { @apply block; height: 100%; border-radius: 999px; background: var(--color-brand-base); transition: width 240ms ease; }

/* ── The five questions ───────────────────────────────────────────────────
   A row per question, readable shut and answerable open. The bar under each
   row is what makes the list scannable once every question is answered and
   the pills all read differently. */
.ld-questions { @apply list-none flex flex-col; margin: 0; padding: 0; }
.ld-question  { @apply flex flex-col; gap: var(--space-2); padding: var(--space-3) 0; }
.ld-question + .ld-question { border-top: 1px solid var(--color-border); }

.ld-question-head {
  @apply flex items-start justify-between w-full text-left cursor-pointer border-none;
  gap: var(--space-3); padding: 0; background: none;
}
.ld-question-text  { @apply flex flex-col; gap: 2px; min-width: 0; }
.ld-question-label { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); }
.ld-question-ask   { font-size: var(--text-xs-size); color: var(--color-text-light); }

.ld-question-state { @apply flex items-center; gap: var(--space-2); flex-shrink: 0; }
.ld-question-points {
  font-size: var(--text-xs-size); font-family: var(--font-family-mono);
  color: var(--color-text-muted); width: 2.75rem; text-align: right;
  font-variant-numeric: tabular-nums;
}
/* Dashed, because an unanswered question is an outline of one. */
.ld-unanswered {
  font-size: var(--text-xs-size); color: var(--color-text-light);
  padding: 2px var(--space-2); border-radius: var(--radius-full);
  border: 1px dashed var(--color-border-strong);
}
/* The list is a column, so the shared bar rule's flex:1 would stretch it. */
.ld-question .ld-bar-track { flex: none; }

.ld-options {
  @apply grid; gap: var(--space-2); margin-top: var(--space-1);
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
}
.ld-option {
  @apply flex flex-col cursor-pointer text-left; gap: 2px;
  padding: var(--space-3); border-radius: var(--radius-md);
  border: 1px solid var(--color-border); background: var(--color-surface-raised);
  transition: background-color 120ms ease, border-color 120ms ease;
}
.ld-option:hover:not(:disabled) { background: var(--color-neutral-100); }
.ld-option:disabled { @apply cursor-wait opacity-60; }
.ld-option.chosen {
  border-color: var(--color-brand-base); background: var(--color-brand-light);
}
.ld-option-label  { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); }
.ld-option-help   { font-size: var(--text-xs-size); color: var(--color-text-muted); line-height: 1.5; }
.ld-option-points {
  font-size: var(--text-xs-size); font-family: var(--font-family-mono);
  color: var(--color-text-light); margin-top: var(--space-1);
}
.ld-option.chosen .ld-option-points { color: var(--color-brand-on-surface); }
.ld-option-note { grid-column: 1 / -1; }

.ld-notes { margin-top: var(--space-4); }

/* The decision, on the screen that informed it. */
.ld-decide {
  @apply flex flex-wrap items-center justify-between;
  gap: var(--space-3); margin-top: var(--space-4);
  padding-top: var(--space-4); border-top: 1px solid var(--color-border);
}
.ld-decide-actions { @apply flex; gap: var(--space-2); }


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
.pill-danger  { background: var(--color-danger-subtle);  color: var(--color-danger-text); border-color: color-mix(in oklab, var(--color-danger-text) 30%, transparent); }

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

/* ── Correspondence ───────────────────────────────────────────────────────
   Three lists under one tab. They share a row shape — what it is on the left,
   what you can do about it on the right — because they are read the same way
   even though they are three different kinds of record. */
.ld-threads { @apply flex flex-wrap; gap: var(--space-1); margin-bottom: var(--space-4); }
.ld-thread {
  @apply cursor-pointer border-none; padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-full); background: transparent;
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  transition: background-color 120ms ease, color 120ms ease;
}
.ld-thread:hover { background: var(--color-neutral-200); }
.ld-thread.active { background: var(--color-brand-base); color: var(--color-on-brand); }

.ld-corr-note { margin-bottom: var(--space-3); }

.ld-corr-list { @apply list-none flex flex-col; margin: 0; padding: 0; gap: var(--space-2); }
.ld-corr {
  @apply flex flex-wrap items-start justify-between;
  gap: var(--space-3); padding: var(--space-4);
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border); border-radius: var(--radius-lg);
}
/* Cancelled, declined — still on the record, visibly spent. */
.ld-corr.spent { opacity: 0.62; }

.ld-corr-main  { @apply flex flex-col; gap: var(--space-1); flex: 1; min-width: 16rem; }
.ld-corr-title { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ld-corr-meta  { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0; }
.ld-corr-body  { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; line-height: 1.6; white-space: pre-wrap; }
.ld-corr-decision { padding-left: var(--space-3); border-left: 2px solid var(--color-border-strong); }
.ld-corr-more  { align-self: flex-start; }
.ld-corr-side  { @apply flex flex-wrap items-center; gap: var(--space-2); flex-shrink: 0; }

.ld-ref { font-family: var(--font-family-mono); }

/* Mail is somebody else's HTML: it must not push the page sideways or bring
   its own typography in. */
.ld-email-body { max-width: 100%; overflow-x: auto; }
.ld-email-body :deep(img) { max-width: 100%; height: auto; }
.ld-email-body :deep(table) { max-width: 100%; }
.ld-email-body :deep(*) { font-size: var(--text-sm-size) !important; font-family: inherit !important; }

.ld-invitees { @apply list-none flex flex-col; margin: 0; padding: 0; gap: var(--space-1); }
.ld-invitee {
  @apply flex items-center cursor-pointer; gap: var(--space-2);
  padding: var(--space-2); border-radius: var(--radius-md);
}
.ld-invitee:hover { background: var(--color-neutral-100); }
.ld-invitee-name    { font-size: var(--text-sm-size); color: var(--color-text); }
.ld-invitee-address { font-size: var(--text-xs-size); color: var(--color-text-light); }
/* Which side of the table they sit on — an invitation that quietly includes
   the customer when you meant an internal huddle is worth one word to avoid. */
.ld-invitee-side {
  font-size: var(--text-xs-size); color: var(--color-text-light);
  padding: 1px var(--space-2); border-radius: var(--radius-full);
  border: 1px solid var(--color-border-strong);
}

.ld-guest-row { @apply grid; gap: var(--space-2); grid-template-columns: 1fr 1.4fr auto; margin-top: var(--space-2); }
@media (max-width: 32rem) { .ld-guest-row { grid-template-columns: 1fr; } }

.ld-chips { @apply list-none flex flex-wrap; margin: 0; padding: 0; gap: var(--space-2); }
.ld-chip {
  @apply inline-flex items-center; gap: var(--space-1);
  padding: 2px var(--space-2); border-radius: var(--radius-full);
  background: var(--color-brand-light); color: var(--color-brand-on-surface);
  font-size: var(--text-xs-size);
}
.ld-chip-x { @apply cursor-pointer border-none bg-transparent p-0; color: inherit; font-size: var(--text-sm-size); line-height: 1; }

/* The stakeholder and document dialogs are "are you sure" width; a meeting
   with an attendee list and a request with six fields are not. */
.ld-modal-lg { max-width: 38rem; }

/* A dialog holding a typeahead must not clip it.
   `.ld-modal` scrolls itself, and a scrolling box crops the absolutely
   positioned dropdown inside it — which left the picker showing one result and
   a scrollbar. The dialog spills instead, and the list below it takes the
   scroll, so nothing here ever needs the modal to. */
.ld-modal-picker { overflow: visible; }
.ld-people-scroll { max-height: 40vh; overflow-y: auto; }
/* The same bargain in the meeting dialog: it spills so the typeahead is not
   cropped, and its two growable lists take the scroll instead, so the dialog
   itself never needs to. */
.ld-invitees-scroll { max-height: 22vh; overflow-y: auto; }
.ld-chips-scroll    { max-height: 14vh; overflow-y: auto; }

.btn-sm { padding: 4px var(--space-3); font-size: var(--text-xs-size); }

</style>
