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
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import EmployeePicker from "../../components/EmployeePicker.vue";
import AccountPicker from "../../components/AccountPicker.vue";
import VendorPartnerPicker from "../../components/VendorPartnerPicker.vue";
import { safeHtml, hasText } from "../../lib/richText";

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
  owner: {
    party: string; is_ours: boolean; name: string | null;
    account_id: number | null; vendor_partner_id: number | null;
    contact_id: number | null; contact_name: string | null;
  };
  project: { id: number; name: string } | null;
  function: { id: number; name: string } | null;
  department: { id: number; name: string } | null;
  business_unit: { id: number; name: string } | null;
  team: { id: number; name: string } | null;
  origin: { type: string; id: number | string; reference: string | null } | null;
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
  // Per-reader, not per-task: two people opening the same task see different
  // answers here. Absent on the nested sub-task rows.
  my_involvement: Involvement | null;
  can_set_involvement: boolean;
  can_take_task: boolean;
  section_counts: Record<string, number>;
  responsibility: Responsibility | null;
  needs_closure: boolean | null;
  closure: Closure | null;
  is_paused: boolean | null;
  paused_days: number | null;
  pause: Pause | null;
  pause_history: PauseRow[];
  extension_count: number | null;
  extensions: Extension[];
  days_overdue: number | null;
  escalations: Escalation[];
}
interface Escalation {
  id: number; trigger: string; trigger_label: string;
  level: string; level_label: string;
  to: string | null; detail: string; at: string | null;
}
interface Pause {
  id: number; category: string; reason: string; note: string | null;
  expected_resume_on: string | null; responsible_party: Responsibility | null;
  paused_at: string | null; days: number; is_overdue: boolean;
}
interface PauseRow {
  id: number; category: string; reason: string; responsible_party: string | null;
  paused_at: string | null; resumed_at: string | null; days: number;
  note: string | null; resume_note: string | null;
}
interface Extension {
  id: number; previous_deadline: string | null; new_deadline: string | null;
  days_moved: number | null; reason: string; impact: string;
  dependency: string | null; responsible_party: string | null;
  requested_by: string | null; at: string | null;
}
type Involvement = "doing" | "assisting" | "tracking";
type Responsibility =
  | "self" | "internal_team" | "customer" | "vendor"
  | "subcontractor" | "management" | "procurement" | "finance";
interface Closure {
  work_completed: string; deliverables: string; challenges: string;
  outcomes: string; lessons_learned: string | null;
  closed_at: string | null; closed_by: string | null;
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
    activeSection.value = "overview";
    // Every section is on the page now rather than behind a tab, so the
    // meetings that used to load on tab-click have to be here too.
    void loadMeetings();
    await nextTick();
    watchSections();
  } catch (e) { fail(e, "Could not load the task."); }
  finally { loading.value = false; }
}

/** Every write returns the whole task, so one helper covers all of them. */
async function send(method: "POST" | "PUT" | "DELETE", path: string, body: unknown, done?: string) {
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
  // A task that has never been closed off asks what came of it first; the
  // server refuses it otherwise, so the form is the way through rather than a
  // hoop in front of one.
  if (task.value!.needs_closure) { openClosure(); return; }

  const needsSignOff = task.value!.requires_approval && !task.value!.approved_at;
  await send("PUT", "", { title: task.value!.title, status: "completed" },
    needsSignOff ? "Sent for approval." : "Marked complete.");
}

// ── Closure summary ─────────────────────────────────────────────────────────
/*
 * What came of the task, asked once, at the moment somebody says it is done.
 *
 * A completion date on its own tells a project review nothing: it cannot say
 * what was handed over, what got in the way, or whether the thing achieved
 * what it was raised for.
 */
const closing = ref(false);
const closureError = ref("");
const closureForm = ref({
  work_completed: "", deliverables: "", challenges: "", outcomes: "", lessons_learned: "",
});

const CLOSURE_FIELDS = [
  { key: "work_completed", label: "Work completed", hint: "What was actually done.", required: true },
  { key: "deliverables",   label: "Deliverables achieved", hint: "What was handed over.", required: true },
  { key: "challenges",     label: "Challenges encountered", hint: "What got in the way — write “none” if nothing did.", required: true },
  { key: "outcomes",       label: "Key outcomes", hint: "What it achieved.", required: true },
  { key: "lessons_learned", label: "Lessons learned", hint: "Optional — only if there is one.", required: false },
] as const;

function openClosure() {
  closureForm.value = { work_completed: "", deliverables: "", challenges: "", outcomes: "", lessons_learned: "" };
  closureError.value = "";
  closing.value = true;
}

const closureReady = computed(() =>
  CLOSURE_FIELDS.filter((f) => f.required)
    .every((f) => closureForm.value[f.key as keyof typeof closureForm.value].trim().length > 0));

async function submitClosure() {
  if (!closureReady.value) return;
  const f = closureForm.value;
  closureError.value = "";
  saving.value = true;
  try {
    task.value = (await api<{ data: Task }>("POST", `/api/v1/tasks/${props.id}/closure`, {
      org: org(),
      body: { ...f, lessons_learned: f.lessons_learned.trim() || null },
    })).data;
    closing.value = false;
    notifySaved(task.value.status === "awaiting_approval" ? "Sent for approval." : "Closed off.");
    emit("changed");
  } catch (e) {
    closureError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not close the task.";
  } finally { saving.value = false; }
}

// ── Extending a deadline ────────────────────────────────────────────────────
/*
 * A deadline that moves without a reason attached leaves a task that slipped
 * four times looking exactly like one estimated correctly. The form is the
 * record; the date change is a side effect of it.
 */
const extending = ref(false);
const extendError = ref("");
const extendForm = ref({
  new_deadline: "", reason: "", impact: "", dependency: "",
  responsible_party: "" as Responsibility | "",
});

function openExtend() {
  extendForm.value = { new_deadline: "", reason: "", impact: "", dependency: "", responsible_party: "" };
  extendError.value = "";
  extending.value = true;
}

const extendReady = computed(() =>
  !!extendForm.value.new_deadline && !!extendForm.value.reason.trim() && !!extendForm.value.impact.trim());

async function submitExtend() {
  if (!extendReady.value) return;
  const f = extendForm.value;
  extendError.value = "";
  saving.value = true;
  try {
    task.value = (await api<{ data: Task }>("POST", `/api/v1/tasks/${props.id}/extensions`, {
      org: org(),
      body: {
        new_deadline: f.new_deadline, reason: f.reason, impact: f.impact,
        dependency: f.dependency.trim() || null,
        responsible_party: f.responsible_party || null,
      },
    })).data;
    extending.value = false;
    notifySaved("Deadline moved, and the reason is on the record.");
    emit("changed");
  } catch (e) {
    extendError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not move the deadline.";
  } finally { saving.value = false; }
}

// ── Pausing ─────────────────────────────────────────────────────────────────
/*
 * Work stops constantly while somebody waits on a signature or a delivery.
 * With nowhere to record it the whole wait counts against us, so a task held
 * six weeks by a client reads like one nobody touched.
 */
const PAUSE_FAMILIES = [
  { id: "pending_customer", label: "On the customer", reasons: [
    ["awaiting_approval", "Awaiting approval"], ["awaiting_information", "Awaiting information"],
    ["awaiting_feedback", "Awaiting feedback"], ["awaiting_payment", "Awaiting payment"],
    ["awaiting_signoff", "Awaiting sign-off"]] },
  { id: "pending_internal", label: "On us", reasons: [
    ["awaiting_management_approval", "Awaiting management approval"], ["awaiting_procurement", "Awaiting procurement"],
    ["awaiting_resource_allocation", "Awaiting resource allocation"], ["awaiting_technical_review", "Awaiting technical review"]] },
  { id: "pending_vendor", label: "On a vendor", reasons: [
    ["awaiting_delivery", "Awaiting delivery"], ["awaiting_support_response", "Awaiting support response"],
    ["awaiting_licensing", "Awaiting licensing"], ["awaiting_implementation", "Awaiting implementation"]] },
  { id: "pending_subcontractor", label: "On a subcontractor", reasons: [
    ["awaiting_execution", "Awaiting execution"], ["awaiting_documentation", "Awaiting documentation"],
    ["awaiting_site_works", "Awaiting site works"]] },
] as const;

const pausing = ref(false);
const pauseError = ref("");
const pauseForm = ref({ reason: "", note: "", expected_resume_on: "" });

function openPause() {
  pauseForm.value = { reason: "", note: "", expected_resume_on: "" };
  pauseError.value = "";
  pausing.value = true;
}

async function submitPause() {
  if (!pauseForm.value.reason) return;
  const f = pauseForm.value;
  pauseError.value = "";
  saving.value = true;
  try {
    task.value = (await api<{ data: Task }>("POST", `/api/v1/tasks/${props.id}/pause`, {
      org: org(),
      body: { reason: f.reason, note: f.note.trim() || null, expected_resume_on: f.expected_resume_on || null },
    })).data;
    pausing.value = false;
    notifySaved("Paused — the clock is stopped.");
    emit("changed");
  } catch (e) {
    pauseError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not pause the task.";
  } finally { saving.value = false; }
}

async function resumeTask() {
  const note = window.prompt("Anything worth noting about the restart? (optional)");
  if (note === null) return;
  await send("POST", "/resume", { note: note.trim() || null }, "Back on.");
}

/** Reasons and categories read as words, not slugs. */
const words = (v: string | null) => v ? v.replace(/_/g, " ").replace(/^./, (m) => m.toUpperCase()) : "";

// ── Responsibility ──────────────────────────────────────────────────────────
/*
 * Who the task is waiting on right now — a different question from who owes
 * the work, and the one that explains a delay.
 */
const RESPONSIBILITIES: { id: Responsibility; label: string; tone: string }[] = [
  { id: "self",          label: "Self",            tone: "blue" },
  { id: "internal_team", label: "Internal team",   tone: "purple" },
  { id: "customer",      label: "Customer",        tone: "orange" },
  { id: "vendor",        label: "Vendor",          tone: "yellow" },
  { id: "subcontractor", label: "Subcontractor",   tone: "teal" },
  { id: "management",    label: "Management",      tone: "red" },
  { id: "procurement",   label: "Procurement",     tone: "cyan" },
  { id: "finance",       label: "Finance",         tone: "green" },
];

const responsibility = computed(() =>
  RESPONSIBILITIES.find((r) => r.id === task.value?.responsibility) ?? null);

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
  functional_unit_id: null as number | null,
  department_id: null as number | null,
  business_unit_id: null as number | null,
  requires_approval: false, approver_id: null as number | null,
  owner_party: "internal",
  owner_account_id: null as number | null,
  owner_vendor_partner_id: null as number | null,
  owner_contact_name: "",
});

// The org hierarchy — Function > Department > Practice — for the edit form's
// "assign to" pickers. Flattened from the structure tree so each level can be a
// dependent dropdown. Loaded on first edit; empty (the selects offer nothing)
// when the viewer cannot see the org structure.
interface RawBu { id: number; name: string }
interface RawDept { id: number; name: string; functional_unit_id: number | null; business_units?: RawBu[] }
interface Unit { id: number; name: string }
interface Dept extends Unit { functional_unit_id: number | null }
interface Bu extends Unit { department_id: number | null }

const functionalUnits  = ref<Unit[]>([]);
const allDepartments   = ref<Dept[]>([]);
const allBusinessUnits = ref<Bu[]>([]);
let orgUnitsLoaded = false;

async function loadOrgUnits() {
  if (orgUnitsLoaded) return;
  orgUnitsLoaded = true;
  try {
    const res = await api<{
      functional_units: { id: number; name: string; departments: RawDept[] }[];
      unassigned_departments: RawDept[];
    }>("GET", "/api/v1/org/structure", { org: org() });

    functionalUnits.value = res.functional_units.map((u) => ({ id: u.id, name: u.name }));

    const depts: Dept[] = [];
    const bus: Bu[] = [];
    const collect = (d: RawDept) => {
      depts.push({ id: d.id, name: d.name, functional_unit_id: d.functional_unit_id ?? null });
      for (const bu of d.business_units ?? [])
        bus.push({ id: bu.id, name: bu.name, department_id: d.id });
    };
    for (const u of res.functional_units) for (const d of u.departments ?? []) collect(d);
    for (const d of res.unassigned_departments ?? []) collect(d);
    allDepartments.value   = depts;
    allBusinessUnits.value = bus;
  } catch { orgUnitsLoaded = false; /* let a later edit retry */ }
}

// Each level offers only what sits under the level above; with nothing chosen
// above, it offers everything.
const departmentOptions = computed(() => form.value.functional_unit_id
  ? allDepartments.value.filter((d) => d.functional_unit_id === form.value.functional_unit_id)
  : allDepartments.value);
const businessUnitOptions = computed(() => form.value.department_id
  ? allBusinessUnits.value.filter((b) => b.department_id === form.value.department_id)
  : allBusinessUnits.value);

// Choosing lower fills the parents; changing a parent drops a child that no
// longer belongs — so the three always name one consistent path.
watch(() => form.value.business_unit_id, (id) => {
  const b = allBusinessUnits.value.find((x) => x.id === id);
  if (b?.department_id) form.value.department_id = b.department_id;
});
watch(() => form.value.department_id, (id) => {
  const d = allDepartments.value.find((x) => x.id === id);
  if (d?.functional_unit_id) form.value.functional_unit_id = d.functional_unit_id;
  if (form.value.business_unit_id && !allBusinessUnits.value.some((b) => b.id === form.value.business_unit_id && b.department_id === id))
    form.value.business_unit_id = null;
});
watch(() => form.value.functional_unit_id, (id) => {
  const d = allDepartments.value.find((x) => x.id === form.value.department_id);
  if (form.value.department_id && d && d.functional_unit_id !== id) {
    form.value.department_id = null;
    form.value.business_unit_id = null;
  }
});

function beginEdit() {
  loadOrgUnits();
  const t = task.value!;
  form.value = {
    title: t.title, description: t.description ?? "",
    category: t.category, status: t.status, priority: t.priority,
    deadline: t.deadline ?? "", started_on: t.started_on ?? "",
    effort_estimate_days: t.effort_estimate_days ?? "",
    assigned_to: t.assignee?.id ?? null,
    functional_unit_id: t.function?.id ?? null,
    department_id: t.department?.id ?? null,
    business_unit_id: t.business_unit?.id ?? null,
    requires_approval: t.requires_approval,
    approver_id: t.approver?.id ?? null,
    owner_party: t.owner.party,
    owner_account_id: t.owner.account_id,
    owner_vendor_partner_id: t.owner.vendor_partner_id,
    // The typed name only — a linked contact is left alone rather than
    // flattened into free text and saved back as one.
    owner_contact_name: t.owner.contact_id ? "" : (t.owner.contact_name ?? ""),
  };
  editing.value = true;
}

async function save() {
  const ok = await send("PUT", "", {
    ...form.value,
    deadline: form.value.deadline || null,
    started_on: form.value.started_on || null,
    effort_estimate_days: form.value.effort_estimate_days === "" ? null : form.value.effort_estimate_days,
    owner_contact_name: form.value.owner_contact_name || null,
  }, "Task updated.");
  if (ok) editing.value = false;
}

// ── Sections ────────────────────────────────────────────────────────────────
/*
 * What were nine tabs are now nine stacked sections with a list beside them.
 *
 * The list navigates rather than switching panels, so everything is on the page
 * at once and nothing is a click away. Sections with nothing in them sort below
 * a divider and go quiet, which is the point of the redesign: the old tab bar
 * gave a full-width row to Issues and Meetings whether or not the task had ever
 * had either, and most tasks have neither.
 */
const SECTIONS = [
  { id: "overview",     label: "Overview",     count: null },
  // Comments and meetings first: they are where a task actually moves. The
  // rest — sub-tasks, dependencies, resources — describe how it is set up, and
  // that is read once. What was said and what was agreed is read every time.
  { id: "comments",     label: "Comments",     count: "comments" },
  { id: "meetings",     label: "Meetings",     count: "meetings" },
  { id: "subtasks",     label: "Sub-tasks",    count: "subtasks" },
  { id: "dependencies", label: "Dependencies", count: "dependencies" },
  { id: "assisting",    label: "Assisting",    count: "assisting" },
  { id: "resources",    label: "Resources",    count: "resources" },
  // Supporting paper sits at the end, next to the history. You come to a task
  // to see where it stands and move it on; the files are what you reach for
  // once you have, and they were pushing the work down the page.
  // This list is also the page's order — the nav scrolls to these, so the two
  // have to agree or clicking one jumps past another.
  { id: "attachments",  label: "Attachments",  count: "attachments" },
  { id: "activities",   label: "Activities",   count: null },
  { id: "issues",       label: "Issues",       count: "issues" },
] as const;
type SectionId = typeof SECTIONS[number]["id"];

const activeSection = ref<SectionId>("overview");

/** How many things a section holds, straight from the server's counts. */
function sectionCount(id: SectionId): number {
  const key = SECTIONS.find((s) => s.id === id)?.count;
  if (!key) return 0;
  return task.value?.section_counts?.[key] ?? 0;
}

/*
 * Overview always carries the task itself, and Activities is derived from
 * stamps every task has, so neither is ever empty. The rest earn their place
 * in the top group by having something in them.
 */
const ALWAYS_PRESENT: SectionId[] = ["overview", "activities"];
const isEmpty = (id: SectionId) => !ALWAYS_PRESENT.includes(id) && sectionCount(id) === 0;

const filledSections = computed(() => SECTIONS.filter((s) => !isEmpty(s.id)));
const emptySections  = computed(() => SECTIONS.filter((s) => isEmpty(s.id)));

const bodyEl = ref<HTMLElement | null>(null);
const sectionEls = ref<Record<string, HTMLElement | null>>({});
const setSectionEl = (id: string) => (el: unknown) => { sectionEls.value[id] = el as HTMLElement | null; };

function goToSection(id: SectionId) {
  activeSection.value = id;
  // Suppress the observer while the smooth scroll runs, or every section it
  // passes through would steal the highlight on the way.
  scrollingTo = id;
  const gentle = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
  sectionEls.value[id]?.scrollIntoView({ behavior: gentle ? "auto" : "smooth", block: "start" });
  window.setTimeout(() => { if (scrollingTo === id) scrollingTo = null; }, 600);
}

let scrollingTo: SectionId | null = null;
let observer: IntersectionObserver | null = null;

/** The active item follows the scroll, so the list always says where you are. */
function watchSections() {
  observer?.disconnect();
  if (!bodyEl.value) return;

  observer = new IntersectionObserver((entries) => {
    if (scrollingTo) return;
    const visible = entries
      .filter((e) => e.isIntersecting)
      .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
    const id = visible?.target.getAttribute("data-section") as SectionId | undefined;
    if (id) activeSection.value = id;
  }, { root: bodyEl.value, rootMargin: "0px 0px -70% 0px", threshold: 0 });

  for (const el of Object.values(sectionEls.value)) if (el) observer.observe(el);
}

onBeforeUnmount(() => observer?.disconnect());

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

/**
 * "in 6 days" / "yesterday" — how far off a deadline is, counted in whole days.
 *
 * Separate from `ago()` below, which measures elapsed time and assumes the
 * moment has passed: a date in the future makes its seconds negative, falls
 * through the first step and reports a deadline as "in 3,058,046 seconds".
 */
function dueIn(v: string | null): string {
  if (!v) return "";
  const start = (d: Date) => Date.UTC(d.getFullYear(), d.getMonth(), d.getDate());
  const days = Math.round((start(new Date(v)) - start(new Date())) / 86400000);

  return new Intl.RelativeTimeFormat(undefined, { numeric: "auto" }).format(days, "day");
}

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

/*
 * The tab badges used to count what still needed attention — unfulfilled
 * documents, open issues, unanswered asks. The section list counts what a
 * section *holds* instead, because it also decides which sections are empty,
 * and a section with three fulfilled documents in it is not empty. What needs
 * attention is still marked on the rows themselves.
 */

// ── Meetings ────────────────────────────────────────────────────────────────
// A task is only where a call is scheduled from; it is tracked where the task
// belongs — a project's Correspondence, a ticket's Meetings — so the tab lists
// whatever this task raised, and says plainly where it is logged.

interface MeetingRow {
  id: number; subject: string; scheduled_at: string | null; duration_minutes: number;
  attendees: { name: string | null; address: string }[];
  join_url: string | null; recording_url: string | null; transcript: string | null;
  has_transcript: boolean; status: "scheduled" | "cancelled";
  created_by: string | null; created_at: string | null;
}
interface MeetingDestination { type: "project" | "ticket" | "none"; id: number | null; label: string | null; note: string; }
interface Candidate { name: string | null; address: string; group: string; }

const meetings    = ref<MeetingRow[]>([]);
const meetingDest = ref<MeetingDestination>({ type: "none", id: null, label: null, note: "" });
const candidates  = ref<Candidate[]>([]);
const meetingsLoaded = ref(false);
const meetingBusy = ref(false);
const meetingError = ref("");

const showScheduleMeeting = ref(false);
const meetingChecks = ref<Record<string, boolean>>({});
const meetingDraft  = ref({ subject: "", scheduled_at: "", duration_minutes: 30, agenda: "", extra_attendees: "" });

async function loadMeetings(): Promise<void> {
  try {
    const res = await api<{ data: MeetingRow[]; destination: MeetingDestination; suggestions: Candidate[] }>(
      "GET", `/api/v1/tasks/${props.id}/meetings`, { org: org() });
    meetings.value    = res.data;
    meetingDest.value = res.destination;
    candidates.value  = res.suggestions;
    meetingsLoaded.value = true;
  } catch { /* leave the empty state; the panel explains it */ }
}

function openScheduleMeeting(): void {
  const t = task.value;
  meetingDraft.value = {
    subject: t ? `Meeting: ${t.title}` : "Meeting",
    scheduled_at: "", duration_minutes: 30, agenda: "", extra_attendees: "",
  };
  meetingChecks.value = {};
  for (const c of candidates.value) meetingChecks.value[c.address] = true;
  meetingError.value = "";
  showScheduleMeeting.value = true;
}

async function scheduleMeeting(): Promise<void> {
  if (!meetingDraft.value.scheduled_at) { meetingError.value = "Pick a date and time."; return; }
  const attendees = candidates.value
    .filter((c) => meetingChecks.value[c.address])
    .map((c) => ({ name: c.name, address: c.address }));
  for (const raw of meetingDraft.value.extra_attendees.split(",")) {
    const address = raw.trim();
    if (address) attendees.push({ name: null, address });
  }
  if (!attendees.length) { meetingError.value = "Add at least one attendee."; return; }

  meetingBusy.value = true; meetingError.value = "";
  try {
    await api("POST", `/api/v1/tasks/${props.id}/meetings`, {
      org: org(), body: {
        subject: meetingDraft.value.subject,
        scheduled_at: new Date(meetingDraft.value.scheduled_at).toISOString(),
        duration_minutes: meetingDraft.value.duration_minutes,
        agenda: meetingDraft.value.agenda.trim() || null,
        attendees,
      },
    });
    showScheduleMeeting.value = false;
    await loadMeetings();
  } catch (e) {
    meetingError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not schedule the meeting.";
  } finally { meetingBusy.value = false; }
}

async function syncMeeting(id: number): Promise<void> {
  meetingBusy.value = true; meetingError.value = "";
  try {
    const res = await api<{ message: string | null }>("POST", `/api/v1/tasks/${props.id}/meetings/${id}/sync`, { org: org() });
    if (res.message) meetingError.value = res.message;
    await loadMeetings();
  } catch (e) {
    meetingError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not pull the recording.";
  } finally { meetingBusy.value = false; }
}

async function cancelMeeting(id: number): Promise<void> {
  meetingBusy.value = true; meetingError.value = "";
  try {
    await api("DELETE", `/api/v1/tasks/${props.id}/meetings/${id}`, { org: org() });
    await loadMeetings();
  } catch (e) {
    meetingError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not cancel the meeting.";
  } finally { meetingBusy.value = false; }
}

const fmtWhen = (v: string | null) => v ? new Date(v).toLocaleString(undefined,
  { weekday: "short", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "";

// ── Involvement ─────────────────────────────────────────────────────────────
/*
 * What the reader is to this task. Per-person, so it is the one control here
 * whose value differs for whoever opens the panel — and the reason the owner
 * line can now say "you are assisting" instead of leaving you to work it out
 * from the avatar stack.
 */
const INVOLVEMENTS: { id: Involvement; label: string; says: string }[] = [
  { id: "doing",     label: "Doing",     says: "You are doing this" },
  { id: "assisting", label: "Assisting", says: "You are assisting" },
  { id: "tracking",  label: "Tracking",  says: "You are tracking this" },
];

const involvementSays = computed(() =>
  INVOLVEMENTS.find((i) => i.id === task.value?.my_involvement)?.says ?? null);

async function setInvolvement(next: Involvement) {
  // Pressing the segment you are already on steps back out of the task.
  const value = task.value?.my_involvement === next ? null : next;
  await send("PUT", "/involvement", { involvement: value },
    value ? INVOLVEMENTS.find((i) => i.id === value)!.says + "." : "Stepped back.");
}

// ── Sub-tasks: finish one without leaving the task ──────────────────────────
/*
 * Done ones sink to the bottom. A sub-task here is a whole task with its own
 * owner and deadline, not a checklist line, so the table keeps those columns —
 * but ticking one off should not mean opening it first.
 */
const orderedSubtasks = computed(() => [...(task.value?.subtasks ?? [])]
  .sort((a, b) => Number(a.is_done) - Number(b.is_done)));

async function toggleSubtask(s: Task) {
  saving.value = true;
  try {
    await api("PUT", `/api/v1/tasks/${s.id}`, {
      org: org(), body: { title: s.title, status: s.is_done ? "todo" : "completed" },
    });
    await load();
    emit("changed");
  } catch (e) { fail(e, "Could not update the sub-task."); }
  finally { saving.value = false; }
}

// ── Deadline: shown as a date, edited as one ────────────────────────────────

const editingDeadline = ref(false);
const deadlineEl = ref<HTMLInputElement | null>(null);

async function openDeadline() {
  editingDeadline.value = true;
  await nextTick();
  // Focus only. Opening the calendar programmatically can commit whatever the
  // picker lands on, so clicking "No deadline" merely to look at it would set
  // one — a date nobody chose is worse than no date at all.
  deadlineEl.value?.focus();
}

// ── Assigning from the rail ─────────────────────────────────────────────────

const assigning = ref(false);
const assignTo  = ref<number | null>(null);

/*
 * Picking somebody puts them on the task there and then.
 *
 * No confirm button: choosing a person from a list of people is the decision,
 * and a second click to agree with yourself only gives the dropdown something
 * to cover up.
 */
watch(assignTo, async (id) => {
  if (!id) return;
  await send("POST", "/assignees", { employee_id: id }, "Added to the task.");
  assignTo.value = null;
  assigning.value = false;
});

async function removeAssignee(p: Person) {
  if (!p.id) return;
  await send("DELETE", `/assignees/${p.id}`, null, "Taken off the task.");
}

/** Category colours the pill's dot, so the kind of work reads at a glance. */
const CATEGORY_TONE: Record<string, string> = {
  project: "brand", opportunity: "brand", account: "brand", internal: "brand",
  support: "amber", ticket: "amber",
  training: "teal", certificate: "teal", research: "teal",
  meeting: "indigo", collaboration: "indigo",
  leave: "muted",
};
const categoryTone = (c: string) => CATEGORY_TONE[c] ?? "brand";
</script>

<template>
  <div class="tp-backdrop" @click.self="close">
    <section class="tp-panel" role="dialog" aria-modal="true" aria-label="Task details">
      <div v-if="loading" class="tp-loading">Loading…</div>

      <template v-else-if="task">
        <!-- ── Breadcrumb bar ───────────────────────────────────────────── -->
        <header class="tp-head">
          <p class="tp-crumb">
            <template v-if="task.project">
              <button class="tp-crumb-up" @click="router.push({ name: 'projects' })">Projects</button>
              <span class="tp-crumb-sep">/</span>
              <button class="tp-crumb-up" @click="router.push({ name: 'project', params: { id: task.project.id } })">
                {{ task.project.name }}
              </button>
            </template>
            <template v-else>
              <button class="tp-crumb-up" @click="close">Tasks</button>
            </template>
            <span class="tp-crumb-sep">/</span>
            <span class="tp-ref">{{ task.reference ?? "—" }}</span>
            <span :class="['tp-kind', categoryTone(task.category)]">
              <span class="tp-kind-dot"></span>{{ titleCase(task.category) }}
            </span>
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

        <div ref="bodyEl" class="tp-grid">
        <!-- ── Section list ─────────────────────────────────────────────────
             Nine tabs, demoted. What the task actually has comes first; what it
             has never had goes quiet below the rule instead of taking a full
             tab-stop of the width. -->
        <nav class="tp-nav" aria-label="Sections">
          <div class="tp-nav-inner">
          <span class="tp-nav-head">Sections</span>
          <button v-for="s in filledSections" :key="s.id"
                  :class="['tp-nav-item', { active: activeSection === s.id }]"
                  :aria-current="activeSection === s.id ? 'true' : undefined"
                  @click="goToSection(s.id)">
            {{ s.label }}
            <span v-if="sectionCount(s.id)" class="tp-nav-count">{{ sectionCount(s.id) }}</span>
          </button>

          <template v-if="emptySections.length">
            <div class="tp-nav-rule"></div>
            <span class="tp-nav-head quiet">Empty</span>
            <button v-for="s in emptySections" :key="s.id"
                    :class="['tp-nav-item empty', { active: activeSection === s.id }]"
                    :aria-current="activeSection === s.id ? 'true' : undefined"
                    @click="goToSection(s.id)">
              {{ s.label }}
            </button>
          </template>
          </div>
        </nav>

        <!-- ── The task ─────────────────────────────────────────────────── -->
        <div class="tp-content">
        <section :ref="setSectionEl('overview')" data-section="overview" class="tp-sec tp-sec-open">
          <h1 class="tp-title">{{ task.title }}</h1>
          <p class="tp-byline">
            <span class="tp-avatar sm">{{ initials(task.assignee?.name) }}</span>
            <strong>{{ task.assignee?.name ?? "Unassigned" }}</strong>
            owns this task
            <!-- The one line on the panel that is different for each reader. -->
            <template v-if="involvementSays">
              <span class="tp-byline-rule"></span>
              <span class="tp-mine"><span class="tp-mine-dot"></span>{{ involvementSays }}</span>
            </template>
          </p>

          <!-- Where a system raised it, and the team queue it sits on. -->
          <div v-if="task.origin || task.team" class="tp-origin">
            <button v-if="task.origin?.type === 'ticket'" class="tp-origin-chip link"
                    @click="router.push({ name: 'ticket-detail', params: { id: task.origin.id } })">
              🎫 From ticket {{ task.origin.reference ?? "#" + task.origin.id }}
            </button>
            <span v-if="task.team" class="tp-origin-chip team">👥 {{ task.team.name }}</span>
          </div>

          <!--
            Owed by somebody outside, said up front.

            It changes what every other number on this panel means: the elapsed
            days are not our effort, and the person assigned is chasing it
            rather than doing it.
          -->
          <p v-if="!task.owner.is_ours" class="tp-external">
            <strong>{{ task.owner.name ?? "Somebody outside" }}</strong> owes this work
            <template v-if="task.owner.contact_name"> — {{ task.owner.contact_name }}</template>.
            <template v-if="task.assignee?.name"> {{ task.assignee.name }} is chasing it.</template>
          </p>

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

          <!-- Sanitised rather than escaped: the lists and emphasis in an
               imported scope document carry meaning. Structure survives and
               every style attribute is dropped, so a Word paste renders in
               our own typography instead of hard-coded Calibri. -->
          <div v-if="hasText(task.description)" class="tp-text rich"
               v-html="safeHtml(task.description)"></div>
          <p v-else class="tp-muted tp-quiet">Nothing written yet.</p>

        </section>
          <section :ref="setSectionEl('comments')" data-section="comments" class="tp-sec">
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

              <div class="tp-comment-body rich" v-html="safeHtml(c.body)"></div>

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
          </section>

          <section :ref="setSectionEl('meetings')" data-section="meetings" class="tp-sec">
            <div class="tp-section-row">
              <h2 class="tp-section">Meetings</h2>
              <button class="link-btn" @click="openScheduleMeeting">+ Schedule meeting</button>
            </div>

            <!-- Where the call is tracked, so it never lives in two places. -->
            <p v-if="meetingDest.note" class="tp-meet-dest">
              <template v-if="meetingDest.type === 'project'">
                🗂 Logged on
                <button class="link-btn" @click="meetingDest.id && router.push({ name: 'project', params: { id: meetingDest.id } })">{{ meetingDest.label }}</button>
                — Correspondence.
              </template>
              <template v-else-if="meetingDest.type === 'ticket'">
                🎫 Shown on ticket
                <button class="link-btn" @click="meetingDest.id && router.push({ name: 'ticket-detail', params: { id: meetingDest.id } })">{{ meetingDest.label }}</button>
                — Meetings.
              </template>
              <template v-else>📌 {{ meetingDest.note }}</template>
            </p>

            <p v-if="meetingError" class="tp-meet-error">{{ meetingError }}</p>

            <p v-if="meetingsLoaded && !meetings.length" class="tp-muted tp-quiet">
              No meetings yet. Schedule one and it is tracked here and on the
              {{ meetingDest.type === 'project' ? 'project' : meetingDest.type === 'ticket' ? 'ticket' : 'task' }}.
            </p>

            <article v-for="m in meetings" :key="m.id" :class="['tp-meet', { cancelled: m.status === 'cancelled' }]">
              <div class="tp-meet-head">
                <h3 class="tp-meet-subject">{{ m.subject }}</h3>
                <span :class="['pill', m.status === 'scheduled' ? 'pill-ok' : 'pill-bad']">
                  {{ m.status === 'scheduled' ? 'Scheduled' : 'Cancelled' }}
                </span>
              </div>
              <p class="tp-meet-meta">
                🗓 {{ fmtWhen(m.scheduled_at) }} · {{ m.duration_minutes }} min
                <template v-if="m.created_by"> · by {{ m.created_by }}</template>
              </p>
              <p v-if="m.attendees.length" class="tp-meet-attendees">
                <span v-for="a in m.attendees" :key="a.address" class="tp-meet-chip">{{ a.name || a.address }}</span>
              </p>
              <div class="tp-meet-actions">
                <a v-if="m.join_url" :href="m.join_url" target="_blank" rel="noopener" class="link-btn">Join</a>
                <a v-if="m.recording_url" :href="m.recording_url" target="_blank" rel="noopener" class="link-btn">Recording</a>
                <span v-if="m.has_transcript" class="tp-meet-flag">Transcript ready</span>
                <button v-if="m.status === 'scheduled'" class="link-btn" :disabled="meetingBusy" @click="syncMeeting(m.id)">Pull recording</button>
                <button v-if="m.status === 'scheduled'" class="link-btn tp-meet-cancel" :disabled="meetingBusy" @click="cancelMeeting(m.id)">Cancel</button>
              </div>
            </article>
          </section>


          <!-- ── Sub-tasks ──────────────────────────────────────────────── -->
          <section :ref="setSectionEl('subtasks')" data-section="subtasks" class="tp-sec">
            <div class="tp-section-row">
              <h2 class="tp-section">Sub-tasks</h2>
              <button class="link-btn" @click="showSubtask = true">+ Add Sub-Task</button>
            </div>
            <p v-if="!task.subtasks.length" class="tp-muted tp-quiet">
              No sub-tasks. A sub-task is a task — it gets its own owner, deadline and status.
            </p>
            <!-- Rows, not a table. A sub-task is still a whole task, so its
                 owner and status ride along at the end of the line rather than
                 in columns that need a header to explain them. -->
            <ul v-else class="tp-subs">
              <li v-for="s in orderedSubtasks" :key="s.id" :class="['tp-sub', { done: s.is_done }]">
                <button :class="['tp-tick', { on: s.is_done }]" :disabled="saving"
                        :aria-label="s.is_done ? `Reopen ${s.title}` : `Complete ${s.title}`"
                        :aria-pressed="s.is_done" @click="toggleSubtask(s)">
                  <span v-if="s.is_done">✓</span>
                </button>
                <button class="tp-sub-title" @click="router.push({ name: 'task', params: { id: s.id } })">
                  {{ s.title }}
                </button>
                <span v-if="!s.is_done && s.status !== 'todo' && s.status !== 'open'"
                      :class="['pill', `pill-${statusTone(s.status)}`]">{{ titleCase(s.status) }}</span>
                <span v-if="s.assignee?.name" class="tp-avatar sm" :title="s.assignee.name">
                  {{ initials(s.assignee.name) }}</span>
                <span v-if="s.deadline" class="tp-sub-due">{{ date(s.deadline) }}</span>
              </li>
            </ul>
          </section>

          <!-- ── Dependencies ───────────────────────────────────────────── -->
          <section :ref="setSectionEl('dependencies')" data-section="dependencies" class="tp-sec">
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
          </section>

          <!-- ── Assisting ──────────────────────────────────────────────── -->
          <section :ref="setSectionEl('assisting')" data-section="assisting" class="tp-sec">
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
          </section>

          <!-- ── Comments ───────────────────────────────────────────────── -->

          <!-- ── Resources ──────────────────────────────────────────────── -->
          <section :ref="setSectionEl('resources')" data-section="resources" class="tp-sec">
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
          </section>

          <!-- ── Meetings ───────────────────────────────────────────────── -->

          <!-- ── Activities ─────────────────────────────────────────────── -->
          <!-- ── What came of it ────────────────────────────────────────── -->
          <section v-if="task.closure" class="tp-sec">
            <div class="tp-section-row">
              <h2 class="tp-section">Closure summary</h2>
              <span class="tp-quiet tp-closed-meta">
                Closed off {{ date(task.closure.closed_at) }}<template v-if="task.closure.closed_by"> by {{ task.closure.closed_by }}</template>
              </span>
            </div>
            <dl class="tp-closure">
              <template v-for="f in CLOSURE_FIELDS" :key="f.key">
                <div v-if="task.closure[f.key as keyof Closure]" class="tp-closure-row">
                  <dt>{{ f.label }}</dt>
                  <dd>{{ task.closure[f.key as keyof Closure] }}</dd>
                </div>
              </template>
            </dl>
          </section>

          <!-- ── Attachments ────────────────────────────────────────────── -->
          <section :ref="setSectionEl('attachments')" data-section="attachments" class="tp-sec">
            <div class="tp-section-row">
              <h2 class="tp-section">Attachments</h2>
              <label class="tp-add">
                + Add
                <input type="file" hidden @change="attach" />
              </label>
            </div>
            <div class="tp-files">
              <a v-for="a in task.attachments" :key="a.id" class="tp-file"
                 :href="`/api/v1/tasks/${task.id}/attachments/${a.id}/download?org=${org()}`">
                <span class="tp-file-thumb">{{ (a.original_name ?? a.label ?? "?").split(".").pop()?.slice(0, 4).toUpperCase() }}</span>
                <span class="tp-file-name">{{ a.original_name ?? a.label }}</span>
                <span class="tp-file-size">{{ size(a.size_bytes) }}</span>
              </a>
              <label class="tp-file-drop">
                <span>+ Drop a file</span>
                <input type="file" hidden @change="attach" />
              </label>
            </div>
          </section>

          <section :ref="setSectionEl('activities')" data-section="activities" class="tp-sec">
            <h2 class="tp-section">Activities</h2>

            <!-- Every time the date moved, and what was said about it. This is
                 the record a delivery review reads; the deadline column only
                 ever knew the latest answer. -->
            <ol v-if="task.extensions.length" class="tp-slips">
              <li v-for="e in task.extensions" :key="e.id" class="tp-slip">
                <div class="tp-slip-head">
                  <span class="tp-slip-move">
                    {{ e.previous_deadline ? date(e.previous_deadline) : "unset" }} → {{ date(e.new_deadline) }}
                  </span>
                  <span v-if="e.days_moved" class="pill pill-warn">+{{ e.days_moved }} days</span>
                  <span v-if="e.responsible_party" class="tp-slip-party">{{ words(e.responsible_party) }}</span>
                  <span class="tp-slip-when">{{ ago(e.at) }}<template v-if="e.requested_by"> · {{ e.requested_by }}</template></span>
                </div>
                <p class="tp-slip-text">{{ e.reason }}</p>
                <p class="tp-slip-text muted">Impact: {{ e.impact }}</p>
                <p v-if="e.dependency" class="tp-slip-text muted">Blocked by: {{ e.dependency }}</p>
              </li>
            </ol>

            <!-- Every stretch it spent waiting on somebody else. -->
            <ol v-if="task.pause_history.length" class="tp-slips">
              <li v-for="p in task.pause_history" :key="p.id" class="tp-slip">
                <div class="tp-slip-head">
                  <span class="tp-slip-move">Paused · {{ words(p.reason) }}</span>
                  <span :class="['pill', p.resumed_at ? 'pill-ok' : 'pill-warn']">
                    {{ p.days }} day{{ p.days === 1 ? "" : "s" }}{{ p.resumed_at ? "" : " and counting" }}
                  </span>
                  <span v-if="p.responsible_party" class="tp-slip-party">{{ words(p.responsible_party) }}</span>
                  <span class="tp-slip-when">{{ ago(p.paused_at) }}</span>
                </div>
                <p v-if="p.note" class="tp-slip-text">{{ p.note }}</p>
                <p v-if="p.resume_note" class="tp-slip-text muted">Back on: {{ p.resume_note }}</p>
              </li>
            </ol>

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
          </section>

          <!-- ── Issues ─────────────────────────────────────────────────── -->
          <section :ref="setSectionEl('issues')" data-section="issues" class="tp-sec">
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
          </section>
        </div>

        <!-- ── Properties ───────────────────────────────────────────────────
             Everything edits where it is read. The old grid of read-only facts
             sent every change through one "Edit details" dialog, which meant
             nine fields opened a form to move one of them. -->
        <aside class="tp-rail">
          <div class="tp-rail-inner">
          <button v-if="!task.is_done && task.status !== 'awaiting_approval'"
                  class="tp-do" :disabled="saving" @click="markComplete">
            ✓ {{ task.requires_approval && !task.approved_at ? "Send for approval" : "Mark complete" }}
          </button>
          <span v-else-if="task.status === 'awaiting_approval'" class="tp-do waiting">
            ⏳ Waiting on {{ task.approver?.name ?? "an approver" }}
          </span>
          <span v-else class="tp-do done">✓ Completed</span>

          <!--
            The two ways work stops, and the one way a problem gets named.

            Pause and Extend are for live work — a finished task has nothing to
            stop and no deadline left to move, and the server refuses both.
            Raising an issue is not like them: a defect found after the fact is
            exactly when somebody needs to record one, and the API has always
            allowed it. Hiding the button on a completed task made the panel
            stricter than the rule it was enforcing.

            Icons drawn rather than typed: Google Sans has no ⏸ or 📅, and an
            emoji it cannot render is a tofu box on the primary control.
          -->
          <div class="tp-actions">
            <template v-if="!task.is_done">
              <button v-if="!task.is_paused" class="tp-action" :disabled="saving" @click="openPause">
                <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                  <rect x="4" y="3" width="3" height="10" rx="1" /><rect x="9" y="3" width="3" height="10" rx="1" />
                </svg>
                Pause
              </button>
              <button v-else class="tp-action resume" :disabled="saving" @click="resumeTask">
                <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5 3l8 5-8 5z" /></svg>
                Resume
              </button>
              <button class="tp-action" :disabled="saving" @click="openExtend">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                  <rect x="2.5" y="3.5" width="11" height="10" rx="1.5" /><path d="M2.5 6.5h11M5.5 2v3M10.5 2v3" />
                </svg>
                Extend
              </button>
            </template>
            <button class="tp-action" :disabled="saving" @click="showIssue = true">
              <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                <path d="M8 2.5L14.5 13.5h-13z" /><path d="M8 6.5v3M8 11.5v.5" />
              </svg>
              Raise
            </button>
          </div>

          <!-- How late it is, said plainly. `is_overdue` says whether; this
               says how far, which is what decides who reads it now. -->
          <div v-if="task.days_overdue" class="tp-late">
            {{ task.days_overdue }} day{{ task.days_overdue === 1 ? "" : "s" }} overdue<template
              v-if="task.paused_days">, {{ task.paused_days }} of them paused</template>
          </div>

          <!-- Who has been told. A rung reached with nobody on it is shown as
               such, because that is a gap in the org chart, not a non-event. -->
          <div v-if="task.escalations.length" class="tp-esc">
            <span class="tp-esc-head">Escalated to</span>
            <span v-for="e in task.escalations" :key="e.id" class="tp-esc-row" :title="e.detail">
              <span class="tp-esc-level">{{ e.level_label }}</span>
              <span v-if="e.to" class="tp-esc-who">{{ e.to }}</span>
              <span v-else class="tp-esc-who none">nobody holds this role</span>
            </span>
          </div>

          <!-- What it is waiting for, and whether that wait has run over. -->
          <div v-if="task.pause" :class="['tp-paused', { over: task.pause.is_overdue }]">
            <span class="tp-paused-head">
              <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <rect x="4" y="3" width="3" height="10" rx="1" /><rect x="9" y="3" width="3" height="10" rx="1" />
              </svg>
              {{ words(task.pause.reason) }}
            </span>
            <span class="tp-paused-meta">
              {{ task.pause.days }} day{{ task.pause.days === 1 ? "" : "s" }} so far<template v-if="task.pause.expected_resume_on">
              · back {{ date(task.pause.expected_resume_on) }}</template>
            </span>
            <span v-if="task.pause.is_overdue" class="tp-paused-late">Past the date it was promised back.</span>
            <span v-if="task.pause.note" class="tp-paused-note">{{ task.pause.note }}</span>
          </div>

          <div class="tp-props">
            <!-- Twelve kinds of work, not the four a task board usually has —
                 so one chip carrying the current value, which opens the rest.
                 The select is real and native; it is only dressed as the chip. -->
            <label class="tp-prop">
              <span class="tp-prop-key">Category</span>
              <span :class="['tp-chip-wrap', categoryTone(task.category)]">
                <span class="tp-kind-dot"></span>
                <select class="tp-chip-select" :value="task.category" :disabled="saving"
                        @change="send('PUT', '', { title: task.title, category: ($event.target as HTMLSelectElement).value })">
                  <option v-for="c in CATEGORIES" :key="c" :value="c">{{ titleCase(c) }}</option>
                </select>
              </span>
            </label>

            <div v-if="task.can_set_involvement" class="tp-prop">
              <span class="tp-prop-key">Your involvement</span>
              <div class="tp-seg" role="group" aria-label="Your involvement">
                <button v-for="i in INVOLVEMENTS" :key="i.id"
                        :class="['tp-seg-btn', { on: task.my_involvement === i.id }]"
                        :disabled="saving || (i.id === 'doing' && !task.can_take_task)"
                        :title="i.id === 'doing' && !task.can_take_task
                          ? 'Taking a task on means changing it, which you cannot do here.'
                          : (task.my_involvement === i.id ? 'Step back out of this task' : i.says)"
                        @click="setInvolvement(i.id)">{{ i.label }}</button>
              </div>
            </div>

            <!-- Who is holding it up, which is not who owes the work. -->
            <label class="tp-prop">
              <span class="tp-prop-key">Waiting on</span>
              <span :class="['tp-chip-wrap', 'resp', responsibility?.tone ?? 'none']">
                <span class="tp-kind-dot"></span>
                <select class="tp-chip-select" :value="task.responsibility ?? ''" :disabled="saving"
                        @change="send('PUT', '', { title: task.title, responsibility: ($event.target as HTMLSelectElement).value || null })">
                  <option value="">Nobody yet</option>
                  <option v-for="r in RESPONSIBILITIES" :key="r.id" :value="r.id">{{ r.label }}</option>
                </select>
              </span>
            </label>

            <label class="tp-prop">
              <span class="tp-prop-key">Status</span>
              <span :class="['tp-chip-wrap', 'status', statusTone(task.status)]">
                <select class="tp-chip-select" :value="task.status" :disabled="saving"
                        @change="send('PUT', '', { title: task.title, status: ($event.target as HTMLSelectElement).value })">
                  <option v-for="s in STATUSES" :key="s" :value="s">{{ titleCase(s) }}</option>
                </select>
              </span>
            </label>

            <!-- Read as a date, edited as one. A native date field left on show
                 says "mm/dd/yyyy" at a task that simply has no deadline. -->
            <div class="tp-prop">
              <span class="tp-prop-key">Deadline</span>
              <input v-if="editingDeadline" ref="deadlineEl" type="date" class="tp-prop-input"
                     :value="task.deadline ?? ''" :disabled="saving"
                     @blur="editingDeadline = false"
                     @change="editingDeadline = false; send('PUT', '', { title: task.title, deadline: ($event.target as HTMLInputElement).value || null })" />
              <button v-else class="tp-prop-edit" @click="openDeadline">
                <span v-if="task.deadline" class="tp-prop-value">{{ date(task.deadline) }}</span>
                <span v-else class="tp-prop-value unset">No deadline</span>
                <span v-if="task.deadline" :class="['tp-prop-aside', { late: task.is_overdue }]">· {{ dueIn(task.deadline) }}</span>
              </button>
              <!-- How many times it has moved. One slip is information; four is
                   a problem with the plan. -->
              <button v-if="task.extension_count" class="tp-prop-aside link" @click="goToSection('activities')">
                Moved {{ task.extension_count }} time{{ task.extension_count === 1 ? "" : "s" }}<template
                  v-if="task.paused_days">, {{ task.paused_days }} days paused</template>
              </button>
            </div>

            <label class="tp-prop">
              <span class="tp-prop-key">Priority</span>
              <span class="tp-prop-row">
                <span :class="['tp-swatch', task.priority]"></span>
                <select class="tp-bare-select" :value="task.priority" :disabled="saving"
                        @change="send('PUT', '', { title: task.title, priority: ($event.target as HTMLSelectElement).value })">
                  <option v-for="p in PRIORITIES" :key="p" :value="p">{{ titleCase(p) }}</option>
                </select>
              </span>
            </label>

            <!-- Named rather than a row of initials: two circles reading "MI"
                 and "MO" tell you how many people are on it and nothing else. -->
            <div class="tp-prop">
              <span class="tp-prop-key">Assignees</span>

              <ul v-if="task.assignees.length" class="tp-people">
                <li v-for="(p, i) in task.assignees" :key="p.id ?? i" class="tp-person-row">
                  <span class="tp-avatar sm">{{ initials(p.name) }}</span>
                  <span class="tp-person-name">{{ p.name }}</span>
                  <!-- The first is the one named on the task and accountable
                       for it; the rest are hands on the same work. -->
                  <span v-if="i === 0" class="tp-person-lead" title="Accountable for this task">owner</span>
                  <button class="tp-person-x" :disabled="saving"
                          :aria-label="`Take ${p.name} off this task`" @click="removeAssignee(p)">×</button>
                </li>
              </ul>
              <span v-else class="tp-prop-value unset">Nobody yet</span>

              <button v-if="!assigning" class="tp-add-person" @click="assigning = true">+ Add somebody</button>
              <div v-else class="tp-assign-box">
                <EmployeePicker v-model="assignTo"
                                :placeholder="task.assignees.length ? 'Who else is on this?' : 'Who should own this?'" />
                <button class="tp-add-person" @click="assigning = false; assignTo = null">Cancel</button>
              </div>
            </div>

            <div class="tp-prop">
              <span class="tp-prop-key">Owed by</span>
              <span v-if="task.owner.is_ours" class="tp-prop-value">Us</span>
              <span v-else class="tp-prop-value external">{{ task.owner.name ?? "Outside" }}</span>
            </div>

            <!-- The comparison the legacy had the data for on nine tasks in ten
                 and never once made. -->
            <div class="tp-prop">
              <span class="tp-prop-key">Against estimate</span>
              <span v-if="variance" :class="['pill', `pill-${variance.tone}`]">{{ variance.text }}</span>
              <span v-else class="tp-prop-value unset">Not estimated</span>
              <!-- Counting somebody else's hours as our capacity makes our own
                   delivery look worse than it is. -->
              <span v-if="!task.owner.is_ours" class="tp-prop-aside">Not our effort.</span>
            </div>

            <!-- Placement down the org hierarchy. Read here, changed in the
                 dialog: three dependent selects do not fit a 250px rail. -->
            <div v-if="task.function" class="tp-prop">
              <span class="tp-prop-key">Function</span>
              <span class="tp-prop-value">{{ task.function.name }}</span>
            </div>
            <div v-if="task.department" class="tp-prop">
              <span class="tp-prop-key">Department</span>
              <span class="tp-prop-value">{{ task.department.name }}</span>
            </div>
            <div v-if="task.business_unit" class="tp-prop">
              <span class="tp-prop-key">Business unit</span>
              <span class="tp-prop-value">{{ task.business_unit.name }}</span>
            </div>
          </div>

          <button class="tp-more" @click="beginEdit">More details…</button>
          </div>
        </aside>
        </div>
      </template>

      <!-- ── Modals ───────────────────────────────────────────────────────── -->

      <!-- Closing a task off. Not a confirmation dialog — the summary is the
           thing being asked for, and the task does not finish without it. -->
      <div v-if="closing" class="tp-modal-backdrop" @click.self="closing = false">
        <div class="tp-modal tp-modal-lg" role="dialog" aria-modal="true" aria-label="Close this task off">
          <div class="tp-modal-head">
            <h3 class="tp-modal-title">Close off — {{ task?.title }}</h3>
            <button class="tp-close" aria-label="Close" @click="closing = false">×</button>
          </div>
          <p class="tp-quiet">
            A completion date on its own tells a review nothing. This is what it reads.
          </p>

          <label v-for="f in CLOSURE_FIELDS" :key="f.key" class="tp-field">
            <span>{{ f.label }}<em v-if="!f.required" class="tp-optional"> · optional</em></span>
            <textarea v-model="closureForm[f.key as keyof typeof closureForm]" class="control" rows="2"
                      :placeholder="f.hint"></textarea>
          </label>

          <p v-if="closureError" class="tp-modal-error">{{ closureError }}</p>

          <div class="tp-modal-actions">
            <button class="link-btn" @click="closing = false">Cancel</button>
            <button class="btn-primary" :disabled="!closureReady || saving" @click="submitClosure">
              {{ task?.requires_approval && !task?.approved_at ? "Send for approval" : "Close off task" }}
            </button>
          </div>
        </div>
      </div>

      <!-- Moving a deadline. The reason is the record; the date is the side
           effect — so the form asks for the reason first. -->
      <div v-if="extending" class="tp-modal-backdrop" @click.self="extending = false">
        <div class="tp-modal tp-modal-lg" role="dialog" aria-modal="true" aria-label="Extend the deadline">
          <div class="tp-modal-head">
            <h3 class="tp-modal-title">Move the deadline</h3>
            <button class="tp-close" aria-label="Close" @click="extending = false">×</button>
          </div>
          <p class="tp-quiet">
            <template v-if="task?.deadline">Currently due {{ date(task.deadline) }}.</template>
            <template v-if="task?.extension_count"> This would be move {{ task.extension_count + 1 }}.</template>
          </p>

          <div class="tp-row">
            <label class="tp-field"><span>New target date</span>
              <input v-model="extendForm.new_deadline" class="control" type="date" /></label>
            <label class="tp-field"><span>Whose delay is it</span>
              <select v-model="extendForm.responsible_party" class="control">
                <option value="">Not saying</option>
                <option v-for="r in RESPONSIBILITIES" :key="r.id" :value="r.id">{{ r.label }}</option>
              </select></label>
          </div>
          <label class="tp-field"><span>Why it is moving</span>
            <textarea v-model="extendForm.reason" class="control" rows="2"
                      placeholder="What happened."></textarea></label>
          <label class="tp-field"><span>Impact</span>
            <textarea v-model="extendForm.impact" class="control" rows="2"
                      placeholder="What this pushes, and what it does not."></textarea></label>
          <label class="tp-field"><span>Dependency causing the delay<em class="tp-optional"> · optional</em></span>
            <input v-model="extendForm.dependency" class="control"
                   placeholder="What is holding it up." /></label>

          <p v-if="extendError" class="tp-modal-error">{{ extendError }}</p>
          <div class="tp-modal-actions">
            <button class="link-btn" @click="extending = false">Cancel</button>
            <button class="btn-primary" :disabled="!extendReady || saving" @click="submitExtend">Move it</button>
          </div>
        </div>
      </div>

      <!-- Stopping the clock. -->
      <div v-if="pausing" class="tp-modal-backdrop" @click.self="pausing = false">
        <div class="tp-modal tp-modal-lg" role="dialog" aria-modal="true" aria-label="Pause the task">
          <div class="tp-modal-head">
            <h3 class="tp-modal-title">Pause — what is it waiting for?</h3>
            <button class="tp-close" aria-label="Close" @click="pausing = false">×</button>
          </div>
          <p class="tp-quiet">
            Time spent paused is counted separately, so a wait on somebody else
            does not read as time we sat on it.
          </p>

          <div class="tp-field"><span>Waiting for</span>
            <div class="tp-reasons">
              <div v-for="f in PAUSE_FAMILIES" :key="f.id" class="tp-reason-group">
                <span class="tp-reason-head">{{ f.label }}</span>
                <button v-for="[id, label] in f.reasons" :key="id"
                        :class="['tp-reason', { on: pauseForm.reason === id }]"
                        @click="pauseForm.reason = id">{{ label }}</button>
              </div>
            </div>
          </div>

          <label class="tp-field"><span>Expected back<em class="tp-optional"> · optional, but it is what chases it</em></span>
            <input v-model="pauseForm.expected_resume_on" class="control" type="date" /></label>
          <label class="tp-field"><span>Anything worth noting<em class="tp-optional"> · optional</em></span>
            <textarea v-model="pauseForm.note" class="control" rows="2"
                      placeholder="Who it went to, when, and what was asked."></textarea></label>

          <p v-if="pauseError" class="tp-modal-error">{{ pauseError }}</p>
          <div class="tp-modal-actions">
            <button class="link-btn" @click="pausing = false">Cancel</button>
            <button class="btn-primary" :disabled="!pauseForm.reason || saving" @click="submitPause">Pause it</button>
          </div>
        </div>
      </div>

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

      <div v-if="showScheduleMeeting" class="tp-modal-backdrop" @click.self="showScheduleMeeting = false">
        <div class="tp-modal" role="dialog" aria-modal="true" aria-label="Schedule meeting">
          <div class="tp-modal-head">
            <h3 class="tp-modal-title">Schedule meeting</h3>
            <button class="tp-close" aria-label="Close" @click="showScheduleMeeting = false">×</button>
          </div>
          <p class="tp-hint">
            A Teams meeting is created and tracked on the
            {{ meetingDest.type === 'project' ? 'project' : meetingDest.type === 'ticket' ? 'ticket' : 'task' }}.
          </p>

          <label class="tp-field"><span>Subject</span>
            <input v-model="meetingDraft.subject" class="control" placeholder="Meeting subject" /></label>
          <div class="tp-meet-grid">
            <label class="tp-field"><span>When</span>
              <input v-model="meetingDraft.scheduled_at" class="control" type="datetime-local" /></label>
            <label class="tp-field"><span>Minutes</span>
              <input v-model.number="meetingDraft.duration_minutes" class="control" type="number" min="5" max="480" step="5" /></label>
          </div>
          <label class="tp-field"><span>Agenda</span>
            <textarea v-model="meetingDraft.agenda" class="control" rows="2" placeholder="What it is for (optional)"></textarea></label>

          <div v-if="candidates.length" class="tp-field">
            <span>Invite</span>
            <label v-for="c in candidates" :key="c.address" class="tp-check">
              <input v-model="meetingChecks[c.address]" type="checkbox" />
              <span>{{ c.name || c.address }} <em class="tp-meet-group">{{ c.group }}</em></span>
            </label>
          </div>
          <label class="tp-field"><span>Other emails</span>
            <input v-model="meetingDraft.extra_attendees" class="control" placeholder="name@firm.com, another@firm.com" /></label>

          <p v-if="meetingError" class="tp-meet-error">{{ meetingError }}</p>
          <button class="btn-primary btn-block" :disabled="meetingBusy || !meetingDraft.subject.trim()" @click="scheduleMeeting">
            {{ meetingBusy ? "Scheduling…" : "Schedule meeting" }}
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
        <div class="tp-modal tp-modal-lg" role="dialog" aria-modal="true" aria-label="Edit task">
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
          <!-- Assign to: down the org hierarchy — Function › Department ›
               Business unit — then, if needed, one person from that department.
               Each level narrows the next; all optional. -->
          <div class="tp-assign">
            <span class="tp-assign-label">Assign to</span>
            <div class="tp-assign-grid">
              <label class="tp-field"><span>Function</span>
                <select v-model="form.functional_unit_id" class="control">
                  <option :value="null">Any function</option>
                  <option v-for="u in functionalUnits" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select></label>
              <label class="tp-field"><span>Department</span>
                <select v-model="form.department_id" class="control">
                  <option :value="null">Any department</option>
                  <option v-for="d in departmentOptions" :key="d.id" :value="d.id">{{ d.name }}</option>
                </select></label>
              <label class="tp-field"><span>Business unit</span>
                <select v-model="form.business_unit_id" class="control">
                  <option :value="null">Any business unit</option>
                  <option v-for="b in businessUnitOptions" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select></label>
            </div>
            <div class="tp-field"><span>Individual</span>
              <EmployeePicker v-model="form.assigned_to" :department-id="form.department_id"
                              :placeholder="form.department_id ? 'A specific person in this department' : 'A specific person'" /></div>
          </div>
          <label class="tp-check">
            <input v-model="form.requires_approval" type="checkbox" />
            <span>Somebody has to sign this off before it counts as done.</span>
          </label>
          <!-- Without a name, "needs approval" leaves the task waiting on
               nobody in particular. -->
          <div v-if="form.requires_approval" class="tp-field"><span>Who approves it</span>
            <EmployeePicker v-model="form.approver_id" placeholder="Who signs it off?" />
          </div>

          <label class="tp-field"><span>Who owes the work</span>
            <select v-model="form.owner_party" class="control">
              <option value="internal">Us</option>
              <option value="customer">A customer</option>
              <option value="vendor">A vendor or OEM</option>
            </select></label>

          <div v-if="form.owner_party === 'customer'" class="tp-field"><span>Which customer</span>
            <AccountPicker v-model="form.owner_account_id" placeholder="Which customer owes this?" /></div>

          <div v-if="form.owner_party === 'vendor'" class="tp-field"><span>Which vendor</span>
            <VendorPartnerPicker v-model="form.owner_vendor_partner_id" placeholder="Which vendor owes this?" /></div>

          <label v-if="form.owner_party !== 'internal'" class="tp-field"><span>Who there</span>
            <input v-model="form.owner_contact_name" class="control" placeholder="Milton Ope" />
            <span class="tp-hint">Typed, not picked — their engineer is rarely on our books.</span>
          </label>
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
/* Three columns need room the old 786px frame did not have: at that width the
   middle would be ~366px, narrower than the description it has to hold. */
.tp-panel {
  @apply flex flex-col; width: min(70rem, 96vw); max-height: calc(100vh - 3rem);
  background: var(--color-surface-raised); border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg); animation: panel-in 160ms ease; overflow: hidden;
}
@keyframes panel-in { from { transform: translateY(8px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .tp-panel { animation: none; } }
.tp-loading { padding: var(--space-8); color: var(--color-text-muted); }

.tp-head {
  /* The bar stays put; the grid below it is what scrolls. */
  @apply flex shrink-0 items-center justify-between; gap: var(--space-3);
  padding: var(--space-3) var(--space-5); border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-sunken);
}
.tp-crumb { @apply flex flex-wrap items-center; gap: var(--space-2); margin: 0; font-size: var(--text-xs-size); }
.tp-crumb-up {
  @apply cursor-pointer border-none bg-transparent; padding: 0;
  font-family: inherit; font-size: var(--text-xs-size); color: var(--color-text-light);
}
.tp-crumb-up:hover { color: var(--color-brand-base); }
.tp-crumb-sep { color: var(--color-neutral-300); }
.tp-ref { font-weight: 500; color: var(--color-text); font-variant-numeric: tabular-nums; }
/* The kind of work, read-only here and editable in the rail. */
.tp-kind {
  @apply inline-flex items-center; gap: var(--space-2); margin-left: 4px;
  padding: 3px var(--space-2); border-radius: var(--radius-full);
  border: 1px solid var(--color-border); background: var(--color-surface-raised);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
}
.tp-kind-dot { @apply inline-block; width: 6px; height: 6px; border-radius: 2px; background: var(--color-text-light); }
.tp-kind.brand  { background: var(--color-brand-light); border-color: var(--color-brand-light); color: var(--color-brand-dark); }
.tp-kind.brand  .tp-kind-dot { background: var(--color-brand-base); }
.tp-kind.teal   .tp-kind-dot { background: var(--color-teal); }
.tp-kind.amber  .tp-kind-dot { background: var(--color-amber); }
.tp-kind.indigo .tp-kind-dot { background: var(--color-indigo); }
.tp-head-actions { @apply flex shrink-0 items-center; gap: var(--space-2); }
.tp-icon {
  @apply inline-flex cursor-pointer items-center justify-center;
  width: 2rem; height: 2rem; border-radius: var(--radius-md);
  border: 1px solid var(--color-border); background: var(--color-surface); color: var(--color-text-muted);
}
.tp-icon svg { width: 1rem; height: 1rem; }

/* ── The three columns ──────────────────────────────────────────────────────
   The grid is the scroll container so the side columns can stick to the top of
   it; `align-self: start` keeps them their own height rather than being
   stretched to the full row, which sticky needs to have anywhere to travel. */
.tp-grid {
  @apply grid; flex: 1 1 auto; min-height: 0; overflow-y: auto;
  grid-template-columns: 184px minmax(0, 1fr) 250px;
}
/* The column stretches so its ground runs the full height of the panel; the
   list inside it is what sticks. Making the column itself sticky would need
   `align-self: start`, and then its background would stop wherever the list
   ended, leaving the ground half-painted. */
.tp-nav {
  border-right: 1px solid var(--color-border); background: var(--color-surface-sunken);
}
.tp-nav-inner {
  @apply flex flex-col; position: sticky; top: 0; gap: 1px;
  padding: var(--space-5) var(--space-3);
}
.tp-nav-head {
  padding: 0 var(--space-3) var(--space-2); font-family: var(--font-family-mono);
  font-size: 0.66rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
  color: var(--color-text-light);
}
.tp-nav-head.quiet { color: var(--color-neutral-300); padding-top: var(--space-1); }
.tp-nav-item {
  @apply flex cursor-pointer items-center justify-between border-none bg-transparent;
  gap: var(--space-2); padding: 7px var(--space-3); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500;
  color: var(--color-text-muted); text-align: left;
  transition: background-color 120ms ease, color 120ms ease;
}
/* neutral-200, not -100: the nav sits on `surface-sunken`, which is the same
   colour as neutral-100 in the light theme, so the hover would not show. */
.tp-nav-item:hover { background: var(--color-neutral-200); color: var(--color-text); }
.tp-nav-item.active { background: var(--color-brand-light); color: var(--color-brand-dark); font-weight: 600; }
/* Nothing in them, so they say so by going quiet rather than by disappearing —
   an empty section still has to be reachable to put the first thing in it. */
.tp-nav-item.empty { color: var(--color-neutral-300); font-weight: 400; padding: 6px var(--space-3); }
.tp-nav-item.empty:hover { color: var(--color-text-muted); }
.tp-nav-count { font-family: var(--font-family-mono); font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-light); }
.tp-nav-item.active .tp-nav-count { color: var(--color-brand-dark); }
.tp-nav-rule { height: 1px; background: var(--color-border); margin: var(--space-2) var(--space-3); }

.tp-content { min-width: 0; padding: var(--space-6) var(--space-8) var(--space-8); }
.tp-sec { margin-top: var(--space-6); padding-top: var(--space-5); border-top: 1px solid var(--color-border); scroll-margin-top: var(--space-4); }
.tp-sec-open { margin-top: 0; padding-top: 0; border-top: none; }
.tp-sec-head { @apply flex flex-wrap items-center justify-between; gap: var(--space-2); margin-top: var(--space-5); }
.tp-sec > .tp-sec-head:first-child, .tp-sec > .tp-section-row:first-child { margin-top: 0; }
.tp-add {
  @apply inline-flex cursor-pointer items-center border-none bg-transparent;
  font-family: inherit; font-size: var(--text-xs-size); font-weight: 500; color: var(--color-brand-base);
}
.tp-add:hover { color: var(--color-brand-hover); }

/* ── Properties rail ─────────────────────────────────────────────────────── */
.tp-rail {
  border-left: 1px solid var(--color-border); background: var(--color-surface-sunken);
}
.tp-rail-inner { position: sticky; top: 0; padding: var(--space-6) var(--space-5); }
.tp-do {
  @apply w-full cursor-pointer border-none; padding: var(--space-3);
  border-radius: var(--radius-lg); background: var(--color-brand-base);
  color: var(--color-on-brand); font-family: inherit; font-size: var(--text-sm-size); font-weight: 600;
  transition: background-color 120ms ease;
}
.tp-do:hover:not(:disabled) { background: var(--color-brand-hover); }
.tp-do:disabled { @apply cursor-not-allowed opacity-50; }
.tp-do.done, .tp-do.waiting {
  @apply block; text-align: center; background: transparent;
  border: 1px solid var(--color-border-strong); font-weight: 500;
}
.tp-do.done { color: var(--color-success); border-color: color-mix(in oklab, var(--color-success) 35%, transparent); }
.tp-do.waiting { color: var(--color-warning); border-color: color-mix(in oklab, var(--color-warning) 35%, transparent); }

/* The two ways work stops, sitting under the one way it finishes. */
.tp-actions { @apply flex; gap: var(--space-2); margin-top: var(--space-2); }
.tp-action {
  @apply flex flex-1 cursor-pointer items-center justify-center; gap: var(--space-1);
  padding: 6px var(--space-2); border-radius: var(--radius-lg);
  border: 1px solid var(--color-border-strong); background: var(--color-surface-raised);
  font-family: inherit; font-size: var(--text-xs-size); font-weight: 500; color: var(--color-text-muted);
  transition: border-color 120ms ease, color 120ms ease;
}
.tp-action svg { width: 0.85rem; height: 0.85rem; flex: none; }
.tp-paused-head svg { width: 0.8rem; height: 0.8rem; flex: none; }
.tp-action:hover:not(:disabled) { border-color: var(--color-brand-base); color: var(--color-brand-base); }
.tp-action.resume { color: var(--color-teal); border-color: color-mix(in oklab, var(--color-teal) 40%, transparent); }
.tp-action:disabled { @apply cursor-not-allowed opacity-50; }

/* What it is waiting for, said where the status is read. */
.tp-paused {
  @apply flex flex-col; gap: 2px; margin-top: var(--space-3);
  padding: var(--space-3); border-radius: var(--radius-md);
  background: color-mix(in oklab, var(--color-amber) 14%, transparent);
  border-left: 3px solid var(--color-amber);
}
.tp-paused.over { background: color-mix(in oklab, var(--color-error) 12%, transparent); border-left-color: var(--color-error); }
.tp-paused-head { @apply flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); }
.tp-paused-meta, .tp-paused-note { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tp-paused-late { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-error); }

/* How late, and who knows. */
.tp-late {
  margin-top: var(--space-3); padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-md); font-size: var(--text-xs-size); font-weight: 600;
  background: color-mix(in oklab, var(--color-error) 12%, transparent); color: var(--color-error);
}
.tp-esc { @apply flex flex-col; gap: 2px; margin-top: var(--space-3); }
.tp-esc-head {
  font-family: var(--font-family-mono); font-size: 0.66rem; letter-spacing: .06em;
  text-transform: uppercase; color: var(--color-text-light);
}
.tp-esc-row { @apply flex flex-wrap items-baseline; gap: var(--space-2); font-size: var(--text-xs-size); }
.tp-esc-level { color: var(--color-text-muted); }
.tp-esc-who { font-weight: 500; color: var(--color-text); }
.tp-esc-who.none { font-weight: 400; color: var(--color-error); font-style: italic; }

/* Every time the date moved, and every stretch spent waiting. */
.tp-slips { @apply flex flex-col; gap: var(--space-3); list-style: none; margin: var(--space-2) 0 var(--space-5); padding: 0; }
.tp-slip { padding-left: var(--space-3); border-left: 2px solid var(--color-border); }
.tp-slip-head { @apply flex flex-wrap items-center; gap: var(--space-2); }
.tp-slip-move { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); font-variant-numeric: tabular-nums; }
.tp-slip-party { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tp-slip-when { font-size: var(--text-xs-size); color: var(--color-text-light); margin-left: auto; }
.tp-slip-text { margin: var(--space-1) 0 0; font-size: var(--text-sm-size); color: var(--color-text); max-width: 62ch; }
.tp-slip-text.muted { color: var(--color-text-muted); font-size: var(--text-xs-size); }

/* Pause reasons, grouped by who the wait is on. */
.tp-reasons { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4); }
@media (max-width: 34rem) { .tp-reasons { grid-template-columns: 1fr; } }
.tp-reason-group { @apply flex flex-col items-start; gap: 3px; }
.tp-reason-head {
  font-family: var(--font-family-mono); font-size: 0.66rem; letter-spacing: .06em;
  text-transform: uppercase; color: var(--color-text-light); margin-bottom: 2px;
}
.tp-reason {
  @apply w-full cursor-pointer; padding: 5px var(--space-2); text-align: left;
  border: 1px solid var(--color-border); border-radius: var(--radius-md);
  background: var(--color-surface-raised); font-family: inherit;
  font-size: var(--text-xs-size); color: var(--color-text-muted);
  transition: border-color 120ms ease, background-color 120ms ease, color 120ms ease;
}
.tp-reason:hover { border-color: var(--color-border-strong); color: var(--color-text); }
.tp-reason.on {
  background: var(--color-brand-light); border-color: var(--color-brand-light);
  color: var(--color-brand-dark); font-weight: 600;
}

.tp-props { @apply flex flex-col; margin-top: var(--space-5); }
.tp-prop { @apply flex flex-col items-start; gap: var(--space-1); padding: var(--space-3) 0; border-bottom: 1px solid var(--color-border); }
.tp-prop:last-child { border-bottom: none; }
.tp-prop-key { font-size: var(--text-xs-size); color: var(--color-text-light); }
.tp-prop-value { font-size: var(--text-sm-size); font-weight: 500; color: var(--color-text); }
/* An unset value is deliberately lighter than a set one. */
.tp-prop-value.unset { font-weight: 400; color: var(--color-neutral-300); }
.tp-prop-value.external { color: var(--color-warning); }
.tp-prop-aside { font-size: var(--text-xs-size); color: var(--color-text-light); }
.tp-prop-aside.late { color: var(--color-error); font-weight: 500; }
.tp-prop-aside.link {
  @apply cursor-pointer border-none bg-transparent; padding: 0; text-align: left;
  font-family: inherit; color: var(--color-text-light); text-decoration: underline;
  text-decoration-color: var(--color-border-strong); text-underline-offset: 2px;
}
.tp-prop-aside.link:hover { color: var(--color-brand-base); }
.tp-prop-row { @apply flex items-center; gap: var(--space-2); }

/*
 * A property reads as its value, not as a form.
 *
 * The old panel put nine fields behind one dialog; replacing that with nine
 * permanent input boxes trades one problem for another — a rail of borders and
 * placeholder text competes with the task itself. So the chrome is the chip,
 * and the native control lives inside it.
 */
.tp-chip-wrap {
  @apply inline-flex w-fit max-w-full items-center; gap: var(--space-2);
  padding: 3px var(--space-2) 3px var(--space-3); border-radius: var(--radius-full);
  border: 1px solid var(--color-border); background: var(--color-surface-raised);
  transition: border-color 120ms ease, background-color 120ms ease;
}
.tp-chip-select {
  @apply cursor-pointer appearance-none border-none bg-transparent; padding: 0 var(--space-1) 0 0;
  font-family: inherit; font-size: var(--text-xs-size); font-weight: 600; color: inherit;
}
.tp-chip-select:disabled { @apply cursor-not-allowed opacity-60; }
.tp-chip-wrap:hover { border-color: var(--color-border-strong); }
.tp-chip-wrap:focus-within { outline: 2px solid var(--color-brand-base); outline-offset: 2px; }
/* Tinted per category, the same key the breadcrumb pill uses. */
.tp-chip-wrap.brand  { background: var(--color-brand-light); border-color: var(--color-brand-light); color: var(--color-brand-dark); }
.tp-chip-wrap.brand  .tp-kind-dot { background: var(--color-brand-base); }
.tp-chip-wrap.teal   { background: color-mix(in oklab, var(--color-teal) 12%, transparent); color: var(--color-teal); border-color: transparent; }
.tp-chip-wrap.teal   .tp-kind-dot { background: var(--color-teal); }
.tp-chip-wrap.amber  { background: color-mix(in oklab, var(--color-amber) 16%, transparent); color: var(--color-warning); border-color: transparent; }
.tp-chip-wrap.amber  .tp-kind-dot { background: var(--color-amber); }
.tp-chip-wrap.indigo { background: color-mix(in oklab, var(--color-indigo) 12%, transparent); color: var(--color-indigo); border-color: transparent; }
.tp-chip-wrap.indigo .tp-kind-dot { background: var(--color-indigo); }
.tp-chip-wrap.muted  { background: var(--color-neutral-200); color: var(--color-text-muted); border-color: transparent; }
/* Status borrows the feedback colours rather than the category ones. */
.tp-chip-wrap.status { padding-left: var(--space-3); }
.tp-chip-wrap.status.ok   { background: color-mix(in oklab, var(--color-success) 14%, transparent); color: var(--color-success); border-color: transparent; }
.tp-chip-wrap.status.warn { background: color-mix(in oklab, var(--color-warning) 16%, transparent); color: var(--color-warning); border-color: transparent; }
.tp-chip-wrap.status.bad  { background: color-mix(in oklab, var(--color-error) 14%, transparent); color: var(--color-error); border-color: transparent; }
.tp-chip-wrap.status.info    { background: var(--color-brand-light); color: var(--color-brand-dark); border-color: transparent; }
.tp-chip-wrap.status.neutral { background: var(--color-neutral-200); color: var(--color-text-muted); border-color: transparent; }

/*
 * Responsibility badges. Eight of them, so they carry their own hues rather
 * than the feedback palette — "waiting on finance" is not a success and
 * "waiting on management" is not an error. Mixed into the surface so they stay
 * legible on both themes rather than being fixed light-mode fills.
 */
.tp-chip-wrap.resp        { border-color: transparent; }
.tp-chip-wrap.resp.none   { background: var(--color-neutral-200); color: var(--color-text-light); }
.tp-chip-wrap.resp.blue   { background: color-mix(in oklab, var(--color-brand-base) 14%, transparent); color: var(--color-brand-dark); }
.tp-chip-wrap.resp.blue   .tp-kind-dot { background: var(--color-brand-base); }
.tp-chip-wrap.resp.purple { background: color-mix(in oklab, var(--color-indigo) 14%, transparent); color: var(--color-indigo); }
.tp-chip-wrap.resp.purple .tp-kind-dot { background: var(--color-indigo); }
.tp-chip-wrap.resp.orange { background: color-mix(in oklab, var(--color-warning) 16%, transparent); color: var(--color-warning); }
.tp-chip-wrap.resp.orange .tp-kind-dot { background: var(--color-warning); }
.tp-chip-wrap.resp.yellow { background: color-mix(in oklab, var(--color-amber) 20%, transparent); color: var(--color-warning); }
.tp-chip-wrap.resp.yellow .tp-kind-dot { background: var(--color-amber); }
.tp-chip-wrap.resp.teal   { background: color-mix(in oklab, var(--color-teal) 14%, transparent); color: var(--color-teal); }
.tp-chip-wrap.resp.teal   .tp-kind-dot { background: var(--color-teal); }
.tp-chip-wrap.resp.red    { background: color-mix(in oklab, var(--color-error) 14%, transparent); color: var(--color-error); }
.tp-chip-wrap.resp.red    .tp-kind-dot { background: var(--color-error); }
.tp-chip-wrap.resp.cyan   { background: color-mix(in oklab, var(--color-info) 16%, transparent); color: var(--color-info); }
.tp-chip-wrap.resp.cyan   .tp-kind-dot { background: var(--color-info); }
.tp-chip-wrap.resp.green  { background: color-mix(in oklab, var(--color-success) 16%, transparent); color: var(--color-success); }
.tp-chip-wrap.resp.green  .tp-kind-dot { background: var(--color-success); }

/* The closure summary, once it exists. Read as a record, not a form. */
.tp-closure { @apply flex flex-col; gap: var(--space-4); margin: var(--space-3) 0 0; }
.tp-closure-row { @apply flex flex-col; gap: 2px; }
.tp-closure-row dt { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-light); }
.tp-closure-row dd { margin: 0; font-size: var(--text-sm-size); color: var(--color-text); white-space: pre-wrap; max-width: 62ch; }
.tp-closed-meta { font-size: var(--text-xs-size); }
.tp-optional { font-style: normal; color: var(--color-text-light); font-weight: 400; }
.tp-modal-error { margin: var(--space-2) 0 0; font-size: var(--text-xs-size); color: var(--color-error); }

.tp-bare-select {
  @apply cursor-pointer appearance-none border-none bg-transparent; padding: 0;
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; color: var(--color-text);
}
.tp-bare-select:disabled { @apply cursor-not-allowed opacity-60; }

/* The value, until you click it. */
.tp-prop-edit {
  @apply flex w-full cursor-pointer flex-wrap items-baseline border-none bg-transparent;
  gap: var(--space-1); padding: 2px 0; text-align: left; font-family: inherit;
}
.tp-prop-edit:hover .tp-prop-value { color: var(--color-brand-base); }
.tp-prop-input {
  @apply w-full; padding: 4px var(--space-2);
  border: 1px solid var(--color-brand-base); border-radius: var(--radius-md);
  background: var(--color-surface-raised); color: var(--color-text);
  font-family: inherit; font-size: var(--text-sm-size);
}
.tp-swatch { @apply inline-block shrink-0; width: 7px; height: 7px; border-radius: 2px; background: var(--color-text-light); }
.tp-swatch.high { background: var(--color-error); }
.tp-swatch.medium { background: var(--color-amber); }
.tp-swatch.low { background: var(--color-text-light); }

/*
 * Segmented control — the selected thumb is the raised one.
 *
 * The track is `surface-sunken` and so is the rail behind it, which is the
 * point: the border draws the control and the raised thumb is what stands out.
 * Filling the track with `neutral-100` would be invisible here — in the light
 * theme that token and `surface-sunken` are the same #F3F4F6.
 */
.tp-seg {
  @apply flex w-full; padding: 2px; gap: 2px; border-radius: var(--radius-lg);
  background: var(--color-surface-sunken); border: 1px solid var(--color-border);
}
.tp-seg-btn {
  @apply flex-1 cursor-pointer border-none bg-transparent; padding: 5px 0;
  border-radius: var(--radius-md); font-family: inherit; font-size: var(--text-xs-size);
  font-weight: 500; color: var(--color-text-muted);
  transition: background-color 120ms ease, color 120ms ease;
}
.tp-seg-btn:hover:not(:disabled):not(.on) { color: var(--color-text); }
.tp-seg-btn.on { background: var(--color-surface-raised); color: var(--color-teal); font-weight: 600; box-shadow: var(--shadow-xs); }
.tp-seg-btn:disabled { @apply cursor-not-allowed opacity-45; }

.tp-more {
  @apply w-full cursor-pointer border-none bg-transparent; margin-top: var(--space-4);
  font-family: inherit; font-size: var(--text-xs-size); color: var(--color-text-muted);
}
.tp-more:hover { color: var(--color-brand-base); }

.tp-approval {
  @apply flex flex-wrap items-center justify-between; gap: var(--space-3);
  padding: var(--space-3) var(--space-4); margin-top: var(--space-4);
  background: var(--color-warning-light); border-radius: var(--radius-md);
}
.tp-approval-text { font-size: var(--text-sm-size); color: var(--color-warning); margin: 0; }
.tp-approval-actions { @apply flex; gap: var(--space-2); }
.tp-external {
  font-size: var(--text-sm-size); color: var(--color-warning); margin: var(--space-4) 0 0;
  padding: var(--space-3) var(--space-4); background: var(--color-warning-light);
  border-radius: var(--radius-md);
}

/* ── Edit form: assign to a department, a function, or a person ─────────── */
.tp-assign { @apply flex flex-col; gap: var(--space-2); padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.tp-assign-label { font-size: var(--text-xs-size); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-muted); }
.tp-assign-grid { @apply grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-3); }
@media (max-width: 30rem) { .tp-assign-grid { grid-template-columns: 1fr; } }
.tp-sent-back {
  font-size: var(--text-sm-size); color: var(--color-error); margin: var(--space-4) 0 0;
  padding: var(--space-3) var(--space-4); background: var(--color-error-light);
  border-radius: var(--radius-md);
}
/* The root font-size is 14px, so the rem scale runs smaller than the handoff's
   px values; --display-sm lands at 26.25px, which is the title it asks for. */
.tp-title {
  font-size: var(--display-sm-size); line-height: var(--line-height-tight); font-weight: 700;
  letter-spacing: -.015em; color: var(--color-text); margin: 0; text-wrap: pretty;
}
.tp-byline { @apply flex flex-wrap items-center; gap: var(--space-2); margin: var(--space-3) 0 0; font-size: var(--text-sm-size); color: var(--color-text-muted); }
.tp-byline-rule { width: 1px; height: 14px; background: var(--color-border-strong); }
/* The only line on the panel whose text differs per reader. */
.tp-mine { @apply inline-flex items-center; gap: var(--space-2); font-size: var(--text-xs-size); font-weight: 600; color: var(--color-teal); }
.tp-mine-dot { @apply inline-block; width: 6px; height: 6px; border-radius: 999px; background: var(--color-teal); }
.tp-origin { @apply flex flex-wrap items-center; gap: var(--space-2); margin-top: var(--space-2); }
.tp-origin-chip { font-size: var(--text-xs-size); border-radius: 999px; padding: 3px 10px; border: 1px solid var(--color-border); background: var(--color-surface); color: var(--color-text); }
.tp-origin-chip.link { cursor: pointer; color: var(--color-brand-on-surface); border-color: var(--color-brand-base); font-family: inherit; }
.tp-origin-chip.link:hover { background: var(--color-brand-subtle, rgba(0,119,219,.08)); }
.tp-origin-chip.team { background: var(--color-surface-sunken); color: var(--color-text-muted); }


.tp-stack { @apply inline-flex items-center; }
.tp-avatar {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 1.6rem; height: 1.6rem; border-radius: 999px; margin-right: -0.4rem;
  border: 2px solid var(--color-surface-raised);
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: 0.55rem; font-weight: 600;
}
.tp-avatar.sm { width: 1.5rem; height: 1.5rem; margin-right: 0; font-size: 0.5rem; background: var(--color-brand-base); color: var(--color-on-brand); }
.tp-avatar.more { background: var(--color-brand-subtle); color: var(--color-brand-on-surface); }
.tp-avatar.add {
  @apply cursor-pointer; background: transparent; border: 1px dashed var(--color-border-strong);
  color: var(--color-text-light); font-family: inherit; font-size: 0.8rem; margin-right: 0;
}
.tp-avatar.add:hover { border-color: var(--color-brand-base); color: var(--color-brand-base); }
.tp-unowned { font-size: var(--text-xs-size); color: var(--color-warning); }
.tp-assign-box { @apply flex flex-col items-start; gap: var(--space-2); margin-top: var(--space-2); }
/*
 * The rail is ~210px of usable width, and a name, a staff number and a work
 * email do not fit in it — left alone the list wraps every result over three
 * lines and grows its own horizontal scrollbar. Anchored right so it opens
 * leftwards across the task content, which is inside the same scroll container
 * and so is not clipped.
 */
.tp-assign-box :deep(.results) {
  right: 0; left: auto; width: 20rem; max-width: 60vw;
}
.tp-assign-box :deep(.results li) { @apply flex-row flex-wrap items-baseline; gap: var(--space-2); }
.tp-assign-box :deep(.chosen) { display: none; }

.tp-people { @apply flex flex-col; gap: 2px; list-style: none; margin: 0; padding: 0; width: 100%; }
.tp-person-row {
  @apply flex items-center; gap: var(--space-2);
  padding: 3px var(--space-2); margin-left: calc(var(--space-2) * -1);
  border-radius: var(--radius-md);
}
.tp-person-row:hover { background: var(--color-neutral-200); }
.tp-person-name { @apply flex-1 truncate; font-size: var(--text-sm-size); color: var(--color-text); }
.tp-person-lead {
  font-family: var(--font-family-mono); font-size: 0.66rem; letter-spacing: .04em;
  color: var(--color-text-light);
}
.tp-person-x {
  @apply cursor-pointer border-none bg-transparent; padding: 0 2px; line-height: 1;
  font-family: inherit; font-size: var(--text-md-size); color: var(--color-text-light);
  opacity: 0; transition: opacity 120ms ease, color 120ms ease;
}
.tp-person-row:hover .tp-person-x, .tp-person-x:focus-visible { opacity: 1; }
.tp-person-x:hover { color: var(--color-error); }
.tp-person-x:disabled { @apply cursor-not-allowed; }

.tp-add-person {
  @apply cursor-pointer border-none bg-transparent; padding: 0; margin-top: var(--space-1);
  font-family: inherit; font-size: var(--text-xs-size); font-weight: 500; color: var(--color-brand-base);
}
.tp-add-person:hover { color: var(--color-brand-hover); }

/* Running text wants a measure; 62ch is the handoff's, and close to the 65
   the design system asks for. */
.tp-content .tp-text.rich { max-width: 62ch; font-size: var(--text-md-size); line-height: var(--line-height-relaxed); }
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
/* The negative left margin lets the hover wash bleed past the text without
   shifting the baseline — the handoff's trick, and it reads well. */
.tp-subs { @apply flex flex-col; gap: 1px; list-style: none; margin: var(--space-2) 0 0; padding: 0; }
.tp-sub {
  @apply flex items-center; gap: var(--space-3);
  padding: var(--space-2) var(--space-3); margin-left: calc(var(--space-3) * -1);
  border-radius: var(--radius-lg); transition: background-color 120ms ease;
}
.tp-sub:hover { background: var(--color-neutral-100); }
.tp-sub-title {
  @apply flex-1 cursor-pointer border-none bg-transparent; padding: 0; text-align: left;
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text);
}
.tp-sub-title:hover { color: var(--color-brand-base); }
.tp-sub.done .tp-sub-title { color: var(--color-text-light); text-decoration: line-through; }
.tp-sub-due { font-size: var(--text-xs-size); font-weight: 500; color: var(--color-text-light); white-space: nowrap; }
.tp-tick {
  @apply inline-flex cursor-pointer items-center justify-center; width: 16px; height: 16px;
  border: 1.5px solid var(--color-border-strong); border-radius: var(--radius-sm);
  background: var(--color-surface-raised); color: var(--color-on-brand);
  font-size: 0.55rem; font-weight: 600; line-height: 1;
  transition: background-color 120ms ease, border-color 120ms ease;
}
.tp-tick:hover:not(:disabled) { border-color: var(--color-teal); }
.tp-tick.on { background: var(--color-teal); border-color: var(--color-teal); }
.tp-tick:disabled { @apply cursor-not-allowed opacity-50; }
.tp-tick:focus-visible { outline: 2px solid var(--color-brand-base); outline-offset: 2px; }

/* Cards rather than a list — a file is a thing you recognise by its face. */
.tp-files { @apply flex flex-wrap; gap: var(--space-3); margin: var(--space-3) 0 0; padding: 0; }
.tp-file {
  @apply flex flex-col; width: 132px; overflow: hidden; text-decoration: none;
  border: 1px solid var(--color-border-strong); border-radius: var(--radius-xl);
  transition: border-color 120ms ease;
}
.tp-file:hover { border-color: var(--color-brand-base); }
.tp-file-thumb {
  @apply flex items-center justify-center; height: 66px;
  background: var(--color-neutral-100); color: var(--color-text-light);
  font-family: var(--font-family-mono); font-size: var(--text-xs-size); font-weight: 700; letter-spacing: .04em;
}
.tp-file-name { padding: var(--space-2) var(--space-3) 0; font-size: var(--text-xs-size); font-weight: 500; color: var(--color-text-muted); word-break: break-word; }
.tp-file-size { padding: 0 var(--space-3) var(--space-2); font-size: var(--text-xs-size); color: var(--color-text-light); }
.tp-file-drop {
  @apply flex cursor-pointer items-center justify-center; width: 132px; height: 106px;
  border: 1px dashed var(--color-border-strong); border-radius: var(--radius-xl);
  font-size: var(--text-xs-size); font-weight: 500; color: var(--color-text-light);
  transition: border-color 120ms ease, color 120ms ease;
}
.tp-file-drop:hover { border-color: var(--color-brand-base); color: var(--color-brand-base); }

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

/* ── Meetings ─────────────────────────────────────────────────────────── */
.tp-meet-dest { font-size: var(--text-sm-size); color: var(--color-text-muted); margin-bottom: var(--space-3); }
.tp-meet-dest .link-btn { font-size: inherit; }
.tp-meet-error { font-size: var(--text-sm-size); color: var(--color-danger-text); margin-bottom: var(--space-2); }
.tp-meet { @apply flex flex-col; gap: var(--space-2); padding: var(--space-4); margin-bottom: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.tp-meet.cancelled { opacity: .6; }
.tp-meet-head { @apply flex items-center justify-between; gap: var(--space-2); }
.tp-meet-subject { font-size: var(--text-sm-size); font-weight: 600; margin: 0; }
.tp-meet-meta { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tp-meet-attendees { @apply flex flex-wrap; gap: var(--space-1); }
.tp-meet-chip { font-size: var(--text-xs-size); padding: 2px 8px; border-radius: 999px; background: var(--color-surface-sunken); color: var(--color-text-muted); }
.tp-meet-actions { @apply flex flex-wrap items-center; gap: var(--space-3); margin-top: var(--space-1); }
.tp-meet-flag { font-size: var(--text-xs-size); color: var(--color-brand-on-surface); }
.tp-meet-cancel { color: var(--color-danger-text); }
.tp-meet-grid { @apply grid; grid-template-columns: 2fr 1fr; gap: var(--space-3); }
.tp-meet-group { font-style: normal; font-size: var(--text-xs-size); color: var(--color-text-muted); margin-left: 4px; }
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
/* 24rem is 336px at the app's 14px root — enough for "are you sure", not for a
   form with paired rows and a three-column grid inside it. */
.tp-modal-lg { width: min(40rem, 100%); }
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

/* Narrow: the section list becomes a scrolling row of pills above the content,
   then the rail moves above it as a two-column field grid. Three columns need
   width that a phone does not have, and stacking them in place would put the
   properties below every section. */
@media (max-width: 68rem) {
  .tp-grid { grid-template-columns: minmax(0, 1fr) 250px; }
  .tp-nav { grid-column: 1 / -1; border-right: none; border-bottom: 1px solid var(--color-border); }
  .tp-nav-inner {
    @apply flex-row; position: static; gap: var(--space-2);
    padding: var(--space-3) var(--space-5); overflow-x: auto; scrollbar-width: thin;
  }
  .tp-nav-head, .tp-nav-rule { display: none; }
  .tp-nav-item { @apply shrink-0; border: 1px solid var(--color-border); border-radius: var(--radius-full); }
  .tp-nav-item.active { border-color: var(--color-brand-light); }
}
@media (max-width: 54rem) {
  .tp-grid { grid-template-columns: minmax(0, 1fr); }
  /* The task before its properties. The handoff suggests the reverse, but you
     open a task to read it, and seven fields above the title push the thing
     you came for off the screen. */
  .tp-content { grid-row: 2; padding: var(--space-5); }
  .tp-rail {
    grid-row: 3; border-left: none;
    border-top: 1px solid var(--color-border); border-bottom: none;
  }
  .tp-rail-inner { position: static; }
  /* Plain `display` rather than `@apply grid`: the base rule's `flex` wins
     against an applied utility here, and the fields stay in one column. */
  .tp-props { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 var(--space-5); }
}

@media (prefers-reduced-motion: reduce) {
  .tp-nav-item, .tp-seg-btn, .tp-do, .tp-file, .tp-file-drop { transition: none; }
}
/* Preflight is off, so focus rings are ours to put back. */
.tp-nav-item:focus-visible, .tp-seg-btn:focus-visible, .tp-do:focus-visible,
.tp-add:focus-visible, .tp-more:focus-visible, .tp-crumb-up:focus-visible,
.tp-file:focus-visible, .tp-file-drop:focus-visible, .tp-avatar.add:focus-visible {
  outline: 2px solid var(--color-brand-base); outline-offset: 2px;
}
</style>
