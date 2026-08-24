<script setup lang="ts">
import { ref, onMounted, reactive, computed } from "vue";
import { useRouter } from "vue-router";
import { api } from "../lib/api";
import { currentOrgSlug, authStore, getToken } from "../stores/auth";
import type { Employee, EmergencyContact, EmployeeVehicle } from "../lib/types";

const props  = defineProps<{ id: string }>();
const router = useRouter();

const employee = ref<Employee | null>(null);
const loading  = ref(false);
const error    = ref("");

/*
 * The nine tabs the HR portal designs specify, in their order.
 *
 * The five that existed were a different carve-up of roughly the same
 * material, not a subset: Career and Benefits have no counterpart in the
 * design, and Job Details, Emergency Contacts, Dependents, Attendance,
 * Leave, Payroll and Performance had none here. Rather than run fourteen
 * tabs, the old contents move into their nearest new home — career history
 * into Job Details, benefits into Payroll, the personal detail into Profile
 * Overview. Nothing is deleted; it is relocated.
 */
type Tab =
  | "profile-overview" | "job-details" | "emergency-contacts" | "dependents"
  | "attendance" | "leave" | "payroll" | "performance"
  | "assets" | "support-tickets" | "training" | "projects" | "tasks"
  | "documents" | "vehicles";

const tab = ref<Tab>("profile-overview");

const TABS: { key: Tab; label: string }[] = [
  { key: "profile-overview",   label: "Profile Overview" },
  { key: "job-details",        label: "Job Details" },
  { key: "emergency-contacts", label: "Emergency Contacts" },
  { key: "dependents",         label: "Dependents" },
  { key: "attendance",         label: "Attendance Management" },
  { key: "leave",              label: "Leave Management" },
  { key: "payroll",            label: "Payroll" },
  { key: "performance",        label: "Performance" },
  /*
   * Each a window onto a module that owns its own data — the same rule
   * Attendance, Leave, Payroll and Performance already follow. Nothing here
   * is a second implementation of tasks, projects, the service desk or the
   * asset register; it is that module's rows, read for one person.
   */
  { key: "assets",             label: "Assets Assigned" },
  { key: "support-tickets",    label: "Support Tickets" },
  { key: "training",           label: "Training" },
  { key: "projects",           label: "Projects" },
  { key: "tasks",              label: "Tasks" },
  { key: "documents",          label: "Documents" },
  /*
   * Not in the design, but a real screen that needs to stay reachable. It was
   * previously its own routed page — a click away from the profile entirely,
   * with none of this person's photo, status or other tabs in view. Folded in
   * here so editing a vehicle happens in the same window as everything else.
   */
  { key: "vehicles",           label: "Vehicles" },
];

// ── Documents ──────────────────────────────────────────────────────────────
/*
 * Contracts, ID copies, certificates. The onboarding wizard could upload these
 * and nothing could then look at them: there was no screen anywhere that listed
 * an employee's documents, so a signed contract went in and disappeared.
 *
 * They are encrypted at rest, which is why downloading is a fetch-and-blob
 * rather than a link — the file is decrypted by the server on the way out and
 * the request has to carry the bearer token.
 */
interface EmployeeDoc {
  id: number; category: string; original_name: string;
  mime_type: string; size_bytes: number; created_at: string;
}

const DOC_CATEGORIES = [
  { value: "national_id",  label: "National ID / Passport" },
  { value: "contract",     label: "Employment Contract" },
  { value: "certificate",  label: "Academic Certificate" },
  { value: "kra_pin",      label: "KRA PIN Certificate" },
  { value: "nhif",         label: "NHIF Card" },
  { value: "nssf",         label: "NSSF Card" },
  { value: "photo",        label: "Passport Photo" },
  { value: "bank_details", label: "Bank Account Details" },
  { value: "other",        label: "Other" },
];

const docs          = ref<EmployeeDoc[]>([]);
const docsLoaded    = ref(false);
const docsLoading   = ref(false);
const docError      = ref("");
const docUploading  = ref(false);
const docCategory   = ref("other");
const docFileInput  = ref<HTMLInputElement | null>(null);

const docLabel = (v: string) =>
  DOC_CATEGORIES.find((c) => c.value === v)?.label ?? v;

function humanSize(bytes: number): string {
  if (!bytes) return "—";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

async function loadDocs(): Promise<void> {
  if (docsLoading.value) return;
  docsLoading.value = true; docError.value = "";
  try {
    const res = await api<{ data: EmployeeDoc[] }>("GET",
      `/api/v1/employees/${props.id}/documents`, { org: org() });
    docs.value = res.data ?? [];
    docsLoaded.value = true;
  } catch (e) {
    docError.value = e instanceof Error ? e.message : "Could not load documents.";
  } finally { docsLoading.value = false; }
}

// ── The tabs added in the design adoption ────────────────────────────────
interface Dependent {
  id: number; name: string; relationship: string | null;
  date_of_birth: string | null; gender?: string | null;
}
interface Balance {
  leave_type: string; entitled_days: number; used_days: number;
  pending_days: number; available_days: number;
}

interface LeaveRow {
  id: number; reference: string;
  leave_type: { name: string } | null;
  from_date: string; to_date: string; days_requested: number;
  is_half_day: boolean; scattered_dates: string[] | null; status: string;
}

const leaveRows = ref<LeaveRow[]>([]);

// ── The three tabs that read modules owning their own data ───────────────
interface AttendanceDay {
  id: number; date: string; status: string; location: string | null;
  clock_in: string | null; clock_out: string | null; hours: number | null; overtime_hours: number | null;
}
interface AttendanceTotals { days: number; present: number; absent: number; late: number; on_leave: number; hours: number }
interface CycleAttendance {
  cycle: string; from: string; to: string;
  present: number; absent: number; expected: number;
  rate: number | null; late: number; on_leave: number; unrecorded: number; note: string | null;
}
interface ReviewRow {
  id: number; cycle: string | null; review_date: string | null;
  rating: number | null; comments: string | null; outcomes: string | null;
  attendance: CycleAttendance | null;
}
interface Payslip {
  run_id: number; run_status: string;
  gross: number | null; net: number | null; paye: number | null; nssf: number | null; nhif: number | null;
}

// ── Vehicles ───────────────────────────────────────────────────────────────
/*
 * Folded in from what used to be a separate routed page. Editing happens
 * inline, in an expanding form beneath the list — the same shape as the
 * Emergency Contacts and Dependents tabs, and the Move dialog above — rather
 * than a popup, so nothing ever leaves this window to edit a vehicle.
 */
const vehicles          = ref<EmployeeVehicle[]>([]);
const showVehicleForm   = ref(false);
const editingVehicleId  = ref<number | null>(null);
const vehicleSaving     = ref(false);
const vehicleError      = ref("");

const VEHICLE_YEAR_NOW = new Date().getFullYear();
const VEHICLE_YEAR_MIN = 1900;

const emptyVehicleForm = () => ({
  registration_number: "", make: "", model: "", color: "",
  year: "" as string | number, parking_slot: "", notes: "",
});
const vehicleForm = reactive(emptyVehicleForm());

function openAddVehicle(): void {
  editingVehicleId.value = null;
  Object.assign(vehicleForm, emptyVehicleForm());
  vehicleError.value = "";
  showVehicleForm.value = true;
}

function openEditVehicle(v: EmployeeVehicle): void {
  editingVehicleId.value = v.id;
  Object.assign(vehicleForm, {
    registration_number: v.registration_number,
    make: v.make ?? "", model: v.model ?? "", color: v.color ?? "",
    year: v.year ?? "", parking_slot: v.parking_slot ?? "", notes: v.notes ?? "",
  });
  vehicleError.value = "";
  showVehicleForm.value = true;
}

async function saveVehicle(): Promise<void> {
  if (!vehicleForm.registration_number.trim()) {
    vehicleError.value = "Registration number is required.";
    return;
  }
  const yr = Number(vehicleForm.year);
  if (vehicleForm.year && (isNaN(yr) || yr < VEHICLE_YEAR_MIN || yr > VEHICLE_YEAR_NOW + 1)) {
    vehicleError.value = `Year must be between ${VEHICLE_YEAR_MIN} and ${VEHICLE_YEAR_NOW + 1}.`;
    return;
  }

  vehicleSaving.value = true; vehicleError.value = "";
  try {
    const body: Record<string, unknown> = { ...vehicleForm };
    body.year = vehicleForm.year ? Number(vehicleForm.year) : null;
    if (!body.year) delete body.year;

    if (editingVehicleId.value) {
      await api("PUT", `/api/v1/employees/${props.id}/vehicles/${editingVehicleId.value}`, { org: org(), body });
    } else {
      await api("POST", `/api/v1/employees/${props.id}/vehicles`, { org: org(), body });
    }
    showVehicleForm.value = false;
    loaded.value.vehicles = false;
    await openTab("vehicles");
  } catch (e) {
    vehicleError.value = e instanceof Error ? e.message : "Could not save that vehicle.";
  } finally { vehicleSaving.value = false; }
}

async function removeVehicle(id: number, reg: string): Promise<void> {
  if (!confirm(`Remove vehicle ${reg}?`)) return;
  await api("DELETE", `/api/v1/employees/${props.id}/vehicles/${id}`, { org: org() });
  vehicles.value = vehicles.value.filter(v => v.id !== id);
}

// ── Windows onto tasks, projects, the service desk, assets and training ──
interface TaskRow {
  id: number; title: string; status: string; priority: string | null;
  deadline: string | null; project: { id: number; name: string } | null;
}
interface ProjectRow {
  id: number; name: string; account: string | null; status: string; priority: string | null;
  deadline: string | null; role: "lead" | "team";
}
interface TicketRow {
  id: number; reference: string; subject: string; account: string | null;
  status: string | null; priority: string | null; role: "assigned" | "raised"; created_at: string | null;
}
interface AssetRow {
  id: number; name: string; tag: string | null; serial: string | null; model: string | null;
  status: string; assigned_date: string | null; role: "assigned" | "custodian";
}
interface TrainingRow {
  id: number; course_name: string; provider: string | null;
  completion_date: string | null; certification_no: string | null;
}

const tasksList    = ref<TaskRow[]>([]);
const projectsList = ref<ProjectRow[]>([]);
const ticketsList  = ref<TicketRow[]>([]);
const ticketsNote  = ref("");
const assetsList   = ref<AssetRow[]>([]);
const trainingList = ref<TrainingRow[]>([]);

const attendance      = ref<{ from: string; to: string; days: AttendanceDay[]; totals: AttendanceTotals } | null>(null);
const attendanceMonth = ref("");
const reviews         = ref<ReviewRow[]>([]);
const currentCycle    = ref<CycleAttendance | null>(null);
const payslips        = ref<Payslip[]>([]);

const contacts   = ref<EmergencyContact[]>([]);
const dependents = ref<Dependent[]>([]);
const balances   = ref<Balance[]>([]);
const sideError  = ref("");

/**
 * Loaded when a tab is first opened, not on every page view.
 *
 * Nine tabs fetched eagerly would be nine requests for a page where most
 * visitors read the first one and leave.
 */
const loaded = ref<Partial<Record<Tab, boolean>>>({});

async function openTab(next: Tab): Promise<void> {
  tab.value = next;

  if (next === "documents") { if (!docsLoaded.value) void loadDocs(); return; }
  if (loaded.value[next]) return;

  loaded.value[next] = true;
  sideError.value = "";

  try {
    if (next === "emergency-contacts") {
      const res = await api<{ data: EmergencyContact[] }>(
        "GET", `/api/v1/employees/${props.id}/emergency-contacts`, { org: org() });
      contacts.value = res.data ?? [];
    } else if (next === "dependents") {
      const res = await api<{ data: Dependent[] }>(
        "GET", `/api/v1/employees/${props.id}/dependents`, { org: org() });
      dependents.value = res.data ?? [];
    } else if (next === "attendance") {
      await loadAttendance();
    } else if (next === "performance") {
      const res = await api<{ data: { reviews: ReviewRow[]; current: CycleAttendance | null } }>(
        "GET", `/api/v1/employees/${props.id}/performance`, { org: org() });
      reviews.value = res.data.reviews ?? [];
      currentCycle.value = res.data.current;
    } else if (next === "payroll") {
      /*
       * Pay history is refused for anybody without the sensitive permission,
       * and that is not an error worth showing them — the rest of the tab is
       * theirs.
       */
      try {
        const res = await api<{ data: Payslip[] }>(
          "GET", `/api/v1/employees/${props.id}/payslips`, { org: org() });
        payslips.value = res.data ?? [];
      } catch { payslips.value = []; }
    } else if (next === "tasks") {
      const res = await api<{ data: TaskRow[] }>("GET", `/api/v1/employees/${props.id}/tasks`, { org: org() });
      tasksList.value = res.data ?? [];
    } else if (next === "projects") {
      const res = await api<{ data: ProjectRow[] }>("GET", `/api/v1/employees/${props.id}/projects`, { org: org() });
      projectsList.value = res.data ?? [];
    } else if (next === "support-tickets") {
      const res = await api<{ data: TicketRow[]; note?: string }>(
        "GET", `/api/v1/employees/${props.id}/support-tickets`, { org: org() });
      ticketsList.value = res.data ?? [];
      ticketsNote.value = res.note ?? "";
    } else if (next === "assets") {
      const res = await api<{ data: AssetRow[] }>("GET", `/api/v1/employees/${props.id}/assets`, { org: org() });
      assetsList.value = res.data ?? [];
    } else if (next === "training") {
      /*
       * Reuses the existing performance/training listing rather than a new
       * read — it already answers "this person's training", filtered by
       * employee_id, and is gated on an org-wide permission a viewer of this
       * page may not hold. A refusal here is not an error worth surfacing;
       * the rest of the tab is still theirs.
       */
      try {
        const res = await api<{ data: TrainingRow[] }>(
          "GET", "/api/v1/performance/training", { org: org(), params: { employee_id: props.id } });
        trainingList.value = res.data ?? [];
      } catch { trainingList.value = []; }
    } else if (next === "vehicles") {
      const res = await api<{ data: EmployeeVehicle[] } | EmployeeVehicle[]>(
        "GET", `/api/v1/employees/${props.id}/vehicles`, { org: org() });
      vehicles.value = Array.isArray(res) ? res : (res.data ?? []);
    } else if (next === "leave") {
      /*
       * This person's requests, not the viewer's. The listing is scoped
       * server-side, so somebody without the reach to see them gets an empty
       * list rather than a leak — and the tab says so.
       */
      const res = await api<{ data: LeaveRow[] }>(
        "GET", "/api/v1/leave",
        { org: org(), params: { employee_id: props.id, per_page: 50, scope: "all" } });
      leaveRows.value = res.data ?? [];
    }
  } catch (e) {
    // The tab still renders; it says it could not read rather than showing an
    // empty list, which would read as "none recorded".
    loaded.value[next] = false;
    sideError.value = e instanceof Error ? e.message : "Could not load that.";
  }
}

/**
 * The viewer's own balance, for the Current Leave Balance card.
 *
 * The endpoint answers for whoever is signed in, so it is only shown on your
 * own record — printing somebody else's card headed with their name while it
 * holds your figures would be worse than not showing it.
 */
const isSelf = ref(false);

async function loadBalances(): Promise<void> {
  try {
    const me = await api<{ data: { id: number } }>("GET", "/api/v1/employees/me", { org: org() });
    isSelf.value = String(me.data?.id) === String(props.id);
    if (!isSelf.value) return;

    const res = await api<{ data: Balance[] }>("GET", "/api/v1/leave/balance", { org: org() });
    balances.value = res.data ?? [];
  } catch {
    isSelf.value = false;
  }
}

async function uploadDoc(e: Event): Promise<void> {
  const input = e.target as HTMLInputElement;
  const file  = input.files?.[0];
  if (!file) return;

  docError.value = "";

  if (file.size > 10 * 1024 * 1024) {
    docError.value = "File must be under 10 MB.";
    input.value = "";
    return;
  }

  docUploading.value = true;
  try {
    const form = new FormData();
    form.append("file", file);
    form.append("category", docCategory.value);

    await api("POST", `/api/v1/employees/${props.id}/documents`, { org: org(), formData: form });
    await loadDocs();
  } catch (err) {
    docError.value = err instanceof Error ? err.message : "Upload failed.";
  } finally {
    docUploading.value = false;
    input.value = "";
  }
}

/**
 * Fetched with the token and handed to the browser as a blob.
 *
 * An <a href> cannot carry the Authorization header, and these are the files
 * that most need one.
 */
async function downloadDoc(doc: EmployeeDoc): Promise<void> {
  docError.value = "";
  try {
    const base = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");
    const headers: Record<string, string> = {};
    const token = getToken();
    if (token) headers["Authorization"] = `Bearer ${token}`;
    const slug = org();
    if (slug) headers["X-Organization"] = slug;

    const res = await fetch(
      `${base}/api/v1/employees/${props.id}/documents/${doc.id}/download`, { headers },
    );
    if (!res.ok) throw new Error(`Download failed (${res.status}).`);

    const url = URL.createObjectURL(await res.blob());
    const a = document.createElement("a");
    a.href = url;
    a.download = doc.original_name;
    a.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    docError.value = e instanceof Error ? e.message : "Download failed.";
  }
}

async function deleteDoc(doc: EmployeeDoc): Promise<void> {
  if (!confirm(`Delete "${doc.original_name}"? This cannot be undone.`)) return;
  try {
    await api("DELETE", `/api/v1/employees/${props.id}/documents/${doc.id}`, { org: org() });
    docs.value = docs.value.filter((d) => d.id !== doc.id);
  } catch (e) {
    docError.value = e instanceof Error ? e.message : "Could not delete.";
  }
}

// Emergency contact form
const showECForm  = ref(false);
const ecSaving    = ref(false);
const ecError     = ref("");
const editingEC   = ref<EmergencyContact | null>(null);
const ecForm = reactive({ name: "", relationship: "", phone: "", email: "", is_primary: false });

// Exit form
const showExit   = ref(false);
const exitDate   = ref("");
const exitReason = ref("");
const exiting    = ref(false);
const exitError  = ref("");

// Compensation edit
const showCompEdit = ref(false);
const compSaving   = ref(false);
const compError    = ref("");
const compSuccess  = ref("");
const comp = reactive({
  basic_salary: 0, house_allowance: 0, transport_allowance: 0,
  medical_allowance: 0, other_allowances: 0, insurance_relief: 0,
});

// Photo upload
const photoInput    = ref<HTMLInputElement | null>(null);
const photoUploading = ref(false);

// Profile text editing (summary / about / hobbies)
const editingProfile = ref(false);
const profileSaving  = ref(false);
const profileDraft   = reactive({ professional_summary: "", about_me: "", hobbies_interests: "" });

// Job description editing
const editingJD = ref(false);
const jdSaving  = ref(false);
const jdDraft   = ref("");

// Qualifications
const showQualForm = ref(false);
const qualSaving   = ref(false);
const qualError    = ref("");
const qualForm = reactive({
  title: "", institution: "", qualification_type: "certificate",
  field_of_study: "", year_obtained: "" as string | number,
  certificate_number: "", notes: "",
});

// ── Moving somebody to another company in the group ───────────────────────────
/*
 * The preflight comes first, always.
 *
 * Around twenty-five places in the application validate an assignee against
 * their primary organisation, so the moment somebody's changes, every open task
 * and contract in their old company that names them stops being saveable. The
 * order has to be reassign, then move — and nobody will follow that order
 * unless the screen shows them what to reassign.
 */
interface PreflightItem {
  key: string; label: string; count: number; why: string; blocking: boolean;
}
interface Preflight { blocking: PreflightItem[]; warnings: PreflightItem[]; clear: boolean }

const showMove   = ref(false);
const moveSaving = ref(false);
const moveError  = ref("");
const moveNote   = ref("");
const preflight  = ref<Preflight | null>(null);
const movements  = ref<any[]>([]);

const moveForm = reactive({
  kind: "transfer" as "transfer" | "secondment",
  organization_id: null as number | null,
  effective_on: new Date().toISOString().slice(0, 10),
  reason: "",
  force: false,
});

async function openMove(): Promise<void> {
  showMove.value = true;
  moveError.value = "";
  moveNote.value = "";
  preflight.value = null;

  try {
    const res = await api<{ data: Preflight }>(
      "GET", `/api/v1/employees/${props.id}/movements/preflight`, { org: org() });
    preflight.value = res.data;
  } catch (e) {
    moveError.value = e instanceof Error ? e.message : "Could not check what is holding them.";
  }
}

async function loadMovements(): Promise<void> {
  try {
    const res = await api<{ data: any[] }>(
      "GET", `/api/v1/employees/${props.id}/movements`, { org: org() });
    movements.value = res.data;
  } catch { /* history is decoration; its absence should not break the tab */ }
}

async function submitMove(): Promise<void> {
  if (!moveForm.organization_id) { moveError.value = "Pick an organisation."; return; }

  moveSaving.value = true;
  moveError.value = "";

  try {
    const res = await api<{ message: string }>(
      "POST", `/api/v1/employees/${props.id}/movements`,
      { org: org(), body: { ...moveForm } });

    moveNote.value = res.message;
    showMove.value = false;
    await Promise.all([load(), loadMovements()]);
  } catch (e) {
    const err = e as any;
    // 409 means the world is not ready, not that a field is wrong — so the
    // holdings come back with it and are shown rather than a bare message.
    if (err?.status === 409 && err?.body?.preflight) preflight.value = err.body.preflight;
    moveError.value = err?.message ?? "Could not move them.";
  } finally {
    moveSaving.value = false;
  }
}

// Secondments
const showSecondmentForm = ref(false);
const secondmentSaving   = ref(false);
const secondmentError    = ref("");
const secondmentForm = reactive({
  organization_id: null as number | null,
  role: "", engagement_type: "secondment",
  allocation_pct: 50, is_billable: false,
  effective_from: "", effective_to: "", notes: "",
});

function org(): string | null { return currentOrgSlug.value; }

const hasSensitive = computed(() => {
  const e = employee.value as any;
  if (!e) return false;
  return e.tax_id !== undefined || e.blood_group !== undefined || Array.isArray(e.emergency_contacts);
});

// ── Adding a contact or a dependent without leaving the tab ──────────────
const newContact   = reactive({ name: "", relationship: "", phone: "", email: "" });
const newDependent = reactive({ name: "", relationship: "", date_of_birth: "" });
const addingSide   = ref(false);

async function addContact(): Promise<void> {
  if (!newContact.name.trim() || !newContact.phone.trim()) return;
  addingSide.value = true; sideError.value = "";
  try {
    await api("POST", `/api/v1/employees/${props.id}/emergency-contacts`, {
      org: org(),
      body: {
        name: newContact.name.trim(),
        relationship: newContact.relationship.trim() || null,
        phone: newContact.phone.trim(),
        email: newContact.email.trim() || null,
      },
    });
    Object.assign(newContact, { name: "", relationship: "", phone: "", email: "" });
    loaded.value["emergency-contacts"] = false;
    await openTab("emergency-contacts");
  } catch (e) {
    sideError.value = e instanceof Error ? e.message : "Could not add that contact.";
  } finally { addingSide.value = false; }
}

async function removeContact(id: number): Promise<void> {
  if (!confirm("Remove this emergency contact?")) return;
  try {
    await api("DELETE", `/api/v1/employees/${props.id}/emergency-contacts/${id}`, { org: org() });
    contacts.value = contacts.value.filter(c => c.id !== id);
  } catch (e) { sideError.value = e instanceof Error ? e.message : "Could not remove that."; }
}

async function addDependent(): Promise<void> {
  if (!newDependent.name.trim()) return;
  addingSide.value = true; sideError.value = "";
  try {
    await api("POST", `/api/v1/employees/${props.id}/dependents`, {
      org: org(),
      body: {
        name: newDependent.name.trim(),
        relationship: newDependent.relationship.trim() || null,
        date_of_birth: newDependent.date_of_birth || null,
      },
    });
    Object.assign(newDependent, { name: "", relationship: "", date_of_birth: "" });
    loaded.value.dependents = false;
    await openTab("dependents");
  } catch (e) {
    sideError.value = e instanceof Error ? e.message : "Could not add that dependent.";
  } finally { addingSide.value = false; }
}

async function removeDependent(id: number): Promise<void> {
  if (!confirm("Remove this dependent?")) return;
  try {
    await api("DELETE", `/api/v1/employees/${props.id}/dependents/${id}`, { org: org() });
    dependents.value = dependents.value.filter(d => d.id !== id);
  } catch (e) { sideError.value = e instanceof Error ? e.message : "Could not remove that."; }
}

async function loadAttendance(): Promise<void> {
  const params: Record<string, string> = {};

  if (attendanceMonth.value) {
    // A month is a from/to pair; the endpoint takes days so it can also serve
    // a cycle window without a second shape.
    const [y, m] = attendanceMonth.value.split("-").map(Number);
    params.from = `${attendanceMonth.value}-01`;
    params.to = new Date(y, m, 0).toISOString().slice(0, 10);
  }

  const res = await api<{ data: { from: string; to: string; days: AttendanceDay[]; totals: AttendanceTotals } }>(
    "GET", `/api/v1/employees/${props.id}/attendance`, { org: org(), params });
  attendance.value = res.data;
  attendanceMonth.value = res.data.from.slice(0, 7);
}

function stepAttendanceMonth(by: number): void {
  const [y, m] = (attendanceMonth.value || new Date().toISOString().slice(0, 7)).split("-").map(Number);
  const d = new Date(y, m - 1 + by, 1);
  attendanceMonth.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
  void loadAttendance();
}

function dayTone(status: string): string {
  return {
    on_time: "badge-green", early: "badge-green", late: "badge-yellow",
    absent: "badge-red", no_clock_in: "badge-gray", no_clock_out: "badge-yellow",
    on_leave: "badge-gray", holiday: "badge-gray", weekend: "badge-gray",
  }[status] ?? "badge-gray";
}

function plainStatus(status: string): string {
  return status.replace(/_/g, " ");
}

/**
 * The dates, however the leave was asked for.
 *
 * A scattered request is not a range; rendering it as one turns eight separate
 * days into a five-week absence. Same rule as the leave screens.
 */
function leaveSpan(r: LeaveRow): string {
  if (r.scattered_dates?.length) {
    return `${r.scattered_dates.length} separate day${r.scattered_dates.length === 1 ? "" : "s"}`;
  }
  if (r.is_half_day) return `${fmtDate(r.from_date)} (half day)`;
  return `${fmtDate(r.from_date)} – ${fmtDate(r.to_date)}`;
}

function leaveBadge(status: string): string {
  return { pending: "badge-yellow", approved: "badge-green", rejected: "badge-red" }[status] ?? "badge-gray";
}

function fmt(n: number | string | null | undefined): string {
  return new Intl.NumberFormat("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n ?? 0));
}

function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  try { return new Date(d).toLocaleDateString("en-KE", { year: "numeric", month: "long", day: "numeric" }); }
  catch { return d; }
}

function calcAge(dob: string | null | undefined): string {
  if (!dob) return "—";
  const diff = Date.now() - new Date(dob).getTime();
  const age  = Math.floor(diff / (365.25 * 24 * 60 * 60 * 1000));
  return `${age} yrs`;
}

function initials(e: any): string {
  return ((e.first_name?.[0] ?? "") + (e.last_name?.[0] ?? "")).toUpperCase() || "?";
}

// ── The profile as rows, so the view can be built from what is known ─────
interface Row { label: string; value: string | null; date?: boolean; href?: string }

/** Anything worth printing. A card with no populated row is not rendered. */
function anyOf(rows: Row[]): boolean {
  return rows.some(r => !!r.value);
}

/** A value, or null — never an em dash. The template decides what absence looks like. */
function val(v: unknown): string | null {
  if (v === null || v === undefined) return null;
  const text = String(v).trim();
  return text === "" ? null : text;
}

function dateVal(v: unknown): string | null {
  const raw = val(v);
  return raw ? fmtDate(raw) : null;
}

const personalRows = computed<Row[]>(() => {
  const e = employee.value as any;
  if (!e) return [];
  return [
    { label: "Work Email", value: val(e.work_email ?? e.email) },
    { label: "Personal Email", value: val(e.personal_email) },
    { label: "Date of Birth", value: dateVal(e.date_of_birth), date: true },
    { label: "Phone", value: val(e.phone) },
    { label: "Address", value: val(e.residential_address ?? e.address) },
    { label: "City", value: val(e.city) },
    { label: "Country", value: val(e.country) },
    {
      label: "LinkedIn",
      value: val(e.linkedin_profile) ? "View profile" : null,
      href: val(e.linkedin_profile) ?? undefined,
    },
    { label: "Enrolled on", value: dateVal(e.date_of_joining ?? e.date_hired), date: true },
  ];
});

const statutoryRows = computed<Row[]>(() => {
  const e = employee.value as any;
  if (!e) return [];
  return [
    { label: "National ID", value: val(e.national_id) },
    { label: "Passport No", value: val(e.passport_number) },
    { label: "KRA PIN", value: val(e.tax_id) },
    { label: "NSSF Number", value: val(e.nssf_number) },
    { label: "SHA / NHIF No", value: val(e.nhif_number ?? e.sha_number) },
  ];
});

const personalDetailRows = computed<Row[]>(() => {
  const e = employee.value as any;
  if (!e) return [];

  /*
   * Derived, and only where there is a birth date to derive it from. "Age —"
   * beside "Date of Birth —" is the same absence printed twice.
   */
  const age = e.date_of_birth ? calcAge(e.date_of_birth) : null;

  return [
    { label: "Gender", value: val(e.gender) },
    { label: "Marital Status", value: val(e.marital_status) },
    { label: "Date of Birth", value: dateVal(e.date_of_birth) },
    { label: "Age", value: age },
  ];
});

const healthRows = computed<Row[]>(() => {
  const e = employee.value as any;
  if (!e) return [];
  return [
    { label: "Blood Group", value: val(e.blood_group) },
    { label: "Allergies", value: val(e.allergies) },
    { label: "Known Conditions", value: val(e.known_conditions) },
    { label: "Disability", value: val(e.disabilities) },
  ];
});

/**
 * What this profile has nothing for, named once at the foot.
 *
 * Better than a card of dashes per section: it says what is missing in one
 * place and offers the way to fix it. Sections the viewer is not allowed to
 * see are left out entirely — telling somebody a card is empty when they
 * simply cannot read it would be a different and misleading claim.
 */
const missingSections = computed<string[]>(() => {
  const out: string[] = [];
  if (!anyOf(personalRows.value)) out.push("personal and contact details");
  if (hasSensitive.value) {
    if (!anyOf(statutoryRows.value)) out.push("statutory IDs");
    if (!anyOf(personalDetailRows.value)) out.push("personal details");
    if (!anyOf(healthRows.value)) out.push("health information");
  }
  return out;
});

const grossPay = computed(() =>
  comp.basic_salary + comp.house_allowance + comp.transport_allowance +
  comp.medical_allowance + comp.other_allowances
);

const empGross = computed(() => {
  const e = employee.value as any;
  if (!e) return 0;
  return Number(e.basic_salary ?? 0) + Number(e.house_allowance ?? 0) +
         Number(e.transport_allowance ?? 0) + Number(e.medical_allowance ?? 0) +
         Number(e.other_allowances ?? 0);
});

async function load(): Promise<void> {
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: Employee }>("GET", `/api/v1/employees/${props.id}`, { org: org() });
    employee.value = (res as any).data ?? res;
    const e = employee.value as any;
    comp.basic_salary        = Number(e.basic_salary ?? 0);
    comp.house_allowance     = Number(e.house_allowance ?? 0);
    comp.transport_allowance = Number(e.transport_allowance ?? 0);
    comp.medical_allowance   = Number(e.medical_allowance ?? 0);
    comp.other_allowances    = Number(e.other_allowances ?? 0);
    comp.insurance_relief    = Number(e.insurance_relief ?? 0);
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load employee.";
  } finally { loading.value = false; }
}

async function saveComp(): Promise<void> {
  compSaving.value = true; compError.value = ""; compSuccess.value = "";
  try {
    const res = await api<{ data: Employee }>("PUT", `/api/v1/employees/${props.id}`, {
      org: org(), body: { ...comp },
    });
    const updated = (res as any).data ?? res;
    employee.value = updated;
    const e = updated as any;
    comp.basic_salary        = Number(e.basic_salary ?? 0);
    comp.house_allowance     = Number(e.house_allowance ?? 0);
    comp.transport_allowance = Number(e.transport_allowance ?? 0);
    comp.medical_allowance   = Number(e.medical_allowance ?? 0);
    comp.other_allowances    = Number(e.other_allowances ?? 0);
    comp.insurance_relief    = Number(e.insurance_relief ?? 0);
    compSuccess.value = "Saved.";
    showCompEdit.value = false;
    setTimeout(() => { compSuccess.value = ""; }, 3000);
  } catch (err) { compError.value = err instanceof Error ? err.message : "Failed."; }
  finally { compSaving.value = false; }
}

async function exitEmployee(): Promise<void> {
  if (!exitDate.value) { exitError.value = "Exit date is required."; return; }
  exiting.value = true; exitError.value = "";
  try {
    await api("POST", `/api/v1/employees/${props.id}/exit`, {
      org: org(),
      body: { date_exited: exitDate.value, exit_reason: exitReason.value || null },
    });
    await load();
    showExit.value = false;
  } catch (err) {
    exitError.value = err instanceof Error ? err.message : "Failed to process exit.";
  } finally { exiting.value = false; }
}

/*
 * Emergency contacts: editing used to live on the overview and moved here with
 * the tab. Kept rather than dropped — the tab that replaced it could add and
 * remove but not correct, and a wrong phone number on the one record that only
 * matters in an emergency is worse than no record.
 */
function openEditEC(c: EmergencyContact) {
  editingEC.value = c;
  Object.assign(ecForm, { name: c.name, relationship: c.relationship, phone: c.phone, email: c.email ?? "", is_primary: c.is_primary });
  ecError.value = "";
  showECForm.value = true;
}

async function saveEC() {
  ecSaving.value = true; ecError.value = "";
  try {
    if (editingEC.value) {
      await api("PUT", `/api/v1/employees/${props.id}/emergency-contacts/${editingEC.value.id}`, { org: org(), body: { ...ecForm } });
    } else {
      await api("POST", `/api/v1/employees/${props.id}/emergency-contacts`, { org: org(), body: { ...ecForm } });
    }
    showECForm.value = false;
    await load();
    // Reload the tab as well, or the row on screen still shows what it said
    // before the correction.
    loaded.value["emergency-contacts"] = false;
    await openTab("emergency-contacts");
  } catch (e) { ecError.value = e instanceof Error ? e.message : "Failed to save."; }
  finally { ecSaving.value = false; }
}


// ── Photo upload ───────────────────────────────────────────────────────────
function triggerPhotoUpload() { photoInput.value?.click(); }

async function onPhotoSelected(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (!file) return;
  photoUploading.value = true;
  try {
    const fd = new FormData();
    fd.append("photo", file);
    const res = await api<{ data: { photo_url: string } }>("POST", `/api/v1/employees/${props.id}/photo`, { org: org(), formData: fd });
    if (employee.value) (employee.value as any).photo_url = (res as any).data?.photo_url ?? (res as any).photo_url;
  } catch (err) { error.value = err instanceof Error ? err.message : "Photo upload failed."; }
  finally { photoUploading.value = false; if (photoInput.value) photoInput.value.value = ""; }
}

// ── Profile text ───────────────────────────────────────────────────────────
function openEditProfile() {
  const e = employee.value as any;
  Object.assign(profileDraft, {
    professional_summary: e.professional_summary ?? "",
    about_me: e.about_me ?? "",
    hobbies_interests: e.hobbies_interests ?? "",
  });
  editingProfile.value = true;
}

async function saveProfile() {
  profileSaving.value = true;
  try {
    await api("PUT", `/api/v1/employees/${props.id}`, { org: org(), body: { ...profileDraft } });
    if (employee.value) {
      (employee.value as any).professional_summary = profileDraft.professional_summary;
      (employee.value as any).about_me             = profileDraft.about_me;
      (employee.value as any).hobbies_interests    = profileDraft.hobbies_interests;
    }
    editingProfile.value = false;
  } catch (err) { error.value = err instanceof Error ? err.message : "Save failed."; }
  finally { profileSaving.value = false; }
}

// ── Job description ────────────────────────────────────────────────────────
function openEditJD() {
  jdDraft.value = (employee.value as any).job_description ?? "";
  editingJD.value = true;
}

async function saveJD() {
  jdSaving.value = true;
  try {
    await api("PUT", `/api/v1/employees/${props.id}`, { org: org(), body: { job_description: jdDraft.value } });
    if (employee.value) (employee.value as any).job_description = jdDraft.value;
    editingJD.value = false;
  } catch (err) { error.value = err instanceof Error ? err.message : "Save failed."; }
  finally { jdSaving.value = false; }
}

// ── Qualifications ─────────────────────────────────────────────────────────
async function addQualification() {
  qualSaving.value = true; qualError.value = "";
  try {
    const res = await api<{ data: any }>("POST", `/api/v1/employees/${props.id}/qualifications`, {
      org: org(), body: { ...qualForm, year_obtained: qualForm.year_obtained || null },
    });
    const e = employee.value as any;
    e.qualifications = [((res as any).data ?? res), ...(e.qualifications ?? [])];
    showQualForm.value = false;
    Object.assign(qualForm, { title: "", institution: "", qualification_type: "certificate", field_of_study: "", year_obtained: "", certificate_number: "", notes: "" });
  } catch (err) { qualError.value = err instanceof Error ? err.message : "Failed to save."; }
  finally { qualSaving.value = false; }
}

async function deleteQualification(qualId: number) {
  if (!confirm("Remove this qualification?")) return;
  await api("DELETE", `/api/v1/employees/${props.id}/qualifications/${qualId}`, { org: org() });
  const e = employee.value as any;
  e.qualifications = e.qualifications?.filter((q: any) => q.id !== qualId) ?? [];
}

// ── Secondments ────────────────────────────────────────────────────────────

/*
 * This panel used to send a typed organisation name and a `start_date` to an
 * endpoint that requires an organisation id and an `effective_from`, so every
 * attempt was refused. It was written against the free-text table that has
 * since been replaced by the organisation map, and nothing here was updated
 * when it went.
 */

/** Everywhere except where they already sit — the server refuses their own. */
const secondableOrgs = computed(() =>
  authStore.organizations.filter(
    (o) => o.id !== (employee.value as any)?.primary_organization?.id,
  ));

/** Seconded time is counted against seconded time, never against their home. */
const secondedPct = computed(() =>
  ((employee.value as any)?.secondments ?? [])
    .filter((s: any) => s.is_active !== false)
    .reduce((n: number, s: any) => n + (Number(s.allocation_pct) || 0), 0));

const secondmentLeft = computed(() => 100 - secondedPct.value);

async function addSecondment() {
  secondmentSaving.value = true; secondmentError.value = "";
  try {
    const res = await api<{ data: any[] }>("POST", `/api/v1/employees/${props.id}/secondments`, {
      org: org(),
      body: {
        organization_id: secondmentForm.organization_id,
        role:            secondmentForm.role || undefined,
        engagement_type: secondmentForm.engagement_type || undefined,
        allocation_pct:  secondmentForm.allocation_pct,
        is_billable:     secondmentForm.is_billable,
        effective_from:  secondmentForm.effective_from,
        effective_to:    secondmentForm.effective_to || undefined,
        notes:           secondmentForm.notes || undefined,
      },
    });

    // The endpoint answers with the whole list, so take it rather than
    // guessing at what the new row looks like.
    (employee.value as any).secondments = res.data ?? [];

    showSecondmentForm.value = false;
    Object.assign(secondmentForm, {
      organization_id: null, role: "", engagement_type: "secondment",
      allocation_pct: 50, is_billable: false,
      effective_from: "", effective_to: "", notes: "",
    });
  } catch (err) { secondmentError.value = err instanceof Error ? err.message : "Failed to save."; }
  finally { secondmentSaving.value = false; }
}

/**
 * Ended, not deleted — somebody was billable to that organisation for three
 * months and payroll still needs to see it. Keyed on the organisation, which is
 * what identifies the row in the map.
 */
async function endSecondment(organizationId: number) {
  if (!confirm("End this secondment? The record is kept.")) return;

  const res = await api<{ data: any[] }>(
    "DELETE", `/api/v1/employees/${props.id}/secondments/${organizationId}`, { org: org() },
  );
  (employee.value as any).secondments = res.data ?? [];
}

function statusBadge(status: string): string {
  return { active: "badge-green", inactive: "badge-yellow", exited: "badge-gray" }[status] ?? "badge-gray";
}

function qualTypeBadgeColor(type: string): string {
  return { degree: "#3b82f6", diploma: "#8b5cf6", certificate: "#10b981", professional: "#f59e0b" }[type] ?? "#64748b";
}

onMounted(() => {
  void load();
  void loadBalances();
  // History only, and quietly — it is the one thing on this screen that is
  // usually empty, and a failure to fetch it should not block the record.
  void loadMovements();
});
</script>

<template>
  <div class="ed-root">

    <!-- Breadcrumb -->
    <nav class="ed-breadcrumb">
      <span class="ed-bc-link" @click="router.push({ name: 'employees' })">Employees</span>
      <span class="ed-bc-sep">/</span>
      <!-- The reference, as the design has it. The name is directly below in
           the header, so repeating it here says nothing new. -->
      <span class="ed-bc-current">{{ employee ? `#${(employee as any).employee_number ?? (employee as any).id}` : "…" }}</span>
    </nav>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error"   class="error">{{ error }}</p>

    <div v-if="employee">

      <!-- ── PROFILE CARD ──────────────────────────────────────────────────── -->
      <div class="ed-profile-card">

        <!-- Photo / avatar -->
        <div class="ed-photo-wrap">
          <img v-if="(employee as any).photo_url" :src="(employee as any).photo_url" class="ed-photo-img" alt="Profile photo" />
          <div v-else class="ed-avatar">{{ initials(employee) }}</div>
          <button v-if="hasSensitive" class="ed-photo-btn" :disabled="photoUploading" @click="triggerPhotoUpload" title="Change profile photo">
            <svg v-if="!photoUploading" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
              <circle cx="12" cy="13" r="4"/>
            </svg>
            <span v-else style="font-size:10px">…</span>
          </button>
          <input ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp" style="display:none" @change="onPhotoSelected" />
        </div>

        <div class="ed-profile-main">
          <div class="ed-profile-top">
            <div>
              <h2 class="ed-name">{{ (employee as any).full_name }}</h2>
              <p class="ed-sub">
                <!-- Designations are empty until the PLM import runs, so this
                     says so rather than printing a dash that reads as a field
                     nobody filled. -->
                <span v-if="(employee as any).designation?.title || (employee as any).designation?.name">
                  {{ (employee as any).designation?.title ?? (employee as any).designation?.name }}
                </span>
                <span v-else class="ed-unset">Role not set</span>
                &nbsp;•&nbsp;
                <span class="ed-sub-type">{{ (employee as any).employment_type?.replace(/_/g, " ") ?? "—" }}</span>
              </p>
              <span class="ed-emp-id-badge">{{ (employee as any).employee_number }}</span>
              <span v-if="(employee as any).manager?.full_name" class="ed-manager-chip">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0">
                  <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                  <circle cx="9" cy="7" r="4"/>
                  <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                {{ (employee as any).manager.full_name }}
              </span>
            </div>
            <div class="ed-profile-actions">
              <span :class="['badge', statusBadge((employee as any).status)]" style="font-size:0.82rem;padding:5px 14px;text-transform:capitalize">
                {{ (employee as any).status }}
              </span>
              <router-link :to="{ name: 'employee-edit', params: { id: (employee as any).id } }" class="btn btn-outline btn-sm">✏ Edit</router-link>
            </div>
          </div>

          <!-- Professional summary preview (one line) -->
          <p v-if="(employee as any).professional_summary" class="ed-summary-preview">
            {{ (employee as any).professional_summary }}
          </p>
        </div>
      </div>

      <!-- ── TAB NAV ───────────────────────────────────────────────────────── -->
      <!-- Nine tabs do not fit 1440px — the design's own frame is clipped at
           "Performa…" — so the bar scrolls rather than wrapping into two rows
           that push the content down. -->
      <div class="ed-tabs" role="tablist">
        <button
          v-for="t in TABS" :key="t.key"
          role="tab" :aria-selected="tab === t.key"
          :class="['ed-tab', { 'ed-tab-active': tab === t.key }]"
          @click="openTab(t.key)"
        >{{ t.label }}</button>
      </div>

      <!-- ── OVERVIEW ──────────────────────────────────────────────────────── -->
      <div v-if="tab === 'profile-overview'" class="ed-tab-body">

        <!--
          The four cards the design draws, and nothing twice.
          Every row and every card is conditional: a profile is a record of
          what is known, not a form somebody did not finish. A wall of em
          dashes reads as a broken screen; an absent row reads as a fact that
          was never captured, which is what it is.
        -->
        <div class="ed-two-col">
          <section v-if="anyOf(personalRows)" class="ed-card">
            <header class="ed-card-h">
              <span class="ed-card-icon" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                  <circle cx="10" cy="7" r="3.2" /><path d="M4 16.5c0-3 2.7-5 6-5s6 2 6 5" />
                </svg>
              </span>
              <span><strong>Personal Information</strong><em>Basic personal and contact details.</em></span>
            </header>
            <dl class="ed-rows">
              <template v-for="r in personalRows" :key="r.label">
                <div v-if="r.value" class="ed-row">
                  <dt>{{ r.label }}</dt>
                  <dd>
                    <svg v-if="r.date" class="ed-row-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                      <rect x="3" y="4.5" width="14" height="12" rx="2" /><path d="M3 8h14M7 3v3M13 3v3" />
                    </svg>
                    <a v-if="r.href" :href="r.href" target="_blank" rel="noopener" class="ed-link">{{ r.value }}</a>
                    <span v-else>{{ r.value }}</span>
                  </dd>
                </div>
              </template>
            </dl>
          </section>

          <!--
            Gated, but not shouted about. The old amber panel made a statutory
            number look like a warning; the design treats it as one card among
            the others, and the permission is what does the protecting.
          -->
          <section v-if="hasSensitive && anyOf(statutoryRows)" class="ed-card">
            <header class="ed-card-h">
              <span class="ed-card-icon" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                  <rect x="3" y="4.5" width="14" height="11" rx="2" /><path d="M7 9h.01M10.5 9H14M10.5 12H14M6 12.5h2" />
                </svg>
              </span>
              <span><strong>Statutory IDs</strong><em>ID, NSSF, SHA, KRA PIN.</em></span>
            </header>
            <dl class="ed-rows">
              <template v-for="r in statutoryRows" :key="r.label">
                <div v-if="r.value" class="ed-row"><dt>{{ r.label }}</dt><dd>{{ r.value }}</dd></div>
              </template>
            </dl>
          </section>
        </div>

        <section v-if="hasSensitive && anyOf(personalDetailRows)" class="ed-card">
          <h3 class="ed-card-plain">Personal Details</h3>
          <div class="ed-two-col ed-two-col-flat">
            <dl class="ed-rows">
              <template v-for="r in personalDetailRows.slice(0, Math.ceil(personalDetailRows.length / 2))" :key="r.label">
                <div v-if="r.value" class="ed-row"><dt>{{ r.label }}</dt><dd>{{ r.value }}</dd></div>
              </template>
            </dl>
            <dl class="ed-rows">
              <template v-for="r in personalDetailRows.slice(Math.ceil(personalDetailRows.length / 2))" :key="r.label">
                <div v-if="r.value" class="ed-row"><dt>{{ r.label }}</dt><dd>{{ r.value }}</dd></div>
              </template>
            </dl>
          </div>
        </section>

        <section v-if="hasSensitive && anyOf(healthRows)" class="ed-card">
          <h3 class="ed-card-plain">Health Information</h3>
          <div class="ed-two-col ed-two-col-flat">
            <dl class="ed-rows">
              <template v-for="r in healthRows.slice(0, 2)" :key="r.label">
                <div v-if="r.value" class="ed-row"><dt>{{ r.label }}</dt><dd>{{ r.value }}</dd></div>
              </template>
            </dl>
            <dl class="ed-rows">
              <template v-for="r in healthRows.slice(2)" :key="r.label">
                <div v-if="r.value" class="ed-row"><dt>{{ r.label }}</dt><dd>{{ r.value }}</dd></div>
              </template>
            </dl>
          </div>
        </section>

        <!--
          Only on your own record: the balance endpoint answers for whoever is
          signed in, so this card under somebody else's name would put your
          figures under their heading.
        -->
        <section v-if="isSelf && balances.length" class="ed-card">
          <h3 class="ed-card-plain">Current Leave Balance</h3>
          <div class="ed-bal-grid">
            <div v-for="b in balances" :key="b.leave_type" class="ed-bal">
              <span class="ed-bal-type">{{ b.leave_type }}</span>
              <strong>{{ b.available_days }}</strong>
              <em>of {{ b.entitled_days }} · {{ b.pending_days }} pending</em>
            </div>
          </div>
        </section>

        <!--
          Said once, at the end, rather than as a card full of dashes for each
          thing that is missing. It names what is absent and offers the way to
          fix it, which a blank panel does not.
        -->
        <section v-if="missingSections.length" class="ed-card ed-card-quiet">
          <h3 class="ed-card-plain">Not recorded yet</h3>
          <p class="ed-muted">
            Nothing has been captured for
            <strong>{{ missingSections.join(", ") }}</strong>.
            <template v-if="!hasSensitive">
              Some of it may also be hidden — this record shows only what your permissions allow.
            </template>
          </p>
          <router-link class="btn btn-outline btn-sm" :to="{ name: 'employee-edit', params: { id: (employee as any).id } }">
            Fill it in
          </router-link>
        </section>
      </div><!-- /overview -->

      <!-- ── CAREER ────────────────────────────────────────────────────────── -->
      <div v-if="tab === 'job-details'" class="ed-tab-body">

        <!--
          The two cards the design puts at the top of this tab. Every field
          already existed; the compensation figures are read-only here and
          edited on Payroll, where the statutory contributions they drive
          already live. One number, one place to change it.
        -->
        <div class="ed-two-col">
          <div class="ed-section-card">
            <h3 class="ed-section-title ed-card-h">
              <span class="ed-card-icon" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                  <rect x="3" y="6" width="9" height="11" rx="1.5" /><path d="M12 9h5v8h-5M5.5 9h4M5.5 12h4M5.5 15h4" />
                </svg>
              </span>
              <span>Job Details<em>Role, team, and reporting structure.</em></span>
            </h3>
            <div class="ed-kv-list">
              <div class="ed-kv-row"><span class="ed-kv-label">Employee No.</span>
                <span class="ed-kv-val ed-kv-accent">{{ (employee as any).employee_number ?? "—" }}</span></div>
              <div class="ed-kv-row"><span class="ed-kv-label">Employment Type</span>
                <span class="ed-kv-val" style="text-transform:capitalize">{{ (employee as any).employment_type?.replace(/_/g, " ") ?? "—" }}</span></div>
              <div class="ed-kv-row"><span class="ed-kv-label">Department</span>
                <span class="ed-kv-val">{{ (employee as any).host_department ?? (employee as any).department?.name ?? "—" }}</span></div>
              <div class="ed-kv-row"><span class="ed-kv-label">Designation</span>
                <span class="ed-kv-val">
                  <template v-if="(employee as any).host_title || (employee as any).designation?.title || (employee as any).designation?.name">
                    {{ (employee as any).host_title ?? (employee as any).designation?.title ?? (employee as any).designation?.name }}
                  </template>
                  <span v-else class="ed-unset">not set</span>
                </span></div>
              <div class="ed-kv-row"><span class="ed-kv-label">Job Grade</span>
                <span class="ed-kv-val">{{ (employee as any).job_grade?.name ?? "—" }}</span></div>
              <div class="ed-kv-row"><span class="ed-kv-label">Date of Joining</span>
                <span class="ed-kv-val">{{ fmtDate((employee as any).date_of_joining ?? (employee as any).date_hired) }}</span></div>
              <div class="ed-kv-row"><span class="ed-kv-label">Probation / Review Date</span>
                <span class="ed-kv-val">{{ fmtDate((employee as any).probation_end_date) }}</span></div>
            </div>
          </div>

          <!-- Pay is gated the same way the rest of the sensitive material is. -->
          <div v-if="hasSensitive" class="ed-section-card">
            <h3 class="ed-section-title ed-card-h">
              <span class="ed-card-icon" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                  <ellipse cx="10" cy="5.5" rx="6" ry="2.5" /><path d="M4 5.5v9c0 1.4 2.7 2.5 6 2.5s6-1.1 6-2.5v-9M4 10c0 1.4 2.7 2.5 6 2.5s6-1.1 6-2.5" />
                </svg>
              </span>
              <span>Monthly Compensation<em>Basic pay and allowances.</em></span>
            </h3>
            <div class="ed-kv-list">
              <div class="ed-kv-row"><span class="ed-kv-label">Basic Salary</span>
                <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).basic_salary) }}</span></div>
              <div class="ed-kv-row"><span class="ed-kv-label">House Allowance</span>
                <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).house_allowance) }}</span></div>
              <div class="ed-kv-row"><span class="ed-kv-label">Transport Allowance</span>
                <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).transport_allowance) }}</span></div>
              <div class="ed-kv-row"><span class="ed-kv-label">Medical Allowance</span>
                <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).medical_allowance) }}</span></div>
              <div class="ed-kv-row"><span class="ed-kv-label">Other Allowances</span>
                <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).other_allowances) }}</span></div>
              <div class="ed-kv-row ed-kv-total"><span class="ed-kv-label">Gross Pay</span>
                <span class="ed-kv-val ed-kv-mono">KES {{ fmt(empGross) }}</span></div>
              <!--
                Below the total on purpose. Insurance relief is a credit against
                tax, not part of gross, and adding it to the figures above it
                would overstate what the company pays.
              -->
              <div class="ed-kv-row"><span class="ed-kv-label">Insurance Relief</span>
                <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).insurance_relief) }}</span></div>
            </div>
          </div>
        </div>

        <!-- Career Details -->
        <div class="ed-section-card">
          <h3 class="ed-section-title">Career Details</h3>
          <div class="ed-kv-list">
            <div class="ed-kv-row">
              <span class="ed-kv-label">Job Grade</span>
              <span class="ed-kv-val">{{ (employee as any).job_grade?.name ?? "—" }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Employment Type</span>
              <span class="ed-kv-val" style="text-transform:capitalize">{{ (employee as any).employment_type?.replace(/_/g, " ") ?? "—" }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Date of Joining</span>
              <span class="ed-kv-val">{{ fmtDate((employee as any).date_of_joining ?? (employee as any).date_hired) }}</span>
            </div>
            <div class="ed-kv-row" v-if="(employee as any).probation_end_date">
              <span class="ed-kv-label">Probation / Review Date</span>
              <span class="ed-kv-val">{{ fmtDate((employee as any).probation_end_date) }}</span>
            </div>
            <div class="ed-kv-row" v-if="(employee as any).contract_end_date">
              <span class="ed-kv-label">Contract End</span>
              <span class="ed-kv-val">{{ fmtDate((employee as any).contract_end_date) }}</span>
            </div>
          </div>
        </div>

        <!-- Reporting Structure -->
        <div class="ed-section-card">
          <h3 class="ed-section-title">Organization Hierarchy</h3>

          <!--
            Manager above, this person highlighted, reports below — the shape
            the design draws. A key-value row saying "Reports To: X" states the
            same fact but does not show where somebody sits.
          -->
          <div class="ed-hier">
            <router-link
              v-if="(employee as any).manager"
              class="ed-hier-node"
              :to="{ name: 'employee-detail', params: { id: (employee as any).manager.id } }"
            >
              <span class="ed-hier-avatar">{{ initials((employee as any).manager) }}</span>
              <span class="ed-hier-text">
                <strong>{{ (employee as any).manager.full_name }}</strong>
                <em>Manager</em>
              </span>
            </router-link>

            <div v-if="(employee as any).manager" class="ed-hier-stem" aria-hidden="true"></div>

            <div class="ed-hier-node ed-hier-self">
              <span class="ed-hier-avatar">{{ initials(employee) }}</span>
              <span class="ed-hier-text">
                <strong>{{ (employee as any).full_name }}</strong>
                <em>
                  <template v-if="(employee as any).designation?.title || (employee as any).designation?.name">
                    {{ (employee as any).designation?.title ?? (employee as any).designation?.name }}
                  </template>
                  <template v-else>Role not set</template>
                  &nbsp;•&nbsp;<span style="text-transform:capitalize">{{ (employee as any).employment_type?.replace(/_/g, " ") ?? "—" }}</span>
                </em>
              </span>
              <span :class="['badge', statusBadge((employee as any).status)]" style="text-transform:capitalize">
                {{ (employee as any).status }}
              </span>
            </div>

            <template v-if="(employee as any).direct_reports?.length">
              <div class="ed-hier-stem" aria-hidden="true"></div>
              <div class="ed-hier-children">
                <router-link
                  v-for="dr in (employee as any).direct_reports"
                  :key="dr.id"
                  class="ed-hier-node ed-hier-child"
                  :to="{ name: 'employee-detail', params: { id: dr.id } }"
                >
                  <span class="ed-hier-avatar">{{ initials(dr) }}</span>
                  <span class="ed-hier-text">
                    <strong>{{ dr.full_name }}</strong>
                    <em>{{ dr.designation?.title ?? "Role not set" }}</em>
                  </span>
                </router-link>
              </div>
            </template>
          </div>

          <p v-if="!(employee as any).manager && !(employee as any).direct_reports?.length" class="ed-empty-hint">
            Nobody reports to this person and they report to nobody — they sit alone on the chart.
          </p>
        </div>

        <!-- Job Description -->
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Job Description</h3>
            <button v-if="hasSensitive && !editingJD" class="btn btn-outline btn-sm" @click="openEditJD">✏ Edit</button>
            <button v-if="editingJD" class="btn btn-secondary btn-sm" @click="editingJD = false">Cancel</button>
          </div>
          <div v-if="editingJD">
            <textarea v-model="jdDraft" class="input" rows="7" style="width:100%;resize:vertical" placeholder="Describe this employee's responsibilities, expectations, and key deliverables…" />
            <div style="margin-top:8px;display:flex;gap:8px">
              <button class="btn btn-primary btn-sm" :disabled="jdSaving" @click="saveJD">{{ jdSaving ? "Saving…" : "Save" }}</button>
            </div>
          </div>
          <p v-else-if="(employee as any).job_description" class="ed-prose">{{ (employee as any).job_description }}</p>
          <p v-else class="ed-empty-hint">No job description added.</p>
        </div>

        <!-- Qualifications & Certifications -->
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Qualifications &amp; Certifications</h3>
            <button v-if="hasSensitive" class="btn btn-outline btn-sm" @click="showQualForm = !showQualForm">
              {{ showQualForm ? "✕ Cancel" : "+ Add" }}
            </button>
          </div>

          <div v-if="showQualForm" class="ed-sub-form">
            <p v-if="qualError" class="error" style="margin-bottom:8px">{{ qualError }}</p>
            <div class="ed-comp-form-grid">
              <div>
                <label class="ed-form-label">Title / Qualification Name *</label>
                <input v-model="qualForm.title" type="text" class="input" placeholder="e.g. BSc Information Technology" />
              </div>
              <div>
                <label class="ed-form-label">Institution / Awarding Body</label>
                <input v-model="qualForm.institution" type="text" class="input" placeholder="University, college, or body" />
              </div>
              <div>
                <label class="ed-form-label">Type</label>
                <select v-model="qualForm.qualification_type" class="input">
                  <option value="degree">Degree</option>
                  <option value="diploma">Diploma</option>
                  <option value="certificate">Certificate</option>
                  <option value="professional">Professional Certification</option>
                </select>
              </div>
              <div>
                <label class="ed-form-label">Year Obtained</label>
                <input v-model="qualForm.year_obtained" type="number" class="input" min="1950" :max="new Date().getFullYear()" placeholder="e.g. 2020" />
              </div>
              <div>
                <label class="ed-form-label">Field of Study</label>
                <input v-model="qualForm.field_of_study" type="text" class="input" placeholder="e.g. Computer Science" />
              </div>
              <div>
                <label class="ed-form-label">Certificate / Reg. No.</label>
                <input v-model="qualForm.certificate_number" type="text" class="input" placeholder="Optional" />
              </div>
            </div>
            <div style="margin-top:8px;display:flex;gap:8px">
              <button class="btn btn-primary btn-sm" :disabled="qualSaving || !qualForm.title" @click="addQualification">
                {{ qualSaving ? "Saving…" : "Save Qualification" }}
              </button>
            </div>
          </div>

          <div v-if="(employee as any).qualifications?.length" class="ed-qual-list">
            <div v-for="q in (employee as any).qualifications" :key="q.id" class="ed-qual-card">
              <div class="ed-qual-top">
                <div style="flex:1;min-width:0">
                  <span class="ed-qual-title">{{ q.title }}</span>
                  <span class="ed-qual-type" :style="{ background: qualTypeBadgeColor(q.qualification_type) + '18', color: qualTypeBadgeColor(q.qualification_type) }">
                    {{ q.qualification_type }}
                  </span>
                </div>
                <button v-if="hasSensitive" class="act-btn act-del" @click="deleteQualification(q.id)">✕</button>
              </div>
              <div class="ed-qual-meta">
                <span v-if="q.institution">{{ q.institution }}</span>
                <span v-if="q.field_of_study"> · {{ q.field_of_study }}</span>
                <span v-if="q.year_obtained" class="ed-qual-year">{{ q.year_obtained }}</span>
              </div>
              <div v-if="q.certificate_number" class="ed-qual-cert">Cert / Reg #: {{ q.certificate_number }}</div>
            </div>
          </div>
          <p v-else-if="!showQualForm" class="ed-empty-hint">No qualifications or certifications recorded.</p>
        </div>

        <!-- ── Organisation ──────────────────────────────────────────────── -->
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Organisation</h3>
            <button v-if="hasSensitive" class="btn btn-outline btn-sm" @click="openMove()">
              Move to another organisation
            </button>
          </div>

          <p v-if="moveNote" class="ed-ok">{{ moveNote }}</p>

          <dl class="ed-kv">
            <div><dt>Employed by</dt><dd>{{ employee?.primary_organization?.name ?? "—" }}</dd></div>
          </dl>

          <!-- Where they have been. Absent until somebody moves, rather than an
               empty heading on every employee in the company. -->
          <template v-if="movements.length">
            <h4 class="ed-form-label" style="margin-top:14px">Movement history</h4>
            <ul class="ed-moves">
              <li v-for="m in movements" :key="m.id">
                <span class="ed-move-when">{{ m.effective_on }}</span>
                <span>
                  {{ m.kind === "transfer" ? "Transferred" : m.kind === "return" ? "Returned" : "Placed" }}
                  from <strong>{{ m.from?.name }}</strong> to <strong>{{ m.to?.name }}</strong>
                </span>
                <span v-if="m.reason" class="ed-move-why">{{ m.reason }}</span>
              </li>
            </ul>
          </template>

          <!-- The move itself: the check first, the form second. -->
          <div v-if="showMove" class="ed-sub-form" style="margin-top:14px">
            <p v-if="moveError" class="error" style="margin-bottom:8px">{{ moveError }}</p>

            <p v-if="!preflight" class="ed-muted">Checking what still depends on them…</p>

            <template v-else>
              <!--
                What is holding them where they are. Shown before anything is
                chosen, because the useful order is to clear these first — an
                open task in the old organisation cannot be re-saved once its
                assignee works somewhere else.
              -->
              <div v-if="preflight.blocking.length || preflight.warnings.length" class="ed-preflight">
                <p class="ed-form-label" style="margin:0 0 6px">
                  Still depending on them in {{ employee?.primary_organization?.name }}
                </p>
                <ul>
                  <li v-for="i in preflight.blocking" :key="i.key" class="blocking">
                    <strong>{{ i.count }} — {{ i.label }}</strong>
                    <span>{{ i.why }}</span>
                  </li>
                  <li v-for="i in preflight.warnings" :key="i.key">
                    <strong>{{ i.count }} — {{ i.label }}</strong>
                    <span>{{ i.why }}</span>
                  </li>
                </ul>
              </div>
              <p v-else class="ed-ok" style="margin-bottom:10px">
                Nothing in {{ employee?.primary_organization?.name }} still depends on them.
              </p>

              <div class="ed-comp-form-grid">
                <div>
                  <label class="ed-form-label">Kind *</label>
                  <select v-model="moveForm.kind" class="input">
                    <!-- Two outcomes of one thing, said plainly enough that
                         nobody picks the wrong one. -->
                    <option value="transfer">Transfer — they change employer</option>
                    <option value="secondment">Secondment — they stay on this payroll</option>
                  </select>
                </div>
                <div>
                  <label class="ed-form-label">Organisation *</label>
                  <select v-model="moveForm.organization_id" class="input">
                    <option :value="null">Select organisation…</option>
                    <option v-for="o in secondableOrgs" :key="o.id" :value="o.id">{{ o.name }}</option>
                  </select>
                </div>
                <div>
                  <label class="ed-form-label">Effective from *</label>
                  <input v-model="moveForm.effective_on" type="date" class="input" />
                </div>
                <div style="grid-column:1/-1">
                  <label class="ed-form-label">Reason</label>
                  <input v-model="moveForm.reason" class="input"
                         placeholder="Why they are moving — read back months later" />
                </div>
              </div>

              <label v-if="moveForm.kind === 'transfer' && preflight && !preflight.clear"
                     class="ed-force">
                <input v-model="moveForm.force" type="checkbox" />
                <span>
                  Move them anyway. The things above stay behind in
                  {{ employee?.primary_organization?.name }} and somebody will have to deal with
                  them there.
                </span>
              </label>

              <div class="ed-sub-actions">
                <button class="btn btn-ghost btn-sm" @click="showMove = false">Cancel</button>
                <button class="btn btn-primary btn-sm" :disabled="moveSaving" @click="submitMove()">
                  {{ moveSaving ? "Moving…" : "Move" }}
                </button>
              </div>
            </template>
          </div>
        </div>

        <!-- Secondments / Placements -->
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Secondments &amp; External Placements</h3>
            <button v-if="hasSensitive" class="btn btn-outline btn-sm" @click="showSecondmentForm = !showSecondmentForm">
              {{ showSecondmentForm ? "✕ Cancel" : "+ Add" }}
            </button>
          </div>

          <div v-if="showSecondmentForm" class="ed-sub-form">
            <p v-if="secondmentError" class="error" style="margin-bottom:8px">{{ secondmentError }}</p>
            <div class="ed-comp-form-grid">
              <!-- Picked, not typed, so "who is 50% billable to Tiberbu this
                   quarter" is a question the data can answer. -->
              <div>
                <label class="ed-form-label">Organisation *</label>
                <select v-model="secondmentForm.organization_id" class="input">
                  <option :value="null">Select organisation…</option>
                  <option v-for="o in secondableOrgs" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>
              </div>
              <div>
                <label class="ed-form-label">Engagement Type</label>
                <select v-model="secondmentForm.engagement_type" class="input">
                  <option value="secondment">Secondment</option>
                  <option value="shared_service">Shared service</option>
                  <option value="advisory">Advisory</option>
                  <option value="project">Project assignment</option>
                  <option value="coverage">Coverage / acting</option>
                </select>
              </div>
              <div>
                <label class="ed-form-label">Role / Title</label>
                <input v-model="secondmentForm.role" type="text" class="input" placeholder="Job title during secondment" />
              </div>
              <div>
                <label class="ed-form-label">Time Allocation (%)</label>
                <input v-model.number="secondmentForm.allocation_pct" type="number" class="input"
                       min="1" :max="secondmentLeft" />
                <p class="ed-empty-hint" style="margin:4px 0 0">
                  {{ secondmentLeft }}% of their week is still unassigned.
                </p>
              </div>
              <div>
                <label class="ed-form-label">Start Date *</label>
                <input v-model="secondmentForm.effective_from" type="date" class="input" />
              </div>
              <div>
                <label class="ed-form-label">End Date</label>
                <input v-model="secondmentForm.effective_to" type="date" class="input" />
              </div>
            </div>
            <div style="margin-top:8px">
              <label class="ed-form-label">
                <input v-model="secondmentForm.is_billable" type="checkbox" />
                Billable to the secondary organisation
              </label>
            </div>
            <div style="margin-top:8px">
              <label class="ed-form-label">Notes</label>
              <input v-model="secondmentForm.notes" type="text" class="input" placeholder="Optional notes" />
            </div>
            <div style="margin-top:8px;display:flex;gap:8px">
              <button class="btn btn-primary btn-sm"
                      :disabled="secondmentSaving || !secondmentForm.organization_id
                        || !secondmentForm.effective_from
                        || secondmentForm.allocation_pct < 1
                        || secondmentForm.allocation_pct > secondmentLeft"
                      @click="addSecondment">
                {{ secondmentSaving ? "Saving…" : "Save Secondment" }}
              </button>
            </div>
          </div>

          <div v-if="(employee as any).secondments?.length" class="ed-secondment-list">
            <div v-for="s in (employee as any).secondments" :key="s.organization_id"
                 :class="['ed-secondment-card', { 'ed-secondment-ended': s.is_active === false }]">
              <div class="ed-secondment-top">
                <div style="flex:1;min-width:0">
                  <span class="ed-secondment-org">{{ s.organization_name }}</span>
                  <span v-if="s.role" class="ed-secondment-role">{{ s.role }}</span>
                </div>
                <button v-if="hasSensitive && s.is_active !== false" class="act-btn act-del"
                        title="End this secondment" @click="endSecondment(s.organization_id)">✕</button>
              </div>
              <div class="ed-secondment-dates">
                {{ fmtDate(s.start_date) }} → {{ s.end_date ? fmtDate(s.end_date) : "Present" }}
                <span v-if="s.allocation_pct"> · {{ s.allocation_pct }}%</span>
                <span v-if="s.engagement_type"> · {{ s.engagement_type.replace("_", " ") }}</span>
                <span :class="['badge', s.is_billable ? 'badge-green' : 'badge-gray']" style="margin-left:6px">
                  {{ s.is_billable ? "Billable" : "Not billable" }}
                </span>
                <span v-if="s.is_active === false" class="badge badge-gray" style="margin-left:6px">Ended</span>
              </div>
              <p v-if="s.notes" class="ed-secondment-notes">{{ s.notes }}</p>
            </div>
          </div>
          <p v-else-if="!showSecondmentForm" class="ed-empty-hint">No secondments or external placements recorded.</p>
        </div>

        <!-- Exit info (if exited) -->
        <div v-if="(employee as any).date_exited" class="ed-section-card ed-exit-card">
          <h3 class="ed-section-title" style="color:#dc2626">Exit Information</h3>
          <div class="ed-kv-list">
            <div class="ed-kv-row">
              <span class="ed-kv-label">Exit Date</span>
              <span class="ed-kv-val">{{ fmtDate((employee as any).date_exited) }}</span>
            </div>
            <div class="ed-kv-row" v-if="(employee as any).exit_reason">
              <span class="ed-kv-label">Reason</span>
              <span class="ed-kv-val">{{ (employee as any).exit_reason }}</span>
            </div>
          </div>
        </div>

        <!-- Process exit -->
        <div v-if="(employee as any).status === 'active'" class="ed-section-card">
          <div class="ed-section-hdr">
            <h3 class="ed-section-title" style="margin:0">Process Exit</h3>
            <button class="btn btn-danger btn-sm" @click="showExit = !showExit">
              {{ showExit ? "Cancel" : "Process Exit" }}
            </button>
          </div>
          <div v-if="showExit" style="margin-top:16px">
            <p class="error" v-if="exitError">{{ exitError }}</p>
            <div class="form-group">
              <label>Exit Date <span style="color:#dc2626">*</span></label>
              <input v-model="exitDate" type="date" class="input" :max="new Date().toISOString().substring(0,10)" />
            </div>
            <div class="form-group">
              <label>Exit Reason</label>
              <input v-model="exitReason" type="text" class="input" placeholder="Resignation, contract end, etc." />
            </div>
            <div style="display:flex;gap:8px">
              <button class="btn btn-danger" :disabled="exiting" @click="exitEmployee">
                {{ exiting ? "Processing…" : "Confirm Exit" }}
              </button>
              <button class="btn btn-secondary" @click="showExit = false">Cancel</button>
            </div>
          </div>
        </div>
      </div>

      <!--
        Professional Summary, About Me and Hobbies — not in the design's four
        cards, but not a duplicate of anything in them either, so kept rather
        than dropped. Everything else this block used to hold (Personal,
        Identity Documents) was a second copy of the Personal Information and
        Statutory IDs cards above, which is the duplication this page had.
      -->
      <div v-if="tab === 'profile-overview'" class="ed-tab-body ed-tab-body-tight">
        <section class="ed-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-card-plain" style="margin:0">About</h3>
            <button v-if="hasSensitive && !editingProfile" class="btn btn-outline btn-sm" @click="openEditProfile">✏ Edit</button>
            <button v-if="editingProfile" class="btn btn-secondary btn-sm" @click="editingProfile = false">Cancel</button>
          </div>

          <div v-if="editingProfile">
            <div class="form-group">
              <label class="ed-form-label">Professional Summary</label>
              <textarea v-model="profileDraft.professional_summary" class="input" rows="4" style="width:100%;resize:vertical" placeholder="Brief professional background and career highlights…" />
            </div>
            <div class="form-group">
              <label class="ed-form-label">About Me</label>
              <textarea v-model="profileDraft.about_me" class="input" rows="3" style="width:100%;resize:vertical" placeholder="Personal statement or introduction…" />
            </div>
            <div class="form-group">
              <label class="ed-form-label">Hobbies &amp; Interests</label>
              <textarea v-model="profileDraft.hobbies_interests" class="input" rows="2" style="width:100%;resize:vertical" placeholder="Reading, running, photography…" />
            </div>
            <button class="btn btn-primary btn-sm" :disabled="profileSaving" @click="saveProfile">{{ profileSaving ? "Saving…" : "Save Profile" }}</button>
          </div>

          <!-- Nothing printed at all when none of the three is set — not
               three cards each saying so. -->
          <template v-else-if="(employee as any).professional_summary || (employee as any).about_me || (employee as any).hobbies_interests">
            <p v-if="(employee as any).professional_summary" class="ed-prose">{{ (employee as any).professional_summary }}</p>
            <p v-if="(employee as any).about_me" class="ed-prose">{{ (employee as any).about_me }}</p>
            <p v-if="(employee as any).hobbies_interests" class="ed-prose" style="margin:0">{{ (employee as any).hobbies_interests }}</p>
          </template>
        </section>
      </div>

      <!-- ── BENEFITS ──────────────────────────────────────────────────────── -->
      <div v-if="tab === 'payroll'" class="ed-tab-body">
        <div v-if="payslips.length" class="ed-section-card">
          <h3 class="ed-section-title">Pay history</h3>
          <table class="ed-mini-table">
            <thead><tr><th>Run</th><th>Status</th><th>Gross</th><th>PAYE</th><th>Net</th></tr></thead>
            <tbody>
              <tr v-for="p in payslips" :key="p.run_id">
                <td>#{{ p.run_id }}</td>
                <td style="text-transform:capitalize">{{ p.run_status }}</td>
                <td>KES {{ fmt(p.gross) }}</td>
                <td>KES {{ fmt(p.paye) }}</td>
                <td><strong>KES {{ fmt(p.net) }}</strong></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:16px">
            <h3 class="ed-section-title" style="margin:0">Monthly Compensation</h3>
            <div style="display:flex;gap:8px;align-items:center">
              <span v-if="compSuccess" class="ed-success-badge">{{ compSuccess }}</span>
              <button class="btn btn-outline btn-sm" @click="showCompEdit = !showCompEdit">
                {{ showCompEdit ? "✕ Cancel" : "✏ Edit" }}
              </button>
            </div>
          </div>
          <p v-if="compError" class="error">{{ compError }}</p>

          <div v-if="!showCompEdit" class="ed-kv-list">
            <div class="ed-kv-row">
              <span class="ed-kv-label">Basic Salary</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).basic_salary) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">House Allowance</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).house_allowance) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Transport Allowance</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).transport_allowance) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Medical Allowance</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).medical_allowance) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Other Allowances</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).other_allowances) }}</span>
            </div>
            <div class="ed-kv-row ed-kv-total">
              <span class="ed-kv-label">Gross Pay</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt(empGross) }}</span>
            </div>
          </div>

          <div v-else>
            <div class="ed-comp-form-grid">
              <div>
                <label class="ed-form-label">Basic Salary (KES)</label>
                <input v-model.number="comp.basic_salary" type="number" min="0" class="input" />
              </div>
              <div>
                <label class="ed-form-label">House Allowance</label>
                <input v-model.number="comp.house_allowance" type="number" min="0" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Transport Allowance</label>
                <input v-model.number="comp.transport_allowance" type="number" min="0" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Medical Allowance</label>
                <input v-model.number="comp.medical_allowance" type="number" min="0" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Other Allowances</label>
                <input v-model.number="comp.other_allowances" type="number" min="0" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Insurance Relief <small style="color:#94a3b8">(max KES 5,000/mo)</small></label>
                <input v-model.number="comp.insurance_relief" type="number" min="0" class="input" />
              </div>
            </div>
            <div class="ed-comp-form-footer">
              <div class="ed-comp-preview">Gross Preview: <strong>KES {{ fmt(grossPay) }}</strong></div>
              <button class="btn btn-primary btn-sm" :disabled="compSaving" @click="saveComp">
                {{ compSaving ? "Saving…" : "Save Compensation" }}
              </button>
            </div>
          </div>
        </div>

        <div class="ed-section-card" style="margin-top:16px">
          <h3 class="ed-section-title">Statutory Contributions (Estimated Monthly)</h3>
          <div class="ed-kv-list">
            <div class="ed-kv-row">
              <span class="ed-kv-label">SHIF (2.75% of Gross)</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt(empGross * 0.0275) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">NSSF Tier I (6% up to KES 7,000)</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt(Math.min(empGross, 7000) * 0.06) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Housing Levy (1.5% of Gross)</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt(empGross * 0.015) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Insurance Relief</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).insurance_relief) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">NITA Levy</span>
              <span class="ed-kv-val ed-kv-mono">KES 50.00</span>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════════  DOCUMENTS  ═══════════════ -->
      <!-- ── EMERGENCY CONTACTS ────────────────────────────────────────── -->
      <div v-if="tab === 'emergency-contacts'" class="ed-tab-body">
        <div class="ed-section-card">
          <h3 class="ed-section-title">Emergency Contacts</h3>
          <p v-if="sideError" class="error">{{ sideError }}</p>
          <table v-else-if="contacts.length" class="ed-mini-table">
            <thead><tr><th>Name</th><th>Relationship</th><th>Phone</th><th></th><th></th></tr></thead>
            <tbody>
              <tr v-for="c in contacts" :key="c.id">
                <td>{{ c.name }}</td>
                <td>{{ c.relationship ?? "—" }}</td>
                <td>{{ c.phone ?? "—" }}</td>
                <td><span v-if="c.is_primary" class="badge badge-green">Primary</span></td>
                <td class="ed-right">
                  <button v-if="hasSensitive" class="ed-link" style="margin-right:10px" @click="openEditEC(c)">Edit</button>
                  <button v-if="hasSensitive" class="ed-link ed-link-danger" @click="removeContact(c.id)">Remove</button>
                </td>
              </tr>
            </tbody>
          </table>
          <!--
            Says what is missing and offers the fix in the same place. A blank
            panel reads as a fault; sending somebody to another screen to add
            the first one is how the table stays empty.
          -->
          <p v-else class="ed-muted">
            No emergency contact is recorded — the one thing somebody needs when it is already too
            late to go looking.
          </p>

          <!-- Correcting one, rather than deleting and retyping it. -->
          <form v-if="hasSensitive && showECForm" class="ed-add-row" @submit.prevent="saveEC">
            <input v-model="ecForm.name" class="input" placeholder="Name" required />
            <input v-model="ecForm.relationship" class="input" placeholder="Relationship" />
            <input v-model="ecForm.phone" class="input" placeholder="Phone" required />
            <input v-model="ecForm.email" class="input" type="email" placeholder="Email (optional)" />
            <label class="ed-inline-check"><input v-model="ecForm.is_primary" type="checkbox" /> Primary</label>
            <button class="btn btn-primary btn-sm" type="submit" :disabled="ecSaving">Save</button>
            <button class="btn btn-secondary btn-sm" type="button" @click="showECForm = false">Cancel</button>
            <span v-if="ecError" class="error" style="flex-basis:100%">{{ ecError }}</span>
          </form>

          <form v-else-if="hasSensitive" class="ed-add-row" @submit.prevent="addContact">
            <input v-model="newContact.name" class="input" placeholder="Name" required />
            <input v-model="newContact.relationship" class="input" placeholder="Relationship" />
            <input v-model="newContact.phone" class="input" placeholder="Phone" required />
            <input v-model="newContact.email" class="input" type="email" placeholder="Email (optional)" />
            <button class="btn btn-primary btn-sm" type="submit" :disabled="addingSide">Add</button>
          </form>
        </div>
      </div>

      <!-- ── DEPENDENTS ────────────────────────────────────────────────── -->
      <div v-if="tab === 'dependents'" class="ed-tab-body">
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Dependents</h3>
            <router-link class="btn btn-outline btn-sm" :to="{ name: 'employee-dependents', params: { id: (employee as any).id } }">
              Manage
            </router-link>
          </div>
          <p v-if="sideError" class="error">{{ sideError }}</p>
          <table v-else-if="dependents.length" class="ed-mini-table">
            <thead><tr><th>Name</th><th>Relationship</th><th>Date of Birth</th><th></th></tr></thead>
            <tbody>
              <tr v-for="d in dependents" :key="d.id">
                <td>{{ d.name }}</td>
                <td>{{ d.relationship ?? "—" }}</td>
                <td>{{ fmtDate(d.date_of_birth) }}</td>
                <td class="ed-right">
                  <button v-if="hasSensitive" class="ed-link ed-link-danger" @click="removeDependent(d.id)">Remove</button>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-else class="ed-muted">No dependents are recorded for this person.</p>

          <form v-if="hasSensitive" class="ed-add-row" @submit.prevent="addDependent">
            <input v-model="newDependent.name" class="input" placeholder="Name" required />
            <input v-model="newDependent.relationship" class="input" placeholder="Relationship" />
            <input v-model="newDependent.date_of_birth" class="input" type="date" aria-label="Date of birth" />
            <button class="btn btn-primary btn-sm" type="submit" :disabled="addingSide">Add</button>
          </form>
        </div>
      </div>

      <!-- ── ATTENDANCE ────────────────────────────────────────────────── -->
      <div v-if="tab === 'attendance'" class="ed-tab-body">
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Attendance Management</h3>
            <div class="ed-month">
              <button aria-label="Previous month" @click="stepAttendanceMonth(-1)">‹</button>
              <span>{{ attendanceMonth || "This month" }}</span>
              <button aria-label="Next month" @click="stepAttendanceMonth(1)">›</button>
            </div>
          </div>

          <p v-if="sideError" class="error">{{ sideError }}</p>

          <template v-else-if="attendance">
            <div class="ed-bal-grid">
              <div class="ed-bal"><span class="ed-bal-type">Present</span><strong>{{ attendance.totals.present }}</strong><em>of {{ attendance.totals.days }} recorded</em></div>
              <div class="ed-bal"><span class="ed-bal-type">Absent</span><strong>{{ attendance.totals.absent }}</strong></div>
              <div class="ed-bal"><span class="ed-bal-type">Late</span><strong>{{ attendance.totals.late }}</strong></div>
              <div class="ed-bal"><span class="ed-bal-type">On leave</span><strong>{{ attendance.totals.on_leave }}</strong></div>
              <div class="ed-bal"><span class="ed-bal-type">Hours</span><strong>{{ attendance.totals.hours }}</strong></div>
            </div>

            <table v-if="attendance.days.length" class="ed-mini-table" style="margin-top:16px">
              <thead><tr><th>Date</th><th>Status</th><th>Where</th><th>In</th><th>Out</th><th>Hours</th></tr></thead>
              <tbody>
                <tr v-for="d in attendance.days" :key="d.id">
                  <td>{{ fmtDate(d.date) }}</td>
                  <td><span :class="['badge', dayTone(d.status)]" style="text-transform:capitalize">{{ plainStatus(d.status) }}</span></td>
                  <td style="text-transform:capitalize">{{ d.location?.replace(/_/g, " ") ?? "—" }}</td>
                  <td>{{ d.clock_in ? new Date(d.clock_in).toLocaleTimeString("en-KE", { hour: "2-digit", minute: "2-digit" }) : "—" }}</td>
                  <td>{{ d.clock_out ? new Date(d.clock_out).toLocaleTimeString("en-KE", { hour: "2-digit", minute: "2-digit" }) : "—" }}</td>
                  <td>{{ d.hours ?? "—" }}</td>
                </tr>
              </tbody>
            </table>
            <p v-else class="ed-muted" style="margin-top:12px">
              No day in this month was recorded for this person.
            </p>
          </template>

          <router-link class="btn btn-outline btn-sm" :to="{ name: 'attendance' }">Open Attendance Tracker</router-link>
        </div>
      </div>

      <!-- ── LEAVE ─────────────────────────────────────────────────────── -->
      <div v-if="tab === 'leave'" class="ed-tab-body">
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Leave Management</h3>
            <router-link class="btn btn-outline btn-sm" :to="{ name: 'leave' }">Open Leave</router-link>
          </div>
          <div v-if="isSelf && balances.length" class="ed-bal-grid">
            <div v-for="b in balances" :key="b.leave_type" class="ed-bal">
              <span class="ed-bal-type">{{ b.leave_type }}</span>
              <strong>{{ b.available_days }}</strong>
              <em>of {{ b.entitled_days }} · {{ b.used_days }} taken</em>
            </div>
          </div>
          <p v-else-if="!isSelf" class="ed-muted">
            Balances are shown on a person's own record. What follows is their request history.
          </p>

          <h4 class="ed-sub-h">Requests</h4>
          <p v-if="sideError" class="error">{{ sideError }}</p>
          <table v-else-if="leaveRows.length" class="ed-mini-table">
            <thead><tr><th>Ref</th><th>Type</th><th>Dates</th><th>Days</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="r in leaveRows" :key="r.id">
                <td>{{ r.reference }}</td>
                <td>{{ r.leave_type?.name ?? "—" }}</td>
                <td>{{ leaveSpan(r) }}</td>
                <td>{{ r.days_requested }}</td>
                <td><span :class="['badge', leaveBadge(r.status)]" style="text-transform:capitalize">{{ r.status }}</span></td>
              </tr>
            </tbody>
          </table>
          <p v-else class="ed-muted">No leave has been requested by this person.</p>
        </div>
      </div>

      <!-- ── PERFORMANCE ───────────────────────────────────────────────── -->
      <div v-if="tab === 'performance'" class="ed-tab-body">
        <!--
          Attendance is advisory here. It is shown for a reviewer to weigh and
          computes no part of the rating — an attendance record people dispute
          should not become an appraisal people dispute.
        -->
        <div v-if="currentCycle" class="ed-section-card">
          <h3 class="ed-section-title">Attendance this cycle — {{ currentCycle.cycle }}</h3>
          <p class="ed-muted">
            {{ fmtDate(currentCycle.from) }} to {{ fmtDate(currentCycle.to) }}. Shown for context;
            it does not compute any part of a rating.
          </p>

          <div v-if="currentCycle.rate !== null" class="ed-bal-grid">
            <div class="ed-bal">
              <span class="ed-bal-type">Present</span>
              <strong>{{ currentCycle.rate }}%</strong>
              <em>{{ currentCycle.present }} of {{ currentCycle.expected }} expected days</em>
            </div>
            <div class="ed-bal"><span class="ed-bal-type">Absent</span><strong>{{ currentCycle.absent }}</strong></div>
            <div class="ed-bal"><span class="ed-bal-type">Late</span><strong>{{ currentCycle.late }}</strong></div>
            <div class="ed-bal">
              <span class="ed-bal-type">Approved leave</span>
              <strong>{{ currentCycle.on_leave }}</strong><em>not counted against</em>
            </div>
          </div>
          <!-- No rate, rather than nought per cent. -->
          <p v-else class="ed-muted">{{ currentCycle.note }}</p>

          <p v-if="currentCycle.unrecorded > 0" class="ed-declined" style="margin-top:12px">
            {{ currentCycle.unrecorded }} day{{ currentCycle.unrecorded === 1 ? "" : "s" }} in this cycle
            have no clock-in recorded. They are excluded from the figures above rather than counted
            as absence — there is no evidence either way, and it is worth asking about.
          </p>
        </div>

        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Reviews</h3>
            <router-link class="btn btn-outline btn-sm" :to="{ name: 'performance' }">Open Performance</router-link>
          </div>

          <template v-if="reviews.length">
            <article v-for="r in reviews" :key="r.id" class="ed-review">
              <header>
                <strong>{{ r.cycle ?? "No cycle recorded" }}</strong>
                <span v-if="r.rating" class="badge badge-green">{{ r.rating }}/5</span>
                <time>{{ fmtDate(r.review_date) }}</time>
              </header>
              <p v-if="r.comments">{{ r.comments }}</p>
              <p v-if="r.outcomes" class="ed-muted" style="margin:0">{{ r.outcomes }}</p>
              <p v-if="r.attendance && r.attendance.rate !== null" class="ed-review-att">
                Attendance over this cycle: <strong>{{ r.attendance.rate }}%</strong>
                ({{ r.attendance.present }} of {{ r.attendance.expected }} expected days)
              </p>
            </article>
          </template>
          <p v-else class="ed-muted">No review has been written for this person yet.</p>
        </div>
      </div>

      <!-- ── VEHICLES ──────────────────────────────────────────────────── -->
      <div v-if="tab === 'vehicles'" class="ed-tab-body">
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <div>
              <h3 class="ed-section-title" style="margin:0 0 2px">Vehicles</h3>
              <p class="ed-muted" style="margin:0">Registered for parking access.</p>
            </div>
            <button v-if="hasSensitive && !showVehicleForm" class="btn btn-outline btn-sm" @click="openAddVehicle">+ Add Vehicle</button>
          </div>

          <p v-if="sideError" class="error">{{ sideError }}</p>

          <table v-else-if="vehicles.length" class="ed-mini-table">
            <thead>
              <tr><th>Registration</th><th>Make</th><th>Model</th><th>Colour</th><th>Year</th><th>Parking Slot</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="v in vehicles" :key="v.id">
                <td class="ed-veh-reg">{{ v.registration_number }}</td>
                <td>{{ v.make ?? "—" }}</td>
                <td>{{ v.model ?? "—" }}</td>
                <td>{{ v.color ?? "—" }}</td>
                <td>{{ v.year ?? "—" }}</td>
                <td><span v-if="v.parking_slot" class="ed-veh-slot">{{ v.parking_slot }}</span><span v-else>—</span></td>
                <td class="ed-right">
                  <button v-if="hasSensitive" class="ed-link" style="margin-right:10px" @click="openEditVehicle(v)">Edit</button>
                  <button v-if="hasSensitive" class="ed-link ed-link-danger" @click="removeVehicle(v.id, v.registration_number)">Remove</button>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-else class="ed-muted">No vehicles are registered for this person.</p>

          <!--
            Expands in place — the same shape as Emergency Contacts, Dependents
            and the Move dialog on this page. Vehicles used to be its own
            routed page, a click away from this profile with none of it in
            view; editing one now never leaves this window.
          -->
          <div v-if="showVehicleForm" class="ed-sub-form" style="margin-top:14px">
            <p v-if="vehicleError" class="error" style="margin-bottom:8px">{{ vehicleError }}</p>
            <div class="ed-comp-form-grid">
              <div>
                <label class="ed-form-label">Registration Number *</label>
                <input v-model="vehicleForm.registration_number" type="text" class="input" placeholder="e.g. KBZ 123A" style="text-transform:uppercase" />
              </div>
              <div>
                <label class="ed-form-label">Make</label>
                <input v-model="vehicleForm.make" type="text" class="input" placeholder="e.g. Toyota" />
              </div>
              <div>
                <label class="ed-form-label">Model</label>
                <input v-model="vehicleForm.model" type="text" class="input" placeholder="e.g. Prado" />
              </div>
              <div>
                <label class="ed-form-label">Colour</label>
                <input v-model="vehicleForm.color" type="text" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Year</label>
                <input v-model="vehicleForm.year" type="number" :min="VEHICLE_YEAR_MIN" :max="VEHICLE_YEAR_NOW + 1" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Parking Slot</label>
                <input v-model="vehicleForm.parking_slot" type="text" class="input" placeholder="e.g. B-12" />
              </div>
              <div style="grid-column:1/-1">
                <label class="ed-form-label">Notes</label>
                <textarea v-model="vehicleForm.notes" class="input" rows="2" style="width:100%;resize:vertical" />
              </div>
            </div>
            <div class="ed-sub-actions">
              <button class="btn btn-ghost btn-sm" @click="showVehicleForm = false">Cancel</button>
              <button class="btn btn-primary btn-sm" :disabled="vehicleSaving" @click="saveVehicle">
                {{ vehicleSaving ? "Saving…" : (editingVehicleId ? "Save Changes" : "Add Vehicle") }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ── ASSETS ASSIGNED ──────────────────────────────────────────── -->
      <div v-if="tab === 'assets'" class="ed-tab-body">
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Assets Assigned</h3>
            <router-link class="btn btn-outline btn-sm" :to="{ name: 'assets' }">Open Asset Register</router-link>
          </div>
          <table v-if="assetsList.length" class="ed-mini-table">
            <thead><tr><th>Asset</th><th>Tag</th><th>Model</th><th>Status</th><th>Since</th><th></th></tr></thead>
            <tbody>
              <tr v-for="a in assetsList" :key="a.id">
                <td>{{ a.name }}</td>
                <td>{{ a.tag ?? "—" }}</td>
                <td>{{ a.model ?? "—" }}</td>
                <td style="text-transform:capitalize">{{ a.status?.replace(/_/g, " ") }}</td>
                <td>{{ fmtDate(a.assigned_date) }}</td>
                <!-- Assigned and custodian are different facts about an
                     asset — worth saying which one this is. -->
                <td><span class="badge badge-gray" style="text-transform:capitalize">{{ a.role }}</span></td>
              </tr>
            </tbody>
          </table>
          <p v-else class="ed-muted">Nothing in the asset register is assigned to this person.</p>
        </div>
      </div>

      <!-- ── SUPPORT TICKETS ──────────────────────────────────────────── -->
      <div v-if="tab === 'support-tickets'" class="ed-tab-body">
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Support Tickets</h3>
            <router-link class="btn btn-outline btn-sm" :to="{ name: 'support' }">Open Service Desk</router-link>
          </div>
          <table v-if="ticketsList.length" class="ed-mini-table">
            <thead><tr><th>Ref</th><th>Subject</th><th>Account</th><th>Status</th><th>Priority</th><th></th></tr></thead>
            <tbody>
              <tr v-for="t in ticketsList" :key="t.id">
                <td>{{ t.reference }}</td>
                <td>{{ t.subject }}</td>
                <td>{{ t.account ?? "—" }}</td>
                <td>{{ t.status ?? "—" }}</td>
                <td>{{ t.priority ?? "—" }}</td>
                <!-- Working it and having raised it are different things to
                     be on this list for. -->
                <td><span class="badge badge-gray" style="text-transform:capitalize">{{ t.role }}</span></td>
              </tr>
            </tbody>
          </table>
          <p v-else class="ed-muted">
            {{ ticketsNote || "No support ticket is assigned to or was raised by this person." }}
          </p>
        </div>
      </div>

      <!-- ── TRAINING ──────────────────────────────────────────────────── -->
      <div v-if="tab === 'training'" class="ed-tab-body">
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Training</h3>
            <router-link class="btn btn-outline btn-sm" :to="{ name: 'training' }">Open Training</router-link>
          </div>
          <table v-if="trainingList.length" class="ed-mini-table">
            <thead><tr><th>Course</th><th>Provider</th><th>Completed</th><th>Certification No.</th></tr></thead>
            <tbody>
              <tr v-for="r in trainingList" :key="r.id">
                <td>{{ r.course_name }}</td>
                <td>{{ r.provider ?? "—" }}</td>
                <td>{{ fmtDate(r.completion_date) }}</td>
                <td>{{ r.certification_no ?? "—" }}</td>
              </tr>
            </tbody>
          </table>
          <p v-else class="ed-muted">No training has been recorded for this person.</p>
        </div>
      </div>

      <!-- ── PROJECTS ──────────────────────────────────────────────────── -->
      <div v-if="tab === 'projects'" class="ed-tab-body">
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Projects</h3>
            <router-link class="btn btn-outline btn-sm" :to="{ name: 'projects' }">Open Projects</router-link>
          </div>
          <table v-if="projectsList.length" class="ed-mini-table">
            <thead><tr><th>Project</th><th>Account</th><th>Status</th><th>Deadline</th><th></th></tr></thead>
            <tbody>
              <tr v-for="p in projectsList" :key="p.id">
                <td>{{ p.name }}</td>
                <td>{{ p.account ?? "—" }}</td>
                <td style="text-transform:capitalize">{{ p.status?.replace(/_/g, " ") }}</td>
                <td>{{ fmtDate(p.deadline) }}</td>
                <!-- Leading a project and delivering on one are different
                     facts about somebody. -->
                <td><span class="badge badge-gray" style="text-transform:capitalize">{{ p.role }}</span></td>
              </tr>
            </tbody>
          </table>
          <p v-else class="ed-muted">This person does not lead or deliver on any project.</p>
        </div>
      </div>

      <!-- ── TASKS ─────────────────────────────────────────────────────── -->
      <div v-if="tab === 'tasks'" class="ed-tab-body">
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Tasks</h3>
            <router-link class="btn btn-outline btn-sm" :to="{ name: 'tasks' }">Open Tasks</router-link>
          </div>
          <table v-if="tasksList.length" class="ed-mini-table">
            <thead><tr><th>Task</th><th>Project</th><th>Status</th><th>Priority</th><th>Deadline</th></tr></thead>
            <tbody>
              <tr v-for="t in tasksList" :key="t.id">
                <td>{{ t.title }}</td>
                <td>{{ t.project?.name ?? "—" }}</td>
                <td style="text-transform:capitalize">{{ t.status.replace(/_/g, " ") }}</td>
                <td style="text-transform:capitalize">{{ t.priority ?? "—" }}</td>
                <td>{{ fmtDate(t.deadline) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-else class="ed-muted">Nothing is assigned to this person right now.</p>
        </div>
      </div>

      <div v-if="tab === 'documents'" class="ed-tab-body">
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Documents</h3>
            <div v-if="hasSensitive" class="ed-doc-upload">
              <select v-model="docCategory" class="input ed-doc-cat">
                <option v-for="c in DOC_CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
              </select>
              <input ref="docFileInput" type="file" class="ed-doc-file"
                     accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" @change="uploadDoc" />
              <button class="btn btn-outline btn-sm" :disabled="docUploading"
                      @click="docFileInput?.click()">
                {{ docUploading ? "Uploading…" : "+ Upload" }}
              </button>
            </div>
          </div>

          <!--
            This said "stored encrypted", which is not true of this class.
            `employee_documents` is not in documents.encrypted_disks and both
            its write and its read go straight to the disk, so contracts and ID
            copies are held as plain files. Saying otherwise on the screen where
            somebody decides whether to upload a passport scan is worse than
            saying nothing.
          -->
          <p class="ed-empty-hint" style="margin-bottom:12px">
            Only people who can see this employee's sensitive details can open
            these.
          </p>

          <p v-if="docError" class="error" style="margin-bottom:8px">{{ docError }}</p>
          <p v-if="docsLoading" class="ed-empty-hint">Loading…</p>

          <div v-else-if="docs.length" class="ed-doc-list">
            <div v-for="d in docs" :key="d.id" class="ed-doc-row">
              <div class="ed-doc-main">
                <span class="ed-doc-name">{{ d.original_name }}</span>
                <span class="ed-doc-meta">
                  {{ docLabel(d.category) }} · {{ humanSize(d.size_bytes) }} · {{ fmtDate(d.created_at) }}
                </span>
              </div>
              <div class="ed-doc-actions">
                <button class="btn btn-outline btn-sm" @click="downloadDoc(d)">Download</button>
                <button v-if="hasSensitive" class="act-btn act-del" title="Delete"
                        @click="deleteDoc(d)">✕</button>
              </div>
            </div>
          </div>

          <p v-else class="ed-empty-hint">
            No documents uploaded yet.
          </p>
        </div>
      </div>

    </div><!-- /employee -->
  </div>
</template>

<style scoped>
/* Root */
/*
 * No ceiling here on purpose, matching the .view convention the rest of the
 * app follows (see style.css) — this page used to hardcode max-width: 88rem,
 * which is exactly the pattern that convention exists to rule out: content
 * stranded in a narrow column with the right half of a wide monitor sitting
 * empty. The two-column grid below stays two columns regardless of how wide
 * the page gets — that shape matches the design — so nothing here actually
 * needed the ceiling; it grows into the space it's given instead of wasting it.
 */
.ed-root { padding: 24px; width: 100%; }

/* Breadcrumb */
.ed-breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; color: #94a3b8; margin-bottom: 20px; }
.ed-bc-link { cursor: pointer; color: var(--color-brand-base); font-weight: 500; }
.ed-bc-link:hover { text-decoration: underline; }
.ed-bc-sep { color: #cbd5e1; }
.ed-bc-current { color: #475569; font-weight: 500; }

/* Profile card */
.ed-profile-card { background: #fff; border: 1px solid var(--color-border); border-radius: 12px; padding: 24px; display: flex; gap: 20px; align-items: flex-start; }
.ed-profile-main { flex: 1; min-width: 0; }
.ed-profile-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.ed-name { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
.ed-sub { font-size: 0.85rem; color: #64748b; margin: 0 0 8px; }
.ed-emp-id-badge { display: inline-block; background: #f0fdf4; border: 1px solid #86efac; color: #166534; font-size: 0.72rem; font-weight: 600; padding: 2px 10px; border-radius: 20px; font-family: ui-monospace, monospace; }
.ed-manager-chip { display: inline-flex; align-items: center; gap: 4px; background: #f0f6ff; border: 1px solid #bfdbfe; color: #1d4ed8; font-size: 0.72rem; font-weight: 500; padding: 2px 10px; border-radius: 20px; margin-left: 6px; }
.ed-profile-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ed-summary-preview { font-size: 0.84rem; color: #475569; margin: 10px 0 0; line-height: 1.55; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

/* Photo wrap */
.ed-photo-wrap { position: relative; flex-shrink: 0; width: 80px; height: 80px; }
.ed-photo-img { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid #e2e8f0; }
.ed-avatar { width: 80px; height: 80px; border-radius: 50%; background: #3b9c74; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.7rem; font-weight: 700; }
.ed-photo-btn { position: absolute; bottom: 0; right: 0; width: 26px; height: 26px; border-radius: 50%; background: #fff; border: 1.5px solid #e2e8f0; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #475569; box-shadow: 0 1px 4px rgba(0,0,0,.12); transition: background 0.15s; }
.ed-photo-btn:hover { background: #f1f5f9; }
.ed-photo-btn:disabled { opacity: 0.6; cursor: default; }

/* Tabs */
.ed-tabs { display: flex; border-bottom: 2px solid #e2e8f0; margin: 24px 0 0; overflow-x: auto; }
.ed-tab { background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; padding: 10px 20px; font-size: 0.88rem; font-weight: 500; color: #64748b; cursor: pointer; white-space: nowrap; transition: color 0.15s, border-color 0.15s; text-decoration: none; }
.ed-tab:hover { color: #1e293b; }
.ed-tab-active { color: var(--color-brand-base); border-bottom-color: var(--color-brand-base); font-weight: 600; }

/* Tab body */
.ed-tab-body { margin-top: 20px; display: flex; flex-direction: column; gap: 16px; }

/* Documents */
.ed-doc-upload { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ed-doc-cat { max-width: 14rem; }
/* The real input is driven by the button beside it, so the browser's own
   "No file chosen" text never appears next to our own controls. */
.ed-doc-file { display: none; }
.ed-doc-list { display: flex; flex-direction: column; }
.ed-doc-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid #e2e8f0; }
.ed-doc-row:last-child { border-bottom: none; }
.ed-doc-main { display: flex; flex-direction: column; min-width: 0; }
.ed-doc-name { font-size: 0.88rem; color: #0f172a; font-weight: 500; overflow-wrap: anywhere; }
.ed-doc-meta { font-size: 0.76rem; color: #64748b; }
.ed-doc-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

/* Two column */
.ed-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 768px) { .ed-two-col { grid-template-columns: 1fr; } }

/* Section card */
.ed-section-card { background: #fff; border: 1px solid var(--color-border); border-radius: 10px; padding: 20px; }
.ed-section-title { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.07em; color: #94a3b8; margin: 0 0 14px; text-transform: uppercase; }
.ed-section-hdr { display: flex; align-items: center; justify-content: space-between; }

/* Sub form */
.ed-sub-form { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 16px; }

/* Sensitive */
.ed-sensitive { border-color: #fed7aa; background: #fffbeb; }
.ed-sensitive-title { color: #d97706; }

/* Health */
.ed-health { border-color: #bfdbfe; background: #eff6ff; }
.ed-health-title { color: #1d4ed8; }
.ed-health-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 24px; }
@media (max-width: 640px) { .ed-health-grid { grid-template-columns: 1fr; } }

/* Emergency contacts */
.ed-ec-form { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
.ed-ec-grid { grid-template-columns: 1fr 1fr; }
.ed-ec-list { display: flex; flex-direction: column; gap: 10px; }
.ed-ec-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; }
.ed-ec-top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px; }
.ed-ec-name { font-weight: 600; font-size: 0.9rem; color: #1e293b; }
.ed-ec-rel { font-size: 0.78rem; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 20px; }
.ed-ec-primary-badge { font-size: 0.72rem; font-weight: 600; background: #dbeafe; color: #1d4ed8; padding: 2px 8px; border-radius: 20px; }
.ed-ec-actions { margin-left: auto; display: flex; gap: 4px; }
.ed-ec-details { font-size: 0.82rem; color: #64748b; display: flex; gap: 16px; flex-wrap: wrap; }

/* Prose */
.ed-prose { white-space: pre-wrap; font-size: 0.88rem; color: #374151; line-height: 1.65; margin: 0; }

/* Reporting structure */
.ed-dr-header { font-size: 0.75rem; font-weight: 600; color: #64748b; letter-spacing: 0.05em; text-transform: uppercase; margin-bottom: 10px; }
.ed-dr-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.ed-dr-chip { display: flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; text-decoration: none; color: inherit; transition: border-color 0.15s, background 0.15s; min-width: 160px; }
.ed-dr-chip:hover { border-color: #94a3b8; background: #f1f5f9; }
.ed-dr-initials { width: 34px; height: 34px; border-radius: 50%; background: #004C97; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.82rem; font-weight: 700; flex-shrink: 0; }
.ed-dr-info { display: flex; flex-direction: column; min-width: 0; }
.ed-dr-name { font-size: 0.85rem; font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ed-dr-role { font-size: 0.75rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Qualifications */
.ed-qual-list { display: flex; flex-direction: column; gap: 10px; }
.ed-qual-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; }
.ed-qual-top { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 4px; }
.ed-qual-title { font-size: 0.9rem; font-weight: 600; color: #0f172a; }
.ed-qual-type { font-size: 0.68rem; font-weight: 600; padding: 2px 8px; border-radius: 20px; text-transform: capitalize; margin-left: 6px; white-space: nowrap; }
.ed-qual-meta { font-size: 0.8rem; color: #64748b; display: flex; flex-wrap: wrap; gap: 0; }
.ed-qual-year { margin-left: auto; font-size: 0.82rem; font-weight: 600; color: #374151; }
.ed-qual-cert { font-size: 0.76rem; color: #94a3b8; margin-top: 4px; font-family: ui-monospace, monospace; }

/* Secondments */
.ed-secondment-list { display: flex; flex-direction: column; gap: 10px; }
.ed-secondment-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; }
/* Ended ones stay on the page — payroll and any invoice reconciliation
   still need them — but they should not read as current. */
.ed-secondment-ended { opacity: 0.6; }
.ed-secondment-top { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 4px; }
.ed-secondment-org { font-size: 0.9rem; font-weight: 600; color: #0f172a; }
.ed-secondment-role { font-size: 0.78rem; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 20px; margin-left: 6px; }
.ed-secondment-dates { font-size: 0.8rem; color: #64748b; margin-bottom: 4px; }
.ed-secondment-notes { font-size: 0.8rem; color: #94a3b8; margin: 4px 0 0; }

/* Empty hint */
.ed-empty-hint { font-size: 0.82rem; color: #94a3b8; margin: 0; }

/* KV wrap */
.ed-kv-wrap { white-space: pre-wrap; text-align: right; max-width: 280px; }
.ed-link { color: var(--color-brand-base); text-decoration: none; font-size: 0.85rem; }
.ed-link:hover { text-decoration: underline; }

/* Exit card */
.ed-exit-card { border-color: #fca5a5; background: #fff7f7; }

/* KV list */
.ed-kv-list { display: flex; flex-direction: column; }
.ed-kv-row { display: flex; justify-content: space-between; align-items: baseline; padding: 9px 0; border-bottom: 1px solid #f8fafc; gap: 16px; }
.ed-kv-row:last-child { border-bottom: none; }
.ed-kv-label { font-size: 0.82rem; color: #64748b; flex-shrink: 0; }
.ed-kv-val { font-size: 0.85rem; color: #0f172a; font-weight: 500; text-align: right; }
.ed-kv-accent { color: var(--color-brand-base); font-weight: 600; }
.ed-kv-mono { font-family: 'SF Mono', ui-monospace, monospace; font-size: 0.82rem; }
.ed-kv-total { border-top: 2px solid #e2e8f0; border-bottom: none; margin-top: 4px; padding-top: 12px; }
.ed-kv-total .ed-kv-label { font-weight: 700; color: #374151; font-size: 0.85rem; }
.ed-kv-total .ed-kv-val { font-weight: 700; font-size: 0.9rem; color: var(--color-brand-base); }

/* Success badge */
.ed-success-badge { background: #f0fdf4; border: 1px solid #86efac; color: #15803d; font-size: 0.78rem; padding: 3px 10px; border-radius: 20px; }

/* Compensation form */
.ed-comp-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
.ed-form-label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 4px; color: #374151; }
.ed-comp-form-footer { display: flex; align-items: center; justify-content: space-between; padding-top: 12px; border-top: 1px solid #f1f5f9; gap: 12px; flex-wrap: wrap; }
.ed-comp-preview { font-size: 0.85rem; color: #64748b; }
.ed-comp-preview strong { color: var(--color-brand-base); }

/* Button outline */
.btn-outline { background: #fff; border: 1px solid var(--color-border); color: #374151; }
.btn-outline:hover { background: #f8fafc; border-color: #94a3b8; }

/* form-group spacing */
.form-group { margin-bottom: 12px; }

/* Act buttons */
.act-btn { background: none; border: 1px solid #e2e8f0; border-radius: 5px; padding: 2px 7px; font-size: 0.78rem; cursor: pointer; color: #64748b; }
.act-btn:hover { background: #f1f5f9; }
.act-del { color: #dc2626; border-color: #fca5a5; }
.act-del:hover { background: #fff7f7; }

@media (max-width: 640px) {
  .ed-comp-form-grid { grid-template-columns: 1fr; }
  .ed-profile-card { flex-direction: column; }
  .ed-ec-grid { grid-template-columns: 1fr; }
}

/* ── Moving between organisations ─────────────────────────────────────────── */
.ed-moves { list-style: none; margin: 6px 0 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.ed-moves li { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px; font-size: 13px; }
.ed-move-when { font-family: ui-monospace, monospace; font-size: 12px; color: var(--color-text-light); width: 6.5rem; }
.ed-move-why { color: var(--color-text-muted); font-size: 12px; flex-basis: 100%; padding-left: 6.5rem; }

/*
 * The holdings list. Blocking items are marked, but everything is shown —
 * a warning somebody cannot see is a warning they will discover in a month.
 */
.ed-preflight { margin-bottom: 12px; padding: 10px 12px; border-radius: 8px;
  background: var(--color-surface-sunken); border: 1px solid var(--color-border); }
.ed-preflight ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 7px; }
.ed-preflight li { display: flex; flex-direction: column; gap: 1px; font-size: 12.5px; }
.ed-preflight li span { color: var(--color-text-muted); }
.ed-preflight li.blocking strong { color: var(--color-danger-text, #b91c1c); }

.ed-force { display: flex; gap: 8px; align-items: flex-start; margin: 10px 0 4px;
  font-size: 12.5px; color: var(--color-text-muted); }
.ed-force input { margin-top: 2px; }
.ed-muted { color: var(--color-text-muted); font-size: 13px; }

/* ── Adopted from the HR portal designs ────────────────────────────────── */

/* Nine tabs do not fit 1440px. Scrolling keeps them on one line instead of
   wrapping into a second row that shoves the content down the page. */
.ed-tabs { overflow-x: auto; flex-wrap: nowrap; scrollbar-width: thin; }
.ed-tab  { white-space: nowrap; }

.ed-unset { color: var(--color-text-light); }
.ed-sub-type { text-transform: capitalize; }

/* Card headings carrying an icon and a subtitle, as the design draws them. */
.ed-card-h { display: flex; align-items: flex-start; gap: 12px; }
.ed-card-h em {
  display: block; font-style: normal; font-weight: 400;
  font-size: 12px; color: var(--color-text-muted); margin-top: 2px;
}
.ed-card-icon {
  display: inline-flex; align-items: center; justify-content: center;
  width: 34px; height: 34px; flex-shrink: 0;
  border-radius: 10px;
  background: var(--color-brand-light); color: var(--color-brand-base);
}
.ed-card-icon svg { width: 18px; height: 18px; }

/* A plain two-column split with no card of its own, for use inside one. */
.ed-two-col-flat { gap: 24px; }

.ed-bal-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 12px; }
.ed-bal {
  display: flex; flex-direction: column;
  padding: 12px 14px;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-surface);
}
.ed-bal-type { font-size: 12px; color: var(--color-text-muted); }
.ed-bal strong { font-size: 24px; font-weight: 600; color: var(--color-text); }
.ed-bal em { font-style: normal; font-size: 11px; color: var(--color-text-light); }

.ed-mini-table { width: 100%; border-collapse: collapse; }
.ed-mini-table th {
  text-align: left; font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: .04em; color: var(--color-text-muted);
  padding: 8px 0; border-bottom: 1px solid var(--color-border);
}
.ed-mini-table td { font-size: 13px; padding: 10px 0; border-bottom: 1px solid var(--color-border); }
.ed-mini-table tbody tr:last-child td { border-bottom: none; }
.ed-right { text-align: right; }

/* Says what is missing rather than showing nothing. */
.ed-muted { font-size: 13px; color: var(--color-text-muted); margin: 0 0 12px; }

.ed-tab-body-tight { margin-top: 0; }

/* ── Organization hierarchy ────────────────────────────────────────────── */
.ed-hier { display: flex; flex-direction: column; }

.ed-hier-node {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 14px;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-surface);
  text-decoration: none; color: inherit;
}
a.ed-hier-node:hover { border-color: var(--color-brand-base); }

/* The person whose record this is, so the eye finds them without reading. */
.ed-hier-self { background: var(--color-success-light); border-color: var(--color-success); }

.ed-hier-avatar {
  display: inline-flex; align-items: center; justify-content: center;
  width: 34px; height: 34px; flex-shrink: 0; border-radius: 999px;
  font-size: 12px; font-weight: 600;
  background: var(--color-brand-light); color: var(--color-brand-base);
}
.ed-hier-text { display: flex; flex-direction: column; flex: 1; min-width: 0; }
.ed-hier-text strong { font-size: 14px; font-weight: 600; color: var(--color-text); }
.ed-hier-text em { font-style: normal; font-size: 12px; color: var(--color-text-muted); }

/* The line that makes it a hierarchy rather than a stack of cards. */
.ed-hier-stem { width: 2px; height: 16px; margin-left: 28px; background: var(--color-border); }

.ed-hier-children { display: flex; flex-direction: column; gap: 8px; padding-left: 28px; }
.ed-hier-child { border-style: dashed; }

/* ── Money ─────────────────────────────────────────────────────────────── */
.ed-kv-total {
  border-top: 1px solid var(--color-border);
  margin-top: 4px; padding-top: 8px;
}
.ed-kv-total .ed-kv-val { color: var(--color-brand-base); font-weight: 600; }

/* ── Adding a row without leaving the tab ──────────────────────────────── */
.ed-add-row {
  display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
  margin-top: 14px; padding-top: 14px;
  border-top: 1px solid var(--color-border);
}
.ed-add-row .input { flex: 1 1 9rem; min-width: 0; }

.ed-sub-h { font-size: 13px; font-weight: 600; color: var(--color-text); margin: 18px 0 8px; }
.ed-link-danger { color: var(--color-error); }

/* ── Attendance and reviews ────────────────────────────────────────────── */
.ed-month {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 2px 8px; border: 1px solid var(--color-border); border-radius: 8px;
  font-size: 12px; color: var(--color-text);
}
.ed-month span { min-width: 5.5rem; text-align: center; }
.ed-month button { border: none; background: none; cursor: pointer; color: var(--color-text-muted); padding: 0 6px; font-size: 14px; }
.ed-month button:hover { color: var(--color-brand-base); }

.ed-review { padding: 12px 0; border-bottom: 1px solid var(--color-border); }
.ed-review:last-child { border-bottom: none; }
.ed-review header { display: flex; flex-wrap: wrap; align-items: baseline; gap: 10px; margin-bottom: 6px; }
.ed-review header strong { font-size: 14px; color: var(--color-text); }
.ed-review time { font-size: 12px; color: var(--color-text-muted); }
.ed-review p { font-size: 13px; color: var(--color-text); margin: 0 0 6px; white-space: pre-wrap; }

/* Advisory, and it should look advisory rather than like a score. */
.ed-review-att {
  font-size: 12px !important; color: var(--color-text-muted) !important;
  background: var(--color-surface-sunken);
  border-radius: 8px; padding: 8px 10px; margin: 8px 0 0 !important;
}

/* ── Profile Overview: cards built from what is known ──────────────────── */
.ed-card {
  background: var(--color-surface-raised, #fff);
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 20px;
}

/* A quiet card for the one line naming what is missing — not styled like the
   amber "sensitive" treatment, because absence is not a warning. */
.ed-card-quiet { background: var(--color-surface-sunken, #fafafa); }

.ed-card-plain {
  font-size: 14px; font-weight: 600; color: var(--color-text);
  margin: 0 0 14px;
}

/* header, not h3 — the design's card title carries an icon and a subtitle
   beside it, not above it. */
.ed-card-h { margin-bottom: 16px; }
.ed-card-h strong { display: block; font-size: 14px; font-weight: 600; color: var(--color-text); }

/*
 * Label left, value right — the design's two-column row, not a table. No rule
 * between rows: with every row conditional, a border would draw a line above
 * a row that is not there and make the gap look like an error.
 */
.ed-rows { display: flex; flex-direction: column; gap: 14px; margin: 0; }
.ed-row { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; }
.ed-row dt { font-size: 12px; color: var(--color-text-muted); white-space: nowrap; }
.ed-row dd {
  display: flex; align-items: center; gap: 6px;
  margin: 0; font-size: 13px; font-weight: 500; color: var(--color-text);
  text-align: right;
}
.ed-row-icon { width: 13px; height: 13px; flex-shrink: 0; color: var(--color-text-light); }

.ed-inline-check { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--color-text-muted); white-space: nowrap; }

/* ── Vehicles ──────────────────────────────────────────────────────────── */
.ed-veh-reg { font-family: ui-monospace, monospace; font-weight: 700; font-size: 13px; color: var(--color-brand-base); text-transform: uppercase; }
.ed-veh-slot { background: #f0fdf4; border: 1px solid #86efac; color: #166534; font-size: 12px; font-weight: 600; padding: 2px 10px; border-radius: 20px; font-family: ui-monospace, monospace; }

/* Footer of an inline expanding form (Move, Vehicles) — was rendered with no
   gap or alignment at all until now. */
.ed-sub-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px; }
</style>
