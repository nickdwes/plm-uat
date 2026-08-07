<script setup lang="ts">
/**
 * One task, as a panel over the list.
 *
 * Eight tabs, which are the designs'. Six were already built; two — Issues and
 * Resources — turn out to be things the legacy did constantly and I had not
 * read: 242 issues of which 212 were escalated to a named person, and 386
 * named document requirements against 825 plain attachments.
 *
 * A panel rather than a page because of what the tabs are for. You come here
 * to answer a request or resolve an issue and then carry on down the column;
 * navigating away and back for each one is how the legacy ended up with 261
 * delegations nobody ever replied to.
 */
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import EmployeePicker from "../../components/EmployeePicker.vue";

const props = defineProps<{ id: string }>();
const emit  = defineEmits<{ (e: "changed"): void }>();

interface Person { id: number | null; name: string | null }
interface Ask {
  id: number; kind: string; status: string; is_stale: boolean;
  request_comment: string | null; response_comment: string | null;
  to: Person | null; requester: Person | null;
}
interface Requirement {
  id: number; name: string; is_fulfilled: boolean;
  fulfilled_at: string | null; original_name: string | null; has_file: boolean;
}
interface Comment {
  id: number; body: string; is_pinned: boolean; created_at: string | null;
  author: Person | null; reactions: Record<string, number>; replies: Comment[];
}
interface Issue {
  id: number; name: string; details: string | null;
  priority: string; status: string; is_open: boolean;
  raised_by: Person | null;
  escalations: { id: number; note: string | null; at: string | null; to: Person | null }[];
}
interface Attachment {
  id: number; label: string | null; original_name: string | null;
  size_bytes: number | null; mime_type: string | null;
}
interface Dependency {
  id: number; reference: string | null; title: string; status: string;
  deadline: string | null; kind: string; note: string | null;
}
interface Task {
  id: number; reference: string | null; title: string; description: string | null;
  category: string; status: string; priority: string;
  deadline: string | null; started_on: string | null; completed_at: string | null;
  is_done: boolean; is_overdue: boolean;
  effort_estimate_days: number | string | null;
  elapsed_days: number | null; effort_variance: number | string | null;
  requires_approval: boolean; approved_at: string | null; approval_note: string | null;
  approver: Person | null; can_approve: boolean;
  started_by: Person | null; completed_by: Person | null;
  assignee: Person | null; assignees: Person[];
  project: { id: number; name: string } | null;
  assisting: { id: number; employee: Person }[];
  requests: Ask[];
  required_documents: Requirement[];
  attachments: Attachment[];
  comments: Comment[];
  issues: Issue[];
  dependencies: Dependency[];
  external_dependencies: { id: number; note: string | null }[];
  subtasks: Task[];
  open_issue_count: number;
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

/** Every write returns the whole task, so one helper covers all of them. */
async function send(method: "POST" | "PUT", path: string, body: unknown, done?: string) {
  saving.value = true;
  try {
    task.value = (await api<{ data: Task }>(method, `/api/v1/tasks/${props.id}${path}`, {
      org: org(), body,
    })).data;
    if (done) notifySaved(done);
    emit("changed");
    return true;
  } catch (e) { fail(e, "Could not save that."); return false; }
  finally { saving.value = false; }
}

function close() {
  emit("changed");
  router.push({ name: "tasks" });
}

watch(() => props.id, load);
onMounted(load);

// ── Header actions ──────────────────────────────────────────────────────────

/**
 * A task that needs signing off goes for approval instead of finishing.
 *
 * The server enforces this; the button says so up front rather than letting
 * somebody press Complete and be surprised by where it lands.
 */
async function markComplete() {
  const needsSignOff = task.value!.requires_approval && !task.value!.approved_at;
  await send("PUT", "", { title: task.value!.title, status: "completed" },
    needsSignOff ? "Sent for approval." : "Marked complete.");
}

// ── Approval ────────────────────────────────────────────────────────────────

const rejecting = ref(false);
const rejectNote = ref("");

const decide = (approved: boolean, note?: string) =>
  send("POST", "/approval", { approved, note: note ?? null },
    approved ? "Approved." : "Sent back.");

async function reject() {
  const ok = await decide(false, rejectNote.value.trim() || undefined);
  if (ok) { rejecting.value = false; rejectNote.value = ""; }
}

const confirmingDelete = ref(false);

async function destroy() {
  try {
    await api("DELETE", `/api/v1/tasks/${props.id}`, { org: org() });
    notifySaved("Task deleted.");
    close();
  } catch (e) { fail(e, "Could not delete the task."); }
}

// ── Editing the facts inline ────────────────────────────────────────────────

const editing = ref(false);
const form = ref({
  title: "", description: "", category: "internal", status: "todo", priority: "medium",
  deadline: "", started_on: "", effort_estimate_days: "" as string | number,
  assigned_to: null as number | null,
  requires_approval: false, approver_id: null as number | null,
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
    approver_id: t.approver?.id ?? null,
  };
  editing.value = true;
}

async function save() {
  const ok = await send("PUT", "", {
    ...form.value,
    deadline: form.value.deadline || null,
    started_on: form.value.started_on || null,
    effort_estimate_days: form.value.effort_estimate_days === "" ? null : form.value.effort_estimate_days,
  }, "Task updated.");
  if (ok) editing.value = false;
}

// ── Tabs ────────────────────────────────────────────────────────────────────

const TABS = [
  "Description", "Sub-tasks", "Dependencies", "Assisting",
  "Comments", "Resources", "Activities", "Issues",
] as const;
type Tab = typeof TABS[number];
const tab = ref<Tab>("Description");

// ── Description: attachments ────────────────────────────────────────────────

async function attach(event: Event) {
  const input = event.target as HTMLInputElement;
  const file  = input.files?.[0];
  if (!file) return;

  const body = new FormData();
  body.append("file", file);

  saving.value = true;
  try {
    task.value = (await api<{ data: Task }>("POST", `/api/v1/tasks/${props.id}/attachments`, {
      org: org(), formData: body,
    })).data;
    notifySaved("Attached.");
    emit("changed");
  } catch (e) { fail(e, "Could not attach the file."); }
  finally { saving.value = false; input.value = ""; }
}

// ── Sub-tasks ───────────────────────────────────────────────────────────────

const showSubtask = ref(false);
const subtask = ref({ title: "", deadline: "", priority: "medium", assigned_to: null as number | null });

async function addSubtask() {
  if (!subtask.value.title.trim()) return;
  const ok = await send("POST", "/subtasks", {
    ...subtask.value, deadline: subtask.value.deadline || null,
  }, "Sub-task added.");
  if (ok) { showSubtask.value = false; subtask.value = { title: "", deadline: "", priority: "medium", assigned_to: null }; }
}

// ── Dependencies ────────────────────────────────────────────────────────────

const showDependency = ref(false);
const dependency = ref({ note: "" });

async function addDependency() {
  if (!dependency.value.note.trim()) return;
  const ok = await send("POST", "/dependencies", { note: dependency.value.note }, "Dependency added.");
  if (ok) { showDependency.value = false; dependency.value = { note: "" }; }
}

// ── Assisting ───────────────────────────────────────────────────────────────

const showAsk = ref(false);
const ask = ref({ kind: "assist", to_employee_id: null as number | null, request_comment: "" });

async function sendAsk() {
  if (!ask.value.to_employee_id) return;
  const ok = await send("POST", "/requests", ask.value, "Asked. It is now in their requests.");
  if (ok) { showAsk.value = false; ask.value = { kind: "assist", to_employee_id: null, request_comment: "" }; }
}

// ── Comments ────────────────────────────────────────────────────────────────

const draft = ref("");
const replyingTo = ref<number | null>(null);
const replyDraft = ref("");
const menuFor = ref<number | null>(null);

async function addComment() {
  if (!draft.value.trim()) return;
  const ok = await send("POST", "/comments", { body: draft.value.trim() });
  if (ok) draft.value = "";
}

async function addReply(parent: Comment) {
  if (!replyDraft.value.trim()) return;
  const ok = await send("POST", "/comments", { body: replyDraft.value.trim(), parent_id: parent.id });
  if (ok) { replyDraft.value = ""; replyingTo.value = null; }
}

const react = (c: Comment, emoji: string) => send("POST", `/comments/${c.id}/react`, { emoji });
const pin   = (c: Comment) => send("POST", `/comments/${c.id}/pin`, { is_pinned: !c.is_pinned });

// ── Resources ───────────────────────────────────────────────────────────────

const showResource = ref(false);
const resourceName = ref("");

async function addResource() {
  if (!resourceName.value.trim()) return;
  const ok = await send("POST", "/required-documents", { name: resourceName.value.trim() }, "Resource required.");
  if (ok) { resourceName.value = ""; showResource.value = false; }
}

async function fulfil(requirement: Requirement, event: Event) {
  const input = event.target as HTMLInputElement;
  const file  = input.files?.[0];
  if (!file) return;

  const body = new FormData();
  body.append("file", file);

  saving.value = true;
  try {
    task.value = (await api<{ data: Task }>(
      "POST", `/api/v1/tasks/${props.id}/required-documents/${requirement.id}/fulfil`,
      { org: org(), formData: body },
    )).data;
    notifySaved(`${requirement.name} supplied.`);
    emit("changed");
  } catch (e) { fail(e, "Could not upload the document."); }
  finally { saving.value = false; input.value = ""; }
}

// ── Issues ──────────────────────────────────────────────────────────────────

const showIssue = ref(false);
const issue = ref({
  name: "", details: "", priority: "high",
  escalate: false, escalate_to: null as number | null,
});

async function addIssue() {
  if (!issue.value.name.trim()) return;
  const ok = await send("POST", "/issues", {
    name: issue.value.name, details: issue.value.details || null,
    priority: issue.value.priority,
    // The toggle off means nobody was named, not that the field was skipped.
    escalate_to: issue.value.escalate ? issue.value.escalate_to : null,
  }, "Issue raised.");
  if (ok) {
    showIssue.value = false;
    issue.value = { name: "", details: "", priority: "high", escalate: false, escalate_to: null };
  }
}

const toggleIssue = (i: Issue) =>
  send("PUT", `/issues/${i.id}`, { status: i.is_open ? "resolved" : "open" },
    i.is_open ? "Issue resolved." : "Issue reopened.");

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
const EMOJI = ["👍", "🎉", "👀", "❤️"];

const statusTone = (s: string) =>
  s === "completed" ? "ok" : s === "blocked" ? "bad" : s === "in_progress" ? "info"
    : s === "awaiting_approval" ? "warn" : "neutral";

const askTone = (s: string) => s === "accepted" ? "ok" : s === "declined" ? "bad" : "warn";

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

/** "2 days ago" — the designs use relative time throughout the panel. */
function ago(v: string | null): string {
  if (!v) return "";
  const seconds = Math.round((Date.now() - new Date(v).getTime()) / 1000);
  const steps: [number, Intl.RelativeTimeFormatUnit][] =
    [[60, "second"], [3600, "minute"], [86400, "hour"], [2592000, "day"], [31536000, "month"]];
  const fmt = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });
  let last = 1;
  for (const [limit, unit] of steps) {
    if (seconds < limit) return fmt.format(-Math.round(seconds / last), unit);
    last = limit;
  }
  return fmt.format(-Math.round(seconds / 31536000), "year");
}

const titleCase = (v: string) => v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());

const initials = (name: string | null | undefined) =>
  (name ?? "?").split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

const size = (bytes: number | null) =>
  bytes === null ? "" : bytes < 1024 * 1024
    ? `${Math.round(bytes / 1024)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;

/**
 * Over or under the estimate. Absent where there is none — nobody having said
 * what the plan was is not the same as coming in exactly on it.
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

const outstanding = computed(() => (task.value?.required_documents ?? []).filter((d) => !d.is_fulfilled));
const waiting     = computed(() => (task.value?.requests ?? []).filter((r) => r.status === "pending"));
const openIssues  = computed(() => (task.value?.issues ?? []).filter((i) => i.is_open));

/** Tabs that carry something worth a badge get one. */
function badge(name: Tab): number {
  const t = task.value;
  if (!t) return 0;
  return {
    "Sub-tasks": t.subtasks.length,
    Dependencies: t.dependencies.length + t.external_dependencies.length,
    Assisting: waiting.value.length,
    Comments: t.comments.length,
    Resources: outstanding.value.length,
    Issues: openIssues.value.length,
  }[name as string] ?? 0;
}
</script>

<template>
  <div class="tp-backdrop" @click.self="close">
    <section class="tp-panel" role="dialog" aria-modal="true" aria-label="Task details">
      <div v-if="loading" class="tp-loading">Loading…</div>

      <template v-else-if="task">
        <!-- ── Header ───────────────────────────────────────────────────── -->
        <header class="tp-head">
          <p class="tp-crumb">
            <span class="tp-ref">{{ task.reference ?? "—" }}</span>
            <span class="tp-crumb-name">{{ task.title }}</span>
          </p>
          <div class="tp-head-actions">
            <button class="tp-icon" aria-label="Delete task" @click="confirmingDelete = true">
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                <path d="M4 6h12M8 6V4h4v2M6 6l1 10h6l1-10M9 9v5M11 9v5" />
              </svg>
            </button>
            <button class="tp-icon" aria-label="Close" @click="close">
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                <path d="M5 5l10 10M15 5L5 15" />
              </svg>
            </button>
          </div>
        </header>

        <!-- ── Title block ──────────────────────────────────────────────── -->
        <div class="tp-title-block">
          <button v-if="!task.is_done && task.status !== 'awaiting_approval'"
                  class="tp-complete" :disabled="saving" @click="markComplete">
            ✓ {{ task.requires_approval && !task.approved_at ? "Send for approval" : "Mark as complete" }}
          </button>
          <span v-else-if="task.status === 'awaiting_approval'" class="tp-complete waiting">
            ⏳ Waiting on {{ task.approver?.name ?? "an approver" }}
          </span>
          <span v-else class="tp-complete done">✓ Completed</span>

          <h1 class="tp-title">{{ task.title }}</h1>
          <p class="tp-byline">
            <span class="tp-avatar sm">{{ initials(task.assignee?.name) }}</span>
            <strong>{{ task.assignee?.name ?? "Unassigned" }}</strong>
            owns this task
            <template v-if="task.project">
              · <button class="link-btn" @click="router.push({ name: 'project', params: { id: task.project.id } })">
                {{ task.project.name }}
              </button>
            </template>
          </p>
        </div>

        <!--
          The approver's controls, only where they can act.

          Approval had columns, a status and a board column and nothing that
          could act on any of it — a task could reach Awaiting Approval and
          stay there forever.
        -->
        <div v-if="task.status === 'awaiting_approval'" class="tp-approval">
          <p class="tp-approval-text">
            This task is waiting on <strong>{{ task.approver?.name ?? "nobody in particular" }}</strong>.
          </p>
          <!-- Only for the person who can actually decide. Showing a button
               the server will refuse is worse than showing none. -->
          <div v-if="task.can_approve" class="tp-approval-actions">
            <button class="btn-ghost btn-sm" :disabled="saving" @click="rejecting = true">Send back</button>
            <button class="btn-primary btn-sm" :disabled="saving" @click="decide(true)">Approve</button>
          </div>
        </div>

        <!-- Why it came back. A rejection with no reason puts the task where
             it was with nothing learned. -->
        <p v-else-if="task.approval_note && !task.approved_at" class="tp-sent-back">
          Sent back: “{{ task.approval_note }}”
        </p>

        <!-- ── Facts ────────────────────────────────────────────────────── -->
        <div class="tp-facts">
          <div class="tp-fact"><span class="tp-fact-key">Deadline</span>
            <span :class="['tp-fact-value', { late: task.is_overdue }]">{{ date(task.deadline) }}</span></div>
          <div class="tp-fact"><span class="tp-fact-key">Priority</span>
            <span :class="['tp-flag', task.priority]">
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                <path d="M5 3v14" /><path d="M5 4h9l-2 3 2 3H5z" />
              </svg>
              {{ titleCase(task.priority) }} priority
            </span></div>
          <div class="tp-fact"><span class="tp-fact-key">Status</span>
            <span :class="['pill', `pill-${statusTone(task.status)}`]">{{ titleCase(task.status) }}</span></div>
          <div class="tp-fact"><span class="tp-fact-key">Assignees</span>
            <span class="tp-stack">
              <span v-for="p in task.assignees.slice(0, 4)" :key="p.id ?? ''" class="tp-avatar"
                    :title="p.name ?? ''">{{ initials(p.name) }}</span>
              <span v-if="task.assignees.length > 4" class="tp-avatar more">+{{ task.assignees.length - 4 }}</span>
              <span v-if="!task.assignees.length" class="tp-unowned">Unassigned</span>
            </span></div>
          <!-- The comparison the legacy had the data for on nine tasks in ten
               and never once made. -->
          <div class="tp-fact"><span class="tp-fact-key">Against estimate</span>
            <span v-if="variance" :class="['pill', `pill-${variance.tone}`]">{{ variance.text }}</span>
            <span v-else class="tp-muted">Not estimated</span></div>
          <div class="tp-fact tp-fact-edit">
            <button class="link-btn" @click="beginEdit">Edit details</button>
          </div>
        </div>

        <!-- ── Tabs ─────────────────────────────────────────────────────── -->
        <nav class="tp-tabs">
          <button v-for="t in TABS" :key="t" :class="['tp-tab', { active: tab === t }]" @click="tab = t">
            {{ t }}
            <span v-if="badge(t)" class="tp-tab-badge">{{ badge(t) }}</span>
          </button>
        </nav>

        <div class="tp-body">
          <!-- ── Description ────────────────────────────────────────────── -->
          <template v-if="tab === 'Description'">
            <h2 class="tp-section">Description</h2>
            <p v-if="task.description" class="tp-text">{{ task.description }}</p>
            <p v-else class="tp-muted tp-quiet">Nothing written yet.</p>

            <h2 class="tp-section">Attachments</h2>
            <ul v-if="task.attachments.length" class="tp-files">
              <li v-for="a in task.attachments" :key="a.id" class="tp-file">
                <span class="tp-file-mark">PDF</span>
                <span class="tp-file-body">
                  <a class="link-btn"
                     :href="`/api/v1/tasks/${task.id}/attachments/${a.id}/download?org=${org()}`">
                    {{ a.original_name ?? a.label }}
                  </a>
                  <span class="tp-file-size">{{ size(a.size_bytes) }}</span>
                </span>
              </li>
            </ul>
            <label class="link-btn tp-upload">
              🔗 Add attachment
              <input type="file" hidden @change="attach" />
            </label>
          </template>

          <!-- ── Sub-tasks ──────────────────────────────────────────────── -->
          <template v-else-if="tab === 'Sub-tasks'">
            <div class="tp-section-row">
              <h2 class="tp-section">Sub-tasks</h2>
              <button class="link-btn" @click="showSubtask = true">+ Add Sub-Task</button>
            </div>
            <p v-if="!task.subtasks.length" class="tp-muted tp-quiet">
              No sub-tasks. A sub-task is a task — it gets its own owner, deadline and status.
            </p>
            <table v-else class="tp-table">
              <thead><tr><th>Task</th><th>Status</th><th>Due Date</th><th>Assignee</th></tr></thead>
              <tbody>
                <tr v-for="s in task.subtasks" :key="s.id">
                  <td><button class="link-btn" @click="router.push({ name: 'task', params: { id: s.id } })">
                    {{ s.title }}</button></td>
                  <td><span :class="['pill', `pill-${statusTone(s.status)}`]">{{ titleCase(s.status) }}</span></td>
                  <td class="tp-nowrap">{{ date(s.deadline) }}</td>
                  <td>
                    <span v-if="s.assignee?.name" class="tp-avatar" :title="s.assignee.name">
                      {{ initials(s.assignee.name) }}</span>
                    <span v-else class="tp-unowned">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </template>

          <!-- ── Dependencies ───────────────────────────────────────────── -->
          <template v-else-if="tab === 'Dependencies'">
            <div class="tp-section-row">
              <h2 class="tp-section">Dependencies</h2>
              <button class="link-btn" @click="showDependency = true">+ Add Dependencies</button>
            </div>
            <p v-if="!task.dependencies.length && !task.external_dependencies.length"
               class="tp-muted tp-quiet">Nothing is holding this up.</p>
            <table v-else class="tp-table">
              <thead><tr><th>Waiting on</th><th>Status</th><th>Due Date</th></tr></thead>
              <tbody>
                <tr v-for="d in task.dependencies" :key="'t' + d.id">
                  <td><button class="link-btn" @click="router.push({ name: 'task', params: { id: d.id } })">
                    {{ d.title }}</button></td>
                  <td><span :class="['pill', `pill-${statusTone(d.status)}`]">{{ titleCase(d.status) }}</span></td>
                  <td class="tp-nowrap">{{ date(d.deadline) }}</td>
                </tr>
                <!-- Written down rather than linked — 172 of the legacy's 203
                     were this, and they are the ones outside our control. -->
                <tr v-for="d in task.external_dependencies" :key="'x' + d.id">
                  <td>{{ d.note }}</td>
                  <td><span class="pill pill-neutral">External</span></td>
                  <td class="tp-muted">—</td>
                </tr>
              </tbody>
            </table>
          </template>

          <!-- ── Assisting ──────────────────────────────────────────────── -->
          <template v-else-if="tab === 'Assisting'">
            <div class="tp-section-row">
              <h2 class="tp-section">Assisting</h2>
              <button class="link-btn" @click="showAsk = true">+ Ask for Help</button>
            </div>

            <p v-if="!task.assisting.length && !task.requests.length" class="tp-muted tp-quiet">
              Nobody asked. Asking is not assigning — nothing moves until they answer.
            </p>

            <ul v-if="task.assisting.length" class="tp-list">
              <li v-for="m in task.assisting" :key="m.id" class="tp-person">
                <span class="tp-avatar">{{ initials(m.employee.name) }}</span>
                {{ m.employee.name }}<span class="tp-tag">assisting</span>
              </li>
            </ul>

            <ul v-if="task.requests.length" class="tp-list tp-asks">
              <li v-for="r in task.requests" :key="r.id" class="tp-ask">
                <div class="tp-ask-head">
                  <span class="tp-tag">{{ titleCase(r.kind) }}</span>
                  <span :class="['pill', `pill-${askTone(r.status)}`]">{{ titleCase(r.status) }}</span>
                  <!-- Asked and never answered for over a week. The legacy left
                       261 like this with nothing surfacing them. -->
                  <span v-if="r.is_stale" class="pill pill-bad">Stale</span>
                </div>
                <p class="tp-ask-meta">{{ r.to?.name }} — asked by {{ r.requester?.name ?? "someone" }}</p>
                <p v-if="r.request_comment" class="tp-ask-note">“{{ r.request_comment }}”</p>
                <p v-if="r.response_comment" class="tp-ask-note tp-reply">“{{ r.response_comment }}”</p>
              </li>
            </ul>
          </template>

          <!-- ── Comments ───────────────────────────────────────────────── -->
          <template v-else-if="tab === 'Comments'">
            <h2 class="tp-section">Comments</h2>
            <p v-if="!task.comments.length" class="tp-muted tp-quiet">Nothing said yet.</p>

            <article v-for="c in task.comments" :key="c.id" class="tp-comment">
              <div class="tp-comment-head">
                <span class="tp-avatar">{{ initials(c.author?.name) }}</span>
                <strong class="tp-comment-who">{{ c.author?.name ?? "Someone" }}</strong>
                <span class="tp-comment-when">{{ ago(c.created_at) }}</span>
                <span v-if="c.is_pinned" class="tp-tag">pinned</span>
                <button class="tp-kebab" aria-label="Comment actions"
                        @click="menuFor = menuFor === c.id ? null : c.id">⋮</button>
              </div>

              <div v-if="menuFor === c.id" class="tp-menu">
                <button @click="replyingTo = c.id; menuFor = null">Reply to comment</button>
                <button v-for="e in EMOJI" :key="e" @click="react(c, e); menuFor = null">React {{ e }}</button>
                <button @click="pin(c); menuFor = null">{{ c.is_pinned ? "Unpin" : "Pin" }} comment</button>
              </div>

              <p class="tp-comment-body">{{ c.body }}</p>

              <div v-if="Object.keys(c.reactions).length" class="tp-reactions">
                <button v-for="(n, e) in c.reactions" :key="e" class="tp-reaction" @click="react(c, String(e))">
                  {{ e }} {{ n }}
                </button>
              </div>

              <article v-for="r in c.replies" :key="r.id" class="tp-comment tp-nested">
                <div class="tp-comment-head">
                  <span class="tp-avatar">{{ initials(r.author?.name) }}</span>
                  <strong class="tp-comment-who">{{ r.author?.name ?? "Someone" }}</strong>
                  <span class="tp-comment-when">{{ ago(r.created_at) }}</span>
                </div>
                <p class="tp-comment-body">{{ r.body }}</p>
              </article>

              <div v-if="replyingTo === c.id" class="tp-reply-box">
                <textarea v-model="replyDraft" class="control" rows="2" placeholder="Write a reply…"></textarea>
                <div class="tp-reply-actions">
                  <button class="btn-ghost btn-sm" @click="replyingTo = null; replyDraft = ''">Cancel</button>
                  <button class="btn-primary btn-sm" :disabled="saving || !replyDraft.trim()"
                          @click="addReply(c)">Reply</button>
                </div>
              </div>
            </article>

            <div class="tp-compose">
              <textarea v-model="draft" class="control" rows="3" placeholder="Add your comment.."></textarea>
              <div class="tp-reply-actions">
                <button class="btn-ghost btn-sm" @click="draft = ''">Cancel</button>
                <button class="btn-primary btn-sm" :disabled="saving || !draft.trim()" @click="addComment">
                  Add comment
                </button>
              </div>
            </div>
          </template>

          <!-- ── Resources ──────────────────────────────────────────────── -->
          <template v-else-if="tab === 'Resources'">
            <div class="tp-section-row">
              <h2 class="tp-section">Resources</h2>
              <button class="link-btn" @click="showResource = true">+ Add Resources</button>
            </div>
            <p class="tp-hint">
              What has to be on file before this closes. The requirement stands
              whether or not the document exists yet — that gap is the thing
              worth seeing. The legacy named 386 and got 247.
            </p>

            <p v-if="!task.required_documents.length" class="tp-muted tp-quiet">Nothing required.</p>
            <ul v-else class="tp-list">
              <li v-for="d in task.required_documents" :key="d.id" class="tp-req">
                <span :class="['tp-req-mark', { done: d.is_fulfilled }]">{{ d.is_fulfilled ? "✓" : "○" }}</span>
                <span class="tp-req-name">{{ d.name }}</span>
                <a v-if="d.has_file" class="link-btn"
                   :href="`/api/v1/tasks/${task.id}/required-documents/${d.id}/download?org=${org()}`">
                  {{ d.original_name }}
                </a>
                <label v-else class="link-btn tp-upload">
                  Attach
                  <input type="file" hidden @change="fulfil(d, $event)" />
                </label>
              </li>
            </ul>
          </template>

          <!-- ── Activities ─────────────────────────────────────────────── -->
          <template v-else-if="tab === 'Activities'">
            <h2 class="tp-section">Activities</h2>
            <ul class="tp-list">
              <li v-if="task.completed_at" class="tp-activity">
                Completed {{ ago(task.completed_at) }}
                <!-- Who finished it, which is not the same as who owns it once
                     several people are on a task. -->
                <template v-if="task.completed_by"> by {{ task.completed_by.name }}</template>
              </li>
              <li v-if="task.started_on" class="tp-activity">
                Started {{ date(task.started_on) }}
                <template v-if="task.started_by"> by {{ task.started_by.name }}</template>
              </li>
              <li v-if="task.approved_at" class="tp-activity">
                Approved {{ ago(task.approved_at) }}
                <template v-if="task.approver"> by {{ task.approver.name }}</template>
              </li>
              <li v-for="r in task.requests.filter((x) => x.status !== 'pending')" :key="r.id" class="tp-activity">
                {{ r.to?.name }} {{ r.status }} the {{ r.kind }} request
              </li>
            </ul>
            <!-- Said plainly rather than shown as an empty feed: time logging
                 is not built, and a blank Activities tab would read as though
                 nothing had happened. -->
            <p class="tp-hint">
              Time logged against tasks is not recorded yet. Until clock-in is
              wired to tasks this shows only what the task itself can prove.
            </p>
          </template>

          <!-- ── Issues ─────────────────────────────────────────────────── -->
          <template v-else-if="tab === 'Issues'">
            <div class="tp-section-row">
              <h2 class="tp-section">Issues</h2>
              <button class="link-btn" @click="showIssue = true">+ Add Issue</button>
            </div>
            <p v-if="!task.issues.length" class="tp-muted tp-quiet">
              Nothing in the way. An issue is not the task's status — a task can
              be in progress and still be stuck.
            </p>

            <article v-for="i in task.issues" :key="i.id" class="tp-issue">
              <div class="tp-issue-head">
                <span :class="['tp-flag', i.priority]">
                  <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                    <path d="M5 3v14" /><path d="M5 4h9l-2 3 2 3H5z" />
                  </svg>
                  {{ titleCase(i.priority) }} priority
                </span>
                <span :class="['pill', i.is_open ? 'pill-bad' : 'pill-ok']">
                  {{ i.is_open ? "Open" : "Resolved" }}
                </span>
                <button class="link-btn tp-issue-toggle" :disabled="saving" @click="toggleIssue(i)">
                  {{ i.is_open ? "Resolve" : "Reopen" }}
                </button>
              </div>
              <h3 class="tp-issue-name">{{ i.name }}</h3>
              <p v-if="i.details" class="tp-text">{{ i.details }}</p>
              <p class="tp-ask-meta">raised by {{ i.raised_by?.name ?? "someone" }}</p>

              <!-- Kept as a list: an issue that climbs past one person has two
                   of these, and 212 of the legacy's 242 were escalated at all. -->
              <ul v-if="i.escalations.length" class="tp-escalations">
                <li v-for="e in i.escalations" :key="e.id">
                  Escalated to <strong>{{ e.to?.name }}</strong> {{ ago(e.at) }}
                  <template v-if="e.note"> — “{{ e.note }}”</template>
                </li>
              </ul>
            </article>
          </template>
        </div>
      </template>

      <!-- ── Modals ───────────────────────────────────────────────────────── -->

      <div v-if="confirmingDelete" class="tp-modal-backdrop" @click.self="confirmingDelete = false">
        <div class="tp-modal tp-modal-sm" role="dialog" aria-modal="true" aria-label="Delete task">
          <div class="tp-modal-head">
            <span class="tp-danger-mark">🗑</span>
            <button class="tp-close" aria-label="Close" @click="confirmingDelete = false">×</button>
          </div>
          <h3 class="tp-modal-title">Delete Task</h3>
          <p class="tp-quiet">Are you sure you want to proceed with deleting this task.</p>
          <div class="tp-modal-actions">
            <button class="link-btn" @click="confirmingDelete = false">Cancel</button>
            <button class="btn-danger" @click="destroy">Delete</button>
          </div>
        </div>
      </div>

      <div v-if="showSubtask" class="tp-modal-backdrop" @click.self="showSubtask = false">
        <div class="tp-modal" role="dialog" aria-modal="true" aria-label="Add sub-task">
          <div class="tp-modal-head">
            <h3 class="tp-modal-title">Add Sub-task</h3>
            <button class="tp-close" aria-label="Close" @click="showSubtask = false">×</button>
          </div>
          <label class="tp-field"><span>Task Name</span>
            <input v-model="subtask.title" class="control" placeholder="Task Name" /></label>
          <label class="tp-field"><span>Task Deadline</span>
            <input v-model="subtask.deadline" class="control" type="date" /></label>
          <label class="tp-field"><span>Task Priority</span>
            <select v-model="subtask.priority" class="control">
              <option v-for="p in PRIORITIES" :key="p" :value="p">{{ titleCase(p) }} priority</option>
            </select></label>
          <div class="tp-field"><span>Assignee</span>
            <EmployeePicker v-model="subtask.assigned_to" placeholder="Assignee" /></div>
          <button class="btn-primary btn-block" :disabled="saving || !subtask.title.trim()" @click="addSubtask">
            Add Sub-task
          </button>
        </div>
      </div>

      <div v-if="showDependency" class="tp-modal-backdrop" @click.self="showDependency = false">
        <div class="tp-modal" role="dialog" aria-modal="true" aria-label="Add dependency">
          <div class="tp-modal-head">
            <h3 class="tp-modal-title">Add Dependency</h3>
            <button class="tp-close" aria-label="Close" @click="showDependency = false">×</button>
          </div>
          <p class="tp-hint">
            What is this task waiting on? Most blockers are outside the system —
            172 of the legacy's 203 were a line of text, not another task.
          </p>
          <label class="tp-field"><span>Dependency</span>
            <input v-model="dependency.note" class="control"
                   placeholder="Awaiting the client’s firewall change window" /></label>
          <button class="btn-primary btn-block" :disabled="saving || !dependency.note.trim()" @click="addDependency">
            Add Dependency
          </button>
        </div>
      </div>

      <div v-if="showAsk" class="tp-modal-backdrop" @click.self="showAsk = false">
        <div class="tp-modal" role="dialog" aria-modal="true" aria-label="Ask for help">
          <div class="tp-modal-head">
            <h3 class="tp-modal-title">Assisting</h3>
            <button class="tp-close" aria-label="Close" @click="showAsk = false">×</button>
          </div>
          <p class="tp-hint">
            Asking is not assigning — nothing moves until they answer. That is
            why the legacy's assists were accepted 98% of the time.
          </p>
          <fieldset class="tp-kinds">
            <legend>What are you asking for</legend>
            <label v-for="k in KINDS" :key="k.key" class="tp-check">
              <input v-model="ask.kind" type="radio" :value="k.key" />
              <span><strong>{{ k.label }}</strong> — {{ k.hint }}</span>
            </label>
          </fieldset>
          <div class="tp-field"><span>Ask Someone</span>
            <EmployeePicker v-model="ask.to_employee_id" placeholder="Select team member" /></div>
          <label class="tp-field"><span>Why</span>
            <textarea v-model="ask.request_comment" class="control" rows="3"
                      placeholder="Context helps them say yes."></textarea></label>
          <button class="btn-primary btn-block" :disabled="saving || !ask.to_employee_id" @click="sendAsk">
            Ask for Help
          </button>
        </div>
      </div>

      <div v-if="showResource" class="tp-modal-backdrop" @click.self="showResource = false">
        <div class="tp-modal" role="dialog" aria-modal="true" aria-label="Add resource">
          <div class="tp-modal-head">
            <h3 class="tp-modal-title">Add Resource</h3>
            <button class="tp-close" aria-label="Close" @click="showResource = false">×</button>
          </div>
          <label class="tp-field"><span>What has to be on file</span>
            <input v-model="resourceName" class="control" placeholder="Signed terms of reference" /></label>
          <button class="btn-primary btn-block" :disabled="saving || !resourceName.trim()" @click="addResource">
            Add Resource
          </button>
        </div>
      </div>

      <div v-if="showIssue" class="tp-modal-backdrop" @click.self="showIssue = false">
        <div class="tp-modal" role="dialog" aria-modal="true" aria-label="Add issue">
          <div class="tp-modal-head">
            <h3 class="tp-modal-title">Add Issue</h3>
            <button class="tp-close" aria-label="Close" @click="showIssue = false">×</button>
          </div>
          <label class="tp-field"><span>Issue Name</span>
            <input v-model="issue.name" class="control" placeholder="Issue Name" /></label>
          <label class="tp-field"><span>Issue Description</span>
            <textarea v-model="issue.details" class="control" rows="3" placeholder="Issue Description"></textarea></label>
          <label class="tp-field"><span>Issue Priority</span>
            <select v-model="issue.priority" class="control">
              <option v-for="p in PRIORITIES" :key="p" :value="p">{{ titleCase(p) }} priority</option>
            </select></label>

          <!-- In the same form rather than behind a second action: the legacy
               escalated 212 of its 242 issues, so this is the common case. -->
          <label class="tp-check">
            <input v-model="issue.escalate" type="checkbox" />
            <span>Escalate this to somebody</span>
          </label>
          <div v-if="issue.escalate" class="tp-field">
            <EmployeePicker v-model="issue.escalate_to" placeholder="Who should see it?" />
          </div>

          <button class="btn-primary btn-block" :disabled="saving || !issue.name.trim()" @click="addIssue">
            Add Issue
          </button>
        </div>
      </div>

      <div v-if="rejecting" class="tp-modal-backdrop" @click.self="rejecting = false">
        <div class="tp-modal" role="dialog" aria-modal="true" aria-label="Send task back">
          <div class="tp-modal-head">
            <h3 class="tp-modal-title">Send back</h3>
            <button class="tp-close" aria-label="Close" @click="rejecting = false">×</button>
          </div>
          <p class="tp-hint">
            It goes back to In Progress, not to the start — the work was done
            and found wanting, not left untouched.
          </p>
          <label class="tp-field"><span>What needs fixing</span>
            <textarea v-model="rejectNote" class="control" rows="3"
                      placeholder="The configuration backup is missing."></textarea></label>
          <button class="btn-primary btn-block" :disabled="saving" @click="reject">Send back</button>
        </div>
      </div>

      <div v-if="editing" class="tp-modal-backdrop" @click.self="editing = false">
        <div class="tp-modal" role="dialog" aria-modal="true" aria-label="Edit task">
          <div class="tp-modal-head">
            <h3 class="tp-modal-title">Edit Task</h3>
            <button class="tp-close" aria-label="Close" @click="editing = false">×</button>
          </div>
          <label class="tp-field"><span>Task Name</span>
            <input v-model="form.title" class="control" /></label>
          <label class="tp-field"><span>Task Description</span>
            <textarea v-model="form.description" class="control" rows="4"></textarea></label>
          <div class="tp-row">
            <label class="tp-field"><span>Kind of work</span>
              <select v-model="form.category" class="control">
                <option v-for="c in CATEGORIES" :key="c" :value="c">{{ titleCase(c) }}</option>
              </select></label>
            <label class="tp-field"><span>Status</span>
              <select v-model="form.status" class="control">
                <option v-for="s in STATUSES" :key="s" :value="s">{{ titleCase(s) }}</option>
              </select></label>
          </div>
          <div class="tp-row">
            <label class="tp-field"><span>Priority</span>
              <select v-model="form.priority" class="control">
                <option v-for="p in PRIORITIES" :key="p" :value="p">{{ titleCase(p) }}</option>
              </select></label>
            <label class="tp-field"><span>Estimated days</span>
              <input v-model="form.effort_estimate_days" class="control" type="number" step="0.5" min="0" /></label>
          </div>
          <div class="tp-row">
            <label class="tp-field"><span>Started</span>
              <input v-model="form.started_on" class="control" type="date" /></label>
            <label class="tp-field"><span>Deadline</span>
              <input v-model="form.deadline" class="control" type="date" /></label>
          </div>
          <div class="tp-field"><span>Assignee</span>
            <EmployeePicker v-model="form.assigned_to" placeholder="Assignee" /></div>
          <label class="tp-check">
            <input v-model="form.requires_approval" type="checkbox" />
            <span>Somebody has to sign this off before it counts as done.</span>
          </label>
          <!-- Without a name, "needs approval" leaves the task waiting on
               nobody in particular. -->
          <div v-if="form.requires_approval" class="tp-field"><span>Who approves it</span>
            <EmployeePicker v-model="form.approver_id" placeholder="Who signs it off?" />
          </div>
          <button class="btn-primary btn-block" :disabled="saving || !form.title.trim()" @click="save">
            {{ saving ? "Saving…" : "Save" }}
          </button>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.tp-backdrop { @apply fixed inset-0 flex items-start justify-center z-40; background: rgb(0 0 0 / 0.28); padding: var(--space-6) var(--space-4); overflow-y: auto; }
.tp-panel {
  @apply flex flex-col; width: min(52rem, 100%);
  background: var(--color-surface-raised); border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg); animation: panel-in 160ms ease;
}
@keyframes panel-in { from { transform: translateY(8px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .tp-panel { animation: none; } }
.tp-loading { padding: var(--space-8); color: var(--color-text-muted); }

.tp-head { @apply flex items-center justify-between; gap: var(--space-3); padding: var(--space-4) var(--space-5); border-bottom: 1px solid var(--color-border); }
.tp-crumb { @apply flex flex-wrap items-baseline; gap: var(--space-2); margin: 0; font-size: var(--text-sm-size); }
.tp-ref { font-weight: 600; color: var(--color-text); font-variant-numeric: tabular-nums; }
.tp-crumb-name { color: var(--color-text-muted); }
.tp-head-actions { @apply flex shrink-0 items-center; gap: var(--space-2); }
.tp-icon {
  @apply inline-flex cursor-pointer items-center justify-center;
  width: 2rem; height: 2rem; border-radius: var(--radius-md);
  border: 1px solid var(--color-border); background: var(--color-surface); color: var(--color-text-muted);
}
.tp-icon svg { width: 1rem; height: 1rem; }

.tp-title-block { @apply flex flex-col items-start; gap: var(--space-2); padding: var(--space-5); border-bottom: 1px solid var(--color-border); }
.tp-complete {
  @apply inline-flex cursor-pointer items-center; gap: var(--space-1);
  padding: 4px var(--space-3); border-radius: 999px;
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-xs-size); color: var(--color-text-muted);
}
.tp-complete.done { border-color: color-mix(in oklab, var(--color-success-text) 35%, transparent); color: var(--color-success-text); background: var(--color-success-subtle); }
.tp-complete.waiting { border-color: color-mix(in oklab, var(--color-warning-text) 35%, transparent); color: var(--color-warning-text); background: var(--color-warning-subtle); }

.tp-approval {
  @apply flex flex-wrap items-center justify-between; gap: var(--space-3);
  padding: var(--space-4) var(--space-5);
  background: var(--color-warning-subtle);
  border-bottom: 1px solid var(--color-border);
}
.tp-approval-text { font-size: var(--text-sm-size); color: var(--color-warning-text); margin: 0; }
.tp-approval-actions { @apply flex; gap: var(--space-2); }
.tp-sent-back {
  font-size: var(--text-sm-size); color: var(--color-danger-text); margin: 0;
  padding: var(--space-3) var(--space-5); background: var(--color-danger-subtle);
  border-bottom: 1px solid var(--color-border);
}
.tp-complete:disabled { @apply cursor-not-allowed opacity-50; }
.tp-title { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); margin: 0; }
.tp-byline { @apply flex flex-wrap items-center; gap: var(--space-1); margin: 0; font-size: var(--text-xs-size); color: var(--color-text-muted); }

.tp-facts { @apply grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: var(--space-4); padding: var(--space-5); border-bottom: 1px solid var(--color-border); }
.tp-fact { @apply flex flex-col; gap: var(--space-1); }
.tp-fact-key { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tp-fact-value { font-size: var(--text-sm-size); color: var(--color-text); }
.tp-fact-value.late { color: var(--color-danger-text); font-weight: 500; }
.tp-fact-edit { @apply justify-end; }

.tp-flag { @apply inline-flex items-center; gap: 4px; font-size: var(--text-sm-size); }
.tp-flag svg { width: 0.9rem; height: 0.9rem; }
.tp-flag.high   { color: var(--color-danger-text); }
.tp-flag.medium { color: var(--color-warning-text); }
.tp-flag.low    { color: var(--color-text-muted); }

.tp-stack { @apply inline-flex items-center; }
.tp-avatar {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 1.6rem; height: 1.6rem; border-radius: 999px; margin-right: -0.4rem;
  border: 2px solid var(--color-surface-raised);
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: 0.55rem; font-weight: 600;
}
.tp-avatar.sm { width: 1.25rem; height: 1.25rem; margin-right: 0; font-size: 0.5rem; }
.tp-avatar.more { background: var(--color-brand-subtle); color: var(--color-brand-on-surface); }
.tp-unowned { font-size: var(--text-xs-size); color: var(--color-warning-text); }

.tp-tabs { @apply flex; gap: var(--space-5); padding: 0 var(--space-5); border-bottom: 1px solid var(--color-border); overflow-x: auto; }
.tp-tab {
  @apply inline-flex shrink-0 cursor-pointer items-center border-none bg-transparent;
  gap: var(--space-1); padding: var(--space-3) 0; margin-bottom: -1px;
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent; white-space: nowrap;
}
.tp-tab.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }
.tp-tab-badge {
  @apply inline-flex items-center justify-center; min-width: 1rem; height: 1rem;
  padding: 0 3px; border-radius: 999px; font-size: 0.6rem; font-weight: 600;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
}
.tp-tab.active .tp-tab-badge { background: var(--color-brand-subtle); color: var(--color-brand-on-surface); }

.tp-body { padding: var(--space-5); min-height: 18rem; }
.tp-section { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-3); }
.tp-section-row { @apply flex flex-wrap items-center justify-between; gap: var(--space-2); }
.tp-section-row .tp-section { margin-bottom: var(--space-3); }
.tp-text { font-size: var(--text-sm-size); color: var(--color-text); white-space: pre-wrap; margin: 0 0 var(--space-4); }
.tp-muted { color: var(--color-text-light); }
.tp-quiet { font-size: var(--text-sm-size); margin: 0 0 var(--space-3); }
.tp-hint { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0 0 var(--space-3); }
.tp-nowrap { white-space: nowrap; }

.tp-list { @apply flex flex-col; gap: var(--space-2); list-style: none; margin: 0; padding: 0; }
.tp-asks { margin-top: var(--space-3); }
.tp-person { @apply flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }
.tp-tag { padding: 1px var(--space-2); border-radius: var(--radius-sm); background: var(--color-surface-sunken); color: var(--color-text-muted); font-size: var(--text-xs-size); white-space: nowrap; }
.tp-activity { font-size: var(--text-sm-size); color: var(--color-text); padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border); }

.tp-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.tp-table th { text-align: left; padding: var(--space-2) var(--space-3); font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); background: var(--color-surface-sunken); }
.tp-table td { padding: var(--space-3); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.tp-table tr:last-child td { border-bottom: none; }

.tp-files { @apply flex flex-col; gap: var(--space-2); list-style: none; margin: 0 0 var(--space-3); padding: 0; }
.tp-file { @apply flex items-center; gap: var(--space-3); padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.tp-file-mark { @apply inline-flex shrink-0 items-center justify-center; width: 2rem; height: 2rem; border-radius: var(--radius-sm); background: var(--color-danger-subtle); color: var(--color-danger-text); font-size: 0.55rem; font-weight: 700; }
.tp-file-body { @apply flex flex-col; }
.tp-file-size { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tp-upload { @apply inline-block cursor-pointer; }

.tp-req { @apply flex flex-wrap items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }
.tp-req-mark { color: var(--color-text-light); font-weight: 600; }
.tp-req-mark.done { color: var(--color-success-text); }
.tp-req-name { flex: 1 1 auto; }

.tp-ask { @apply flex flex-col; gap: var(--space-1); padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.tp-ask-head { @apply flex flex-wrap items-center; gap: var(--space-2); }
.tp-ask-meta { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; }
.tp-ask-note { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }
.tp-reply { color: var(--color-text-muted); padding-left: var(--space-3); border-left: 2px solid var(--color-border); }

.tp-comment { @apply relative flex flex-col; gap: var(--space-1); padding: var(--space-3) 0; border-bottom: 1px solid var(--color-border); }
.tp-nested { margin-left: var(--space-6); border-bottom: none; padding-bottom: 0; }
.tp-comment-head { @apply flex flex-wrap items-center; gap: var(--space-2); }
.tp-comment-who { font-size: var(--text-sm-size); color: var(--color-text); }
.tp-comment-when { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tp-comment-body { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; white-space: pre-wrap; }
.tp-kebab { @apply ml-auto cursor-pointer border-none bg-transparent p-0; color: var(--color-text-muted); font-size: 1.1rem; line-height: 1; }
.tp-menu {
  @apply absolute right-0 z-10 flex flex-col; top: var(--space-6); min-width: 12rem;
  border: 1px solid var(--color-border); border-radius: var(--radius-md);
  background: var(--color-surface-raised); box-shadow: var(--shadow-lg); padding: var(--space-1);
}
.tp-menu button { @apply cursor-pointer border-none bg-transparent; padding: 6px var(--space-3); font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text); text-align: left; border-radius: var(--radius-sm); }
.tp-menu button:hover { background: var(--color-surface-sunken); }

.tp-reactions { @apply flex flex-wrap; gap: var(--space-1); }
.tp-reaction { @apply cursor-pointer; padding: 1px var(--space-2); border-radius: 999px; border: 1px solid var(--color-border); background: var(--color-surface); font-family: inherit; font-size: var(--text-xs-size); color: var(--color-text); }

.tp-reply-box, .tp-compose { @apply flex flex-col; gap: var(--space-2); margin-top: var(--space-3); }
.tp-reply-actions { @apply flex justify-end; gap: var(--space-2); }

.tp-issue { @apply flex flex-col; gap: var(--space-1); padding: var(--space-4); margin-bottom: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.tp-issue-head { @apply flex flex-wrap items-center; gap: var(--space-2); }
.tp-issue-toggle { @apply ml-auto; }
.tp-issue-name { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.tp-escalations { @apply flex flex-col; gap: var(--space-1); list-style: none; margin: var(--space-2) 0 0; padding: 0 0 0 var(--space-3); border-left: 2px solid var(--color-warning-subtle); font-size: var(--text-xs-size); color: var(--color-text-muted); }

.tp-field { @apply flex flex-col; gap: var(--space-1); }
.tp-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tp-row { @apply grid; grid-template-columns: 1fr 1fr; gap: var(--space-3); }
.tp-kinds { @apply flex flex-col; gap: var(--space-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-3); }
.tp-kinds legend { font-size: var(--text-xs-size); color: var(--color-text-muted); padding: 0 var(--space-1); }
.tp-check { @apply flex cursor-pointer items-start; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }

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
.link-btn:disabled { @apply cursor-not-allowed no-underline opacity-40; }

.tp-modal-backdrop { @apply fixed inset-0 flex items-center justify-center z-50; background: rgb(0 0 0 / 0.32); padding: var(--space-4); }
.tp-modal {
  @apply flex flex-col overflow-y-auto; width: min(24rem, 100%); max-height: 90vh; gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised);
  border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
}
.tp-modal-sm { width: min(20rem, 100%); text-align: center; }
.tp-modal-head { @apply flex items-center justify-between; }
.tp-modal-title { font-size: var(--text-lg-size); font-weight: 600; margin: 0; color: var(--color-text); }
.tp-modal-actions { @apply flex items-center justify-between; }
.tp-danger-mark { @apply inline-flex items-center justify-center; width: 2.5rem; height: 2.5rem; border-radius: 999px; background: var(--color-danger-subtle); }
.tp-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

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
.btn-danger {
  @apply inline-flex cursor-pointer items-center justify-center border-none;
  padding: 8px var(--space-5); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500;
  background: var(--color-danger-text); color: #fff;
}
.btn-sm { padding: 5px var(--space-3); font-size: var(--text-xs-size); }
</style>
