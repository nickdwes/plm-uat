<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import type { Paginator, LeaveRequest, LeaveBalance, LeaveBalanceResponse } from "../lib/types";

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

type Tab = "my-leaves" | "balance" | "apply" | "pending";
const tab = ref<Tab>("my-leaves");

// ── My leave requests ─────────────────────────────────────────────────────
const myLeaves  = ref<LeaveRequest[]>([]);
const myTotal   = ref(0);
const myPage    = ref(1);
const myLoading = ref(false);
const myError   = ref("");

// ── Leave balances ────────────────────────────────────────────────────────
const balances    = ref<LeaveBalance[]>([]);
const balYear     = ref(new Date().getFullYear());
const balLoading  = ref(false);
const balError    = ref("");

// ── Leave types (for apply form) ──────────────────────────────────────────
const leaveTypes    = ref<LeaveTypeFull[]>([]);
const typesLoading  = ref(false);

// ── Apply form ────────────────────────────────────────────────────────────
const applyForm = ref({
  leave_type_id: "" as string | number,
  from_date:     "",
  to_date:       "",
  reason:        "",
  is_half_day:   false,
  half_day_period: "morning" as "morning" | "afternoon",
});

// Scattered dates (separate from the main form for easier manipulation)
const scatteredDates  = ref<string[]>([]); // array of YYYY-MM-DD
const newScatterDate  = ref("");

const applying   = ref(false);
const applyError = ref("");
const applySuccess = ref("");

// Day preview
const calcResult  = ref<CalcResult | null>(null);
const calcLoading = ref(false);

// ── Pending approvals ────────────────────────────────────────────────────
const pending        = ref<LeaveRequest[]>([]);
const pendingLoading = ref(false);
const pendingError   = ref("");

// ── Computed helpers ─────────────────────────────────────────────────────
const selectedType = computed<LeaveTypeFull | null>(() => {
  const id = Number(applyForm.value.leave_type_id);
  return leaveTypes.value.find(t => t.id === id) ?? null;
});

// Scattered is opt-in — range picker is always the default
const useScattered = ref(false);

const isScatteredMode = computed(() =>
  useScattered.value && !!selectedType.value?.allow_scattered && !applyForm.value.is_half_day
);

function org(): string | null { return currentOrgSlug.value; }

// ── Loaders ───────────────────────────────────────────────────────────────

async function loadMyLeaves(page = 1): Promise<void> {
  if (!org()) return;
  myLoading.value = true; myError.value = ""; myPage.value = page;
  try {
    const res = await api<Paginator<LeaveRequest>>("GET", "/api/v1/leave", { org: org(), params: { page, per_page: 10 } });
    myLeaves.value = res.data;
    myTotal.value  = res.meta.total;
  } catch (e) { myError.value = e instanceof Error ? e.message : "Failed."; }
  finally { myLoading.value = false; }
}

async function loadBalance(): Promise<void> {
  if (!org()) return;
  balLoading.value = true; balError.value = "";
  try {
    const res = await api<LeaveBalanceResponse>("GET", "/api/v1/leave/balance", { org: org(), params: { year: balYear.value } });
    balances.value = res.data;
  } catch (e) {
    balError.value = e instanceof ApiError && e.status === 422
      ? "No employee record linked to your account."
      : e instanceof Error ? e.message : "Failed.";
  }
  finally { balLoading.value = false; }
}

async function loadLeaveTypes(): Promise<void> {
  if (!org()) return;
  typesLoading.value = true;
  try {
    const res = await api<{ data: LeaveTypeFull[] }>("GET", "/api/v1/leave-types", { org: org() });
    leaveTypes.value = (res as any).data ?? [];
  } catch { /* silent */ }
  finally { typesLoading.value = false; }
}

async function loadPending(): Promise<void> {
  if (!org()) return;
  pendingLoading.value = true; pendingError.value = "";
  try {
    const res = await api<Paginator<LeaveRequest>>("GET", "/api/v1/leave", { org: org(), params: { status: "pending", per_page: 50 } });
    pending.value = res.data;
  } catch (e) {
    if (!(e instanceof ApiError && e.status === 403)) {
      pendingError.value = e instanceof Error ? e.message : "Failed.";
    }
  }
  finally { pendingLoading.value = false; }
}

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
    const res = await api<CalcResult>("POST", "/api/v1/leave/calculate", { org: org(), body });
    calcResult.value = res;
  } catch { /* non-blocking */ }
  finally { calcLoading.value = false; }
}

// ── Scatter date helpers ──────────────────────────────────────────────────

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

// ── Apply submit ──────────────────────────────────────────────────────────

async function applyLeave(): Promise<void> {
  const typeId = Number(applyForm.value.leave_type_id);
  if (!typeId) { applyError.value = "Please select a leave type."; return; }

  if (applyForm.value.is_half_day && !applyForm.value.from_date) {
    applyError.value = "Please select the date for the half day."; return;
  }
  if (!applyForm.value.is_half_day && !isScatteredMode.value) {
    if (!applyForm.value.from_date || !applyForm.value.to_date) {
      applyError.value = "Please fill in both From and To dates."; return;
    }
  }
  if (isScatteredMode.value && !scatteredDates.value.length) {
    applyError.value = "Please add at least one date for scattered leave."; return;
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
    applySuccess.value = "Leave request submitted successfully.";
    applyForm.value = { leave_type_id: "", from_date: "", to_date: "", reason: "", is_half_day: false, half_day_period: "morning" };
    scatteredDates.value = [];
    calcResult.value = null;
    void loadMyLeaves(1);
    void loadBalance();
  } catch (e) {
    applyError.value = e instanceof ApiError ? (e.firstError ?? e.message) : (e instanceof Error ? e.message : "Failed.");
  }
  finally { applying.value = false; }
}

// ── Pending approval actions ──────────────────────────────────────────────

async function approveLeave(id: number): Promise<void> {
  await api("POST", `/api/v1/leave/${id}/approve`, { org: org(), body: {} });
  void loadPending();
}

async function rejectLeave(id: number): Promise<void> {
  const reason = prompt("Rejection reason:");
  if (reason === null) return;
  await api("POST", `/api/v1/leave/${id}/reject`, { org: org(), body: { rejection_reason: reason } });
  void loadPending();
}

async function cancelLeave(id: number): Promise<void> {
  if (!confirm("Cancel this leave request?")) return;
  await api("DELETE", `/api/v1/leave/${id}`, { org: org() });
  void loadMyLeaves(myPage.value);
}

// ── Watchers ──────────────────────────────────────────────────────────────

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

// ── Status badge ──────────────────────────────────────────────────────────
function statusBadge(status: string): string {
  return { pending: "badge-yellow", approved: "badge-green", rejected: "badge-red", cancelled: "badge-gray" }[status] ?? "badge-gray";
}

function formatDate(d: string): string {
  try { return new Date(d).toLocaleDateString("en-KE", { weekday: "short", day: "numeric", month: "short" }); }
  catch { return d; }
}

// ── Init ──────────────────────────────────────────────────────────────────
function init(): void {
  void loadMyLeaves(1);
  void loadBalance();
  void loadLeaveTypes();
  void loadPending();
}

onMounted(init);
watch(currentOrgSlug, init);
</script>

<template>
  <div class="view">
    <div class="view-header"><h2>Leave</h2></div>

    <!-- Tabs -->
    <div class="tabs">
      <button :class="['tab', { active: tab === 'my-leaves' }]" @click="tab = 'my-leaves'">My Requests</button>
      <button :class="['tab', { active: tab === 'balance' }]"   @click="tab = 'balance'">Balance</button>
      <button :class="['tab', { active: tab === 'apply' }]"     @click="tab = 'apply'">Apply</button>
      <button :class="['tab', { active: tab === 'pending' }]"   @click="tab = 'pending'">Pending Approvals</button>
    </div>

    <!-- ══ MY REQUESTS ═══════════════════════════════════════════════════ -->
    <div v-if="tab === 'my-leaves'">
      <p v-if="myLoading" class="status">Loading…</p>
      <p v-if="myError" class="error">{{ myError }}</p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Type</th><th>Date(s)</th><th>Days</th><th>Mode</th><th>Status</th><th>Submitted</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in myLeaves" :key="r.id">
              <td>{{ r.leave_type?.name ?? "—" }}</td>
              <td>
                <span v-if="r.scattered_dates && r.scattered_dates.length">
                  {{ r.scattered_dates.length }} day{{ r.scattered_dates.length !== 1 ? 's' : '' }}
                  <span class="scatter-count">(scattered)</span>
                </span>
                <span v-else-if="r.is_half_day">
                  {{ formatDate(r.from_date) }}
                  <span class="scatter-count">({{ r.half_day_period ?? 'half' }})</span>
                </span>
                <span v-else>{{ formatDate(r.from_date) }} → {{ formatDate(r.to_date) }}</span>
              </td>
              <td>{{ r.days_requested }}</td>
              <td>
                <span v-if="r.is_half_day" class="mode-chip half">½ day</span>
                <span v-else-if="r.scattered_dates?.length" class="mode-chip scatter">scattered</span>
                <span v-else class="mode-chip">range</span>
              </td>
              <td><span :class="['badge', statusBadge(r.status)]">{{ r.status }}</span></td>
              <td class="cell-sub">{{ r.created_at.substring(0,10) }}</td>
              <td>
                <button v-if="r.status === 'pending'" class="btn btn-secondary btn-sm" @click.stop="cancelLeave(r.id)">Cancel</button>
              </td>
            </tr>
            <tr v-if="myLeaves.length === 0 && !myLoading">
              <td colspan="7" class="empty">No leave requests found.</td>
            </tr>
          </tbody>
        </table>
        <div class="pagination">
          <span>{{ myTotal }} total</span>
          <button class="btn btn-secondary btn-sm" :disabled="myPage === 1" @click="loadMyLeaves(myPage - 1)">← Prev</button>
          <button class="btn btn-secondary btn-sm" @click="loadMyLeaves(myPage + 1)">Next →</button>
        </div>
      </div>
    </div>

    <!-- ══ BALANCE ═══════════════════════════════════════════════════════ -->
    <div v-if="tab === 'balance'">
      <div class="year-bar">
        <label>Year</label>
        <input v-model="balYear" type="number" class="input year-inp" @change="loadBalance" />
        <button class="btn btn-secondary btn-sm" :disabled="balLoading" @click="loadBalance">Refresh</button>
      </div>
      <p v-if="balLoading" class="status">Loading…</p>
      <p v-if="balError" class="error">{{ balError }}</p>
      <div v-if="balances.length > 0" class="card">
        <div class="balance-list">
          <div v-for="b in balances" :key="b.leave_type" class="balance-item">
            <div class="balance-header">
              <span class="balance-name">{{ b.leave_type }}</span>
              <span class="balance-numbers">{{ b.available_days }} / {{ b.entitled_days + b.carried_days }} available</span>
            </div>
            <div class="balance-bar">
              <div
                class="balance-fill"
                :style="{ width: (b.entitled_days + b.carried_days) > 0
                  ? `${Math.max(0, (b.available_days / (b.entitled_days + b.carried_days)) * 100)}%`
                  : '0%' }"
              ></div>
            </div>
            <div class="balance-meta">
              <span>Entitled: {{ b.entitled_days }}</span>
              <span>Used: {{ b.used_days }}</span>
              <span>Pending: {{ b.pending_days }}</span>
              <span>Carried: {{ b.carried_days }}</span>
            </div>
          </div>
        </div>
      </div>
      <p v-if="!balLoading && balances.length === 0 && !balError" class="empty">No leave balances for {{ balYear }}.</p>
    </div>

    <!-- ══ APPLY ══════════════════════════════════════════════════════════ -->
    <div v-if="tab === 'apply'" class="apply-container">
      <div class="card apply-card">
        <h3 class="card-title">Apply for Leave</h3>

        <p v-if="applySuccess" class="apply-success">✓ {{ applySuccess }}</p>
        <p v-if="applyError"   class="error">{{ applyError }}</p>

        <!-- Leave Type -->
        <div class="form-field">
          <label>Leave Type <span class="req">*</span></label>
          <select v-model="applyForm.leave_type_id" class="input" :disabled="typesLoading">
            <option value="">{{ typesLoading ? 'Loading…' : '— Select a leave type —' }}</option>
            <option v-for="t in leaveTypes" :key="t.id" :value="t.id">
              {{ t.name }}{{ t.code ? ` (${t.code})` : '' }}
              — {{ t.counting_method === 'calendar_days' ? 'calendar days' : 'business days' }}
            </option>
          </select>
          <span v-if="selectedType" class="field-hint">
            <template v-if="selectedType.counting_method === 'calendar_days'">
              Counts every calendar day including weekends and public holidays.
            </template>
            <template v-else>
              Counts working days only — weekends and public holidays are skipped.
            </template>
          </span>
        </div>

        <!-- Mode toggles row -->
        <div v-if="selectedType" class="option-row">
          <!-- Half-day (if type allows) -->
          <template v-if="selectedType.allow_half_day">
            <label class="toggle-label">
              <span class="toggle-track" :class="{ on: applyForm.is_half_day }" @click="applyForm.is_half_day = !applyForm.is_half_day; useScattered = false">
                <span class="toggle-thumb" />
              </span>
              Half-Day
            </label>
            <select v-if="applyForm.is_half_day" v-model="applyForm.half_day_period" class="input period-select">
              <option value="morning">Morning (AM)</option>
              <option value="afternoon">Afternoon (PM)</option>
            </select>
          </template>
          <!-- Scattered (if type allows and not half-day) -->
          <template v-if="selectedType.allow_scattered && !applyForm.is_half_day">
            <label class="toggle-label">
              <span class="toggle-track" :class="{ on: useScattered }" @click="useScattered = !useScattered; scatteredDates = []; calcResult = null">
                <span class="toggle-thumb" />
              </span>
              Scattered Dates
            </label>
          </template>
        </div>

        <!-- Date fields — half day (single date) -->
        <template v-if="applyForm.is_half_day">
          <div class="form-field">
            <label>Date <span class="req">*</span></label>
            <input v-model="applyForm.from_date" type="date" class="input" />
          </div>
        </template>

        <!-- Date fields — scattered mode (opt-in) -->
        <template v-else-if="isScatteredMode">
          <div class="form-field">
            <label>Add Individual Dates <span class="req">*</span></label>
            <div class="scatter-input-row">
              <input v-model="newScatterDate" type="date" class="input" @keyup.enter="addScatterDate" />
              <button class="btn btn-secondary btn-sm" @click="addScatterDate">Add</button>
            </div>
            <span class="field-hint">Add each non-consecutive date one at a time. Weekends and public holidays are excluded automatically for business-day types.</span>
          </div>
          <div v-if="scatteredDates.length" class="scatter-chips">
            <span v-for="d in scatteredDates" :key="d" class="scatter-chip">
              {{ formatDate(d) }}
              <button class="remove-chip" @click="removeScatterDate(d)">×</button>
            </span>
          </div>
        </template>

        <!-- Date fields — consecutive range (default) -->
        <template v-else>
          <div class="form-grid-2">
            <div class="form-field">
              <label>From <span class="req">*</span></label>
              <input v-model="applyForm.from_date" type="date" class="input" />
            </div>
            <div class="form-field">
              <label>To <span class="req">*</span></label>
              <input v-model="applyForm.to_date" type="date" class="input" :min="applyForm.from_date" />
            </div>
          </div>
        </template>

        <!-- Reason -->
        <div class="form-field">
          <label>Reason</label>
          <input v-model="applyForm.reason" type="text" class="input" placeholder="Optional reason" />
        </div>

        <!-- Day preview -->
        <div v-if="calcLoading" class="preview-box loading">Calculating days…</div>
        <div v-else-if="calcResult" class="preview-box">
          <div class="preview-header">
            <span class="preview-days">{{ calcResult.days }} day{{ calcResult.days !== 1 ? 's' : '' }} charged</span>
            <span class="preview-method">{{ calcResult.counting_method === 'calendar_days' ? 'Calendar' : 'Business' }} days</span>
          </div>
          <div class="preview-breakdown">
            <div
              v-for="b in calcResult.breakdown"
              :key="b.date"
              class="bd-row"
              :class="{ 'bd-skipped': !b.counted }"
            >
              <span class="bd-day">{{ b.weekday }}</span>
              <span class="bd-date">{{ b.date }}</span>
              <span class="bd-reason">{{ b.reason }}</span>
              <span class="bd-val">{{ b.counted ? '+1' : '—' }}</span>
            </div>
          </div>
        </div>

        <div class="apply-actions">
          <button class="btn btn-primary" :disabled="applying" @click="applyLeave">
            {{ applying ? "Submitting…" : "Submit Request" }}
          </button>
        </div>
      </div>
    </div>

    <!-- ══ PENDING APPROVALS ══════════════════════════════════════════════ -->
    <div v-if="tab === 'pending'">
      <p v-if="pendingLoading" class="status">Loading…</p>
      <p v-if="pendingError"   class="error">{{ pendingError }}</p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Employee</th><th>Type</th><th>Date(s)</th><th>Days</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <tr v-for="r in pending" :key="r.id">
              <td>{{ r.employee ? `${r.employee.first_name} ${r.employee.last_name}` : `#${r.employee_id}` }}</td>
              <td>{{ r.leave_type?.name ?? "—" }}</td>
              <td>
                <span v-if="r.scattered_dates?.length">{{ r.scattered_dates.length }} dates (scattered)</span>
                <span v-else-if="r.is_half_day">{{ r.from_date }} ({{ r.half_day_period }})</span>
                <span v-else>{{ r.from_date }} → {{ r.to_date }}</span>
              </td>
              <td>{{ r.days_requested }}</td>
              <td>
                <div style="display:flex;gap:6px">
                  <button class="btn btn-primary btn-sm" @click="approveLeave(r.id)">Approve</button>
                  <button class="btn btn-ghost btn-sm btn-danger-ghost" @click="rejectLeave(r.id)">Reject</button>
                </div>
              </td>
            </tr>
            <tr v-if="pending.length === 0 && !pendingLoading">
              <td colspan="5" class="empty">No pending leave requests.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.req { color: var(--color-error); margin-left: 2px; }
.cell-sub { font-size: 12px; color: var(--color-text-muted); }

/* ── Year bar ─────────────────────────────────────────────────────────── */
.year-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; font-size: 13px; font-weight: 600; }
.year-inp { width: 90px; }

/* ── Balance ─────────────────────────────────────────────────────────── */
.balance-list { display: flex; flex-direction: column; gap: 20px; }
.balance-item { display: flex; flex-direction: column; gap: 6px; }
.balance-header { display: flex; align-items: center; justify-content: space-between; }
.balance-name { font-size: var(--font-size-sm); font-weight: var(--font-weight-bold); color: var(--color-text); }
.balance-numbers { font-size: var(--font-size-sm); font-weight: var(--font-weight-semibold); color: var(--color-brand-base); }
.balance-bar {
  height: 8px; background: var(--color-neutral-200); border-radius: var(--radius-full); overflow: hidden;
}
.balance-fill { height: 100%; background: var(--color-brand-base); border-radius: var(--radius-full); transition: width 0.4s; }
.balance-meta { display: flex; gap: 16px; font-size: 12px; color: var(--color-text-muted); }

/* ── Apply container ─────────────────────────────────────────────────── */
.apply-container { display: flex; gap: 24px; flex-wrap: wrap; align-items: flex-start; }
.apply-card { flex: 0 0 520px; max-width: 100%; padding: 24px; }
.card-title { font-size: var(--font-size-lg); font-weight: var(--font-weight-bold); margin-bottom: 20px; }
.apply-success {
  padding: 10px 16px; background: var(--color-success-light); color: var(--color-success);
  border: 1px solid rgba(16,137,129,0.2); border-radius: var(--radius-lg); font-size: var(--font-size-sm); margin-bottom: 16px;
}
.field-hint { font-size: 12px; color: var(--color-text-muted); margin-top: 4px; display: block; }
.form-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 16px; }
.form-field label { font-size: var(--font-size-sm); font-weight: var(--font-weight-semibold); color: var(--color-text); }
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

/* ── Half-day / option row ───────────────────────────────────────────── */
.option-row { display: flex; align-items: center; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
.period-select { width: auto; min-width: 160px; }

/* ── Toggle ──────────────────────────────────────────────────────────── */
.toggle-label {
  display: flex; align-items: center; gap: 10px;
  font-size: var(--font-size-sm); font-weight: var(--font-weight-semibold); color: var(--color-text);
  cursor: pointer; user-select: none;
}
.toggle-track {
  position: relative; display: inline-block; width: 36px; height: 20px;
  background: var(--color-neutral-300); border-radius: var(--radius-full);
  transition: background 0.18s; flex-shrink: 0;
}
.toggle-track.on { background: var(--color-brand-base); }
.toggle-thumb {
  position: absolute; top: 2px; left: 2px; width: 16px; height: 16px;
  background: #fff; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.2); transition: left 0.18s;
}
.toggle-track.on .toggle-thumb { left: 18px; }

/* ── Scattered ───────────────────────────────────────────────────────── */
.scatter-input-row { display: flex; gap: 8px; }
.scatter-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.scatter-chip {
  display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px;
  background: var(--color-brand-subtle); border: 1px solid var(--color-brand-base);
  border-radius: var(--radius-full); font-size: var(--font-size-sm); font-weight: var(--font-weight-semibold);
  color: var(--color-brand-base);
}
.remove-chip {
  background: none; border: none; cursor: pointer; color: var(--color-brand-base); font-size: 14px;
  line-height: 1; padding: 0; opacity: 0.7;
}
.remove-chip:hover { opacity: 1; }

/* ── Day preview ─────────────────────────────────────────────────────── */
.preview-box {
  border: 1px solid var(--color-border); border-radius: var(--radius-xl);
  overflow: hidden; margin-bottom: 20px; font-size: var(--font-size-sm);
}
.preview-box.loading { padding: 14px 16px; color: var(--color-text-muted); }
.preview-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 16px; background: var(--color-neutral-100);
  border-bottom: 1px solid var(--color-border);
}
.preview-days { font-size: var(--font-size-md); font-weight: var(--font-weight-bold); color: var(--color-brand-base); }
.preview-method { font-size: 12px; color: var(--color-text-muted); }
.preview-breakdown { max-height: 220px; overflow-y: auto; }
.bd-row {
  display: grid; grid-template-columns: 36px 110px 1fr 30px;
  align-items: center; gap: 6px; padding: 6px 16px;
  border-bottom: 1px solid var(--color-border);
}
.bd-row:last-child { border-bottom: none; }
.bd-row.bd-skipped { background: var(--color-neutral-50); opacity: 0.6; }
.bd-day { font-weight: var(--font-weight-bold); color: var(--color-text-muted); }
.bd-date { color: var(--color-text); }
.bd-reason { color: var(--color-text-muted); }
.bd-val { font-weight: var(--font-weight-bold); color: var(--color-brand-base); text-align: right; }
.bd-skipped .bd-val { color: var(--color-text-muted); }

.apply-actions { display: flex; justify-content: flex-end; margin-top: 4px; }

/* ── Modes in My Requests table ─────────────────────────────────────── */
.mode-chip {
  display: inline-block; padding: 2px 8px;
  background: var(--color-neutral-100); color: var(--color-text-muted);
  border: 1px solid var(--color-border); border-radius: var(--radius-full);
  font-size: 11px; font-weight: var(--font-weight-semibold);
}
.mode-chip.half    { background: var(--color-warning-light); color: #92400E; border-color: #F59E0B; }
.mode-chip.scatter { background: var(--color-brand-subtle); color: var(--color-brand-base); border-color: var(--color-brand-base); }
.scatter-count { font-size: 12px; color: var(--color-text-muted); margin-left: 4px; }
.btn-danger-ghost { color: var(--color-error); }
.btn-danger-ghost:hover:not(:disabled) { background: var(--color-error-light); }
</style>
