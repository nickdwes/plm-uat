<script setup lang="ts">
/**
 * Raising a delivery.
 *
 * Its own journey rather than the travel wizard, because the questions differ.
 * A delivery has no per diem and no accommodation; it has a consignment, a
 * courier and a vehicle. Sending finance through a form that asks about
 * allowances to move a server is how a process gets worked around.
 *
 * The request is created as a delivery from the first step, so it routes to
 * finance rather than a line manager from the moment it is submitted — not
 * once somebody remembers to tick a box on a later screen.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import EmployeePicker from "../../components/EmployeePicker.vue";

interface Option { id: number; name: string }
interface VehicleType { id: number; name: string; km_per_litre: string | null; load_capacity_kg: string | null }

const router = useRouter();
const org = () => currentOrgSlug.value;

const step      = ref(1);
const saving    = ref(false);
const error     = ref<string | null>(null);
const requestId = ref<number | null>(null);
const submitted = ref(false);

const destinations   = ref<Option[]>([]);
const vehicleTypes   = ref<VehicleType[]>([]);
const hasMileageRate = ref(true);

const request  = ref<any>(null);
const manifest = computed(() => request.value?.manifest ?? []);
const couriers = computed(() => request.value?.couriers ?? []);
const legs     = computed(() => request.value?.transport_legs ?? []);

const STEPS = [
  { n: 1, title: "Consignment", hint: "Where it is going, and why" },
  { n: 2, title: "Manifest",    hint: "What is being moved" },
  { n: 3, title: "Transport",   hint: "Vehicle and who is driving" },
  { n: 4, title: "Review",      hint: "Check and submit" },
];

const form = ref({
  travel_destination_id: null as number | null,
  departure_date: "",
  return_date: "",
  delivery_reference: "",
  purpose: "",
  business_impact: "",
});

const newItem    = ref({ description: "", quantity: "1", unit: "pcs", weight_kg: "", reference: "" });
const newCourier = ref<number | null>(null);
const newLeg     = ref({
  mode: "hired_vehicle",
  estimated_km: "", fuel_price_per_litre: "",
  transport_vehicle_type_id: null as number | null,
  from_location: "", to_location: "",
});

function fail(e: unknown) {
  error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Something went wrong.";
}

onMounted(async () => {
  try {
    const l = await api<any>("GET", "/api/v1/travel/lookups", { org: org() });
    destinations.value   = l.destinations;
    vehicleTypes.value   = l.vehicle_types;
    hasMileageRate.value = l.has_mileage_rate;
  } catch (e) { fail(e); }
});

const canLeaveStepOne = computed(() =>
  !!form.value.departure_date && !!form.value.purpose.trim());

/** Created as a delivery, so routing is right from the start. */
async function saveConsignment(): Promise<boolean> {
  saving.value = true;
  error.value = null;
  try {
    const body = {
      ...form.value,
      travel_type: "delivery",
      return_date: form.value.return_date || form.value.departure_date,
    };

    const res = requestId.value
      ? await api<{ data: any }>("PUT", `/api/v1/travel-requests/${requestId.value}`, { org: org(), body })
      : await api<{ data: any }>("POST", "/api/v1/travel-requests", { org: org(), body });

    requestId.value = res.data.id;
    request.value = res.data;
    return true;
  } catch (e) { fail(e); return false; } finally { saving.value = false; }
}

const url = (path: string) => `/api/v1/travel-requests/${requestId.value}${path}`;

async function addItem() {
  if (!newItem.value.description.trim()) return;
  saving.value = true;
  try {
    const body: Record<string, unknown> = { ...newItem.value };
    Object.keys(body).forEach((k) => body[k] === "" && delete body[k]);
    const res = await api<{ data: any }>("POST", url("/manifest"), { org: org(), body });
    request.value = res.data;
    newItem.value = { description: "", quantity: "1", unit: "pcs", weight_kg: "", reference: "" };
  } catch (e) { fail(e); } finally { saving.value = false; }
}

async function removeItem(item: any) {
  try {
    const res = await api<{ data: any }>("DELETE", url(`/manifest/${item.id}`), { org: org() });
    request.value = res.data;
  } catch (e) { fail(e); }
}

async function addCourier() {
  if (!newCourier.value) return;
  saving.value = true;
  try {
    const res = await api<{ data: any }>("POST", url("/couriers"), {
      org: org(), body: { employee_id: newCourier.value },
    });
    request.value = res.data;
    newCourier.value = null;
  } catch (e) { fail(e); } finally { saving.value = false; }
}

async function removeCourier(c: any) {
  try {
    const res = await api<{ data: any }>("DELETE", url(`/couriers/${c.id}`), { org: org() });
    request.value = res.data;
  } catch (e) { fail(e); }
}

/** Total weight guides the vehicle, which is the requirement's own rule. */
const totalWeight = computed(() =>
  manifest.value.reduce((sum: number, i: any) => sum + Number(i.weight_kg ?? 0) * Number(i.quantity ?? 1), 0));

const tooHeavy = computed(() => {
  const chosen = vehicleTypes.value.find((v) => v.id === newLeg.value.transport_vehicle_type_id);
  const capacity = Number(chosen?.load_capacity_kg ?? 0);
  return capacity > 0 && totalWeight.value > capacity ? { capacity, chosen } : null;
});

const legBlocker = computed(() => {
  if (newLeg.value.mode === "self_drive" && !hasMileageRate.value)
    return "No mileage rate is configured, so a self-drive journey cannot be costed.";
  if (newLeg.value.mode === "hired_vehicle" && vehicleTypes.value.length === 0)
    return "No vehicle types are configured, so fuel cannot be estimated.";
  return null;
});

async function addLeg() {
  saving.value = true;
  error.value = null;
  try {
    const body: Record<string, unknown> = { ...newLeg.value, is_delivery: true, travel_type: "delivery" };
    Object.keys(body).forEach((k) => (body[k] === "" || body[k] === null) && delete body[k]);
    body.is_delivery = true;
    body.travel_type = "delivery";

    // The manifest is what is being moved, so the leg describes it rather
    // than asking the same question twice.
    if (manifest.value.length) {
      body.item_description = manifest.value.map((i: any) => i.description).join(", ").slice(0, 500);
      if (totalWeight.value > 0) body.item_weight_kg = totalWeight.value;
    }

    const res = await api<{ data: any }>("POST", url("/legs"), { org: org(), body });
    request.value = res.data;
    newLeg.value = { mode: "hired_vehicle", estimated_km: "", fuel_price_per_litre: "",
      transport_vehicle_type_id: null, from_location: "", to_location: "" };
  } catch (e) { fail(e); } finally { saving.value = false; }
}

async function next() {
  if (step.value === 1) {
    if (!canLeaveStepOne.value) { error.value = "A date and a purpose are required."; return; }
    if (!(await saveConsignment())) return;
  }
  step.value = Math.min(4, step.value + 1);
}

async function submit() {
  saving.value = true;
  error.value = null;
  try {
    await api("POST", url("/submit"), { org: org() });
    submitted.value = true;
  } catch (e) { fail(e); } finally { saving.value = false; }
}

const gaps = computed(() => {
  const out: string[] = [];
  if (!manifest.value.length) out.push("a manifest of what is being moved");
  if (!couriers.value.length) out.push("somebody to carry it");
  if (!legs.value.length) out.push("a vehicle");
  return out;
});

const destinationName = computed(() =>
  destinations.value.find((d) => d.id === form.value.travel_destination_id)?.name ?? "—");
</script>

<template>
  <div class="dw-wrap">
    <div v-if="submitted" class="dw-done">
      <h2 class="dw-done-title">Delivery request submitted</h2>
      <p class="dw-done-sub">
        It has gone to finance for approval. Large consignments also need the COO.
      </p>
      <button class="btn-primary" @click="router.push({ name: 'deliveries' })">Back to Deliveries</button>
    </div>

    <template v-else>
      <header class="dw-header">
        <div>
          <h1 class="dw-title">Delivery request</h1>
          <p class="dw-sub">Moving an item — approved by finance, not by a line manager</p>
        </div>
        <div class="dw-actions">
          <button class="btn-ghost" @click="router.push({ name: 'deliveries' })">Cancel</button>
          <button v-if="step < 4" class="btn-primary" :disabled="saving" @click="next">Next</button>
          <button v-else class="btn-primary" :disabled="saving || gaps.length > 0" @click="submit">Submit</button>
        </div>
      </header>

      <div v-if="error" class="alert-error">{{ error }}</div>

      <div class="dw-body">
        <ol class="dw-steps">
          <li v-for="s in STEPS" :key="s.n" :class="['dw-step', { active: step === s.n, done: step > s.n }]">
            <span class="dw-step-n">{{ step > s.n ? "✓" : s.n }}</span>
            <span class="dw-step-text"><strong>{{ s.title }}</strong><em>{{ s.hint }}</em></span>
          </li>
        </ol>

        <section class="dw-panel">
          <!-- 1. Consignment -->
          <template v-if="step === 1">
            <h2 class="dw-panel-title">Where it is going</h2>
            <div class="dw-grid">
              <label class="dw-field">
                <span>Destination</span>
                <select v-model.number="form.travel_destination_id" class="control">
                  <option :value="null">Select destination</option>
                  <option v-for="d in destinations" :key="d.id" :value="d.id">{{ d.name }}</option>
                </select>
              </label>
              <label class="dw-field"><span>Delivery date <b>*</b></span>
                <input v-model="form.departure_date" class="control" type="date" /></label>
              <label class="dw-field"><span>Return date</span>
                <input v-model="form.return_date" class="control" type="date" /></label>
              <label class="dw-field">
                <!-- Ties the consignment to the order it came from. -->
                <span>LPO / order reference</span>
                <input v-model="form.delivery_reference" class="control" placeholder="LPO-2026-0142" />
              </label>
            </div>

            <label class="dw-field"><span>Purpose <b>*</b></span>
              <textarea v-model="form.purpose" class="control" rows="3"
                        placeholder="Why this needs delivering"></textarea></label>
            <label class="dw-field"><span>Impact if not delivered</span>
              <textarea v-model="form.business_impact" class="control" rows="2"></textarea></label>
          </template>

          <!-- 2. Manifest -->
          <template v-else-if="step === 2">
            <h2 class="dw-panel-title">What is being moved</h2>
            <p class="dw-note">
              Line by line, so arrival can be checked off against it. Weight guides which
              vehicle is needed on the next step.
            </p>

            <div class="dw-form">
              <div class="dw-row">
                <label class="dw-field dw-grow"><span>Item</span>
                  <input v-model="newItem.description" class="control" placeholder="Rack server" /></label>
                <label class="dw-field dw-sm"><span>Qty</span>
                  <input v-model="newItem.quantity" class="control" type="number" min="0.01" /></label>
                <label class="dw-field dw-sm"><span>Unit</span>
                  <input v-model="newItem.unit" class="control" /></label>
                <label class="dw-field dw-sm"><span>Weight each (kg)</span>
                  <input v-model="newItem.weight_kg" class="control" type="number" min="0" /></label>
                <label class="dw-field"><span>Reference</span>
                  <input v-model="newItem.reference" class="control" placeholder="Serial" /></label>
                <button class="btn-ghost" :disabled="saving" @click="addItem">Add</button>
              </div>
            </div>

            <table class="dw-table">
              <thead><tr><th>Item</th><th>Qty</th><th>Weight</th><th>Reference</th><th></th></tr></thead>
              <tbody>
                <tr v-if="!manifest.length"><td colspan="5" class="dw-none">Nothing added yet.</td></tr>
                <tr v-for="i in manifest" :key="i.id">
                  <td>{{ i.description }}</td>
                  <td class="dw-num">{{ Number(i.quantity) }} {{ i.unit }}</td>
                  <td class="dw-num">{{ i.weight_kg ? Number(i.weight_kg) + " kg" : "—" }}</td>
                  <td>{{ i.reference ?? "—" }}</td>
                  <td><button class="link-btn link-danger" @click="removeItem(i)">Remove</button></td>
                </tr>
                <tr v-if="totalWeight > 0" class="dw-total">
                  <td colspan="2">Total weight</td>
                  <td class="dw-num">{{ totalWeight.toLocaleString() }} kg</td>
                  <td colspan="2"></td>
                </tr>
              </tbody>
            </table>

            <h2 class="dw-panel-title">Who is carrying it</h2>
            <div class="dw-form">
              <div class="dw-row">
                <div class="dw-field dw-grow">
                  <span class="dw-label">Courier</span>
                  <EmployeePicker v-model="newCourier" placeholder="Search by name or number…" />
                </div>
                <button class="btn-ghost" :disabled="saving || !newCourier" @click="addCourier">Add</button>
              </div>
            </div>

            <ul class="dw-list">
              <li v-if="!couriers.length" class="dw-none">Nobody assigned yet.</li>
              <li v-for="c in couriers" :key="c.id">
                {{ c.employee?.name ?? "—" }}
                <span v-if="c.is_primary" class="pill pill-ok">accountable</span>
                <button class="link-btn link-danger" @click="removeCourier(c)">Remove</button>
              </li>
            </ul>
          </template>

          <!-- 3. Transport -->
          <template v-else-if="step === 3">
            <h2 class="dw-panel-title">Vehicle</h2>
            <p class="dw-note">
              Costed from distance and fuel. A hired vehicle needs today's pump price, because
              a price held centrally is out of date within a week.
            </p>

            <div class="dw-form">
              <div class="dw-row">
                <label class="dw-field">
                  <span>Mode</span>
                  <select v-model="newLeg.mode" class="control">
                    <option value="hired_vehicle">Hired vehicle</option>
                    <option value="self_drive">Self-drive</option>
                  </select>
                </label>
                <label class="dw-field">
                  <span>Vehicle</span>
                  <select v-model.number="newLeg.transport_vehicle_type_id" class="control">
                    <option :value="null">Choose…</option>
                    <option v-for="v in vehicleTypes" :key="v.id" :value="v.id">
                      {{ v.name }}<template v-if="v.load_capacity_kg"> — up to {{ Number(v.load_capacity_kg) }} kg</template>
                    </option>
                  </select>
                </label>
                <label class="dw-field"><span>Distance (km)</span>
                  <input v-model="newLeg.estimated_km" class="control" type="number" min="0" /></label>
                <label v-if="newLeg.mode === 'hired_vehicle'" class="dw-field"><span>Fuel price today</span>
                  <input v-model="newLeg.fuel_price_per_litre" class="control" type="number" min="0" step="0.01" /></label>
              </div>
              <div class="dw-row">
                <label class="dw-field"><span>From</span>
                  <input v-model="newLeg.from_location" class="control" /></label>
                <label class="dw-field"><span>To</span>
                  <input v-model="newLeg.to_location" class="control" /></label>
              </div>

              <!-- The consignment decides the vehicle, which is the rule the
                   requirement states. Warned rather than blocked: the weights
                   entered may be estimates. -->
              <p v-if="tooHeavy" class="dw-warn">
                The manifest weighs {{ totalWeight.toLocaleString() }} kg but
                {{ tooHeavy.chosen?.name }} carries up to {{ Number(tooHeavy.capacity).toLocaleString() }} kg.
              </p>
              <p v-if="legBlocker" class="dw-warn">{{ legBlocker }}</p>

              <button class="btn-ghost" :disabled="saving || !!legBlocker" @click="addLeg">Add vehicle</button>
            </div>

            <table class="dw-table">
              <thead><tr><th>Mode</th><th>Route</th><th>Distance</th><th>Estimated</th></tr></thead>
              <tbody>
                <tr v-if="!legs.length"><td colspan="4" class="dw-none">No vehicle yet.</td></tr>
                <tr v-for="l in legs" :key="l.id">
                  <td>{{ l.mode === "self_drive" ? "Self-drive" : "Hired vehicle" }}</td>
                  <td>{{ l.from_location || "—" }} → {{ l.to_location || "—" }}</td>
                  <td class="dw-num">{{ l.estimated_km ? Number(l.estimated_km) + " km" : "—" }}</td>
                  <td class="dw-num">
                    <template v-if="l.estimated_cost">KES {{ Number(l.estimated_cost).toLocaleString() }}</template>
                    <span v-else class="pill pill-neutral">not costed</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </template>

          <!-- 4. Review -->
          <template v-else>
            <h2 class="dw-panel-title">Review</h2>
            <dl class="dw-review">
              <div><dt>Destination</dt><dd>{{ destinationName }}</dd></div>
              <div><dt>Delivery date</dt><dd>{{ form.departure_date }}</dd></div>
              <div><dt>Reference</dt><dd>{{ form.delivery_reference || "—" }}</dd></div>
              <div><dt>Items</dt><dd>{{ manifest.length }} line(s), {{ totalWeight.toLocaleString() }} kg</dd></div>
              <div><dt>Couriers</dt><dd>{{ couriers.map((c: any) => c.employee?.name).join(", ") || "—" }}</dd></div>
              <div><dt>Estimated cost</dt>
                <dd>KES {{ Number(request?.total_claimed ?? 0).toLocaleString() }}</dd></div>
            </dl>

            <p class="dw-note">{{ form.purpose }}</p>

            <div v-if="gaps.length" class="dw-warn">
              <strong>Still needed</strong>
              <ul><li v-for="g in gaps" :key="g">{{ g }}</li></ul>
            </div>
            <p v-else class="dw-note">
              This will go to finance for approval, and to the COO if the value is large enough.
            </p>
          </template>
        </section>
      </div>

      <div class="dw-nav">
        <button v-if="step > 1" class="btn-ghost" @click="step = step - 1">Back</button>
      </div>
    </template>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.dw-wrap { padding: var(--space-6); max-width: 78rem; }

.dw-header { @apply flex flex-wrap items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-4); }
.dw-title { font-size: var(--text-2xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.dw-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }
.dw-actions { @apply flex; gap: var(--space-2); }

.dw-body { @apply flex flex-wrap items-start; gap: var(--space-6); }

.dw-steps { @apply flex flex-col; list-style: none; margin: 0; padding: 0; gap: var(--space-4); min-width: 14rem; }
.dw-step { @apply flex items-start; gap: var(--space-3); }
.dw-step-n {
  @apply flex items-center justify-center shrink-0;
  width: 1.75rem; height: 1.75rem; border-radius: 999px;
  font-size: var(--text-xs-size); font-weight: 600;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
}
.dw-step.active .dw-step-n { background: var(--color-brand-base); color: var(--color-on-brand); }
.dw-step.done   .dw-step-n { background: var(--color-success-subtle); color: var(--color-success-text); }
.dw-step-text { @apply flex flex-col; }
.dw-step-text strong { font-size: var(--text-sm-size); color: var(--color-text); font-weight: 500; }
.dw-step.active .dw-step-text strong { color: var(--color-brand-on-surface); }
.dw-step-text em { font-style: normal; font-size: var(--text-xs-size); color: var(--color-text-light); }

.dw-panel {
  flex: 1; min-width: 22rem;
  padding: var(--space-5);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  @apply flex flex-col;
  gap: var(--space-4);
}
.dw-panel-title { font-size: var(--text-base-size); font-weight: 600; color: var(--color-text); margin: 0; }

.dw-grid { @apply grid; gap: var(--space-4); grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); }
.dw-row  { @apply flex flex-wrap items-end; gap: var(--space-3); }
.dw-field { @apply flex flex-col; gap: var(--space-1); min-width: 8rem; }
.dw-field span, .dw-label { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.dw-field b { color: var(--color-danger-text); }
.dw-grow { flex: 1; min-width: 14rem; }
.dw-sm { max-width: 7rem; }

.dw-form {
  padding: var(--space-4);
  background: var(--color-surface-sunken);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  @apply flex flex-col;
  gap: var(--space-3);
}

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.dw-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.dw-table th {
  text-align: left; padding: var(--space-2) var(--space-3);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  text-transform: uppercase; letter-spacing: 0.04em;
  border-bottom: 1px solid var(--color-border);
}
.dw-table td { padding: var(--space-3); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.dw-num { font-variant-numeric: tabular-nums; }
.dw-total td { font-weight: 600; }
.dw-none { text-align: center; color: var(--color-text-light); padding: var(--space-5); }

.dw-list { list-style: none; margin: 0; padding: 0; @apply flex flex-col; gap: var(--space-2); }
.dw-list li { @apply flex items-center; gap: var(--space-3); font-size: var(--text-sm-size); color: var(--color-text); }

.dw-note { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; max-width: 64ch; }
.dw-warn {
  padding: var(--space-3) var(--space-4); border-radius: var(--radius-md);
  background: var(--color-warning-subtle); color: var(--color-warning-text);
  font-size: var(--text-sm-size); margin: 0;
}
.dw-warn ul { margin: var(--space-1) 0 0; padding-left: var(--space-5); }

.dw-review { @apply grid; gap: var(--space-3); grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); margin: 0; }
.dw-review dt { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.dw-review dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }

.dw-nav { margin-top: var(--space-4); }

.dw-done { @apply flex flex-col items-center; text-align: center; padding: var(--space-8); gap: var(--space-2); }
.dw-done-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.dw-done-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0 0 var(--space-3); max-width: 46ch; }

.pill { display: inline-block; width: fit-content; padding: 2px var(--space-2); border-radius: 999px; font-size: var(--text-xs-size); }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }
.link-danger { color: var(--color-danger-text); }

.btn-primary, .btn-ghost {
  @apply cursor-pointer; padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-ghost   { background: transparent; color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-primary:disabled, .btn-ghost:disabled { @apply cursor-not-allowed opacity-50; }

.alert-error {
  padding: var(--space-3) var(--space-4); border-radius: var(--radius-md);
  font-size: var(--text-sm-size);
  background: var(--color-danger-subtle); color: var(--color-danger-text);
  margin-bottom: var(--space-4);
}
</style>
