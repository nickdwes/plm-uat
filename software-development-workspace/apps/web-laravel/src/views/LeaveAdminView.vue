<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

const router = useRouter();

interface LeaveType {
  id: number; name: string; code: string | null;
  days_per_year: number; is_paid: boolean; carry_forward: boolean;
  max_carry_days: number; requires_approval: boolean; is_active: boolean;
  counting_method: "business_days" | "calendar_days";
  working_days: number[] | null;
  allow_half_day: boolean;
  allow_scattered: boolean;
}
interface Balance {
  id: number; employee_id: number; employee_name: string;
  leave_type: string; leave_type_id: number;
  year: number; entitled_days: number; used_days: number;
  pending_days: number; carried_days: number; remaining: number;
}
interface PublicHoliday {
  id: number; name: string; local_name: string | null;
  date: string; year: number; country_code: string;
  source: "manual" | "imported"; is_active: boolean;
}

const tab       = ref<"types" | "balances" | "holidays">("types");
const types     = ref<LeaveType[]>([]);
const balances  = ref<Balance[]>([]);
const loading   = ref(false);
const error     = ref("");
const saving    = ref(false);
const saveError = ref("");
const balYear   = ref(new Date().getFullYear());
const initYear  = ref(new Date().getFullYear());
const initing   = ref(false);
const initMsg   = ref("");
const editBalId = ref<number | null>(null);
const editBal   = ref({ entitled_days: 0, carried_days: 0 });

const showForm    = ref(false);
const editTypeId  = ref<number | null>(null);
const typeForm    = ref({
  name: "", code: "", days_per_year: 0,
  is_paid: true, carry_forward: false, max_carry_days: 0,
  requires_approval: true, is_active: true,
  counting_method: "business_days" as "business_days" | "calendar_days",
  working_days: [1, 2, 3, 4, 5] as number[],
  allow_half_day: false,
  allow_scattered: false,
});

const WEEKDAYS = [
  { iso: 1, label: "Mon" }, { iso: 2, label: "Tue" }, { iso: 3, label: "Wed" },
  { iso: 4, label: "Thu" }, { iso: 5, label: "Fri" }, { iso: 6, label: "Sat" }, { iso: 7, label: "Sun" },
];

function toggleWorkDay(iso: number): void {
  const idx = typeForm.value.working_days.indexOf(iso);
  if (idx >= 0) typeForm.value.working_days.splice(idx, 1);
  else typeForm.value.working_days.push(iso);
}

// ── Public holidays state ─────────────────────────────────────────────────
const holidays    = ref<PublicHoliday[]>([]);
const holYear     = ref(new Date().getFullYear());
const importing   = ref(false);
const importMsg   = ref("");
const importErr   = ref("");
const showHolForm = ref(false);
const editHolId   = ref<number | null>(null);
const holForm     = ref({ name: "", local_name: "", date: "", is_active: true });
const holSaving   = ref(false);
const holSaveErr  = ref("");

const activeHols   = computed(() => holidays.value.filter(h => h.is_active).length);
const upcomingHols = computed(() => holidays.value.filter(h => h.is_active && new Date(h.date) >= new Date()).length);
const passedHols   = computed(() => holidays.value.filter(h => h.is_active && new Date(h.date) < new Date()).length);

function org(): string | null { return currentOrgSlug.value; }

// ── Leave Types ───────────────────────────────────────────────────────────

async function loadTypes(): Promise<void> {
  if (!org()) return;
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: LeaveType[] }>("GET", "/api/v1/leave-types", { org: org() });
    types.value = (res as any).data ?? res;
  } catch (e) { error.value = e instanceof Error ? e.message : "Failed."; }
  finally { loading.value = false; }
}

async function loadBalances(): Promise<void> {
  if (!org()) return;
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: Balance[] }>("GET", "/api/v1/leave-balances", { org: org(), params: { year: balYear.value } });
    balances.value = (res as any).data ?? [];
  } catch (e) { error.value = e instanceof Error ? e.message : "Failed."; }
  finally { loading.value = false; }
}

function openNewType(): void {
  editTypeId.value = null;
  typeForm.value = {
    name: "", code: "", days_per_year: 0, is_paid: true, carry_forward: false,
    max_carry_days: 0, requires_approval: true, is_active: true,
    counting_method: "business_days", working_days: [1, 2, 3, 4, 5],
    allow_half_day: false, allow_scattered: false,
  };
  showForm.value = true;
}

function openEditType(t: LeaveType): void {
  editTypeId.value = t.id;
  typeForm.value = {
    name: t.name, code: t.code ?? "", days_per_year: t.days_per_year,
    is_paid: t.is_paid, carry_forward: t.carry_forward, max_carry_days: t.max_carry_days,
    requires_approval: t.requires_approval, is_active: t.is_active,
    counting_method: t.counting_method ?? "business_days",
    working_days: t.working_days ? [...t.working_days] : [1, 2, 3, 4, 5],
    allow_half_day: t.allow_half_day ?? false,
    allow_scattered: t.allow_scattered ?? false,
  };
  showForm.value = true;
}

async function saveType(): Promise<void> {
  saving.value = true; saveError.value = "";
  try {
    if (editTypeId.value) {
      await api("PUT", `/api/v1/leave-types/${editTypeId.value}`, { org: org(), body: typeForm.value });
    } else {
      await api("POST", "/api/v1/leave-types", { org: org(), body: typeForm.value });
    }
    showForm.value = false;
    await loadTypes();
  } catch (e) { saveError.value = e instanceof Error ? e.message : "Failed."; }
  finally { saving.value = false; }
}

async function deleteType(t: LeaveType): Promise<void> {
  if (!confirm(`Delete leave type "${t.name}"?`)) return;
  await api("DELETE", `/api/v1/leave-types/${t.id}`, { org: org() });
  await loadTypes();
}

async function initBalances(): Promise<void> {
  initing.value = true; initMsg.value = "";
  try {
    const res = await api<{ message: string }>("POST", "/api/v1/leave-balances/init", { org: org(), body: { year: initYear.value } });
    initMsg.value = (res as any).message ?? "Done";
    await loadBalances();
  } catch (e) { initMsg.value = e instanceof Error ? e.message : "Failed."; }
  finally { initing.value = false; }
}

function startEditBal(b: Balance): void {
  editBalId.value = b.id;
  editBal.value = { entitled_days: b.entitled_days, carried_days: b.carried_days };
}

async function saveBal(b: Balance): Promise<void> {
  await api("PUT", `/api/v1/leave-balances/${b.id}`, { org: org(), body: editBal.value });
  editBalId.value = null;
  await loadBalances();
}

// ── Public Holidays ───────────────────────────────────────────────────────

async function loadHolidays(): Promise<void> {
  if (!org()) return;
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: PublicHoliday[] }>("GET", "/api/v1/public-holidays", { org: org(), params: { year: holYear.value } });
    holidays.value = (res as any).data ?? [];
  } catch (e) { error.value = e instanceof Error ? e.message : "Failed."; }
  finally { loading.value = false; }
}

async function importHolidays(): Promise<void> {
  if (!org()) return;
  importMsg.value = ""; importErr.value = "";
  importing.value = true;
  try {
    const res = await api<{ message: string }>("POST", "/api/v1/public-holidays/import", { org: org(), body: { year: holYear.value, country_code: "KE" } });
    importMsg.value = (res as any).message ?? "Done";
    await loadHolidays();
  } catch (e) { importErr.value = e instanceof Error ? e.message : "Import failed."; }
  finally { importing.value = false; }
}

function openNewHol(): void {
  editHolId.value = null;
  holForm.value = { name: "", local_name: "", date: `${holYear.value}-01-01`, is_active: true };
  showHolForm.value = true; holSaveErr.value = "";
}

function openEditHol(h: PublicHoliday): void {
  editHolId.value = h.id;
  holForm.value = { name: h.name, local_name: h.local_name ?? "", date: h.date, is_active: h.is_active };
  showHolForm.value = true; holSaveErr.value = "";
}

async function saveHol(): Promise<void> {
  holSaving.value = true; holSaveErr.value = "";
  try {
    const body = { ...holForm.value, local_name: holForm.value.local_name || null };
    if (editHolId.value) {
      await api("PUT", `/api/v1/public-holidays/${editHolId.value}`, { org: org(), body });
    } else {
      await api("POST", "/api/v1/public-holidays", { org: org(), body });
    }
    showHolForm.value = false;
    await loadHolidays();
  } catch (e) { holSaveErr.value = e instanceof Error ? e.message : "Save failed."; }
  finally { holSaving.value = false; }
}

async function removeHol(h: PublicHoliday): Promise<void> {
  if (!confirm(`Remove "${h.name}" from the list?`)) return;
  await api("DELETE", `/api/v1/public-holidays/${h.id}`, { org: org() });
  await loadHolidays();
}

function formatDate(d: string) {
  return new Date(d).toLocaleDateString("en-KE", { weekday: "short", day: "numeric", month: "long" });
}
function isPast(d: string)  { return new Date(d) < new Date(new Date().toDateString()); }
function isToday(d: string) { return new Date(d).toDateString() === new Date().toDateString(); }

onMounted(() => { loadTypes(); });
watch(tab, (t) => {
  if (t === "balances") loadBalances();
  if (t === "holidays") loadHolidays();
});
watch(balYear, loadBalances);
watch(holYear, () => { if (tab.value === "holidays") loadHolidays(); });
watch(currentOrgSlug, () => {
  if (tab.value === "types") loadTypes();
  if (tab.value === "balances") loadBalances();
  if (tab.value === "holidays") loadHolidays();
});
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div style="display:flex;align-items:center;gap:12px">
        <button class="btn btn-ghost btn-sm" @click="router.push('/org')" style="display:flex;align-items:center;gap:4px;font-size:13px">
          <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
          Organisation
        </button>
        <h2 style="margin:0">Leave Administration</h2>
      </div>
    </div>

    <p v-if="error" class="error">{{ error }}</p>

    <!-- ── Tabs ─────────────────────────────────────────────────────────── -->
    <div class="tabs">
      <button class="tab" :class="{ active: tab === 'types' }"    @click="tab = 'types'">Leave Types</button>
      <button class="tab" :class="{ active: tab === 'balances' }" @click="tab = 'balances'">Leave Balances</button>
      <button class="tab" :class="{ active: tab === 'holidays' }" @click="tab = 'holidays'">Public Holidays</button>
    </div>

    <!-- ══ Leave Types ═══════════════════════════════════════════════════ -->
    <template v-if="tab === 'types'">

      <!-- Add/Edit form -->
      <div v-if="showForm" class="card form-card">
        <div class="form-card-header">
          <span class="form-card-title">{{ editTypeId ? "Edit Leave Type" : "New Leave Type" }}</span>
          <button class="btn btn-ghost btn-sm" @click="showForm = false">✕</button>
        </div>
        <p v-if="saveError" class="error">{{ saveError }}</p>
        <div class="form-grid form-grid-2">
          <div class="form-field">
            <label>Name <span class="required">*</span></label>
            <input v-model="typeForm.name" type="text" class="input" placeholder="e.g. Annual Leave" />
          </div>
          <div class="form-field">
            <label>Code</label>
            <input v-model="typeForm.code" type="text" class="input" placeholder="e.g. AL" />
          </div>
          <div class="form-field">
            <label>Days Per Year <span class="required">*</span></label>
            <input v-model.number="typeForm.days_per_year" class="input" type="number" min="0" />
          </div>
          <div class="form-field">
            <label>Carry-Forward Cap (days)</label>
            <input v-model.number="typeForm.max_carry_days" class="input" type="number" min="0" />
          </div>
          <div class="form-field">
            <label>Counting Method <span class="required">*</span></label>
            <select v-model="typeForm.counting_method" class="input">
              <option value="business_days">Business Days — working days only, public holidays excluded</option>
              <option value="calendar_days">Calendar Days — every day including weekends &amp; public holidays</option>
            </select>
          </div>
        </div>
        <!-- Working days — only relevant for business_days mode -->
        <div v-if="typeForm.counting_method === 'business_days'" class="working-days-row">
          <span class="wd-label">Working Days</span>
          <label v-for="wd in WEEKDAYS" :key="wd.iso" class="wd-checkbox">
            <input
              type="checkbox"
              :checked="typeForm.working_days.includes(wd.iso)"
              @change="toggleWorkDay(wd.iso)"
            />
            {{ wd.label }}
          </label>
        </div>
        <div class="toggle-row">
          <label class="toggle-label">
            <span class="toggle-track" :class="{ on: typeForm.is_paid }" @click="typeForm.is_paid = !typeForm.is_paid">
              <span class="toggle-thumb" />
            </span>
            Paid Leave
          </label>
          <label class="toggle-label">
            <span class="toggle-track" :class="{ on: typeForm.carry_forward }" @click="typeForm.carry_forward = !typeForm.carry_forward">
              <span class="toggle-thumb" />
            </span>
            Allow Carry Forward
          </label>
          <label class="toggle-label">
            <span class="toggle-track" :class="{ on: typeForm.requires_approval }" @click="typeForm.requires_approval = !typeForm.requires_approval">
              <span class="toggle-thumb" />
            </span>
            Requires Approval
          </label>
          <label class="toggle-label">
            <span class="toggle-track" :class="{ on: typeForm.is_active }" @click="typeForm.is_active = !typeForm.is_active">
              <span class="toggle-thumb" />
            </span>
            Active
          </label>
          <label class="toggle-label">
            <span class="toggle-track" :class="{ on: typeForm.allow_half_day }" @click="typeForm.allow_half_day = !typeForm.allow_half_day">
              <span class="toggle-thumb" />
            </span>
            Allow Half-Day
          </label>
          <label class="toggle-label">
            <span class="toggle-track" :class="{ on: typeForm.allow_scattered }" @click="typeForm.allow_scattered = !typeForm.allow_scattered">
              <span class="toggle-thumb" />
            </span>
            Allow Scattered Days
          </label>
        </div>
        <div class="form-actions">
          <button class="btn btn-ghost" @click="showForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveType">
            {{ saving ? "Saving…" : editTypeId ? "Save Changes" : "Create Leave Type" }}
          </button>
        </div>
      </div>

      <!-- Toolbar -->
      <div class="section-toolbar">
        <span v-if="!loading" class="result-count">{{ types.length }} {{ types.length === 1 ? "type" : "types" }}</span>
        <button class="btn btn-primary btn-sm" @click="openNewType">+ Add Leave Type</button>
      </div>

      <p v-if="loading" class="status">Loading…</p>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Name</th><th>Code</th><th>Days/Year</th><th>Counting</th><th>Paid</th>
              <th>Carry Forward</th><th>Options</th><th>Status</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!types.length">
              <td colspan="9" class="empty">No leave types configured. Add one above.</td>
            </tr>
            <tr v-for="t in types" :key="t.id">
              <td class="cell-name">{{ t.name }}</td>
              <td><span class="code-chip">{{ t.code ?? "—" }}</span></td>
              <td>{{ t.days_per_year }}</td>
              <td>
                <span class="badge" :class="t.counting_method === 'calendar_days' ? 'badge-purple' : 'badge-blue'">
                  {{ t.counting_method === 'calendar_days' ? 'Calendar' : 'Business' }}
                </span>
              </td>
              <td><span class="badge" :class="t.is_paid ? 'badge-green' : 'badge-gray'">{{ t.is_paid ? "Paid" : "Unpaid" }}</span></td>
              <td>{{ t.carry_forward ? `Yes (max ${t.max_carry_days}d)` : "No" }}</td>
              <td class="cell-opts">
                <span v-if="t.allow_half_day" class="opt-chip">½ day</span>
                <span v-if="t.allow_scattered" class="opt-chip">scattered</span>
                <span v-if="!t.allow_half_day && !t.allow_scattered" class="text-muted">—</span>
              </td>
              <td><span class="badge" :class="t.is_active ? 'badge-blue' : 'badge-gray'">{{ t.is_active ? "Active" : "Inactive" }}</span></td>
              <td class="cell-actions">
                <button class="btn btn-ghost btn-sm" @click="openEditType(t)">Edit</button>
                <button class="btn btn-ghost btn-sm btn-danger-ghost" @click="deleteType(t)">Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ══ Leave Balances ════════════════════════════════════════════════ -->
    <template v-if="tab === 'balances'">
      <div class="balances-toolbar">
        <div class="toolbar-left">
          <div class="form-field inline-field">
            <label>Year</label>
            <input v-model.number="balYear" class="input year-input" type="number" />
          </div>
        </div>
        <div class="toolbar-right">
          <div class="init-block">
            <input v-model.number="initYear" class="input year-input" type="number" />
            <button class="btn btn-secondary btn-sm" :disabled="initing" @click="initBalances">
              {{ initing ? "Working…" : "Initialise Year" }}
            </button>
          </div>
          <p v-if="initMsg" class="init-msg">{{ initMsg }}</p>
        </div>
      </div>

      <p v-if="loading" class="status">Loading…</p>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Employee</th><th>Leave Type</th><th>Entitled</th>
              <th>Used</th><th>Pending</th><th>Carried</th><th>Remaining</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!balances.length">
              <td colspan="8" class="empty">No balances for {{ balYear }}. Use "Initialise Year" above to generate them.</td>
            </tr>
            <template v-for="b in balances" :key="b.id">
              <tr v-if="editBalId !== b.id">
                <td class="cell-name">{{ b.employee_name }}</td>
                <td>{{ b.leave_type }}</td>
                <td>{{ b.entitled_days }}</td><td>{{ b.used_days }}</td>
                <td>{{ b.pending_days }}</td><td>{{ b.carried_days }}</td>
                <td><span class="badge" :class="b.remaining < 0 ? 'badge-red' : b.remaining === 0 ? 'badge-gray' : 'badge-green'">{{ b.remaining }}d</span></td>
                <td class="cell-actions"><button class="btn btn-ghost btn-sm" @click="startEditBal(b)">Edit</button></td>
              </tr>
              <tr v-else class="editing-row">
                <td class="cell-name">{{ b.employee_name }}</td>
                <td>{{ b.leave_type }}</td>
                <td><input v-model.number="editBal.entitled_days" class="input inline-num" type="number" /></td>
                <td>{{ b.used_days }}</td><td>{{ b.pending_days }}</td>
                <td><input v-model.number="editBal.carried_days" class="input inline-num" type="number" /></td>
                <td>—</td>
                <td class="cell-actions">
                  <button class="btn btn-primary btn-sm" @click="saveBal(b)">Save</button>
                  <button class="btn btn-ghost btn-sm" @click="editBalId = null">Cancel</button>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ══ Public Holidays ═══════════════════════════════════════════════ -->
    <template v-if="tab === 'holidays'">

      <!-- Import bar -->
      <div class="import-bar">
        <div class="import-bar-left">
          <svg class="import-icon" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" />
          </svg>
          <div>
            <p class="import-title">Sync from Nager.Date</p>
            <p class="import-sub">
              Live data from
              <a href="https://date.nager.at/api/v3/PublicHolidays" target="_blank" rel="noopener" class="ext-link">date.nager.at</a>
              — updated annually for 100+ countries.
            </p>
          </div>
        </div>
        <div class="import-bar-right">
          <div class="form-field inline-field">
            <label>Year</label>
            <input v-model.number="holYear" class="input year-input" type="number" min="2020" max="2100" />
          </div>
          <button class="btn btn-secondary btn-sm" :disabled="importing" @click="importHolidays">
            {{ importing ? "Syncing…" : `Sync Kenya ${holYear}` }}
          </button>
          <button class="btn btn-primary btn-sm" @click="openNewHol">+ Add Holiday</button>
        </div>
      </div>
      <p v-if="importMsg" class="feedback-msg success-msg">✓ {{ importMsg }}</p>
      <p v-if="importErr" class="error">{{ importErr }}</p>

      <!-- Stats pills -->
      <div class="metrics-row">
        <div class="metric-pill">
          <span class="metric-num">{{ activeHols }}</span>
          <span class="metric-label">Total</span>
        </div>
        <div class="metric-pill green">
          <span class="metric-num">{{ upcomingHols }}</span>
          <span class="metric-label">Upcoming</span>
        </div>
        <div class="metric-pill muted">
          <span class="metric-num">{{ passedHols }}</span>
          <span class="metric-label">Passed</span>
        </div>
      </div>

      <!-- Add / Edit form -->
      <div v-if="showHolForm" class="card form-card">
        <div class="form-card-header">
          <span class="form-card-title">{{ editHolId ? "Edit Holiday" : "Add Holiday" }}</span>
          <button class="btn btn-ghost btn-sm" @click="showHolForm = false">✕</button>
        </div>
        <p v-if="holSaveErr" class="error">{{ holSaveErr }}</p>
        <div class="form-grid-3">
          <div class="form-field">
            <label>Name <span class="required">*</span></label>
            <input v-model="holForm.name" type="text" class="input" placeholder="e.g. Mashujaa Day" />
          </div>
          <div class="form-field">
            <label>Local / Swahili Name</label>
            <input v-model="holForm.local_name" type="text" class="input" placeholder="Optional" />
          </div>
          <div class="form-field">
            <label>Date <span class="required">*</span></label>
            <input v-model="holForm.date" type="date" class="input" />
          </div>
        </div>
        <div class="toggle-row">
          <label class="toggle-label">
            <span class="toggle-track" :class="{ on: holForm.is_active }" @click="holForm.is_active = !holForm.is_active">
              <span class="toggle-thumb" />
            </span>
            Active
          </label>
        </div>
        <div class="form-actions">
          <button class="btn btn-ghost" @click="showHolForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="holSaving" @click="saveHol">
            {{ holSaving ? "Saving…" : editHolId ? "Save Changes" : "Add Holiday" }}
          </button>
        </div>
      </div>

      <p v-if="loading" class="status">Loading…</p>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th><th>Name</th><th>Local Name</th><th>Source</th><th>Status</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!holidays.length">
              <td colspan="6" class="empty">
                No holidays for {{ holYear }}. Use "Sync Kenya {{ holYear }}" above to import them.
              </td>
            </tr>
            <tr
              v-for="h in holidays" :key="h.id"
              :class="{ 'row-past': isPast(h.date) && h.is_active, 'row-today': isToday(h.date), 'row-inactive': !h.is_active }"
            >
              <td class="cell-date">
                <span class="date-label" :class="{ past: isPast(h.date), today: isToday(h.date) }">{{ formatDate(h.date) }}</span>
                <span v-if="isToday(h.date)" class="today-chip">Today</span>
              </td>
              <td class="cell-name">{{ h.name }}</td>
              <td class="cell-local">{{ h.local_name ?? "—" }}</td>
              <td><span class="badge" :class="h.source === 'imported' ? 'badge-blue' : 'badge-gray'">{{ h.source === "imported" ? "Synced" : "Manual" }}</span></td>
              <td><span class="badge" :class="h.is_active ? 'badge-green' : 'badge-gray'">{{ h.is_active ? "Active" : "Inactive" }}</span></td>
              <td class="cell-actions">
                <button class="btn btn-ghost btn-sm" @click="openEditHol(h)">Edit</button>
                <button class="btn btn-ghost btn-sm btn-danger-ghost" @click="removeHol(h)">Remove</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<style scoped>
.required { color: var(--color-error); margin-left: 2px; }

/* ── Form card ───────────────────────────────────────────────────────────── */
.form-card { margin-bottom: 20px; padding: 24px; }
.form-card-header {
  display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;
}
.form-card-title { font-size: var(--font-size-md); font-weight: var(--font-weight-bold); color: var(--color-text); }

.form-grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 20px; }
.form-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 16px; }
@media (max-width: 720px) { .form-grid-3 { grid-template-columns: 1fr; } }

/* ── Working days row ───────────────────────────────────────────────────── */
.working-days-row {
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  margin: -8px 0 20px; padding: 12px 16px;
  background: var(--color-neutral-100, #F9FAFB); border-radius: var(--radius-lg);
}
.wd-label { font-size: var(--font-size-sm); font-weight: var(--font-weight-semibold); color: var(--color-text-muted); min-width: 90px; }
.wd-checkbox {
  display: flex; align-items: center; gap: 5px;
  font-size: var(--font-size-sm); font-weight: var(--font-weight-semibold);
  color: var(--color-text); cursor: pointer; user-select: none;
}
.wd-checkbox input[type=checkbox] { accent-color: var(--color-brand-base); width: 15px; height: 15px; cursor: pointer; }

/* ── Options chips in table ─────────────────────────────────────────────── */
.cell-opts { white-space: nowrap; }
.opt-chip {
  display: inline-block; padding: 2px 8px; margin: 1px 2px;
  background: var(--color-brand-subtle); color: var(--color-brand-base);
  border: 1px solid var(--color-brand-base); border-radius: var(--radius-full);
  font-size: 11px; font-weight: var(--font-weight-semibold);
}
.text-muted { color: var(--color-text-muted); font-size: var(--font-size-sm); }

/* ── Toggles ────────────────────────────────────────────────────────────── */
.toggle-row {
  display: flex; gap: 24px; flex-wrap: wrap; margin-bottom: 20px;
  padding: 16px; background: var(--color-neutral-100, #F9FAFB); border-radius: var(--radius-lg);
}
.toggle-label {
  display: flex; align-items: center; gap: 10px;
  font-size: var(--font-size-sm); font-weight: var(--font-weight-semibold);
  color: var(--color-text); cursor: pointer; user-select: none;
}
.toggle-track {
  position: relative; display: inline-block; width: 36px; height: 20px;
  background: var(--color-neutral-300, #D1D5DB); border-radius: var(--radius-full);
  transition: background 0.18s; flex-shrink: 0;
}
.toggle-track.on { background: var(--color-brand-base); }
.toggle-thumb {
  position: absolute; top: 2px; left: 2px; width: 16px; height: 16px;
  background: #fff; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.2); transition: left 0.18s;
}
.toggle-track.on .toggle-thumb { left: 18px; }

.form-actions { display: flex; justify-content: flex-end; gap: 8px; }

/* ── Section toolbar ─────────────────────────────────────────────────────── */
.section-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.result-count { font-size: var(--font-size-sm); color: var(--color-text-muted); }

/* ── Table helpers ───────────────────────────────────────────────────────── */
.cell-name   { font-weight: var(--font-weight-semibold); }
.cell-actions { text-align: right; white-space: nowrap; }
.code-chip {
  display: inline-block; padding: 2px 8px;
  background: var(--color-neutral-100, #F3F4F6); border: 1px solid var(--color-border);
  border-radius: var(--radius-md); font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold); font-family: monospace;
  color: var(--color-text-muted); letter-spacing: 0.04em;
}
.btn-danger-ghost { color: var(--color-error); }
.btn-danger-ghost:hover:not(:disabled) { color: var(--color-error); background: var(--color-error-light); }
.editing-row td { background: var(--color-brand-subtle); }
.inline-num { width: 72px; padding: 5px 8px; }

/* ── Balances toolbar ────────────────────────────────────────────────────── */
.balances-toolbar {
  display: flex; align-items: flex-end; justify-content: space-between;
  gap: 16px; flex-wrap: wrap; margin-bottom: 16px;
  padding: 16px 20px; background: var(--color-surface);
  border: 1px solid var(--color-border); border-radius: var(--radius-xl); box-shadow: var(--shadow-xs);
}
.toolbar-left, .toolbar-right { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.inline-field { flex-direction: row; align-items: center; gap: 10px; }
.inline-field label { margin: 0; white-space: nowrap; }
.year-input { width: 90px; }
.init-block { display: flex; align-items: center; gap: 8px; }
.init-msg { font-size: var(--font-size-sm); color: var(--color-success); margin: 0; }

/* ── Import bar ──────────────────────────────────────────────────────────── */
.import-bar {
  display: flex; align-items: center; justify-content: space-between;
  gap: 16px; flex-wrap: wrap; padding: 14px 20px;
  background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: var(--radius-xl); box-shadow: var(--shadow-xs); margin-bottom: 8px;
}
.import-bar-left { display: flex; align-items: flex-start; gap: 12px; }
.import-icon { width: 20px; height: 20px; color: var(--color-brand-base); flex-shrink: 0; margin-top: 2px; }
.import-title { font-size: var(--font-size-sm); font-weight: var(--font-weight-bold); color: var(--color-text); margin: 0 0 2px; }
.import-sub { font-size: var(--font-size-xs); color: var(--color-text-muted); margin: 0; }
.ext-link { color: var(--color-brand-base); text-decoration: underline; }
.import-bar-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

.feedback-msg { font-size: var(--font-size-sm); margin: 6px 0 12px; padding: 8px 14px; border-radius: var(--radius-lg); }
.success-msg { background: var(--color-success-light); color: var(--color-success); border: 1px solid rgba(16,137,129,0.2); }

/* ── Metric pills ────────────────────────────────────────────────────────── */
.metrics-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.metric-pill {
  display: flex; align-items: center; gap: 10px; padding: 10px 18px;
  background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: var(--radius-xl); box-shadow: var(--shadow-xs);
}
.metric-pill.green { border-color: var(--color-success); background: var(--color-success-light); }
.metric-pill.muted { background: var(--color-neutral-100, #F9FAFB); }
.metric-num { font-size: var(--font-size-xl); font-weight: var(--font-weight-bold); color: var(--color-text); line-height: 1; }
.metric-pill.green .metric-num { color: var(--color-success); }
.metric-pill.muted  .metric-num { color: var(--color-text-muted); }
.metric-label { font-size: var(--font-size-sm); color: var(--color-text-muted); }

/* ── Holiday table rows ──────────────────────────────────────────────────── */
.cell-date   { white-space: nowrap; }
.date-label  { font-size: var(--font-size-sm); font-weight: var(--font-weight-semibold); color: var(--color-text); }
.date-label.past  { color: var(--color-text-muted); }
.date-label.today { color: var(--color-brand-base); }
.cell-local  { color: var(--color-text-muted); font-style: italic; }
.today-chip {
  display: inline-block; margin-left: 8px; padding: 1px 7px;
  background: var(--color-brand-base); color: #fff; border-radius: var(--radius-full);
  font-size: 10px; font-weight: var(--font-weight-bold); letter-spacing: 0.04em; vertical-align: middle;
}
.row-past td     { opacity: 0.65; }
.row-today       { background: var(--color-brand-subtle); }
.row-inactive td { opacity: 0.45; text-decoration: line-through; }
</style>
