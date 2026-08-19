<script setup lang="ts">
/**
 * The service desk.
 *
 * Replacing the old PLM tickets module. The list opens on unresolved work
 * because a desk that opens on two years of closed tickets is a list nobody
 * reads, and the detail pane leads with the conversation because that is what
 * an agent is actually here to do.
 *
 * The public/internal distinction is drawn hard in the UI, not just the data.
 * The old module had no private channel and collected thirteen notes across
 * 1,792 tickets — agents wrote nothing down because there was nowhere safe to
 * write it.
 */
import { computed, onMounted, ref, watch } from "vue";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

const org = () => currentOrgSlug.value;

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

interface TicketRow {
  id: number; reference: string; subject: string;
  sla: TicketClock | null;
  requester_name: string | null; requester_email: string | null;
  status: Status | null; type: Named | null; priority: Priority | null;
  assignee: { id: number; name: string } | null;
  account: { id: number; name: string } | null;
  messages_count: number; opened_at: string | null; first_response_at: string | null;
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

interface TicketDetail extends TicketRow {
  body: string | null;
  sla_events?: { id: number; event: string; clock: string | null; detail: string | null;
                 working_minutes: number | null; occurred_at: string }[];
  urgency: Named | null; impact: Named | null;
  contract: { id: number; name: string } | null;
  messages: Message[]; worklogs: Worklog[]; resolved_at: string | null;
}

const taxonomy = ref<{
  statuses: Status[]; types: Named[]; urgencies: Named[]; impacts: Named[];
  priorities: Priority[]; states: Record<string, string>;
}>({ statuses: [], types: [], urgencies: [], impacts: [], priorities: [], states: {} });

const rows      = ref<TicketRow[]>([]);
const listBusy  = ref(false);
const listError = ref("");
const query     = ref("");
const stateFilter = ref("");
const showAll   = ref(false);

const open      = ref<TicketDetail | null>(null);
const openBusy  = ref(false);
const openError = ref("");
const minutesLogged = ref(0);
const slaUsed  = ref(0);
const elapsed  = ref(0);

const reply      = ref({ body: "", visibility: "public" as "public" | "internal" });
const replyBusy  = ref(false);
const timeDraft  = ref({ minutes: "", note: "", under_contract: true });
const showTime   = ref(false);

const pendingStates = computed(() => taxonomy.value.statuses.filter((s) => s.state === "pending"));

async function loadTaxonomy(): Promise<void> {
  try {
    taxonomy.value = await api("GET", "/api/v1/support/taxonomy", { org: org() });
  } catch { /* the list still renders; pickers simply stay empty */ }
}

async function loadList(): Promise<void> {
  listBusy.value = true; listError.value = "";
  try {
    const params = new URLSearchParams();
    if (query.value.trim()) params.set("q", query.value.trim());
    if (stateFilter.value) params.set("state", stateFilter.value);
    if (showAll.value) params.set("all", "1");

    const res = await api<{ data: TicketRow[] }>(
      "GET", `/api/v1/support/tickets?${params.toString()}`, { org: org() },
    );
    rows.value = res.data;
  } catch (e) {
    listError.value = e instanceof Error ? e.message : "Could not load the queue.";
  } finally { listBusy.value = false; }
}

async function openTicket(row: TicketRow): Promise<void> {
  openBusy.value = true; openError.value = ""; reply.value = { body: "", visibility: "public" };
  try {
    const res = await api<{
      data: TicketDetail; minutes_logged: number;
      sla_minutes_used: number; elapsed_minutes: number;
    }>("GET", `/api/v1/support/tickets/${row.id}`, { org: org() });
    open.value = res.data;
    minutesLogged.value = res.minutes_logged;
    slaUsed.value = res.sla_minutes_used;
    elapsed.value = res.elapsed_minutes;
  } catch (e) {
    openError.value = e instanceof Error ? e.message : "Could not open the ticket.";
  } finally { openBusy.value = false; }
}

async function refreshOpen(): Promise<void> {
  if (open.value) await openTicket(open.value as TicketRow);
  await loadList();
}

async function sendReply(): Promise<void> {
  if (!open.value || !reply.value.body.trim()) return;
  replyBusy.value = true; openError.value = "";
  try {
    await api("POST", `/api/v1/support/tickets/${open.value.id}/reply`, {
      org: org(),
      body: { body: reply.value.body, visibility: reply.value.visibility },
    });
    reply.value.body = "";
    await refreshOpen();
  } catch (e) {
    openError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not post it.";
  } finally { replyBusy.value = false; }
}

async function setStatus(statusId: number): Promise<void> {
  if (!open.value) return;
  openError.value = "";
  try {
    await api("POST", `/api/v1/support/tickets/${open.value.id}/status`, {
      org: org(), body: { ticket_status_id: statusId },
    });
    await refreshOpen();
  } catch (e) {
    // Closing needs its own permission; say which rather than failing silently.
    openError.value = e instanceof ApiError ? e.message : "Could not change the status.";
  }
}

async function classify(field: string, value: string): Promise<void> {
  if (!open.value) return;
  openError.value = "";
  try {
    await api("POST", `/api/v1/support/tickets/${open.value.id}/classify`, {
      org: org(), body: { [field]: value ? Number(value) : null },
    });
    await refreshOpen();
  } catch (e) {
    openError.value = e instanceof Error ? e.message : "Could not reclassify it.";
  }
}

async function logTime(): Promise<void> {
  if (!open.value || !Number(timeDraft.value.minutes)) return;
  try {
    await api("POST", `/api/v1/support/tickets/${open.value.id}/time`, {
      org: org(),
      body: {
        minutes: Number(timeDraft.value.minutes),
        note: timeDraft.value.note || null,
        under_contract: timeDraft.value.under_contract,
      },
    });
    timeDraft.value = { minutes: "", note: "", under_contract: true };
    showTime.value = false;
    await refreshOpen();
  } catch (e) {
    openError.value = e instanceof Error ? e.message : "Could not log the time.";
  }
}

// ── The clock ──────────────────────────────────────────────────────────────────
/*
 * What a row says about its SLA.
 *
 * The old system showed nothing, because every figure it computed came out of a
 * column that does not exist. The states here are deliberately few — a queue
 * scanned at a glance can carry four meanings, not ten.
 */
function clockState(t: TicketRow): { label: string; tone: "ok" | "warn" | "bad" | "mute" } {
  const c = t.sla;

  // Untriaged: no impact, no urgency, so no promise was made.
  if (!c) return { label: "No target", tone: "mute" };
  if (c.paused_at) return { label: "Paused", tone: "mute" };

  if (c.response_met === false) return { label: "Response breached", tone: "bad" };
  if (c.resolution_met === false) return { label: "Resolution breached", tone: "bad" };

  const due = c.response_met === null ? c.response_due_at : c.resolution_due_at;
  const what = c.response_met === null ? "Reply" : "Fix";

  if (!due) return { label: "No target", tone: "mute" };

  const left = Math.round((new Date(due).getTime() - Date.now()) / 60000);

  if (left < 0) return { label: `${what} overdue`, tone: "bad" };
  // Under an hour is where somebody can still do something about it.
  if (left < 60) return { label: `${what} due in ${left}m`, tone: "warn" };

  const hours = Math.floor(left / 60);

  return hours < 24
    ? { label: `${what} due in ${hours}h`, tone: "ok" }
    : { label: `${what} due in ${Math.floor(hours / 24)}d`, tone: "ok" };
}

function hm(minutes: number): string {
  const h = Math.floor(minutes / 60);
  return h > 0 ? `${h}h ${minutes % 60}m` : `${minutes}m`;
}

// ── Service levels ─────────────────────────────────────────────────────────────
interface SlaTargetRow {
  id: number; priority_id: number; priority: string; level: number;
  response_minutes: number; resolution_minutes: number;
  hod_escalation_minutes: number | null;
  cto_escalation_minutes: number | null;
  ceo_escalation_minutes: number | null;
}
interface SlaRow {
  id: number; name: string; is_default: boolean; is_active: boolean;
  calendar: string | null; calendar_id: number | null;
  contract: string | null; contract_id: number | null;
  targets: SlaTargetRow[];
}
interface CalendarRow {
  id: number; name: string; timezone: string; is_default: boolean;
  windows: { id: number; weekday: number; opens_at: string; closes_at: string }[];
  holidays: { id: number; observed_on: string; name: string }[];
}

const slaData = ref<{
  calendars: CalendarRow[]; slas: SlaRow[];
  priorities: { id: number; name: string; level: number; colour: string | null }[];
  contracts: { id: number; name: string; account: string | null }[];
}>({ calendars: [], slas: [], priorities: [], contracts: [] });

const slaBusy  = ref(false);
const slaError = ref("");
const slaNote  = ref("");
const openSla  = ref<number | null>(null);
const openCal  = ref<number | null>(null);

const weekdays = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

const targetDraft = ref({
  priority_id: "", response_minutes: "", resolution_minutes: "",
  hod_escalation_minutes: "", cto_escalation_minutes: "", ceo_escalation_minutes: "",
});
const slaDraft  = ref({ name: "", account_contract_id: "", support_calendar_id: "", is_default: false });
const holDraft  = ref({ observed_on: "", name: "" });

async function loadSla(): Promise<void> {
  slaBusy.value = true; slaError.value = "";
  try {
    slaData.value = await api("GET", "/api/v1/support/sla", { org: org() });
    if (openSla.value === null && slaData.value.slas.length) openSla.value = slaData.value.slas[0].id;
    if (openCal.value === null && slaData.value.calendars.length) openCal.value = slaData.value.calendars[0].id;
  } catch (e) {
    slaError.value = e instanceof Error ? e.message : "Could not load the service levels.";
  } finally { slaBusy.value = false; }
}

async function addSla(): Promise<void> {
  slaBusy.value = true; slaError.value = "";
  try {
    await api("POST", "/api/v1/support/sla/policies", {
      org: org(),
      body: {
        name: slaDraft.value.name,
        account_contract_id: slaDraft.value.account_contract_id ? Number(slaDraft.value.account_contract_id) : null,
        support_calendar_id: slaDraft.value.support_calendar_id ? Number(slaDraft.value.support_calendar_id) : null,
        is_default: slaDraft.value.is_default,
      },
    });
    slaDraft.value = { name: "", account_contract_id: "", support_calendar_id: "", is_default: false };
    await loadSla();
  } catch (e) {
    slaError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not save it.";
  } finally { slaBusy.value = false; }
}

async function saveTarget(): Promise<void> {
  if (!openSla.value) return;
  slaBusy.value = true; slaError.value = ""; slaNote.value = "";
  try {
    await api("POST", `/api/v1/support/sla/policies/${openSla.value}/targets`, {
      org: org(),
      body: {
        priority_id: Number(targetDraft.value.priority_id),
        response_minutes: Number(targetDraft.value.response_minutes),
        resolution_minutes: Number(targetDraft.value.resolution_minutes),
        hod_escalation_minutes: targetDraft.value.hod_escalation_minutes
          ? Number(targetDraft.value.hod_escalation_minutes) : null,
        cto_escalation_minutes: targetDraft.value.cto_escalation_minutes
          ? Number(targetDraft.value.cto_escalation_minutes) : null,
        ceo_escalation_minutes: targetDraft.value.ceo_escalation_minutes
          ? Number(targetDraft.value.ceo_escalation_minutes) : null,
      },
    });
    slaNote.value = "Saved. Tickets already answered keep the target they were judged against.";
    targetDraft.value = {
      priority_id: "", response_minutes: "", resolution_minutes: "",
      hod_escalation_minutes: "", cto_escalation_minutes: "", ceo_escalation_minutes: "",
    };
    await loadSla();
    setTimeout(() => { slaNote.value = ""; }, 6000);
  } catch (e) {
    // The server names the resolution-inside-response slip; pass it through.
    slaError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not save the target.";
  } finally { slaBusy.value = false; }
}

async function addHoliday(): Promise<void> {
  if (!openCal.value || !holDraft.value.observed_on) return;
  slaBusy.value = true; slaError.value = "";
  try {
    await api("POST", `/api/v1/support/sla/calendars/${openCal.value}/holidays`, {
      org: org(), body: { ...holDraft.value },
    });
    holDraft.value = { observed_on: "", name: "" };
    await loadSla();
  } catch (e) {
    slaError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not add it.";
  } finally { slaBusy.value = false; }
}

async function removeHoliday(id: number): Promise<void> {
  if (!openCal.value) return;
  try {
    await api("DELETE", `/api/v1/support/sla/calendars/${openCal.value}/holidays/${id}`, { org: org() });
    await loadSla();
  } catch (e) {
    slaError.value = e instanceof Error ? e.message : "Could not remove it.";
  }
}

const currentSla = computed(() => slaData.value.slas.find((s) => s.id === openSla.value) ?? null);
const currentCal = computed(() => slaData.value.calendars.find((c) => c.id === openCal.value) ?? null);

// ── Held mail ──────────────────────────────────────────────────────────────────
/*
 * Mail from senders nobody recognises. Held rather than discarded or bounced:
 * discarding means the message a customer swears they sent cannot be produced,
 * and bouncing tells a spammer the address is live.
 *
 * Releasing offers to trust the sender in the same motion, because this is the
 * only moment somebody has the context to decide it. The old module kept its
 * whitelist on a screen of its own, and it stayed empty for two years.
 */
type Tab = "queue" | "held" | "settings" | "sla";
const tab = ref<Tab>("queue");

interface Held {
  id: number; from_name: string | null; from_email: string | null;
  subject: string | null; body_preview: string | null;
  received_at: string | null; quarantine_reason: string;
}

const held      = ref<Held[]>([]);
const heldBusy  = ref(false);
const heldError = ref("");
const heldNote  = ref("");

async function loadHeld(): Promise<void> {
  heldBusy.value = true; heldError.value = "";
  try {
    const res = await api<{ data: Held[] }>("GET", "/api/v1/support/mail/quarantine", { org: org() });
    held.value = res.data;
  } catch (e) {
    heldError.value = e instanceof Error ? e.message : "Could not load held mail.";
  } finally { heldBusy.value = false; }
}

async function releaseHeld(row: Held, trust: "" | "address" | "domain"): Promise<void> {
  heldBusy.value = true; heldError.value = ""; heldNote.value = "";
  try {
    const res = await api<{ ticket: { reference: string } | null }>(
      "POST", `/api/v1/support/mail/quarantine/${row.id}/release`,
      { org: org(), body: trust ? { trust } : {} },
    );
    heldNote.value = res.ticket
      ? `Released as ${res.ticket.reference}${trust ? ` — future mail from this ${trust} goes straight through.` : "."}`
      : "Released.";
    await Promise.all([loadHeld(), loadList()]);
    setTimeout(() => { heldNote.value = ""; }, 6000);
  } catch (e) {
    heldError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not release it.";
  } finally { heldBusy.value = false; }
}

function domainOf(address: string | null): string {
  return address?.split("@")[1] ?? "";
}

// ── Mail settings ──────────────────────────────────────────────────────────────
interface MailboxRow {
  id: number; address: string; is_active: boolean; auto_acknowledge: boolean;
  connection: string | null; subscribed: boolean; expires_at: string | null;
  needs_renewal: boolean; last_delivered_at: string | null; last_error: string | null;
}
interface Rule { id: number; kind: string; value: string; action: string; note: string | null }

const mailboxes  = ref<MailboxRow[]>([]);
const rules      = ref<Rule[]>([]);
const cfgBusy    = ref(false);
const cfgError   = ref("");
const cfgNote    = ref("");
const connections = ref<{ id: number; name: string }[]>([]);

const mailboxDraft = ref({ address: "", integration_connection_id: "", auto_acknowledge: true });
const ruleDraft    = ref({ kind: "domain", value: "", action: "allow", note: "" });

async function loadSettings(): Promise<void> {
  cfgBusy.value = true; cfgError.value = "";
  try {
    const [mb, rl] = await Promise.all([
      api<{ data: MailboxRow[] }>("GET", "/api/v1/support/mailboxes", { org: org() }),
      api<{ data: Rule[] }>("GET", "/api/v1/support/sender-rules", { org: org() }),
    ]);
    mailboxes.value = mb.data;
    rules.value = rl.data;
  } catch (e) {
    cfgError.value = e instanceof Error ? e.message : "Could not load the mail settings.";
  } finally { cfgBusy.value = false; }

  try {
    const res = await api<{ data: any[] }>("GET", "/api/v1/integrations/connections", { org: org() });
    connections.value = (res.data ?? []).map((c: any) => ({ id: c.id, name: c.name }));
  } catch { connections.value = []; }
}

async function addMailbox(): Promise<void> {
  cfgBusy.value = true; cfgError.value = "";
  try {
    await api("POST", "/api/v1/support/mailboxes", {
      org: org(),
      body: {
        address: mailboxDraft.value.address,
        integration_connection_id: Number(mailboxDraft.value.integration_connection_id),
        auto_acknowledge: mailboxDraft.value.auto_acknowledge,
      },
    });
    mailboxDraft.value = { address: "", integration_connection_id: "", auto_acknowledge: true };
    await loadSettings();
  } catch (e) {
    cfgError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not add the mailbox.";
  } finally { cfgBusy.value = false; }
}

async function subscribe(row: MailboxRow): Promise<void> {
  cfgBusy.value = true; cfgError.value = ""; cfgNote.value = "";
  try {
    await api("POST", `/api/v1/support/mailboxes/${row.id}/subscribe`, { org: org() });
    cfgNote.value = `Microsoft Graph will now notify us about mail to ${row.address}.`;
    await loadSettings();
  } catch (e) {
    // The two usual causes are named by the server; pass them straight through.
    cfgError.value = e instanceof ApiError ? e.message : "Could not subscribe.";
  } finally { cfgBusy.value = false; }
}

async function addRule(): Promise<void> {
  cfgBusy.value = true; cfgError.value = "";
  try {
    await api("POST", "/api/v1/support/sender-rules", { org: org(), body: { ...ruleDraft.value } });
    ruleDraft.value = { kind: "domain", value: "", action: "allow", note: "" };
    await loadSettings();
  } catch (e) {
    cfgError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not save the rule.";
  } finally { cfgBusy.value = false; }
}

async function removeRule(rule: Rule): Promise<void> {
  cfgBusy.value = true;
  try {
    await api("DELETE", `/api/v1/support/sender-rules/${rule.id}`, { org: org() });
    await loadSettings();
  } catch (e) {
    cfgError.value = e instanceof Error ? e.message : "Could not remove it.";
  } finally { cfgBusy.value = false; }
}

// ── New ticket ─────────────────────────────────────────────────────────────────
const showNew = ref(false);
const draft = ref({
  subject: "", body: "", requester_name: "", requester_email: "",
  ticket_type_id: "", urgency_id: "", impact_id: "", source: "phone",
});
const newBusy  = ref(false);
const newError = ref("");

async function createTicket(): Promise<void> {
  newBusy.value = true; newError.value = "";
  try {
    const res = await api<{ data: TicketRow }>("POST", "/api/v1/support/tickets", {
      org: org(),
      body: {
        subject: draft.value.subject,
        body: draft.value.body || null,
        requester_name: draft.value.requester_name || null,
        requester_email: draft.value.requester_email || null,
        ticket_type_id: draft.value.ticket_type_id ? Number(draft.value.ticket_type_id) : null,
        urgency_id: draft.value.urgency_id ? Number(draft.value.urgency_id) : null,
        impact_id: draft.value.impact_id ? Number(draft.value.impact_id) : null,
        source: draft.value.source,
      },
    });
    showNew.value = false;
    draft.value = {
      subject: "", body: "", requester_name: "", requester_email: "",
      ticket_type_id: "", urgency_id: "", impact_id: "", source: "phone",
    };
    await loadList();
    await openTicket(res.data);
  } catch (e) {
    newError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not open the ticket.";
  } finally { newBusy.value = false; }
}

/**
 * Priority is shown as read-only with its inputs beside it. It is a lookup from
 * impact and urgency, and an editable field would invite somebody to disagree
 * with the matrix one ticket at a time.
 */
const priorityHint = computed(() => {
  if (!open.value) return "";
  if (open.value.priority) return "From impact and urgency";
  return "Set impact and urgency to derive it";
});

function when(v: string | null): string {
  return v ? v.slice(0, 16).replace("T", " ") : "—";
}

function init(): void {
  void loadTaxonomy();
  void loadList();
  // Loaded up front so the tab can carry a count — held mail nobody looks at
  // is the same as mail that was discarded.
  void loadHeld();
}

onMounted(init);
watch(currentOrgSlug, init);
watch([stateFilter, showAll], loadList);
watch(tab, (t) => {
  if (t === "held") void loadHeld();
  if (t === "settings") void loadSettings();
  if (t === "sla") void loadSla();
});
</script>

<template>
  <div class="view sd">
    <div class="sd-head">
      <div>
        <h2>Service Desk</h2>
        <p class="sd-sub">Incidents, requests and everything else the desk is asked for.</p>
      </div>
      <button v-if="tab === 'queue'" class="btn btn-primary btn-sm" @click="showNew = true">New ticket</button>
    </div>

    <div class="sd-tabs">
      <button :class="['sd-tabbtn', { on: tab === 'queue' }]" @click="tab = 'queue'">Queue</button>
      <!--
        The count is on the tab because held mail nobody looks at is the same
        as mail that was discarded — which is the outcome this whole mechanism
        exists to avoid.
      -->
      <button :class="['sd-tabbtn', { on: tab === 'held' }]" @click="tab = 'held'">
        Held mail
        <span v-if="held.length" class="sd-badge">{{ held.length }}</span>
      </button>
      <button :class="['sd-tabbtn', { on: tab === 'settings' }]" @click="tab = 'settings'">Mail settings</button>
      <button :class="['sd-tabbtn', { on: tab === 'sla' }]" @click="tab = 'sla'">Service levels</button>
    </div>

    <template v-if="tab === 'queue'">
    <div class="sd-filters">
      <input v-model="query" class="control sd-search" placeholder="Reference, subject, requester"
             @keyup.enter="loadList" />
      <select v-model="stateFilter" class="control sd-select">
        <option value="">All open work</option>
        <option v-for="(label, key) in taxonomy.states" :key="key" :value="key">{{ label }}</option>
      </select>
      <label class="sd-check">
        <input v-model="showAll" type="checkbox" />
        <span>Include closed</span>
      </label>
      <button class="btn btn-secondary btn-sm" :disabled="listBusy" @click="loadList">
        {{ listBusy ? "Loading…" : "Search" }}
      </button>
    </div>

    <p v-if="listError" class="error">{{ listError }}</p>

    <div class="sd-card table-scroll">
      <table class="sd-table">
        <thead>
          <tr>
            <th>Ref</th><th>Subject</th><th>Requester</th>
            <th>Status</th><th>Priority</th><th>SLA</th><th>Assignee</th>
            <th class="text-right">Replies</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in rows" :key="t.id" class="sd-row" @click="openTicket(t)">
            <td class="sd-mono">{{ t.reference }}</td>
            <td>
              <span class="sd-subject">{{ t.subject }}</span>
              <span v-if="t.account" class="sd-meta">{{ t.account.name }}</span>
            </td>
            <td>
              <span>{{ t.requester_name ?? "—" }}</span>
              <span v-if="t.requester_email" class="sd-meta">{{ t.requester_email }}</span>
            </td>
            <td>
              <span class="sd-pill" :style="{ background: (t.status?.colour ?? '#64748b') + '22',
                                              color: t.status?.colour ?? '#64748b' }">
                {{ t.status?.name ?? "—" }}
              </span>
            </td>
            <td>
              <span v-if="t.priority" class="sd-pill"
                    :style="{ background: (t.priority.colour ?? '#64748b') + '22',
                              color: t.priority.colour ?? '#64748b' }">
                {{ t.priority.name }}
              </span>
              <!-- An untriaged ticket says so rather than showing a guessed Medium. -->
              <span v-else class="sd-meta">Untriaged</span>
            </td>
            <!--
              One of four meanings, because a queue scanned at a glance can
              carry four and not ten. Paused reads as neutral on purpose:
              waiting on a supplier is not the desk failing.
            -->
            <td>
              <span class="sd-pill" :class="'sd-clock-' + clockState(t).tone">{{ clockState(t).label }}</span>
            </td>
            <td>{{ t.assignee?.name ?? "Unassigned" }}</td>
            <td class="text-right sd-mono">{{ t.messages_count }}</td>
          </tr>
          <tr v-if="!rows.length && !listBusy">
            <td colspan="7" class="sd-empty">
              Nothing open. Tickets raised here — and, from the next release, arriving by email —
              will appear in this queue.
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    </template>

    <!-- ── Held mail ───────────────────────────────────────────────────────── -->
    <template v-else-if="tab === 'held'">
      <p class="sd-lede">
        Mail from senders nobody recognises. It is held rather than discarded or bounced —
        discarding means a message a customer swears they sent cannot be produced, and bouncing
        tells a spammer the address is live. Releasing it opens a ticket.
      </p>

      <p v-if="heldError" class="error">{{ heldError }}</p>
      <p v-if="heldNote" class="sd-ok">{{ heldNote }}</p>

      <div class="sd-card">
        <ul class="sd-held">
          <li v-for="h in held" :key="h.id" class="sd-held-item">
            <div class="sd-held-main">
              <div class="sd-held-from">
                <strong>{{ h.from_name || h.from_email }}</strong>
                <span class="sd-meta">{{ h.from_email }}</span>
              </div>
              <div class="sd-held-subject">{{ h.subject || "(no subject)" }}</div>
              <p class="sd-held-preview">{{ h.body_preview }}</p>
              <p class="sd-held-why">{{ h.quarantine_reason }}</p>
            </div>
            <!--
              Trusting the sender is offered here rather than on the settings
              tab, because this is the only moment somebody has the context to
              decide it. The old module kept its whitelist on a screen of its
              own and it stayed empty for two years.
            -->
            <div class="sd-held-actions">
              <button class="btn btn-primary btn-sm" :disabled="heldBusy"
                      @click="releaseHeld(h, '')">Release once</button>
              <button class="btn btn-secondary btn-sm" :disabled="heldBusy"
                      @click="releaseHeld(h, 'address')">Release &amp; trust this address</button>
              <button class="btn btn-secondary btn-sm" :disabled="heldBusy || !domainOf(h.from_email)"
                      @click="releaseHeld(h, 'domain')">
                Release &amp; trust @{{ domainOf(h.from_email) || "…" }}
              </button>
            </div>
          </li>
          <li v-if="!held.length && !heldBusy" class="sd-empty">
            Nothing held. Mail from known contacts and allowed domains goes straight to the queue.
          </li>
        </ul>
      </div>
    </template>

    <!-- ── Mail settings ───────────────────────────────────────────────────── -->
    <template v-else>
      <p v-if="cfgError" class="error">{{ cfgError }}</p>
      <p v-if="cfgNote" class="sd-ok">{{ cfgNote }}</p>

      <h3 class="sd-h3">Watched mailboxes</h3>
      <p class="sd-lede">
        Microsoft Graph notifies this system when mail arrives. Subscriptions last about three
        days and are renewed automatically — a mailbox showing as due for renewal has stopped
        being watched, which is a support desk that has quietly gone silent.
      </p>

      <div class="sd-card table-scroll">
        <table class="sd-table">
          <thead>
            <tr><th>Mailbox</th><th>Credentials</th><th>Watching</th><th>Last delivery</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="m in mailboxes" :key="m.id">
              <td>
                <span class="sd-subject">{{ m.address }}</span>
                <span class="sd-meta">{{ m.auto_acknowledge ? "Auto-acknowledges" : "No auto-reply" }}</span>
              </td>
              <td>{{ m.connection ?? "— none linked —" }}</td>
              <td>
                <span v-if="m.subscribed && !m.needs_renewal" class="sd-pill sd-ok-pill">Watching</span>
                <span v-else-if="m.subscribed" class="sd-pill sd-warn-pill">Renewal due</span>
                <span v-else class="sd-pill sd-off-pill">Not subscribed</span>
                <span v-if="m.expires_at" class="sd-meta">until {{ m.expires_at.slice(0, 16) }}</span>
              </td>
              <td>
                <span>{{ m.last_delivered_at?.slice(0, 16) ?? "—" }}</span>
                <span v-if="m.last_error" class="sd-error-note">{{ m.last_error }}</span>
              </td>
              <td>
                <button class="btn btn-secondary btn-sm" :disabled="cfgBusy" @click="subscribe(m)">
                  {{ m.subscribed ? "Re-subscribe" : "Subscribe" }}
                </button>
              </td>
            </tr>
            <tr v-if="!mailboxes.length"><td colspan="5" class="sd-empty">No mailbox is being watched yet.</td></tr>
          </tbody>
        </table>
      </div>

      <div class="sd-card sd-pad">
        <h4 class="sd-h4">Watch a mailbox</h4>
        <div class="sd-grid">
          <label class="sd-field"><span>Address</span>
            <input v-model="mailboxDraft.address" class="control" placeholder="support@kns.co.ke" />
          </label>
          <label class="sd-field"><span>Credentials</span>
            <select v-model="mailboxDraft.integration_connection_id" class="control">
              <option value="">Choose a connection…</option>
              <option v-for="c in connections" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
          </label>
        </div>
        <label class="sd-check">
          <input v-model="mailboxDraft.auto_acknowledge" type="checkbox" />
          <span>Send an automatic acknowledgement with the ticket reference</span>
        </label>
        <p class="sd-note">
          The app registration needs <strong>Mail.Read</strong> and <strong>Mail.Send</strong> as
          application permissions with admin consent, and this system needs a publicly reachable
          address — Graph checks the webhook before it will subscribe.
        </p>
        <div class="sd-right">
          <button class="btn btn-primary btn-sm"
                  :disabled="cfgBusy || !mailboxDraft.address || !mailboxDraft.integration_connection_id"
                  @click="addMailbox">Add mailbox</button>
        </div>
      </div>

      <h3 class="sd-h3">Who may open a ticket</h3>
      <p class="sd-lede">
        Anyone recorded as a contact on a client account is allowed without a rule. These rules
        cover everyone else. Blocking an address beats allowing its domain.
      </p>

      <div class="sd-card table-scroll">
        <table class="sd-table">
          <thead><tr><th>Rule</th><th>Match</th><th>Note</th><th></th></tr></thead>
          <tbody>
            <tr v-for="r in rules" :key="r.id">
              <td>
                <span class="sd-pill" :class="r.action === 'block' ? 'sd-block-pill' : 'sd-ok-pill'">
                  {{ r.action === "block" ? "Block" : "Allow" }}
                </span>
              </td>
              <td class="sd-mono">{{ r.kind === "domain" ? "@" + r.value : r.value }}</td>
              <td class="sd-meta">{{ r.note ?? "" }}</td>
              <td class="text-right">
                <button class="btn btn-ghost btn-sm" style="font-size:.72rem"
                        :disabled="cfgBusy" @click="removeRule(r)">Remove</button>
              </td>
            </tr>
            <tr v-if="!rules.length">
              <td colspan="4" class="sd-empty">
                No rules yet. Known contacts still get through — rules are for everyone else.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="sd-card sd-pad">
        <h4 class="sd-h4">Add a rule</h4>
        <div class="sd-grid">
          <label class="sd-field"><span>Match on</span>
            <select v-model="ruleDraft.kind" class="control">
              <option value="domain">Whole domain</option>
              <option value="address">One address</option>
            </select>
          </label>
          <label class="sd-field"><span>{{ ruleDraft.kind === "domain" ? "Domain" : "Address" }}</span>
            <input v-model="ruleDraft.value" class="control"
                   :placeholder="ruleDraft.kind === 'domain' ? 'client.co.ke' : 'ann@client.co.ke'" />
          </label>
          <label class="sd-field"><span>Then</span>
            <select v-model="ruleDraft.action" class="control">
              <option value="allow">Allow — opens tickets</option>
              <option value="block">Block — recorded, never a ticket</option>
            </select>
          </label>
          <label class="sd-field"><span>Note</span>
            <input v-model="ruleDraft.note" class="control" placeholder="Why, for whoever asks later" />
          </label>
        </div>
        <div class="sd-right">
          <button class="btn btn-primary btn-sm" :disabled="cfgBusy || !ruleDraft.value.trim()"
                  @click="addRule">Add rule</button>
        </div>
      </div>
    </template>

    <!-- ── Service levels ──────────────────────────────────────────────────── -->
    <template v-if="tab === 'sla'">
      <p v-if="slaError" class="error">{{ slaError }}</p>
      <p v-if="slaNote" class="sd-ok">{{ slaNote }}</p>

      <h3 class="sd-h3">Working hours</h3>
      <p class="sd-lede">
        Every target is counted in working minutes against a calendar. Without one, a ticket
        raised at five on Friday burns a four-hour target before anybody is at a desk.
      </p>

      <div class="sd-card sd-pad">
        <label class="sd-field" style="max-width:22rem"><span>Calendar</span>
          <select v-model.number="openCal" class="control">
            <option v-for="c in slaData.calendars" :key="c.id" :value="c.id">
              {{ c.name }}{{ c.is_default ? " · default" : "" }}
            </option>
          </select>
        </label>

        <template v-if="currentCal">
          <ul class="sd-week">
            <li v-for="d in 7" :key="d" class="sd-day">
              <span class="sd-day-name">{{ weekdays[d - 1] }}</span>
              <span v-if="currentCal.windows.find(w => w.weekday === d - 1)" class="sd-day-hours">
                {{ currentCal.windows.find(w => w.weekday === d - 1)!.opens_at }} –
                {{ currentCal.windows.find(w => w.weekday === d - 1)!.closes_at }}
              </span>
              <span v-else class="sd-day-shut">Closed</span>
            </li>
          </ul>

          <h4 class="sd-h4" style="margin-top:14px">Public holidays</h4>
          <!--
            Said plainly, because the omission is deliberate and would otherwise
            look like an oversight the first time Easter passes uncounted.
          -->
          <p class="sd-note">
            Fixed-date Kenyan holidays are filled in for three years. Good Friday, Easter Monday
            and both Eids move — Eid on sighting of the moon — so they are added here by hand. A
            wrong date silently changes every SLA that week.
          </p>

          <ul class="sd-holidays">
            <li v-for="h in currentCal.holidays" :key="h.id">
              <span class="sd-mono">{{ h.observed_on }}</span>
              <span>{{ h.name }}</span>
              <button class="link" @click="removeHoliday(h.id)">Remove</button>
            </li>
          </ul>

          <div class="sd-grid" style="margin-top:10px">
            <label class="sd-field"><span>Date</span>
              <input v-model="holDraft.observed_on" class="control" type="date" />
            </label>
            <label class="sd-field"><span>Name</span>
              <input v-model="holDraft.name" class="control" placeholder="Good Friday" />
            </label>
          </div>
          <div class="sd-right">
            <button class="btn btn-secondary btn-sm"
                    :disabled="slaBusy || !holDraft.observed_on || !holDraft.name"
                    @click="addHoliday">Add holiday</button>
          </div>
        </template>
      </div>

      <h3 class="sd-h3">What we promise</h3>
      <p class="sd-lede">
        Targets hang off a contract. The one marked default covers tickets from accounts with no
        contract, so every ticket has a target rather than no answer at all.
      </p>

      <div class="sd-card sd-pad">
        <label class="sd-field" style="max-width:26rem"><span>Service level</span>
          <select v-model.number="openSla" class="control">
            <option v-for="s in slaData.slas" :key="s.id" :value="s.id">
              {{ s.name }}{{ s.contract ? ` · ${s.contract}` : (s.is_default ? " · default" : "") }}
            </option>
          </select>
        </label>

        <div v-if="currentSla" class="table-scroll">
          <table class="sd-table">
            <thead>
              <tr>
                <th>Priority</th><th class="text-right">Respond</th><th class="text-right">Resolve</th>
                <th class="text-right">HOD</th><th class="text-right">CTO</th><th class="text-right">CEO</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in currentSla.targets" :key="t.id">
                <td>{{ t.priority }}</td>
                <td class="text-right sd-mono">{{ hm(t.response_minutes) }}</td>
                <td class="text-right sd-mono">{{ hm(t.resolution_minutes) }}</td>
                <td class="text-right sd-mono">{{ t.hod_escalation_minutes ? hm(t.hod_escalation_minutes) : "—" }}</td>
                <td class="text-right sd-mono">{{ t.cto_escalation_minutes ? hm(t.cto_escalation_minutes) : "—" }}</td>
                <td class="text-right sd-mono">{{ t.ceo_escalation_minutes ? hm(t.ceo_escalation_minutes) : "—" }}</td>
              </tr>
              <tr v-if="!currentSla.targets.length">
                <td colspan="6" class="sd-empty">
                  No targets yet. Until a priority has one, tickets at that priority have no clock.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <h4 class="sd-h4" style="margin-top:14px">Set a target</h4>
        <div class="sd-grid">
          <label class="sd-field"><span>Priority</span>
            <select v-model="targetDraft.priority_id" class="control">
              <option value="">Choose…</option>
              <option v-for="p in slaData.priorities" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
            </select>
          </label>
          <label class="sd-field"><span>Respond within (working minutes)</span>
            <input v-model="targetDraft.response_minutes" class="control" type="number" min="1" placeholder="240" />
          </label>
          <label class="sd-field"><span>Resolve within (working minutes)</span>
            <input v-model="targetDraft.resolution_minutes" class="control" type="number" min="1" placeholder="540" />
          </label>
          <label class="sd-field"><span>Escalate to HOD after</span>
            <input v-model="targetDraft.hod_escalation_minutes" class="control" type="number" min="1" placeholder="300" />
          </label>
          <label class="sd-field"><span>Then to CTO after</span>
            <input v-model="targetDraft.cto_escalation_minutes" class="control" type="number" min="1" placeholder="480" />
          </label>
          <label class="sd-field"><span>Then to CEO after</span>
            <input v-model="targetDraft.ceo_escalation_minutes" class="control" type="number" min="1" placeholder="600" />
          </label>
        </div>
        <p class="sd-note">
          Working minutes, not clock minutes: 540 is one full day on an eight-to-five calendar.
          Changing a target leaves tickets already answered judged by the target they were
          answered under.
        </p>
        <div class="sd-right">
          <button class="btn btn-primary btn-sm"
                  :disabled="slaBusy || !targetDraft.priority_id
                             || !Number(targetDraft.response_minutes) || !Number(targetDraft.resolution_minutes)"
                  @click="saveTarget">Save target</button>
        </div>
      </div>

      <div class="sd-card sd-pad">
        <h4 class="sd-h4">Add a service level</h4>
        <div class="sd-grid">
          <label class="sd-field"><span>Name</span>
            <input v-model="slaDraft.name" class="control" placeholder="Gold — 24x7" />
          </label>
          <label class="sd-field"><span>Contract</span>
            <select v-model="slaDraft.account_contract_id" class="control">
              <option value="">None — organisation fallback</option>
              <option v-for="c in slaData.contracts" :key="c.id" :value="String(c.id)">
                {{ c.account ? `${c.account} · ` : "" }}{{ c.name }}
              </option>
            </select>
          </label>
          <label class="sd-field"><span>Calendar</span>
            <select v-model="slaDraft.support_calendar_id" class="control">
              <option value="">Use the default calendar</option>
              <option v-for="c in slaData.calendars" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
          </label>
          <label class="sd-check" style="align-self:end">
            <input v-model="slaDraft.is_default" type="checkbox" />
            <span>Use for tickets with no contract</span>
          </label>
        </div>
        <div class="sd-right">
          <button class="btn btn-primary btn-sm" :disabled="slaBusy || !slaDraft.name.trim()"
                  @click="addSla">Add service level</button>
        </div>
      </div>
    </template>

    <!-- ── Ticket ──────────────────────────────────────────────────────────── -->
    <div v-if="open" class="sd-overlay" @click.self="open = null">
      <div class="sd-panel" role="dialog" aria-modal="true">
        <div class="sd-panel-head">
          <div>
            <span class="sd-mono sd-ref">{{ open.reference }}</span>
            <h3>{{ open.subject }}</h3>
            <p class="sd-meta">
              {{ open.requester_name ?? "Unknown requester" }}
              <template v-if="open.requester_email"> · {{ open.requester_email }}</template>
              <template v-if="open.account"> · {{ open.account.name }}</template>
              · opened {{ when(open.opened_at) }}
            </p>
          </div>
          <button class="sd-close" aria-label="Close" @click="open = null">✕</button>
        </div>

        <p v-if="openError" class="error">{{ openError }}</p>

        <div class="sd-panel-body">
          <!-- ── Conversation ──────────────────────────────────────────────── -->
          <div class="sd-thread">
            <div v-for="m in open.messages" :key="m.id"
                 :class="['sd-msg', m.visibility === 'internal' ? 'internal' : (m.is_from_agent ? 'agent' : 'customer')]">
              <div class="sd-msg-head">
                <strong>{{ m.author?.name ?? m.author_name ?? "Unknown" }}</strong>
                <!--
                  The private channel is marked hard. An agent about to paste a
                  supplier's price into a note needs to see, without reading a
                  legend, that the customer will not get it.
                -->
                <span v-if="m.visibility === 'internal'" class="sd-tag internal">Internal — not sent</span>
                <span v-else-if="m.is_from_agent" class="sd-tag">Reply to customer</span>
                <span v-else class="sd-tag">From customer</span>
                <span class="sd-when">{{ when(m.created_at) }}</span>
              </div>
              <div class="sd-msg-body">{{ m.body }}</div>
            </div>
            <p v-if="!open.messages.length" class="sd-meta">Nothing on this ticket yet.</p>
          </div>

          <!-- ── Reply ─────────────────────────────────────────────────────── -->
          <div :class="['sd-compose', { internal: reply.visibility === 'internal' }]">
            <div class="sd-compose-tabs">
              <button :class="['sd-tab', { on: reply.visibility === 'public' }]"
                      @click="reply.visibility = 'public'">Reply to customer</button>
              <button :class="['sd-tab', { on: reply.visibility === 'internal' }]"
                      @click="reply.visibility = 'internal'">Internal note</button>
            </div>
            <textarea v-model="reply.body" class="control" rows="4"
                      :placeholder="reply.visibility === 'internal'
                        ? 'Only the desk sees this. Working notes, supplier prices, what you have ruled out.'
                        : 'This goes to the customer.'"></textarea>
            <div class="sd-compose-foot">
              <span class="sd-meta">
                <template v-if="reply.visibility === 'internal'">The customer never sees internal notes.</template>
                <template v-else-if="!open.first_response_at">This will be recorded as the first response.</template>
                <template v-else>First response {{ when(open.first_response_at) }}.</template>
              </span>
              <button class="btn btn-primary btn-sm" :disabled="replyBusy || !reply.body.trim()"
                      @click="sendReply">
                {{ replyBusy ? "Saving…" : (reply.visibility === "internal" ? "Add note" : "Send reply") }}
              </button>
            </div>
          </div>
        </div>

        <!-- ── Side ──────────────────────────────────────────────────────────── -->
        <aside class="sd-side">
          <label class="sd-field"><span>Status</span>
            <select :value="open.status?.id" class="control"
                    @change="setStatus(Number(($event.target as HTMLSelectElement).value))">
              <option v-for="s in taxonomy.statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </label>
          <!--
            Which statuses stop the clock, said on the screen. It is the thing
            the SLA engine reads next release, and an agent parking a ticket
            should know whether they have stopped the clock or not.
          -->
          <p class="sd-note" v-if="pendingStates.length">
            Waiting statuses — {{ pendingStates.map(s => s.name).join(", ") }} — mean the ticket is
            with someone else.
          </p>

          <label class="sd-field"><span>Type</span>
            <select :value="open.type?.id ?? ''" class="control"
                    @change="classify('ticket_type_id', ($event.target as HTMLSelectElement).value)">
              <option value="">Unclassified</option>
              <option v-for="t in taxonomy.types" :key="t.id" :value="t.id">{{ t.name }}</option>
            </select>
          </label>

          <label class="sd-field"><span>Impact</span>
            <select :value="open.impact?.id ?? ''" class="control"
                    @change="classify('impact_id', ($event.target as HTMLSelectElement).value)">
              <option value="">Not set</option>
              <option v-for="i in taxonomy.impacts" :key="i.id" :value="i.id">{{ i.name }}</option>
            </select>
          </label>

          <label class="sd-field"><span>Urgency</span>
            <select :value="open.urgency?.id ?? ''" class="control"
                    @change="classify('urgency_id', ($event.target as HTMLSelectElement).value)">
              <option value="">Not set</option>
              <option v-for="u in taxonomy.urgencies" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </label>

          <!-- Read-only: a lookup, not an opinion. -->
          <div class="sd-field">
            <span>Priority</span>
            <div class="sd-derived">
              <span v-if="open.priority" class="sd-pill"
                    :style="{ background: (open.priority.colour ?? '#64748b') + '22',
                              color: open.priority.colour ?? '#64748b' }">
                {{ open.priority.name }}
              </span>
              <span v-else class="sd-meta">Untriaged</span>
              <span class="sd-note">{{ priorityHint }}</span>
            </div>
          </div>

          <!--
            Counted minutes beside raw elapsed. The gap between the two is the
            most-disputed number in any service review, so it is shown rather
            than left to be worked out.
          -->
          <div v-if="open.sla" class="sd-field">
            <span>Service level</span>
            <div class="sd-sla">
              <span class="sd-pill" :class="'sd-clock-' + clockState(open).tone">
                {{ clockState(open).label }}
              </span>
              <span class="sd-note">
                {{ hm(slaUsed) }} counted of {{ hm(elapsed) }} elapsed
                <template v-if="open.sla.paused_minutes"> · {{ hm(open.sla.paused_minutes) }} paused</template>
              </span>
              <span v-if="open.sla.escalation_level" class="sd-note sd-esc">
                Escalated to {{ open.sla.escalation_level.toUpperCase() }}
              </span>
            </div>
          </div>
          <div v-else class="sd-field">
            <span>Service level</span>
            <span class="sd-note">
              No target — set impact and urgency to start the clock.
            </span>
          </div>

          <div class="sd-field">
            <span>Time logged</span>
            <div class="sd-derived">
              <strong>{{ Math.floor(minutesLogged / 60) }}h {{ minutesLogged % 60 }}m</strong>
              <button class="link" @click="showTime = !showTime">{{ showTime ? "Cancel" : "Log time" }}</button>
            </div>
          </div>

          <div v-if="showTime" class="sd-time">
            <input v-model="timeDraft.minutes" class="control" type="number" min="1" placeholder="Minutes" />
            <input v-model="timeDraft.note" class="control" placeholder="What you did" />
            <label class="sd-check">
              <input v-model="timeDraft.under_contract" type="checkbox" />
              <span>Under contract</span>
            </label>
            <button class="btn btn-primary btn-sm" :disabled="!Number(timeDraft.minutes)" @click="logTime">Save</button>
          </div>

          <ul v-if="open.worklogs.length" class="sd-worklogs">
            <li v-for="w in open.worklogs" :key="w.id">
              <strong>{{ w.minutes }}m</strong> — {{ w.user?.name ?? "—" }}
              <span class="sd-meta">{{ w.performed_on }}<template v-if="!w.under_contract"> · outside contract</template></span>
            </li>
          </ul>
        </aside>
      </div>
    </div>

    <!-- ── New ticket ──────────────────────────────────────────────────────── -->
    <div v-if="showNew" class="sd-overlay" @click.self="showNew = false">
      <div class="sd-dialog" role="dialog" aria-modal="true">
        <h3>New ticket</h3>
        <p v-if="newError" class="error">{{ newError }}</p>

        <label class="sd-field"><span>Subject</span>
          <input v-model="draft.subject" class="control" placeholder="Link down at the branch" />
        </label>
        <label class="sd-field"><span>What was reported</span>
          <textarea v-model="draft.body" class="control" rows="4"></textarea>
        </label>

        <div class="sd-grid">
          <label class="sd-field"><span>Requester</span>
            <input v-model="draft.requester_name" class="control" />
          </label>
          <label class="sd-field"><span>Email</span>
            <input v-model="draft.requester_email" class="control" type="email" />
          </label>
          <label class="sd-field"><span>Type</span>
            <select v-model="draft.ticket_type_id" class="control">
              <option value="">Unclassified</option>
              <option v-for="t in taxonomy.types" :key="t.id" :value="String(t.id)">{{ t.name }}</option>
            </select>
          </label>
          <label class="sd-field"><span>How it came in</span>
            <select v-model="draft.source" class="control">
              <option value="phone">Phone</option>
              <option value="agent">Raised by us</option>
              <option value="email">Email</option>
              <option value="portal">Portal</option>
            </select>
          </label>
          <label class="sd-field"><span>Impact</span>
            <select v-model="draft.impact_id" class="control">
              <option value="">Not set</option>
              <option v-for="i in taxonomy.impacts" :key="i.id" :value="String(i.id)">{{ i.name }}</option>
            </select>
          </label>
          <label class="sd-field"><span>Urgency</span>
            <select v-model="draft.urgency_id" class="control">
              <option value="">Not set</option>
              <option v-for="u in taxonomy.urgencies" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
            </select>
          </label>
        </div>

        <p class="sd-note">
          Priority is worked out from impact and urgency. Leave them unset and the ticket stays
          untriaged rather than being given a middling priority nobody chose.
        </p>

        <div class="sd-dialog-actions">
          <button class="btn btn-secondary btn-sm" @click="showNew = false">Cancel</button>
          <button class="btn btn-primary btn-sm" :disabled="newBusy || !draft.subject.trim()"
                  @click="createTicket">
            {{ newBusy ? "Opening…" : "Open ticket" }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../theme.css";

.sd-head { @apply flex flex-wrap items-start justify-between gap-3; margin-bottom: 14px; }
.sd-head h2 { @apply font-semibold; margin: 0; color: var(--color-text); }
.sd-sub { font-size: .85rem; color: var(--color-text-light); margin: 2px 0 0; }

.sd-filters { @apply flex flex-wrap items-center gap-2; margin-bottom: 14px; }
.sd-search { max-width: 22rem; }
.sd-select { max-width: 14rem; }
.sd-check { @apply flex items-center gap-2; font-size: .85rem; cursor: pointer; }

.sd-card { background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: var(--radius-xl); overflow: hidden; }

.sd-table { @apply w-full; border-collapse: collapse; font-size: .86rem; }
.sd-table th, .sd-table td { text-align: left; padding: 9px 12px; border-bottom: 1px solid var(--color-border); }
.sd-table th { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em;
  color: var(--color-text-muted); background: var(--color-surface-sunken); }
.sd-table tbody tr:last-child td { border-bottom: none; }
.sd-row { cursor: pointer; }
.sd-row:hover { background: var(--color-surface-sunken); }
.sd-subject { display: block; color: var(--color-text); }
.sd-meta { display: block; font-size: .74rem; color: var(--color-text-light); }
.sd-mono { font-family: ui-monospace, monospace; font-size: .78rem; }
.sd-empty { text-align: center; color: var(--color-text-light); padding: 26px 12px; }

.sd-pill { display: inline-block; font-size: .7rem; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }

.sd-overlay { @apply fixed inset-0 z-50 flex items-center justify-center; background: rgb(0 0 0 / 45%); padding: 20px; }

.sd-panel { background: var(--color-surface); border-radius: var(--radius-xl);
  width: min(64rem, 100%); max-height: 90vh; overflow-y: auto;
  display: grid; grid-template-columns: minmax(0, 1fr) 17rem; grid-template-areas: "head head" "body side"; }
@media (max-width: 900px) { .sd-panel { grid-template-columns: 1fr; grid-template-areas: "head" "side" "body"; } }

.sd-panel-head { grid-area: head; @apply flex items-start justify-between gap-3;
  padding: 18px 20px 12px; border-bottom: 1px solid var(--color-border); }
.sd-panel-head h3 { @apply font-semibold; margin: 3px 0 4px; color: var(--color-text); }
.sd-ref { color: var(--color-text-light); }
.sd-close { background: none; border: none; cursor: pointer; font-size: 1rem; color: var(--color-text-light); }

.sd-panel-body { grid-area: body; padding: 16px 20px 20px; display: flex; flex-direction: column; gap: 14px; }

.sd-thread { display: flex; flex-direction: column; gap: 10px; }
.sd-msg { border: 1px solid var(--color-border); border-radius: 10px; padding: 10px 12px; }
.sd-msg.customer { background: var(--color-surface-sunken); }
.sd-msg.agent { background: var(--color-brand-subtle); }
/* Internal is visually unmistakable — an agent must never mistake it for a reply. */
.sd-msg.internal { background: repeating-linear-gradient(135deg,
    var(--color-warning-light), var(--color-warning-light) 10px,
    transparent 10px, transparent 20px);
  border-color: var(--color-warning); }
.sd-msg-head { @apply flex flex-wrap items-center gap-2; font-size: .78rem; margin-bottom: 5px; }
.sd-msg-body { font-size: .88rem; white-space: pre-wrap; color: var(--color-text); }
.sd-tag { font-size: .68rem; padding: 1px 6px; border-radius: 999px;
  background: var(--color-surface); color: var(--color-text-light); }
.sd-tag.internal { background: var(--color-warning); color: #fff; }
.sd-when { margin-left: auto; font-size: .72rem; color: var(--color-text-light); }

.sd-compose { border: 1px solid var(--color-border); border-radius: 10px; overflow: hidden; }
.sd-compose.internal { border-color: var(--color-warning); }
.sd-compose-tabs { display: flex; border-bottom: 1px solid var(--color-border); }
.sd-tab { flex: 1; padding: 8px; font-size: .8rem; background: var(--color-surface-sunken);
  border: none; cursor: pointer; color: var(--color-text-light); }
.sd-tab.on { background: var(--color-surface); color: var(--color-text); font-weight: 600; }
.sd-compose textarea { border: none; border-radius: 0; }
.sd-compose-foot { @apply flex flex-wrap items-center justify-between gap-2;
  padding: 8px 10px; border-top: 1px solid var(--color-border); }

.sd-side { grid-area: side; border-left: 1px solid var(--color-border);
  padding: 16px 18px 20px; display: flex; flex-direction: column; gap: 12px; }
@media (max-width: 900px) { .sd-side { border-left: none; border-bottom: 1px solid var(--color-border); } }

.sd-field { @apply flex flex-col; gap: 5px; }
.sd-field > span { @apply font-semibold; font-size: .74rem; color: var(--color-text); }
.sd-derived { @apply flex flex-wrap items-center gap-2; }
.sd-note { font-size: .74rem; color: var(--color-text-light); margin: 0; }

.sd-time { display: flex; flex-direction: column; gap: 6px;
  padding: 10px; border-radius: 8px; background: var(--color-surface-sunken); }
.sd-worklogs { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 5px;
  font-size: .78rem; max-height: 10rem; overflow-y: auto; }

.sd-dialog { background: var(--color-surface); border-radius: var(--radius-xl); padding: 22px;
  width: min(40rem, 100%); max-height: 90vh; overflow-y: auto;
  display: flex; flex-direction: column; gap: 12px; }
.sd-dialog h3 { @apply font-semibold; margin: 0; color: var(--color-text); }
.sd-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
@media (max-width: 560px) { .sd-grid { grid-template-columns: 1fr; } }
.sd-dialog-actions { @apply flex justify-end gap-2; margin-top: 4px; }

.control { @apply w-full; font-family: inherit; font-size: .88rem; padding: 8px 10px;
  border: 1px solid var(--color-border-strong); border-radius: 8px;
  background: var(--color-surface); color: var(--color-text); }
.error { font-size: .85rem; color: var(--color-error); background: var(--color-error-light);
  border-radius: 8px; padding: 9px 12px; margin: 0 20px; }
.table-scroll { overflow-x: auto; }

.sd-tabs { display: flex; gap: 4px; margin-bottom: 14px; border-bottom: 1px solid var(--color-border); }
.sd-tabbtn { background: none; border: none; cursor: pointer; padding: 8px 14px;
  font-size: .88rem; color: var(--color-text-light); border-bottom: 2px solid transparent; }
.sd-tabbtn.on { color: var(--color-text); font-weight: 600; border-bottom-color: var(--color-brand-base); }
.sd-badge { display: inline-block; margin-left: 6px; font-size: .68rem; padding: 1px 6px;
  border-radius: 999px; background: var(--color-warning); color: #fff; }

.sd-lede { font-size: .85rem; color: var(--color-text-light); margin: 0 0 12px; max-width: 46rem; }
.sd-h3 { @apply font-semibold; margin: 18px 0 4px; color: var(--color-text); }
.sd-h4 { @apply font-semibold; margin: 0 0 10px; font-size: .9rem; color: var(--color-text); }
.sd-pad { padding: 16px; margin-top: 12px; display: flex; flex-direction: column; gap: 10px; }
.sd-right { display: flex; justify-content: flex-end; }
.sd-ok { font-size: .85rem; padding: 9px 12px; border-radius: 8px;
  background: var(--color-success-light); color: var(--color-success); margin: 0 0 10px; }

.sd-held { list-style: none; margin: 0; padding: 0; }
.sd-held-item { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between;
  padding: 14px 16px; border-bottom: 1px solid var(--color-border); }
.sd-held-item:last-child { border-bottom: none; }
.sd-held-main { flex: 1 1 22rem; min-width: 0; }
.sd-held-from { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px; }
.sd-held-subject { font-size: .9rem; color: var(--color-text); margin-top: 2px; }
.sd-held-preview { font-size: .8rem; color: var(--color-text-light); margin: 4px 0 0;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.sd-held-why { font-size: .76rem; color: var(--color-warning); margin: 6px 0 0; }
.sd-held-actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: flex-start; }

.sd-ok-pill { background: var(--color-success-light); color: var(--color-success); }
.sd-warn-pill { background: var(--color-warning-light); color: #7C2D12; }
.sd-off-pill { background: var(--color-surface-sunken); color: var(--color-text-light); }
.sd-block-pill { background: var(--color-error-light); color: var(--color-error); }
.sd-error-note { display: block; font-size: .72rem; color: var(--color-error); }

.sd-clock-ok { background: var(--color-success-light); color: var(--color-success); }
.sd-clock-warn { background: var(--color-warning-light); color: #7C2D12; }
.sd-clock-bad { background: var(--color-error-light); color: var(--color-error); }
.sd-clock-mute { background: var(--color-surface-sunken); color: var(--color-text-light); }

.sd-sla { display: flex; flex-direction: column; gap: 4px; align-items: flex-start; }
.sd-esc { color: var(--color-error); }

.sd-week { list-style: none; margin: 10px 0 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
.sd-day { display: flex; gap: 12px; font-size: .85rem; padding: 3px 0; }
.sd-day-name { width: 7rem; color: var(--color-text-light); }
.sd-day-hours { font-family: ui-monospace, monospace; }
.sd-day-shut { color: var(--color-text-muted); }

.sd-holidays { list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-direction: column; gap: 3px;
  font-size: .82rem; max-height: 14rem; overflow-y: auto; }
.sd-holidays li { display: flex; gap: 10px; align-items: baseline; }
.sd-holidays li .sd-mono { width: 6.5rem; }
</style>
