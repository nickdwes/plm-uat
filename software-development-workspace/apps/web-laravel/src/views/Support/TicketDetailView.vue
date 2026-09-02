<script setup lang="ts">
/**
 * A ticket, on its own page.
 *
 * This used to be a dialog stretched to 88rem over the queue list — an email
 * thread and sixteen sidebar controls sharing whatever width was left after
 * the modal's own padding. A page gives the thread room to actually read an
 * email in, and the sidebar room to read as sections instead of one long
 * column of sixteen equally-weighted fields.
 *
 * Every action here (reply, classify, route, escalate, split, merge, log
 * time) is the same endpoint the old dialog called — nothing about what a
 * ticket can do changed, only how much room it has to do it in.
 */
import { computed, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug, getToken } from "../../stores/auth";
import { excerpt, splitQuoted } from "../../lib/richText";

const props = defineProps<{ id: string | number }>();
const router = useRouter();

function org(): string | null { return currentOrgSlug.value; }

// ── Types (same shapes SupportView.vue's dialog used) ───────────────────────
interface Named { id: number; name: string }
interface Status extends Named { state: string; colour: string | null; is_default: boolean }
interface Priority extends Named { level: number; colour: string | null }

interface TicketClock {
  response_due_at: string | null; resolution_due_at: string | null;
  response_met: boolean | null; resolution_met: boolean | null;
  paused_at: string | null; paused_minutes: number;
  response_minutes: number | null; resolution_minutes: number | null;
  response_minutes_used: number; resolution_minutes_used: number;
  escalation_level: string | null;
}

interface Message {
  id: number; visibility: "public" | "internal"; body: string;
  author_name: string | null; author: { id: number; name: string } | null;
  is_from_agent: boolean; source: string; created_at: string;
}

interface Worklog {
  id: number; minutes: number; performed_on: string; note: string | null;
  under_contract: boolean; user: { id: number; name: string } | null;
}

interface TicketEventRow {
  id: number; event: string; field: string | null;
  from_value: string | null; to_value: string | null; reason: string | null;
  automatic: boolean; occurred_at: string;
  actor: { id: number; name: string } | null;
}

interface EmailParticipant { name: string | null; address: string; kind: string; internal: boolean }

interface TicketMeetingRow {
  id: number; subject: string; scheduled_at: string; duration_minutes: number;
  attendees: { name: string | null; address: string }[];
  join_url: string | null; recording_url: string | null; transcript: string | null;
  status: "scheduled" | "cancelled"; cancelled_at: string | null;
  creator: { id: number; name: string } | null;
}

interface RcaAction { action: string; owner: string; priority: string; due_date: string; status: string }
interface RcaWhy { question: string; answer: string }
interface RcaTimelineRow { time: string; event: string }

interface RcaData {
  id?: number;
  title: string | null;
  incident_date: string | null;
  prepared_date: string | null;
  system_affected: string | null;
  business_unit: string | null;
  severity: string | null;
  incident_owner: string | null;
  technical_owner: string | null;
  reviewers: string[];
  executive_summary: string | null;
  incident_description: {
    symptoms: string; systems_affected: string; users_affected: string;
    error_messages: string; detection_method: string; initial_observations: string;
  };
  business_impact: {
    users_affected_count: string; transactions_affected: string; revenue_impact: string;
    sla_impact: string; operational_impact: string; customer_impact: string;
    compliance_security_impact: string; data_loss: string;
  };
  timeline: RcaTimelineRow[];
  technical_investigation: string | null;
  immediate_cause: string | null;
  root_cause: string | null;
  contributing_factors: string | null;
  control_failure: string | null;
  five_whys: RcaWhy[];
  final_root_cause: string | null;
  root_cause_category: string | null;
  resolution: string | null;
  corrective_actions: RcaAction[];
  preventive_actions: RcaAction[];
  lessons_learned: string | null;
  evidence: string | null;
  risk_assessment: {
    similar_systems: string; probability: string; impact: string;
    existing_controls: string; control_gaps: string; recommended_treatment: string;
  };
  status: "draft" | "final";
  evidence_files?: RcaEvidenceFileRow[];
}

interface RcaEvidenceFileRow {
  id: number; original_name: string; mime_type: string | null; size_bytes: number | null;
  uploaded_by: { id: number; name: string } | null; created_at: string;
}

const RCA_CATEGORIES = [
  "Application", "Infrastructure", "Network", "Database", "Security",
  "Configuration", "Deployment", "Process", "Human error", "Vendor",
  "Capacity", "Monitoring", "Change management",
];

function blankRca(): RcaData {
  return {
    title: null, incident_date: null, prepared_date: null, system_affected: null, business_unit: null,
    severity: null, incident_owner: null, technical_owner: null, reviewers: [],
    executive_summary: null,
    incident_description: { symptoms: "", systems_affected: "", users_affected: "", error_messages: "", detection_method: "", initial_observations: "" },
    business_impact: { users_affected_count: "", transactions_affected: "", revenue_impact: "", sla_impact: "", operational_impact: "", customer_impact: "", compliance_security_impact: "", data_loss: "" },
    timeline: [], technical_investigation: null,
    immediate_cause: null, root_cause: null, contributing_factors: null, control_failure: null,
    five_whys: [1, 2, 3, 4, 5].map(() => ({ question: "", answer: "" })), final_root_cause: null,
    root_cause_category: null, resolution: null,
    corrective_actions: [], preventive_actions: [],
    lessons_learned: null, evidence: null,
    risk_assessment: { similar_systems: "", probability: "", impact: "", existing_controls: "", control_gaps: "", recommended_treatment: "" },
    status: "draft",
  };
}

interface TicketDetail {
  id: number; reference: string; subject: string;
  sla: TicketClock | null;
  team?: { id: number; name: string } | null;
  assignee?: { id: number; name: string } | null;
  requester_name: string | null; requester_email: string | null;
  status: Status | null; type: Named | null; priority: Priority | null;
  account: { id: number; name: string } | null;
  opened_at: string | null; first_response_at: string | null; resolved_at: string | null; closed_at?: string | null;
  body: string | null;
  assets?: { id: number; name: string; asset_tag: string | null }[];
  split_from?: { id: number; reference: string; subject: string } | null;
  splits?: { id: number; reference: string; subject: string }[];
  service_category?: { id: number; name: string; is_technical: boolean } | null;
  escalations?: { id: number; level: string; reason: string; outcome: string | null;
                  escalated_at: string; resolved_at: string | null;
                  to?: { name: string } | null; by?: { name: string } | null }[];
  parent?: { id: number; reference: string; subject: string } | null;
  children?: { id: number; reference: string; subject: string }[];
  urgency: Named | null; impact: Named | null;
  messages: Message[]; worklogs: Worklog[];
  events?: TicketEventRow[];
  issue_summary: string | null;
  current_owner?: "support" | "client" | "vendor" | null;
  owner_set_at?: string | null;
  meetings?: TicketMeetingRow[];
}

interface Suggestion {
  user_id: number; name: string | null; reason: string;
  score: number; open: number; pending: number; at_risk: number; available: boolean;
}

interface TeamRow {
  id: number; name: string;
  business_unit?: { id: number; name: string } | null;
  practice?: { id: number; name: string } | null;
  is_service_desk?: boolean; is_vendor_queue?: boolean;
}
interface CannedRow { id: number; title: string; body: string; use_count: number }
interface CategoryRow { id: number; name: string; is_technical: boolean }

// ── Load ─────────────────────────────────────────────────────────────────
const taxonomy = ref<{ statuses: Status[]; types: Named[]; urgencies: Named[]; impacts: Named[] }>(
  { statuses: [], types: [], urgencies: [], impacts: [] });
const teams = ref<TeamRow[]>([]);
const canned = ref<CannedRow[]>([]);
const categories = ref<CategoryRow[]>([]);

const ticket = ref<TicketDetail | null>(null);
const loading = ref(false);
const error = ref("");
const minutesLogged = ref(0);
const slaUsed = ref(0);
const elapsed = ref(0);
const noticeMessage = ref("");
const emailParticipants = ref<EmailParticipant[]>([]);

// ── Layout state: collapsible thread, tabbed sidebar ────────────────────────
const threadOpen = ref(true);
function toggleThread(): void { threadOpen.value = !threadOpen.value; }

type SidebarTab = "client" | "internal" | "issue" | "related" | "resources" | "effort" | "meetings" | "rca" | "timeline";
const sidebarTab = ref<SidebarTab>("internal");

const pendingStates = computed(() => taxonomy.value.statuses.filter((s) => s.state === "pending"));

async function loadTaxonomy(): Promise<void> {
  try { taxonomy.value = await api("GET", "/api/v1/support/taxonomy", { org: org() }); }
  catch { /* pickers stay empty */ }
}

async function loadTeamsAndCanned(): Promise<void> {
  try {
    const res = await api<{ teams: TeamRow[]; canned: CannedRow[] }>("GET", "/api/v1/support/workspace", { org: org() });
    teams.value = res.teams; canned.value = res.canned;
  } catch { /* selectors stay empty */ }
}

async function loadCategories(): Promise<void> {
  try {
    const res = await api<{ categories: CategoryRow[] }>("GET", "/api/v1/support/contracts", { org: org() });
    categories.value = res.categories;
  } catch { /* selector stays empty */ }
}

async function loadTicket(): Promise<void> {
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: TicketDetail; email_participants: EmailParticipant[]; minutes_logged: number; sla_minutes_used: number; elapsed_minutes: number }>(
      "GET", `/api/v1/support/tickets/${props.id}`, { org: org() });
    ticket.value = res.data;
    emailParticipants.value = res.email_participants ?? [];
    issueSummaryDraft.value = res.data.issue_summary ?? "";
    minutesLogged.value = res.minutes_logged;
    slaUsed.value = res.sla_minutes_used;
    elapsed.value = res.elapsed_minutes;
    void loadSuggestions();
    void loadSuggested();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Could not open the ticket.";
  } finally { loading.value = false; }
}

async function refresh(): Promise<void> { await loadTicket(); }

// ── Reply / notes ────────────────────────────────────────────────────────
const reply = ref({ body: "", visibility: "public" as "public" | "internal" });
const replyBusy = ref(false);
const showCanned = ref(false);

async function sendReply(): Promise<void> {
  if (!ticket.value || !reply.value.body.trim()) return;
  replyBusy.value = true; error.value = "";
  try {
    await api("POST", `/api/v1/support/tickets/${ticket.value.id}/reply`, {
      org: org(), body: { body: reply.value.body, visibility: reply.value.visibility },
    });
    reply.value.body = "";
    await refresh();
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not post it.";
  } finally { replyBusy.value = false; }
}

async function broadcast(): Promise<void> {
  if (!ticket.value || !reply.value.body.trim()) return;
  replyBusy.value = true; error.value = "";
  try {
    const res = await api<{ message: string }>("POST", `/api/v1/support/tickets/${ticket.value.id}/broadcast`,
      { org: org(), body: { body: reply.value.body } });
    notice(res.message);
    reply.value.body = "";
    await refresh();
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not post it.";
  } finally { replyBusy.value = false; }
}

async function useCanned(id: number): Promise<void> {
  if (!ticket.value) return;
  try {
    const res = await api<{ body: string }>("POST", `/api/v1/support/workspace/canned/${id}/use`,
      { org: org(), body: { ticket_id: ticket.value.id } });
    reply.value.body = reply.value.body ? `${reply.value.body}\n\n${res.body}` : res.body;
    showCanned.value = false;
  } catch (e) { error.value = e instanceof Error ? e.message : "Could not load that response."; }
}

function notice(msg: string): void {
  noticeMessage.value = msg;
  setTimeout(() => { noticeMessage.value = ""; }, 6000);
}

// ── Status / classification ─────────────────────────────────────────────
async function setStatus(statusId: number): Promise<void> {
  if (!ticket.value) return;
  error.value = "";
  try {
    await api("POST", `/api/v1/support/tickets/${ticket.value.id}/status`, { org: org(), body: { ticket_status_id: statusId } });
    await refresh();
  } catch (e) { error.value = e instanceof ApiError ? e.message : "Could not change the status."; }
}

async function classify(field: string, value: string): Promise<void> {
  if (!ticket.value) return;
  error.value = "";
  try {
    await api("POST", `/api/v1/support/tickets/${ticket.value.id}/classify`, { org: org(), body: { [field]: value ? Number(value) : null } });
    await refresh();
  } catch (e) { error.value = e instanceof Error ? e.message : "Could not reclassify it."; }
}

// ── Issue summary: the agent's own words, for the record and for an RCA ────
const issueSummaryDraft = ref("");
const issueSummaryBusy = ref(false);

async function saveIssueSummary(): Promise<void> {
  if (!ticket.value) return;
  issueSummaryBusy.value = true; error.value = "";
  try {
    await api("POST", `/api/v1/support/tickets/${ticket.value.id}/issue-summary`, {
      org: org(), body: { issue_summary: issueSummaryDraft.value.trim() || null },
    });
    await refresh();
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not save the summary.";
  } finally { issueSummaryBusy.value = false; }
}

// ── Schedule a Teams call ───────────────────────────────────────────────────
const showScheduleMeeting = ref(false);
const meetingBusy = ref(false);
const meetingDraft = ref({ subject: "", scheduled_at: "", duration_minutes: 30, agenda: "", extra_attendees: "" });
const meetingAttendeeChecks = ref<Record<string, boolean>>({});

const meetingCandidates = computed(() => {
  const t = ticket.value;
  if (!t) return [];
  const list: { name: string | null; address: string }[] = [];
  if (t.requester_email) list.push({ name: t.requester_name, address: t.requester_email });
  for (const p of clientParticipants.value) list.push({ name: p.name, address: p.address });
  for (const p of internalParticipants.value) list.push({ name: p.name, address: p.address });
  const seen = new Set<string>();
  return list.filter((p) => {
    const a = p.address.toLowerCase();
    if (seen.has(a)) return false;
    seen.add(a); return true;
  });
});

function openScheduleMeeting(): void {
  if (!ticket.value) return;
  meetingDraft.value = {
    subject: `Call: ${ticket.value.reference} — ${ticket.value.subject}`,
    scheduled_at: "", duration_minutes: 30, agenda: "", extra_attendees: "",
  };
  meetingAttendeeChecks.value = {};
  for (const c of meetingCandidates.value) meetingAttendeeChecks.value[c.address] = true;
  showScheduleMeeting.value = true;
}

function scheduleFromHeader(): void {
  sidebarTab.value = "meetings";
  openScheduleMeeting();
}

const upcomingMeetingCount = computed(
  () => ticket.value?.meetings?.filter((m) => m.status === "scheduled").length ?? 0,
);

async function scheduleMeeting(): Promise<void> {
  if (!ticket.value || !meetingDraft.value.scheduled_at) return;

  const attendees = meetingCandidates.value
    .filter((c) => meetingAttendeeChecks.value[c.address])
    .map((c) => ({ name: c.name, address: c.address }));
  for (const raw of meetingDraft.value.extra_attendees.split(",")) {
    const address = raw.trim();
    if (address) attendees.push({ name: null, address });
  }
  if (!attendees.length) { error.value = "Add at least one attendee."; return; }

  meetingBusy.value = true; error.value = "";
  try {
    await api("POST", `/api/v1/support/tickets/${ticket.value.id}/meetings`, {
      org: org(), body: {
        subject: meetingDraft.value.subject,
        scheduled_at: new Date(meetingDraft.value.scheduled_at).toISOString(),
        duration_minutes: meetingDraft.value.duration_minutes,
        agenda: meetingDraft.value.agenda.trim() || null,
        attendees,
      },
    });
    showScheduleMeeting.value = false;
    await refresh();
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not schedule the call.";
  } finally { meetingBusy.value = false; }
}

async function cancelMeeting(id: number): Promise<void> {
  if (!ticket.value) return;
  try {
    await api("DELETE", `/api/v1/support/tickets/${ticket.value.id}/meetings/${id}`, { org: org() });
    await refresh();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Could not cancel the call.";
  }
}

// ── Root Cause Analysis ──────────────────────────────────────────────────
const rca = ref<RcaData>(blankRca());
const rcaLoaded = ref(false);
const rcaBusy = ref(false);
const rcaPdfBusy = ref(false);

async function loadRca(): Promise<void> {
  if (!ticket.value || rcaLoaded.value) return;
  try {
    const res = await api<{ data: RcaData | null }>("GET", `/api/v1/support/tickets/${ticket.value.id}/rca`, { org: org() });
    if (res.data) {
      const blank = blankRca();
      rca.value = {
        ...blank, ...res.data,
        reviewers: res.data.reviewers ?? [],
        incident_description: { ...blank.incident_description, ...(res.data.incident_description ?? {}) },
        business_impact: { ...blank.business_impact, ...(res.data.business_impact ?? {}) },
        timeline: res.data.timeline ?? [],
        five_whys: res.data.five_whys?.length ? res.data.five_whys : blank.five_whys,
        corrective_actions: res.data.corrective_actions ?? [],
        preventive_actions: res.data.preventive_actions ?? [],
        risk_assessment: { ...blank.risk_assessment, ...(res.data.risk_assessment ?? {}) },
      };
    } else {
      rca.value = blankRca();
      rca.value.title = `RCA — ${ticket.value.reference} — ${ticket.value.subject}`;
      rca.value.prepared_date = new Date().toISOString().slice(0, 10);
      rca.value.timeline = timeline.value.map((t) => ({ time: when(t.at), event: t.label + (t.detail ? `: ${t.detail}` : "") }));
    }
    rcaLoaded.value = true;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Could not load the RCA.";
  }
}

async function saveRca(): Promise<void> {
  if (!ticket.value) return;
  rcaBusy.value = true; error.value = "";
  try {
    const res = await api<{ data: RcaData }>("PUT", `/api/v1/support/tickets/${ticket.value.id}/rca`, { org: org(), body: rca.value });
    rca.value = { ...rca.value, ...res.data };
    notice("RCA saved.");
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not save the RCA.";
  } finally { rcaBusy.value = false; }
}

async function downloadRcaPdf(): Promise<void> {
  if (!ticket.value) return;
  rcaPdfBusy.value = true; error.value = "";
  try {
    const res = await fetch(
      `${import.meta.env.VITE_LARAVEL_API_URL ?? ""}/api/v1/support/tickets/${ticket.value.id}/rca/pdf?org=${org() ?? ""}`,
      { headers: { Authorization: `Bearer ${getToken() ?? ""}`, "X-Organization": org() ?? "" } },
    );
    if (!res.ok) {
      const body = await res.json().catch(() => ({}));
      error.value = body.message ?? "Could not build the RCA PDF.";
      return;
    }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `RCA-${ticket.value.reference}.pdf`;
    a.click();
    URL.revokeObjectURL(url);
  } catch {
    error.value = "Could not reach the server.";
  } finally { rcaPdfBusy.value = false; }
}

function addRcaAction(list: "corrective_actions" | "preventive_actions"): void {
  rca.value[list].push({ action: "", owner: "", priority: "Medium", due_date: "", status: "Open" });
}
function removeRcaAction(list: "corrective_actions" | "preventive_actions", i: number): void {
  rca.value[list].splice(i, 1);
}
function addTimelineRow(): void { rca.value.timeline.push({ time: "", event: "" }); }
function removeTimelineRow(i: number): void { rca.value.timeline.splice(i, 1); }

// ── RCA evidence files ───────────────────────────────────────────────────
const evidenceUploading = ref(false);
const evidenceError = ref("");

function formatBytes(bytes: number | null): string {
  if (!bytes) return "";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function evidenceFileIcon(mime: string | null): string {
  if (mime === "application/pdf") return "📄";
  if (mime?.startsWith("image/")) return "🖼️";
  return "📎";
}

const EVIDENCE_ALLOWED = [
  "application/pdf", "image/jpeg", "image/png", "image/webp",
  "application/msword", "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
];
const EVIDENCE_MAX_BYTES = 10 * 1024 * 1024;

async function onEvidenceFilesPicked(e: Event): Promise<void> {
  const input = e.target as HTMLInputElement;
  const files = input.files ? Array.from(input.files) : [];
  input.value = "";
  for (const file of files) await uploadEvidenceFile(file);
}

async function uploadEvidenceFile(file: File): Promise<void> {
  if (!ticket.value) return;
  evidenceError.value = "";
  if (!EVIDENCE_ALLOWED.includes(file.type)) {
    evidenceError.value = `"${file.name}" has an unsupported file type. Allowed: PDF, JPEG, PNG, WEBP, DOC, DOCX.`;
    return;
  }
  if (file.size > EVIDENCE_MAX_BYTES) {
    evidenceError.value = `"${file.name}" exceeds the 10 MB limit (${formatBytes(file.size)}).`;
    return;
  }

  evidenceUploading.value = true;
  const formData = new FormData();
  formData.append("file", file);
  try {
    const res = await api<{ data: RcaEvidenceFileRow }>("POST", `/api/v1/support/tickets/${ticket.value.id}/rca/evidence`, { org: org(), formData });
    rca.value.evidence_files = [res.data, ...(rca.value.evidence_files ?? [])];
  } catch (e) {
    evidenceError.value = e instanceof ApiError ? (e.firstError ?? e.message) : `Could not upload "${file.name}".`;
  } finally { evidenceUploading.value = false; }
}

async function downloadEvidenceFile(f: RcaEvidenceFileRow): Promise<void> {
  if (!ticket.value) return;
  try {
    const res = await fetch(
      `${import.meta.env.VITE_LARAVEL_API_URL ?? ""}/api/v1/support/tickets/${ticket.value.id}/rca/evidence/${f.id}/download?org=${org() ?? ""}`,
      { headers: { Authorization: `Bearer ${getToken() ?? ""}`, "X-Organization": org() ?? "" } },
    );
    if (!res.ok) { evidenceError.value = "Could not download that file."; return; }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url; a.download = f.original_name; a.click();
    URL.revokeObjectURL(url);
  } catch { evidenceError.value = "Could not reach the server."; }
}

async function deleteEvidenceFile(f: RcaEvidenceFileRow): Promise<void> {
  if (!ticket.value) return;
  try {
    await api("DELETE", `/api/v1/support/tickets/${ticket.value.id}/rca/evidence/${f.id}`, { org: org() });
    rca.value.evidence_files = (rca.value.evidence_files ?? []).filter((x) => x.id !== f.id);
  } catch (e) {
    evidenceError.value = e instanceof Error ? e.message : "Could not remove that file.";
  }
}

const priorityHint = computed(() => {
  if (!ticket.value) return "";
  return ticket.value.priority ? "From impact and urgency" : "Set impact and urgency to derive it";
});

// ── Service level ────────────────────────────────────────────────────────
function clockState(t: { sla: TicketClock | null }): { label: string; tone: "ok" | "warn" | "bad" | "mute" } {
  const c = t.sla;
  if (!c) return { label: "No target", tone: "mute" };
  if (c.paused_at) return { label: "Paused", tone: "mute" };
  if (c.response_met === false) return { label: "Response breached", tone: "bad" };
  if (c.resolution_met === false) return { label: "Resolution breached", tone: "bad" };
  const due = c.response_met === null ? c.response_due_at : c.resolution_due_at;
  const what = c.response_met === null ? "Reply" : "Fix";
  if (!due) return { label: "No target", tone: "mute" };
  const left = Math.round((new Date(due).getTime() - Date.now()) / 60000);
  if (left < 0) return { label: `${what} overdue`, tone: "bad" };
  if (left < 60) return { label: `${what} due in ${left}m`, tone: "warn" };
  const hours = Math.floor(left / 60);
  return hours < 24 ? { label: `${what} due in ${hours}h`, tone: "ok" } : { label: `${what} due in ${Math.floor(hours / 24)}d`, tone: "ok" };
}

function hm(minutes: number): string {
  const d = Math.floor(minutes / 1440);
  const h = Math.floor((minutes % 1440) / 60);
  const m = minutes % 60;
  if (d > 0) return `${d}d ${h}h ${m}m`;
  if (h > 0) return `${h}h ${m}m`;
  return `${m}m`;
}

// ── Who owned the next move, and for how long ───────────────────────────────
// Built from the ticket's own owner_changed events — auto-written whenever a
// public message goes out or comes in — rather than the SLA pause/resume
// trail, because that trail only exists once a ticket has a priority to pause
// against. A ticket with real correspondence but no SLA clock still has a
// full, accurate ownership history this way.
function minutesBetween(a: string, b: string): number {
  return Math.max(0, Math.round((new Date(b).getTime() - new Date(a).getTime()) / 60000));
}

type Owner = "support" | "client" | "vendor";
const OWNER_LABEL: Record<Owner, string> = { support: "Support", client: "Client", vendor: "Vendor" };

interface ResponsibilityPeriod { owner: Owner; from: string; to: string | null; minutes: number; reason?: string; ongoing?: boolean }

const responsibilityPeriods = computed<ResponsibilityPeriod[]>(() => {
  const t = ticket.value;
  if (!t?.opened_at) return [];
  const events = (t.events ?? [])
    .filter((e) => e.event === "owner_changed")
    .slice()
    .sort((a, b) => a.occurred_at.localeCompare(b.occurred_at));

  const periods: ResponsibilityPeriod[] = [];
  let cursor = t.opened_at;
  let owner: Owner = "support";

  for (const e of events) {
    const to = (e.to_value as Owner) ?? "support";
    periods.push({ owner, from: cursor, to: e.occurred_at, minutes: minutesBetween(cursor, e.occurred_at), reason: e.reason ?? undefined });
    cursor = e.occurred_at; owner = to;
  }

  const end = t.resolved_at ?? t.closed_at ?? null;
  const now = new Date().toISOString();
  periods.push({ owner, from: cursor, to: end, minutes: minutesBetween(cursor, end ?? now), ongoing: !end });

  return periods;
});

const ownerTotals = computed(() => {
  const totals: Record<Owner, number> = { support: 0, client: 0, vendor: 0 };
  for (const p of responsibilityPeriods.value) totals[p.owner] += p.minutes;
  return totals;
});

const respondedYet = computed(() => !!ticket.value?.first_response_at);

const firstResponseMinutes = computed(() => {
  const t = ticket.value;
  if (!t?.opened_at) return null;
  return minutesBetween(t.opened_at, t.first_response_at ?? new Date().toISOString());
});

// ── Manual override: the auto-tracking guesses from message direction ──────
const showOwnerChange = ref(false);
const ownerDraft = ref<Owner>("support");
const ownerReason = ref("");
const ownerBusy = ref(false);

function openOwnerChange(): void {
  ownerDraft.value = (ticket.value?.current_owner as Owner) ?? "support";
  ownerReason.value = "";
  showOwnerChange.value = true;
}

async function setOwner(): Promise<void> {
  if (!ticket.value) return;
  ownerBusy.value = true; error.value = "";
  try {
    await api("POST", `/api/v1/support/tickets/${ticket.value.id}/owner`, {
      org: org(), body: { owner: ownerDraft.value, reason: ownerReason.value.trim() || null },
    });
    showOwnerChange.value = false;
    await refresh();
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not change who owns it.";
  } finally { ownerBusy.value = false; }
}

// ── Routing ──────────────────────────────────────────────────────────────
async function reroute(): Promise<void> {
  if (!ticket.value) return;
  error.value = "";
  try {
    const res = await api<{ result: { why: string } }>("POST", `/api/v1/support/tickets/${ticket.value.id}/reroute`, { org: org(), body: { force: false } });
    notice(res.result.why);
    await refresh();
  } catch (e) { error.value = e instanceof Error ? e.message : "Could not re-route it."; }
}

async function moveTeam(value: string): Promise<void> {
  if (!ticket.value) return;
  error.value = "";
  try {
    await api("POST", `/api/v1/support/tickets/${ticket.value.id}/assign`, { org: org(), body: { support_team_id: value ? Number(value) : null } });
    await refresh();
  } catch (e) { error.value = e instanceof ApiError ? e.message : "Could not move it."; }
}

// ── Who is on it ─────────────────────────────────────────────────────────
const suggestions = ref<Suggestion[]>([]);
const suggestBusy = ref(false);
const showAssign = ref(false);
const assignReason = ref("");
const assignTo = ref<number | null>(null);

async function loadSuggestions(): Promise<void> {
  if (!ticket.value) return;
  suggestBusy.value = true;
  try {
    const res = await api<{ suggestions: Suggestion[] }>("GET", `/api/v1/support/tickets/${ticket.value.id}/suggest`, { org: org() });
    suggestions.value = res.suggestions;
  } catch { suggestions.value = []; }
  finally { suggestBusy.value = false; }
}

interface EngineerRow { user_id: number; name: string; open: number; pending: number; at_risk: number; accepts_assignment: boolean }
const techEngineers = ref<EngineerRow[]>([]);
const techEngineersBusy = ref(false);
const techEngineersLoaded = ref(false);

async function loadTechEngineers(): Promise<void> {
  if (techEngineersLoaded.value || techEngineersBusy.value) return;
  techEngineersBusy.value = true;
  try {
    const res = await api<{ data: EngineerRow[] }>("GET", "/api/v1/support/workspace/workload?department=Technical", { org: org() });
    techEngineers.value = res.data;
    techEngineersLoaded.value = true;
  } catch { techEngineers.value = []; }
  finally { techEngineersBusy.value = false; }
}

function openAssign(userId: number | null = null): void {
  assignTo.value = userId;
  showAssign.value = true;
  void loadTechEngineers();
}

async function assignTicket(): Promise<void> {
  if (!ticket.value || !assignReason.value.trim()) return;
  error.value = "";
  try {
    await api("POST", `/api/v1/support/tickets/${ticket.value.id}/reassign`, { org: org(), body: { assigned_to: assignTo.value, reason: assignReason.value } });
    showAssign.value = false; assignReason.value = ""; assignTo.value = null;
    await refresh();
  } catch (e) { error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not assign it."; }
}

// ── Escalation ───────────────────────────────────────────────────────────
const showEscalate = ref(false);
const escalation = ref({ level: "specialist", reason: "", to_user_id: "", to_team_id: "" });
const outcomeDraft = ref<Record<number, string>>({});
const escalationLevels: Record<string, string> = {
  specialist: "Specialist engineer", team_lead: "Technical team lead",
  business_unit: "Business unit", vendor: "Vendor or supplier", service_desk: "Back to the service desk",
};

/*
 * What "to a team" should actually offer depends on the level — escalating
 * to a Business Unit means picking among the units that exist, not the whole
 * team list; a vendor queue only ever has one destination.
 */
function escalateTeamOptions(level: string): TeamRow[] {
  if (level === "business_unit") return teams.value.filter((t) => t.business_unit || t.practice);
  if (level === "vendor") return teams.value.filter((t) => t.is_vendor_queue);
  if (level === "service_desk") return teams.value.filter((t) => t.is_service_desk);
  return teams.value;
}

const escalateTeams = computed(() => escalateTeamOptions(escalation.value.level));

const escalateTeamPlaceholder = computed(() => {
  switch (escalation.value.level) {
    case "business_unit": return "Which business unit…";
    case "vendor": return "Which vendor queue…";
    case "service_desk": return "Back to…";
    default: return "To a team…";
  }
});

function openEscalate(): void {
  showEscalate.value = true;
  if (escalation.value.level === "specialist") void loadTechEngineers();
}

watch(() => escalation.value.level, (level) => {
  escalation.value.to_user_id = "";
  escalation.value.to_team_id = "";
  if (level === "specialist") {
    void loadTechEngineers();
  } else {
    const options = escalateTeamOptions(level);
    if (options.length === 1) escalation.value.to_team_id = String(options[0].id);
  }
});

async function escalate(): Promise<void> {
  if (!ticket.value || !escalation.value.reason.trim()) return;
  error.value = "";
  try {
    await api("POST", `/api/v1/support/tickets/${ticket.value.id}/escalate`, {
      org: org(), body: {
        level: escalation.value.level, reason: escalation.value.reason,
        to_user_id: escalation.value.to_user_id ? Number(escalation.value.to_user_id) : null,
        to_team_id: escalation.value.to_team_id ? Number(escalation.value.to_team_id) : null,
      },
    });
    showEscalate.value = false;
    escalation.value = { level: "specialist", reason: "", to_user_id: "", to_team_id: "" };
    await refresh();
  } catch (e) { error.value = e instanceof ApiError ? e.message : "Could not escalate it."; }
}

async function resolveEscalation(id: number): Promise<void> {
  if (!ticket.value || !outcomeDraft.value[id]?.trim()) return;
  try {
    await api("POST", `/api/v1/support/tickets/${ticket.value.id}/escalations/${id}/resolve`, { org: org(), body: { outcome: outcomeDraft.value[id] } });
    delete outcomeDraft.value[id];
    await refresh();
  } catch (e) { error.value = e instanceof ApiError ? e.message : "Could not close it off."; }
}

// ── Related tickets (major incident) ────────────────────────────────────
const mergeRef = ref("");
const showMerge = ref(false);

async function mergeTicket(): Promise<void> {
  if (!ticket.value || !mergeRef.value.trim()) return;
  error.value = "";
  try {
    await api("POST", `/api/v1/support/tickets/${ticket.value.id}/merge`, { org: org(), body: { parent_reference: mergeRef.value.trim() } });
    mergeRef.value = ""; showMerge.value = false;
    await refresh();
  } catch (e) { error.value = e instanceof ApiError ? e.message : "Could not join it."; }
}

async function detachTicket(): Promise<void> {
  if (!ticket.value) return;
  try {
    await api("POST", `/api/v1/support/tickets/${ticket.value.id}/detach`, { org: org() });
    await refresh();
  } catch (e) { error.value = e instanceof Error ? e.message : "Could not separate it."; }
}

// ── Splitting ────────────────────────────────────────────────────────────
const showSplit = ref(false);
const splitDraft = ref({ subject: "", service_category_id: "", message_ids: [] as number[] });
const splitBusy = ref(false);

function toggleSplitMessage(id: number): void {
  const at = splitDraft.value.message_ids.indexOf(id);
  at === -1 ? splitDraft.value.message_ids.push(id) : splitDraft.value.message_ids.splice(at, 1);
}

async function splitTicket(): Promise<void> {
  if (!ticket.value || !splitDraft.value.subject.trim()) return;
  splitBusy.value = true; error.value = "";
  try {
    const res = await api<{ message: string }>("POST", `/api/v1/support/tickets/${ticket.value.id}/split`, {
      org: org(), body: {
        subject: splitDraft.value.subject, message_ids: splitDraft.value.message_ids,
        service_category_id: splitDraft.value.service_category_id ? Number(splitDraft.value.service_category_id) : null,
      },
    });
    notice(res.message);
    showSplit.value = false;
    splitDraft.value = { subject: "", service_category_id: "", message_ids: [] };
    await refresh();
  } catch (e) { error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not split it."; }
  finally { splitBusy.value = false; }
}

// ── Assets / knowledge / portal / effort ────────────────────────────────
const suggested = ref<{ id: number; title: string; summary: string; misleading?: boolean }[]>([]);
const assetQuery = ref("");
const assetHits = ref<{ id: number; name: string; asset_tag: string | null }[]>([]);
const portalLink = ref("");
const surveyLink = ref("");
const showTime = ref(false);
const timeDraft = ref({ minutes: "", note: "", under_contract: true });

async function loadSuggested(): Promise<void> {
  if (!ticket.value) return;
  try {
    const res = await api<{ data: typeof suggested.value }>("GET", `/api/v1/support/tickets/${ticket.value.id}/suggested-articles`, { org: org() });
    suggested.value = res.data;
  } catch { suggested.value = []; }
}

async function useArticle(id: number): Promise<void> {
  if (!ticket.value) return;
  try {
    const res = await api<{ body: string }>("POST", `/api/v1/support/tickets/${ticket.value.id}/attach-article`, { org: org(), body: { article_id: id } });
    reply.value.body = reply.value.body ? `${reply.value.body}\n\n${res.body}` : res.body;
  } catch (e) { error.value = e instanceof Error ? e.message : "Could not attach it."; }
}

async function draftArticle(): Promise<void> {
  if (!ticket.value) return;
  error.value = "";
  try {
    const res = await api<{ message: string }>("POST", `/api/v1/support/tickets/${ticket.value.id}/draft-article`, { org: org(), body: {} });
    notice(res.message);
  } catch (e) { error.value = e instanceof Error ? e.message : "Could not draft it."; }
}

async function findAssets(): Promise<void> {
  if (!assetQuery.value.trim()) { assetHits.value = []; return; }
  try {
    const res = await api<{ data: typeof assetHits.value }>("GET", `/api/v1/assets?search=${encodeURIComponent(assetQuery.value)}&per_page=8`, { org: org() });
    assetHits.value = res.data ?? [];
  } catch { assetHits.value = []; }
}

async function linkAsset(assetId: number): Promise<void> {
  if (!ticket.value) return;
  try {
    await api("POST", `/api/v1/support/tickets/${ticket.value.id}/assets`, { org: org(), body: { asset_id: assetId } });
    assetQuery.value = ""; assetHits.value = [];
    await refresh();
  } catch (e) { error.value = e instanceof Error ? e.message : "Could not link it."; }
}

async function unlinkAsset(assetId: number): Promise<void> {
  if (!ticket.value) return;
  try {
    await api("DELETE", `/api/v1/support/tickets/${ticket.value.id}/assets/${assetId}`, { org: org() });
    await refresh();
  } catch (e) { error.value = e instanceof Error ? e.message : "Could not unlink it."; }
}

async function issuePortalLink(): Promise<void> {
  if (!ticket.value) return;
  error.value = "";
  try {
    const res = await api<{ url: string }>("POST", `/api/v1/support/tickets/${ticket.value.id}/portal-link`, { org: org() });
    portalLink.value = res.url;
  } catch (e) { error.value = e instanceof ApiError ? e.message : "Could not create a link."; }
}

async function issueSurveyLink(): Promise<void> {
  if (!ticket.value) return;
  error.value = "";
  try {
    const res = await api<{ url: string }>("POST", `/api/v1/support/tickets/${ticket.value.id}/survey`, { org: org() });
    surveyLink.value = res.url;
  } catch (e) { error.value = e instanceof ApiError ? e.message : "Could not create a survey."; }
}

function copy(text: string): void { void navigator.clipboard?.writeText(text); }

async function logTime(): Promise<void> {
  if (!ticket.value || !Number(timeDraft.value.minutes)) return;
  try {
    await api("POST", `/api/v1/support/tickets/${ticket.value.id}/time`, {
      org: org(), body: { minutes: Number(timeDraft.value.minutes), note: timeDraft.value.note || null, under_contract: timeDraft.value.under_contract },
    });
    timeDraft.value = { minutes: "", note: "", under_contract: true };
    showTime.value = false;
    await refresh();
  } catch (e) { error.value = e instanceof Error ? e.message : "Could not log the time."; }
}

// ── Thread rendering ─────────────────────────────────────────────────────
const quoted = ref<Set<number>>(new Set());
const expanded = ref<Set<number>>(new Set());
const splitCache = new Map<string, { latest: string; quoted: string }>();

function splitBody(body: string | null | undefined): { latest: string; quoted: string } {
  const key = body ?? "";
  if (!splitCache.has(key)) splitCache.set(key, splitQuoted(key));
  return splitCache.get(key)!;
}

function toggleQuoted(id: number): void {
  const next = new Set(quoted.value);
  next.has(id) ? next.delete(id) : next.add(id);
  quoted.value = next;
}

function toggleExpanded(id: number): void {
  const next = new Set(expanded.value);
  next.has(id) ? next.delete(id) : next.add(id);
  expanded.value = next;
}

function when(v: string | null): string {
  return v ? v.slice(0, 16).replace("T", " ") : "—";
}

// ── Correspondence also carries call notes, once a meeting has them ────────
type CorrespondenceEntry =
  | { kind: "message"; at: string; message: Message }
  | { kind: "meeting"; at: string; meeting: TicketMeetingRow };

const correspondenceEntries = computed<CorrespondenceEntry[]>(() => {
  const t = ticket.value;
  if (!t) return [];
  const entries: CorrespondenceEntry[] = t.messages.map((message) => ({ kind: "message" as const, at: message.created_at, message }));
  for (const meeting of t.meetings ?? []) {
    if (meeting.transcript || meeting.recording_url) {
      entries.push({ kind: "meeting", at: meeting.scheduled_at, meeting });
    }
  }
  return entries.sort((a, b) => a.at.localeCompare(b.at));
});

const expandedMeetings = ref<Set<number>>(new Set());
function toggleMeetingExpanded(id: number): void {
  const next = new Set(expandedMeetings.value);
  next.has(id) ? next.delete(id) : next.add(id);
  expandedMeetings.value = next;
}

// ── Client / internal split, and the engagement timeline ───────────────────
const clientParticipants = computed(() => emailParticipants.value.filter((p) => !p.internal));
const internalParticipants = computed(() => emailParticipants.value.filter((p) => p.internal));

interface TimelineEntry { at: string; kind: string; label: string; detail?: string; actor?: string }

function eventLabel(ev: TicketEventRow): string {
  if (ev.event === "owner_changed") {
    const to = OWNER_LABEL[(ev.to_value as Owner) ?? "support"] ?? ev.to_value;
    return `Handed to ${to}`;
  }
  const words = ev.event.replace(/_/g, " ");
  const label = words.charAt(0).toUpperCase() + words.slice(1);
  if (ev.from_value || ev.to_value) {
    return `${label}${ev.field ? ` (${ev.field})` : ""}: ${ev.from_value ?? "—"} → ${ev.to_value ?? "—"}`;
  }
  return label;
}

const timeline = computed<TimelineEntry[]>(() => {
  const t = ticket.value;
  if (!t) return [];
  const entries: TimelineEntry[] = [];

  if (t.opened_at) entries.push({ at: t.opened_at, kind: "milestone", label: "Ticket opened", actor: t.requester_name ?? undefined });
  if (t.first_response_at) entries.push({ at: t.first_response_at, kind: "milestone", label: "First response sent" });
  if (t.resolved_at) entries.push({ at: t.resolved_at, kind: "milestone", label: "Marked resolved" });

  for (const m of t.messages) {
    entries.push({
      at: m.created_at, kind: "message",
      label: m.visibility === "internal" ? "Internal note added" : (m.is_from_agent ? "Reply sent" : "Message received"),
      detail: excerpt(m.body, 120),
      actor: m.author?.name ?? m.author_name ?? undefined,
    });
  }
  for (const w of t.worklogs) {
    entries.push({ at: w.performed_on, kind: "worklog", label: `${w.minutes}m logged`, detail: w.note ?? undefined, actor: w.user?.name });
  }
  for (const e of t.escalations ?? []) {
    entries.push({
      at: e.escalated_at, kind: "escalation",
      label: `Escalated — ${escalationLevels[e.level] ?? e.level}`, detail: e.reason, actor: e.by?.name ?? undefined,
    });
    if (e.resolved_at) {
      entries.push({ at: e.resolved_at, kind: "escalation", label: "Escalation handed back", detail: e.outcome ?? undefined, actor: e.to?.name ?? undefined });
    }
  }
  for (const ev of t.events ?? []) {
    entries.push({
      at: ev.occurred_at, kind: "event", label: eventLabel(ev),
      detail: ev.reason ?? undefined, actor: ev.actor?.name ?? (ev.automatic ? "System" : undefined),
    });
  }

  return entries.filter((e) => e.at).sort((a, b) => a.at.localeCompare(b.at));
});

onMounted(() => {
  void loadTaxonomy();
  void loadTeamsAndCanned();
  void loadCategories();
  void loadTicket();
});
</script>

<template>
  <div class="view td-page">
    <div class="td-topbar">
      <button type="button" class="td-back-btn" @click="router.push({ name: 'support' })">
        <span class="td-back-icon" aria-hidden="true">←</span> Back to Service Desk
      </button>
      <span v-if="ticket" class="td-crumb-ref">{{ ticket.reference }}</span>
    </div>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-else-if="loading" class="td-loading">Loading…</p>

    <template v-else-if="ticket">
      <header class="td-header">
        <div>
          <span class="td-mono td-ref">{{ ticket.reference }}</span>
          <h1 class="td-title">{{ ticket.subject }}</h1>
          <p class="td-meta">
            {{ ticket.requester_name ?? "Unknown requester" }}
            <template v-if="ticket.requester_email"> · {{ ticket.requester_email }}</template>
            <template v-if="ticket.account"> · {{ ticket.account.name }}</template>
            · opened {{ when(ticket.opened_at) }}
          </p>
        </div>
      </header>

      <p v-if="noticeMessage" class="td-notice">{{ noticeMessage }}</p>

      <!-- Ergonomic top strip: the two things read first on any ticket. -->
      <div class="td-top-strip">
        <div class="td-top-card">
          <span class="td-top-label">Type</span>
          <select :value="ticket.type?.id ?? ''" class="control" @change="classify('ticket_type_id', ($event.target as HTMLSelectElement).value)">
            <option value="">Unclassified</option>
            <option v-for="t in taxonomy.types" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>
          <p class="td-hint">Incident, service request, feature request, change — what kind of ticket this is.</p>
        </div>

        <div class="td-top-card">
          <span class="td-top-label">Status</span>
          <select :value="ticket.status?.id" class="control" @change="setStatus(Number(($event.target as HTMLSelectElement).value))">
            <option v-for="s in taxonomy.statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
          <p v-if="pendingStates.length" class="td-hint">
            Waiting — {{ pendingStates.map(s => s.name).join(", ") }} — means the ticket is with someone else.
          </p>
        </div>

        <div class="td-top-card">
          <span class="td-top-label">Priority</span>
          <div class="td-derived">
            <span v-if="ticket.priority" class="sd-pill"
                  :style="{ background: (ticket.priority.colour ?? '#64748b') + '22', color: ticket.priority.colour ?? '#64748b' }">
              {{ ticket.priority.name }}
            </span>
            <span v-else class="td-meta">Untriaged</span>
            <span class="td-hint">{{ priorityHint }}</span>
          </div>
        </div>

        <div class="td-top-card">
          <span class="td-top-label">Service Level</span>
          <div v-if="ticket.sla" class="sd-sla">
            <span class="sd-pill" :class="'sd-clock-' + clockState(ticket).tone">{{ clockState(ticket).label }}</span>
            <span class="td-hint">
              {{ hm(slaUsed) }} counted of {{ hm(elapsed) }} elapsed
              <template v-if="ticket.sla.paused_minutes"> · {{ hm(ticket.sla.paused_minutes) }} paused</template>
            </span>
            <span v-if="ticket.sla.escalation_level" class="td-hint sd-esc">Escalated to {{ ticket.sla.escalation_level.toUpperCase() }}</span>
          </div>
          <span v-else class="td-hint">No target — set impact and urgency to start the clock.</span>
        </div>

        <button type="button" class="td-top-card td-top-action" @click="scheduleFromHeader">
          <span class="td-top-label">Meetings</span>
          <span class="td-top-action-text">+ Schedule Teams Call</span>
          <span v-if="upcomingMeetingCount" class="td-hint">{{ upcomingMeetingCount }} scheduled</span>
        </button>
      </div>

      <div class="td-columns">
        <!-- ── Correspondence window ─────────────────────────────────── -->
        <section class="sd-card td-thread-card">
          <button type="button" class="td-thread-header" @click="toggleThread">
            <span class="td-card-title td-thread-title">Correspondence</span>
            <span class="td-thread-count">
              {{ ticket.messages.length }} message{{ ticket.messages.length === 1 ? "" : "s" }}
            </span>
            <span class="td-thread-chevron" :class="{ open: threadOpen }" aria-hidden="true">⌄</span>
          </button>

          <div v-show="threadOpen" class="td-thread-body">
          <div class="sd-thread">
            <template v-for="entry in correspondenceEntries" :key="entry.kind === 'message' ? 'm' + entry.message.id : 'mt' + entry.meeting.id">
              <div v-if="entry.kind === 'message'"
                   :class="['sd-msg', entry.message.visibility === 'internal' ? 'internal' : (entry.message.is_from_agent ? 'agent' : 'customer')]">
                <div class="sd-msg-head">
                  <strong>{{ entry.message.author?.name ?? entry.message.author_name ?? "Unknown" }}</strong>
                  <span v-if="entry.message.visibility === 'internal'" class="sd-tag internal">Internal — not sent</span>
                  <span v-else-if="entry.message.is_from_agent" class="sd-tag">Reply to customer</span>
                  <span v-else class="sd-tag">From customer</span>
                  <span class="sd-when">{{ when(entry.message.created_at) }}</span>
                </div>
                <div :class="['sd-msg-body', 'rich', { clamped: !expanded.has(entry.message.id) }]" v-html="splitBody(entry.message.body).latest"></div>
                <button v-if="splitBody(entry.message.body).quoted" class="sd-quote-toggle" @click="toggleQuoted(entry.message.id)">
                  {{ quoted.has(entry.message.id) ? "Hide the earlier conversation" : "Show the earlier conversation" }}
                </button>
                <div v-if="quoted.has(entry.message.id)" class="sd-msg-quoted rich" v-html="splitBody(entry.message.body).quoted"></div>
                <button v-if="!splitBody(entry.message.body).quoted && (entry.message.body?.length ?? 0) > 2000" class="sd-quote-toggle" @click="toggleExpanded(entry.message.id)">
                  {{ expanded.has(entry.message.id) ? "Show less" : "Show the whole message" }}
                </button>
              </div>

              <!-- Call notes: a meeting's transcript/recording, shown in the same thread it happened in the middle of. -->
              <div v-else class="sd-msg meeting">
                <div class="sd-msg-head">
                  <strong>{{ entry.meeting.subject }}</strong>
                  <span class="sd-tag call">Teams call</span>
                  <span class="sd-when">{{ when(entry.meeting.scheduled_at) }}</span>
                </div>
                <div v-if="entry.meeting.transcript" :class="['sd-msg-body', { clamped: !expandedMeetings.has(entry.meeting.id) }]">
                  {{ entry.meeting.transcript }}
                </div>
                <button v-if="entry.meeting.transcript && entry.meeting.transcript.length > 800" class="sd-quote-toggle" @click="toggleMeetingExpanded(entry.meeting.id)">
                  {{ expandedMeetings.has(entry.meeting.id) ? "Show less" : "Show the whole transcript" }}
                </button>
                <a v-if="entry.meeting.recording_url" :href="entry.meeting.recording_url" target="_blank" rel="noopener" class="sd-quote-toggle">Watch the recording</a>
              </div>
            </template>
            <p v-if="!correspondenceEntries.length" class="td-meta">Nothing on this ticket yet.</p>
          </div>

          <div :class="['sd-compose', { internal: reply.visibility === 'internal' }]">
            <div class="sd-compose-tabs">
              <button :class="['sd-tab', { on: reply.visibility === 'public' }]" @click="reply.visibility = 'public'">Reply to customer</button>
              <button :class="['sd-tab', { on: reply.visibility === 'internal' }]" @click="reply.visibility = 'internal'">Internal note</button>
            </div>
            <textarea v-model="reply.body" class="control" rows="4"
                      :placeholder="reply.visibility === 'internal' ? 'Only the desk sees this.' : 'This goes to the customer.'"></textarea>
            <div v-if="showCanned" class="sd-canned">
              <button v-for="c in canned" :key="c.id" class="sd-canned-item" @click="useCanned(c.id)">
                <strong>{{ c.title }}</strong>
                <span class="td-meta">used {{ c.use_count }}×</span>
              </button>
              <p v-if="!canned.length" class="td-hint">Nothing saved yet.</p>
            </div>
            <div class="sd-compose-foot">
              <button class="link" @click="showCanned = !showCanned">{{ showCanned ? "Hide saved answers" : "Saved answers" }}</button>
              <span class="td-meta">
                <template v-if="reply.visibility === 'internal'">The customer never sees internal notes.</template>
                <template v-else-if="!ticket.first_response_at">This will be recorded as the first response.</template>
                <template v-else>First response {{ when(ticket.first_response_at) }}.</template>
              </span>
              <div class="sd-compose-actions">
                <button v-if="ticket.children?.length && reply.visibility === 'public'" class="btn btn-secondary btn-sm"
                        :disabled="replyBusy || !reply.body.trim()" @click="broadcast">
                  Send to all {{ ticket.children.length + 1 }}
                </button>
                <button class="btn btn-primary btn-sm" :disabled="replyBusy || !reply.body.trim()" @click="sendReply">
                  {{ replyBusy ? "Saving…" : (reply.visibility === "internal" ? "Add note" : "Send reply") }}
                </button>
              </div>
            </div>
          </div>
          </div>
        </section>

        <!-- ── Sidebar, grouped into tabs so eight cards don't stack ──── -->
        <aside class="td-side">
          <div class="td-tabs" role="tablist">
            <button type="button" role="tab" class="td-tab-btn" :class="{ on: sidebarTab === 'client' }" @click="sidebarTab = 'client'">
              Client
              <span v-if="clientParticipants.length" class="td-tab-badge">{{ clientParticipants.length }}</span>
            </button>
            <button type="button" role="tab" class="td-tab-btn" :class="{ on: sidebarTab === 'internal' }" @click="sidebarTab = 'internal'">
              Internal
              <span v-if="ticket.escalations?.length" class="td-tab-badge">{{ ticket.escalations.length }}</span>
            </button>
            <button type="button" role="tab" class="td-tab-btn" :class="{ on: sidebarTab === 'issue' }" @click="sidebarTab = 'issue'">Issue</button>
            <button type="button" role="tab" class="td-tab-btn" :class="{ on: sidebarTab === 'related' }" @click="sidebarTab = 'related'">Related</button>
            <button type="button" role="tab" class="td-tab-btn" :class="{ on: sidebarTab === 'resources' }" @click="sidebarTab = 'resources'">
              Resources
              <span v-if="ticket.assets?.length" class="td-tab-badge">{{ ticket.assets.length }}</span>
            </button>
            <button type="button" role="tab" class="td-tab-btn" :class="{ on: sidebarTab === 'effort' }" @click="sidebarTab = 'effort'">Effort</button>
            <button type="button" role="tab" class="td-tab-btn" :class="{ on: sidebarTab === 'meetings' }" @click="sidebarTab = 'meetings'">
              Meetings
              <span v-if="ticket.meetings?.filter(m => m.status === 'scheduled').length" class="td-tab-badge">
                {{ ticket.meetings.filter(m => m.status === "scheduled").length }}
              </span>
            </button>
            <button type="button" role="tab" class="td-tab-btn" :class="{ on: sidebarTab === 'rca' }" @click="sidebarTab = 'rca'; loadRca()">
              RCA
              <span v-if="rcaLoaded && rca.status === 'final'" class="td-tab-badge">Final</span>
            </button>
            <button type="button" role="tab" class="td-tab-btn" :class="{ on: sidebarTab === 'timeline' }" @click="sidebarTab = 'timeline'">Timeline</button>
          </div>

          <!-- ── Client: the requester, and who else is copied on the customer side ── -->
          <section class="sd-card" v-show="sidebarTab === 'client'">
            <p class="td-card-title">Client</p>
            <div class="sd-field">
              <span>Requester</span>
              <div class="sd-derived">
                <strong>{{ ticket.requester_name ?? "Unknown" }}</strong>
              </div>
              <span v-if="ticket.requester_email" class="td-meta">{{ ticket.requester_email }}</span>
              <span v-if="ticket.account" class="td-meta">{{ ticket.account.name }}</span>
            </div>
            <p class="td-card-subtitle">Also copied</p>
            <ul v-if="clientParticipants.length" class="sd-participants">
              <li v-for="p in clientParticipants" :key="p.address">
                <strong>{{ p.name || p.address }}</strong>
                <span class="td-meta">{{ p.address }}</span>
              </li>
            </ul>
            <p v-else class="td-hint">Nobody else has been copied on this thread.</p>
          </section>

          <!-- ── Internal: who owns it, the team, escalation, who else is on the loop ── -->
          <section class="sd-card" v-show="sidebarTab === 'internal'">
            <p class="td-card-title">Assigned</p>
            <div class="sd-derived">
              <strong>{{ ticket.assignee?.name ?? "Unassigned" }}</strong>
            </div>
            <label class="sd-field"><span>Team</span>
              <select :value="ticket.team?.id ?? ''" class="control" @change="moveTeam(($event.target as HTMLSelectElement).value)">
                <option value="">Unassigned to a team</option>
                <option v-for="t in teams" :key="t.id" :value="t.id">
                  {{ t.name }}{{ t.business_unit ? ` — ${t.business_unit.name}` : (t.practice ? ` — ${t.practice.name}` : "") }}
                </option>
              </select>
            </label>
            <p v-if="internalParticipants.length" class="td-card-subtitle">Also copied internally</p>
            <ul v-if="internalParticipants.length" class="sd-participants">
              <li v-for="p in internalParticipants" :key="p.address">
                <strong>{{ p.name || p.address }}</strong>
                <span class="td-meta">{{ p.address }}</span>
              </li>
            </ul>
          </section>

          <section class="sd-card" v-show="sidebarTab === 'internal'">
            <p class="td-card-title">Who Is On It</p>
            <ul v-if="suggestions.length" class="sd-suggest">
              <li v-for="sg in suggestions" :key="sg.user_id" :class="{ off: !sg.available }">
                <button class="sd-suggest-btn" @click="openAssign(sg.user_id)">
                  <strong>{{ sg.name }}</strong>
                  <span class="td-meta">{{ sg.reason }}</span>
                  <span class="td-meta">{{ sg.open }} open · {{ sg.pending }} waiting<template v-if="sg.at_risk"> · {{ sg.at_risk }} at risk</template></span>
                </button>
              </li>
            </ul>
            <p v-else-if="!suggestBusy" class="td-hint">Nobody is named on a contract for this customer, and no practice owns the category.</p>
            <button v-if="!showAssign" class="link" @click="openAssign()">Reassign</button>
            <div v-if="showAssign" class="sd-assign">
              <label class="sd-field"><span>Engineer (Technical department)</span>
                <select v-model="assignTo" class="control">
                  <option :value="null">Unassigned</option>
                  <option v-for="eng in techEngineers" :key="eng.user_id" :value="eng.user_id">
                    {{ eng.name }} — {{ eng.open }} open<template v-if="eng.at_risk"> · {{ eng.at_risk }} at risk</template>
                  </option>
                </select>
                <span v-if="techEngineersBusy" class="td-hint">Loading engineers…</span>
                <span v-else-if="techEngineersLoaded && !techEngineers.length" class="td-hint">No one found in the Technical department.</span>
              </label>
              <input v-model="assignReason" class="control" placeholder="Why you are moving it" />
              <button class="btn btn-primary btn-sm" :disabled="!assignReason.trim()" @click="assignTicket">Assign</button>
              <button class="link" @click="showAssign = false">Cancel</button>
            </div>
          </section>

          <section class="sd-card" v-show="sidebarTab === 'internal'">
            <p class="td-card-title">Escalation</p>
            <ul v-if="ticket.escalations?.length" class="sd-escalations">
              <li v-for="e in ticket.escalations" :key="e.id">
                <strong>{{ escalationLevels[e.level] ?? e.level }}</strong>
                <span class="td-meta">{{ e.reason }}</span>
                <span class="td-meta">by {{ e.by?.name ?? "—" }}<template v-if="e.to"> to {{ e.to.name }}</template> · {{ when(e.escalated_at) }}</span>
                <template v-if="!e.resolved_at">
                  <input v-model="outcomeDraft[e.id]" class="control" placeholder="What was found, before handing it back" />
                  <button class="link" :disabled="!outcomeDraft[e.id]?.trim()" @click="resolveEscalation(e.id)">Hand back</button>
                </template>
                <span v-else class="td-meta sd-done">Closed: {{ e.outcome }}</span>
              </li>
            </ul>
            <button v-if="!showEscalate" class="link" @click="openEscalate">Escalate this</button>
            <div v-else class="sd-assign">
              <select v-model="escalation.level" class="control">
                <option v-for="(label, key) in escalationLevels" :key="key" :value="key">{{ label }}</option>
              </select>

              <template v-if="escalation.level === 'specialist'">
                <select v-model="escalation.to_user_id" class="control">
                  <option value="">Which engineer…</option>
                  <option v-for="eng in techEngineers" :key="eng.user_id" :value="String(eng.user_id)">
                    {{ eng.name }} — {{ eng.open }} open<template v-if="eng.at_risk"> · {{ eng.at_risk }} at risk</template>
                  </option>
                </select>
                <span v-if="techEngineersBusy" class="td-hint">Loading engineers…</span>
                <span v-else-if="techEngineersLoaded && !techEngineers.length" class="td-hint">No one found in the Technical department.</span>
              </template>
              <template v-else>
                <select v-model="escalation.to_team_id" class="control">
                  <option value="">{{ escalateTeamPlaceholder }}</option>
                  <option v-for="t in escalateTeams" :key="t.id" :value="String(t.id)">
                    {{ t.name }}{{ t.business_unit ? ` — ${t.business_unit.name}` : (t.practice ? ` — ${t.practice.name}` : "") }}
                  </option>
                </select>
                <span v-if="!escalateTeams.length" class="td-hint">Nothing is set up for this yet.</span>
              </template>

              <textarea v-model="escalation.reason" class="control" rows="3" placeholder="What has been tried, and what you need."></textarea>
              <button class="btn btn-primary btn-sm" :disabled="!escalation.reason.trim() || (!escalation.to_team_id && !escalation.to_user_id)" @click="escalate">Escalate</button>
              <button class="link" @click="showEscalate = false">Cancel</button>
            </div>
          </section>

          <!-- ── Issue: the exact problem, as triaged ────────────────────── -->
          <section class="sd-card" v-show="sidebarTab === 'issue'">
            <p class="td-card-title">Issue Summary</p>
            <p class="td-hint">What was actually found, in your own words — this is what an RCA pulls from, not the raw message thread.</p>
            <textarea v-model="issueSummaryDraft" class="control" rows="6"
                      placeholder="Summarise the issue identified…"></textarea>
            <div class="sd-compose-actions" style="margin-top: 8px">
              <button class="btn btn-primary btn-sm"
                      :disabled="issueSummaryBusy || issueSummaryDraft === (ticket.issue_summary ?? '')"
                      @click="saveIssueSummary">
                {{ issueSummaryBusy ? "Saving…" : "Save" }}
              </button>
            </div>
          </section>

          <section class="sd-card" v-show="sidebarTab === 'issue'">
            <p class="td-card-title">How It Is Classified</p>
            <label class="sd-field"><span>Type</span>
              <select :value="ticket.type?.id ?? ''" class="control" @change="classify('ticket_type_id', ($event.target as HTMLSelectElement).value)">
                <option value="">Unclassified</option>
                <option v-for="t in taxonomy.types" :key="t.id" :value="t.id">{{ t.name }}</option>
              </select>
            </label>
            <label class="sd-field"><span>Impact</span>
              <select :value="ticket.impact?.id ?? ''" class="control" @change="classify('impact_id', ($event.target as HTMLSelectElement).value)">
                <option value="">Not set</option>
                <option v-for="i in taxonomy.impacts" :key="i.id" :value="i.id">{{ i.name }}</option>
              </select>
            </label>
            <label class="sd-field"><span>Urgency</span>
              <select :value="ticket.urgency?.id ?? ''" class="control" @change="classify('urgency_id', ($event.target as HTMLSelectElement).value)">
                <option value="">Not set</option>
                <option v-for="u in taxonomy.urgencies" :key="u.id" :value="u.id">{{ u.name }}</option>
              </select>
            </label>
            <label class="sd-field"><span>Service category</span>
              <select :value="ticket.service_category?.id ?? ''" class="control" @change="classify('service_category_id', ($event.target as HTMLSelectElement).value)">
                <option value="">Not categorised</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}{{ c.is_technical ? "" : " (non-technical)" }}</option>
              </select>
              <button class="link" @click="reroute">Re-run routing</button>
            </label>
          </section>

          <section class="sd-card" v-show="sidebarTab === 'related'">
            <p class="td-card-title">Related Tickets</p>
            <div v-if="ticket.parent" class="sd-derived">
              <span class="td-meta">Joined to {{ ticket.parent.reference }}</span>
              <button class="link" @click="detachTicket">Separate</button>
            </div>
            <div v-else-if="ticket.children?.length" class="sd-derived">
              <span class="td-meta">{{ ticket.children.length }} joined to this</span>
            </div>
            <div v-else class="sd-derived">
              <button v-if="!showMerge" class="link" @click="showMerge = true">Join to another ticket</button>
              <template v-else>
                <input v-model="mergeRef" class="control" placeholder="SD-2026-00001" />
                <button class="btn btn-secondary btn-sm" :disabled="!mergeRef.trim()" @click="mergeTicket">Join</button>
              </template>
            </div>

            <p class="td-card-subtitle">Splitting</p>
            <div v-if="ticket.split_from" class="sd-derived">
              <span class="td-meta">Split out of {{ ticket.split_from.reference }}</span>
            </div>
            <ul v-if="ticket.splits?.length" class="sd-splits">
              <li v-for="sp in ticket.splits" :key="sp.id"><span class="td-mono">{{ sp.reference }}</span> {{ sp.subject }}</li>
            </ul>
            <button v-if="!showSplit" class="link" @click="showSplit = true">Split part of this into a new ticket</button>
            <div v-else class="sd-assign">
              <input v-model="splitDraft.subject" class="control" placeholder="Subject for the new ticket" />
              <select v-model="splitDraft.service_category_id" class="control">
                <option value="">Same discipline</option>
                <option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
              </select>
              <p class="td-hint">Tick the messages that belong to the new ticket. They are copied, not moved.</p>
              <ul class="sd-split-pick">
                <li v-for="m in ticket.messages" :key="m.id">
                  <label class="sd-check">
                    <input type="checkbox" :checked="splitDraft.message_ids.includes(m.id)" @change="toggleSplitMessage(m.id)" />
                    <span>{{ excerpt(m.body, 60) }}</span>
                  </label>
                </li>
              </ul>
              <button class="btn btn-primary btn-sm" :disabled="splitBusy || !splitDraft.subject.trim()" @click="splitTicket">Raise the new ticket</button>
              <button class="link" @click="showSplit = false">Cancel</button>
            </div>
          </section>

          <section v-if="suggested.length" class="sd-card" v-show="sidebarTab === 'resources'">
            <p class="td-card-title">Might Already Be Answered</p>
            <ul class="sd-suggest">
              <li v-for="a in suggested" :key="a.id">
                <button class="sd-suggest-btn" @click="useArticle(a.id)">
                  <strong>{{ a.title }}</strong>
                  <span class="td-meta">{{ a.summary }}</span>
                  <span v-if="a.misleading" class="td-meta sd-esc">People have marked this unhelpful — check before sending it.</span>
                </button>
              </li>
            </ul>
          </section>

          <section class="sd-card" v-show="sidebarTab === 'resources'">
            <p class="td-card-title">Kit Involved</p>
            <ul v-if="ticket.assets?.length" class="sd-splits">
              <li v-for="a in ticket.assets" :key="a.id">
                {{ a.name }}<span v-if="a.asset_tag" class="td-meta"> {{ a.asset_tag }}</span>
                <button class="link" @click="unlinkAsset(a.id)">Remove</button>
              </li>
            </ul>
            <input v-model="assetQuery" class="control" placeholder="Find an asset by name or tag" @keyup.enter="findAssets" />
            <ul v-if="assetHits.length" class="sd-split-pick">
              <li v-for="a in assetHits" :key="a.id">
                <button class="link" @click="linkAsset(a.id)">{{ a.name }} <span class="td-meta">{{ a.asset_tag }}</span></button>
              </li>
            </ul>
          </section>

          <section class="sd-card" v-show="sidebarTab === 'resources'">
            <p class="td-card-title">Afterwards</p>
            <div class="sd-field"><span>Knowledge</span>
              <button class="link" @click="draftArticle">Write this up as an article</button>
            </div>
            <div class="sd-field"><span>Customer portal</span>
              <div v-if="portalLink" class="sd-linkbox">
                <code>{{ portalLink }}</code>
                <button class="link" @click="copy(portalLink)">Copy</button>
              </div>
              <button v-else class="link" @click="issuePortalLink">Create a link for the customer</button>
              <div v-if="surveyLink" class="sd-linkbox">
                <code>{{ surveyLink }}</code>
                <button class="link" @click="copy(surveyLink)">Copy</button>
              </div>
              <button v-else class="link" @click="issueSurveyLink">Ask how it went</button>
            </div>
          </section>

          <!-- ── Response time, and who owned the next move ──────────────── -->
          <section class="sd-card" v-show="sidebarTab === 'effort'">
            <p class="td-card-title">Response &amp; Ownership</p>
            <div class="sd-effort-stats">
              <div class="sd-effort-stat">
                <span class="td-top-label">First Response</span>
                <strong>{{ firstResponseMinutes !== null ? hm(firstResponseMinutes) : "—" }}</strong>
                <span class="td-hint">
                  <template v-if="respondedYet">from open to {{ when(ticket.first_response_at) }}</template>
                  <template v-else>still awaiting a first reply</template>
                </span>
              </div>
              <div class="sd-effort-stat sd-effort-support">
                <span class="td-top-label">With Support</span>
                <strong>{{ hm(ownerTotals.support) }}</strong>
              </div>
              <div class="sd-effort-stat sd-effort-client">
                <span class="td-top-label">With Client</span>
                <strong>{{ hm(ownerTotals.client) }}</strong>
              </div>
              <div v-if="ownerTotals.vendor" class="sd-effort-stat sd-effort-vendor">
                <span class="td-top-label">With Vendor</span>
                <strong>{{ hm(ownerTotals.vendor) }}</strong>
              </div>
            </div>

            <div class="sd-owner-now">
              <span class="td-meta">
                Currently with
                <strong>{{ ticket.current_owner ? OWNER_LABEL[ticket.current_owner] : "Support" }}</strong>
                <template v-if="ticket.owner_set_at"> since {{ when(ticket.owner_set_at) }}</template>
                — set automatically from the last reply.
              </span>
              <button v-if="!showOwnerChange" class="link" @click="openOwnerChange">Set by hand</button>
            </div>
            <div v-if="showOwnerChange" class="sd-assign">
              <select v-model="ownerDraft" class="control">
                <option value="support">With Support</option>
                <option value="client">With Client</option>
                <option value="vendor">With Vendor</option>
              </select>
              <input v-model="ownerReason" class="control" placeholder="Why (optional) — e.g. still investigating" />
              <button class="btn btn-primary btn-sm" :disabled="ownerBusy" @click="setOwner">
                {{ ownerBusy ? "Saving…" : "Save" }}
              </button>
              <button class="link" @click="showOwnerChange = false">Cancel</button>
            </div>

            <p class="td-card-subtitle">Action-point History</p>
            <ol v-if="responsibilityPeriods.length" class="sd-resp-periods">
              <li v-for="(p, i) in responsibilityPeriods" :key="i" :class="'sd-resp-' + p.owner">
                <span class="sd-resp-owner">With {{ OWNER_LABEL[p.owner] }}</span>
                <span class="td-meta">{{ when(p.from) }} → {{ p.to ? when(p.to) : "now" }}</span>
                <span class="td-meta">{{ hm(p.minutes) }}<template v-if="p.ongoing"> so far</template></span>
                <span v-if="p.reason" class="td-meta">{{ p.reason }}</span>
              </li>
            </ol>
            <p v-else class="td-hint">Nothing recorded yet.</p>
          </section>

          <section class="sd-card" v-show="sidebarTab === 'effort'">
            <p class="td-card-title">Logged Work</p>
            <div class="sd-derived">
              <strong>{{ Math.floor(minutesLogged / 60) }}h {{ minutesLogged % 60 }}m logged</strong>
              <button class="link" @click="showTime = !showTime">{{ showTime ? "Cancel" : "Log time" }}</button>
            </div>
            <div v-if="showTime" class="sd-time">
              <input v-model="timeDraft.minutes" class="control" type="number" min="1" placeholder="Minutes" />
              <input v-model="timeDraft.note" class="control" placeholder="What you did" />
              <label class="sd-check"><input v-model="timeDraft.under_contract" type="checkbox" /><span>Under contract</span></label>
              <button class="btn btn-primary btn-sm" :disabled="!Number(timeDraft.minutes)" @click="logTime">Save</button>
            </div>
            <ul v-if="ticket.worklogs.length" class="sd-worklogs">
              <li v-for="w in ticket.worklogs" :key="w.id">
                <strong>{{ w.minutes }}m</strong> — {{ w.user?.name ?? "—" }}
                <span class="td-meta">{{ w.performed_on }}<template v-if="!w.under_contract"> · outside contract</template></span>
              </li>
            </ul>
          </section>

          <!-- ── Meetings: Teams calls scheduled against this ticket ──────── -->
          <section class="sd-card" v-show="sidebarTab === 'meetings'">
            <p class="td-card-title">Meetings</p>

            <ul v-if="ticket.meetings?.length" class="sd-meetings">
              <li v-for="m in ticket.meetings" :key="m.id" :class="{ off: m.status === 'cancelled' }">
                <div class="sd-meeting-head">
                  <strong>{{ m.subject }}</strong>
                  <span :class="['sd-pill', m.status === 'cancelled' ? 'sd-clock-mute' : 'sd-clock-ok']">
                    {{ m.status === "cancelled" ? "Cancelled" : "Scheduled" }}
                  </span>
                </div>
                <span class="td-meta">
                  {{ when(m.scheduled_at) }} · {{ m.duration_minutes }} min
                  <template v-if="m.creator"> · booked by {{ m.creator.name }}</template>
                </span>
                <span class="td-meta">
                  {{ m.attendees.map(a => a.name || a.address).join(", ") }}
                </span>
                <div v-if="m.status === 'scheduled'" class="sd-meeting-actions">
                  <a v-if="m.join_url" :href="m.join_url" target="_blank" rel="noopener" class="link">Join in Teams</a>
                  <button class="link" @click="cancelMeeting(m.id)">Cancel</button>
                </div>
                <a v-if="m.recording_url" :href="m.recording_url" target="_blank" rel="noopener" class="link">Recording</a>
                <p v-if="m.transcript" class="td-hint">Transcript available.</p>
              </li>
            </ul>
            <p v-else class="td-hint">No calls scheduled yet.</p>

            <button v-if="!showScheduleMeeting" class="link" @click="openScheduleMeeting">Schedule a Teams call</button>
            <div v-else class="sd-assign">
              <input v-model="meetingDraft.subject" class="control" placeholder="Subject" />
              <input v-model="meetingDraft.scheduled_at" type="datetime-local" class="control" />
              <select v-model.number="meetingDraft.duration_minutes" class="control">
                <option :value="15">15 minutes</option>
                <option :value="30">30 minutes</option>
                <option :value="45">45 minutes</option>
                <option :value="60">1 hour</option>
                <option :value="90">1.5 hours</option>
              </select>
              <textarea v-model="meetingDraft.agenda" class="control" rows="2" placeholder="Agenda (optional)"></textarea>

              <p class="td-card-subtitle">Attendees</p>
              <label v-for="c in meetingCandidates" :key="c.address" class="sd-check">
                <input type="checkbox" v-model="meetingAttendeeChecks[c.address]" />
                <span>{{ c.name || c.address }} <span class="td-meta">{{ c.address }}</span></span>
              </label>
              <input v-model="meetingDraft.extra_attendees" class="control" placeholder="Add more emails, comma-separated" />

              <button class="btn btn-primary btn-sm" :disabled="meetingBusy || !meetingDraft.subject.trim() || !meetingDraft.scheduled_at" @click="scheduleMeeting">
                {{ meetingBusy ? "Scheduling…" : "Schedule" }}
              </button>
              <button class="link" @click="showScheduleMeeting = false">Cancel</button>
            </div>
          </section>

          <!-- ── RCA: Root Cause Analysis, generatable as a PDF ───────────── -->
          <section class="sd-card sd-rca" v-show="sidebarTab === 'rca'">
            <div class="sd-rca-head">
              <p class="td-card-title" style="margin: 0">Root Cause Analysis</p>
              <div class="sd-rca-head-actions">
                <select v-model="rca.status" class="control sd-rca-status">
                  <option value="draft">Draft</option>
                  <option value="final">Final</option>
                </select>
                <button class="btn btn-secondary btn-sm" :disabled="rcaPdfBusy" @click="downloadRcaPdf">
                  {{ rcaPdfBusy ? "Building…" : "Download PDF" }}
                </button>
                <button class="btn btn-primary btn-sm" :disabled="rcaBusy" @click="saveRca">
                  {{ rcaBusy ? "Saving…" : "Save" }}
                </button>
              </div>
            </div>

            <details open>
              <summary>1. Document Information</summary>
              <div class="sd-rca-grid">
                <label class="sd-field"><span>Title</span><input v-model="rca.title" class="control" /></label>
                <label class="sd-field"><span>Date of incident</span><input v-model="rca.incident_date" type="date" class="control" /></label>
                <label class="sd-field"><span>Date RCA prepared</span><input v-model="rca.prepared_date" type="date" class="control" /></label>
                <label class="sd-field"><span>System / service affected</span><input v-model="rca.system_affected" class="control" /></label>
                <label class="sd-field"><span>Business unit</span><input v-model="rca.business_unit" class="control" /></label>
                <label class="sd-field"><span>Incident severity</span><input v-model="rca.severity" class="control" placeholder="Low / Medium / High / Critical" /></label>
                <label class="sd-field"><span>Incident owner</span><input v-model="rca.incident_owner" class="control" /></label>
                <label class="sd-field"><span>Technical owner</span><input v-model="rca.technical_owner" class="control" /></label>
                <label class="sd-field sd-field-full"><span>Reviewers (comma-separated)</span>
                  <input :value="rca.reviewers.join(', ')" class="control"
                         @change="rca.reviewers = ($event.target as HTMLInputElement).value.split(',').map(s => s.trim()).filter(Boolean)" />
                </label>
              </div>
            </details>

            <details>
              <summary>2. Executive Summary</summary>
              <textarea v-model="rca.executive_summary" class="control" rows="4"
                        placeholder="What happened, when, what was affected, duration, business impact, current status."></textarea>
            </details>

            <details>
              <summary>3. Incident Description</summary>
              <label class="sd-field"><span>Symptoms observed</span><textarea v-model="rca.incident_description.symptoms" class="control" rows="2"></textarea></label>
              <label class="sd-field"><span>Systems affected</span><textarea v-model="rca.incident_description.systems_affected" class="control" rows="2"></textarea></label>
              <label class="sd-field"><span>Users affected</span><textarea v-model="rca.incident_description.users_affected" class="control" rows="2"></textarea></label>
              <label class="sd-field"><span>Error messages</span><textarea v-model="rca.incident_description.error_messages" class="control" rows="2"></textarea></label>
              <label class="sd-field"><span>How it was detected</span><textarea v-model="rca.incident_description.detection_method" class="control" rows="2"></textarea></label>
              <label class="sd-field"><span>Initial observations</span><textarea v-model="rca.incident_description.initial_observations" class="control" rows="2"></textarea></label>
            </details>

            <details>
              <summary>4. Business Impact</summary>
              <div class="sd-rca-grid">
                <label class="sd-field"><span>Users affected</span><input v-model="rca.business_impact.users_affected_count" class="control" /></label>
                <label class="sd-field"><span>Transactions affected</span><input v-model="rca.business_impact.transactions_affected" class="control" /></label>
                <label class="sd-field sd-field-full"><span>Revenue impact</span><input v-model="rca.business_impact.revenue_impact" class="control" /></label>
              </div>
              <label class="sd-field"><span>SLA impact</span><textarea v-model="rca.business_impact.sla_impact" class="control" rows="2"></textarea></label>
              <label class="sd-field"><span>Operational impact</span><textarea v-model="rca.business_impact.operational_impact" class="control" rows="2"></textarea></label>
              <label class="sd-field"><span>Customer impact</span><textarea v-model="rca.business_impact.customer_impact" class="control" rows="2"></textarea></label>
              <label class="sd-field"><span>Compliance / security impact</span><textarea v-model="rca.business_impact.compliance_security_impact" class="control" rows="2"></textarea></label>
              <label class="sd-field"><span>Data loss</span><textarea v-model="rca.business_impact.data_loss" class="control" rows="2"></textarea></label>
            </details>

            <details>
              <summary>5. Incident Timeline</summary>
              <table class="sd-rca-table">
                <thead><tr><th>Time</th><th>Event</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="(row, i) in rca.timeline" :key="i">
                    <td><input v-model="row.time" class="control" /></td>
                    <td><input v-model="row.event" class="control" /></td>
                    <td><button class="link" @click="removeTimelineRow(i)">✕</button></td>
                  </tr>
                </tbody>
              </table>
              <button class="link" @click="addTimelineRow">+ Add row</button>
            </details>

            <details>
              <summary>6. Technical Investigation</summary>
              <textarea v-model="rca.technical_investigation" class="control" rows="4"
                        placeholder="Logs reviewed, infrastructure/database/network events, config changes, deployment history, monitoring alerts, third-party dependencies."></textarea>
            </details>

            <details open>
              <summary>7. Root Cause <span class="sd-req">Required</span></summary>
              <label class="sd-field"><span>Immediate cause</span><textarea v-model="rca.immediate_cause" class="control" rows="2" placeholder="What directly caused the incident."></textarea></label>
              <label class="sd-field"><span>Root cause</span><textarea v-model="rca.root_cause" class="control" rows="2" placeholder="Why the failure occurred."></textarea></label>
              <label class="sd-field"><span>Contributing factors</span><textarea v-model="rca.contributing_factors" class="control" rows="2" placeholder="Other conditions that increased likelihood or impact."></textarea></label>
              <label class="sd-field"><span>Control failure</span><textarea v-model="rca.control_failure" class="control" rows="2" placeholder="Why existing controls did not prevent or detect this."></textarea></label>
            </details>

            <details>
              <summary>8. Five Whys Analysis</summary>
              <div v-for="(w, i) in rca.five_whys" :key="i" class="sd-rca-why">
                <label class="sd-field"><span>Why {{ i + 1 }}?</span><input v-model="w.question" class="control" /></label>
                <label class="sd-field"><span>Answer</span><input v-model="w.answer" class="control" /></label>
              </div>
              <label class="sd-field"><span>Final root cause</span><textarea v-model="rca.final_root_cause" class="control" rows="2"></textarea></label>
            </details>

            <details>
              <summary>9. Root Cause Classification</summary>
              <select v-model="rca.root_cause_category" class="control">
                <option :value="null">Not classified</option>
                <option v-for="c in RCA_CATEGORIES" :key="c" :value="c">{{ c }}</option>
              </select>
            </details>

            <details>
              <summary>10. Resolution</summary>
              <textarea v-model="rca.resolution" class="control" rows="3"
                        placeholder="Immediate fix, configuration changes, restart, rollback, data correction, vendor intervention, validation performed."></textarea>
            </details>

            <details open>
              <summary>11. Corrective Actions <span class="sd-req">Required</span></summary>
              <table class="sd-rca-table">
                <thead><tr><th>Action</th><th>Owner</th><th>Priority</th><th>Due</th><th>Status</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="(a, i) in rca.corrective_actions" :key="i">
                    <td><input v-model="a.action" class="control" /></td>
                    <td><input v-model="a.owner" class="control" /></td>
                    <td>
                      <select v-model="a.priority" class="control"><option>Low</option><option>Medium</option><option>High</option></select>
                    </td>
                    <td><input v-model="a.due_date" type="date" class="control" /></td>
                    <td>
                      <select v-model="a.status" class="control"><option>Open</option><option>In Progress</option><option>Completed</option></select>
                    </td>
                    <td><button class="link" @click="removeRcaAction('corrective_actions', i)">✕</button></td>
                  </tr>
                </tbody>
              </table>
              <button class="link" @click="addRcaAction('corrective_actions')">+ Add action</button>
            </details>

            <details open>
              <summary>12. Preventive Actions <span class="sd-req">Required</span></summary>
              <table class="sd-rca-table">
                <thead><tr><th>Action</th><th>Owner</th><th>Priority</th><th>Due</th><th>Status</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="(a, i) in rca.preventive_actions" :key="i">
                    <td><input v-model="a.action" class="control" /></td>
                    <td><input v-model="a.owner" class="control" /></td>
                    <td>
                      <select v-model="a.priority" class="control"><option>Low</option><option>Medium</option><option>High</option></select>
                    </td>
                    <td><input v-model="a.due_date" type="date" class="control" /></td>
                    <td>
                      <select v-model="a.status" class="control"><option>Open</option><option>In Progress</option><option>Completed</option></select>
                    </td>
                    <td><button class="link" @click="removeRcaAction('preventive_actions', i)">✕</button></td>
                  </tr>
                </tbody>
              </table>
              <button class="link" @click="addRcaAction('preventive_actions')">+ Add action</button>
            </details>

            <details>
              <summary>13. Lessons Learned</summary>
              <textarea v-model="rca.lessons_learned" class="control" rows="3"
                        placeholder="What worked well, what didn't, what monitoring was missing, what process needs improvement."></textarea>
            </details>

            <details>
              <summary>14. Evidence</summary>
              <label class="sd-rca-upload">
                <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" @change="onEvidenceFilesPicked" />
                <span>{{ evidenceUploading ? "Uploading…" : "📎 Attach screenshots, logs or documents" }}</span>
              </label>
              <p class="td-hint">PDF, JPEG, PNG, WEBP, DOC, DOCX · Max 10 MB each</p>
              <p v-if="evidenceError" class="error" style="margin: 8px 0">{{ evidenceError }}</p>

              <ul v-if="rca.evidence_files?.length" class="sd-evidence-list">
                <li v-for="f in rca.evidence_files" :key="f.id">
                  <span class="sd-evidence-icon">{{ evidenceFileIcon(f.mime_type) }}</span>
                  <div class="sd-evidence-info">
                    <button class="link" @click="downloadEvidenceFile(f)">{{ f.original_name }}</button>
                    <span class="td-meta">{{ formatBytes(f.size_bytes) }}<template v-if="f.uploaded_by"> · {{ f.uploaded_by.name }}</template> · {{ when(f.created_at) }}</span>
                  </div>
                  <button class="link" @click="deleteEvidenceFile(f)">Remove</button>
                </li>
              </ul>

              <label class="sd-field" style="margin-top: 10px"><span>Other references (links, ticket numbers, etc.)</span>
                <textarea v-model="rca.evidence" class="control" rows="3"
                          placeholder="Log extracts, monitoring graphs, change tickets, links — one per line."></textarea>
              </label>
            </details>

            <details>
              <summary>15. Risk Assessment</summary>
              <label class="sd-field"><span>Similar systems that could be affected</span><textarea v-model="rca.risk_assessment.similar_systems" class="control" rows="2"></textarea></label>
              <label class="sd-field"><span>Probability of recurrence</span><input v-model="rca.risk_assessment.probability" class="control" placeholder="Low / Medium / High" /></label>
              <label class="sd-field"><span>Potential impact</span><textarea v-model="rca.risk_assessment.impact" class="control" rows="2"></textarea></label>
              <label class="sd-field"><span>Existing controls</span><textarea v-model="rca.risk_assessment.existing_controls" class="control" rows="2"></textarea></label>
              <label class="sd-field"><span>Control gaps</span><textarea v-model="rca.risk_assessment.control_gaps" class="control" rows="2"></textarea></label>
              <label class="sd-field"><span>Recommended risk treatment</span><textarea v-model="rca.risk_assessment.recommended_treatment" class="control" rows="2"></textarea></label>
            </details>

            <p class="td-hint">Section 16, the Final Action Plan, is built automatically from Corrective and Preventive Actions above when the PDF is generated.</p>

            <div class="sd-rca-foot">
              <button class="btn btn-secondary btn-sm" :disabled="rcaPdfBusy" @click="downloadRcaPdf">
                {{ rcaPdfBusy ? "Building…" : "Download PDF" }}
              </button>
              <button class="btn btn-primary btn-sm" :disabled="rcaBusy" @click="saveRca">
                {{ rcaBusy ? "Saving…" : "Save" }}
              </button>
            </div>
          </section>

          <!-- ── Timeline: every message, status change, assignment and escalation, in order ── -->
          <section class="sd-card" v-show="sidebarTab === 'timeline'">
            <p class="td-card-title">Timeline</p>
            <ol v-if="timeline.length" class="sd-timeline">
              <li v-for="(e, i) in timeline" :key="i" :class="'sd-tl-' + e.kind">
                <span class="sd-tl-dot" aria-hidden="true"></span>
                <div class="sd-tl-body">
                  <div class="sd-tl-head">
                    <strong>{{ e.label }}</strong>
                    <span class="sd-when">{{ when(e.at) }}</span>
                  </div>
                  <p v-if="e.detail" class="td-meta sd-tl-detail">{{ e.detail }}</p>
                  <span v-if="e.actor" class="td-meta">— {{ e.actor }}</span>
                </div>
              </li>
            </ol>
            <p v-else class="td-hint">Nothing recorded yet.</p>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

/*
 * Fluid by default, matching every other page in the app — `.view` (see
 * style.css) leaves max-width unset unless a page opts into `.view--stacked`
 * for a single stacked column. This page is a two-column grid, the kind
 * style.css says should take the width it's given, so it stays plain `.view`.
 * `.app-content` already supplies the outer gutter — nothing added here.
 */

.td-topbar { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.td-back-btn {
  display: inline-flex; align-items: center; gap: 6px; font-size: .82rem; font-weight: 600;
  color: var(--color-text); background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: var(--radius-md, 8px); padding: 7px 12px; cursor: pointer; transition: background .12s, border-color .12s;
}
.td-back-btn:hover { background: var(--color-surface-sunken); border-color: var(--color-border-strong); }
.td-back-icon { font-size: .95rem; line-height: 1; }
.td-crumb-ref { font-family: ui-monospace, monospace; font-size: .78rem; color: var(--color-text-light); }

.td-loading { color: var(--color-text-light); padding: 24px 0; }
.td-notice { font-size: .85rem; padding: 9px 12px; border-radius: 8px; background: var(--color-success-light); color: var(--color-success); margin-bottom: 14px; }

.td-header { margin-bottom: 16px; }
.td-mono { font-family: ui-monospace, monospace; font-size: .78rem; color: var(--color-text-light); }
.td-ref { display: block; }
.td-title { font-size: 1.4rem; font-weight: 600; color: var(--color-text); margin: 4px 0 6px; }
.td-meta { font-size: .82rem; color: var(--color-text-light); }

/* ── Ergonomic top strip: type / status / priority / SLA at a glance ──── */
.td-top-strip {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: var(--space-4);
  margin-bottom: var(--space-5);
}
.td-top-card {
  background: var(--color-surface-raised); border: 1px solid var(--color-border);
  border-radius: var(--radius-lg); padding: 14px 16px; display: flex; flex-direction: column; gap: 6px;
}
.td-top-label { font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--color-text-muted); }
.td-hint { font-size: .76rem; color: var(--color-text-light); margin: 0; }

.td-top-action {
  align-items: flex-start; justify-content: center; text-align: left; cursor: pointer;
  background: var(--color-brand-subtle); border-color: var(--color-brand-base); transition: background .12s;
}
.td-top-action:hover { background: var(--color-brand-light); }
.td-top-action-text { font-size: .9rem; font-weight: 600; color: var(--color-brand-base); }

/* ── Two-column body: thread + sidebar ─────────────────────────────────── */
.td-columns { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: var(--space-4); align-items: start; }
@media (max-width: 60rem) { .td-columns { grid-template-columns: 1fr; } }

.sd-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 16px 18px; }
.td-card-title { font-size: .78rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-muted); margin: 0 0 12px; }
.td-card-subtitle { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-muted); margin: 14px 0 8px; }

.td-thread-card { display: flex; flex-direction: column; gap: 0; }

.td-thread-header {
  display: flex; align-items: center; gap: 10px; width: 100%; text-align: left;
  background: none; border: none; padding: 0 0 12px; margin: 0; cursor: pointer;
}
.td-thread-title { margin: 0; }
.td-thread-count { font-size: .76rem; color: var(--color-text-light); }
.td-thread-chevron { margin-left: auto; font-size: 1rem; line-height: 1; color: var(--color-text-light); transition: transform .15s ease; }
.td-thread-chevron.open { transform: rotate(180deg); }
.td-thread-body { display: flex; flex-direction: column; gap: 14px; padding-top: 14px; border-top: 1px solid var(--color-border); }

.td-side { display: flex; flex-direction: column; gap: var(--space-3); }

.td-tabs {
  display: flex; flex-wrap: wrap; gap: 4px; background: var(--color-surface-sunken);
  border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 4px;
}
.td-tab-btn {
  flex: 1 1 auto; display: inline-flex; align-items: center; justify-content: center; gap: 5px;
  font-size: .78rem; font-weight: 600; color: var(--color-text-light); background: none; border: none;
  border-radius: var(--radius-md, 8px); padding: 7px 8px; cursor: pointer; white-space: nowrap; transition: background .12s, color .12s;
}
.td-tab-btn:hover { color: var(--color-text); }
.td-tab-btn.on { background: var(--color-surface); color: var(--color-text); box-shadow: 0 1px 2px rgba(0,0,0,.06); }
.td-tab-badge {
  font-size: .66rem; font-weight: 700; padding: 0 5px; border-radius: 999px;
  background: var(--color-brand-base); color: #fff; min-width: 15px; line-height: 15px; text-align: center;
}

/* ── Reused from the old dialog's visual language ──────────────────────── */
.sd-thread { display: flex; flex-direction: column; gap: 10px; }
.sd-msg { border: 1px solid var(--color-border); border-radius: 10px; padding: 10px 12px; }
.sd-msg.customer { background: var(--color-surface-sunken); }
.sd-msg.agent { background: var(--color-brand-subtle); }
.sd-msg.internal { background: repeating-linear-gradient(135deg, var(--color-warning-light), var(--color-warning-light) 10px, transparent 10px, transparent 20px); border-color: var(--color-warning); }
.sd-msg.meeting { background: var(--color-success-light); border-color: var(--color-success); border-style: dashed; }
.sd-msg.meeting .sd-msg-body { white-space: pre-wrap; }
.sd-msg-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: .78rem; margin-bottom: 5px; }
.sd-msg-body { font-size: .88rem; color: var(--color-text); }
.sd-msg-body.clamped { max-height: 22rem; overflow: hidden; -webkit-mask-image: linear-gradient(to bottom, #000 78%, transparent); mask-image: linear-gradient(to bottom, #000 78%, transparent); }
.sd-quote-toggle { margin-top: 8px; padding: 0; background: none; border: none; cursor: pointer; font-size: .76rem; color: var(--color-brand-base); }
.sd-quote-toggle:hover { text-decoration: underline; }
.sd-msg-quoted { margin-top: 10px; padding-left: 12px; font-size: .82rem; color: var(--color-text-light); border-left: 2px solid var(--color-border); }
.sd-tag { font-size: .68rem; padding: 1px 6px; border-radius: 999px; background: var(--color-surface); color: var(--color-text-light); }
.sd-tag.internal { background: var(--color-warning); color: #fff; }
.sd-tag.call { background: var(--color-success); color: #fff; }
.sd-when { margin-left: auto; font-size: .72rem; color: var(--color-text-light); }

.sd-compose { border: 1px solid var(--color-border); border-radius: 10px; overflow: hidden; }
.sd-compose.internal { border-color: var(--color-warning); }
.sd-compose-tabs { display: flex; border-bottom: 1px solid var(--color-border); }
.sd-tab { flex: 1; padding: 8px; font-size: .8rem; background: var(--color-surface-sunken); border: none; cursor: pointer; color: var(--color-text-light); }
.sd-tab.on { background: var(--color-surface); color: var(--color-text); font-weight: 600; }
.sd-compose textarea { border: none; border-radius: 0; }
.sd-compose-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 10px; border-top: 1px solid var(--color-border); }
.sd-compose-actions { display: flex; gap: 6px; }

.sd-canned { display: flex; flex-direction: column; gap: 2px; max-height: 12rem; overflow-y: auto; border-top: 1px solid var(--color-border); padding: 6px; }
.sd-canned-item { text-align: left; background: none; border: none; cursor: pointer; padding: 6px 8px; border-radius: 6px; font-size: .84rem; }
.sd-canned-item:hover { background: var(--color-surface-sunken); }

.sd-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
.sd-field:last-child { margin-bottom: 0; }
.sd-field > span { font-weight: 600; font-size: .74rem; color: var(--color-text); }
.sd-derived { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }

.sd-pill { display: inline-block; font-size: .7rem; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
.sd-clock-ok { background: var(--color-success-light); color: var(--color-success); }
.sd-clock-warn { background: var(--color-warning-light); color: #7C2D12; }
.sd-clock-bad { background: var(--color-error-light); color: var(--color-error); }
.sd-clock-mute { background: var(--color-surface-sunken); color: var(--color-text-light); }
.sd-sla { display: flex; flex-direction: column; gap: 4px; align-items: flex-start; }
.sd-esc { color: var(--color-error); }

.sd-suggest { list-style: none; margin: 0 0 8px; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.sd-suggest li.off { opacity: .55; }
.sd-suggest-btn { width: 100%; text-align: left; background: var(--color-surface-sunken); border: 1px solid var(--color-border); border-radius: 8px; padding: 7px 9px; cursor: pointer; display: flex; flex-direction: column; gap: 1px; font-size: .8rem; }
.sd-suggest-btn:hover { border-color: var(--color-brand-base); }
.sd-assign { display: flex; flex-direction: column; gap: 6px; margin-top: 6px; padding: 9px; border-radius: 8px; background: var(--color-surface-sunken); }

.sd-escalations { list-style: none; margin: 0 0 6px; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.sd-escalations li { display: flex; flex-direction: column; gap: 2px; font-size: .8rem; padding: 8px; border-radius: 8px; background: var(--color-warning-light); }
.sd-done { color: var(--color-success); }

.sd-splits { list-style: none; margin: 0 0 8px; padding: 0; display: flex; flex-direction: column; gap: 3px; font-size: .78rem; color: var(--color-text-light); }
.sd-split-pick { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 3px; max-height: 10rem; overflow-y: auto; font-size: .78rem; }
.sd-check { display: flex; align-items: center; gap: 8px; font-size: .85rem; cursor: pointer; }

.sd-time { display: flex; flex-direction: column; gap: 6px; padding: 10px; border-radius: 8px; background: var(--color-surface-sunken); margin-top: 8px; }
.sd-worklogs { list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-direction: column; gap: 5px; font-size: .78rem; max-height: 10rem; overflow-y: auto; }

/* ── Meetings ─────────────────────────────────────────────────────────────── */
.sd-meetings { list-style: none; margin: 0 0 10px; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.sd-meetings li { display: flex; flex-direction: column; gap: 3px; padding: 9px 11px; border-radius: 8px; background: var(--color-surface-sunken); border: 1px solid var(--color-border); }
.sd-meetings li.off { opacity: .6; }
.sd-meeting-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.sd-meeting-actions { display: flex; gap: 10px; margin-top: 4px; }

/* ── Root Cause Analysis ──────────────────────────────────────────────────── */
.sd-rca-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px; }
.sd-rca-head-actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.sd-rca-status { width: auto; padding: 6px 8px; font-size: .82rem; }

.sd-rca details {
  border: 1px solid var(--color-border); border-radius: 8px; margin-bottom: 8px;
  background: var(--color-surface-sunken);
}
.sd-rca details[open] { background: var(--color-surface); }
.sd-rca summary {
  padding: 10px 12px; font-size: .84rem; font-weight: 600; cursor: pointer;
  list-style: none; display: flex; align-items: center; gap: 8px;
}
.sd-rca summary::-webkit-details-marker { display: none; }
.sd-rca summary::before { content: "▸"; font-size: .7rem; color: var(--color-text-light); }
.sd-rca details[open] > summary::before { content: "▾"; }
.sd-rca details > *:not(summary) { padding: 0 12px 12px; }
.sd-rca details > .sd-rca-grid { padding: 0 12px 12px; }
.sd-rca .sd-field { margin-bottom: 10px; }
.sd-rca textarea.control { resize: vertical; }

.sd-req { font-size: .64rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; padding: 1px 6px; border-radius: 999px; background: var(--color-error-light); color: var(--color-error); }

.sd-rca-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
.sd-field-full { grid-column: 1 / -1; }

.sd-rca-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
.sd-rca-table th { font-size: .68rem; text-transform: uppercase; letter-spacing: .03em; color: var(--color-text-muted); text-align: left; padding: 4px 6px; }
.sd-rca-table td { padding: 3px 4px; vertical-align: middle; }
.sd-rca-table td .control { font-size: .8rem; padding: 5px 7px; }

.sd-rca-why { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; padding-bottom: 4px; border-bottom: 1px dashed var(--color-border); margin-bottom: 8px; }
.sd-rca-why:last-of-type { border-bottom: none; }

.sd-rca-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--color-border); }

.sd-rca-upload {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  border: 2px dashed var(--color-border-strong); border-radius: 8px; padding: 14px;
  cursor: pointer; font-size: .82rem; font-weight: 500; color: var(--color-text-light);
  transition: border-color .12s, background .12s; text-align: center;
}
.sd-rca-upload:hover { border-color: var(--color-brand-base); background: var(--color-brand-subtle); }
.sd-rca-upload input[type="file"] { display: none; }

.sd-evidence-list { list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-direction: column; gap: 1px; border: 1px solid var(--color-border); border-radius: 8px; overflow: hidden; }
.sd-evidence-list li { display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: var(--color-surface); border-bottom: 1px solid var(--color-border); }
.sd-evidence-list li:last-child { border-bottom: none; }
.sd-evidence-icon { font-size: 1.1rem; flex-shrink: 0; }
.sd-evidence-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
.sd-evidence-info .link { text-align: left; font-size: .82rem; }

/* ── Client / Internal participant lists ─────────────────────────────────── */
.sd-participants { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.sd-participants li { display: flex; flex-direction: column; gap: 1px; font-size: .82rem; padding: 6px 8px; border-radius: 8px; background: var(--color-surface-sunken); }

/* ── Timeline ─────────────────────────────────────────────────────────────── */
.sd-timeline { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0; max-height: 34rem; overflow-y: auto; }
.sd-timeline li { position: relative; display: flex; gap: 10px; padding: 0 0 16px 4px; }
.sd-timeline li::before {
  content: ""; position: absolute; left: 8px; top: 14px; bottom: -2px; width: 1px; background: var(--color-border);
}
.sd-timeline li:last-child::before { display: none; }
.sd-tl-dot {
  flex-shrink: 0; width: 9px; height: 9px; margin-top: 4px; border-radius: 50%;
  background: var(--color-text-light); z-index: 1;
}
.sd-tl-message .sd-tl-dot { background: var(--color-brand-base); }
.sd-tl-event .sd-tl-dot { background: var(--color-warning); }
.sd-tl-escalation .sd-tl-dot { background: var(--color-error); }
.sd-tl-milestone .sd-tl-dot { background: var(--color-success); }
.sd-tl-body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.sd-tl-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px; font-size: .84rem; }
.sd-tl-detail { margin: 0; }

/* ── Response & Ownership ─────────────────────────────────────────────────── */
.sd-effort-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 10px; margin-bottom: 6px; }
.sd-effort-stat {
  display: flex; flex-direction: column; gap: 3px; padding: 10px 12px;
  border-radius: 10px; background: var(--color-surface-sunken); border: 1px solid var(--color-border);
}
.sd-effort-stat strong { font-size: 1.05rem; }
.sd-effort-support { border-color: var(--color-brand-base); }
.sd-effort-client { border-color: var(--color-warning); }
.sd-effort-vendor { border-color: var(--color-error); }

.sd-owner-now { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; }

.sd-resp-periods { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; max-height: 18rem; overflow-y: auto; }
.sd-resp-periods li {
  display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px; font-size: .8rem;
  padding: 7px 10px; border-radius: 8px; border-left: 3px solid var(--color-border);
}
.sd-resp-support { border-left-color: var(--color-brand-base); background: var(--color-brand-subtle); }
.sd-resp-client { border-left-color: var(--color-warning); background: var(--color-warning-light); }
.sd-resp-vendor { border-left-color: var(--color-error); background: var(--color-error-light); }
.sd-resp-owner { font-weight: 600; }

.sd-linkbox { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 8px; border-radius: 8px; background: var(--color-surface-sunken); margin-bottom: 6px; }
.sd-linkbox code { font-size: .7rem; word-break: break-all; flex: 1 1 10rem; color: var(--color-text-light); }

/* These two never had definitions in the old dialog either — a bare button
   with no visual affordance is still a bug worth fixing while it's here. */
.control { width: 100%; font-family: inherit; font-size: .88rem; padding: 8px 10px; border: 1px solid var(--color-border-strong); border-radius: 8px; background: var(--color-surface); color: var(--color-text); }
.link { background: none; border: none; padding: 0; cursor: pointer; font-size: .78rem; color: var(--color-brand-base); }
.link:hover { text-decoration: underline; }
.link:disabled { opacity: .5; cursor: not-allowed; text-decoration: none; }

.link-btn { background: none; border: none; padding: 0; font-size: .82rem; color: var(--color-brand-base); cursor: pointer; }

.error { font-size: .85rem; color: var(--color-error); background: var(--color-error-light); border-radius: 8px; padding: 9px 12px; margin-bottom: 14px; }
</style>
