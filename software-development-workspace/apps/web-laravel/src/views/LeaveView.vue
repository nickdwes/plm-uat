<script setup lang="ts">
/**
 * Leave, as two tabs on one page.
 *
 * My Leaves is what everybody has. Employee Leave Management appears only for
 * somebody entitled to see past themselves, and it is the same screen at a
 * different width — the organisation for HR, direct reports for a manager.
 * The server decides which, and says so in `meta.scope`; nothing here infers
 * it from a role name, because a screen that guesses its own permissions
 * eventually guesses wrong in the generous direction.
 *
 * This replaces the old four-tab layout and absorbs LeaveApprovalsView, which
 * did the same job at a second URL with a second set of code.
 */
import { ref, computed, onMounted, onBeforeUnmount, watch } from "vue";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug, getToken } from "../stores/auth";
import type {
  Paginator, LeaveRequest, LeaveBalance, LeaveBalanceResponse,
  LeaveScope, LeaveSummary, LeaveAttachment, LeaveMessage,
} from "../lib/types";
import LeaveHandoverPanel from "../components/LeaveHandoverPanel.vue";

interface LeaveTypeFull {
  id: number; name: string; code: string | null;
  counting_method: "business_days" | "calendar_days";
  working_days: number[] | null;
  allow_half_day: boolean;
  allow_scattered: boolean;
  days_per_year: number;
}
interface DayBreakdown { date: string; weekday: string; counted: boolean; reason: string; }
interface CalcResult { days: number; counting_method: string; breakdown: DayBreakdown[]; }

function org(): string | null { return currentOrgSlug.value; }

// ── Which tab, and whether the second one exists at all ───────────────────
type TopTab = "mine" | "others";
const topTab = ref<TopTab>("mine");

/**
 * The widest view this user holds, straight from the server.
 *
 * Starts at "own" so the second tab is absent until something proves
 * otherwise — the safe direction to be wrong in for the half-second before
 * the first response lands.
 */
const scope = ref<LeaveScope>("own");

const canSeeOthers = computed(() => scope.value !== "own");

/** HR sees the organisation; a manager sees their reports. Same screen. */
const othersTitle = computed(() => scope.value === "all" ? "Employee Leaves" : "Team Leaves");
const othersLead  = computed(() => scope.value === "all"
  ? "Keep track of your employee leave days and upcoming breaks."
  : "Keep track of your team's leave days and upcoming breaks.");

// ── My leave requests ─────────────────────────────────────────────────────
const myLeaves  = ref<LeaveRequest[]>([]);
const myTotal   = ref(0);
const myPage    = ref(1);
const myLoading = ref(false);
const myError   = ref("");

// ── The other side ────────────────────────────────────────────────────────
const others        = ref<LeaveRequest[]>([]);
const othersTotal   = ref(0);
const othersPage    = ref(1);
const othersPages   = ref(1);
const othersLoading = ref(false);
const othersError   = ref("");
const summary       = ref<LeaveSummary | null>(null);

// ── Filters. Shared shape, one set per tab so they do not bleed. ──────────
function thisMonth(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

const mineFilters   = ref({ search: "", month: "", status: "" });
const othersFilters = ref({ search: "", month: thisMonth(), status: "" });

const filtersOpen = ref(false);

// ── Leave balances, which double as the cards along the top ──────────────
const balances    = ref<LeaveBalance[]>([]);
const balYear     = ref(new Date().getFullYear());
const balLoading  = ref(false);
const balError    = ref("");

const leaveTypes   = ref<LeaveTypeFull[]>([]);

// ── Request Leave drawer ──────────────────────────────────────────────────
const requestOpen = ref(false);

const applyForm = ref({
  leave_type_id: "" as string | number,
  from_date:     "",
  to_date:       "",
  reason:        "",
  is_half_day:   false,
  half_day_period: "morning" as "morning" | "afternoon",
});

const scatteredDates  = ref<string[]>([]);
const newScatterDate  = ref("");

const applying     = ref(false);
const applyError   = ref("");
const applySuccess = ref("");

const calcResult  = ref<CalcResult | null>(null);
const calcLoading = ref(false);

const selectedType = computed<LeaveTypeFull | null>(() => {
  const id = Number(applyForm.value.leave_type_id);
  return leaveTypes.value.find(t => t.id === id) ?? null;
});

const useScattered = ref(false);

const isScatteredMode = computed(() =>
  useScattered.value && !!selectedType.value?.allow_scattered && !applyForm.value.is_half_day
);

// ── Detail / approval drawer ──────────────────────────────────────────────
const openLeave    = ref<LeaveRequest | null>(null);
const detailTab    = ref<"description" | "messages">("description");
const attachments  = ref<LeaveAttachment[]>([]);
const messages     = ref<LeaveMessage[]>([]);
const detailBusy   = ref(false);
const detailError  = ref("");
const newMessage   = ref("");
const messagePrivate = ref(false);
const uploading    = ref(false);

/** Whether the open request is the viewer's own, which changes what it offers. */
const openIsMine = computed(() =>
  !!openLeave.value && myLeaves.value.some(r => r.id === openLeave.value!.id)
);

const canAction = computed(() =>
  !!openLeave.value && openLeave.value.status === "pending" && !openIsMine.value && canSeeOthers.value
);

const handoverFor = ref<{ id: number; label: string } | null>(null);

// ── Loaders ───────────────────────────────────────────────────────────────

async function loadMyLeaves(page = 1): Promise<void> {
  if (!org()) return;
  myLoading.value = true; myError.value = ""; myPage.value = page;
  try {
    const res = await api<Paginator<LeaveRequest> & { meta: { scope?: LeaveScope } }>(
      "GET", "/api/v1/leave",
      { org: org(), params: { page, per_page: 10, scope: "own", ...active(mineFilters.value) } },
    );
    myLeaves.value = res.data;
    myTotal.value  = res.meta.total;
  } catch (e) { myError.value = e instanceof Error ? e.message : "Failed."; }
  finally { myLoading.value = false; }
}

/**
 * Ask for the widest view and let the server answer with what it granted.
 *
 * Nothing here decides whether the user is HR. The request says "as wide as
 * I'm allowed", the reply says how wide that turned out to be, and the tab
 * appears or does not on the strength of it.
 */
async function loadOthers(page = 1): Promise<void> {
  if (!org()) return;
  othersLoading.value = true; othersError.value = ""; othersPage.value = page;
  try {
    const res = await api<Paginator<LeaveRequest> & { meta: { scope?: LeaveScope } }>(
      "GET", "/api/v1/leave",
      { org: org(), params: { page, per_page: 10, scope: "all", ...active(othersFilters.value) } },
    );
    others.value      = res.data;
    othersTotal.value = res.meta.total;
    othersPages.value = res.meta.last_page ?? 1;
    if (res.meta.scope) scope.value = res.meta.scope;
  } catch (e) {
    // A plain employee is refused here, and that is not an error worth showing
    // them — the tab simply is not theirs.
    if (e instanceof ApiError && e.status === 403) { scope.value = "own"; }
    else { othersError.value = e instanceof Error ? e.message : "Failed."; }
  }
  finally { othersLoading.value = false; }
}

async function loadSummary(): Promise<void> {
  if (!org()) return;
  try {
    const res = await api<{ data: LeaveSummary }>("GET", "/api/v1/leave/summary",
      { org: org(), params: { scope: "all" } });
    summary.value = res.data;
    if (res.data.scope) scope.value = res.data.scope;
  } catch { summary.value = null; }
}

async function loadBalance(): Promise<void> {
  if (!org()) return;
  balLoading.value = true; balError.value = "";
  try {
    const res = await api<LeaveBalanceResponse>("GET", "/api/v1/leave/balance", { org: org(), params: { year: balYear.value } });
    balances.value = res.data;
  } catch (e) {
    balError.value = e instanceof ApiError && e.status === 422
      ? "No employee record is linked to your account, so there is no balance to show."
      : e instanceof Error ? e.message : "Failed.";
  }
  finally { balLoading.value = false; }
}

async function loadLeaveTypes(): Promise<void> {
  if (!org()) return;
  try {
    const res = await api<{ data: LeaveTypeFull[] }>("GET", "/api/v1/leave-types", { org: org() });
    leaveTypes.value = (res as { data?: LeaveTypeFull[] }).data ?? [];
  } catch { /* the drawer reports its own emptiness */ }
}

/** Drop blank filters rather than sending empty strings the API must ignore. */
function active(f: Record<string, string>): Record<string, string> {
  return Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ""));
}

// ── The cards along the top of My Leaves ──────────────────────────────────

/**
 * Two named types plus a summary, as the design shows — but chosen from what
 * the organisation actually has rather than hardcoded to Annual and Sick.
 * A tenant without a type called "Annual Leave" should not get a card headed
 * with one.
 */
const balanceCards = computed(() => balances.value.slice(0, 2));

const awaySummary = computed(() => {
  const total = balances.value.reduce((n, b) => n + Number(b.entitled_days ?? 0), 0);
  const used  = balances.value.reduce((n, b) => n + Number(b.used_days ?? 0), 0);
  return { total, used, remaining: Math.max(0, total - used) };
});

// ── Day preview ───────────────────────────────────────────────────────────

async function previewDays(): Promise<void> {
  const typeId = Number(applyForm.value.leave_type_id);
  if (!typeId || !org()) return;

  const body: Record<string, unknown> = { leave_type_id: typeId, is_half_day: applyForm.value.is_half_day };

  if (applyForm.value.is_half_day) {
    body.from_date       = applyForm.value.from_date;
    body.half_day_period = applyForm.value.half_day_period;
  } else if (isScatteredMode.value && scatteredDates.value.length) {
    body.scattered_dates = scatteredDates.value;
  } else if (applyForm.value.from_date && applyForm.value.to_date) {
    body.from_date = applyForm.value.from_date;
    body.to_date   = applyForm.value.to_date;
  } else {
    return;
  }

  calcLoading.value = true; calcResult.value = null;
  try {
    calcResult.value = await api<CalcResult>("POST", "/api/v1/leave/calculate", { org: org(), body });
  } catch { /* non-blocking: the server counts again on submit */ }
  finally { calcLoading.value = false; }
}

function addScatterDate(): void {
  const d = newScatterDate.value;
  if (!d || scatteredDates.value.includes(d)) return;
  scatteredDates.value.push(d);
  scatteredDates.value.sort();
  newScatterDate.value = "";
  void previewDays();
}

function removeScatterDate(d: string): void {
  scatteredDates.value = scatteredDates.value.filter(x => x !== d);
  void previewDays();
}

// ── Submitting a request ──────────────────────────────────────────────────

function openRequest(): void {
  applyError.value = ""; applySuccess.value = "";
  requestOpen.value = true;
}

function resetRequest(): void {
  applyForm.value = { leave_type_id: "", from_date: "", to_date: "", reason: "", is_half_day: false, half_day_period: "morning" };
  scatteredDates.value = [];
  calcResult.value = null;
  useScattered.value = false;
}

async function applyLeave(): Promise<void> {
  const typeId = Number(applyForm.value.leave_type_id);
  if (!typeId) { applyError.value = "Please choose a leave type."; return; }

  if (applyForm.value.is_half_day && !applyForm.value.from_date) {
    applyError.value = "Please choose the date for the half day."; return;
  }
  if (!applyForm.value.is_half_day && !isScatteredMode.value
      && (!applyForm.value.from_date || !applyForm.value.to_date)) {
    applyError.value = "Please give both a start and an end date."; return;
  }
  if (isScatteredMode.value && !scatteredDates.value.length) {
    applyError.value = "Please add at least one date."; return;
  }

  applying.value = true; applyError.value = ""; applySuccess.value = "";

  const body: Record<string, unknown> = {
    leave_type_id: typeId,
    reason:        applyForm.value.reason || null,
    is_half_day:   applyForm.value.is_half_day,
  };

  if (applyForm.value.is_half_day) {
    body.from_date       = applyForm.value.from_date;
    body.to_date         = applyForm.value.from_date;
    body.half_day_period = applyForm.value.half_day_period;
  } else if (isScatteredMode.value) {
    body.scattered_dates = scatteredDates.value;
  } else {
    body.from_date = applyForm.value.from_date;
    body.to_date   = applyForm.value.to_date;
  }

  try {
    await api("POST", "/api/v1/leave", { org: org(), body });
    applySuccess.value = "Request sent.";
    resetRequest();
    void loadMyLeaves(1);
    void loadBalance();
    if (canSeeOthers.value) void loadSummary();
  } catch (e) {
    applyError.value = e instanceof ApiError ? (e.firstError ?? e.message) : (e instanceof Error ? e.message : "Failed.");
  }
  finally { applying.value = false; }
}

// ── The detail drawer ─────────────────────────────────────────────────────

async function openDetail(r: LeaveRequest): Promise<void> {
  openLeave.value = r;
  detailTab.value = "description";
  detailError.value = "";
  attachments.value = [];
  messages.value = [];
  newMessage.value = "";
  messagePrivate.value = false;
  await Promise.all([loadAttachments(r.id), loadMessages(r.id)]);
}

function closeDetail(): void {
  openLeave.value = null;
}

async function loadAttachments(id: number): Promise<void> {
  try {
    const res = await api<{ data: LeaveAttachment[] }>("GET", `/api/v1/leave/${id}/attachments`, { org: org() });
    attachments.value = res.data;
  } catch { attachments.value = []; }
}

async function loadMessages(id: number): Promise<void> {
  try {
    const res = await api<{ data: LeaveMessage[] }>("GET", `/api/v1/leave/${id}/messages`, { org: org() });
    messages.value = res.data;
  } catch { messages.value = []; }
}

async function uploadAttachment(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file  = input.files?.[0];
  if (!file || !openLeave.value) return;

  uploading.value = true; detailError.value = "";
  const form = new FormData();
  form.append("file", file);

  try {
    // formData, not body — the helper JSON-encodes `body` and the browser
    // must set the multipart boundary itself.
    await api("POST", `/api/v1/leave/${openLeave.value.id}/attachments`, { org: org(), formData: form });
    await loadAttachments(openLeave.value.id);
  } catch (e) {
    detailError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not attach that file.";
  } finally {
    uploading.value = false;
    // Clear it, or choosing the same file twice fires no change event.
    input.value = "";
  }
}

async function removeAttachment(id: number): Promise<void> {
  if (!openLeave.value) return;
  try {
    await api("DELETE", `/api/v1/leave/${openLeave.value.id}/attachments/${id}`, { org: org() });
    await loadAttachments(openLeave.value.id);
  } catch (e) {
    detailError.value = e instanceof ApiError ? e.message : "Could not remove that file.";
  }
}

/**
 * Fetched with the token and handed over as a blob.
 *
 * An <a href> cannot carry the Authorization header, and a sick note is
 * exactly the kind of file that must not be reachable without one.
 */
async function downloadAttachment(a: LeaveAttachment): Promise<void> {
  if (!openLeave.value) return;
  detailError.value = "";
  try {
    const base = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");
    const headers: Record<string, string> = {};
    const token = getToken();
    if (token) headers["Authorization"] = `Bearer ${token}`;
    const slug = org();
    if (slug) headers["X-Organization"] = slug;

    const res = await fetch(
      `${base}/api/v1/leave/${openLeave.value.id}/attachments/${a.id}/download`, { headers },
    );
    if (!res.ok) throw new Error(`Download failed (${res.status}).`);

    const url = URL.createObjectURL(await res.blob());
    const link = document.createElement("a");
    link.href = url;
    link.download = a.original_name;
    link.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    detailError.value = e instanceof Error ? e.message : "Download failed.";
  }
}

async function sendMessage(): Promise<void> {
  const body = newMessage.value.trim();
  if (!body || !openLeave.value) return;

  detailBusy.value = true; detailError.value = "";
  try {
    await api("POST", `/api/v1/leave/${openLeave.value.id}/messages`, {
      org: org(),
      body: { body, visible_to_employee: openIsMine.value ? true : !messagePrivate.value },
    });
    newMessage.value = "";
    messagePrivate.value = false;
    await loadMessages(openLeave.value.id);
  } catch (e) {
    detailError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not send that.";
  } finally { detailBusy.value = false; }
}

// ── Decisions ─────────────────────────────────────────────────────────────

async function approveLeave(id: number): Promise<void> {
  detailBusy.value = true; detailError.value = "";
  try {
    await api("POST", `/api/v1/leave/${id}/approve`, { org: org(), body: {} });
    closeDetail();
    await refreshOthers();
  } catch (e) {
    detailError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not approve that.";
  } finally { detailBusy.value = false; }
}

const declineFor    = ref<number | null>(null);
const declineReason = ref("");

async function declineLeave(): Promise<void> {
  const id = declineFor.value;
  const reason = declineReason.value.trim();
  if (!id || !reason) return;

  detailBusy.value = true; detailError.value = "";
  try {
    await api("POST", `/api/v1/leave/${id}/reject`, { org: org(), body: { rejection_reason: reason } });
    declineFor.value = null; declineReason.value = "";
    closeDetail();
    await refreshOthers();
  } catch (e) {
    detailError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not decline that.";
  } finally { detailBusy.value = false; }
}

async function cancelLeave(id: number): Promise<void> {
  if (!confirm("Withdraw this leave request?")) return;
  await api("DELETE", `/api/v1/leave/${id}`, { org: org() });
  closeDetail();
  void loadMyLeaves(myPage.value);
  void loadBalance();
}

async function refreshOthers(): Promise<void> {
  await Promise.all([loadOthers(othersPage.value), loadSummary(), loadMyLeaves(myPage.value)]);
}

// ── The month stepper ─────────────────────────────────────────────────────

function stepMonth(which: "mine" | "others", by: number): void {
  const f = which === "mine" ? mineFilters.value : othersFilters.value;
  const [y, m] = (f.month || thisMonth()).split("-").map(Number);
  const d = new Date(y, m - 1 + by, 1);
  f.month = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
  which === "mine" ? void loadMyLeaves(1) : void loadOthers(1);
}

function monthLabel(month: string): string {
  if (!month) return "All time";
  const [y, m] = month.split("-").map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString("en-KE", { month: "short", year: "numeric" });
}

// ── Formatting ────────────────────────────────────────────────────────────

function statusBadge(status: string): string {
  return { pending: "lv-pill-amber", approved: "lv-pill-green", rejected: "lv-pill-red", cancelled: "lv-pill-grey" }[status] ?? "lv-pill-grey";
}

function formatDate(d: string | null): string {
  if (!d) return "—";
  try { return new Date(d).toLocaleDateString("en-KE", { day: "2-digit", month: "2-digit", year: "numeric" }); }
  catch { return d; }
}

function longDate(d: string | null): string {
  if (!d) return "—";
  try { return new Date(d).toLocaleDateString("en-KE", { day: "numeric", month: "long", year: "numeric" }); }
  catch { return d; }
}

/**
 * The dates, however they were asked for.
 *
 * A scattered request is not a range and rendering it as one silently turns
 * eight separate days into a five-week absence. The old approvals table did
 * exactly that.
 */
function dateSpan(r: LeaveRequest): string {
  if (r.scattered_dates?.length) {
    return `${r.scattered_dates.length} separate day${r.scattered_dates.length === 1 ? "" : "s"}`;
  }
  if (r.is_half_day) {
    return `${formatDate(r.from_date)} (${r.half_day_period ?? "half day"})`;
  }
  return `${formatDate(r.from_date)}  -  ${formatDate(r.to_date)}`;
}

function initials(r: LeaveRequest): string {
  const e = r.employee;
  if (!e) return "—";
  return `${e.first_name?.[0] ?? ""}${e.last_name?.[0] ?? ""}`.toUpperCase() || "—";
}

function fullName(r: LeaveRequest): string {
  const e = r.employee;
  return e ? `${e.first_name} ${e.last_name}`.trim() : "—";
}

function fileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

// ── Watchers and init ─────────────────────────────────────────────────────

watch(() => applyForm.value.leave_type_id, () => {
  applyForm.value.is_half_day = false;
  useScattered.value   = false;
  scatteredDates.value = [];
  calcResult.value     = null;
});

watch([
  () => applyForm.value.from_date,
  () => applyForm.value.to_date,
  () => applyForm.value.is_half_day,
  () => applyForm.value.half_day_period,
], () => { void previewDays(); });

/** Escape closes whichever layer is on top, innermost first. */
function onKey(e: KeyboardEvent): void {
  if (e.key !== "Escape") return;
  if (declineFor.value !== null) { declineFor.value = null; return; }
  if (openLeave.value) { closeDetail(); return; }
  if (requestOpen.value) { requestOpen.value = false; }
}

function init(): void {
  void loadMyLeaves(1);
  void loadBalance();
  void loadLeaveTypes();
  void loadOthers(1);
  void loadSummary();
}

onMounted(() => { window.addEventListener("keydown", onKey); init(); });
onBeforeUnmount(() => window.removeEventListener("keydown", onKey));
watch(currentOrgSlug, init);
</script>

<template>
  <div class="view lv">

    <!-- ── The two sides of leave ─────────────────────────────────────── -->
    <nav class="lv-toptabs" role="tablist" aria-label="Leave">
      <button
        role="tab" :aria-selected="topTab === 'mine'"
        :class="['lv-toptab', { 'is-on': topTab === 'mine' }]"
        @click="topTab = 'mine'"
      >
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
          <rect x="3" y="4" width="14" height="13" rx="2" /><path d="M3 8h14M7 2v4M13 2v4" />
        </svg>
        My Leaves
      </button>
      <button
        v-if="canSeeOthers"
        role="tab" :aria-selected="topTab === 'others'"
        :class="['lv-toptab', { 'is-on': topTab === 'others' }]"
        @click="topTab = 'others'"
      >
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
          <circle cx="7.5" cy="7" r="3" /><path d="M2 17c0-3 2.5-5 5.5-5s5.5 2 5.5 5M14 8.5a2.5 2.5 0 100-5M15 17c0-2-.6-3.6-1.6-4.7" />
        </svg>
        Employee Leave Management
      </button>
    </nav>

    <!-- ══ MY LEAVES ═══════════════════════════════════════════════════ -->
    <section v-show="topTab === 'mine'">
      <header class="lv-head">
        <div>
          <h2 class="lv-title">My Leaves</h2>
          <p class="lv-lead">Keep your work-life balance in check — track your leave days and upcoming breaks.</p>
        </div>
        <button class="lv-btn-primary" @click="openRequest">Request Leave</button>
      </header>

      <p v-if="balError" class="lv-note">{{ balError }}</p>

      <!-- Entitlement cards. Named types as the organisation defines them,
           not a hardcoded Annual and Sick. -->
      <div v-if="balances.length" class="lv-cards">
        <article v-for="b in balanceCards" :key="b.leave_type" class="lv-card">
          <h3 class="lv-card-title">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
              <circle cx="10" cy="10" r="7.5" /><path d="M10 6v4.5l3 1.8" />
            </svg>
            {{ b.leave_type }}
          </h3>
          <dl class="lv-card-figs">
            <!--
              Available, not entitled minus used. Requests already submitted
              but not yet decided are spoken for, and a balance that ignores
              them invites somebody to book days they have already asked for.
            -->
            <div><dt>Total</dt><dd>{{ b.entitled_days }}</dd></div>
            <div><dt>Balance</dt><dd>{{ b.available_days }}</dd></div>
            <div><dt>Pending</dt><dd>{{ b.pending_days }}</dd></div>
          </dl>
        </article>

        <article class="lv-card">
          <h3 class="lv-card-title">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
              <circle cx="10" cy="10" r="7.5" /><path d="M10 6v4.5l3 1.8" />
            </svg>
            Away Summary
          </h3>
          <dl class="lv-card-figs">
            <div><dt>Entitled</dt><dd>{{ awaySummary.total }}</dd></div>
            <div><dt>Taken</dt><dd>{{ awaySummary.used }}</dd></div>
            <div><dt>Left</dt><dd>{{ awaySummary.remaining }}</dd></div>
          </dl>
        </article>
      </div>

      <div class="lv-panel">
        <div class="lv-toolbar">
          <label class="lv-search">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
              <circle cx="9" cy="9" r="5.5" /><path d="M13 13l4 4" />
            </svg>
            <input
              v-model="mineFilters.search" type="search" placeholder="Search"
              aria-label="Search my leave requests"
              @keyup.enter="loadMyLeaves(1)"
            />
          </label>

          <div class="lv-tool-right">
            <div class="lv-month">
              <button aria-label="Previous month" @click="stepMonth('mine', -1)">‹</button>
              <span>{{ monthLabel(mineFilters.month) }}</span>
              <button aria-label="Next month" @click="stepMonth('mine', 1)">›</button>
            </div>
            <button
              class="lv-btn-ghost" :aria-expanded="filtersOpen"
              @click="filtersOpen = !filtersOpen"
            >Filter</button>
          </div>
        </div>

        <div v-if="filtersOpen" class="lv-filters">
          <label>
            <span>Status</span>
            <select v-model="mineFilters.status" @change="loadMyLeaves(1)">
              <option value="">Any</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </label>
          <button class="lv-btn-ghost" @click="mineFilters.month = ''; loadMyLeaves(1)">Clear month</button>
        </div>

        <p v-if="myLoading" class="lv-note">Loading…</p>
        <p v-if="myError" class="lv-error">{{ myError }}</p>

        <div class="lv-table-wrap">
          <table class="lv-table">
            <thead>
              <tr>
                <th>Ref ID</th><th>Leave Type</th><th>Start Date &nbsp;-&nbsp; End Date</th>
                <th>Days</th><th>Status</th><th class="lv-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in myLeaves" :key="r.id">
                <td class="lv-ref">{{ r.reference }}</td>
                <td>{{ r.leave_type?.name ?? "—" }}</td>
                <td>
                  {{ dateSpan(r) }}
                  <svg v-if="r.attachments_count" class="lv-clip" viewBox="0 0 20 20" fill="none"
                       stroke="currentColor" stroke-width="1.6" aria-label="Has attachments">
                    <path d="M13 7l-5 5a2 2 0 002.8 2.8l5.2-5.2a3.5 3.5 0 00-5-5L5.5 10" />
                  </svg>
                </td>
                <td>{{ r.days_requested }} {{ Number(r.days_requested) === 1 ? "day" : "days" }}</td>
                <td><span :class="['lv-pill', statusBadge(r.status)]">{{ r.status }}</span></td>
                <td class="lv-right"><button class="lv-link" @click="openDetail(r)">View</button></td>
              </tr>
              <tr v-if="!myLoading && !myLeaves.length">
                <td colspan="6" class="lv-empty">
                  No leave requests{{ mineFilters.month ? ` in ${monthLabel(mineFilters.month)}` : "" }}.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="myTotal > 10" class="lv-pager">
          <span>{{ myLeaves.length }} of {{ myTotal }}</span>
          <div>
            <button class="lv-btn-ghost" :disabled="myPage <= 1" @click="loadMyLeaves(myPage - 1)">Previous</button>
            <button class="lv-btn-ghost" :disabled="myPage * 10 >= myTotal" @click="loadMyLeaves(myPage + 1)">Next</button>
          </div>
        </div>
      </div>
    </section>

    <!-- ══ EMPLOYEE / TEAM ═════════════════════════════════════════════ -->
    <section v-if="canSeeOthers" v-show="topTab === 'others'">
      <header class="lv-head">
        <div>
          <h2 class="lv-title">{{ othersTitle }}</h2>
          <p class="lv-lead">{{ othersLead }}</p>
        </div>
      </header>

      <!-- The figures, counted over exactly the rows below them. -->
      <div v-if="summary" class="lv-kpis">
        <div class="lv-kpi"><span>Pending Requests</span><strong>{{ summary.pending_requests }}</strong></div>
        <div class="lv-kpi"><span>On Leave Today</span><strong>{{ summary.on_leave_today }}</strong></div>
        <div class="lv-kpi"><span>Upcoming this week</span><strong>{{ summary.upcoming_this_week }}</strong></div>
        <div class="lv-kpi"><span>Rejected this month</span><strong>{{ summary.rejected_this_month }}</strong></div>
        <div class="lv-kpi">
          <span>Availability</span>
          <strong v-if="summary.availability.percentage !== null">
            {{ summary.availability.percentage }}%
            <em>{{ summary.availability.available }} of {{ summary.availability.headcount }}</em>
          </strong>
          <!-- No headcount is not nought per cent and not a hundred. -->
          <strong v-else class="lv-kpi-none">No one to count</strong>
        </div>
      </div>

      <div class="lv-panel">
        <div class="lv-toolbar">
          <label class="lv-search">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
              <circle cx="9" cy="9" r="5.5" /><path d="M13 13l4 4" />
            </svg>
            <input
              v-model="othersFilters.search" type="search" placeholder="Search by name, email, id"
              aria-label="Search leave requests"
              @keyup.enter="loadOthers(1)"
            />
          </label>

          <div class="lv-tool-right">
            <div class="lv-month">
              <button aria-label="Previous month" @click="stepMonth('others', -1)">‹</button>
              <span>{{ monthLabel(othersFilters.month) }}</span>
              <button aria-label="Next month" @click="stepMonth('others', 1)">›</button>
            </div>
            <select v-model="othersFilters.status" class="lv-select" aria-label="Status" @change="loadOthers(1)">
              <option value="">All statuses</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </select>
            <button class="lv-btn-ghost" @click="othersFilters.month = ''; loadOthers(1)">Clear month</button>
          </div>
        </div>

        <p v-if="othersLoading" class="lv-note">Loading…</p>
        <p v-if="othersError" class="lv-error">{{ othersError }}</p>

        <div class="lv-table-wrap">
          <table class="lv-table">
            <thead>
              <tr>
                <th>Ref ID</th><th>Employee Name</th><th>Leave Type</th>
                <th>Start Date &nbsp;-&nbsp; End Date</th><th>Days</th><th>Status</th>
                <th class="lv-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in others" :key="r.id">
                <td class="lv-ref">{{ r.reference }}</td>
                <td>
                  <span class="lv-who"><span class="lv-avatar">{{ initials(r) }}</span>{{ fullName(r) }}</span>
                </td>
                <td>{{ r.leave_type?.name ?? "—" }}</td>
                <td>
                  {{ dateSpan(r) }}
                  <svg v-if="r.attachments_count" class="lv-clip" viewBox="0 0 20 20" fill="none"
                       stroke="currentColor" stroke-width="1.6" aria-label="Has attachments">
                    <path d="M13 7l-5 5a2 2 0 002.8 2.8l5.2-5.2a3.5 3.5 0 00-5-5L5.5 10" />
                  </svg>
                </td>
                <td>{{ r.days_requested }} {{ Number(r.days_requested) === 1 ? "day" : "days" }}</td>
                <td><span :class="['lv-pill', statusBadge(r.status)]">{{ r.status }}</span></td>
                <td class="lv-right"><button class="lv-link" @click="openDetail(r)">View</button></td>
              </tr>
              <tr v-if="!othersLoading && !others.length">
                <td colspan="7" class="lv-empty">
                  Nothing here{{ othersFilters.month ? ` for ${monthLabel(othersFilters.month)}` : "" }}.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="othersPages > 1" class="lv-pager">
          <span>Page {{ othersPage }} of {{ othersPages }}</span>
          <div>
            <button class="lv-btn-ghost" :disabled="othersPage <= 1" @click="loadOthers(othersPage - 1)">Previous</button>
            <button class="lv-btn-ghost" :disabled="othersPage >= othersPages" @click="loadOthers(othersPage + 1)">Next</button>
          </div>
        </div>
      </div>
    </section>

    <!-- ══ REQUEST LEAVE DRAWER ════════════════════════════════════════ -->
    <div v-if="requestOpen" class="lv-scrim" @click.self="requestOpen = false">
      <aside class="lv-drawer" role="dialog" aria-label="Request leave">
        <div class="lv-drawer-head">
          <h3>Request Leave</h3>
          <button class="lv-close" aria-label="Close" @click="requestOpen = false">×</button>
        </div>

        <p v-if="applyError" class="lv-error">{{ applyError }}</p>
        <p v-if="applySuccess" class="lv-ok">{{ applySuccess }}</p>

        <form class="lv-form" @submit.prevent="applyLeave">
          <label class="lv-field">
            <span>Leave Type</span>
            <select v-model="applyForm.leave_type_id" required>
              <option value="">Pick a leave type</option>
              <option v-for="t in leaveTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
            </select>
          </label>

          <!--
            The design offers a "Date Type" dropdown and nothing behind it. The
            three shapes leave actually comes in are kept: a range, a single
            half day, and a set of separate days. Dropping scattered dates to
            match the mock would lose the only way to book eight Fridays.
          -->
          <fieldset v-if="selectedType" class="lv-modes">
            <legend>Date Type</legend>
            <label><input v-model="applyForm.is_half_day" type="checkbox" :disabled="!selectedType.allow_half_day" /> Half day</label>
            <label v-if="selectedType.allow_scattered">
              <input v-model="useScattered" type="checkbox" :disabled="applyForm.is_half_day" /> Separate days
            </label>
          </fieldset>

          <template v-if="applyForm.is_half_day">
            <label class="lv-field">
              <span>Date</span>
              <input v-model="applyForm.from_date" type="date" required />
            </label>
            <label class="lv-field">
              <span>Which half</span>
              <select v-model="applyForm.half_day_period">
                <option value="morning">Morning</option>
                <option value="afternoon">Afternoon</option>
              </select>
            </label>
          </template>

          <template v-else-if="isScatteredMode">
            <label class="lv-field">
              <span>Add a date</span>
              <span class="lv-inline">
                <input v-model="newScatterDate" type="date" />
                <button type="button" class="lv-btn-ghost" @click="addScatterDate">Add</button>
              </span>
            </label>
            <ul v-if="scatteredDates.length" class="lv-chips">
              <li v-for="d in scatteredDates" :key="d">
                {{ formatDate(d) }}
                <button type="button" aria-label="Remove" @click="removeScatterDate(d)">×</button>
              </li>
            </ul>
          </template>

          <template v-else>
            <label class="lv-field">
              <span>Start date</span>
              <input v-model="applyForm.from_date" type="date" required />
            </label>
            <label class="lv-field">
              <span>End date</span>
              <input v-model="applyForm.to_date" type="date" required />
            </label>
          </template>

          <p v-if="calcLoading" class="lv-note">Counting…</p>
          <p v-else-if="calcResult" class="lv-count">
            <strong>{{ calcResult.days }}</strong>
            {{ calcResult.days === 1 ? "day" : "days" }} will be deducted
            <em>({{ calcResult.counting_method === "business_days" ? "working days" : "calendar days" }})</em>
          </p>

          <label class="lv-field">
            <span>Leave Reason</span>
            <textarea v-model="applyForm.reason" rows="4" placeholder="Type here…"></textarea>
          </label>

          <p class="lv-hint">
            Documents can be attached once the request is submitted — open it from the list and use Attachments.
          </p>

          <button type="submit" class="lv-btn-primary lv-wide" :disabled="applying">
            {{ applying ? "Sending…" : "Send Request" }}
          </button>
        </form>
      </aside>
    </div>

    <!-- ══ DETAIL / APPROVAL DRAWER ════════════════════════════════════ -->
    <div v-if="openLeave" class="lv-scrim" @click.self="closeDetail">
      <aside class="lv-drawer lv-drawer-wide" role="dialog" aria-label="Leave request">
        <div class="lv-drawer-head">
          <div class="lv-drawer-id">
            <h3>Leave Ref: {{ openLeave.reference }}</h3>
            <span :class="['lv-pill', statusBadge(openLeave.status)]">{{ openLeave.status }}</span>
          </div>
          <div class="lv-drawer-acts">
            <template v-if="canAction">
              <button class="lv-btn-danger" :disabled="detailBusy" @click="declineFor = openLeave.id">Decline</button>
              <button class="lv-btn-primary" :disabled="detailBusy" @click="approveLeave(openLeave.id)">Approve</button>
            </template>
            <button
              v-if="openIsMine && openLeave.status === 'pending'"
              class="lv-btn-ghost" @click="cancelLeave(openLeave.id)"
            >Withdraw</button>
            <button class="lv-close" aria-label="Close" @click="closeDetail">×</button>
          </div>
        </div>

        <p v-if="detailError" class="lv-error">{{ detailError }}</p>

        <dl class="lv-facts">
          <div><dt>Leave Type</dt><dd class="lv-accent">{{ openLeave.leave_type?.name ?? "—" }}</dd></div>
          <div><dt>Leave Date</dt><dd>{{ dateSpan(openLeave) }}</dd></div>
          <div><dt>Days</dt><dd>{{ openLeave.days_requested }}</dd></div>
          <div><dt>Requested on</dt><dd>{{ longDate(openLeave.created_at) }}</dd></div>
          <div v-if="openLeave.actioned_at">
            <dt>{{ openLeave.status === "rejected" ? "Date declined" : "Date approved" }}</dt>
            <dd>{{ longDate(openLeave.actioned_at) }}</dd>
          </div>
          <div v-if="openLeave.approved_by">
            <dt>{{ openLeave.status === "rejected" ? "Declined by" : "Approved by" }}</dt>
            <dd>{{ openLeave.approved_by.full_name }}</dd>
          </div>
          <div v-if="!openIsMine"><dt>Employee</dt><dd>{{ fullName(openLeave) }}</dd></div>
        </dl>

        <nav class="lv-subtabs" role="tablist">
          <button role="tab" :aria-selected="detailTab === 'description'"
                  :class="['lv-subtab', { 'is-on': detailTab === 'description' }]"
                  @click="detailTab = 'description'">Description</button>
          <button role="tab" :aria-selected="detailTab === 'messages'"
                  :class="['lv-subtab', { 'is-on': detailTab === 'messages' }]"
                  @click="detailTab = 'messages'">
            Messages<span v-if="messages.length" class="lv-count-chip">{{ messages.length }}</span>
          </button>
        </nav>

        <!-- ── Description ── -->
        <div v-if="detailTab === 'description'" class="lv-pane">
          <h4>Reason for leave</h4>
          <p class="lv-reason">{{ openLeave.reason || "No reason was given." }}</p>

          <p v-if="openLeave.status === 'rejected' && openLeave.rejection_reason" class="lv-declined">
            <strong>Declined:</strong> {{ openLeave.rejection_reason }}
          </p>

          <h4>Attachments</h4>
          <ul v-if="attachments.length" class="lv-files">
            <li v-for="a in attachments" :key="a.id">
              <button class="lv-file" @click="downloadAttachment(a)">
                <span class="lv-file-ext">{{ (a.original_name.split(".").pop() ?? "").toUpperCase().slice(0, 4) }}</span>
                <span class="lv-file-meta">
                  <strong>{{ a.original_name }}</strong>
                  <em>{{ fileSize(a.size_bytes) }}<template v-if="a.uploaded_by"> · {{ a.uploaded_by }}</template></em>
                </span>
              </button>
              <button
                v-if="openIsMine && openLeave.status === 'pending'"
                class="lv-link lv-link-danger" @click="removeAttachment(a.id)"
              >Remove</button>
            </li>
          </ul>
          <p v-else class="lv-note">Nothing attached.</p>

          <!--
            Only while the decision is open. A file added afterwards would read
            as evidence that was weighed, and nothing could tell them apart.
          -->
          <label v-if="openLeave.status === 'pending'" class="lv-attach">
            <input type="file" hidden :disabled="uploading" @change="uploadAttachment" />
            <span>{{ uploading ? "Attaching…" : "Add attachment" }}</span>
          </label>
          <p v-else class="lv-note">This request has been decided, so nothing further can be attached.</p>

          <LeaveHandoverPanel
            v-if="handoverFor && handoverFor.id === openLeave.id"
            :leave-id="openLeave.id"
            :label="handoverFor.label"
          />
          <button v-else class="lv-link" @click="handoverFor = { id: openLeave.id, label: fullName(openLeave) }">
            Arrange cover for this leave
          </button>
        </div>

        <!-- ── Messages ── -->
        <div v-else class="lv-pane">
          <ol v-if="messages.length" class="lv-thread">
            <li v-for="m in messages" :key="m.id" :class="{ 'is-private': !m.visible_to_employee }">
              <header>
                <strong>{{ m.author ?? "Someone" }}</strong>
                <time>{{ longDate(m.created_at) }}</time>
                <span v-if="!m.visible_to_employee" class="lv-private">Not shown to the employee</span>
              </header>
              <p>{{ m.body }}</p>
            </li>
          </ol>
          <p v-else class="lv-note">Nothing said yet.</p>

          <form class="lv-reply" @submit.prevent="sendMessage">
            <textarea
              v-model="newMessage" rows="3" placeholder="Write a message…"
              aria-label="Message"
            ></textarea>
            <div class="lv-reply-foot">
              <!-- Only the approving side can write out of the applicant's
                   sight; theirs would otherwise have no audience at all. -->
              <label v-if="!openIsMine" class="lv-priv-toggle">
                <input v-model="messagePrivate" type="checkbox" />
                Keep this from the employee
              </label>
              <span v-else></span>
              <button type="submit" class="lv-btn-primary" :disabled="detailBusy || !newMessage.trim()">Send</button>
            </div>
          </form>
        </div>
      </aside>
    </div>

    <!-- ══ DECLINE — a reason is required ══════════════════════════════ -->
    <div v-if="declineFor !== null" class="lv-scrim lv-scrim-centre" @click.self="declineFor = null">
      <div class="lv-modal" role="dialog" aria-label="Decline leave">
        <h3>Decline this request</h3>
        <p class="lv-note">
          The reason is recorded against the request and shown to the employee. It cannot be edited afterwards.
        </p>
        <textarea v-model="declineReason" rows="4" placeholder="Why is it being declined?" aria-label="Reason"></textarea>
        <div class="lv-modal-acts">
          <button class="lv-btn-ghost" @click="declineFor = null">Cancel</button>
          <button class="lv-btn-danger" :disabled="!declineReason.trim() || detailBusy" @click="declineLeave">
            Decline
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/*
 * Built on the Konvergenz tokens — see docs/design-system.md. No literal
 * colours; every surface, border and status tone comes from a variable so the
 * page follows the theme rather than pinning itself to one.
 */
@reference "../theme.css";

.lv { display: flex; flex-direction: column; gap: var(--space-5); }

/* ── The two sides ─────────────────────────────────────────────────────── */
.lv-toptabs {
  @apply flex items-end;
  gap: var(--space-6);
  border-bottom: 1px solid var(--color-border);
}

.lv-toptab {
  @apply flex cursor-pointer items-center gap-2 border-none bg-transparent font-medium;
  font-family: inherit;
  font-size: var(--text-sm-size);
  color: var(--color-text-muted);
  padding: var(--space-3) 0;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  transition: color var(--transition-fast), border-color var(--transition-fast);
}

.lv-toptab svg { @apply size-4 shrink-0; }
.lv-toptab:hover { color: var(--color-text); }
.lv-toptab.is-on { color: var(--color-brand-base); border-bottom-color: var(--color-brand-base); }

/* ── Page head ─────────────────────────────────────────────────────────── */
.lv-head { @apply flex flex-wrap items-start justify-between gap-4; }
.lv-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.lv-lead  { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

/* ── Entitlement cards ─────────────────────────────────────────────────── */
.lv-cards {
  display: grid;
  /* auto-fit so two types or five both fill the row instead of leaving a gap. */
  grid-template-columns: repeat(auto-fit, minmax(17rem, 1fr));
  gap: var(--space-4);
}

.lv-card {
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  background: var(--color-surface-raised);
}

.lv-card-title {
  @apply flex items-center gap-2 font-semibold;
  font-size: var(--text-sm-size);
  color: var(--color-text);
  margin: 0 0 var(--space-3);
}
.lv-card-title svg { @apply size-4; color: var(--color-brand-base); }

.lv-card-figs { @apply grid grid-cols-3; margin: 0; }
.lv-card-figs > div { padding-right: var(--space-3); }
.lv-card-figs > div + div { border-left: 1px solid var(--color-border); padding-left: var(--space-3); }
.lv-card-figs dt { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; }
.lv-card-figs dd { font-size: var(--text-2xl-size); font-weight: 600; color: var(--color-text); margin: var(--space-1) 0 0; }

/* ── KPI strip ─────────────────────────────────────────────────────────── */
.lv-kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  background: var(--color-surface-raised);
}

.lv-kpi { @apply flex flex-col; padding: 0 var(--space-4); }
.lv-kpi + .lv-kpi { border-left: 1px solid var(--color-border); }
.lv-kpi span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.lv-kpi strong { font-size: var(--text-2xl-size); font-weight: 600; color: var(--color-text); margin-top: var(--space-1); }
.lv-kpi em { @apply block not-italic; font-size: var(--text-xs-size); font-weight: 400; color: var(--color-text-muted); }
/* An absent figure must not be mistaken for a large one. */
.lv-kpi-none { font-size: var(--text-sm-size) !important; color: var(--color-text-light) !important; }

/* ── Panel, toolbar, filters ───────────────────────────────────────────── */
.lv-panel {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  background: var(--color-surface-raised);
  overflow: hidden;
}

.lv-toolbar {
  @apply flex flex-wrap items-center justify-between gap-3;
  padding: var(--space-4);
  border-bottom: 1px solid var(--color-border);
}

.lv-search {
  @apply flex flex-1 items-center gap-2;
  min-width: 14rem;
  max-width: 22rem;
  padding: 0 var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface);
}
.lv-search svg { @apply size-4 shrink-0; color: var(--color-text-light); }
.lv-search input {
  @apply w-full border-none bg-transparent;
  font-family: inherit;
  font-size: var(--text-sm-size);
  color: var(--color-text);
  padding: 0.5rem 0;
}
.lv-search input:focus { @apply outline-none; }

.lv-tool-right { @apply flex flex-wrap items-center gap-2; }

.lv-month {
  @apply flex items-center gap-1;
  padding: 0.25rem var(--space-2);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  font-size: var(--text-sm-size);
  color: var(--color-text);
}
.lv-month span { min-width: 6.5rem; text-align: center; }
.lv-month button {
  @apply cursor-pointer border-none bg-transparent;
  font-size: var(--text-base-size);
  color: var(--color-text-muted);
  padding: 0 var(--space-2);
  border-radius: var(--radius-sm);
}
.lv-month button:hover { color: var(--color-brand-base); }

.lv-select, .lv-filters select {
  font-family: inherit;
  font-size: var(--text-sm-size);
  padding: 0.4375rem var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
}

.lv-filters {
  @apply flex flex-wrap items-end gap-3;
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-sunken);
}
.lv-filters label { @apply flex flex-col gap-1; font-size: var(--text-xs-size); color: var(--color-text-muted); }

/* ── Table ─────────────────────────────────────────────────────────────── */
.lv-table-wrap { overflow-x: auto; }
.lv-table { @apply w-full; border-collapse: collapse; }

.lv-table th {
  @apply text-left font-semibold whitespace-nowrap;
  font-size: var(--text-xs-size);
  color: var(--color-text-muted);
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-sunken);
}

.lv-table td {
  font-size: var(--text-sm-size);
  color: var(--color-text);
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
  vertical-align: middle;
}

.lv-table tbody tr:last-child td { border-bottom: none; }
.lv-table tbody tr:hover { background: var(--color-surface-sunken); }

.lv-right { text-align: right; }
.lv-ref { font-variant-numeric: tabular-nums; color: var(--color-text-muted); }
.lv-empty { text-align: center; color: var(--color-text-light); padding: var(--space-8) var(--space-4) !important; }

.lv-who { @apply flex items-center gap-2; }
.lv-avatar {
  @apply inline-flex size-7 shrink-0 items-center justify-center rounded-full font-semibold;
  font-size: var(--text-xs-size);
  background: var(--color-brand-light);
  color: var(--color-brand-base);
}

.lv-clip { @apply inline-block size-4 align-text-bottom; color: var(--color-text-light); margin-left: var(--space-1); }

.lv-pill {
  @apply inline-block rounded-full font-medium capitalize;
  font-size: var(--text-xs-size);
  padding: 2px var(--space-3);
  border: 1px solid transparent;
}
.lv-pill-amber { background: var(--color-warning-light); color: var(--color-warning); border-color: var(--color-warning); }
.lv-pill-green { background: var(--color-success-light); color: var(--color-success); border-color: var(--color-success); }
.lv-pill-red   { background: var(--color-error-light);   color: var(--color-error);   border-color: var(--color-error); }
.lv-pill-grey  { background: var(--color-surface-sunken); color: var(--color-text-muted); border-color: var(--color-border); }

.lv-pager {
  @apply flex flex-wrap items-center justify-between gap-3;
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--color-border);
  font-size: var(--text-xs-size);
  color: var(--color-text-muted);
}
.lv-pager > div { @apply flex gap-2; }

/* ── Buttons and links ─────────────────────────────────────────────────── */
.lv-btn-primary, .lv-btn-ghost, .lv-btn-danger {
  @apply cursor-pointer font-medium;
  font-family: inherit;
  font-size: var(--text-sm-size);
  padding: 0.5rem var(--space-4);
  border-radius: var(--radius-md);
  transition: background var(--transition-fast), border-color var(--transition-fast);
}

.lv-btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.lv-btn-primary:hover:not(:disabled) { background: var(--color-brand-hover); }

.lv-btn-ghost {
  background: var(--color-surface);
  color: var(--color-text);
  border: 1px solid var(--color-border-strong);
}
.lv-btn-ghost:hover:not(:disabled) { background: var(--color-surface-sunken); }

.lv-btn-danger { @apply border-none; background: var(--color-error); color: var(--color-on-brand); }
.lv-btn-danger:hover:not(:disabled) { filter: brightness(0.92); }

.lv-btn-primary:disabled, .lv-btn-ghost:disabled, .lv-btn-danger:disabled { @apply cursor-not-allowed opacity-50; }
.lv-wide { @apply w-full; }

.lv-link {
  @apply cursor-pointer border-none bg-transparent p-0 font-medium;
  font-family: inherit;
  font-size: var(--text-sm-size);
  color: var(--color-brand-base);
}
.lv-link:hover { @apply underline; }
.lv-link-danger { color: var(--color-error); }

/* ── Notices ───────────────────────────────────────────────────────────── */
.lv-note  { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; padding: var(--space-3) var(--space-4); }
.lv-error {
  font-size: var(--text-xs-size); color: var(--color-error);
  background: var(--color-error-light);
  border-left: 3px solid var(--color-error);
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  padding: var(--space-3); margin: 0 0 var(--space-3);
}
.lv-ok {
  font-size: var(--text-xs-size); color: var(--color-success);
  background: var(--color-success-light);
  border-radius: var(--radius-sm);
  padding: var(--space-3); margin: 0 0 var(--space-3);
}

/* ── Drawers ───────────────────────────────────────────────────────────── */
.lv-scrim { @apply fixed inset-0 flex justify-end z-50; background: rgb(0 0 0 / 0.28); }
.lv-scrim-centre { @apply items-center justify-center; }

.lv-drawer {
  @apply flex flex-col overflow-y-auto;
  width: min(28rem, 100%);
  gap: var(--space-4);
  padding: var(--space-5);
  background: var(--color-surface-raised);
  box-shadow: var(--shadow-lg);
  animation: lv-in 180ms ease;
}
.lv-drawer-wide { width: min(44rem, 100%); }

@keyframes lv-in { from { transform: translateX(1.5rem); opacity: 0; } to { transform: none; opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .lv-drawer { animation: none; } }

.lv-drawer-head { @apply flex items-start justify-between gap-3; }
.lv-drawer-head h3 { font-size: var(--text-base-size); font-weight: 600; color: var(--color-text); margin: 0; }
.lv-drawer-id { @apply flex flex-wrap items-center gap-3; }
.lv-drawer-acts { @apply flex shrink-0 items-center gap-2; }

.lv-close {
  @apply cursor-pointer border-none bg-transparent;
  font-size: var(--text-xl-size); line-height: 1;
  color: var(--color-text-muted);
  padding: 0 var(--space-2);
  border-radius: var(--radius-sm);
}
.lv-close:hover { color: var(--color-text); }

/* ── Facts grid ────────────────────────────────────────────────────────── */
.lv-facts { @apply grid gap-x-4 gap-y-3; grid-template-columns: auto 1fr; margin: 0; }
.lv-facts > div { @apply contents; }
.lv-facts dt { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; }
.lv-facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }
.lv-accent { color: var(--color-brand-base); font-weight: 500; }

/* ── Sub-tabs and panes ────────────────────────────────────────────────── */
.lv-subtabs { @apply flex gap-5; border-bottom: 1px solid var(--color-border); }
.lv-subtab {
  @apply flex cursor-pointer items-center gap-2 border-none bg-transparent font-medium;
  font-family: inherit;
  font-size: var(--text-sm-size);
  color: var(--color-text-muted);
  padding: var(--space-2) 0;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
}
.lv-subtab.is-on { color: var(--color-brand-base); border-bottom-color: var(--color-brand-base); }

.lv-count-chip {
  @apply rounded-full;
  font-size: 0.6875rem;
  padding: 1px var(--space-2);
  background: var(--color-brand-light);
  color: var(--color-brand-base);
}

.lv-pane { @apply flex flex-col; gap: var(--space-3); }
.lv-pane h4 { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: var(--space-2) 0 0; }
.lv-reason { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; white-space: pre-wrap; }

.lv-declined {
  font-size: var(--text-sm-size); color: var(--color-error);
  background: var(--color-error-light);
  border-radius: var(--radius-sm);
  padding: var(--space-3); margin: 0;
}

/* ── Attachments ───────────────────────────────────────────────────────── */
.lv-files { @apply flex list-none flex-col; gap: var(--space-2); margin: 0; padding: 0; }
.lv-files li { @apply flex items-center gap-3; }

.lv-file {
  @apply flex flex-1 cursor-pointer items-center gap-3 text-left;
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
}
.lv-file:hover { border-color: var(--color-brand-base); }

.lv-file-ext {
  @apply inline-flex size-10 shrink-0 items-center justify-center rounded font-semibold;
  font-size: 0.625rem;
  background: var(--color-error-light);
  color: var(--color-error);
}
.lv-file-meta { @apply flex flex-col; }
.lv-file-meta strong { font-size: var(--text-sm-size); font-weight: 500; color: var(--color-text); }
.lv-file-meta em { @apply not-italic; font-size: var(--text-xs-size); color: var(--color-text-muted); }

.lv-attach {
  @apply inline-flex cursor-pointer items-center gap-2 self-start font-medium;
  font-size: var(--text-sm-size);
  color: var(--color-brand-base);
}
.lv-attach:hover { @apply underline; }

/* ── Thread ────────────────────────────────────────────────────────────── */
.lv-thread { @apply flex list-none flex-col; gap: var(--space-3); margin: 0; padding: 0; }
.lv-thread li {
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
}
/* A private note must look different, or somebody writes one thinking it is. */
.lv-thread li.is-private { background: var(--color-warning-light); border-color: var(--color-warning); }

.lv-thread header { @apply flex flex-wrap items-baseline gap-2; margin-bottom: var(--space-2); }
.lv-thread header strong { font-size: var(--text-sm-size); color: var(--color-text); }
.lv-thread time { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.lv-private {
  @apply rounded-full font-medium;
  font-size: 0.625rem;
  padding: 1px var(--space-2);
  background: var(--color-warning);
  color: var(--color-on-brand);
}
.lv-thread p { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; white-space: pre-wrap; }

.lv-reply { @apply flex flex-col; gap: var(--space-2); }
.lv-reply-foot { @apply flex items-center justify-between gap-3; }
.lv-priv-toggle { @apply flex cursor-pointer items-center gap-2; font-size: var(--text-xs-size); color: var(--color-text-muted); }

/* ── Form ──────────────────────────────────────────────────────────────── */
.lv-form { @apply flex flex-col; gap: var(--space-4); }
.lv-field { @apply flex flex-col; gap: var(--space-2); }
.lv-field > span { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text); }
.lv-inline { @apply flex items-center gap-2; }

.lv-field input, .lv-field select, .lv-field textarea,
.lv-reply textarea, .lv-modal textarea {
  @apply w-full;
  font-family: inherit;
  font-size: var(--text-sm-size);
  padding: 0.5625rem var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
}
.lv-field input:focus, .lv-field select:focus, .lv-field textarea:focus,
.lv-reply textarea:focus, .lv-modal textarea:focus {
  @apply outline-none;
  border-color: var(--color-brand-base);
  box-shadow: 0 0 0 3px var(--color-brand-subtle);
}

.lv-modes {
  @apply flex flex-wrap items-center gap-4;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-3);
  margin: 0;
}
.lv-modes legend { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text); padding: 0 var(--space-2); }
.lv-modes label { @apply flex cursor-pointer items-center gap-2; font-size: var(--text-sm-size); color: var(--color-text); }

.lv-chips { @apply flex list-none flex-wrap gap-2; margin: 0; padding: 0; }
.lv-chips li {
  @apply inline-flex items-center gap-2 rounded-full;
  font-size: var(--text-xs-size);
  padding: 2px var(--space-3);
  background: var(--color-brand-light);
  color: var(--color-brand-base);
}
.lv-chips button { @apply cursor-pointer border-none bg-transparent p-0; color: inherit; }

.lv-count {
  font-size: var(--text-sm-size); color: var(--color-text); margin: 0;
  background: var(--color-surface-sunken);
  border-radius: var(--radius-md);
  padding: var(--space-3);
}
.lv-count em { @apply not-italic; color: var(--color-text-muted); font-size: var(--text-xs-size); }

.lv-hint {
  font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0;
  background: var(--color-surface-sunken);
  border-left: 3px solid var(--color-info);
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  padding: var(--space-3);
}

/* ── Decline modal ─────────────────────────────────────────────────────── */
.lv-modal {
  @apply flex flex-col;
  width: min(30rem, calc(100% - 2rem));
  gap: var(--space-3);
  padding: var(--space-5);
  border-radius: var(--radius-xl);
  background: var(--color-surface-raised);
  box-shadow: var(--shadow-lg);
}
.lv-modal h3 { font-size: var(--text-base-size); font-weight: 600; color: var(--color-text); margin: 0; }
.lv-modal .lv-note { padding: 0; }
.lv-modal-acts { @apply flex justify-end gap-2; }

@media (max-width: 640px) {
  .lv-toptabs { gap: var(--space-4); }
  .lv-toptab { font-size: var(--text-xs-size); }
  .lv-kpi + .lv-kpi { border-left: none; border-top: 1px solid var(--color-border); padding-top: var(--space-3); margin-top: var(--space-3); }
  .lv-drawer, .lv-drawer-wide { width: 100%; padding: var(--space-4); }
  .lv-facts { grid-template-columns: 1fr; gap: var(--space-1); }
  .lv-facts > div { @apply block; margin-bottom: var(--space-2); }
}
</style>
