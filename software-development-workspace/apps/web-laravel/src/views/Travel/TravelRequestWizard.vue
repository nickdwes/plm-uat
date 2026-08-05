<script setup lang="ts">
/**
 * Raising a travel request.
 *
 * The request is created on the server at the end of step 1 rather than held
 * in the browser until submit. Expenses are checked against policy as each is
 * added, and that check needs the destination, dates and grade — so a draft
 * has to exist for the answer to mean anything. It also means a half-filled
 * request survives a closed tab, which is the difference between a draft and
 * a lost afternoon.
 *
 * Nothing here enforces the allowance. Going over is shown, and the approver
 * decides. A form that refuses the only available fare does not prevent the
 * trip; it prevents the record of it.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";

interface Option { id: number; name: string }
interface Item {
  id: number;
  description: string | null;
  category?: { id: number; name: string };
  amount_claimed: string;
  policy_limit: string | null;
  exceeds_policy: boolean;
}
interface Itinerary { id: number; starts_on: string; activity: string; estimated_cost: string | null }
interface VehicleType { id: number; name: string; km_per_litre: string | null; load_capacity_kg: string | null }
interface Leg {
  id: number; mode: string; travel_type: string; is_delivery: boolean;
  estimated_km: string | null; estimated_cost: string | null;
  from_location: string | null; to_location: string | null;
  item_description: string | null;
}
interface Lookups {
  destinations: Option[];
  expense_categories: Option[];
  vehicle_types: VehicleType[];
  has_mileage_rate: boolean;
  bus_routes: number[];
}

const router = useRouter();
const org = () => currentOrgSlug.value;

const step    = ref(1);
const saving  = ref(false);
const error   = ref<string | null>(null);
const requestId = ref<number | null>(null);
const submitted = ref(false);

const destinations = ref<Option[]>([]);
const categories   = ref<Option[]>([]);
const accounts       = ref<Option[]>([]);
const vehicleTypes   = ref<VehicleType[]>([]);
const hasMileageRate = ref(true);
const busRoutes      = ref<number[]>([]);
const legs           = ref<Leg[]>([]);

const items     = ref<Item[]>([]);
const itinerary = ref<Itinerary[]>([]);
const totals    = ref({ currency: "KES", total_claimed: "0" });

const form = ref({
  travel_destination_id: null as number | null,
  travel_mode: "",
  priority: "",
  departure_date: "",
  return_date: "",
  accommodation_needed: false,
  flexible_days: false,
  account_id: null as number | null,
  linked_task_ref: "",
  purpose: "",
  business_impact: "",
  advance_required: null as number | null,
  advance_justification: "",
});

const showExpense = ref(false);
const newExpense  = ref({ travel_expense_category_id: null as number | null, amount_claimed: "", description: "" });
const newItinerary = ref({ starts_on: "", activity: "", estimated_cost: "" });

const STEPS = [
  { n: 1, title: "Travel Details",     hint: "Purpose, location, dates" },
  { n: 2, title: "Transport",          hint: "How you are getting there" },
  { n: 3, title: "Expenses & Advance", hint: "Allowances and advance" },
  { n: 4, title: "Itinerary",          hint: "Plans and supporting detail" },
  { n: 5, title: "Review & Submit",    hint: "Review and submit request" },
];

/*
 * What each mode needs, and what it cannot be asked for.
 *
 * A taxi fare is not knowable in advance, so the form does not pretend it is;
 * a bus fare is a published price, so distance is irrelevant to it. Showing
 * every field for every mode invites figures that are then quietly ignored.
 */
const MODES: Record<string, { label: string; needs: string[]; note: string }> = {
  self_drive:    { label: "Self-drive",     needs: ["km"],
                   note: "Paid per kilometre. The rate covers fuel and wear, so fuel is not claimed separately." },
  hired_vehicle: { label: "Hired vehicle",  needs: ["km", "vehicle", "fuel"],
                   note: "Costed as the fuel the journey burns." },
  bus:           { label: "Bus",            needs: ["destination"],
                   note: "Uses the published round-trip fare for the route." },
  taxi:          { label: "Taxi / Uber",    needs: [],
                   note: "No estimate — enter what it actually cost when you reconcile, with receipts." },
  flight:        { label: "Flight",         needs: ["quote"],
                   note: "Enter the quoted round-trip ticket cost." },
  visa:          { label: "Visa",           needs: ["quote"],
                   note: "Enter the visa application or processing fee." },
};

const blankLeg = () => ({
  mode: "self_drive",
  travel_type: "local",
  from_location: "", to_location: "",
  travel_destination_id: null as number | null,
  estimated_km: "", fuel_price_per_litre: "",
  transport_vehicle_type_id: null as number | null,
  estimated_cost: "",
  is_delivery: false, item_description: "", item_weight_kg: "",
});
const newLeg = ref(blankLeg());

const legNeeds = (field: string) => (MODES[newLeg.value.mode]?.needs ?? []).includes(field);

/** Blocks adding a journey the system cannot cost, rather than valuing it at nothing. */
const legBlocker = computed(() => {
  const m = newLeg.value.mode;
  if (m === "self_drive" && !hasMileageRate.value)
    return "No mileage rate is configured, so a self-drive journey cannot be costed. Ask finance to set one.";
  if (m === "hired_vehicle" && vehicleTypes.value.length === 0)
    return "No vehicle types are configured, so fuel cannot be estimated.";
  if (m === "bus" && newLeg.value.travel_destination_id
      && !busRoutes.value.includes(newLeg.value.travel_destination_id))
    return "No standard fare is set for that route.";
  return null;
});

async function addLeg() {
  saving.value = true;
  error.value = null;
  try {
    const body: Record<string, unknown> = { ...newLeg.value };
    Object.keys(body).forEach((k) => (body[k] === "" || body[k] === null) && delete body[k]);
    body.mode = newLeg.value.mode;
    body.is_delivery = newLeg.value.is_delivery;

    const res = await api<{ data: any }>("POST", `/api/v1/travel-requests/${requestId.value}/legs`,
      { org: org(), body });
    absorb(res.data);
    newLeg.value = blankLeg();
  } catch (e) { fail(e); } finally { saving.value = false; }
}

async function removeLeg(leg: Leg) {
  try {
    const res = await api<{ data: any }>("DELETE",
      `/api/v1/travel-requests/${requestId.value}/legs/${leg.id}`, { org: org() });
    absorb(res.data);
  } catch (e) { fail(e); }
}

const legTotal = computed(() =>
  legs.value.reduce((sum, l) => sum + Number(l.estimated_cost ?? 0), 0));

function fail(e: unknown) {
  error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Something went wrong.";
}

onMounted(async () => {
  try {
    // One call, and no longer the settings endpoints: those need
    // travel.manage_rates, so an ordinary employee got a 403 that the form
    // swallowed into empty dropdowns with no error.
    const [l, a] = await Promise.all([
      api<Lookups>("GET", "/api/v1/travel/lookups", { org: org() }),
      api<{ data: Option[] }>("GET", "/api/v1/accounts", { org: org(), params: { per_page: 100 } })
        .catch(() => ({ data: [] })),
    ]);
    destinations.value = l.destinations;
    categories.value   = l.expense_categories;
    vehicleTypes.value = l.vehicle_types;
    hasMileageRate.value = l.has_mileage_rate;
    busRoutes.value    = l.bus_routes;
    accounts.value     = a.data;
  } catch (e) { fail(e); }
});

const canLeaveStepOne = computed(() =>
  !!form.value.departure_date && !!form.value.return_date && !!form.value.purpose.trim());

/** Create on first advance, update on any return to step 1. */
async function saveDetails(): Promise<boolean> {
  saving.value = true;
  error.value = null;
  try {
    // The traveller is the caller: the API defaults it, and raising on
    // someone else's behalf is a separate permission rather than a field.
    const body = { ...form.value, advance_required: form.value.advance_required || null };

    const res = requestId.value
      ? await api<{ data: any }>("PUT", `/api/v1/travel-requests/${requestId.value}`, { org: org(), body })
      : await api<{ data: any }>("POST", "/api/v1/travel-requests", { org: org(), body });

    requestId.value = res.data.id;
    absorb(res.data);
    return true;
  } catch (e) { fail(e); return false; } finally { saving.value = false; }
}

function absorb(data: any) {
  items.value     = data.items ?? items.value;
  legs.value      = data.transport_legs ?? legs.value;
  itinerary.value = data.itinerary ?? itinerary.value;
  totals.value    = { currency: data.currency ?? "KES", total_claimed: data.total_claimed ?? "0" };
}

async function next() {
  if (step.value === 1) {
    if (!canLeaveStepOne.value) { error.value = "Dates and purpose are required."; return; }
    if (!(await saveDetails())) return;
  }
  step.value = Math.min(5, step.value + 1);
}

async function addExpense() {
  if (!newExpense.value.travel_expense_category_id || !newExpense.value.amount_claimed) return;
  saving.value = true;
  try {
    const res = await api<{ data: any }>("POST", `/api/v1/travel-requests/${requestId.value}/items`, {
      org: org(), body: newExpense.value,
    });
    absorb(res.data);
    newExpense.value = { travel_expense_category_id: null, amount_claimed: "", description: "" };
    showExpense.value = false;
  } catch (e) { fail(e); } finally { saving.value = false; }
}

async function removeExpense(item: Item) {
  try {
    const res = await api<{ data: any }>("DELETE", `/api/v1/travel-requests/${requestId.value}/items/${item.id}`, { org: org() });
    absorb(res.data);
  } catch (e) { fail(e); }
}

async function addItinerary() {
  if (!newItinerary.value.starts_on || !newItinerary.value.activity.trim()) return;
  saving.value = true;
  try {
    const res = await api<{ data: any }>("POST", `/api/v1/travel-requests/${requestId.value}/itinerary`, {
      org: org(),
      body: { ...newItinerary.value, estimated_cost: newItinerary.value.estimated_cost || null },
    });
    absorb(res.data);
    newItinerary.value = { starts_on: "", activity: "", estimated_cost: "" };
  } catch (e) { fail(e); } finally { saving.value = false; }
}

async function submit() {
  saving.value = true;
  error.value = null;
  try {
    await api("POST", `/api/v1/travel-requests/${requestId.value}/submit`, { org: org() });
    submitted.value = true;
  } catch (e) { fail(e); } finally { saving.value = false; }
}

/** The lines an approver will be asked to weigh. */
const breaches = computed(() =>
  items.value.filter((i) => i.exceeds_policy).map((i) => ({
    name: i.category?.name ?? "Expense",
    over: Number(i.amount_claimed) - Number(i.policy_limit ?? 0),
  })));

const destinationName = computed(() =>
  destinations.value.find((d) => d.id === form.value.travel_destination_id)?.name ?? "—");
const accountName = computed(() =>
  accounts.value.find((a) => a.id === form.value.account_id)?.name ?? "—");
</script>

<template>
  <div class="wz-wrap">
    <!-- Success is a state of this screen rather than a redirect, so the
         reference stays visible with what happens next. -->
    <div v-if="submitted" class="wz-done">
      <h2 class="wz-done-title">Request submitted</h2>
      <p class="wz-done-sub">
        It has gone to your line manager for approval. You can follow it in Travel Requests.
      </p>
      <p v-if="breaches.length" class="wz-done-note">
        It exceeds policy on {{ breaches.length }} line{{ breaches.length > 1 ? "s" : "" }},
        so it may take longer to approve.
      </p>
      <button class="btn-primary" @click="router.push({ name: 'travel-requests' })">
        Back to Travel Requests
      </button>
    </div>

    <template v-else>
      <header class="wz-header">
        <div>
          <h1 class="wz-title">Request Travel</h1>
          <p class="wz-sub">Manage your travel requests and expenses</p>
        </div>
        <div class="wz-actions">
          <button class="btn-ghost" @click="router.push({ name: 'travel-requests' })">Cancel</button>
          <button v-if="step < 5" class="btn-primary" :disabled="saving" @click="next">Next</button>
          <button v-else class="btn-primary"
                  :disabled="saving || (items.length === 0 && legs.length === 0)" @click="submit">Submit</button>
        </div>
      </header>

      <div v-if="error" class="alert-error">{{ error }}</div>

      <div class="wz-body">
        <ol class="wz-steps">
          <li v-for="s in STEPS" :key="s.n" :class="['wz-step', { active: step === s.n, done: step > s.n }]">
            <span class="wz-step-n">{{ step > s.n ? "✓" : s.n }}</span>
            <span class="wz-step-text">
              <strong>{{ s.title }}</strong>
              <em>{{ s.hint }}</em>
            </span>
          </li>
        </ol>

        <section class="wz-panel">
          <!-- ── 1. Details ────────────────────────────────────────────── -->
          <template v-if="step === 1">
            <h2 class="wz-panel-title">Travel Details</h2>
            <div class="wz-grid">
              <label class="wz-field">
                <span>Destination</span>
                <select v-model.number="form.travel_destination_id" class="control">
                  <option :value="null">Select destination</option>
                  <option v-for="d in destinations" :key="d.id" :value="d.id">{{ d.name }}</option>
                </select>
              </label>
              <label class="wz-field">
                <span>Travel mode</span>
                <select v-model="form.travel_mode" class="control">
                  <option value="">Select mode</option>
                  <option value="air">Air</option><option value="road">Road</option>
                  <option value="rail">Rail</option><option value="sea">Sea</option>
                </select>
              </label>
              <label class="wz-field">
                <span>Priority</span>
                <select v-model="form.priority" class="control">
                  <option value="">Select priority</option>
                  <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
                </select>
              </label>
              <label class="wz-field">
                <span>Departure date <b>*</b></span>
                <input v-model="form.departure_date" class="control" type="date" />
              </label>
              <label class="wz-field">
                <span>Return date <b>*</b></span>
                <input v-model="form.return_date" class="control" type="date" />
              </label>
            </div>

            <div class="wz-toggles">
              <label class="wz-check"><input v-model="form.accommodation_needed" type="checkbox" /> Accommodation needed</label>
              <label class="wz-check"><input v-model="form.flexible_days" type="checkbox" /> Flexible days</label>
            </div>

            <h2 class="wz-panel-title">Purpose of Travel</h2>
            <div class="wz-grid">
              <label class="wz-field">
                <span>Client</span>
                <select v-model.number="form.account_id" class="control">
                  <option :value="null">Select client</option>
                  <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
              </label>
              <label class="wz-field">
                <!-- A free reference until Tasks ships. Requiring a real task
                     would block every request on a module that does not exist. -->
                <span>Linked task</span>
                <input v-model="form.linked_task_ref" class="control" placeholder="e.g. Data Migration Phase 2" />
              </label>
            </div>

            <label class="wz-field">
              <span>Purpose <b>*</b></span>
              <textarea v-model="form.purpose" class="control" rows="4"
                        placeholder="Why this trip is needed"></textarea>
            </label>
            <label class="wz-field">
              <span>Business impact if not approved</span>
              <textarea v-model="form.business_impact" class="control" rows="3"></textarea>
            </label>
          </template>

          <!-- ── 2. Transport ──────────────────────────────────────────── -->
          <template v-else-if="step === 2">
            <h2 class="wz-panel-title">How you are getting there</h2>
            <p class="wz-note">
              Transport is worked out from distance, fuel and mode — not from your grade.
              Add a journey for each leg: a flight out, and taxis once you are there.
            </p>

            <div class="wz-form-block">
              <div class="wz-row">
                <label class="wz-field">
                  <span>Mode</span>
                  <select v-model="newLeg.mode" class="control">
                    <option v-for="(m, key) in MODES" :key="key" :value="key">{{ m.label }}</option>
                  </select>
                </label>

                <label v-if="legNeeds('km')" class="wz-field">
                  <span>Estimated distance (km)</span>
                  <input v-model="newLeg.estimated_km" class="control" type="number" min="0" />
                </label>

                <label v-if="legNeeds('vehicle')" class="wz-field">
                  <span>Vehicle</span>
                  <select v-model.number="newLeg.transport_vehicle_type_id" class="control">
                    <option :value="null">Choose…</option>
                    <option v-for="v in vehicleTypes" :key="v.id" :value="v.id">
                      {{ v.name }}<template v-if="v.km_per_litre"> ({{ v.km_per_litre }} km/l)</template>
                    </option>
                  </select>
                </label>

                <label v-if="legNeeds('fuel')" class="wz-field">
                  <!-- Asked for here because a pump price held centrally is
                       out of date within a week. -->
                  <span>Fuel price today</span>
                  <input v-model="newLeg.fuel_price_per_litre" class="control" type="number" min="0" step="0.01" />
                </label>

                <label v-if="legNeeds('destination')" class="wz-field">
                  <span>Route</span>
                  <select v-model.number="newLeg.travel_destination_id" class="control">
                    <option :value="null">Choose…</option>
                    <option v-for="d in destinations" :key="d.id" :value="d.id">{{ d.name }}</option>
                  </select>
                </label>

                <label v-if="legNeeds('quote')" class="wz-field">
                  <span>Quoted cost</span>
                  <input v-model="newLeg.estimated_cost" class="control" type="number" min="0" />
                </label>
              </div>

              <div class="wz-row">
                <label class="wz-field"><span>From</span>
                  <input v-model="newLeg.from_location" class="control" placeholder="Nairobi" /></label>
                <label class="wz-field"><span>To</span>
                  <input v-model="newLeg.to_location" class="control" placeholder="Mombasa" /></label>
                <label class="wz-check">
                  <input v-model="newLeg.is_delivery" type="checkbox" /> This journey delivers an item
                </label>
              </div>

              <!-- A delivery routes to finance rather than a line manager, so
                   what is being moved is part of the record. -->
              <div v-if="newLeg.is_delivery" class="wz-row">
                <label class="wz-field wz-grow"><span>What is being delivered</span>
                  <input v-model="newLeg.item_description" class="control" placeholder="2 rack servers" /></label>
                <label class="wz-field"><span>Weight (kg)</span>
                  <input v-model="newLeg.item_weight_kg" class="control" type="number" min="0" /></label>
              </div>

              <p class="wz-note">{{ MODES[newLeg.mode]?.note }}</p>
              <p v-if="legBlocker" class="wz-breach">{{ legBlocker }}</p>

              <button class="btn-ghost" :disabled="saving || !!legBlocker" @click="addLeg">Add journey</button>
            </div>

            <table class="wz-table">
              <thead><tr><th>Mode</th><th>Route</th><th>Distance</th><th>Estimated</th><th></th></tr></thead>
              <tbody>
                <tr v-if="legs.length === 0"><td colspan="5" class="wz-none">No journeys added yet.</td></tr>
                <tr v-for="l in legs" :key="l.id">
                  <td>
                    {{ MODES[l.mode]?.label ?? l.mode }}
                    <span v-if="l.is_delivery" class="pill pill-neutral">delivery</span>
                  </td>
                  <td>{{ l.from_location || "—" }} → {{ l.to_location || "—" }}</td>
                  <td class="wz-amount">{{ l.estimated_km ? Number(l.estimated_km).toLocaleString() + " km" : "—" }}</td>
                  <td class="wz-amount">
                    <template v-if="l.estimated_cost">{{ totals.currency }} {{ Number(l.estimated_cost).toLocaleString() }}</template>
                    <span v-else class="pill pill-neutral">on reconciliation</span>
                  </td>
                  <td><button class="link-btn link-danger" @click="removeLeg(l)">Remove</button></td>
                </tr>
                <tr v-if="legTotal > 0" class="wz-total">
                  <td colspan="3">Transport total</td>
                  <td class="wz-amount">{{ totals.currency }} {{ legTotal.toLocaleString() }}</td>
                  <td></td>
                </tr>
              </tbody>
            </table>
          </template>

          <!-- ── 3. Expenses ───────────────────────────────────────────── -->
          <template v-else-if="step === 3">
            <h2 class="wz-panel-title">Advance Request</h2>
            <div class="wz-grid">
              <label class="wz-field">
                <span>Advance amount</span>
                <input v-model.number="form.advance_required" class="control" type="number" min="0" placeholder="Amount" />
              </label>
            </div>
            <label class="wz-field">
              <span>Justification</span>
              <textarea v-model="form.advance_justification" class="control" rows="3"
                        placeholder="Why the money is needed before the trip"></textarea>
            </label>

            <div class="wz-panel-head">
              <h2 class="wz-panel-title">Estimated Expenses</h2>
              <button class="btn-ghost" @click="showExpense = true">+ Add expense</button>
            </div>

            <table class="wz-table">
              <thead>
                <tr><th>#</th><th>Category</th><th>Description</th><th>Estimated</th><th>Policy</th><th></th></tr>
              </thead>
              <tbody>
                <tr v-if="items.length === 0"><td colspan="6" class="wz-none">No expenses added yet.</td></tr>
                <tr v-for="(i, n) in items" :key="i.id">
                  <td>{{ n + 1 }}</td>
                  <td>{{ i.category?.name ?? "—" }}</td>
                  <td>{{ i.description ?? "—" }}</td>
                  <td class="wz-amount">{{ totals.currency }} {{ Number(i.amount_claimed).toLocaleString() }}</td>
                  <td>
                    <span v-if="i.exceeds_policy" class="pill pill-warn">Exceeds policy</span>
                    <span v-else-if="i.policy_limit" class="pill pill-ok">Within policy</span>
                    <span v-else class="pill pill-neutral">No limit set</span>
                  </td>
                  <td><button class="link-btn link-danger" @click="removeExpense(i)">Remove</button></td>
                </tr>
                <tr v-if="items.length" class="wz-total">
                  <td colspan="3">Total</td>
                  <td class="wz-amount">{{ totals.currency }} {{ Number(totals.total_claimed).toLocaleString() }}</td>
                  <td colspan="2"></td>
                </tr>
              </tbody>
            </table>

            <!-- Shown, not blocked: the approver decides, and a refused form
                 just moves the conversation to email. -->
            <div v-if="breaches.length" class="wz-breach">
              <strong>Policy compliance</strong>
              <ul>
                <li v-for="b in breaches" :key="b.name">
                  {{ b.name }} exceeds the limit by {{ totals.currency }} {{ b.over.toLocaleString() }}
                </li>
              </ul>
            </div>
          </template>

          <!-- ── 4. Itinerary ──────────────────────────────────────────── -->
          <template v-else-if="step === 4">
            <h2 class="wz-panel-title">Itinerary</h2>
            <p class="wz-note">
              What you will be doing, day by day. An approver reads this to judge whether the
              trip is necessary, and the expenses to judge whether it is affordable.
            </p>

            <div class="wz-row">
              <label class="wz-field"><span>Date</span>
                <input v-model="newItinerary.starts_on" class="control" type="date" /></label>
              <label class="wz-field wz-grow"><span>Activity</span>
                <input v-model="newItinerary.activity" class="control" placeholder="Flight Nairobi → Mombasa" /></label>
              <label class="wz-field"><span>Estimated cost</span>
                <input v-model="newItinerary.estimated_cost" class="control" type="number" min="0" /></label>
              <button class="btn-ghost" :disabled="saving" @click="addItinerary">Add</button>
            </div>

            <table class="wz-table">
              <thead><tr><th>Date</th><th>Activity</th><th>Estimated</th></tr></thead>
              <tbody>
                <tr v-if="itinerary.length === 0"><td colspan="3" class="wz-none">No itinerary yet.</td></tr>
                <tr v-for="it in itinerary" :key="it.id">
                  <td>{{ String(it.starts_on).slice(0, 10) }}</td>
                  <td>{{ it.activity }}</td>
                  <td class="wz-amount">{{ it.estimated_cost ? Number(it.estimated_cost).toLocaleString() : "—" }}</td>
                </tr>
              </tbody>
            </table>
          </template>

          <!-- ── 5. Review ─────────────────────────────────────────────── -->
          <template v-else>
            <h2 class="wz-panel-title">Review</h2>

            <dl class="wz-review">
              <div><dt>Destination</dt><dd>{{ destinationName }}</dd></div>
              <div><dt>Travel dates</dt><dd>{{ form.departure_date }} → {{ form.return_date }}</dd></div>
              <div><dt>Mode</dt><dd>{{ form.travel_mode || "—" }}</dd></div>
              <div><dt>Client</dt><dd>{{ accountName }}</dd></div>
              <div><dt>Accommodation</dt><dd>{{ form.accommodation_needed ? "Yes" : "No" }}</dd></div>
              <div><dt>Flexible days</dt><dd>{{ form.flexible_days ? "Yes" : "No" }}</dd></div>
            </dl>

            <p class="wz-purpose">{{ form.purpose }}</p>

            <table v-if="legs.length" class="wz-table">
              <thead><tr><th>Journey</th><th>Route</th><th>Estimated</th></tr></thead>
              <tbody>
                <tr v-for="l in legs" :key="l.id">
                  <td>{{ MODES[l.mode]?.label ?? l.mode }}</td>
                  <td>{{ l.from_location || "—" }} → {{ l.to_location || "—" }}</td>
                  <td class="wz-amount">
                    <template v-if="l.estimated_cost">{{ totals.currency }} {{ Number(l.estimated_cost).toLocaleString() }}</template>
                    <span v-else class="pill pill-neutral">on reconciliation</span>
                  </td>
                </tr>
              </tbody>
            </table>

            <table class="wz-table">
              <thead><tr><th>Category</th><th>Estimated</th><th>Policy</th></tr></thead>
              <tbody>
                <tr v-for="i in items" :key="i.id">
                  <td>{{ i.category?.name ?? "—" }}</td>
                  <td class="wz-amount">{{ totals.currency }} {{ Number(i.amount_claimed).toLocaleString() }}</td>
                  <td>
                    <span v-if="i.exceeds_policy" class="pill pill-warn">Exceeds policy</span>
                    <span v-else class="pill pill-ok">Within policy</span>
                  </td>
                </tr>
                <tr class="wz-total">
                  <td>Total</td>
                  <td class="wz-amount">{{ totals.currency }} {{ Number(totals.total_claimed).toLocaleString() }}</td>
                  <td></td>
                </tr>
              </tbody>
            </table>

            <p v-if="items.length === 0 && legs.length === 0" class="wz-note">
              Add a journey or an expense before submitting — an empty request tells an
              approver nothing about what is being asked for.
            </p>
          </template>
        </section>
      </div>

      <div class="wz-nav">
        <button v-if="step > 1" class="btn-ghost" @click="step = step - 1">Back</button>
      </div>
    </template>

    <!-- Add expense -->
    <div v-if="showExpense" class="wz-modal-backdrop" @click.self="showExpense = false">
      <div class="wz-modal">
        <h3 class="wz-modal-title">Add expense</h3>
        <label class="wz-field">
          <span>Category</span>
          <select v-model.number="newExpense.travel_expense_category_id" class="control">
            <option :value="null">Choose…</option>
            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </label>
        <label class="wz-field">
          <span>Amount</span>
          <input v-model="newExpense.amount_claimed" class="control" type="number" min="0" />
        </label>
        <label class="wz-field">
          <span>Description</span>
          <textarea v-model="newExpense.description" class="control" rows="3"></textarea>
        </label>
        <div class="wz-modal-actions">
          <button class="btn-ghost" @click="showExpense = false">Cancel</button>
          <button class="btn-primary" :disabled="saving" @click="addExpense">Add expense</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.wz-wrap { padding: var(--space-6); max-width: 78rem; }

.wz-header { @apply flex flex-wrap items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-4); }
.wz-title { font-size: var(--text-2xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.wz-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }
.wz-actions { @apply flex; gap: var(--space-2); }

.wz-body { @apply flex flex-wrap items-start; gap: var(--space-6); }

.wz-steps { @apply flex flex-col; list-style: none; margin: 0; padding: 0; gap: var(--space-4); min-width: 15rem; }
.wz-step { @apply flex items-start; gap: var(--space-3); }
.wz-step-n {
  @apply flex items-center justify-center shrink-0;
  width: 1.75rem; height: 1.75rem; border-radius: 999px;
  font-size: var(--text-xs-size); font-weight: 600;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
}
.wz-step.active .wz-step-n { background: var(--color-brand-base); color: var(--color-on-brand); }
.wz-step.done   .wz-step-n { background: var(--color-success-subtle); color: var(--color-success-text); }
.wz-step-text { @apply flex flex-col; }
.wz-step-text strong { font-size: var(--text-sm-size); color: var(--color-text); font-weight: 500; }
.wz-step.active .wz-step-text strong { color: var(--color-brand-on-surface); }
.wz-step-text em { font-style: normal; font-size: var(--text-xs-size); color: var(--color-text-light); }

.wz-panel {
  flex: 1; min-width: 22rem;
  padding: var(--space-5);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  @apply flex flex-col;
  gap: var(--space-4);
}
.wz-panel-head { @apply flex items-center justify-between; gap: var(--space-3); }
.wz-panel-title { font-size: var(--text-base-size); font-weight: 600; color: var(--color-text); margin: 0; }

.wz-grid { @apply grid; gap: var(--space-4); grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); }
.wz-row  { @apply flex flex-wrap items-end; gap: var(--space-3); }
.wz-grow { flex: 1; min-width: 14rem; }

.wz-field { @apply flex flex-col; gap: var(--space-1); }
.wz-field span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.wz-field b { color: var(--color-danger-text); }

.wz-toggles { @apply flex flex-wrap; gap: var(--space-5); }
.wz-check { @apply flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }

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

.wz-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.wz-table th {
  text-align: left; padding: var(--space-2) var(--space-3);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  text-transform: uppercase; letter-spacing: 0.04em;
  border-bottom: 1px solid var(--color-border);
}
.wz-table td { padding: var(--space-3); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.wz-amount { font-variant-numeric: tabular-nums; }
.wz-total td { font-weight: 600; }
.wz-none { text-align: center; color: var(--color-text-light); padding: var(--space-5); }

.wz-note { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; max-width: 62ch; }
.wz-purpose { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; white-space: pre-wrap; }

.wz-review { @apply grid; gap: var(--space-3); grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); margin: 0; }
.wz-review dt { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.wz-review dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }

.wz-form-block {
  padding: var(--space-4);
  background: var(--color-surface-sunken);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  @apply flex flex-col;
  gap: var(--space-3);
}

.wz-breach {
  padding: var(--space-3) var(--space-4);
  border-radius: var(--radius-md);
  background: var(--color-warning-subtle);
  color: var(--color-warning-text);
  font-size: var(--text-sm-size);
}
.wz-breach ul { margin: var(--space-1) 0 0; padding-left: var(--space-5); }

.pill {
  display: inline-block; width: fit-content;
  padding: 2px var(--space-2); border-radius: 999px;
  font-size: var(--text-xs-size);
}
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); }

.wz-nav { margin-top: var(--space-4); }

.wz-done { @apply flex flex-col items-center; text-align: center; padding: var(--space-8); gap: var(--space-2); }
.wz-done-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.wz-done-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; max-width: 46ch; }
.wz-done-note  {
  font-size: var(--text-sm-size); margin: var(--space-2) 0 var(--space-3);
  padding: var(--space-2) var(--space-3); border-radius: var(--radius-md);
  background: var(--color-warning-subtle); color: var(--color-warning-text);
}

.wz-modal-backdrop {
  @apply fixed inset-0 flex items-center justify-center z-50;
  background: rgb(0 0 0 / 0.45);
  padding: var(--space-4);
}
.wz-modal {
  @apply flex flex-col w-full;
  max-width: 26rem; gap: var(--space-3);
  padding: var(--space-5);
  background: var(--color-surface-raised);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg);
}
.wz-modal-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.wz-modal-actions { @apply flex justify-end; gap: var(--space-2); margin-top: var(--space-2); }

.btn-primary, .btn-ghost {
  @apply cursor-pointer;
  padding: 8px var(--space-4);
  border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-ghost {
  background: transparent; color: var(--color-text);
  border: 1px solid var(--color-border-strong);
}
.btn-primary:disabled, .btn-ghost:disabled { @apply cursor-not-allowed opacity-50; }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }
.link-danger { color: var(--color-danger-text); }

.alert-error {
  padding: var(--space-3) var(--space-4); border-radius: var(--radius-md);
  font-size: var(--text-sm-size);
  background: var(--color-danger-subtle); color: var(--color-danger-text);
  margin-bottom: var(--space-4);
}
</style>
