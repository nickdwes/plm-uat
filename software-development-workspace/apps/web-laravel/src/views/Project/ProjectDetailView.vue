<script setup lang="ts">
/**
 * One project.
 *
 * The design's four tabs plus Milestones. That fifth one is an addition, not
 * something the mockups show — the legacy's 82 contract milestones had to live
 * somewhere, and a project is where they attach. If it is unwanted it is one
 * tab to remove, but hiding what the customer agreed to pay is worse.
 *
 * The task drawer is where most of the work happens: sub-tasks, dependencies,
 * assignees, assisting, resources. It opens over the list rather than
 * navigating, so the list stays as context.
 */
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import EmployeePicker from "../../components/EmployeePicker.vue";

const props = defineProps<{ id: string | number }>();

interface Person { id: number | null; name: string | null }
interface Member { id: number; employee: Person }
interface Doc {
  id: number; label: string; document_type: string; original_name: string;
  size_bytes: number | null; project_task_id: number | null; uploaded_at: string | null;
}
interface Comment {
  id: number; body: string; author: Person; created_at: string | null; replies: Comment[];
}
interface Task {
  id: number; reference: string | null; title: string; description: string | null;
  status: string; priority: string; deadline: string | null; completed_at: string | null;
  position: number; parent_task_id: number | null;
  is_done: boolean; is_overdue: boolean; is_blocked: boolean;
  assignees: Member[]; assisting: Member[];
  dependencies: { id: number; title: string; status: string }[];
  subtasks: Task[]; attachments: Doc[]; comments: Comment[];
}
interface Milestone {
  id: number; reference: string | null; name: string; status: string;
  currency: string | null; value: string | null;
  due_on: string | null; invoiced_at: string | null; paid_at: string | null;
  invoice_reference: string | null;
  is_paid: boolean; is_outstanding: boolean; is_overdue: boolean;
  work_is_done: boolean | null;
  task: { id: number; title: string; status: string } | null;
}
interface Activity { id: number; type: string; body: string | null; occurred_at: string | null; actor: Person }
interface Project {
  id: number; reference: string | null; name: string; description: string | null;
  status: string; priority: string; deadline: string | null;
  progress: number | null; is_overdue: boolean; was_late: boolean | null;
  account?: { id: number; name: string } | null;
  opportunity?: { id: number; reference: string | null; name: string } | null;
  project_lead?: Person | null;
  team?: { id: number; employee: Person; role: string | null }[];
  delivery?: {
    vendor_partner?: { id: number; name: string } | null;
    days_own: string | null; days_oem: string | null; total_days: number | null;
    oem_share: number | null; oem_share_drift: number | null; oem_ps_cost: string | null;
  };
  created_at: string | null;
}

type Tab = "details" | "tasks" | "milestones" | "comments" | "activities";

const router  = useRouter();
const project = ref<Project | null>(null);
const tasks   = ref<Task[]>([]);
const milestones = ref<Milestone[]>([]);
const milestoneTotals = ref<Record<string, Record<string, number>> | null>(null);
const commercialsVisible = ref(true);
const comments   = ref<Comment[]>([]);
const activities = ref<Activity[]>([]);

const tab     = ref<Tab>("details");
const loading = ref(true);
const saving  = ref(false);

const org  = () => currentOrgSlug.value;
const base = computed(() => `/api/v1/projects/${props.id}`);

const tabs: { key: Tab; label: string }[] = [
  { key: "details",    label: "Details" },
  { key: "tasks",      label: "Tasks" },
  { key: "milestones", label: "Milestones" },
  { key: "comments",   label: "Comments" },
  { key: "activities", label: "Activities" },
];

function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

async function load() {
  loading.value = true;
  try {
    project.value = (await api<{ data: Project }>("GET", base.value, { org: org() })).data;
    form.value.description = project.value.description ?? "";
  } catch (e) { fail(e, "Could not load this project."); }
  finally { loading.value = false; }
}

async function loadTasks() {
  try {
    tasks.value = (await api<{ data: Task[] }>("GET", `${base.value}/tasks`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load tasks."); }
}

async function loadMilestones() {
  try {
    const res = await api<{ data: Milestone[]; totals: typeof milestoneTotals.value; commercials_visible: boolean }>(
      "GET", `${base.value}/milestones`, { org: org() });
    milestones.value = res.data;
    milestoneTotals.value = res.totals;
    commercialsVisible.value = res.commercials_visible;
  } catch (e) { fail(e, "Could not load milestones."); }
}

async function loadComments() {
  try {
    comments.value = (await api<{ data: Comment[] }>("GET", `${base.value}/comments`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load comments."); }
}

async function loadActivities() {
  try {
    activities.value = (await api<{ data: Activity[] }>("GET", `${base.value}/activities`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load the timeline."); }
}

watch(tab, (t) => {
  if (t === "tasks" && !tasks.value.length) void loadTasks();
  if (t === "milestones" && !milestones.value.length) void loadMilestones();
  if (t === "comments" && !comments.value.length) void loadComments();
  if (t === "activities" && !activities.value.length) void loadActivities();
});

// ── Details ─────────────────────────────────────────────────────────────────

const form = ref({ description: "" });

async function saveDetails() {
  if (!project.value) return;
  saving.value = true;
  try {
    project.value = (await api<{ data: Project }>("PUT", base.value, {
      org: org(),
      body: {
        name: project.value.name, description: form.value.description || null,
        status: project.value.status, priority: project.value.priority,
        deadline: project.value.deadline,
      },
    })).data;
    notifySaved("Saved.");
  } catch (e) { fail(e, "Could not save."); }
  finally { saving.value = false; }
}

// ── Tasks ───────────────────────────────────────────────────────────────────

const openTask = ref<Task | null>(null);
const blankTask = () => ({
  id: null as number | null, title: "", description: "",
  status: "todo", priority: "medium", deadline: "", parent_task_id: null as number | null,
});
const taskForm = ref(blankTask());
const showTask = ref(false);

function newTask(parentId: number | null = null) {
  taskForm.value = { ...blankTask(), parent_task_id: parentId };
  openTask.value = null;
  showTask.value = true;
}

async function saveTask() {
  if (!taskForm.value.title.trim()) return;
  saving.value = true;
  const { id, ...raw } = taskForm.value;
  const body = { ...raw, deadline: raw.deadline || null, description: raw.description || null };
  try {
    const res = await api<{ data: Task }>(
      id ? "PUT" : "POST", id ? `${base.value}/tasks/${id}` : `${base.value}/tasks`,
      { org: org(), body },
    );
    showTask.value = false;
    await Promise.all([loadTasks(), load()]);
    openTask.value = res.data;
    notifySaved(id ? "Task updated." : "Task added.");
  } catch (e) { fail(e, "Could not save the task."); }
  finally { saving.value = false; }
}

/** Ticking the box is one gesture, so it goes through the same update. */
async function toggleDone(t: Task) {
  try {
    await api("PUT", `${base.value}/tasks/${t.id}`, {
      org: org(),
      body: { title: t.title, status: t.is_done ? "in_progress" : "completed" },
    });
    await Promise.all([loadTasks(), load()]);
    if (openTask.value?.id === t.id) void reopenDrawer(t.id);
  } catch (e) { fail(e, "Could not change the task."); }
}

async function reopenDrawer(id: number) {
  try {
    openTask.value = (await api<{ data: Task }>("GET", `${base.value}/tasks/${id}`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load the task."); }
}

function editTask(t: Task) {
  taskForm.value = {
    id: t.id, title: t.title, description: t.description ?? "",
    status: t.status, priority: t.priority, deadline: t.deadline ?? "",
    parent_task_id: t.parent_task_id,
  };
  showTask.value = true;
}

// ── Task members and dependencies ───────────────────────────────────────────

const newMember = ref({ employee_id: null as number | null, capacity: "assignee" });

async function addMember() {
  if (!openTask.value || !newMember.value.employee_id) return;
  saving.value = true;
  try {
    openTask.value = (await api<{ data: Task }>("POST", `${base.value}/tasks/${openTask.value.id}/members`, {
      org: org(), body: { ...newMember.value },
    })).data;
    newMember.value = { employee_id: null, capacity: "assignee" };
    await loadTasks();
  } catch (e) { fail(e, "Could not add them."); }
  finally { saving.value = false; }
}

async function removeMember(m: Member) {
  if (!openTask.value) return;
  try {
    openTask.value = (await api<{ data: Task }>(
      "DELETE", `${base.value}/tasks/${openTask.value.id}/members/${m.id}`, { org: org() })).data;
    await loadTasks();
  } catch (e) { fail(e, "Could not remove them."); }
}

const newDependency = ref<number | null>(null);

/** Everything else on the project, flattened — a task can wait on a sub-task. */
const dependencyOptions = computed(() => {
  const flat: Task[] = [];
  const walk = (list: Task[]) => list.forEach((t) => { flat.push(t); walk(t.subtasks ?? []); });
  walk(tasks.value);
  return flat.filter((t) => t.id !== openTask.value?.id);
});

async function addDependency() {
  if (!openTask.value || !newDependency.value) return;
  saving.value = true;
  try {
    openTask.value = (await api<{ data: Task }>("POST", `${base.value}/tasks/${openTask.value.id}/dependencies`, {
      org: org(), body: { depends_on_task_id: newDependency.value },
    })).data;
    newDependency.value = null;
  } catch (e) { fail(e, "Could not add that dependency."); }
  finally { saving.value = false; }
}

async function removeDependency(id: number) {
  if (!openTask.value) return;
  try {
    openTask.value = (await api<{ data: Task }>(
      "DELETE", `${base.value}/tasks/${openTask.value.id}/dependencies/${id}`, { org: org() })).data;
  } catch (e) { fail(e, "Could not remove that dependency."); }
}

// ── Milestones ──────────────────────────────────────────────────────────────

const showMilestone = ref(false);
const blankMilestone = () => ({
  name: "", value: null as number | null, currency: "",
  due_on: "", project_task_id: null as number | null, description: "",
});
const milestoneForm = ref(blankMilestone());

async function saveMilestone() {
  if (!milestoneForm.value.name.trim() || milestoneForm.value.value === null) return;
  saving.value = true;
  try {
    await api("POST", `${base.value}/milestones`, {
      org: org(),
      body: {
        ...milestoneForm.value,
        currency: milestoneForm.value.currency || undefined,
        due_on: milestoneForm.value.due_on || null,
        description: milestoneForm.value.description || null,
      },
    });
    showMilestone.value = false;
    milestoneForm.value = blankMilestone();
    await loadMilestones();
    notifySaved("Milestone added.");
  } catch (e) { fail(e, "Could not add the milestone."); }
  finally { saving.value = false; }
}

async function transition(m: Milestone, status: string) {
  try {
    await api("POST", `${base.value}/milestones/${m.id}/transition`, { org: org(), body: { status } });
    await loadMilestones();
    notifySaved(`Marked ${status}.`);
  } catch (e) { fail(e, "Could not update the milestone."); }
}

// ── Comments and resources ──────────────────────────────────────────────────

const newComment = ref("");
const replyTo    = ref<Comment | null>(null);

async function postComment() {
  if (!newComment.value.trim()) return;
  saving.value = true;
  try {
    comments.value = (await api<{ data: Comment[] }>("POST", `${base.value}/comments`, {
      org: org(),
      body: { body: newComment.value, parent_id: replyTo.value?.id ?? null },
    })).data;
    newComment.value = "";
    replyTo.value = null;
  } catch (e) { fail(e, "Could not post that."); }
  finally { saving.value = false; }
}

const uploading = ref(false);

async function upload(event: Event) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;

  uploading.value = true;
  try {
    const body = new FormData();
    body.append("file", file);
    if (openTask.value) body.append("project_task_id", String(openTask.value.id));

    await api("POST", `${base.value}/attachments`, { org: org(), body });
    if (openTask.value) await reopenDrawer(openTask.value.id);
    notifySaved("Resource attached.");
  } catch (e) { fail(e, "Could not attach the resource."); }
  finally { uploading.value = false; input.value = ""; }
}

function downloadUrl(d: Doc): string {
  const root = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");
  return `${root}${base.value}/attachments/${d.id}/download?org=${org()}`;
}

// ── Presentation ────────────────────────────────────────────────────────────

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

const titleCase = (v: string | null) =>
  v ? v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase()) : "—";

const initials = (name: string | null | undefined) =>
  (name ?? "?").split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

const kb = (b: number | null) => (b === null ? "" : `${Math.round(b / 1024)} KB`);

function money(m: Milestone): string {
  if (!commercialsVisible.value) return "•••";
  return m.value === null ? "—" : `${m.currency} ${Number(m.value).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

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

const statusTone = (s: string) =>
  s === "completed" ? "ok" : s === "awaiting_approval" ? "warn" : s === "blocked" ? "danger" : "info";

onMounted(load);
</script>

<template>
  <div class="pd-wrap">
    <nav class="pd-crumbs">
      <button class="pd-crumb" @click="router.push({ name: 'projects' })">Projects</button>
      <span class="pd-sep">›</span>
      <span class="pd-current">{{ project?.reference ?? "…" }}</span>
    </nav>

    <div v-if="loading" class="pd-loading">Loading…</div>

    <template v-else-if="project">
      <header class="pd-head">
        <div>
          <h1 class="pd-title">{{ project.name }}</h1>
          <p class="pd-byline">
            Created {{ date(project.created_at) }}
            <template v-if="project.account"> · for {{ project.account.name }}</template>
          </p>
        </div>
        <div v-if="project.progress !== null" class="pd-progress">
          <span class="pd-progress-value">{{ project.progress }}%</span>
          <span class="pd-progress-label">complete</span>
        </div>
      </header>

      <div class="pd-facts-row">
        <dl class="pd-facts">
          <div><dt>Deadline</dt>
            <dd :class="project.is_overdue ? 'pd-late' : ''">
              📅 {{ date(project.deadline) }}
              <span v-if="project.is_overdue" class="pd-late">· overdue</span>
              <span v-else-if="project.was_late" class="pd-late">· delivered late</span>
            </dd></div>
          <div><dt>Status</dt><dd class="pd-status">{{ titleCase(project.status) }}</dd></div>
          <div><dt>Priority</dt>
            <dd :class="project.priority === 'high' ? 'pd-late' : ''">⚑ {{ titleCase(project.priority) }} priority</dd></div>
        </dl>

        <dl class="pd-facts">
          <div><dt>Project Lead</dt><dd>{{ project.project_lead?.name ?? "—" }}</dd></div>
          <div><dt>Assignees</dt>
            <dd>
              <span v-if="!project.team?.length" class="pd-muted">Nobody yet</span>
              <span v-else class="pd-stack">
                <span v-for="m in project.team.slice(0, 5)" :key="m.id" class="pd-avatar"
                      :title="m.employee.name ?? ''">{{ initials(m.employee.name) }}</span>
              </span>
            </dd></div>
          <div v-if="project.delivery && project.delivery.oem_share !== null"><dt>Delivery split</dt>
            <dd>
              {{ 100 - project.delivery.oem_share }}% us ·
              {{ project.delivery.oem_share }}% {{ project.delivery.vendor_partner?.name ?? "OEM" }}
              <!-- Delivered against scoped. A deal quoted at 20% OEM and
                   delivered at 60% either mis-scoped or lost people, and both
                   matter before the next quote. -->
              <span v-if="project.delivery.oem_share_drift" class="pd-drift"
                    :title="`${Math.abs(project.delivery.oem_share_drift)} points ${project.delivery.oem_share_drift > 0 ? 'more' : 'less'} OEM than scoped`">
                {{ project.delivery.oem_share_drift > 0 ? "+" : "" }}{{ project.delivery.oem_share_drift }} pts vs plan
              </span>
            </dd></div>
          <div v-if="project.opportunity"><dt>From deal</dt>
            <dd><button class="link-btn"
                        @click="router.push({ name: 'opportunity', params: { id: project.opportunity.id } })">
              {{ project.opportunity.reference }}</button></dd></div>
        </dl>
      </div>

      <nav class="pd-tabs" role="tablist">
        <button v-for="t in tabs" :key="t.key" role="tab" :aria-selected="tab === t.key"
                :class="['pd-tab', { active: tab === t.key }]" @click="tab = t.key">{{ t.label }}</button>
      </nav>

      <!-- ── Details ─────────────────────────────────────────────────── -->
      <section v-if="tab === 'details'" class="pd-panel">
        <div class="pd-card">
          <h3 class="pd-h3">Description</h3>
          <textarea v-model="form.description" class="control" rows="14"
                    placeholder="Add the project details — overview, scope, objectives."></textarea>
          <div class="pd-actions">
            <button class="btn-primary" :disabled="saving" @click="saveDetails">
              {{ saving ? "Saving…" : "Save" }}
            </button>
          </div>
        </div>
      </section>

      <!-- ── Tasks ───────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'tasks'" class="pd-panel">
        <div class="pd-panel-actions">
          <button class="btn-primary" @click="newTask()">+ Add Task</button>
        </div>

        <p v-if="!tasks.length" class="pd-empty">
          Nothing planned yet. Until there is, the project has no progress to report —
          which is different from being at zero.
        </p>

        <div v-else class="pd-card pd-table-card">
          <table class="pd-table">
            <thead><tr><th></th><th>Task</th><th>Status</th><th>Assignee</th><th>Due Date</th><th>Priority</th><th></th></tr></thead>
            <tbody>
              <template v-for="t in tasks" :key="t.id">
                <tr :class="{ done: t.is_done }">
                  <td class="pd-tick">
                    <input type="checkbox" :checked="t.is_done" @change="toggleDone(t)" />
                  </td>
                  <td>
                    <span class="pd-task-title">{{ t.title }}</span>
                    <!-- Reported, not enforced: refusing to start a blocked
                         task gets worked around by deleting the dependency. -->
                    <span v-if="t.is_blocked" class="pd-flag" title="Waiting on another task">blocked</span>
                    <span v-if="t.subtasks.length" class="pd-sub">{{ t.subtasks.length }} sub-task{{ t.subtasks.length === 1 ? "" : "s" }}</span>
                  </td>
                  <td><span :class="['pill', `pill-${statusTone(t.status)}`]">{{ titleCase(t.status) }}</span></td>
                  <td>
                    <span v-if="!t.assignees.length" class="pd-muted">—</span>
                    <span v-else class="pd-stack">
                      <span v-for="a in t.assignees.slice(0, 3)" :key="a.id" class="pd-avatar"
                            :title="a.employee.name ?? ''">{{ initials(a.employee.name) }}</span>
                    </span>
                  </td>
                  <td :class="['pd-nowrap', { 'pd-late': t.is_overdue }]">{{ date(t.deadline) }}</td>
                  <td><span :class="['pd-priority', `prio-${t.priority}`]">⚑ {{ titleCase(t.priority) }}</span></td>
                  <td><button class="link-btn" @click="reopenDrawer(t.id)">View</button></td>
                </tr>
                <tr v-for="s in t.subtasks" :key="s.id" :class="['pd-subrow', { done: s.is_done }]">
                  <td class="pd-tick"><input type="checkbox" :checked="s.is_done" @change="toggleDone(s)" /></td>
                  <td><span class="pd-subtask">↳ {{ s.title }}</span></td>
                  <td><span :class="['pill', `pill-${statusTone(s.status)}`]">{{ titleCase(s.status) }}</span></td>
                  <td>
                    <span v-if="!s.assignees.length" class="pd-muted">—</span>
                    <span v-else class="pd-stack">
                      <span v-for="a in s.assignees.slice(0, 3)" :key="a.id" class="pd-avatar">{{ initials(a.employee.name) }}</span>
                    </span>
                  </td>
                  <td :class="['pd-nowrap', { 'pd-late': s.is_overdue }]">{{ date(s.deadline) }}</td>
                  <td><span :class="['pd-priority', `prio-${s.priority}`]">⚑ {{ titleCase(s.priority) }}</span></td>
                  <td><button class="link-btn" @click="reopenDrawer(s.id)">View</button></td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── Milestones ──────────────────────────────────────────────── -->
      <section v-else-if="tab === 'milestones'" class="pd-panel">
        <div class="pd-panel-actions">
          <button class="btn-primary" @click="milestoneForm = blankMilestone(); showMilestone = true">
            + Add Milestone
          </button>
        </div>

        <!-- Totals per currency, never summed across: adding KES to USD states
             a figure that means nothing. -->
        <div v-if="milestoneTotals" class="pd-totals">
          <div v-for="(t, ccy) in milestoneTotals" :key="ccy" class="pd-total">
            <span class="pd-total-ccy">{{ ccy }}</span>
            <span class="pd-total-item">agreed <strong>{{ t.agreed.toLocaleString() }}</strong></span>
            <span class="pd-total-item">paid <strong>{{ t.paid.toLocaleString() }}</strong></span>
            <span class="pd-total-item outstanding">outstanding <strong>{{ t.outstanding.toLocaleString() }}</strong></span>
          </div>
        </div>
        <p v-else-if="!commercialsVisible" class="pd-masked">
          Milestone values are hidden. They need the commercial-data permission.
        </p>

        <p v-if="!milestones.length" class="pd-empty">Nothing agreed yet.</p>

        <div v-else class="pd-card pd-table-card">
          <table class="pd-table">
            <thead><tr><th>ID</th><th>Milestone</th><th>Against</th><th class="pd-num">Value</th>
              <th>Due</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="m in milestones" :key="m.id">
                <td class="pd-ref">{{ m.reference ?? "—" }}</td>
                <td class="pd-task-title">{{ m.name }}</td>
                <td>
                  <span v-if="!m.task" class="pd-muted">Not attached</span>
                  <span v-else>
                    {{ m.task.title }}
                    <!-- Billing for undelivered work is a conversation nobody
                         wants to have with the customer first. -->
                    <span v-if="m.work_is_done === false" class="pd-flag" title="The work is not finished">work open</span>
                  </span>
                </td>
                <td class="pd-num">{{ money(m) }}</td>
                <td :class="['pd-nowrap', { 'pd-late': m.is_overdue }]">{{ date(m.due_on) }}</td>
                <td>
                  <span :class="['pill', m.is_paid ? 'pill-ok' : m.is_overdue ? 'pill-danger' : m.is_outstanding ? 'pill-warn' : 'pill-neutral']">
                    {{ titleCase(m.status) }}
                  </span>
                </td>
                <td class="pd-row-actions">
                  <button v-if="m.status === 'pending'" class="link-btn" @click="transition(m, 'invoiced')">Invoice</button>
                  <button v-if="m.status === 'invoiced'" class="link-btn" @click="transition(m, 'paid')">Mark paid</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── Comments ────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'comments'" class="pd-panel">
        <div class="pd-card">
          <p v-if="replyTo" class="pd-replying">
            Replying to {{ replyTo.author.name }}
            <button class="link-btn" @click="replyTo = null">cancel</button>
          </p>
          <textarea v-model="newComment" class="control" rows="3"
                    placeholder="Add a comment…" @keyup.ctrl.enter="postComment"></textarea>
          <div class="pd-actions">
            <button class="btn-primary" :disabled="saving || !newComment.trim()" @click="postComment">Post</button>
          </div>
        </div>

        <p v-if="!comments.length" class="pd-empty">Nothing said yet.</p>

        <ul v-else class="pd-comments">
          <li v-for="c in comments" :key="c.id" class="pd-comment">
            <div class="pd-comment-row">
              <span class="pd-avatar">{{ initials(c.author.name) }}</span>
              <div class="pd-comment-body">
                <p class="pd-comment-who">
                  <strong>{{ c.author.name ?? "Somebody" }}</strong>
                  <span class="pd-comment-when">{{ ago(c.created_at) }}</span>
                </p>
                <p class="pd-comment-text">{{ c.body }}</p>
                <button class="link-btn pd-reply" @click="replyTo = c">Reply</button>
              </div>
            </div>

            <ul v-if="c.replies.length" class="pd-replies">
              <li v-for="r in c.replies" :key="r.id" class="pd-comment-row">
                <span class="pd-avatar">{{ initials(r.author.name) }}</span>
                <div class="pd-comment-body">
                  <p class="pd-comment-who">
                    <strong>{{ r.author.name ?? "Somebody" }}</strong>
                    <span class="pd-comment-when">{{ ago(r.created_at) }}</span>
                  </p>
                  <p class="pd-comment-text">{{ r.body }}</p>
                </div>
              </li>
            </ul>
          </li>
        </ul>
      </section>

      <!-- ── Activities ──────────────────────────────────────────────── -->
      <section v-else class="pd-panel">
        <p v-if="!activities.length" class="pd-empty">
          Nothing recorded yet. The timeline fills itself as tasks are added and
          finished — nobody has to remember to write it.
        </p>

        <ul v-else class="pd-feed">
          <li v-for="a in activities" :key="a.id" class="pd-event">
            <span class="pd-avatar">{{ initials(a.actor.name) }}</span>
            <div>
              <p class="pd-event-who">
                <strong>{{ a.actor.name ?? "System" }}</strong>
                <span class="pd-comment-when">{{ ago(a.occurred_at) }}</span>
              </p>
              <p class="pd-comment-text">{{ a.body }}</p>
            </div>
          </li>
        </ul>
      </section>
    </template>

    <!-- ── Task drawer ───────────────────────────────────────────────── -->
    <div v-if="openTask" class="pd-sheet-backdrop" @click.self="openTask = null">
      <aside class="pd-sheet pd-sheet-wide" role="dialog" aria-label="Task">
        <div class="pd-sheet-head">
          <h3 class="pd-sheet-title">{{ openTask.title }}</h3>
          <button class="pd-close" aria-label="Close" @click="openTask = null">×</button>
        </div>

        <dl class="pd-mini">
          <div><dt>Status</dt><dd><span :class="['pill', `pill-${statusTone(openTask.status)}`]">{{ titleCase(openTask.status) }}</span></dd></div>
          <div><dt>Deadline</dt><dd>{{ date(openTask.deadline) }}</dd></div>
          <div><dt>Priority</dt><dd>{{ titleCase(openTask.priority) }}</dd></div>
          <div v-if="openTask.parent_task_id"><dt>Parent</dt><dd>Sub-task</dd></div>
        </dl>

        <p v-if="openTask.description" class="pd-prose">{{ openTask.description }}</p>

        <!-- Sub-tasks -->
        <div class="pd-block">
          <div class="pd-block-head">
            <h4 class="pd-h4">Sub-tasks</h4>
            <button class="link-btn" @click="newTask(openTask!.id)">+ Add</button>
          </div>
          <p v-if="!openTask.subtasks.length" class="pd-muted">None.</p>
          <ul v-else class="pd-list">
            <li v-for="s in openTask.subtasks" :key="s.id">
              <input type="checkbox" :checked="s.is_done" @change="toggleDone(s)" />
              <span :class="{ struck: s.is_done }">{{ s.title }}</span>
            </li>
          </ul>
        </div>

        <!-- Dependencies -->
        <div class="pd-block">
          <h4 class="pd-h4">Dependencies</h4>
          <p class="pd-hint">
            What has to finish first. Shown rather than enforced — a blocked task you
            cannot start just gets its dependency deleted.
          </p>
          <ul v-if="openTask.dependencies.length" class="pd-list">
            <li v-for="d in openTask.dependencies" :key="d.id">
              <span :class="d.status === 'completed' ? 'pd-done-dep' : ''">{{ d.title }}</span>
              <button class="link-btn link-danger" @click="removeDependency(d.id)">×</button>
            </li>
          </ul>
          <div class="pd-add-row">
            <select v-model.number="newDependency" class="control">
              <option :value="null">Waits on…</option>
              <option v-for="o in dependencyOptions" :key="o.id" :value="o.id">{{ o.title }}</option>
            </select>
            <button class="btn-ghost" :disabled="saving || !newDependency" @click="addDependency">Add</button>
          </div>
        </div>

        <!-- People -->
        <div class="pd-block">
          <h4 class="pd-h4">Assignees and assisting</h4>
          <div class="pd-people">
            <span v-for="a in openTask.assignees" :key="a.id" class="pd-person-chip">
              {{ a.employee.name }}<button class="link-btn link-danger" @click="removeMember(a)">×</button>
            </span>
            <span v-for="a in openTask.assisting" :key="a.id" class="pd-person-chip assisting">
              {{ a.employee.name }} · assisting<button class="link-btn link-danger" @click="removeMember(a)">×</button>
            </span>
          </div>
          <div class="pd-add-row">
            <div class="pd-add-picker">
              <EmployeePicker v-model="newMember.employee_id" placeholder="Add somebody…" />
            </div>
            <select v-model="newMember.capacity" class="control pd-capacity">
              <option value="assignee">Assignee</option>
              <option value="assisting">Assisting</option>
            </select>
            <button class="btn-ghost" :disabled="saving || !newMember.employee_id" @click="addMember">Add</button>
          </div>
        </div>

        <!-- Resources -->
        <div class="pd-block">
          <div class="pd-block-head">
            <h4 class="pd-h4">Resources</h4>
            <label class="link-btn pd-upload">
              {{ uploading ? "Uploading…" : "+ Add" }}
              <input type="file" hidden :disabled="uploading" @change="upload" />
            </label>
          </div>
          <p v-if="!openTask.attachments.length" class="pd-muted">Nothing attached.</p>
          <ul v-else class="pd-list">
            <li v-for="d in openTask.attachments" :key="d.id">
              <a :href="downloadUrl(d)" class="link-btn">{{ d.label }}</a>
              <span class="pd-muted">{{ kb(d.size_bytes) }}</span>
            </li>
          </ul>
        </div>

        <div class="pd-sheet-actions">
          <button class="btn-ghost" @click="editTask(openTask!)">Edit</button>
          <button class="btn-primary" @click="openTask = null">Close</button>
        </div>
      </aside>
    </div>

    <!-- Task form -->
    <div v-if="showTask" class="pd-sheet-backdrop" @click.self="showTask = false">
      <aside class="pd-sheet" role="dialog" aria-label="Task form">
        <div class="pd-sheet-head">
          <h3 class="pd-sheet-title">{{ taskForm.id ? "Edit task" : "Add new task" }}</h3>
          <button class="pd-close" aria-label="Close" @click="showTask = false">×</button>
        </div>

        <label class="pd-field"><span>Task Title</span>
          <input v-model="taskForm.title" class="control" placeholder="Prototype the screens" /></label>

        <label class="pd-field"><span>Deadline</span>
          <input v-model="taskForm.deadline" class="control" type="date" /></label>

        <label class="pd-field"><span>Status</span>
          <select v-model="taskForm.status" class="control">
            <option value="todo">To Do</option>
            <option value="in_progress">In Progress</option>
            <option value="awaiting_approval">Awaiting Approval</option>
            <option value="blocked">Blocked</option>
            <option value="completed">Completed</option>
          </select></label>

        <label class="pd-field"><span>Priority</span>
          <select v-model="taskForm.priority" class="control">
            <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
          </select></label>

        <label class="pd-field"><span>Description</span>
          <textarea v-model="taskForm.description" class="control" rows="6"></textarea></label>

        <p v-if="taskForm.parent_task_id" class="pd-hint">
          This will be a sub-task. Sub-tasks carry everything a task does — owner,
          date, status.
        </p>

        <button class="btn-primary btn-block" :disabled="saving || !taskForm.title.trim()" @click="saveTask">
          {{ taskForm.id ? "Save Changes" : "Add Task" }}
        </button>
      </aside>
    </div>

    <!-- Milestone form -->
    <div v-if="showMilestone" class="pd-sheet-backdrop" @click.self="showMilestone = false">
      <aside class="pd-sheet" role="dialog" aria-label="New milestone">
        <div class="pd-sheet-head">
          <h3 class="pd-sheet-title">New Milestone</h3>
          <button class="pd-close" aria-label="Close" @click="showMilestone = false">×</button>
        </div>

        <label class="pd-field"><span>Milestone</span>
          <input v-model="milestoneForm.name" class="control" placeholder="On delivery of Phase 1" /></label>

        <label class="pd-field"><span>Value</span>
          <input v-model.number="milestoneForm.value" class="control" type="number" min="0" step="0.01" /></label>

        <label class="pd-field"><span>Currency</span>
          <input v-model="milestoneForm.currency" class="control" maxlength="3" placeholder="Blank uses the organisation's" />
          <span class="pd-hint">
            Kept per milestone because it varies — 15 of the legacy's 82 were USD.
          </span></label>

        <label class="pd-field"><span>Due</span>
          <input v-model="milestoneForm.due_on" class="control" type="date" /></label>

        <label class="pd-field"><span>Paid against</span>
          <select v-model.number="milestoneForm.project_task_id" class="control">
            <option :value="null">Not attached yet</option>
            <option v-for="o in dependencyOptions" :key="o.id" :value="o.id">{{ o.title }}</option>
          </select>
          <span class="pd-hint">
            Attaching it is what lets the screen warn before billing for work that
            is not finished.
          </span></label>

        <button class="btn-primary btn-block"
                :disabled="saving || !milestoneForm.name.trim() || milestoneForm.value === null"
                @click="saveMilestone">Add Milestone</button>
      </aside>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.pd-wrap { @apply flex flex-col; padding: var(--space-6); max-width: 80rem; gap: var(--space-4); }

.pd-crumbs { @apply flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); }
.pd-crumb { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: inherit; color: var(--color-text-muted); }
.pd-crumb:hover { color: var(--color-text); }
.pd-sep { color: var(--color-text-light); }
.pd-current { color: var(--color-text); }
.pd-loading { padding: var(--space-6); color: var(--color-text-muted); }

.pd-head { @apply flex items-start justify-between; gap: var(--space-4); }
.pd-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.pd-byline { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }
.pd-progress { @apply flex flex-col items-end; }
.pd-progress-value { font-size: var(--text-xl-size); font-weight: 700; color: var(--color-brand-on-surface); }
.pd-progress-label { font-size: var(--text-xs-size); color: var(--color-text-muted); }

.pd-facts-row { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3) var(--space-8); }
@media (max-width: 48rem) { .pd-facts-row { grid-template-columns: 1fr; } }
.pd-facts { margin: 0; }
.pd-facts > div { @apply flex items-center; gap: var(--space-3); padding: var(--space-1) 0; min-height: 2rem; }
.pd-facts dt { font-size: var(--text-sm-size); color: var(--color-text-muted); min-width: 7rem; }
.pd-facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }
.pd-status { border-left: 2px solid var(--color-brand-base); padding-left: var(--space-2); }
.pd-late { color: var(--color-danger-text); }
.pd-drift {
  margin-left: var(--space-2); padding: 1px var(--space-2); border-radius: var(--radius-sm);
  background: var(--color-warning-subtle); color: var(--color-warning-text);
  font-size: var(--text-xs-size); cursor: help;
}

.pd-tabs { @apply flex flex-wrap gap-1 overflow-x-auto; border-bottom: 1px solid var(--color-border); }
.pd-tab {
  @apply cursor-pointer border-none bg-transparent;
  padding: var(--space-2) var(--space-3);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap;
}
.pd-tab:hover { color: var(--color-text); }
.pd-tab.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }

.pd-panel { @apply flex flex-col; gap: var(--space-4); }
.pd-panel-actions { @apply flex justify-end; }

.pd-card {
  @apply flex flex-col; gap: var(--space-3);
  padding: var(--space-5);
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
.pd-table-card { padding: 0; overflow-x: auto; }
.pd-h3 { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.pd-h4 { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.04em; margin: 0; }
.pd-prose { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; white-space: pre-wrap; line-height: 1.6; }
.pd-muted { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-hint { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0; }
.pd-empty { font-size: var(--text-sm-size); color: var(--color-text-light); padding: var(--space-5) 0; margin: 0; max-width: 60ch; }
.pd-actions { @apply flex justify-end; }
.pd-nowrap { white-space: nowrap; }

.pd-masked {
  padding: var(--space-2) var(--space-3); border-radius: var(--radius-md);
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: var(--text-xs-size); margin: 0;
}

.pd-totals { @apply flex flex-wrap; gap: var(--space-3); }
.pd-total {
  @apply inline-flex flex-wrap items-baseline; gap: var(--space-3);
  padding: var(--space-2) var(--space-4); border-radius: var(--radius-md);
  border: 1px solid var(--color-border); background: var(--color-surface-raised);
}
.pd-total-ccy { font-size: var(--text-xs-size); font-weight: 700; color: var(--color-text-muted); }
.pd-total-item { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.pd-total-item strong { color: var(--color-text); font-variant-numeric: tabular-nums; }
.pd-total-item.outstanding strong { color: var(--color-warning-text); }

.pd-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.pd-table th {
  text-align: left; padding: var(--space-3); background: var(--color-surface-sunken);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); white-space: nowrap;
}
.pd-table td { padding: var(--space-3); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.pd-table tr:last-child td { border-bottom: none; }
.pd-table tr.done .pd-task-title { text-decoration: line-through; color: var(--color-text-light); }
.pd-subrow td { background: var(--color-surface); }
.pd-subtask { color: var(--color-text-muted); font-size: var(--text-xs-size); }
.pd-tick { width: 2rem; }
.pd-task-title { font-weight: 500; }
.pd-sub { @apply block; font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-ref { font-variant-numeric: tabular-nums; color: var(--color-text-muted); }
.pd-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.pd-row-actions { @apply flex; gap: var(--space-3); }
.pd-flag {
  margin-left: var(--space-2); padding: 1px var(--space-2); border-radius: var(--radius-sm);
  background: var(--color-warning-subtle); color: var(--color-warning-text);
  font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.04em;
}
.pd-priority { font-size: var(--text-xs-size); white-space: nowrap; }
.prio-high { color: var(--color-danger-text); font-weight: 500; }
.prio-medium { color: var(--color-warning-text); }
.prio-low { color: var(--color-text-muted); }

.pd-comments, .pd-replies, .pd-feed, .pd-list, .pd-people { @apply list-none; margin: 0; padding: 0; }
.pd-comment { padding: var(--space-3) 0; border-bottom: 1px solid var(--color-border); }
.pd-comment:last-child { border-bottom: none; }
.pd-comment-row { @apply flex; gap: var(--space-3); padding: var(--space-2) 0; }
.pd-comment-body { flex: 1; }
.pd-comment-who { @apply flex flex-wrap items-baseline; gap: var(--space-2); font-size: var(--text-sm-size); margin: 0; color: var(--color-text); }
.pd-comment-when { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-comment-text { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; white-space: pre-wrap; }
.pd-replies { margin-left: var(--space-6); border-left: 2px solid var(--color-border); padding-left: var(--space-3); }
.pd-reply { font-size: var(--text-xs-size); margin-top: var(--space-1); }
.pd-replying { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; }

.pd-event { @apply flex; gap: var(--space-3); padding: var(--space-3) 0; border-bottom: 1px solid var(--color-border); }
.pd-event:last-child { border-bottom: none; }
.pd-event-who { @apply flex flex-wrap items-baseline; gap: var(--space-2); font-size: var(--text-sm-size); margin: 0; color: var(--color-text); }

.pd-block { @apply flex flex-col; gap: var(--space-2); padding-top: var(--space-3); border-top: 1px solid var(--color-border); }
.pd-block-head { @apply flex items-center justify-between; }
.pd-list li { @apply flex items-center; gap: var(--space-2); padding: var(--space-1) 0; font-size: var(--text-sm-size); color: var(--color-text); }
.pd-list .struck, .pd-done-dep { text-decoration: line-through; color: var(--color-text-light); }
.pd-people { @apply flex flex-wrap; gap: var(--space-2); }
.pd-person-chip {
  @apply inline-flex items-center; gap: var(--space-1);
  padding: 2px var(--space-2); border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text);
  font-size: var(--text-xs-size);
}
.pd-person-chip.assisting { background: var(--color-brand-subtle); color: var(--color-brand-on-surface); }
.pd-add-row { @apply flex flex-wrap items-start; gap: var(--space-2); }
.pd-add-picker { flex: 1; min-width: 12rem; }
.pd-capacity { max-width: 9rem; }
.pd-upload { @apply cursor-pointer; }

.pd-stack { @apply inline-flex items-center; }
.pd-avatar {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 1.5rem; height: 1.5rem; margin-right: -0.35rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  border: 2px solid var(--color-surface-raised);
  font-size: 0.6rem; font-weight: 600;
}

.pd-mini { margin: 0; }
.pd-mini > div { @apply flex items-baseline justify-between; gap: var(--space-3); padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border); }
.pd-mini > div:last-child { border-bottom: none; }
.pd-mini dt { font-size: var(--text-sm-size); color: var(--color-text-muted); }
.pd-mini dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }

.pd-field { @apply flex flex-col; gap: var(--space-1); }
.pd-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }

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
.pill-danger  { background: var(--color-danger-subtle);  color: var(--color-danger-text);  border-color: color-mix(in oklab, var(--color-danger-text) 30%, transparent); }
.pill-info    { background: var(--color-brand-subtle);   color: var(--color-brand-on-surface); border-color: color-mix(in oklab, var(--color-brand-base) 30%, transparent); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); border-color: var(--color-border); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); text-align: left; }
.link-btn:hover { @apply underline; }
.link-danger { color: var(--color-danger-text); }

.pd-sheet-backdrop { @apply fixed inset-0 flex justify-end z-50; background: rgb(0 0 0 / 0.28); }
.pd-sheet {
  @apply flex flex-col overflow-y-auto; width: min(26rem, 100%); gap: var(--space-3);
  padding: var(--space-5); background: var(--color-surface-raised); box-shadow: var(--shadow-lg);
  animation: sheet-in 180ms ease;
}
.pd-sheet-wide { width: min(34rem, 100%); }
@keyframes sheet-in { from { transform: translateX(12px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .pd-sheet { animation: none; } }
.pd-sheet-head { @apply flex items-center justify-between; }
.pd-sheet-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.pd-sheet-actions { @apply flex justify-end; gap: var(--space-2); margin-top: auto; padding-top: var(--space-3); }
.pd-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

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
