<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug, getToken } from "../stores/auth";

// ── Types ──────────────────────────────────────────────────────────────────────
interface Period {
  id: number; name: string; start_date: string; end_date: string;
  payment_date: string | null; status: string; runs_count?: number;
}
interface Run {
  id: number; employee_id: number; status: string;
  basic_salary: number; house_allowance: number; transport_allowance: number;
  other_earnings: number; gross_pay: number; total_deductions: number;
  paye: number; shif: number; nssf_tier1: number; nssf_tier2: number;
  housing_levy: number; nita: number; personal_relief: number;
  insurance_relief: number; paye_payable: number; net_pay: number;
  employee?: { id: number; full_name: string; employee_number: string;
    tax_id?: string; nssf_number?: string;
    department?: { name: string }; designation?: { title: string }; };
}
interface PeriodDetail extends Period { runs: Run[]; }
interface P9Row {
  employee: { id: number; full_name: string; employee_number: string; tax_id?: string; nssf_number?: string; };
  months: number; gross_pay: number; taxable_income: number;
  paye: number; personal_relief: number; insurance_relief: number;
  paye_payable: number; shif: number; nssf_tier1: number; nssf_tier2: number;
  housing_levy: number; net_pay: number;
}

// ── State ──────────────────────────────────────────────────────────────────────
type Tab = "periods" | "history" | "p9";
const tab = ref<Tab>("periods");

const periods        = ref<Period[]>([]);
const periodsTotal   = ref(0);
const periodsPage    = ref(1);
const periodsLoading = ref(false);
const periodsError   = ref("");

const selectedPeriod = ref<PeriodDetail | null>(null);
const detailLoading  = ref(false);

const showCreate  = ref(false);
const createForm  = ref({ name: "", start_date: "", end_date: "", payment_date: "" });
const creating    = ref(false);
const createError = ref("");

const history        = ref<any[]>([]);
const historyLoading = ref(false);

const p9Year    = ref<number>(new Date().getFullYear());
const p9Data    = ref<P9Row[]>([]);
const p9Loading = ref(false);
const p9Error   = ref("");

const actionMsg   = ref("");
const actionError = ref("");

const showSlip = ref(false);
const slipRun  = ref<Run | null>(null);

/*
 * Deduction labels, built server-side from the rates the period was computed
 * under. They used to be typed into the template — "first KES 7,000", "next
 * KES 29,000" — and the moment the NSSF limits were corrected the payslip
 * described a calculation nobody had performed.
 */
const rates = ref<Record<string, any>>({});

/*
 * What has already gone out for this period. Without it the screen reads the
 * same before and after a send, and the natural answer to "did that work?" is
 * to press it again.
 */
const delivery = ref<{ sent: number; held: number; failed: number; last_sent: string | null } | null>(null);
const slipDownloading = ref(false);
const slipError = ref("");

/**
 * The PDF, which is encrypted before it exists.
 *
 * Fetched rather than linked so the 422 naming an employee with no ID on file
 * is shown here, instead of the browser opening a blank tab.
 */
async function downloadSlip(): Promise<void> {
  if (!slipRun.value || !selectedPeriod.value) return;
  slipDownloading.value = true; slipError.value = "";
  try {
    const res = await fetch(
      `${import.meta.env.VITE_LARAVEL_API_URL ?? ""}/api/v1/payroll/${selectedPeriod.value.id}`
      + `/slip/${slipRun.value.employee_id}/pdf?org=${org() ?? ""}`,
      { headers: { Authorization: `Bearer ${getToken() ?? ""}`, "X-Organization": org() ?? "" } },
    );

    if (!res.ok) {
      const body = await res.json().catch(() => ({}));
      slipError.value = body.message ?? "Could not build the payslip.";
      return;
    }

    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `Payslip-${slipRun.value.employee?.full_name ?? "employee"}.pdf`;
    a.click();
    URL.revokeObjectURL(url);
  } catch {
    slipError.value = "Could not reach the server.";
  } finally {
    slipDownloading.value = false;
  }
}

function org(): string | null { return currentOrgSlug.value; }

// ── Formatting ─────────────────────────────────────────────────────────────────
function fmt(n: number | string | null | undefined): string {
  return new Intl.NumberFormat("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n ?? 0));
}
function statusBadge(s: string): string {
  return { draft: "badge-gray", processing: "badge-blue", approved: "badge-green", paid: "badge-teal" }[s] ?? "badge-gray";
}
function onStartDateChange(): void {
  if (createForm.value.start_date && !createForm.value.name) {
    const d = new Date(createForm.value.start_date);
    createForm.value.name = d.toLocaleDateString("en-KE", { month: "long", year: "numeric" });
  }
}

// ── Loaders ────────────────────────────────────────────────────────────────────
async function loadPeriods(page = 1): Promise<void> {
  if (!org()) return;
  periodsLoading.value = true; periodsError.value = ""; periodsPage.value = page;
  try {
    const res = await api<any>("GET", "/api/v1/payroll", { org: org(), params: { page, per_page: 15 } });
    periods.value      = res.data ?? [];
    periodsTotal.value = res.meta?.total ?? res.total ?? 0;
  } catch (e) {
    if (!(e instanceof ApiError && e.status === 403))
      periodsError.value = e instanceof Error ? e.message : "Failed.";
  }
  finally { periodsLoading.value = false; }
}

async function loadDetail(id: number): Promise<void> {
  detailLoading.value = true;
  try {
    const detail = await api<PeriodDetail & { rates?: Record<string, any>; delivery?: any }>(
      "GET", `/api/v1/payroll/${id}`, { org: org() },
    );
    selectedPeriod.value = detail;
    rates.value = detail.rates ?? {};
    delivery.value = detail.delivery ?? null;
  } catch { /* silent */ }
  finally { detailLoading.value = false; }
}

async function loadHistory(): Promise<void> {
  historyLoading.value = true;
  try {
    const res = await api<any>("GET", "/api/v1/payroll/history", { org: org(), params: { per_page: 24 } });
    history.value = res.data ?? [];
  } catch { /* silent */ }
  finally { historyLoading.value = false; }
}

async function loadP9(): Promise<void> {
  if (!org()) return;
  p9Loading.value = true; p9Error.value = "";
  try {
    const res = await api<{ year: number; employees: P9Row[] }>("GET", "/api/v1/payroll/p9", {
      org: org(), params: { year: p9Year.value },
    });
    p9Data.value = res.employees ?? [];
  } catch (e) { p9Error.value = e instanceof Error ? e.message : "Failed."; }
  finally { p9Loading.value = false; }
}

// ── Actions ────────────────────────────────────────────────────────────────────
async function createPeriod(): Promise<void> {
  if (!createForm.value.name || !createForm.value.start_date || !createForm.value.end_date) {
    createError.value = "Name, start date, and end date are required."; return;
  }
  creating.value = true; createError.value = "";
  try {
    await api("POST", "/api/v1/payroll", {
      org: org(),
      body: { ...createForm.value, payment_date: createForm.value.payment_date || null },
    });
    showCreate.value = false;
    createForm.value = { name: "", start_date: "", end_date: "", payment_date: "" };
    void loadPeriods(1);
  } catch (e) {
    if (e instanceof ApiError) createError.value = e.firstError ?? e.message;
    else createError.value = e instanceof Error ? e.message : "Failed.";
  }
  finally { creating.value = false; }
}

async function doAction(url: string, msg: string): Promise<void> {
  actionMsg.value = ""; actionError.value = "";
  try {
    await api("POST", url, { org: org(), body: {} });
    actionMsg.value = msg;
    void loadPeriods(periodsPage.value);
    if (selectedPeriod.value) void loadDetail(selectedPeriod.value.id);
    setTimeout(() => { actionMsg.value = ""; }, 3500);
  } catch (e) { actionError.value = e instanceof Error ? e.message : "Failed."; }
}

function openSlip(run: Run): void {
  slipRun.value = run; showSlip.value = true; slipError.value = "";
}

// ── Summary ────────────────────────────────────────────────────────────────────
const runsSummary = computed(() => {
  const runs = selectedPeriod.value?.runs ?? [];
  return {
    count: runs.length,
    gross: runs.reduce((s, r) => s + Number(r.gross_pay ?? 0), 0),
    paye:  runs.reduce((s, r) => s + Number(r.paye_payable ?? 0), 0),
    shif:  runs.reduce((s, r) => s + Number(r.shif ?? 0), 0),
    nssf:  runs.reduce((s, r) => s + Number(r.nssf_tier1 ?? 0) + Number(r.nssf_tier2 ?? 0), 0),
    levy:  runs.reduce((s, r) => s + Number(r.housing_levy ?? 0), 0),
    net:   runs.reduce((s, r) => s + Number(r.net_pay ?? 0), 0),
  };
});

// ── Init ───────────────────────────────────────────────────────────────────────
function init(): void {
  selectedPeriod.value = null;
  void loadPeriods(1);
  void loadHistory();
}
onMounted(init);
watch(currentOrgSlug, init);
watch(tab, (t) => { if (t === "p9") void loadP9(); });
</script>

<template>
  <div class="view pr-view">

    <!-- Header -->
    <div class="pr-header">
      <div>
        <h2 class="pr-title">Payroll</h2>
        <p class="pr-subtitle">Kenya statutory deductions — PAYE · SHIF · NSSF · Housing Levy</p>
      </div>
      <button class="btn btn-primary btn-sm" @click="showCreate = !showCreate">
        {{ showCreate ? "✕ Cancel" : "+ New Period" }}
      </button>
    </div>

    <!-- Create form -->
    <div v-if="showCreate" class="pr-card" style="max-width:560px;margin-bottom:20px">
      <h3 class="pr-card-title">New Payroll Period</h3>
      <p v-if="createError" class="error">{{ createError }}</p>
      <div class="pr-form-grid">
        <div class="pr-form-field pr-form-field--full">
          <label>Period Name <span class="req">*</span></label>
          <input v-model="createForm.name" type="text" class="input" placeholder="e.g. April 2026" />
        </div>
        <div class="pr-form-field">
          <label>Start Date <span class="req">*</span></label>
          <input v-model="createForm.start_date" type="date" class="input" @change="onStartDateChange" />
        </div>
        <div class="pr-form-field">
          <label>End Date <span class="req">*</span></label>
          <input v-model="createForm.end_date" type="date" class="input" />
        </div>
        <div class="pr-form-field">
          <label>Payment Date</label>
          <input v-model="createForm.payment_date" type="date" class="input" />
        </div>
      </div>
      <button class="btn btn-primary btn-sm" :disabled="creating" @click="createPeriod">
        {{ creating ? "Creating…" : "Create Period" }}
      </button>
    </div>

    <!-- Tabs -->
    <div class="tabs" style="margin-bottom:20px">
      <button :class="['tab', { active: tab === 'periods' }]"  @click="tab = 'periods'">📅 Payroll Periods</button>
      <button :class="['tab', { active: tab === 'history' }]"  @click="tab = 'history'">🧾 My Pay History</button>
      <button :class="['tab', { active: tab === 'p9' }]"       @click="tab = 'p9'">📋 P9 Report</button>
    </div>

    <p v-if="actionMsg"   class="pr-success">{{ actionMsg }}</p>
    <p v-if="actionError" class="error">{{ actionError }}</p>

    <!-- ── PERIODS TAB ──────────────────────────────────────────────────────── -->
    <template v-if="tab === 'periods'">
      <p v-if="periodsLoading" class="status">Loading periods…</p>
      <p v-if="periodsError"   class="error">{{ periodsError }}</p>

      <div class="pr-card" v-if="!periodsLoading">
        <table class="pr-table">
          <thead>
            <tr>
              <th>Period</th><th>Start → End</th><th>Payment Date</th>
              <th class="text-right">Employees</th><th>Status</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in periods" :key="p.id"
                :class="{ 'pr-row-selected': selectedPeriod?.id === p.id }"
                style="cursor:pointer" @click="loadDetail(p.id)">
              <td class="pr-name">{{ p.name }}</td>
              <td class="pr-dates">{{ p.start_date }} → {{ p.end_date }}</td>
              <td class="pr-dates">{{ p.payment_date ?? "—" }}</td>
              <td class="text-right">{{ p.runs_count ?? "—" }}</td>
              <td><span :class="['badge', statusBadge(p.status)]">{{ p.status }}</span></td>
              <td @click.stop>
                <div class="pr-actions">
                  <button v-if="p.status === 'draft'"
                    class="btn btn-secondary btn-sm"
                    @click="doAction(`/api/v1/payroll/${p.id}/process`, 'Payroll processed!')">Run Payroll</button>
                  <button v-if="p.status === 'processing'"
                    class="btn btn-primary btn-sm"
                    @click="doAction(`/api/v1/payroll/${p.id}/approve`, 'Payroll approved!')">Approve</button>
                  <button v-if="p.status === 'approved'"
                    class="btn btn-sm pr-btn-teal"
                    @click="doAction(`/api/v1/payroll/${p.id}/mark-paid`, 'Marked as paid!')">Mark Paid</button>
                </div>
              </td>
            </tr>
            <tr v-if="!periods.length">
              <td colspan="6" class="empty">No payroll periods yet. Create one above.</td>
            </tr>
          </tbody>
        </table>
        <div class="pr-pagination">
          <span>{{ periodsTotal }} total</span>
          <button class="btn btn-ghost btn-sm" :disabled="periodsPage === 1" @click="loadPeriods(periodsPage - 1)">← Prev</button>
          <button class="btn btn-ghost btn-sm" :disabled="periods.length < 15" @click="loadPeriods(periodsPage + 1)">Next →</button>
        </div>
      </div>

      <!-- Period detail / runs -->
      <div v-if="selectedPeriod" class="pr-card" style="margin-top:20px">
        <div class="pr-detail-header">
          <div>
            <h3 class="pr-card-title" style="margin-bottom:2px">
              {{ selectedPeriod.name }}
              <span :class="['badge', statusBadge(selectedPeriod.status)]" style="margin-left:8px;font-size:0.8rem">{{ selectedPeriod.status }}</span>
            </h3>
            <p class="pr-subtitle">{{ selectedPeriod.start_date }} → {{ selectedPeriod.end_date }}</p>
          </div>
          <!--
            Only once the period is approved. A draft can still be recomputed
            and an emailed payslip cannot, so the button is not offered before
            the figures are settled.
          -->
          <div v-if="['approved','paid'].includes(selectedPeriod.status)" class="pr-send">
            <!--
              Reads "sent" once any have gone, so pressing it again is a
              deliberate act rather than the obvious next thing to try.
            -->
            <router-link
              :class="['btn', 'btn-sm', delivery?.sent ? 'pr-btn-teal' : 'btn-primary']"
              :to="{ name: 'payroll-send', params: { id: selectedPeriod.id } }"
            >{{ delivery?.sent ? "Pay-slips sent" : "Send Payslips" }}</router-link>

            <span v-if="delivery?.sent" class="pr-send-note">
              {{ delivery.sent }} sent{{ delivery.last_sent ? ` · ${delivery.last_sent.slice(0, 16)}` : "" }}
              <template v-if="delivery.held">· {{ delivery.held }} held</template>
              <template v-if="delivery.failed">· {{ delivery.failed }} failed</template>
            </span>
          </div>
          <span v-else class="pr-subtitle">Approve the period to send payslips.</span>
        </div>
        <p v-if="detailLoading" class="status">Loading runs…</p>

        <!-- Summary bar -->
        <div v-if="selectedPeriod.runs?.length" class="pr-summary-bar">
          <div class="pr-sum-item"><span class="pr-sum-label">Employees</span><span class="pr-sum-val">{{ runsSummary.count }}</span></div>
          <div class="pr-sum-item"><span class="pr-sum-label">Gross Pay</span><span class="pr-sum-val">{{ fmt(runsSummary.gross) }}</span></div>
          <div class="pr-sum-item pr-sum-deduct"><span class="pr-sum-label">PAYE</span><span class="pr-sum-val">{{ fmt(runsSummary.paye) }}</span></div>
          <div class="pr-sum-item pr-sum-deduct"><span class="pr-sum-label">SHIF</span><span class="pr-sum-val">{{ fmt(runsSummary.shif) }}</span></div>
          <div class="pr-sum-item pr-sum-deduct"><span class="pr-sum-label">NSSF</span><span class="pr-sum-val">{{ fmt(runsSummary.nssf) }}</span></div>
          <div class="pr-sum-item pr-sum-deduct"><span class="pr-sum-label">Hsg Levy</span><span class="pr-sum-val">{{ fmt(runsSummary.levy) }}</span></div>
          <div class="pr-sum-item pr-sum-net"><span class="pr-sum-label">Net Pay</span><span class="pr-sum-val">{{ fmt(runsSummary.net) }}</span></div>
        </div>

        <!-- Runs table -->
        <div class="table-scroll" v-if="selectedPeriod.runs?.length">
          <table class="pr-table pr-runs-table">
            <thead>
              <tr>
                <th>Employee</th>
                <th class="text-right">Gross</th>
                <th class="text-right">PAYE</th>
                <th class="text-right">SHIF</th>
                <th class="text-right">NSSF T1</th>
                <th class="text-right">NSSF T2</th>
                <th class="text-right">Hsg Levy</th>
                <th class="text-right">Net Pay</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in selectedPeriod.runs" :key="r.id">
                <td>
                  <div class="pr-emp-cell">
                    <span class="pr-emp-name">{{ r.employee?.full_name ?? `Employee #${r.employee_id}` }}</span>
                    <span class="pr-emp-sub">{{ r.employee?.employee_number }} · {{ r.employee?.department?.name ?? "" }}</span>
                  </div>
                </td>
                <td class="text-right pr-num">{{ fmt(r.gross_pay) }}</td>
                <td class="text-right pr-num pr-deduct">{{ fmt(r.paye_payable) }}</td>
                <td class="text-right pr-num pr-deduct">{{ fmt(r.shif) }}</td>
                <td class="text-right pr-num pr-deduct">{{ fmt(r.nssf_tier1) }}</td>
                <td class="text-right pr-num pr-deduct">{{ fmt(r.nssf_tier2) }}</td>
                <td class="text-right pr-num pr-deduct">{{ fmt(r.housing_levy) }}</td>
                <td class="text-right pr-num pr-net">{{ fmt(r.net_pay) }}</td>
                <td><span :class="['badge', statusBadge(r.status)]">{{ r.status }}</span></td>
                <td>
                  <button class="btn btn-ghost btn-sm" style="font-size:0.75rem" @click="openSlip(r)">Payslip</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else-if="!detailLoading" class="empty">No runs yet — click "Run Payroll" to compute.</p>
      </div>
    </template>

    <!-- ── PAY HISTORY TAB ─────────────────────────────────────────────────── -->
    <template v-if="tab === 'history'">
      <p v-if="historyLoading" class="status">Loading…</p>
      <div class="pr-card">
        <table class="pr-table">
          <thead>
            <tr>
              <th>Period</th>
              <th class="text-right">Gross</th>
              <th class="text-right">PAYE</th>
              <th class="text-right">SHIF</th>
              <th class="text-right">NSSF</th>
              <th class="text-right">Net Pay</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in history" :key="r.id">
              <td class="pr-name">{{ r.period?.name ?? "—" }}</td>
              <td class="text-right pr-num">{{ fmt(r.gross_pay) }}</td>
              <td class="text-right pr-num pr-deduct">{{ fmt(r.paye_payable) }}</td>
              <td class="text-right pr-num pr-deduct">{{ fmt(r.shif) }}</td>
              <td class="text-right pr-num pr-deduct">{{ fmt(Number(r.nssf_tier1) + Number(r.nssf_tier2)) }}</td>
              <td class="text-right pr-num pr-net">{{ fmt(r.net_pay) }}</td>
              <td><span :class="['badge', statusBadge(r.status)]">{{ r.status }}</span></td>
              <td><button class="btn btn-ghost btn-sm" style="font-size:0.75rem" @click="openSlip(r)">Payslip</button></td>
            </tr>
            <tr v-if="!history.length && !historyLoading">
              <td colspan="8" class="empty">No pay history found.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── P9 TAB ──────────────────────────────────────────────────────────── -->
    <template v-if="tab === 'p9'">
      <div class="pr-p9-toolbar">
        <div>
          <h3 class="pr-card-title" style="margin:0">P9 Tax Deduction Card</h3>
          <p class="pr-subtitle" style="margin:0">Annual PAYE summary for KRA compliance</p>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
          <label style="font-size:0.85rem;font-weight:600">Year</label>
          <input v-model.number="p9Year" type="number" class="input" style="width:90px;padding:6px" min="2020" max="2030" />
          <button class="btn btn-primary btn-sm" :disabled="p9Loading" @click="loadP9">
            {{ p9Loading ? "Loading…" : "Generate" }}
          </button>
        </div>
      </div>
      <p v-if="p9Error" class="error">{{ p9Error }}</p>
      <div class="pr-card table-scroll">
        <table class="pr-table pr-p9-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Employee</th>
              <th>KRA PIN</th>
              <th>NSSF No.</th>
              <th class="text-right">Mths</th>
              <th class="text-right">Gross Pay</th>
              <th class="text-right">Taxable</th>
              <th class="text-right">Gross PAYE</th>
              <th class="text-right">Personal Relief</th>
              <th class="text-right">Ins. Relief</th>
              <th class="text-right">PAYE Payable</th>
              <th class="text-right">SHIF</th>
              <th class="text-right">NSSF (T1+T2)</th>
              <th class="text-right">Hsg Levy</th>
              <th class="text-right">Net Pay</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, i) in p9Data" :key="row.employee.id">
              <td style="color:#94a3b8;font-size:0.8rem">{{ i + 1 }}</td>
              <td>
                <div class="pr-emp-cell">
                  <span class="pr-emp-name">{{ row.employee.full_name }}</span>
                  <span class="pr-emp-sub">{{ row.employee.employee_number }}</span>
                </div>
              </td>
              <td class="pr-mono">{{ row.employee.tax_id ?? "—" }}</td>
              <td class="pr-mono">{{ row.employee.nssf_number ?? "—" }}</td>
              <td class="text-right">{{ row.months }}</td>
              <td class="text-right pr-num">{{ fmt(row.gross_pay) }}</td>
              <td class="text-right pr-num">{{ fmt(row.taxable_income) }}</td>
              <td class="text-right pr-num pr-deduct">{{ fmt(row.paye) }}</td>
              <td class="text-right pr-num">{{ fmt(row.personal_relief) }}</td>
              <td class="text-right pr-num">{{ fmt(row.insurance_relief) }}</td>
              <td class="text-right pr-num pr-deduct" style="font-weight:700">{{ fmt(row.paye_payable) }}</td>
              <td class="text-right pr-num pr-deduct">{{ fmt(row.shif) }}</td>
              <td class="text-right pr-num pr-deduct">{{ fmt(Number(row.nssf_tier1) + Number(row.nssf_tier2)) }}</td>
              <td class="text-right pr-num pr-deduct">{{ fmt(row.housing_levy) }}</td>
              <td class="text-right pr-num pr-net">{{ fmt(row.net_pay) }}</td>
            </tr>
            <tr v-if="!p9Data.length && !p9Loading">
              <td colspan="15" class="empty">No approved/paid payroll for {{ p9Year }}. Process and approve periods first.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── PAYSLIP MODAL ───────────────────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showSlip && slipRun" class="comp-overlay" @click.self="showSlip = false">
        <div class="pr-slip" role="dialog" aria-modal="true">
          <div class="pr-slip-header">
            <div>
              <h3 class="pr-slip-title">Pay Slip</h3>
              <p class="pr-slip-sub">{{ slipRun.employee?.full_name ?? `Employee #${slipRun.employee_id}` }}</p>
              <p class="pr-slip-meta">{{ slipRun.employee?.designation?.title ?? "" }}{{ slipRun.employee?.department?.name ? ` · ${slipRun.employee.department.name}` : "" }}</p>
              <p class="pr-slip-meta">KRA: {{ slipRun.employee?.tax_id ?? "—" }} · NSSF: {{ slipRun.employee?.nssf_number ?? "—" }}</p>
            </div>
            <button class="comp-popup-close" @click="showSlip = false">✕</button>
          </div>
          <div class="pr-slip-body">
            <!-- Earnings -->
            <table class="pr-slip-table">
              <thead><tr><th colspan="2" class="pr-slip-section-head">EARNINGS</th></tr></thead>
              <tbody>
                <tr><td>Basic Salary</td><td class="text-right">{{ fmt(slipRun.basic_salary) }}</td></tr>
                <tr v-if="Number(slipRun.house_allowance) > 0">
                  <td>House Allowance</td><td class="text-right">{{ fmt(slipRun.house_allowance) }}</td>
                </tr>
                <tr v-if="Number(slipRun.transport_allowance) > 0">
                  <td>Transport Allowance</td><td class="text-right">{{ fmt(slipRun.transport_allowance) }}</td>
                </tr>
                <tr v-if="Number(slipRun.other_earnings) > 0">
                  <td>Other Earnings</td><td class="text-right">{{ fmt(slipRun.other_earnings) }}</td>
                </tr>
                <tr class="pr-slip-total">
                  <td>Gross Pay</td><td class="text-right">KES {{ fmt(slipRun.gross_pay) }}</td>
                </tr>
              </tbody>
            </table>

            <!-- Deductions -->
            <table class="pr-slip-table" style="margin-top:16px">
              <thead><tr><th colspan="2" class="pr-slip-section-head">STATUTORY DEDUCTIONS</th></tr></thead>
              <tbody>
                <tr>
                  <td>
                    PAYE
                    <span class="pr-slip-relief">
                      (Gross tax: {{ fmt(slipRun.paye) }} − Personal Relief: {{ fmt(slipRun.personal_relief) }}<span v-if="Number(slipRun.insurance_relief) > 0"> − Ins. Relief: {{ fmt(slipRun.insurance_relief) }}</span>)
                    </span>
                  </td>
                  <td class="text-right">{{ fmt(slipRun.paye_payable) }}</td>
                </tr>
                <tr><td>{{ rates.shif ?? 'SHIF' }}</td><td class="text-right">{{ fmt(slipRun.shif) }}</td></tr>
                <tr><td>{{ rates.nssf_tier1 ?? 'NSSF Tier I' }}</td><td class="text-right">{{ fmt(slipRun.nssf_tier1) }}</td></tr>
                <tr v-if="Number(slipRun.nssf_tier2) > 0">
                  <td>{{ rates.nssf_tier2 ?? 'NSSF Tier II' }}</td><td class="text-right">{{ fmt(slipRun.nssf_tier2) }}</td></tr>
                <tr><td>{{ rates.housing_levy ?? 'Affordable Housing Levy' }}</td><td class="text-right">{{ fmt(slipRun.housing_levy) }}</td></tr>
                <!--
                  NITA appears only where the organisation has chosen to pass it
                  on. It is the employer's levy, and a line for money the
                  employee did not pay starts an argument it cannot settle.
                -->
                <tr v-if="rates.nita_on_employee">
                  <td>NITA training levy</td><td class="text-right">{{ fmt(slipRun.nita) }}</td>
                </tr>
                <tr class="pr-slip-total">
                  <td>Total Deductions</td><td class="text-right">KES {{ fmt(slipRun.total_deductions) }}</td>
                </tr>
              </tbody>
            </table>

            <div class="pr-slip-net">
              <span>NET PAY</span>
              <span>KES {{ fmt(slipRun.net_pay) }}</span>
            </div>

            <!-- Employer cost, kept clear of the deductions above: it is what
                 the statutory returns are filed on, not money off this payslip. -->
            <p v-if="!rates.nita_on_employee && Number(slipRun.nita) > 0" class="pr-slip-meta" style="margin-top:10px">
              {{ rates.nita ?? 'NITA training levy (employer)' }}: KES {{ fmt(slipRun.nita) }} —
              paid by the company, not deducted from this payslip.
            </p>

            <p v-if="slipError" class="error" style="margin-top:12px">{{ slipError }}</p>

            <div class="pr-slip-actions">
              <button class="btn btn-primary btn-sm" :disabled="slipDownloading" @click="downloadSlip">
                {{ slipDownloading ? "Preparing…" : "Download PDF" }}
              </button>
              <!-- Says what to type, never the password itself. -->
              <span class="pr-slip-meta">Opens with the employee's national ID.</span>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
</template>

<style scoped>
.pr-view { padding: 24px; max-width: 100%; }

/* Header */
.pr-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; gap: 12px; flex-wrap: wrap; }
.pr-title { font-size: 1.4rem; font-weight: 700; color: var(--color-text); margin: 0 0 4px; }
.pr-subtitle { font-size: 0.8rem; color: var(--color-text-muted); margin: 0; }
.pr-success { background: #f0fdf4; border: 1px solid #86efac; color: #15803d; padding: 8px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 12px; }

/* Card */
.pr-card { background: #fff; border-radius: 10px; border: 1px solid var(--color-border); padding: 20px; }
.pr-slip-actions { display: flex; align-items: center; gap: 12px; margin-top: 18px;
  padding-top: 14px; border-top: 1px solid var(--color-border); }
.pr-send { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.pr-send-note { font-size: 0.74rem; color: var(--color-text-muted); }
.pr-card-title { font-size: 1rem; font-weight: 700; color: var(--color-text); margin: 0 0 14px; }
.req { color: var(--red); }

/* Form */
.pr-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
.pr-form-field label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 4px; color: #374151; }
.pr-form-field--full { grid-column: 1 / -1; }

/* Table */
.pr-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.pr-table th { text-align: left; padding: 9px 10px; background: #f8fafc; font-size: 0.75rem; font-weight: 600; color: #64748b; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.pr-table td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.pr-table tbody tr:hover { background: #f8fafc; }
.pr-table tbody tr:last-child td { border-bottom: none; }
.pr-row-selected td { background: var(--color-brand-light) !important; }
.pr-name { font-weight: 600; }
.pr-dates { font-size: 0.78rem; color: #64748b; }
.pr-num { font-family: 'SF Mono', ui-monospace, monospace; font-size: 0.82rem; }
.pr-deduct { color: #dc2626; }
.pr-net { font-weight: 700; color: var(--color-brand-base); }
.pr-mono { font-family: monospace; font-size: 0.8rem; }
.table-scroll { overflow-x: auto; }
.pr-runs-table th, .pr-runs-table td { padding: 7px 8px; }
.pr-actions { display: flex; gap: 6px; }
.pr-pagination { display: flex; align-items: center; gap: 8px; padding-top: 10px; font-size: 0.8rem; color: #64748b; }

/* Summary bar */
.pr-summary-bar { display: flex; flex-wrap: wrap; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 16px; }
.pr-sum-item { flex: 1; min-width: 90px; padding: 10px 14px; border-right: 1px solid #e2e8f0; }
.pr-sum-item:last-child { border-right: none; }
.pr-sum-deduct { background: #fef2f2; }
.pr-sum-net { background: var(--color-brand-light); }
.pr-sum-label { display: block; font-size: 0.7rem; color: #64748b; font-weight: 600; margin-bottom: 2px; text-transform: uppercase; }
.pr-sum-val { font-size: 0.88rem; font-weight: 700; color: var(--color-text); font-family: monospace; }

/* Employee cell */
.pr-emp-cell { display: flex; flex-direction: column; }
.pr-emp-name { font-weight: 600; font-size: 0.85rem; }
.pr-emp-sub { font-size: 0.72rem; color: #94a3b8; }

/* Period detail */
.pr-detail-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 16px; }

/* P9 toolbar */
.pr-p9-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
.pr-p9-table th, .pr-p9-table td { padding: 6px 8px; font-size: 0.78rem; }

/* Buttons */
.pr-btn-teal { background: #0d9488; color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.pr-btn-teal:hover { background: #0f766e; }

/* Payslip modal */
.comp-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 999; display: flex; align-items: center; justify-content: center; padding: 16px; }
.pr-slip { background: #fff; border-radius: 12px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
.pr-slip-header { display: flex; align-items: flex-start; justify-content: space-between; padding: 20px 22px 14px; border-bottom: 1px solid #e2e8f0; gap: 12px; }
.pr-slip-title { font-size: 1.1rem; font-weight: 700; margin: 0 0 2px; color: var(--color-brand-base); }
.pr-slip-sub { font-size: 0.95rem; font-weight: 600; color: #1e293b; margin: 0; }
.pr-slip-meta { font-size: 0.75rem; color: #64748b; margin: 2px 0 0; }
.comp-popup-close { background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #94a3b8; padding: 2px 6px; border-radius: 4px; }
.comp-popup-close:hover { background: #f1f5f9; color: #1e293b; }
.pr-slip-body { padding: 18px 22px; }
.pr-slip-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.pr-slip-section-head { padding: 6px 8px; background: #f8fafc; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; color: #64748b; text-align: left; border-bottom: 1px solid #e2e8f0; }
.pr-slip-table td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; }
.pr-slip-total td { font-weight: 700; background: #f8fafc; font-size: 0.88rem; }
.pr-slip-relief { font-size: 0.72rem; color: #94a3b8; margin-left: 4px; }
.pr-slip-net { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding: 14px 12px; background: var(--color-brand-base); color: #fff; border-radius: 8px; font-weight: 700; font-size: 1.05rem; }
</style>
