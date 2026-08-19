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

interface TicketRow {
  id: number; reference: string; subject: string;
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
    const res = await api<{ data: TicketDetail; minutes_logged: number }>(
      "GET", `/api/v1/support/tickets/${row.id}`, { org: org() },
    );
    open.value = res.data;
    minutesLogged.value = res.minutes_logged;
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
}

onMounted(init);
watch(currentOrgSlug, init);
watch([stateFilter, showAll], loadList);
</script>

<template>
  <div class="view sd">
    <div class="sd-head">
      <div>
        <h2>Service Desk</h2>
        <p class="sd-sub">Incidents, requests and everything else the desk is asked for.</p>
      </div>
      <button class="btn btn-primary btn-sm" @click="showNew = true">New ticket</button>
    </div>

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
            <th>Status</th><th>Priority</th><th>Assignee</th>
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
</style>
