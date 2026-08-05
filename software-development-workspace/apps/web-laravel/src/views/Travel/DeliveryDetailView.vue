<script setup lang="ts">
/**
 * One delivery.
 *
 * Three questions, in the order they get asked: what is in the vehicle, who is
 * carrying it, and did it arrive. The manifest is signed off line by line
 * because a delivery where four of five items turned up is the case that
 * matters — a single "delivered" tick cannot express it.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import EmployeePicker from "../../components/EmployeePicker.vue";

const props  = defineProps<{ id: string }>();
const router = useRouter();
const org    = () => currentOrgSlug.value;

const request = ref<any>(null);
const loading = ref(true);
const saving  = ref(false);
const error   = ref<string | null>(null);
const notice  = ref<string | null>(null);

const newItem    = ref({ description: "", quantity: "1", unit: "pcs", weight_kg: "", reference: "" });
const newCourier = ref<number | null>(null);
const noteFile   = ref<File | null>(null);

function fail(e: unknown) {
  error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Something went wrong.";
}

function ok(message: string) {
  notice.value = message;
  error.value = null;
  setTimeout(() => (notice.value = null), 4000);
}

async function load() {
  loading.value = true;
  try {
    const res = await api<{ data: any }>("GET", `/api/v1/travel-requests/${props.id}`, { org: org() });
    request.value = res.data;
  } catch (e) { fail(e); } finally { loading.value = false; }
}

const url = (path: string) => `/api/v1/travel-requests/${props.id}${path}`;

// ── Manifest ────────────────────────────────────────────────────────────────
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

/**
 * Sign a line off on arrival.
 *
 * Sent on its own so the request stays gated on seeing the delivery rather
 * than editing it — receiving happens after approval, when what was sent can
 * no longer be changed.
 */
async function receive(item: any) {
  saving.value = true;
  try {
    const res = await api<{ data: any }>("PUT", url(`/manifest/${item.id}`), {
      org: org(),
      body: {
        quantity_received: item.quantity_received,
        condition_on_arrival: item.condition_on_arrival || null,
      },
    });
    request.value = res.data;
    ok("Signed for.");
  } catch (e) { fail(e); } finally { saving.value = false; }
}

// ── Couriers ────────────────────────────────────────────────────────────────
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

// ── Delivery note ───────────────────────────────────────────────────────────
function pickNote(e: Event) {
  noteFile.value = (e.target as HTMLInputElement).files?.[0] ?? null;
}

async function uploadNote() {
  if (!noteFile.value) return;
  saving.value = true;
  try {
    const fd = new FormData();
    fd.append("document_type", "delivery_note");
    fd.append("file", noteFile.value);

    const res = await api<{ data: any }>("POST", url("/attachments"), { org: org(), formData: fd });
    request.value = res.data;
    noteFile.value = null;
    ok("Delivery note recorded.");
  } catch (e) { fail(e); } finally { saving.value = false; }
}

const noteAttachment = computed(() =>
  (request.value?.attachments ?? []).find((a: any) => a.id === request.value?.delivery_note_attachment_id) ?? null);

/** What still stands between this delivery and being closed. */
const gaps = computed(() => {
  const r = request.value;
  if (!r) return [];
  const out: string[] = [];
  if (!r.delivery_note_attachment_id) out.push("a signed delivery note");
  if (!(r.manifest ?? []).length) out.push("a manifest");
  const unsigned = (r.manifest ?? []).filter(
    (i: any) => i.quantity_received === null || Number(i.quantity_received) < Number(i.quantity));
  if (unsigned.length) out.push(`${unsigned.length} line(s) not signed for`);
  return out;
});

const shortfall = (i: any) =>
  i.quantity_received !== null && Number(i.quantity_received) < Number(i.quantity);

onMounted(load);
</script>

<template>
  <div class="dd-wrap">
    <button class="link-btn" @click="router.push({ name: 'deliveries' })">← Deliveries</button>

    <div v-if="error" class="alert-error">{{ error }}</div>
    <div v-if="notice" class="alert-success">{{ notice }}</div>
    <div v-if="loading" class="dd-loading">Loading…</div>

    <template v-else-if="request">
      <header class="dd-header">
        <h1 class="dd-title">{{ request.reference ?? "Delivery" }}</h1>
        <span class="pill pill-neutral">{{ String(request.status).replace("_", " ") }}</span>
      </header>

      <dl class="dd-summary">
        <div><dt>Destination</dt><dd>{{ request.destination?.name ?? "—" }}</dd></div>
        <div><dt>Date</dt><dd>{{ request.departure_date ?? "—" }}</dd></div>
        <div><dt>Raised by</dt><dd>{{ request.employee?.name ?? "—" }}</dd></div>
        <div><dt>Estimated cost</dt><dd>{{ request.currency }} {{ Number(request.total_claimed).toLocaleString() }}</dd></div>
      </dl>

      <div v-if="gaps.length" class="dd-gaps">
        <strong>Before this can be closed</strong>
        <ul><li v-for="g in gaps" :key="g">{{ g }}</li></ul>
      </div>

      <!-- ── Manifest ─────────────────────────────────────────────────── -->
      <section class="dd-section">
        <h2 class="dd-h2">Manifest</h2>
        <p class="dd-note">
          What is in the vehicle, line by line — so arrival can be checked off against it.
          A delivery where four of five items turned up is the case worth recording.
        </p>

        <div class="dd-form">
          <div class="dd-row">
            <label class="dd-field dd-grow"><span>Item</span>
              <input v-model="newItem.description" class="control" placeholder="Rack server" /></label>
            <label class="dd-field dd-sm"><span>Qty</span>
              <input v-model="newItem.quantity" class="control" type="number" min="0.01" /></label>
            <label class="dd-field dd-sm"><span>Unit</span>
              <input v-model="newItem.unit" class="control" placeholder="pcs" /></label>
            <label class="dd-field dd-sm"><span>Weight (kg)</span>
              <input v-model="newItem.weight_kg" class="control" type="number" min="0" /></label>
            <label class="dd-field"><span>Reference</span>
              <input v-model="newItem.reference" class="control" placeholder="Serial / LPO" /></label>
            <button class="btn-ghost" :disabled="saving" @click="addItem">Add</button>
          </div>
        </div>

        <table class="dd-table">
          <thead>
            <tr><th>Item</th><th>Sent</th><th>Reference</th><th>Received</th><th>Condition</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-if="!(request.manifest ?? []).length">
              <td colspan="6" class="dd-none">Nothing on the manifest yet.</td>
            </tr>
            <tr v-for="i in request.manifest" :key="i.id" :class="{ 'dd-short': shortfall(i) }">
              <td>{{ i.description }}</td>
              <td class="dd-num">{{ Number(i.quantity) }} {{ i.unit }}</td>
              <td>{{ i.reference ?? "—" }}</td>
              <td>
                <input v-model="i.quantity_received" class="control control-xs" type="number" min="0"
                       placeholder="—" @change="receive(i)" />
              </td>
              <td>
                <select v-model="i.condition_on_arrival" class="control control-sm" @change="receive(i)">
                  <option :value="null">—</option>
                  <option value="good">Good</option>
                  <option value="damaged">Damaged</option>
                  <option value="partial">Partial</option>
                </select>
              </td>
              <td><button class="link-btn link-danger" @click="removeItem(i)">Remove</button></td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- ── Couriers ─────────────────────────────────────────────────── -->
      <section class="dd-section">
        <h2 class="dd-h2">Who is carrying it</h2>
        <p class="dd-note">
          More than one, because a heavy consignment travels with a driver and a hand — and
          both need naming for a note either might sign.
        </p>

        <div class="dd-form">
          <div class="dd-row">
            <div class="dd-field dd-grow">
              <span class="dd-label">Add courier</span>
              <EmployeePicker v-model="newCourier" placeholder="Search by name or number…" />
            </div>
            <button class="btn-ghost" :disabled="saving || !newCourier" @click="addCourier">Add</button>
          </div>
        </div>

        <ul class="dd-couriers">
          <li v-if="!(request.couriers ?? []).length" class="dd-none">Nobody assigned yet.</li>
          <li v-for="c in request.couriers" :key="c.id">
            {{ c.employee?.name ?? "—" }}
            <span v-if="c.is_primary" class="pill pill-ok">accountable</span>
            <button class="link-btn link-danger" @click="removeCourier(c)">Remove</button>
          </li>
        </ul>
      </section>

      <!-- ── Delivery note ────────────────────────────────────────────── -->
      <section class="dd-section">
        <h2 class="dd-h2">Delivery note</h2>
        <p class="dd-note">
          A delivery closes against a signed note, not against any attachment — otherwise a
          quotation would satisfy the check.
        </p>

        <p v-if="noteAttachment" class="dd-have">
          <a :href="url(`/attachments/${noteAttachment.id}/download`)" target="_blank" class="link-btn">
            {{ noteAttachment.original_name }}
          </a>
          <span class="pill pill-ok">recorded</span>
        </p>

        <div v-else class="dd-form">
          <div class="dd-row">
            <label class="dd-field dd-grow"><span>Signed note (PDF or image)</span>
              <input class="control" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" @change="pickNote" /></label>
            <button class="btn-primary" :disabled="saving || !noteFile" @click="uploadNote">Upload</button>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.dd-wrap { padding: var(--space-6); max-width: 72rem; @apply flex flex-col; gap: var(--space-4); }
.dd-loading { padding: var(--space-6); color: var(--color-text-muted); }

.dd-header { @apply flex items-center; gap: var(--space-3); }
.dd-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }

.dd-summary { @apply grid; gap: var(--space-3); grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); margin: 0; }
.dd-summary dt { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.dd-summary dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }

.dd-gaps {
  padding: var(--space-3) var(--space-4);
  border-radius: var(--radius-md);
  background: var(--color-warning-subtle); color: var(--color-warning-text);
  font-size: var(--text-sm-size);
}
.dd-gaps ul { margin: var(--space-1) 0 0; padding-left: var(--space-5); }

.dd-section { @apply flex flex-col; gap: var(--space-3); }
.dd-h2 { font-size: var(--text-base-size); font-weight: 600; color: var(--color-text); margin: 0; }
.dd-note { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; max-width: 66ch; }

.dd-form {
  padding: var(--space-4);
  background: var(--color-surface-sunken);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
.dd-row { @apply flex flex-wrap items-end; gap: var(--space-3); }
.dd-field { @apply flex flex-col; gap: var(--space-1); min-width: 8rem; }
.dd-field span, .dd-label { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.dd-grow { flex: 1; min-width: 14rem; }
.dd-sm { max-width: 7rem; }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }
.control-sm { width: 8rem; }
.control-xs { width: 5.5rem; }

.dd-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.dd-table th {
  text-align: left; padding: var(--space-2) var(--space-3);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  text-transform: uppercase; letter-spacing: 0.04em;
  border-bottom: 1px solid var(--color-border);
}
.dd-table td { padding: var(--space-3); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.dd-num { font-variant-numeric: tabular-nums; }
.dd-none { text-align: center; color: var(--color-text-light); padding: var(--space-5); }
/* A line signed for short is the thing worth seeing without reading numbers. */
.dd-short td { background: var(--color-warning-subtle); }

.dd-couriers { list-style: none; margin: 0; padding: 0; @apply flex flex-col; gap: var(--space-2); }
.dd-couriers li { @apply flex items-center; gap: var(--space-3); font-size: var(--text-sm-size); color: var(--color-text); }

.dd-have { @apply flex items-center; gap: var(--space-3); margin: 0; font-size: var(--text-sm-size); }

.pill { display: inline-block; width: fit-content; padding: 2px var(--space-2); border-radius: 999px; font-size: var(--text-xs-size); text-transform: capitalize; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0 self-start; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }
.link-danger { color: var(--color-danger-text); }

.btn-primary, .btn-ghost {
  @apply cursor-pointer; padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-ghost   { background: transparent; color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-primary:disabled, .btn-ghost:disabled { @apply cursor-not-allowed opacity-50; }

.alert-error, .alert-success { padding: var(--space-3) var(--space-4); border-radius: var(--radius-md); font-size: var(--text-sm-size); }
.alert-error   { background: var(--color-danger-subtle);  color: var(--color-danger-text); }
.alert-success { background: var(--color-success-subtle); color: var(--color-success-text); }
</style>
