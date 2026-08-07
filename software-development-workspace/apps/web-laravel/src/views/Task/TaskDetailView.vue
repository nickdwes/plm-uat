<script setup lang="ts">
/**
 * One task.
 *
 * Three panels below the detail, each for something the legacy did constantly
 * and never modelled:
 *
 *   Who is helping — its "helping" table ran to 2,566 rows and was accepted 98%
 *   of the time, because it was a request rather than an assignment.
 *
 *   What has been asked and not answered — 74% of its delegations sat Pending
 *   forever, with nothing anywhere showing them.
 *
 *   What the task cannot close without — 344 required documents, 247 supplied.
 *   The 97 that never arrived are the reason the list has to show both.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import EmployeePicker from "../../components/EmployeePicker.vue";

const props = defineProps<{ id: string }>();

interface Person { id: number | null; name: string | null }
interface Ask {
  id: number; kind: string; status: string; is_stale: boolean;
  request_comment: string | null; response_comment: string | null;
  responded_at: string | null;
  to: Person | null; requester: Person | null;
}
interface Requirement {
  id: number; name: string; is_fulfilled: boolean;
  fulfilled_at: string | null; original_name: string | null; has_file: boolean;
}
interface Task {
  id: number; reference: string | null; title: string; description: string | null;
  category: string; status: string; priority: string;
  deadline: string | null; started_on: string | null; completed_at: string | null;
  is_done: boolean; is_overdue: boolean; is_blocked: boolean;
  effort_estimate_days: number | string | null;
  elapsed_days: number | null;
  effort_variance: number | string | null;
  requires_approval: boolean; approved_at: string | null;
  assignee: Person | null;
  project: { id: number; name: string } | null;
  assisting: { id: number; employee: Person }[];
  requests: Ask[];
  required_documents: Requirement[];
  subtasks: Task[];
}

const router  = useRouter();
const task    = ref<Task | null>(null);
const loading = ref(true);
const saving  = ref(false);

const org = () => currentOrgSlug.value;

function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

async function load() {
  loading.value = true;
  try {
    task.value = (await api<{ data: Task }>("GET", `/api/v1/tasks/${props.id}`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load the task."); }
  finally { loading.value = false; }
}

// ── Editing ─────────────────────────────────────────────────────────────────

const editing = ref(false);
const form = ref({
  title: "", description: "", category: "internal", status: "todo", priority: "medium",
  deadline: "", started_on: "", effort_estimate_days: "" as string | number,
  assigned_to: null as number | null, requires_approval: false,
});

function beginEdit() {
  const t = task.value!;
  form.value = {
    title: t.title, description: t.description ?? "",
    category: t.category, status: t.status, priority: t.priority,
    deadline: t.deadline ?? "", started_on: t.started_on ?? "",
    effort_estimate_days: t.effort_estimate_days ?? "",
    assigned_to: t.assignee?.id ?? null,
    requires_approval: t.requires_approval,
  };
  editing.value = true;
}

async function save() {
  saving.value = true;
  try {
    task.value = (await api<{ data: Task }>("PUT", `/api/v1/tasks/${props.id}`, {
      org: org(),
      body: {
        ...form.value,
        deadline: form.value.deadline || null,
        started_on: form.value.started_on || null,
        effort_estimate_days: form.value.effort_estimate_days === "" ? null : form.value.effort_estimate_days,
      },
    })).data;
    editing.value = false;
    notifySaved("Task updated.");
  } catch (e) { fail(e, "Could not save the task."); }
  finally { saving.value = false; }
}

/** The one-click move people actually make, without opening the form. */
async function markDone() {
  saving.value = true;
  try {
    task.value = (await api<{ data: Task }>("PUT", `/api/v1/tasks/${props.id}`, {
      org: org(), body: { title: task.value!.title, status: "completed" },
    })).data;
    notifySaved("Marked done.");
  } catch (e) { fail(e, "Could not close the task."); }
  finally { saving.value = false; }
}

// ── Asking somebody ─────────────────────────────────────────────────────────

const showAsk = ref(false);
const ask = ref({ kind: "assist", to_employee_id: null as number | null, request_comment: "" });

async function sendAsk() {
  if (!ask.value.to_employee_id) return;
  saving.value = true;
  try {
    task.value = (await api<{ data: Task }>("POST", `/api/v1/tasks/${props.id}/requests`, {
      org: org(), body: ask.value,
    })).data;
    showAsk.value = false;
    ask.value = { kind: "assist", to_employee_id: null, request_comment: "" };
    notifySaved("Asked. It is now in their requests.");
  } catch (e) { fail(e, "Could not send the request."); }
  finally { saving.value = false; }
}

// ── Required documents ──────────────────────────────────────────────────────

const newRequirement = ref("");

async function addRequirement() {
  if (!newRequirement.value.trim()) return;
  try {
    task.value = (await api<{ data: Task }>("POST", `/api/v1/tasks/${props.id}/required-documents`, {
      org: org(), body: { name: newRequirement.value.trim() },
    })).data;
    newRequirement.value = "";
  } catch (e) { fail(e, "Could not add the requirement."); }
}

async function fulfil(requirement: Requirement, event: Event) {
  const input = event.target as HTMLInputElement;
  const file  = input.files?.[0];
  if (!file) return;

  const body = new FormData();
  body.append("file", file);

  try {
    task.value = (await api<{ data: Task }>(
      "POST", `/api/v1/tasks/${props.id}/required-documents/${requirement.id}/fulfil`,
      { org: org(), formData: body },
    )).data;
    notifySaved(`${requirement.name} supplied.`);
  } catch (e) { fail(e, "Could not upload the document."); }
  finally { input.value = ""; }
}

// ── Presentation ────────────────────────────────────────────────────────────

const CATEGORIES = [
  "opportunity", "project", "support", "meeting", "training", "certificate",
  "research", "collaboration", "internal", "ticket", "account", "leave",
];
const STATUSES   = ["todo", "in_progress", "awaiting_approval", "completed", "blocked"];
const PRIORITIES = ["low", "medium", "high"];
const KINDS = [
  { key: "assist",      label: "Assist",      hint: "They help. You stay accountable." },
  { key: "delegate",    label: "Hand over",   hint: "If they accept, the task becomes theirs." },
  { key: "collaborate", label: "Collaborate", hint: "Shared work, ownership unchanged." },
];

const statusTone = (s: string) =>
  s === "completed" ? "ok" : s === "blocked" ? "bad" : s === "in_progress" ? "info"
    : s === "awaiting_approval" ? "warn" : "neutral";

const askTone = (s: string) => s === "accepted" ? "ok" : s === "declined" ? "bad" : "warn";

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

const titleCase = (v: string) => v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());

const initials = (name: string | null | undefined) =>
  (name ?? "?").split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

/**
 * Over or under the estimate. Absent where there is no estimate — nobody
 * having said what the plan was is not the same as coming in exactly on it.
 */
const variance = computed(() => {
  const t = task.value;
  if (!t || t.effort_variance === null || t.effort_variance === undefined) return null;
  const v = Number(t.effort_variance);
  if (v === 0) return { text: "On estimate", tone: "ok" };
  return v > 0
    ? { text: `${v.toFixed(1)} days over`, tone: "bad" }
    : { text: `${Math.abs(v).toFixed(1)} days under`, tone: "ok" };
});

/** What is still outstanding on the checklist, which is the part that bites. */
const outstanding = computed(() =>
  (task.value?.required_documents ?? []).filter((d) => !d.is_fulfilled),
);

const waiting = computed(() => (task.value?.requests ?? []).filter((r) => r.status === "pending"));

onMounted(load);
</script>

<template>
  <div class="td-wrap">
    <div v-if="loading" class="td-loading">Loading…</div>

    <template v-else-if="task">
      <button class="link-btn td-back" @click="router.push({ name: 'tasks' })">← All tasks</button>

      <header class="td-header">
        <div>
          <p class="td-ref">{{ task.reference ?? "—" }} · {{ titleCase(task.category) }}</p>
          <h1 class="td-title">{{ task.title }}</h1>
          <p class="td-sub">
            <span :class="['pill', `pill-${statusTone(task.status)}`]">{{ titleCase(task.status) }}</span>
            <span v-if="task.is_overdue" class="pill pill-bad">Past its deadline</span>
            <button v-if="task.project" class="link-btn"
                    @click="router.push({ name: 'project', params: { id: task.project.id } })">
              {{ task.project.name }}
            </button>
          </p>
        </div>
        <div class="td-actions">
          <button v-if="!task.is_done" class="btn-ghost" :disabled="saving" @click="markDone">Mark done</button>
          <button class="btn-ghost" @click="showAsk = true">Ask someone</button>
          <button class="btn-primary" @click="beginEdit">Edit</button>
        </div>
      </header>

      <!-- ── The facts ─────────────────────────────────────────────────── -->
      <section class="td-card">
        <dl class="td-facts">
          <div><dt>Owner</dt>
            <dd v-if="task.assignee?.name" class="td-person">
              <span class="td-avatar">{{ initials(task.assignee.name) }}</span>{{ task.assignee.name }}
            </dd>
            <dd v-else class="td-unowned">Unassigned</dd>
          </div>
          <div><dt>Priority</dt><dd>{{ titleCase(task.priority) }}</dd></div>
          <div><dt>Started</dt><dd>{{ date(task.started_on) }}</dd></div>
          <div><dt>Deadline</dt><dd :class="{ 'td-late': task.is_overdue }">{{ date(task.deadline) }}</dd></div>
          <div><dt>Estimated</dt>
            <dd>{{ task.effort_estimate_days ? `${Number(task.effort_estimate_days).toFixed(1)} days` : "—" }}</dd>
          </div>
          <div><dt>Actual</dt><dd>{{ task.elapsed_days !== null ? `${task.elapsed_days} days` : "—" }}</dd></div>
          <!-- The comparison the legacy had the data for on nine tasks in ten
               and never once made. -->
          <div><dt>Against estimate</dt>
            <dd><span v-if="variance" :class="['pill', `pill-${variance.tone}`]">{{ variance.text }}</span>
              <span v-else class="td-muted">Not estimated</span></dd>
          </div>
          <div v-if="task.requires_approval"><dt>Approval</dt>
            <dd>{{ task.approved_at ? `Approved ${date(task.approved_at)}` : "Awaiting sign-off" }}</dd>
          </div>
        </dl>

        <p v-if="task.description" class="td-description">{{ task.description }}</p>
      </section>

      <div class="td-grid">
        <!-- ── Who is on it ────────────────────────────────────────────── -->
        <section class="td-card">
          <h2 class="td-card-title">Who is on it</h2>
          <p v-if="!task.assisting.length" class="td-muted td-quiet">
            Nobody assisting. Ask someone if the work needs more hands.
          </p>
          <ul v-else class="td-list">
            <li v-for="m in task.assisting" :key="m.id" class="td-person">
              <span class="td-avatar">{{ initials(m.employee.name) }}</span>{{ m.employee.name }}
              <span class="td-tag">assisting</span>
            </li>
          </ul>
        </section>

        <!-- ── Requests ────────────────────────────────────────────────── -->
        <section class="td-card">
          <h2 class="td-card-title">
            Requests
            <span v-if="waiting.length" class="pill pill-warn">{{ waiting.length }} unanswered</span>
          </h2>
          <p v-if="!task.requests.length" class="td-muted td-quiet">Nothing asked of anyone.</p>
          <ul v-else class="td-list">
            <li v-for="r in task.requests" :key="r.id" class="td-ask">
              <div class="td-ask-head">
                <span class="td-tag">{{ titleCase(r.kind) }}</span>
                <span :class="['pill', `pill-${askTone(r.status)}`]">{{ titleCase(r.status) }}</span>
                <!-- Asked and never answered for over a week. The legacy left
                     261 like this with nothing surfacing them. -->
                <span v-if="r.is_stale" class="pill pill-bad">Stale</span>
              </div>
              <p class="td-ask-meta">{{ r.to?.name }} — asked by {{ r.requester?.name ?? "someone" }}</p>
              <p v-if="r.request_comment" class="td-ask-note">“{{ r.request_comment }}”</p>
              <p v-if="r.response_comment" class="td-ask-note td-reply">“{{ r.response_comment }}”</p>
            </li>
          </ul>
        </section>

        <!-- ── Required documents ──────────────────────────────────────── -->
        <section class="td-card">
          <h2 class="td-card-title">
            Cannot close without
            <span v-if="outstanding.length" class="pill pill-warn">{{ outstanding.length }} outstanding</span>
          </h2>
          <p class="td-hint">
            Name what has to be on file. The requirement stands whether or not
            the document exists yet — that gap is the thing worth seeing.
          </p>

          <ul v-if="task.required_documents.length" class="td-list">
            <li v-for="d in task.required_documents" :key="d.id" class="td-req">
              <span :class="['td-req-mark', { done: d.is_fulfilled }]">{{ d.is_fulfilled ? "✓" : "○" }}</span>
              <span class="td-req-name">{{ d.name }}</span>
              <a v-if="d.has_file" class="link-btn"
                 :href="`/api/v1/tasks/${task.id}/required-documents/${d.id}/download?org=${org()}`">
                {{ d.original_name }}
              </a>
              <label v-else class="link-btn td-upload">
                Attach
                <input type="file" hidden @change="fulfil(d, $event)" />
              </label>
            </li>
          </ul>

          <div class="td-add">
            <input v-model="newRequirement" class="control" placeholder="Signed terms of reference"
                   @keyup.enter="addRequirement" />
            <button class="btn-ghost btn-sm" :disabled="!newRequirement.trim()" @click="addRequirement">Add</button>
          </div>
        </section>

        <!-- ── Sub-tasks ───────────────────────────────────────────────── -->
        <section v-if="task.subtasks.length" class="td-card">
          <h2 class="td-card-title">Sub-tasks</h2>
          <ul class="td-list">
            <li v-for="s in task.subtasks" :key="s.id" class="td-sub-task">
              <span :class="['pill', `pill-${statusTone(s.status)}`]">{{ titleCase(s.status) }}</span>
              <button class="link-btn" @click="router.push({ name: 'task', params: { id: s.id } })">
                {{ s.title }}
              </button>
            </li>
          </ul>
        </section>
      </div>
    </template>

    <!-- ── Ask someone ──────────────────────────────────────────────────── -->
    <div v-if="showAsk" class="td-sheet-backdrop" @click.self="showAsk = false">
      <aside class="td-sheet" role="dialog" aria-modal="true" aria-label="Ask someone">
        <div class="td-sheet-head">
          <h3 class="td-sheet-title">Ask someone</h3>
          <button class="td-close" aria-label="Close" @click="showAsk = false">×</button>
        </div>

        <p class="td-hint">
          Asking is not assigning — nothing moves until they answer. That is why
          the legacy's assists were accepted almost every time.
        </p>

        <fieldset class="td-kinds">
          <legend>What are you asking for</legend>
          <label v-for="k in KINDS" :key="k.key" class="td-check">
            <input v-model="ask.kind" type="radio" :value="k.key" />
            <span><strong>{{ k.label }}</strong> — {{ k.hint }}</span>
          </label>
        </fieldset>

        <div class="td-field"><span>Who</span>
          <EmployeePicker v-model="ask.to_employee_id" placeholder="Who are you asking?" /></div>

        <label class="td-field"><span>Why</span>
          <textarea v-model="ask.request_comment" class="control" rows="3"
                    placeholder="Context helps them say yes."></textarea></label>

        <button class="btn-primary btn-block" :disabled="saving || !ask.to_employee_id" @click="sendAsk">
          {{ saving ? "Sending…" : "Send request" }}
        </button>
      </aside>
    </div>

    <!-- ── Edit ─────────────────────────────────────────────────────────── -->
    <div v-if="editing" class="td-sheet-backdrop" @click.self="editing = false">
      <aside class="td-sheet" role="dialog" aria-modal="true" aria-label="Edit task">
        <div class="td-sheet-head">
          <h3 class="td-sheet-title">Edit Task</h3>
          <button class="td-close" aria-label="Close" @click="editing = false">×</button>
        </div>

        <label class="td-field"><span>Task</span>
          <input v-model="form.title" class="control" /></label>

        <label class="td-field"><span>Detail</span>
          <textarea v-model="form.description" class="control" rows="4"></textarea></label>

        <div class="td-row">
          <label class="td-field"><span>Kind of work</span>
            <select v-model="form.category" class="control">
              <option v-for="c in CATEGORIES" :key="c" :value="c">{{ titleCase(c) }}</option>
            </select></label>

          <label class="td-field"><span>Status</span>
            <select v-model="form.status" class="control">
              <option v-for="s in STATUSES" :key="s" :value="s">{{ titleCase(s) }}</option>
            </select></label>
        </div>

        <div class="td-row">
          <label class="td-field"><span>Priority</span>
            <select v-model="form.priority" class="control">
              <option v-for="p in PRIORITIES" :key="p" :value="p">{{ titleCase(p) }}</option>
            </select></label>

          <label class="td-field"><span>Estimated days</span>
            <input v-model="form.effort_estimate_days" class="control" type="number" step="0.5" min="0" /></label>
        </div>

        <div class="td-row">
          <label class="td-field"><span>Started</span>
            <input v-model="form.started_on" class="control" type="date" /></label>

          <label class="td-field"><span>Deadline</span>
            <input v-model="form.deadline" class="control" type="date" /></label>
        </div>

        <div class="td-field"><span>Owner</span>
          <EmployeePicker v-model="form.assigned_to" placeholder="Who is doing it?" /></div>

        <label class="td-check">
          <input v-model="form.requires_approval" type="checkbox" />
          <span>Somebody has to sign this off before it counts as done.</span>
        </label>

        <button class="btn-primary btn-block" :disabled="saving || !form.title.trim()" @click="save">
          {{ saving ? "Saving…" : "Save" }}
        </button>
      </aside>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.td-wrap { padding: var(--space-6); max-width: 78rem; }
.td-loading { padding: var(--space-6); color: var(--color-text-muted); }
.td-back { margin-bottom: var(--space-3); }

.td-header { @apply flex flex-wrap items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-5); }
.td-ref { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; }
.td-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: var(--space-1) 0 0; }
.td-sub { @apply flex flex-wrap items-center; gap: var(--space-2); margin: var(--space-2) 0 0; }
.td-actions { @apply flex flex-wrap items-center; gap: var(--space-2); }

.td-card {
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--space-5);
  margin-bottom: var(--space-4);
}
.td-card-title {
  @apply flex flex-wrap items-center;
  gap: var(--space-2); font-size: var(--text-base-size); font-weight: 600;
  color: var(--color-text); margin: 0 0 var(--space-3);
}

.td-grid { @apply grid; grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr)); gap: var(--space-4); }
.td-grid > .td-card { margin-bottom: 0; }

.td-facts { @apply grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: var(--space-4); margin: 0; }
.td-facts dt { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.td-facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: var(--space-1) 0 0; }
.td-late { color: var(--color-danger-text); font-weight: 500; }
.td-unowned { color: var(--color-warning-text); }

.td-description {
  font-size: var(--text-sm-size); color: var(--color-text); white-space: pre-wrap;
  margin: var(--space-4) 0 0; padding-top: var(--space-4);
  border-top: 1px solid var(--color-border);
}

.td-list { @apply flex flex-col; gap: var(--space-2); list-style: none; margin: 0; padding: 0; }
.td-muted { color: var(--color-text-light); }
.td-quiet { font-size: var(--text-sm-size); margin: 0; }
.td-hint { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0 0 var(--space-3); }

.td-person { @apply flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }
.td-avatar {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 1.5rem; height: 1.5rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: 0.6rem; font-weight: 600;
}
.td-tag {
  padding: 1px var(--space-2); border-radius: var(--radius-sm);
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: var(--text-xs-size); white-space: nowrap;
}

.td-ask { @apply flex flex-col; gap: var(--space-1); padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.td-ask-head { @apply flex flex-wrap items-center; gap: var(--space-2); }
.td-ask-meta { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; }
.td-ask-note { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }
.td-reply { color: var(--color-text-muted); padding-left: var(--space-3); border-left: 2px solid var(--color-border); }

.td-req { @apply flex flex-wrap items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }
.td-req-mark { color: var(--color-text-light); font-weight: 600; }
.td-req-mark.done { color: var(--color-success-text); }
.td-req-name { flex: 1 1 auto; }
.td-upload { @apply cursor-pointer; }

.td-add { @apply flex; gap: var(--space-2); margin-top: var(--space-3); }
.td-sub-task { @apply flex items-center; gap: var(--space-2); }

.td-field { @apply flex flex-col; gap: var(--space-1); }
.td-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.td-row { @apply grid; grid-template-columns: 1fr 1fr; gap: var(--space-3); }

.td-kinds { @apply flex flex-col; gap: var(--space-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-3); }
.td-kinds legend { font-size: var(--text-xs-size); color: var(--color-text-muted); padding: 0 var(--space-1); }
.td-check { @apply flex cursor-pointer items-start; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }

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

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); text-align: left; text-decoration: none; }
.link-btn:hover { @apply underline; }

.td-sheet-backdrop { @apply fixed inset-0 flex justify-end z-50; background: rgb(0 0 0 / 0.28); }
.td-sheet {
  @apply flex flex-col overflow-y-auto; width: min(28rem, 100%); gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised); box-shadow: var(--shadow-lg);
  animation: sheet-in 180ms ease;
}
@keyframes sheet-in { from { transform: translateX(12px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .td-sheet { animation: none; } }
.td-sheet-head { @apply flex items-center justify-between; }
.td-sheet-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.td-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

.btn-primary {
  @apply inline-flex cursor-pointer items-center justify-center border-none;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
  background: var(--color-brand-base); color: var(--color-on-brand);
}
.btn-block { width: 100%; }
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }

.btn-ghost {
  @apply inline-flex cursor-pointer items-center justify-center;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text);
}
.btn-ghost:disabled { @apply cursor-not-allowed opacity-50; }
.btn-sm { padding: 5px var(--space-3); font-size: var(--text-xs-size); }
</style>
