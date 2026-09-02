<script setup lang="ts">
/**
 * Travel configuration for HR and Finance.
 *
 * Four things an organisation has to set before anyone can raise a request:
 * where people go, what the money is for, how much is allowed, and who signs.
 * Without rates nothing can be costed, and a request that costs nothing cannot
 * be submitted — so this page is the one that makes the module usable.
 *
 * The rate card is the part worth reading carefully. Rates are never edited in
 * place: changing one closes the old rate and opens a new one from a date you
 * choose. A request approved in March keeps March's rates when someone opens
 * it in November, which is only true if the old row survives.
 */
import { ref, computed, onMounted } from "vue";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";

type Tab = "rates" | "transport" | "destinations" | "categories" | "chain";

interface Destination { id: number; name: string; country: string | null; is_international: boolean; is_active: boolean }
interface Category {
  id: number; name: string; code: string | null; is_active: boolean;
  evidence_type: "none" | "acknowledgement" | "receipt";
  evidence_provider: "requestor" | "finance" | "either";
}
interface Grade { id: number; name: string; code: string | null; level: number | null }
interface Rate {
  id: number;
  travel_expense_category_id: number;
  job_grade_id: number | null;
  travel_destination_id: number | null;
  amount: string; currency: string; unit: string;
  effective_from: string; effective_to: string | null;
  category?: { id: number; name: string };
}
interface MileageRate { id: number; rate_per_km: string; currency: string; effective_from: string; effective_to: string | null }
interface VehicleType { id: number; name: string; km_per_litre: string | null; load_capacity_kg: string | null; is_active: boolean }
interface BusFare { id: number; travel_destination_id: number; round_trip_amount: string; currency: string; destination?: { id: number; name: string } }
interface Stage {
  stage: string; sequence: number;
  required_above_amount: string | null; currency: string; is_active: boolean;
}

const STAGE_LABELS: Record<string, string> = {
  department_head: "Head of Department",
  function_head:   "Chief Manager (Function)",
  finance:         "Finance Officer",
  executive:       "CFO",
};

const tab = ref<Tab>("rates");
const loading = ref(true);
const saving  = ref(false);
const error   = ref<string | null>(null);
const success = ref<string | null>(null);

const destinations = ref<Destination[]>([]);
const categories   = ref<Category[]>([]);
const rates        = ref<Rate[]>([]);
const grades       = ref<Grade[]>([]);
const mileageRates = ref<MileageRate[]>([]);
const vehicleTypes = ref<VehicleType[]>([]);
const busFares     = ref<BusFare[]>([]);
const stages       = ref<Stage[]>([]);
const showHistory  = ref(false);

const org = () => currentOrgSlug.value;

function notify(message: string) {
  success.value = message;
  error.value = null;
  setTimeout(() => (success.value = null), 4000);
}

function fail(e: unknown) {
  error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Something went wrong.";
  success.value = null;
}

async function load() {
  loading.value = true;
  try {
    const [d, c, r, s, g, m, v, b] = await Promise.all([
      api<{ data: Destination[] }>("GET", "/api/v1/travel/settings/destinations", { org: org() }),
      api<{ data: Category[] }>("GET", "/api/v1/travel/settings/categories", { org: org() }),
      api<{ data: Rate[] }>("GET", "/api/v1/travel/settings/rates", {
        org: org(), params: { include_history: showHistory.value ? 1 : 0 },
      }),
      api<{ data: Stage[] }>("GET", "/api/v1/travel/settings/approval-stages", { org: org() }),
      api<{ data: Grade[] }>("GET", "/api/v1/travel/settings/job-grades", { org: org() }),
      api<{ data: MileageRate[] }>("GET", "/api/v1/travel/settings/mileage-rates", { org: org() }),
      api<{ data: VehicleType[] }>("GET", "/api/v1/travel/settings/vehicle-types", { org: org() }),
      api<{ data: BusFare[] }>("GET", "/api/v1/travel/settings/bus-fares", { org: org() }),
    ]);
    destinations.value = d.data;
    categories.value   = c.data;
    rates.value        = r.data;
    stages.value       = seedChain(s.data);
    grades.value       = g.data;
    mileageRates.value = m.data;
    vehicleTypes.value = v.data;
    busFares.value     = b.data;
  } catch (e) { fail(e); } finally { loading.value = false; }
}

/**
 * Show all four stages even when only some are configured.
 *
 * An unconfigured chain otherwise renders as an empty table, which reads as
 * "nothing to set" rather than "nothing is set".
 */
function seedChain(saved: Stage[]): Stage[] {
  return Object.keys(STAGE_LABELS).map((key, i) => {
    const existing = saved.find((s) => s.stage === key);
    return existing ?? {
      stage: key, sequence: i + 1,
      required_above_amount: null, currency: "KES", is_active: false,
    };
  }).sort((a, b) => a.sequence - b.sequence);
}

// ── Destinations ────────────────────────────────────────────────────────────
const newDestination = ref({ name: "", country: "", is_international: false });

async function addDestination() {
  if (!newDestination.value.name.trim()) return;
  saving.value = true;
  try {
    await api("POST", "/api/v1/travel/settings/destinations", {
      org: org(),
      body: { ...newDestination.value, country: newDestination.value.country || null },
    });
    newDestination.value = { name: "", country: "", is_international: false };
    notify("Destination added.");
    await load();
  } catch (e) { fail(e); } finally { saving.value = false; }
}

async function retireDestination(d: Destination) {
  // Retired rather than deleted: requests already raised against it would
  // otherwise show no destination at all.
  if (!confirm(`Retire ${d.name}? Existing requests keep it; it disappears from the picker.`)) return;
  try {
    await api("DELETE", `/api/v1/travel/settings/destinations/${d.id}`, { org: org() });
    notify("Destination retired.");
    await load();
  } catch (e) { fail(e); }
}

// ── Categories ──────────────────────────────────────────────────────────────
const newCategory = ref({
  name: "",
  evidence_type: "none" as Category["evidence_type"],
  evidence_provider: "requestor" as Category["evidence_provider"],
});

/** Saved inline, because changing a policy is a one-field decision. */
async function saveCategory(c: Category) {
  try {
    await api("PUT", `/api/v1/travel/settings/categories/${c.id}`, {
      org: org(),
      body: { evidence_type: c.evidence_type, evidence_provider: c.evidence_provider },
    });
    notify(`${c.name} updated.`);
  } catch (e) { fail(e); }
}

async function addCategory() {
  if (!newCategory.value.name.trim()) return;
  saving.value = true;
  try {
    await api("POST", "/api/v1/travel/settings/categories", { org: org(), body: newCategory.value });
    newCategory.value = { name: "", evidence_type: "none", evidence_provider: "requestor" };
    notify("Category added.");
    await load();
  } catch (e) { fail(e); } finally { saving.value = false; }
}

async function retireCategory(c: Category) {
  if (!confirm(`Retire ${c.name}?`)) return;
  try {
    await api("DELETE", `/api/v1/travel/settings/categories/${c.id}`, { org: org() });
    notify("Category retired.");
    await load();
  } catch (e) { fail(e); }
}

// ── Rates ───────────────────────────────────────────────────────────────────
const blankRate = () => ({
  travel_expense_category_id: null as number | null,
  job_grade_id: null as number | null,
  travel_destination_id: null as number | null,
  amount: "", currency: "KES", unit: "per_day",
  effective_from: new Date().toISOString().slice(0, 10),
});
const newRate  = ref(blankRate());
const superseding = ref<Rate | null>(null);

async function saveRate() {
  const r = newRate.value;
  if (!r.travel_expense_category_id || !r.amount) return;

  saving.value = true;
  try {
    if (superseding.value) {
      // Supersede rather than edit: the old rate stays, closed the day before
      // this one starts.
      await api("PUT", `/api/v1/travel/settings/rates/${superseding.value.id}`, { org: org(), body: r });
      notify("Rate replaced. The previous one is closed and kept.");
    } else {
      await api("POST", "/api/v1/travel/settings/rates", { org: org(), body: r });
      notify("Rate added.");
    }
    newRate.value = blankRate();
    superseding.value = null;
    await load();
  } catch (e) { fail(e); } finally { saving.value = false; }
}

function supersede(rate: Rate) {
  superseding.value = rate;
  newRate.value = {
    travel_expense_category_id: rate.travel_expense_category_id,
    job_grade_id: rate.job_grade_id,
    travel_destination_id: rate.travel_destination_id,
    amount: rate.amount, currency: rate.currency, unit: rate.unit,
    effective_from: new Date().toISOString().slice(0, 10),
  };
  tab.value = "rates";
}

async function closeRate(rate: Rate) {
  if (!confirm("Close this rate from today? Requests already costed against it are unchanged.")) return;
  try {
    await api("DELETE", `/api/v1/travel/settings/rates/${rate.id}`, { org: org() });
    notify("Rate closed.");
    await load();
  } catch (e) { fail(e); }
}

const destinationName = (id: number | null) =>
  id ? (destinations.value.find((d) => d.id === id)?.name ?? "—") : "All destinations";
const gradeName = (id: number | null) =>
  id ? (grades.value.find((g) => g.id === id)?.name ?? "—") : "All grades";

/** Dates arrive as full timestamps; only the day is meaningful here. */
const day = (v: string | null) => (v ? String(v).slice(0, 10) : null);
const categoryName = (id: number) =>
  categories.value.find((c) => c.id === id)?.name ?? "—";

// ── Transport ───────────────────────────────────────────────────────────────
const newMileage = ref({ rate_per_km: "", effective_from: new Date().toISOString().slice(0, 10) });
const newVehicle = ref({ name: "", km_per_litre: "", load_capacity_kg: "" });
const newFare    = ref({ travel_destination_id: null as number | null, round_trip_amount: "" });

const currentMileage = computed(() => mileageRates.value.find((r) => !r.effective_to) ?? null);

async function saveMileageRate() {
  if (!newMileage.value.rate_per_km) return;
  saving.value = true;
  try {
    await api("POST", "/api/v1/travel/settings/mileage-rates", { org: org(), body: newMileage.value });
    newMileage.value = { rate_per_km: "", effective_from: new Date().toISOString().slice(0, 10) };
    notify("Mileage rate set. The previous one is closed and kept.");
    await load();
  } catch (e) { fail(e); } finally { saving.value = false; }
}

async function addVehicleType() {
  if (!newVehicle.value.name.trim()) return;
  saving.value = true;
  try {
    await api("POST", "/api/v1/travel/settings/vehicle-types", {
      org: org(),
      body: {
        name: newVehicle.value.name,
        km_per_litre: newVehicle.value.km_per_litre || null,
        load_capacity_kg: newVehicle.value.load_capacity_kg || null,
      },
    });
    newVehicle.value = { name: "", km_per_litre: "", load_capacity_kg: "" };
    notify("Vehicle type added.");
    await load();
  } catch (e) { fail(e); } finally { saving.value = false; }
}

async function saveVehicleType(v: VehicleType) {
  try {
    await api("PUT", `/api/v1/travel/settings/vehicle-types/${v.id}`, {
      org: org(), body: { km_per_litre: v.km_per_litre || null, load_capacity_kg: v.load_capacity_kg || null },
    });
    notify(`${v.name} updated.`);
  } catch (e) { fail(e); }
}

async function retireVehicleType(v: VehicleType) {
  if (!confirm(`Retire ${v.name}? Journeys already costed against it keep it.`)) return;
  try {
    await api("DELETE", `/api/v1/travel/settings/vehicle-types/${v.id}`, { org: org() });
    notify("Vehicle type retired.");
    await load();
  } catch (e) { fail(e); }
}

async function saveFare() {
  if (!newFare.value.travel_destination_id || !newFare.value.round_trip_amount) return;
  saving.value = true;
  try {
    await api("POST", "/api/v1/travel/settings/bus-fares", { org: org(), body: newFare.value });
    newFare.value = { travel_destination_id: null, round_trip_amount: "" };
    notify("Fare saved.");
    await load();
  } catch (e) { fail(e); } finally { saving.value = false; }
}

async function removeFare(f: BusFare) {
  try {
    await api("DELETE", `/api/v1/travel/settings/bus-fares/${f.id}`, { org: org() });
    notify("Fare removed.");
    await load();
  } catch (e) { fail(e); }
}

// ── Approval chain ──────────────────────────────────────────────────────────
const activeStages = computed(() => stages.value.filter((s) => s.is_active));

/**
 * A chain where every active stage carries a threshold can match nothing for a
 * small request, which would leave it approved by no one. Surfaced before
 * saving rather than as a validation error afterwards.
 */
const chainWarning = computed(() => {
  if (activeStages.value.length === 0) return "No stages are active. Every request would be approved by nobody.";
  if (activeStages.value.every((s) => s.required_above_amount !== null && s.required_above_amount !== ""))
    return "Every active stage has a threshold, so a small request would match none of them. Leave the first stage's threshold blank.";
  return null;
});

async function saveChain() {
  saving.value = true;
  try {
    await api("PUT", "/api/v1/travel/settings/approval-stages", {
      org: org(),
      body: {
        stages: stages.value.map((s) => ({
          stage: s.stage,
          sequence: s.sequence,
          required_above_amount: s.required_above_amount === "" ? null : s.required_above_amount,
          currency: s.currency,
          is_active: s.is_active,
        })),
      },
    });
    notify("Approval chain saved.");
    await load();
  } catch (e) { fail(e); } finally { saving.value = false; }
}

onMounted(load);
</script>

<template>
  <div class="ts-wrap">
    <header class="ts-header">
      <h1 class="ts-title">Travel Configuration</h1>
      <p class="ts-sub">
        Destinations, what the organisation pays, and who signs. Requests cannot be
        raised until at least one allowance rate exists.
      </p>
    </header>

    <div v-if="error" class="alert-error">{{ error }}</div>
    <div v-if="success" class="alert-success">{{ success }}</div>

    <div v-if="loading" class="ts-loading">Loading configuration…</div>

    <template v-else>
      <nav class="ts-tabs" role="tablist">
        <button :class="['ts-tab', { active: tab === 'rates' }]" @click="tab = 'rates'">Allowance Rates</button>
        <button :class="['ts-tab', { active: tab === 'transport' }]" @click="tab = 'transport'">Transport</button>
        <button :class="['ts-tab', { active: tab === 'destinations' }]" @click="tab = 'destinations'">Destinations</button>
        <button :class="['ts-tab', { active: tab === 'categories' }]" @click="tab = 'categories'">Expense Categories</button>
        <button :class="['ts-tab', { active: tab === 'chain' }]" @click="tab = 'chain'">Approval Chain</button>
      </nav>

      <!-- ── Rates ──────────────────────────────────────────────────────── -->
      <section v-if="tab === 'rates'" class="ts-panel">
        <p class="ts-note">
          A blank grade or destination means <strong>applies to all</strong>. State one rate
          globally, then add grade- or destination-specific rows above it — the most specific
          match wins, so a trainee and a unit head can be priced differently without
          restating every combination.
        </p>

        <div class="ts-form">
          <h2 class="ts-form-title">
            {{ superseding ? "Replace an existing rate" : "Add a rate" }}
          </h2>
          <p v-if="superseding" class="ts-note ts-note-warn">
            The current rate stays on record, closed the day before this one starts.
            Requests already costed against it keep their original amounts.
            <button class="link-btn" @click="superseding = null; newRate = blankRate()">Cancel</button>
          </p>

          <div class="ts-row">
            <label class="ts-field">
              <span>Category</span>
              <select v-model.number="newRate.travel_expense_category_id" class="control">
                <option :value="null" disabled>Choose…</option>
                <option v-for="c in categories.filter(c => c.is_active)" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </label>

            <label class="ts-field">
              <span>Grade</span>
              <select v-model.number="newRate.job_grade_id" class="control">
                <option :value="null">All grades</option>
                <option v-for="g in grades" :key="g.id" :value="g.id">{{ g.name }}</option>
              </select>
            </label>

            <label class="ts-field">
              <span>Destination</span>
              <select v-model.number="newRate.travel_destination_id" class="control">
                <option :value="null">All destinations</option>
                <option v-for="d in destinations.filter(d => d.is_active)" :key="d.id" :value="d.id">{{ d.name }}</option>
              </select>
            </label>

            <label class="ts-field">
              <span>Amount</span>
              <input v-model="newRate.amount" class="control" type="number" min="0" step="0.01" />
            </label>

            <label class="ts-field ts-field-sm">
              <span>Per</span>
              <select v-model="newRate.unit" class="control">
                <option value="per_day">Day</option>
                <option value="per_trip">Trip</option>
              </select>
            </label>

            <label class="ts-field">
              <span>Effective from</span>
              <input v-model="newRate.effective_from" class="control" type="date" />
            </label>

            <button class="btn-primary" :disabled="saving" @click="saveRate">
              {{ superseding ? "Replace" : "Add" }}
            </button>
          </div>
        </div>

        <label class="ts-toggle">
          <input v-model="showHistory" type="checkbox" @change="load" />
          Show superseded rates
        </label>

        <table class="ts-table">
          <thead>
            <tr>
              <th>Category</th><th>Grade</th><th>Destination</th><th>Amount</th><th>Per</th>
              <th>In force</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="rates.length === 0">
              <td colspan="7" class="ts-empty">
                No rates yet. Nothing can be costed, so no request can be submitted.
              </td>
            </tr>
            <tr v-for="r in rates" :key="r.id" :class="{ 'ts-closed': r.effective_to }">
              <td>{{ r.category?.name ?? categoryName(r.travel_expense_category_id) }}</td>
              <td>{{ gradeName(r.job_grade_id) }}</td>
              <td>{{ destinationName(r.travel_destination_id) }}</td>
              <td class="ts-amount">{{ r.currency }} {{ Number(r.amount).toLocaleString() }}</td>
              <td>{{ r.unit === "per_day" ? "day" : "trip" }}</td>
              <td>
                {{ day(r.effective_from) }}
                <template v-if="r.effective_to"> → {{ day(r.effective_to) }}</template>
                <span v-else class="badge">current</span>
              </td>
              <td class="ts-actions">
                <button v-if="!r.effective_to" class="link-btn" @click="supersede(r)">Change</button>
                <button v-if="!r.effective_to" class="link-btn link-danger" @click="closeRate(r)">Close</button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- ── Transport ──────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'transport'" class="ts-panel">
        <p class="ts-note">
          Transport is worked out from distance, fuel and mode rather than looked up against
          a grade — a 40&nbsp;km errand and a 600&nbsp;km round trip are not the same claim.
          What you set here is the arithmetic; the journey supplies the rest.
          <strong>Fuel price is not set here</strong> — it is entered on each request, because a
          pump price held centrally is out of date within a week.
        </p>

        <h2 class="ts-form-title">Mileage rate</h2>
        <p class="ts-note">
          Paid per kilometre for self-drive journeys. It covers fuel and wear together, which
          is why a self-drive claim does not also reimburse fuel.
        </p>

        <div class="ts-form">
          <div class="ts-row">
            <label class="ts-field">
              <span>Rate per km</span>
              <input v-model="newMileage.rate_per_km" class="control" type="number" min="0" step="0.01"
                     :placeholder="currentMileage ? `currently ${Number(currentMileage.rate_per_km).toLocaleString()}` : 'not set'" />
            </label>
            <label class="ts-field">
              <span>Effective from</span>
              <input v-model="newMileage.effective_from" class="control" type="date" />
            </label>
            <button class="btn-primary" :disabled="saving" @click="saveMileageRate">
              {{ currentMileage ? "Replace" : "Set" }}
            </button>
          </div>
        </div>

        <table class="ts-table">
          <thead><tr><th>Rate per km</th><th>In force</th></tr></thead>
          <tbody>
            <tr v-if="mileageRates.length === 0">
              <td colspan="2" class="ts-empty">
                No mileage rate set. Self-drive journeys cannot be costed until there is one.
              </td>
            </tr>
            <tr v-for="r in mileageRates" :key="r.id" :class="{ 'ts-closed': r.effective_to }">
              <td class="ts-amount">{{ r.currency }} {{ Number(r.rate_per_km).toLocaleString() }}</td>
              <td>
                {{ day(r.effective_from) }}
                <template v-if="r.effective_to"> → {{ day(r.effective_to) }}</template>
                <span v-else class="badge">current</span>
              </td>
            </tr>
          </tbody>
        </table>

        <h2 class="ts-form-title">Vehicle types</h2>
        <p class="ts-note">
          Consumption is held per class rather than per vehicle — real figures vary more by
          driver and load than by chassis. A hired vehicle cannot be costed without it.
        </p>

        <div class="ts-form">
          <div class="ts-row">
            <label class="ts-field"><span>Name</span>
              <input v-model="newVehicle.name" class="control" placeholder="4x4" /></label>
            <label class="ts-field"><span>Km per litre</span>
              <input v-model="newVehicle.km_per_litre" class="control" type="number" min="0.1" step="0.1" /></label>
            <label class="ts-field"><span>Load capacity (kg)</span>
              <input v-model="newVehicle.load_capacity_kg" class="control" type="number" min="0" /></label>
            <button class="btn-primary" :disabled="saving" @click="addVehicleType">Add</button>
          </div>
        </div>

        <table class="ts-table">
          <thead><tr><th>Vehicle</th><th>Km/litre</th><th>Load (kg)</th><th></th></tr></thead>
          <tbody>
            <tr v-if="vehicleTypes.length === 0"><td colspan="4" class="ts-empty">No vehicle types yet.</td></tr>
            <tr v-for="v in vehicleTypes" :key="v.id" :class="{ 'ts-closed': !v.is_active }">
              <td>{{ v.name }}</td>
              <td>
                <input v-model="v.km_per_litre" class="control control-xs" type="number" min="0.1" step="0.1"
                       :disabled="!v.is_active" @change="saveVehicleType(v)" />
              </td>
              <td>
                <input v-model="v.load_capacity_kg" class="control control-sm" type="number" min="0"
                       :disabled="!v.is_active" @change="saveVehicleType(v)" />
              </td>
              <td class="ts-actions">
                <span v-if="!v.is_active" class="badge">retired</span>
                <button v-else class="link-btn link-danger" @click="retireVehicleType(v)">Retire</button>
              </td>
            </tr>
          </tbody>
        </table>

        <h2 class="ts-form-title">Bus fares</h2>
        <p class="ts-note">
          A published round-trip price per route. Fares are not a function of distance, so
          computing one would produce a figure no ticket office recognises.
        </p>

        <div class="ts-form">
          <div class="ts-row">
            <label class="ts-field">
              <span>Route</span>
              <select v-model.number="newFare.travel_destination_id" class="control">
                <option :value="null">Choose a destination…</option>
                <option v-for="d in destinations.filter(d => d.is_active)" :key="d.id" :value="d.id">{{ d.name }}</option>
              </select>
            </label>
            <label class="ts-field"><span>Round trip</span>
              <input v-model="newFare.round_trip_amount" class="control" type="number" min="0" /></label>
            <button class="btn-primary" :disabled="saving" @click="saveFare">Save</button>
          </div>
        </div>

        <table class="ts-table">
          <thead><tr><th>Route</th><th>Round trip</th><th></th></tr></thead>
          <tbody>
            <tr v-if="busFares.length === 0"><td colspan="3" class="ts-empty">No fares set.</td></tr>
            <tr v-for="f in busFares" :key="f.id">
              <td>{{ f.destination?.name ?? "—" }}</td>
              <td class="ts-amount">{{ f.currency }} {{ Number(f.round_trip_amount).toLocaleString() }}</td>
              <td class="ts-actions">
                <button class="link-btn link-danger" @click="removeFare(f)">Remove</button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- ── Destinations ───────────────────────────────────────────────── -->
      <section v-else-if="tab === 'destinations'" class="ts-panel">
        <div class="ts-form">
          <div class="ts-row">
            <label class="ts-field">
              <span>Name</span>
              <input v-model="newDestination.name" class="control" placeholder="Mombasa" />
            </label>
            <label class="ts-field ts-field-sm">
              <span>Country</span>
              <input v-model="newDestination.country" class="control" maxlength="2" placeholder="KE" />
            </label>
            <label class="ts-check">
              <input v-model="newDestination.is_international" type="checkbox" />
              International
            </label>
            <button class="btn-primary" :disabled="saving" @click="addDestination">Add</button>
          </div>
        </div>

        <table class="ts-table">
          <thead><tr><th>Name</th><th>Country</th><th>Type</th><th></th></tr></thead>
          <tbody>
            <tr v-if="destinations.length === 0"><td colspan="4" class="ts-empty">No destinations yet.</td></tr>
            <tr v-for="d in destinations" :key="d.id" :class="{ 'ts-closed': !d.is_active }">
              <td>{{ d.name }}</td>
              <td>{{ d.country ?? "—" }}</td>
              <td>{{ d.is_international ? "International" : "Domestic" }}</td>
              <td class="ts-actions">
                <span v-if="!d.is_active" class="badge">retired</span>
                <button v-else class="link-btn link-danger" @click="retireDestination(d)">Retire</button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- ── Categories ─────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'categories'" class="ts-panel">
        <p class="ts-note">
          What travel money is spent on — transport, meals, accommodation, facilitation.
          Each category is priced separately in the rate card.
        </p>

        <p class="ts-note">
          Closing a trip needs different things from different people. A taxi fare needs a
          receipt from the traveller, an airline ticket a receipt from finance, and a per
          diem only the traveller's confirmation that they received it. Asking the wrong
          party is what leaves requests open indefinitely.
        </p>

        <div class="ts-form">
          <div class="ts-row">
            <label class="ts-field">
              <span>Name</span>
              <input v-model="newCategory.name" class="control" placeholder="Meals" />
            </label>
            <label class="ts-field">
              <span>To close, needs</span>
              <select v-model="newCategory.evidence_type" class="control">
                <option value="none">Nothing</option>
                <option value="acknowledgement">Acknowledgement</option>
                <option value="receipt">A receipt</option>
              </select>
            </label>
            <label class="ts-field">
              <span>Provided by</span>
              <select v-model="newCategory.evidence_provider" class="control"
                      :disabled="newCategory.evidence_type === 'none'">
                <option value="requestor">The traveller</option>
                <option value="finance">Finance</option>
                <option value="either">Either</option>
              </select>
            </label>
            <button class="btn-primary" :disabled="saving" @click="addCategory">Add</button>
          </div>
        </div>

        <table class="ts-table">
          <thead><tr><th>Name</th><th>To close, needs</th><th>Provided by</th><th></th></tr></thead>
          <tbody>
            <tr v-if="categories.length === 0"><td colspan="4" class="ts-empty">No categories yet.</td></tr>
            <tr v-for="c in categories" :key="c.id" :class="{ 'ts-closed': !c.is_active }">
              <td>{{ c.name }}</td>
              <td>
                <select v-model="c.evidence_type" class="control control-sm"
                        :disabled="!c.is_active" @change="saveCategory(c)">
                  <option value="none">Nothing</option>
                  <option value="acknowledgement">Acknowledgement</option>
                  <option value="receipt">A receipt</option>
                </select>
              </td>
              <td>
                <select v-model="c.evidence_provider" class="control control-sm"
                        :disabled="!c.is_active || c.evidence_type === 'none'" @change="saveCategory(c)">
                  <option value="requestor">The traveller</option>
                  <option value="finance">Finance</option>
                  <option value="either">Either</option>
                </select>
              </td>
              <td class="ts-actions">
                <span v-if="!c.is_active" class="badge">retired</span>
                <button v-else class="link-btn link-danger" @click="retireCategory(c)">Retire</button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- ── Approval chain ─────────────────────────────────────────────── -->
      <section v-else class="ts-panel">
        <p class="ts-note">
          Stages run in order. Leave a threshold blank for a stage that applies to every
          request; set one to skip it below that value.
        </p>

        <div v-if="chainWarning" class="alert-error">{{ chainWarning }}</div>

        <table class="ts-table">
          <thead>
            <tr><th>Active</th><th>Stage</th><th>Order</th><th>Required above</th><th>Currency</th></tr>
          </thead>
          <tbody>
            <tr v-for="s in stages" :key="s.stage" :class="{ 'ts-closed': !s.is_active }">
              <td><input v-model="s.is_active" type="checkbox" /></td>
              <td>{{ STAGE_LABELS[s.stage] }}</td>
              <td><input v-model.number="s.sequence" class="control control-xs" type="number" min="1" /></td>
              <td>
                <input v-model="s.required_above_amount" class="control control-sm"
                       type="number" min="0" placeholder="Always" :disabled="!s.is_active" />
              </td>
              <td><input v-model="s.currency" class="control control-xs" maxlength="3" :disabled="!s.is_active" /></td>
            </tr>
          </tbody>
        </table>

        <button class="btn-primary" :disabled="saving || !!chainWarning" @click="saveChain">
          Save approval chain
        </button>
      </section>
    </template>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.ts-wrap { padding: var(--space-6); max-width: 76rem; }

.ts-header { margin-bottom: var(--space-5); }
.ts-title { font-size: var(--text-2xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ts-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; max-width: 60ch; }

.ts-loading { padding: var(--space-6); color: var(--color-text-muted); }

.ts-tabs { @apply flex gap-1; border-bottom: 1px solid var(--color-border); margin-bottom: var(--space-5); }
.ts-tab {
  @apply cursor-pointer border-none bg-transparent;
  padding: var(--space-3) var(--space-4);
  font-family: inherit; font-size: var(--text-sm-size);
  color: var(--color-text-muted);
  border-bottom: 2px solid transparent;
}
.ts-tab:hover  { color: var(--color-text); }
.ts-tab.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }

.ts-panel { @apply flex flex-col; gap: var(--space-4); }

.ts-note {
  font-size: var(--text-sm-size); color: var(--color-text-muted);
  margin: 0; max-width: 70ch;
}
.ts-note-warn {
  padding: var(--space-3);
  background: var(--color-brand-subtle);
  border-radius: var(--radius-md);
  color: var(--color-text);
}

.ts-form {
  padding: var(--space-4);
  background: var(--color-surface-sunken);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
.ts-form-title { font-size: var(--text-base-size); font-weight: 600; margin: 0 0 var(--space-3); color: var(--color-text); }

.ts-row { @apply flex flex-wrap items-end; gap: var(--space-3); }
.ts-field { @apply flex flex-col; gap: var(--space-1); min-width: 11rem; flex: 1; }
.ts-field-sm { min-width: 6rem; flex: 0 0 auto; }
.ts-field span { font-size: var(--text-xs-size); color: var(--color-text-muted); }

.ts-check { @apply flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
  width: 100%;
}
.control:focus {
  @apply outline-none;
  border-color: var(--color-brand-base);
  box-shadow: 0 0 0 3px var(--color-brand-subtle);
}
.control-sm { width: 8rem; }
.control-xs { width: 5rem; }

.ts-toggle { @apply flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text-muted); }

.ts-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.ts-table th {
  text-align: left; padding: var(--space-2) var(--space-3);
  font-size: var(--text-xs-size); font-weight: 600;
  color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.04em;
  border-bottom: 1px solid var(--color-border);
}
.ts-table td { padding: var(--space-3); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.ts-amount { font-variant-numeric: tabular-nums; }
.ts-empty { color: var(--color-text-light); text-align: center; padding: var(--space-6); }

/* Superseded and retired rows stay legible but recede — they are history,
   not current configuration. */
.ts-closed td { color: var(--color-text-light); }

.ts-actions { @apply flex; gap: var(--space-3); }

.link-btn {
  @apply cursor-pointer border-none bg-transparent p-0;
  font-family: inherit; font-size: var(--text-sm-size);
  color: var(--color-brand-on-surface);
}
.link-btn:hover { @apply underline; }
.link-danger { color: var(--color-error); }

.btn-primary {
  @apply cursor-pointer border-none;
  padding: 8px var(--space-4);
  border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500;
  background: var(--color-brand-base);
  color: var(--color-on-brand);
}
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }

.alert-error, .alert-success {
  padding: var(--space-3) var(--space-4);
  border-radius: var(--radius-md);
  font-size: var(--text-sm-size);
}
.alert-error   { background: var(--color-danger-subtle,  var(--color-surface-sunken)); color: var(--color-danger-text, var(--color-text)); }
.alert-success { background: var(--color-success-subtle, var(--color-surface-sunken)); color: var(--color-success-text, var(--color-text)); }

.badge {
  display: inline-block; width: fit-content;
  padding: 2px var(--space-2);
  border-radius: var(--radius-sm);
  font-size: var(--text-xs-size);
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
}
</style>
