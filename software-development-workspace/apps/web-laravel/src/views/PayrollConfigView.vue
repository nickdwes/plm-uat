<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import { useRouter } from "vue-router";

const router = useRouter();
const org = () => currentOrgSlug.value!;

// ── UI State ─────────────────────────────────────────────────────────────────
const tab     = ref<"bands" | "rates" | "earnings" | "deductions" | "documents">("bands");
const loading = ref(false);
const saving  = ref(false);
const error   = ref("");
const success  = ref("");

const docs        = ref<DocumentSettings | null>(null);
const docSources  = ref<Record<string, string>>({});
const docCases    = ref<string[]>([]);
const docSummary  = ref("");
/*
 * Employees for whom no password could be built. Answered here rather than at
 * send time, because a missing ID is a record somebody fixes in a minute — and
 * finding out mid-run means half the company paid and half not.
 */
const unreachable = ref<{ id: number; name: string; employee_number: string }[]>([]);

async function loadDocuments(): Promise<void> {
  try {
    const res = await api<{
      data: DocumentSettings; sources: Record<string, string>; cases: string[];
      summary: string; unreachable: typeof unreachable.value;
    }>("GET", "/api/v1/payroll-config/document-settings", { org: org() });
    docs.value = res.data;
    docSources.value = res.sources;
    docCases.value = res.cases;
    docSummary.value = res.summary;
    unreachable.value = res.unreachable ?? [];
  } catch (e) {
    error.value = "Could not load payslip security settings.";
  }
}

async function saveDocuments(): Promise<void> {
  if (!docs.value) return;
  saving.value = true; error.value = "";
  try {
    const res = await api<{ data: DocumentSettings; summary: string; unreachable: typeof unreachable.value }>(
      "PUT", "/api/v1/payroll-config/document-settings", { org: org(), body: docs.value },
    );
    docs.value = res.data;
    docSummary.value = res.summary;
    unreachable.value = res.unreachable ?? [];
    showSuccess("Payslip security saved.");
  } catch (e) {
    error.value = "Could not save. Check the fields and try again.";
  } finally { saving.value = false; }
}

const caseLabels: Record<string, string> = {
  upper: "Capitals", lower: "Lower case", as_recorded: "Exactly as recorded",
};

function showSuccess(msg: string) {
  success.value = msg;
  error.value = "";
  setTimeout(() => (success.value = ""), 3000);
}

// ── Types ─────────────────────────────────────────────────────────────────────
interface TaxBand {
  id?: number; band_order: number; lower_limit: number; upper_limit: number | null; rate: number;
}
interface StatutoryRate {
  param_key: string; value: number; description: string; effective_date: string | null; from_db: boolean;
}
/*
 * How payslips are locked. There is deliberately no "protected" switch: the
 * only question is what the password is derived from.
 */
interface DocumentSettings {
  password_source: string;
  password_fallback_source: string | null;
  password_prefix: string | null;
  password_suffix: string | null;
  password_case: string;
  allow_print: boolean;
  allow_copy: boolean;
}

interface EarningCode {
  id?: number; code: string; name: string;
  is_taxable: boolean; is_pensionable: boolean; is_shif_applicable: boolean; is_nssf_applicable: boolean; is_active: boolean;
  _editing?: boolean;
}
interface DeductionCode {
  id?: number; code: string; name: string;
  deduction_type: string; priority: number; calculation_type: string;
  max_amount: number | null; recover_balance: boolean; is_active: boolean;
  _editing?: boolean;
}

// ── Data ──────────────────────────────────────────────────────────────────────
const taxYear    = ref(new Date().getFullYear());
const taxBands   = ref<TaxBand[]>([]);
const rates      = ref<Record<string, StatutoryRate>>({});
const earnings   = ref<EarningCode[]>([]);
const deductions = ref<DeductionCode[]>([]);

const effectiveDateBands = ref(taxYear.value + "-01-01");
const effectiveDateRates = ref(new Date().toISOString().slice(0, 10));

// ── Load ──────────────────────────────────────────────────────────────────────
async function load() {
  loading.value = true; error.value = "";
  try {
    const data = await api<any>("GET", "/api/v1/payroll-config", { org: org() });
    taxYear.value = data.tax_year;
    taxBands.value = (data.tax_bands ?? []).map((b: any) => ({
      id: b.id, band_order: b.band_order, lower_limit: b.lower_limit ?? 0,
      upper_limit: b.upper_limit ?? null, rate: b.rate ?? 0,
    }));
    rates.value = data.statutory_rates ?? {};
    earnings.value = (data.earning_codes ?? []).map((e: any) => ({ ...e, _editing: false }));
    deductions.value = (data.deduction_codes ?? []).map((d: any) => ({ ...d, _editing: false }));
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load config.";
  } finally {
    loading.value = false;
  }
}

onMounted(() => { void load(); void loadDocuments(); });

// ── Seed Defaults ─────────────────────────────────────────────────────────────
const seeding = ref(false);
async function seedDefaults() {
  seeding.value = true;
  try {
    const res = await api<any>("POST", "/api/v1/payroll-config/seed-defaults", { org: org() });
    showSuccess(res.message ?? "Defaults seeded.");
    await load();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Seed failed.";
  } finally {
    seeding.value = false;
  }
}

// ── Tax Bands ─────────────────────────────────────────────────────────────────
function addBand() {
  const lastOrder = taxBands.value.length ? Math.max(...taxBands.value.map(b => b.band_order)) : 0;
  taxBands.value.push({ band_order: lastOrder + 1, lower_limit: 0, upper_limit: null, rate: 0 });
}
function removeBand(i: number) { taxBands.value.splice(i, 1); }

async function saveBands() {
  saving.value = true; error.value = "";
  try {
    await api("PUT", "/api/v1/payroll-config/tax-bands", {
      org: org(),
      body: {
        tax_year: taxYear.value,
        effective_date: effectiveDateBands.value,
        bands: taxBands.value.map((b, i) => ({ ...b, band_order: i + 1 })),
      },
    });
    showSuccess("PAYE tax bands saved.");
    await load();
  } catch (e) { error.value = e instanceof Error ? e.message : "Save failed."; }
  finally { saving.value = false; }
}

// ── Statutory Rates ───────────────────────────────────────────────────────────
const rateGroups = computed(() => [
  {
    title: "PAYE & Personal Relief",
    keys: ["personal_relief", "insurance_relief_rate", "insurance_relief_max"],
  },
  {
    title: "SHIF (Social Health Insurance Fund)",
    keys: ["shif_rate", "shif_min", "shif_max"],
  },
  {
    title: "NSSF",
    keys: ["nssf_tier1_ceiling", "nssf_tier2_ceiling", "nssf_employee_rate", "nssf_employer_rate"],
  },
  {
    title: "Affordable Housing Levy",
    keys: ["housing_levy_employee_rate", "housing_levy_employer_rate"],
  },
  {
    title: "NITA",
    keys: ["nita_amount"],
  },
]);

async function saveRates() {
  saving.value = true; error.value = "";
  try {
    await api("PUT", "/api/v1/payroll-config/statutory-rates", {
      org: org(),
      body: {
        effective_date: effectiveDateRates.value,
        rates: Object.values(rates.value).map(r => ({ param_key: r.param_key, value: r.value })),
      },
    });
    showSuccess("Statutory rates saved.");
    await load();
  } catch (e) { error.value = e instanceof Error ? e.message : "Save failed."; }
  finally { saving.value = false; }
}

// ── Earning Codes ─────────────────────────────────────────────────────────────
const newEarning = ref<Partial<EarningCode>>({});
const addingEarning = ref(false);

function startAddEarning() {
  newEarning.value = { code: "", name: "", is_taxable: true, is_pensionable: true, is_shif_applicable: true, is_nssf_applicable: true, is_active: true };
  addingEarning.value = true;
}

async function saveNewEarning() {
  saving.value = true; error.value = "";
  try {
    await api("POST", "/api/v1/payroll-config/earning-codes", { org: org(), body: newEarning.value });
    addingEarning.value = false;
    showSuccess("Earning code added.");
    await load();
  } catch (e) { error.value = e instanceof Error ? e.message : "Save failed."; }
  finally { saving.value = false; }
}

async function saveEarning(code: EarningCode) {
  saving.value = true; error.value = "";
  try {
    await api("PUT", `/api/v1/payroll-config/earning-codes/${code.id}`, { org: org(), body: code });
    code._editing = false;
    showSuccess("Earning code updated.");
  } catch (e) { error.value = e instanceof Error ? e.message : "Save failed."; }
  finally { saving.value = false; }
}

async function deleteEarning(code: EarningCode) {
  if (!confirm(`Delete earning code "${code.code}"?`)) return;
  try {
    await api("DELETE", `/api/v1/payroll-config/earning-codes/${code.id}`, { org: org() });
    showSuccess("Earning code deleted.");
    await load();
  } catch (e) { error.value = e instanceof Error ? e.message : "Delete failed."; }
}

// ── Deduction Codes ───────────────────────────────────────────────────────────
const newDeduction = ref<Partial<DeductionCode>>({});
const addingDeduction = ref(false);

function startAddDeduction() {
  newDeduction.value = { code: "", name: "", deduction_type: "checkoff", priority: 99, calculation_type: "fixed", max_amount: null, recover_balance: false, is_active: true };
  addingDeduction.value = true;
}

async function saveNewDeduction() {
  saving.value = true; error.value = "";
  try {
    await api("POST", "/api/v1/payroll-config/deduction-codes", { org: org(), body: newDeduction.value });
    addingDeduction.value = false;
    showSuccess("Deduction code added.");
    await load();
  } catch (e) { error.value = e instanceof Error ? e.message : "Save failed."; }
  finally { saving.value = false; }
}

async function saveDeduction(code: DeductionCode) {
  saving.value = true; error.value = "";
  try {
    await api("PUT", `/api/v1/payroll-config/deduction-codes/${code.id}`, { org: org(), body: code });
    code._editing = false;
    showSuccess("Deduction code updated.");
  } catch (e) { error.value = e instanceof Error ? e.message : "Save failed."; }
  finally { saving.value = false; }
}

async function deleteDeduction(code: DeductionCode) {
  if (!confirm(`Delete deduction code "${code.code}"?`)) return;
  try {
    await api("DELETE", `/api/v1/payroll-config/deduction-codes/${code.id}`, { org: org() });
    showSuccess("Deduction code deleted.");
    await load();
  } catch (e) { error.value = e instanceof Error ? e.message : "Delete failed."; }
}

const DEDUCTION_TYPES = ["statutory", "checkoff", "loan"];
const CALC_TYPES = ["fixed", "percentage"];

function formatKey(k: string) {
  return k.replace(/_/g, " ").replace(/\b\w/g, c => c.toUpperCase());
}
function formatRate(r: StatutoryRate) {
  if (r.param_key.endsWith("_rate")) return r.value + "%";
  return "KES " + Number(r.value).toLocaleString();
}
</script>

<template>
  <div class="pc-wrap">
    <!-- Header -->
    <div class="pc-header">
      <div>
        <button class="back-btn" @click="router.push('/org')">← Organisation</button>
        <h1 class="pc-title">Payroll Configuration</h1>
        <p class="pc-sub">Parameter-driven statutory deductions — Kenya FY {{ taxYear }}</p>
      </div>
      <button class="btn-seed" :disabled="seeding" @click="seedDefaults">
        {{ seeding ? "Seeding…" : "⚡ Seed Kenya Defaults" }}
      </button>
    </div>

    <!-- Alerts -->
    <div v-if="error"   class="alert-error"   style="margin-bottom:16px">{{ error }}</div>
    <div v-if="success" class="alert-success"  style="margin-bottom:16px">{{ success }}</div>

    <!-- Loading -->
    <div v-if="loading" class="pc-loading">Loading configuration…</div>

    <template v-else>
      <!-- Tabs -->
      <div class="pc-tabs">
        <button :class="['pc-tab', { active: tab === 'bands' }]"      @click="tab = 'bands'">PAYE Tax Bands</button>
        <button :class="['pc-tab', { active: tab === 'rates' }]"      @click="tab = 'rates'">Statutory Rates</button>
        <button :class="['pc-tab', { active: tab === 'earnings' }]"   @click="tab = 'earnings'">Earning Codes</button>
        <button :class="['pc-tab', { active: tab === 'deductions' }]" @click="tab = 'deductions'">Check-off Codes</button>
        <button :class="['pc-tab', { active: tab === 'documents' }]"  @click="tab = 'documents'">Payslip Security</button>
      </div>

      <!-- ══ PAYE TAX BANDS ══ -->
      <div v-if="tab === 'bands'" class="pc-card">
        <div class="pc-card-header">
          <div>
            <h2 class="pc-card-title">PAYE Tax Bands</h2>
            <p class="pc-card-sub">Progressive income tax bands for monthly gross pay. Rates apply to the income within each band.</p>
          </div>
          <div style="display:flex;gap:10px;align-items:center">
            <div class="pc-field-inline">
              <label>Tax Year</label>
              <input v-model.number="taxYear" type="number" class="pc-input" style="width:90px" />
            </div>
            <div class="pc-field-inline">
              <label>Effective Date</label>
              <input v-model="effectiveDateBands" type="date" class="pc-input" />
            </div>
          </div>
        </div>

        <table class="pc-table">
          <thead>
            <tr>
              <th>#</th>
              <th>From (KES)</th>
              <th>To (KES)</th>
              <th>Rate (%)</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(band, i) in taxBands" :key="i">
              <td class="pc-td-num">{{ i + 1 }}</td>
              <td><input v-model.number="band.lower_limit" type="number" min="0" class="pc-input pc-input-sm" /></td>
              <td>
                <input
                  :value="band.upper_limit ?? ''"
                  type="number" min="0" class="pc-input pc-input-sm"
                  placeholder="No ceiling"
                  @input="band.upper_limit = ($event.target as HTMLInputElement).value ? Number(($event.target as HTMLInputElement).value) : null"
                />
              </td>
              <td><input v-model.number="band.rate" type="number" min="0" max="100" step="0.5" class="pc-input pc-input-sm" /></td>
              <td>
                <button class="pc-btn-del" @click="removeBand(i)" title="Remove band">✕</button>
              </td>
            </tr>
            <tr v-if="taxBands.length === 0">
              <td colspan="5" class="pc-empty">No bands configured. Click "+ Add Band" or seed defaults.</td>
            </tr>
          </tbody>
        </table>

        <div class="pc-table-footer">
          <button class="btn-secondary" @click="addBand">+ Add Band</button>
          <button class="btn-primary" :disabled="saving" @click="saveBands">{{ saving ? "Saving…" : "Save Bands" }}</button>
        </div>

        <div class="pc-info-box">
          <strong>Deduction Priority Order:</strong>
          Gross Earnings → Pension → Taxable Pay → PAYE → SHIF → Housing Levy → NSSF → Court Orders → HELB → SACCO Loans → Other Check-offs → Recoveries → Net Pay
        </div>
      </div>

      <!-- ══ STATUTORY RATES ══ -->
      <div v-if="tab === 'rates'" class="pc-card">
        <div class="pc-card-header">
          <div>
            <h2 class="pc-card-title">Statutory Rates</h2>
            <p class="pc-card-sub">SHIF, NSSF, Housing Levy, Personal Relief, and NITA parameters. Changes take effect from the effective date.</p>
          </div>
          <div class="pc-field-inline">
            <label>Effective Date</label>
            <input v-model="effectiveDateRates" type="date" class="pc-input" />
          </div>
        </div>

        <div v-for="group in rateGroups" :key="group.title" class="pc-rate-group">
          <h3 class="pc-rate-group-title">{{ group.title }}</h3>
          <div class="pc-rate-grid">
            <template v-for="key in group.keys" :key="key">
              <div v-if="rates[key]" class="pc-rate-item">
                <label class="pc-rate-label">
                  {{ formatKey(key) }}
                  <span class="pc-rate-badge" v-if="rates[key].from_db">DB</span>
                  <span class="pc-rate-badge pc-rate-badge-default" v-else>Default</span>
                </label>
                <p class="pc-rate-desc">{{ rates[key].description }}</p>
                <input v-model.number="rates[key].value" type="number" step="0.01" min="0" class="pc-input" />
                <p class="pc-rate-hint">Current: {{ formatRate(rates[key]) }}</p>
              </div>
            </template>
          </div>
        </div>

        <div class="pc-table-footer" style="margin-top:24px">
          <button class="btn-primary" :disabled="saving" @click="saveRates">{{ saving ? "Saving…" : "Save Statutory Rates" }}</button>
        </div>
      </div>

      <!-- ══ PAYSLIP SECURITY ══ -->
      <div v-if="tab === 'documents' && docs" class="pc-card">
        <div class="pc-card-header">
          <div>
            <h2 class="pc-card-title">Payslip Security</h2>
            <!--
              No "protect payslips" switch, on purpose. A payslip carries a
              salary, a tax number and bank details, goes to a personal address
              and is forwarded from there — so the only question is what the
              password is, never whether there is one.
            -->
            <p class="pc-card-sub">
              Every payslip PDF is encrypted, whether downloaded or emailed. These settings
              decide what opens it. Where no password can be built the payslip is held back
              rather than sent unlocked.
            </p>
          </div>
        </div>

        <div class="pc-rate-grid">
          <div class="pc-rate-item">
            <label class="pc-rate-label">Password from</label>
            <p class="pc-rate-desc">Something the employee already knows without being told.</p>
            <select v-model="docs.password_source" class="pc-input">
              <option v-for="(label, key) in docSources" :key="key" :value="key">{{ label }}</option>
            </select>
          </div>

          <div class="pc-rate-item">
            <label class="pc-rate-label">If that is missing</label>
            <p class="pc-rate-desc">
              Records are incomplete in practice. Leave blank to hold the payslip back
              instead of falling to a second source.
            </p>
            <select v-model="docs.password_fallback_source" class="pc-input">
              <option :value="null">Hold the payslip back</option>
              <option v-for="(label, key) in docSources" :key="key" :value="key">{{ label }}</option>
            </select>
          </div>

          <div class="pc-rate-item">
            <label class="pc-rate-label">Prefix</label>
            <p class="pc-rate-desc">Optional, e.g. KNS-</p>
            <input v-model="docs.password_prefix" type="text" maxlength="16" class="pc-input" />
          </div>

          <div class="pc-rate-item">
            <label class="pc-rate-label">Suffix</label>
            <p class="pc-rate-desc">Optional.</p>
            <input v-model="docs.password_suffix" type="text" maxlength="16" class="pc-input" />
          </div>

          <div class="pc-rate-item">
            <label class="pc-rate-label">Letter case</label>
            <p class="pc-rate-desc">People type what they were told, so pick one and keep it.</p>
            <select v-model="docs.password_case" class="pc-input">
              <option v-for="c in docCases" :key="c" :value="c">{{ caseLabels[c] ?? c }}</option>
            </select>
          </div>

          <div class="pc-rate-item">
            <label class="pc-rate-label">Once open, they may</label>
            <p class="pc-rate-desc">
              A payslip goes to landlords and banks, so printing is usually wanted;
              copying text out of it usually is not.
            </p>
            <label class="pc-check"><input v-model="docs.allow_print" type="checkbox" /> Print it</label>
            <label class="pc-check"><input v-model="docs.allow_copy" type="checkbox" /> Copy text from it</label>
          </div>
        </div>

        <!-- What a covering email may say. Never the password itself. -->
        <p class="pc-summary">
          Payslips will open with: <strong>{{ docSummary }}</strong>
        </p>

        <!--
          The list worth having. A payslip that cannot be built is a record
          somebody corrects now, not a run that stops halfway.
        -->
        <div v-if="unreachable.length" class="pc-warn">
          <strong>{{ unreachable.length }}</strong>
          {{ unreachable.length === 1 ? "employee has" : "employees have" }}
          nothing to build a password from and would be held back:
          <ul class="pc-warn-list">
            <li v-for="u in unreachable" :key="u.id">{{ u.name }} ({{ u.employee_number }})</li>
          </ul>
          Add the missing detail on their record before sending.
        </div>
        <p v-else class="pc-ok">Every active employee can be sent a payslip.</p>

        <div class="pc-table-footer" style="margin-top:24px">
          <button class="btn-primary" :disabled="saving" @click="saveDocuments">
            {{ saving ? "Saving…" : "Save Payslip Security" }}
          </button>
        </div>
      </div>

      <!-- ══ EARNING CODES ══ -->
      <div v-if="tab === 'earnings'" class="pc-card">
        <div class="pc-card-header">
          <div>
            <h2 class="pc-card-title">Earning Codes</h2>
            <p class="pc-card-sub">Define taxable and non-taxable earning types for payroll processing.</p>
          </div>
          <button class="btn-primary" @click="startAddEarning" v-if="!addingEarning">+ Add Earning</button>
        </div>

        <!-- Add new earning form -->
        <div v-if="addingEarning" class="pc-inline-form">
          <h4 class="pc-inline-form-title">New Earning Code</h4>
          <div class="pc-form-grid">
            <div class="pc-field">
              <label>Code</label>
              <input v-model="newEarning.code" type="text" class="pc-input" placeholder="e.g. BASIC" style="text-transform:uppercase" />
            </div>
            <div class="pc-field">
              <label>Name</label>
              <input v-model="newEarning.name" type="text" class="pc-input" placeholder="e.g. Basic Salary" />
            </div>
          </div>
          <div class="pc-check-row">
            <label class="pc-check"><input type="checkbox" v-model="newEarning.is_taxable" /> Taxable</label>
            <label class="pc-check"><input type="checkbox" v-model="newEarning.is_pensionable" /> Pensionable</label>
            <label class="pc-check"><input type="checkbox" v-model="newEarning.is_shif_applicable" /> SHIF Applicable</label>
            <label class="pc-check"><input type="checkbox" v-model="newEarning.is_nssf_applicable" /> NSSF Applicable</label>
            <label class="pc-check"><input type="checkbox" v-model="newEarning.is_active" /> Active</label>
          </div>
          <div class="pc-inline-form-actions">
            <button class="btn-secondary" @click="addingEarning = false">Cancel</button>
            <button class="btn-primary" :disabled="saving" @click="saveNewEarning">{{ saving ? "Saving…" : "Add" }}</button>
          </div>
        </div>

        <table class="pc-table">
          <thead>
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th class="pc-th-center">Taxable</th>
              <th class="pc-th-center">Pensionable</th>
              <th class="pc-th-center">SHIF</th>
              <th class="pc-th-center">NSSF</th>
              <th class="pc-th-center">Active</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="ec in earnings" :key="ec.id">
              <!-- View row -->
              <tr v-if="!ec._editing" class="pc-tr-hover">
                <td><span class="pc-code-badge">{{ ec.code }}</span></td>
                <td>{{ ec.name }}</td>
                <td class="pc-td-center"><span :class="ec.is_taxable ? 'pc-yes' : 'pc-no'">{{ ec.is_taxable ? "✓" : "—" }}</span></td>
                <td class="pc-td-center"><span :class="ec.is_pensionable ? 'pc-yes' : 'pc-no'">{{ ec.is_pensionable ? "✓" : "—" }}</span></td>
                <td class="pc-td-center"><span :class="ec.is_shif_applicable ? 'pc-yes' : 'pc-no'">{{ ec.is_shif_applicable ? "✓" : "—" }}</span></td>
                <td class="pc-td-center"><span :class="ec.is_nssf_applicable ? 'pc-yes' : 'pc-no'">{{ ec.is_nssf_applicable ? "✓" : "—" }}</span></td>
                <td class="pc-td-center"><span :class="ec.is_active ? 'pc-yes' : 'pc-no'">{{ ec.is_active ? "✓" : "—" }}</span></td>
                <td class="pc-td-actions">
                  <button class="pc-btn-edit" @click="ec._editing = true">Edit</button>
                  <button class="pc-btn-del" @click="deleteEarning(ec)">✕</button>
                </td>
              </tr>
              <!-- Edit row -->
              <tr v-else class="pc-tr-editing">
                <td><input v-model="ec.code" type="text" class="pc-input pc-input-xs" /></td>
                <td><input v-model="ec.name" type="text" class="pc-input pc-input-xs" /></td>
                <td class="pc-td-center"><input type="checkbox" v-model="ec.is_taxable" /></td>
                <td class="pc-td-center"><input type="checkbox" v-model="ec.is_pensionable" /></td>
                <td class="pc-td-center"><input type="checkbox" v-model="ec.is_shif_applicable" /></td>
                <td class="pc-td-center"><input type="checkbox" v-model="ec.is_nssf_applicable" /></td>
                <td class="pc-td-center"><input type="checkbox" v-model="ec.is_active" /></td>
                <td class="pc-td-actions">
                  <button class="pc-btn-save" :disabled="saving" @click="saveEarning(ec)">Save</button>
                  <button class="pc-btn-cancel" @click="ec._editing = false">Cancel</button>
                </td>
              </tr>
            </template>
            <tr v-if="earnings.length === 0">
              <td colspan="8" class="pc-empty">No earning codes. Click "+ Add Earning" or seed defaults.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- ══ DEDUCTION / CHECK-OFF CODES ══ -->
      <div v-if="tab === 'deductions'" class="pc-card">
        <div class="pc-card-header">
          <div>
            <h2 class="pc-card-title">Check-off & Deduction Codes</h2>
            <p class="pc-card-sub">SACCO loans, union dues, HELB, court orders, and other voluntary or statutory deductions.</p>
          </div>
          <button class="btn-primary" @click="startAddDeduction" v-if="!addingDeduction">+ Add Deduction</button>
        </div>

        <!-- Add new deduction form -->
        <div v-if="addingDeduction" class="pc-inline-form">
          <h4 class="pc-inline-form-title">New Deduction Code</h4>
          <div class="pc-form-grid pc-form-grid-3">
            <div class="pc-field">
              <label>Code</label>
              <input v-model="newDeduction.code" type="text" class="pc-input" placeholder="e.g. SACCO" style="text-transform:uppercase" />
            </div>
            <div class="pc-field">
              <label>Name</label>
              <input v-model="newDeduction.name" type="text" class="pc-input" placeholder="e.g. Sacco Loan" />
            </div>
            <div class="pc-field">
              <label>Type</label>
              <select v-model="newDeduction.deduction_type" class="pc-input">
                <option v-for="t in DEDUCTION_TYPES" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>
            <div class="pc-field">
              <label>Priority <span class="pc-hint">(lower = first)</span></label>
              <input v-model.number="newDeduction.priority" type="number" min="1" class="pc-input" />
            </div>
            <div class="pc-field">
              <label>Calculation</label>
              <select v-model="newDeduction.calculation_type" class="pc-input">
                <option v-for="t in CALC_TYPES" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>
            <div class="pc-field">
              <label>Max Amount (KES)</label>
              <input v-model.number="newDeduction.max_amount" type="number" min="0" class="pc-input" placeholder="0 = no limit" />
            </div>
          </div>
          <div class="pc-check-row">
            <label class="pc-check"><input type="checkbox" v-model="newDeduction.recover_balance" /> Recover Balance</label>
            <label class="pc-check"><input type="checkbox" v-model="newDeduction.is_active" /> Active</label>
          </div>
          <div class="pc-inline-form-actions">
            <button class="btn-secondary" @click="addingDeduction = false">Cancel</button>
            <button class="btn-primary" :disabled="saving" @click="saveNewDeduction">{{ saving ? "Saving…" : "Add" }}</button>
          </div>
        </div>

        <table class="pc-table">
          <thead>
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th>Type</th>
              <th class="pc-th-center">Priority</th>
              <th>Calc</th>
              <th>Max (KES)</th>
              <th class="pc-th-center">Recover</th>
              <th class="pc-th-center">Active</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="dc in deductions" :key="dc.id">
              <tr v-if="!dc._editing" class="pc-tr-hover">
                <td><span class="pc-code-badge">{{ dc.code }}</span></td>
                <td>{{ dc.name }}</td>
                <td><span :class="['pc-type-badge', 'pc-type-' + dc.deduction_type]">{{ dc.deduction_type }}</span></td>
                <td class="pc-td-center">{{ dc.priority }}</td>
                <td>{{ dc.calculation_type }}</td>
                <td>{{ dc.max_amount ? dc.max_amount.toLocaleString() : "—" }}</td>
                <td class="pc-td-center"><span :class="dc.recover_balance ? 'pc-yes' : 'pc-no'">{{ dc.recover_balance ? "✓" : "—" }}</span></td>
                <td class="pc-td-center"><span :class="dc.is_active ? 'pc-yes' : 'pc-no'">{{ dc.is_active ? "✓" : "—" }}</span></td>
                <td class="pc-td-actions">
                  <button class="pc-btn-edit" @click="dc._editing = true">Edit</button>
                  <button class="pc-btn-del" @click="deleteDeduction(dc)">✕</button>
                </td>
              </tr>
              <tr v-else class="pc-tr-editing">
                <td><input v-model="dc.code" type="text" class="pc-input pc-input-xs" /></td>
                <td><input v-model="dc.name" type="text" class="pc-input pc-input-xs" /></td>
                <td>
                  <select v-model="dc.deduction_type" class="pc-input pc-input-xs">
                    <option v-for="t in DEDUCTION_TYPES" :key="t" :value="t">{{ t }}</option>
                  </select>
                </td>
                <td><input v-model.number="dc.priority" type="number" min="1" class="pc-input pc-input-xs" style="width:60px" /></td>
                <td>
                  <select v-model="dc.calculation_type" class="pc-input pc-input-xs">
                    <option v-for="t in CALC_TYPES" :key="t" :value="t">{{ t }}</option>
                  </select>
                </td>
                <td><input v-model.number="dc.max_amount" type="number" min="0" class="pc-input pc-input-xs" /></td>
                <td class="pc-td-center"><input type="checkbox" v-model="dc.recover_balance" /></td>
                <td class="pc-td-center"><input type="checkbox" v-model="dc.is_active" /></td>
                <td class="pc-td-actions">
                  <button class="pc-btn-save" :disabled="saving" @click="saveDeduction(dc)">Save</button>
                  <button class="pc-btn-cancel" @click="dc._editing = false">Cancel</button>
                </td>
              </tr>
            </template>
            <tr v-if="deductions.length === 0">
              <td colspan="9" class="pc-empty">No deduction codes. Click "+ Add Deduction" or seed defaults.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<style scoped>
.pc-wrap      { max-width: 1100px; margin: 0 auto; padding: 24px 16px 60px; }
.pc-header    { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
.pc-title     { font-size: 22px; font-weight: 700; margin: 4px 0 2px; }
.pc-sub       { font-size: 13px; color: var(--color-text-muted); margin: 0; }
.back-btn     { background: none; border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 5px 12px; cursor: pointer; font-size: 13px; margin-bottom: 6px; }

.btn-seed     { background: #7c3aed; color: #fff; border: none; border-radius: var(--radius-md); padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-seed:disabled { opacity: .6; }
.btn-primary  { background: var(--color-brand-base); color: #fff; border: none; border-radius: var(--radius-md); padding: 7px 16px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary:disabled { opacity: .6; }
.btn-secondary { background: var(--color-neutral-100); color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 7px 14px; font-size: 13px; cursor: pointer; }

.alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 10px 14px; border-radius: var(--radius-md); font-size: 13px; }
.alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #16a34a; padding: 10px 14px; border-radius: var(--radius-md); font-size: 13px; }
.pc-loading    { color: var(--color-text-muted); padding: 40px; text-align: center; }

.pc-tabs { display: flex; gap: 2px; margin-bottom: 20px; border-bottom: 2px solid var(--color-border); }
.pc-tab  { background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; padding: 10px 18px; cursor: pointer; font-size: 14px; color: var(--color-text-muted); font-weight: 500; transition: all .15s; }
.pc-tab.active  { color: var(--color-brand-base); border-bottom-color: var(--color-brand-base); font-weight: 700; }
.pc-tab:hover:not(.active) { color: var(--color-text); }

.pc-card        { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 24px; }
.pc-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.pc-card-title  { font-size: 16px; font-weight: 700; margin: 0 0 4px; }
.pc-card-sub    { font-size: 13px; color: var(--color-text-muted); margin: 0; }

.pc-field-inline { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.pc-field-inline label { color: var(--color-text-muted); white-space: nowrap; }

.pc-table  { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 4px; }
.pc-table th { text-align: left; padding: 8px 10px; background: var(--color-neutral-50); border-bottom: 1px solid var(--color-border); font-weight: 600; font-size: 12px; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: .04em; }
.pc-table td { padding: 9px 10px; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
.pc-th-center { text-align: center !important; }
.pc-td-center { text-align: center; }
.pc-td-num    { text-align: center; color: var(--color-text-muted); width: 36px; }
.pc-td-actions { white-space: nowrap; text-align: right; display: flex; gap: 6px; justify-content: flex-end; }
.pc-tr-hover:hover td { background: var(--color-neutral-50); }
.pc-tr-editing td { background: #fffbeb; }
.pc-empty  { text-align: center; color: var(--color-text-muted); padding: 24px; font-style: italic; }

.pc-input    { border: 1px solid var(--color-border); border-radius: var(--radius-sm, 6px); padding: 6px 10px; font-size: 13px; width: 100%; background: var(--color-surface); color: var(--color-text); }
.pc-input:focus { outline: 2px solid var(--color-brand-base); outline-offset: 1px; }
.pc-input-sm { width: 110px; }
.pc-input-xs { min-width: 80px; }
.pc-select   { padding: 6px 8px; }

.pc-table-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 16px; }

.pc-info-box { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: var(--radius-md); padding: 12px 16px; font-size: 12px; color: #0369a1; margin-top: 20px; line-height: 1.6; }

.pc-code-badge { font-family: monospace; font-size: 12px; font-weight: 700; background: #f1f5f9; padding: 2px 7px; border-radius: 4px; color: #1e293b; }
.pc-type-badge { font-size: 11px; font-weight: 600; padding: 2px 7px; border-radius: 10px; }
.pc-type-statutory { background: #fef3c7; color: #92400e; }
.pc-type-checkoff  { background: #dbeafe; color: #1d4ed8; }
.pc-type-loan      { background: #f3e8ff; color: #7c3aed; }
.pc-yes { color: #16a34a; font-weight: 700; }
.pc-no  { color: #94a3b8; }
.pc-hint { font-size: 11px; color: var(--color-text-muted); margin-left: 4px; }

.pc-btn-edit   { font-size: 12px; background: none; border: 1px solid var(--color-border); border-radius: 4px; padding: 3px 10px; cursor: pointer; }
.pc-btn-del    { font-size: 12px; background: none; border: 1px solid #fecaca; color: #dc2626; border-radius: 4px; padding: 3px 8px; cursor: pointer; }
.pc-btn-save   { font-size: 12px; background: var(--color-brand-base); color: #fff; border: none; border-radius: 4px; padding: 3px 10px; cursor: pointer; }
.pc-btn-cancel { font-size: 12px; background: none; border: 1px solid var(--color-border); border-radius: 4px; padding: 3px 8px; cursor: pointer; }

/* Rates tab */
.pc-rate-group       { margin-bottom: 28px; }
.pc-rate-group-title { font-size: 14px; font-weight: 700; color: var(--color-text); margin: 0 0 12px; padding-bottom: 6px; border-bottom: 1px solid var(--color-border); }
.pc-rate-grid        { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
.pc-rate-item        { display: flex; flex-direction: column; gap: 4px; }
.pc-rate-label       { font-size: 12px; font-weight: 600; color: var(--color-text); display: flex; align-items: center; gap: 6px; }
.pc-rate-desc        { font-size: 11px; color: var(--color-text-muted); margin: 0 0 4px; }
.pc-rate-hint        { font-size: 11px; color: var(--color-text-muted); margin: 2px 0 0; }
.pc-rate-badge { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 10px; background: #dbeafe; color: #1d4ed8; }
.pc-rate-badge-default { background: #f1f5f9; color: #64748b; }

/* Inline add form */
.pc-inline-form       { background: #f8fafc; border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 16px; margin-bottom: 16px; }
.pc-inline-form-title { font-size: 14px; font-weight: 600; margin: 0 0 14px; }
.pc-form-grid  { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
.pc-form-grid-3 { grid-template-columns: repeat(3, 1fr); }
.pc-field      { display: flex; flex-direction: column; gap: 4px; }
.pc-field label { font-size: 12px; font-weight: 600; color: var(--color-text-muted); }
.pc-check-row  { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 12px; }
.pc-check      { display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; }
.pc-inline-form-actions { display: flex; justify-content: flex-end; gap: 8px; }

@media (max-width: 600px) {
  .pc-form-grid, .pc-form-grid-3 { grid-template-columns: 1fr; }
  .pc-rate-grid { grid-template-columns: 1fr; }
  .pc-table { font-size: 12px; }
}
</style>
