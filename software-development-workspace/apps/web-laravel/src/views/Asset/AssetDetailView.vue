<script setup lang="ts">
/**
 * One asset.
 *
 * Custody is handled by its own actions rather than by editing a field,
 * mirroring the API: assigning equipment is a separate permission from
 * correcting a serial number, and the two should not look the same here.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug, getToken } from "../../stores/auth";
import type { Asset } from "../../lib/types";
import EmployeePicker from "../../components/EmployeePicker.vue";

const props = defineProps<{ id: string | number }>();
const router = useRouter();

const asset   = ref<Asset | null>(null);
const loading = ref(false);
const error   = ref("");
const busy    = ref(false);

/**
 * Three views of one asset, as the designs have them.
 *
 * The timeline is the reason this is tabbed rather than one long page: an
 * asset's history is the answer to a different question from its
 * specification, and stacking them means the person asking "who had this in
 * March" scrolls past a MAC address to find out.
 */
type Tab = "details" | "lifecycle" | "issues";
const tab = ref<Tab>("details");

/** Where a stage sits on the timeline, and what to call it. */
const STAGE_LABEL: Record<string, string> = {
  procured: "Procured", in_stock: "In Stock", assigned: "Assigned",
  reassigned: "Reassigned", returned: "Returned", under_repair: "Under Repair",
  repaired: "Repaired", lost: "Lost", retired: "Retired", disposed: "Disposed",
};

/**
 * Seats on a licence batch.
 *
 * The endpoints have existed since batches arrived and nothing showed them, so
 * "who is affected" was answerable and unanswered. This is the screen that
 * makes a licence something you can hand to somebody and take back.
 */
/**
 * Editing an asset.
 *
 * There was no way to correct anything after registering it — a serial number
 * typed wrongly stayed wrong, and the only recourse was deleting the record and
 * starting again, which loses its history.
 *
 * Custody is deliberately not here. Assigning equipment is a different
 * permission from correcting a model number, and the API keeps them apart; a
 * form that quietly reassigned a laptop while fixing a typo would be worse than
 * no form.
 */
const showEdit  = ref(false);
const editSaving = ref(false);
const editError = ref("");
const editForm  = ref<Record<string, any>>({});
const editFields = ref<any[]>([]);

function openEdit() {
  const a: any = asset.value;
  editError.value = "";
  editFields.value = customFieldDefs.value;
  editForm.value = {
    name: a.name ?? "", asset_tag: a.asset_tag ?? "", category: a.category ?? "",
    brand: a.brand ?? "", model: a.model ?? "", serial_number: a.serial_number ?? "",
    vendor: a.vendor ?? "", location: a.location ?? "",
    purchase_value: a.purchase_value ?? "", condition: a.condition ?? "",
    notes: a.notes ?? "",
    description: a.description ?? "",
    custom_fields: { ...(a.custom_fields ?? {}) },
  };
  showEdit.value = true;
}

async function saveEdit() {
  editSaving.value = true; editError.value = "";
  try {
    const body: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(editForm.value)) {
      if (k === "custom_fields") continue;
      // Empty means "clear it", not "leave it" — but an untouched empty field
      // that was already empty should not be sent as a change either.
      body[k] = v === "" ? null : v;
    }
    if (Object.keys(editForm.value.custom_fields ?? {}).length) {
      body.custom_fields = editForm.value.custom_fields;
    }

    const res = await api<{ data: any }>("PUT", `/api/v1/assets/${props.id}`, { org: org(), body });
    asset.value = res.data;
    showEdit.value = false;
    await load();
  } catch (e) {
    editError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not save those changes.";
  } finally { editSaving.value = false; }
}

/** The questions this asset's category asks, as returned beside the asset. */
const customFieldDefs = ref<any[]>([]);

const capturedFields = computed(() => {
  const values: Record<string, any> = (asset.value as any)?.custom_fields ?? {};
  return customFieldDefs.value
    .map(d => ({ ...d, value: values[d.key] }))
    .filter(d => d.value !== undefined && d.value !== null && d.value !== "");
});

/**
 * Signing for equipment.
 *
 * Shown only to the person it is waiting on. Custody starts here — everything
 * before this is an allocation, and the gap between the two is where a laptop
 * goes missing from somebody's record.
 */
const showAccept  = ref(false);
const acceptForm  = ref({ note: "", agreed: false });
const acceptError = ref("");

const awaitingMe = computed(() => {
  const a: any = asset.value;
  return a?.status === "pending_acceptance";
});

async function acceptAsset() {
  acceptError.value = "";
  busy.value = true;
  try {
    const res = await api<{ data: any }>("POST", `/api/v1/assets/${props.id}/accept`, {
      org: org(),
      body: { note: acceptForm.value.note || null, agreed: acceptForm.value.agreed },
    });
    asset.value = res.data;
    showAccept.value = false;
    await load();
  } catch (e) {
    acceptError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not record that.";
  } finally { busy.value = false; }
}

/**
 * Photographs of the asset.
 *
 * The condition something was in when it was handed over is what gets argued
 * about when it comes back. Images are fetched as blobs because they live on
 * the encrypted disk behind an authorised route — a photograph of a rack shows
 * the serial number of everything in it, so there is no public URL to point an
 * <img> at.
 */
const photoSrc = ref<Record<number, string>>({});
const photoBusy = ref(false);
const photoError = ref("");
const photoInput = ref<HTMLInputElement | null>(null);
const atHandover = ref(false);

async function loadPhotoImages() {
  const list: any[] = (asset.value as any)?.photos ?? [];
  const base = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");
  const headers: Record<string, string> = {};
  const token = getToken();
  if (token) headers["Authorization"] = `Bearer ${token}`;
  const slug = org();
  if (slug) headers["X-Organization"] = slug;

  for (const p of list) {
    if (photoSrc.value[p.id]) continue;
    try {
      const res = await fetch(`${base}/api/v1/assets/${props.id}/photos/${p.id}`, { headers });
      if (res.ok) photoSrc.value[p.id] = URL.createObjectURL(await res.blob());
    } catch { /* the tile shows its filename instead */ }
  }
}

async function uploadPhoto(e: Event) {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;

  photoError.value = "";
  if (file.size > 10 * 1024 * 1024) {
    photoError.value = "That image is over 10 MB.";
    input.value = "";
    return;
  }

  photoBusy.value = true;
  try {
    const form = new FormData();
    form.append("photo", file);
    // Marked at the point custody changes, because a picture from today does
    // not settle a dispute about last year.
    if (atHandover.value) form.append("at_handover", "1");
    await api("POST", `/api/v1/assets/${props.id}/photos`, { org: org(), formData: form });
    await load();
    await loadPhotoImages();
  } catch (err) {
    photoError.value = err instanceof ApiError ? (err.firstError ?? err.message) : "Could not upload that.";
  } finally {
    photoBusy.value = false;
    input.value = "";
  }
}

async function removePhoto(id: number) {
  if (!confirm("Remove this photograph?")) return;
  await api("DELETE", `/api/v1/assets/${props.id}/photos/${id}`, { org: org() });
  delete photoSrc.value[id];
  await load();
}

const showSeat   = ref(false);
const seatForm   = ref<{ employee_id: number | null; note: string }>({ employee_id: null, note: "" });
const seatError  = ref("");

const isLicence = computed(() =>
  ["software", "subscription"].includes((asset.value as any)?.asset_class ?? ""));

async function allocateSeat() {
  if (!seatForm.value.employee_id) return;
  busy.value = true; seatError.value = "";
  try {
    const res = await api<{ data: any }>("POST", `/api/v1/assets/${props.id}/seats`, {
      org: org(),
      body: { employee_id: seatForm.value.employee_id, note: seatForm.value.note || null },
    });
    asset.value = res.data;
    showSeat.value = false;
  } catch (e) {
    // The over-allocation refusal lands here, and it is the one message worth
    // showing verbatim — it says exactly what to do about it.
    seatError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not allocate that seat.";
  } finally { busy.value = false; }
}

async function releaseSeat(allocationId: number, who: string) {
  if (!confirm(`Take the seat back from ${who}? The record of them having held it is kept.`)) return;
  busy.value = true;
  try {
    const res = await api<{ data: any }>("DELETE", `/api/v1/assets/${props.id}/seats/${allocationId}`, { org: org() });
    asset.value = res.data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Could not release that seat.";
  } finally { busy.value = false; }
}

function openSeat() {
  seatForm.value = { employee_id: null, note: "" };
  seatError.value = "";
  showSeat.value = true;
}

const showAssign  = ref(false);
const assignError = ref("");
const assignForm  = ref<{ employee_id: number | null; assigned_date: string }>({ employee_id: null, assigned_date: "" });

function org() { return currentOrgSlug.value; }

const issues = ref<any[]>([]);
const issuesLoaded = ref(false);

/** Fetched on first open. Most visits to an asset never ask about faults. */
async function loadIssues() {
  if (issuesLoaded.value) return;
  try {
    const res = await api<{ data: any }>("GET", "/api/v1/assets/issues", {
      org: org(), params: { asset_id: props.id },
    });
    issues.value = res.data?.data ?? [];
    issuesLoaded.value = true;
  } catch { /* the tab shows its empty state */ }
}

async function load() {
  if (!org()) return;
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: Asset; custom_field_defs?: any[] }>(
      "GET", `/api/v1/assets/${props.id}`, { org: org() },
    );
    asset.value = res.data;
    customFieldDefs.value = res.custom_field_defs ?? [];
    void loadPhotoImages();
  } catch (e) {
    error.value = e instanceof ApiError && e.status === 404
      ? "That asset does not exist in this organisation."
      : (e instanceof Error ? e.message : "Failed to load the asset.");
  } finally {
    loading.value = false;
  }
}

async function assign() {
  busy.value = true; assignError.value = "";
  try {
    const res = await api<{ data: Asset }>("POST", `/api/v1/assets/${props.id}/assign`, {
      org: org(),
      body: {
        employee_id: assignForm.value.employee_id,
        assigned_date: assignForm.value.assigned_date || null,
      },
    });
    asset.value = res.data;
    showAssign.value = false;
  } catch (e) {
    // 422 here is usually the tenancy check: a real employee id, wrong org.
    assignError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not assign the asset.";
  } finally {
    busy.value = false;
  }
}

async function release(status: string) {
  busy.value = true; error.value = "";
  try {
    const res = await api<{ data: Asset }>("POST", `/api/v1/assets/${props.id}/release`, {
      org: org(), body: { status },
    });
    asset.value = res.data;
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not release the asset.";
  } finally {
    busy.value = false;
  }
}

function openAssign() {
  assignForm.value = { employee_id: null, assigned_date: new Date().toISOString().slice(0, 10) };
  assignError.value = "";
  showAssign.value = true;
}

onMounted(load);
</script>

<template>
  <div class="detail">
    <button class="back" @click="router.push({ name: 'assets' })">← Assets</button>

    <p v-if="error" class="alert" role="alert">{{ error }}</p>
    <p v-if="loading" class="muted">Loading…</p>

    <template v-if="asset">
      <header class="head">
        <div>
          <h1>{{ asset.name }}</h1>
          <p class="lead">
            <code>{{ asset.asset_tag }}</code>
            <span class="dot">·</span>{{ asset.category }}
            <span v-if="asset.brand"><span class="dot">·</span>{{ asset.brand }} {{ asset.model }}</span>
          </p>
        </div>
        <div class="head-actions">
          <button class="btn-secondary" @click="openEdit">Edit</button>
          <span :class="['pill', `pill-${asset.status}`]">{{ asset.status }}</span>
        </div>
      </header>

      <nav class="tabs" role="tablist">
        <button role="tab" :class="['tab', { on: tab === 'details' }]"
                :aria-selected="tab === 'details'" @click="tab = 'details'">Asset details</button>
        <button role="tab" :class="['tab', { on: tab === 'lifecycle' }]"
                :aria-selected="tab === 'lifecycle'" @click="tab = 'lifecycle'">Lifecycle Tracking</button>
        <button role="tab" :class="['tab', { on: tab === 'issues' }]"
                :aria-selected="tab === 'issues'" @click="tab = 'issues'; loadIssues()">Issues History</button>
      </nav>

      <!-- ── Lifecycle ──────────────────────────────────────────────────── -->
      <section v-if="tab === 'lifecycle'" class="panel">
        <div class="panel-head"><h2>Lifecycle Timeline</h2></div>
        <p class="muted tl-lead">Track an asset's journey from procurement to its current state.</p>

        <p v-if="!(asset as any).events?.length" class="muted">
          Nothing has been recorded against this asset yet.
        </p>

        <!--
          Read forwards, one marker per thing that happened. Reassignment gets
          its own marker rather than a second "assigned" — a handover between
          two people is where the signature gets skipped, and two identical
          markers would hide exactly that.
        -->
        <ol v-else class="timeline">
          <li v-for="e in (asset as any).events" :key="e.id" class="tl-item">
            <span class="tl-dot" />
            <span class="tl-body">
              <span class="tl-stage">{{ STAGE_LABEL[e.event] ?? e.event }}</span>
              <span class="tl-date">{{ e.occurred_on }}</span>
              <span v-if="e.employee || e.vendor || e.location" class="tl-who">
                {{ e.employee ?? e.vendor ?? e.location }}
              </span>
              <span v-if="e.note" class="tl-note">{{ e.note }}</span>
            </span>
          </li>
        </ol>
      </section>

      <!-- ── Issues ─────────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'issues'" class="panel">
        <div class="panel-head"><h2>Issues History</h2></div>
        <p v-if="!issues.length" class="muted">No faults have been reported against this asset.</p>
        <table v-else class="tl-table">
          <thead>
            <tr><th>Ticket</th><th>Reported</th><th>Type</th><th>Status</th></tr>
          </thead>
          <tbody>
            <tr v-for="i in issues" :key="i.id">
              <td><code>{{ i.reference }}</code></td>
              <td>{{ i.reported_on }}</td>
              <td>{{ i.issue_type ?? '—' }}</td>
              <td><span class="pill">{{ i.status }}</span></td>
            </tr>
          </tbody>
        </table>
      </section>

      <template v-else>
      <!--
        Waiting to be signed for.

        Placed above everything because it is the only thing on this page that
        needs somebody to do something today.
      -->
      <div v-if="awaitingMe" class="accept-strip">
        <div>
          <strong>This is waiting for you to accept it.</strong>
          <p>
            Allocated to {{ (asset as any).holder?.full_name ?? 'you' }} on
            {{ (asset as any).assigned_date }}. It stays allocated rather than
            held until you confirm you have it.
          </p>
        </div>
        <button class="btn-primary" @click="showAccept = true">Sign acceptance</button>
      </div>

      <!-- Attention strip: the two things a register is asked about. -->
      <p v-if="asset.warranty_has_expired || asset.maintenance_is_due" class="note">
        <span v-if="asset.warranty_has_expired">Warranty expired {{ asset.warranty_expiry_date }}.</span>
        <span v-if="asset.maintenance_is_due"> Maintenance due {{ asset.next_maintenance }}.</span>
      </p>

      <!-- ── Seats, for a licence batch ─────────────────────────────────── -->
      <section v-if="isLicence" class="panel">
        <div class="panel-head">
          <h2>Seats</h2>
          <button class="btn-primary" :disabled="busy" @click="openSeat">Allocate a seat</button>
        </div>

        <div class="seat-counts">
          <div class="seat-stat">
            <span class="seat-n">{{ (asset as any).seats_total ?? '—' }}</span>
            <span class="seat-l">in the batch</span>
          </div>
          <div class="seat-stat">
            <span class="seat-n">{{ (asset as any).seats_used ?? 0 }}</span>
            <span class="seat-l">allocated</span>
          </div>
          <!-- The number that hands money back at a renewal. -->
          <div class="seat-stat">
            <span class="seat-n">{{ (asset as any).seats_spare ?? '—' }}</span>
            <span class="seat-l">spare</span>
          </div>
          <div v-if="(asset as any).starts_on || (asset as any).expires_on" class="seat-stat seat-term">
            <span class="seat-l">Term</span>
            <span class="seat-term-dates">
              {{ (asset as any).starts_on ?? '—' }} → {{ (asset as any).expires_on ?? '—' }}
            </span>
          </div>
        </div>

        <p v-if="(asset as any).description" class="pad seat-desc">{{ (asset as any).description }}</p>

        <p v-if="!(asset as any).seat_holders?.length" class="pad muted">
          Nobody holds a seat on this batch yet.
        </p>

        <!-- Who is affected — the question a typed count could never answer. -->
        <table v-else class="tl-table">
          <thead>
            <tr><th>Who</th><th>Since</th><th>Note</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="h in (asset as any).seat_holders" :key="h.id">
              <td>{{ h.employee }}</td>
              <td>{{ h.allocated_on }}</td>
              <td class="muted">{{ h.note ?? '—' }}</td>
              <td><button class="link-danger" :disabled="busy" @click="releaseSeat(h.id, h.employee)">Release</button></td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Custody -->
      <section v-if="!isLicence" class="panel">
        <div class="panel-head">
          <h2>Custody</h2>
          <div class="actions">
            <button v-if="!asset.is_assigned" class="btn-primary" :disabled="busy" @click="openAssign">Assign</button>
            <template v-else>
              <button class="btn-secondary" :disabled="busy" @click="release('available')">Return to stock</button>
              <button class="btn-secondary" :disabled="busy" @click="release('maintenance')">Send to maintenance</button>
            </template>
          </div>
        </div>
        <dl class="facts pad">
          <dt>Held by</dt>
          <dd>
            <template v-if="asset.holder">
              {{ asset.holder.full_name }}
              <span class="muted">({{ asset.holder.employee_number }})</span>
            </template>
            <span v-else class="muted">Unassigned</span>
          </dd>
          <dt>Since</dt><dd>{{ asset.assigned_date || "—" }}</dd>
          <dt>Department</dt><dd>{{ asset.department?.name || "—" }}</dd>
          <dt>Location</dt><dd>{{ asset.location || "—" }}</dd>
          <dt>Handover</dt>
          <dd>
            <span v-if="!asset.handover_required" class="muted">Not required</span>
            <span v-else-if="asset.handover_signed_at">Signed {{ asset.handover_signed_at.slice(0, 10) }}</span>
            <span v-else class="pill pill-warn">Required, unsigned</span>
          </dd>
        </dl>
      </section>

      <div class="cols">
        <section class="panel">
          <div class="panel-head"><h2>Procurement</h2></div>
          <dl class="facts pad">
            <dt>Purchased</dt><dd>{{ asset.purchase_date || "—" }}</dd>
            <dt>Value</dt><dd>{{ asset.purchase_value ?? "—" }}</dd>
            <dt>Vendor</dt><dd>{{ asset.vendor || "—" }}</dd>
            <dt>Invoice</dt><dd>{{ asset.invoice_number || "—" }}</dd>
            <dt>Warranty</dt><dd>{{ asset.warranty_expiry_date || "—" }}</dd>
            <dt>Next service</dt><dd>{{ asset.next_maintenance || "—" }}</dd>
          </dl>
        </section>

        <section class="panel">
          <div class="panel-head"><h2>Specification</h2></div>
          <dl class="facts pad">
            <dt>Serial</dt><dd><code v-if="asset.serial_number">{{ asset.serial_number }}</code><span v-else class="muted">—</span></dd>
            <dt>Processor</dt><dd>{{ asset.specification?.processor || "—" }}</dd>
            <dt>Memory</dt><dd>{{ asset.specification?.ram || "—" }}</dd>
            <dt>Storage</dt><dd>{{ asset.specification?.storage || "—" }}</dd>
            <dt>OS</dt><dd>{{ asset.specification?.operating_system || "—" }}</dd>
            <dt>Condition</dt><dd>{{ asset.condition || "—" }}</dd>
          </dl>
        </section>
      </div>

      <!-- ── Photographs ────────────────────────────────────────────────── -->
      <section class="panel">
        <div class="panel-head">
          <h2>Photographs</h2>
          <div class="photo-actions">
            <label class="check-inline">
              <input v-model="atHandover" type="checkbox" />
              <span>Taken at handover</span>
            </label>
            <input ref="photoInput" type="file" class="hidden-file"
                   accept="image/jpeg,image/png,image/webp,image/heic" @change="uploadPhoto" />
            <button class="btn-secondary" :disabled="photoBusy" @click="photoInput?.click()">
              {{ photoBusy ? "Uploading…" : "Add photo" }}
            </button>
          </div>
        </div>

        <p v-if="photoError" class="alert" role="alert">{{ photoError }}</p>

        <p v-if="!(asset as any).photos?.length" class="pad muted">
          No photographs. One taken at handover is what settles an argument
          about condition when the asset comes back.
        </p>

        <div v-else class="photo-grid">
          <figure v-for="p in (asset as any).photos" :key="p.id" class="photo">
            <img v-if="photoSrc[p.id]" :src="photoSrc[p.id]" :alt="p.caption ?? p.original_name" />
            <span v-else class="photo-loading">{{ p.original_name }}</span>
            <figcaption>
              <span v-if="p.is_primary" class="tag">main</span>
              <!-- The distinction that matters later: at handover, or since. -->
              <span v-if="p.at_handover" class="tag tag-hand">at handover</span>
              <span class="photo-date">{{ p.uploaded_at }}</span>
              <button class="link-danger" @click="removePhoto(p.id)">Remove</button>
            </figcaption>
          </figure>
        </div>
      </section>

      <!-- What this category asks for, and what was answered. -->
      <section v-if="capturedFields.length" class="panel">
        <div class="panel-head"><h2>{{ asset.category }} details</h2></div>
        <div class="kv">
          <div v-for="f in capturedFields" :key="f.key" class="kv-row">
            <span class="kv-k">{{ f.label }}</span>
            <span class="kv-v">
              <template v-if="f.type === 'boolean'">{{ f.value ? 'Yes' : 'No' }}</template>
              <template v-else>{{ f.value }}</template>
            </span>
          </div>
        </div>
      </section>

      <section v-if="asset.notes" class="panel">
        <div class="panel-head"><h2>Notes</h2></div>
        <p class="pad">{{ asset.notes }}</p>
      </section>
      </template>
    </template>

    <!-- Acceptance -->
    <div v-if="showAccept" class="overlay" @click.self="showAccept = false">
      <div class="dialog" role="dialog" aria-modal="true" aria-label="Acceptance form">
        <h2>Acceptance form</h2>
        <p class="muted seat-lead">
          By signing, you acknowledge receipt of <strong>{{ asset?.name }}</strong>
          in good working condition and agree to the company asset policy.
        </p>

        <!--
          Anything already wrong belongs on the record now rather than being
          discovered at an exit interview and argued about.
        -->
        <label class="field"><span>Anything to note about its condition?</span>
          <textarea v-model="acceptForm.note" class="control" rows="3"
                    placeholder="Optional — e.g. screen already has a scratch on the lower left."></textarea>
        </label>

        <label class="check-inline accept-check">
          <input v-model="acceptForm.agreed" type="checkbox" />
          <span>I have read and agree to the Company Asset Policy.</span>
        </label>

        <p v-if="acceptError" class="alert" role="alert">{{ acceptError }}</p>

        <div class="dialog-actions">
          <button class="btn-secondary" @click="showAccept = false">Cancel</button>
          <!-- Refused rather than assumed: an acknowledgement nobody made is
               not an acknowledgement. -->
          <button class="btn-primary" :disabled="busy || !acceptForm.agreed" @click="acceptAsset">
            Confirm &amp; accept asset
          </button>
        </div>
      </div>
    </div>

    <!-- Edit -->
    <div v-if="showEdit" class="overlay" @click.self="showEdit = false">
      <div class="dialog dialog-wide" role="dialog" aria-modal="true" aria-label="Edit asset">
        <h2>Edit asset</h2>
        <p class="muted seat-lead">
          Custody is changed from the Custody panel, not here — assigning
          equipment is a different decision from correcting a typo.
        </p>

        <div class="edit-grid">
          <label class="field"><span>Name</span><input v-model="editForm.name" class="control" /></label>
          <label class="field"><span>Asset tag</span><input v-model="editForm.asset_tag" class="control" /></label>
          <label class="field"><span>Category</span><input v-model="editForm.category" class="control" /></label>
          <label class="field"><span>Vendor</span><input v-model="editForm.vendor" class="control" /></label>

          <template v-if="!isLicence">
            <label class="field"><span>Brand</span><input v-model="editForm.brand" class="control" /></label>
            <label class="field"><span>Model</span><input v-model="editForm.model" class="control" /></label>
            <label class="field"><span>Serial number</span><input v-model="editForm.serial_number" class="control" /></label>
            <label class="field"><span>Condition</span><input v-model="editForm.condition" class="control" /></label>
            <label class="field"><span>Location</span><input v-model="editForm.location" class="control" /></label>
            <label class="field"><span>Purchase value</span><input v-model="editForm.purchase_value" class="control" type="number" /></label>
          </template>

          <label v-else class="field field-span"><span>What this covers</span>
            <textarea v-model="editForm.description" class="control" rows="3"></textarea>
          </label>

          <!-- Whatever this category asks for. -->
          <template v-if="editFields.length">
            <p class="field-span cf-lead">{{ editForm.category }} details</p>
            <label v-for="cf in editFields" :key="cf.key"
                   :class="['field', cf.type === 'textarea' ? 'field-span' : '']">
              <span>{{ cf.label }}<template v-if="cf.is_required"> *</template></span>
              <textarea v-if="cf.type === 'textarea'" v-model="editForm.custom_fields[cf.key]" class="control" rows="3"></textarea>
              <select v-else-if="cf.type === 'select'" v-model="editForm.custom_fields[cf.key]" class="control">
                <option value="">—</option>
                <option v-for="o in (cf.options ?? [])" :key="o" :value="o">{{ o }}</option>
              </select>
              <label v-else-if="cf.type === 'boolean'" class="check-inline">
                <input v-model="editForm.custom_fields[cf.key]" type="checkbox" /><span>Yes</span>
              </label>
              <input v-else v-model="editForm.custom_fields[cf.key]" class="control"
                     :type="cf.type === 'number' ? 'number' : cf.type === 'date' ? 'date' : 'text'" />
              <span v-if="cf.help" class="hint-sm">{{ cf.help }}</span>
            </label>
          </template>

          <label class="field field-span"><span>Notes</span>
            <textarea v-model="editForm.notes" class="control" rows="3"></textarea>
          </label>
        </div>

        <p v-if="editError" class="alert" role="alert">{{ editError }}</p>

        <div class="dialog-actions">
          <button class="btn-secondary" @click="showEdit = false">Cancel</button>
          <button class="btn-primary" :disabled="editSaving || !editForm.name" @click="saveEdit">
            {{ editSaving ? "Saving…" : "Save changes" }}
          </button>
        </div>
      </div>
    </div>

    <!-- Allocate a seat -->
    <div v-if="showSeat" class="overlay" @click.self="showSeat = false">
      <div class="dialog" role="dialog" aria-modal="true" aria-label="Allocate a seat">
        <h2>Allocate a seat</h2>
        <p class="muted seat-lead">
          One seat comes off the batch and this person is recorded as holding it,
          so a renewal or an exit knows who is affected.
        </p>

        <label class="field"><span>Who</span>
          <EmployeePicker v-model="seatForm.employee_id" />
        </label>

        <label class="field"><span>Note</span>
          <input v-model="seatForm.note" class="control" placeholder="What the seat is for, if it is not obvious" />
        </label>

        <p v-if="seatError" class="alert" role="alert">{{ seatError }}</p>

        <div class="dialog-actions">
          <button class="btn-secondary" @click="showSeat = false">Cancel</button>
          <button class="btn-primary" :disabled="busy || !seatForm.employee_id" @click="allocateSeat">
            {{ busy ? "Allocating…" : "Allocate" }}
          </button>
        </div>
      </div>
    </div>

    <!-- Assign -->
    <div v-if="showAssign" class="overlay" @click.self="showAssign = false">
      <div class="dialog" role="dialog" aria-modal="true" aria-label="Assign asset">
        <h2>Assign {{ asset?.name }}</h2>
        <div class="field">
          <span>Employee</span>
          <EmployeePicker v-model="assignForm.employee_id" />
        </div>
        <label class="field"><span>Assigned from</span>
          <input v-model="assignForm.assigned_date" type="date" class="control" />
        </label>
        <p v-if="assignError" class="alert" role="alert">{{ assignError }}</p>
        <div class="dialog-actions">
          <button class="btn-secondary" @click="showAssign = false">Cancel</button>
          <button class="btn-primary" :disabled="busy || !assignForm.employee_id" @click="assign">
            {{ busy ? "Assigning…" : "Assign" }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.detail { padding: var(--space-6); }

.back {
  @apply cursor-pointer border-none bg-transparent p-0 font-medium;
  font-family: inherit;
  font-size: var(--text-sm-size);
  color: var(--color-brand-base);
  margin-bottom: var(--space-4);
}
.back:hover { @apply underline; }

.head { @apply flex items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-4); }

.head h1 {
  @apply font-semibold;
  font-size: var(--display-xs-size);
  line-height: var(--display-xs-line);
  color: var(--color-text);
  margin: 0;
}

.lead { font-size: var(--text-sm-size); color: var(--color-text-light); margin: var(--space-2) 0 0; }
.dot { margin: 0 var(--space-2); }

.cols { @apply grid; gap: var(--space-4); grid-template-columns: 1fr 1fr; }
@media (max-width: 900px) { .cols { grid-template-columns: 1fr; } }

.panel {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  overflow: hidden;
  margin-bottom: var(--space-4);
}

.panel-head {
  @apply flex items-center justify-between;
  gap: var(--space-3);
  padding: var(--space-4);
  border-bottom: 1px solid var(--color-border);
}

.panel-head h2 {
  @apply font-semibold;
  font-size: var(--text-md-size);
  color: var(--color-text);
  margin: 0;
}

.actions { @apply flex flex-wrap; gap: var(--space-2); }
.pad { padding: var(--space-4); }
.muted { color: var(--color-text-light); }

.facts { @apply grid; grid-template-columns: max-content 1fr; gap: var(--space-2) var(--space-6); margin: 0; }
.facts dt { font-size: var(--text-xs-size); color: var(--color-text-light); }
.facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }

code {
  font-family: var(--font-family-mono);
  font-size: var(--text-xs-size);
  background: var(--color-surface-sunken);
  padding: 1px 5px;
  border-radius: var(--radius-sm);
}

.pill {
  @apply inline-flex items-center rounded-full font-medium capitalize;
  padding: 2px var(--space-2);
  font-size: var(--text-xs-size);
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
}
.pill-available   { background: var(--color-success-light); color: #065F46; }
.pill-assigned    { background: var(--color-brand-light);   color: var(--color-brand-dark); }
.pill-maintenance { background: var(--color-warning-light); color: #7C2D12; }
.pill-retired     { background: var(--color-surface-sunken); color: var(--color-text-light); }
.pill-warn        { background: var(--color-warning-light); color: #7C2D12; }

.note {
  font-size: var(--text-xs-size);
  color: var(--color-text-muted);
  background: var(--color-surface-sunken);
  border-left: 3px solid var(--color-warning);
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  padding: var(--space-3);
  margin: 0 0 var(--space-4);
}

.alert {
  font-size: var(--text-xs-size);
  color: var(--color-error);
  background: var(--color-error-light);
  border-radius: var(--radius-sm);
  padding: var(--space-3);
  margin: 0 0 var(--space-3);
}

.btn-primary, .btn-secondary {
  @apply cursor-pointer font-medium;
  font-family: inherit;
  font-size: var(--text-sm-size);
  padding: 8px var(--space-4);
  border-radius: var(--radius-md);
  border: 1px solid transparent;
}
.btn-primary { background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-primary:hover:not(:disabled) { background: var(--color-brand-hover); }
.btn-primary:disabled, .btn-secondary:disabled { @apply cursor-not-allowed opacity-60; }
.btn-secondary { background: var(--color-surface); color: var(--color-text); border-color: var(--color-border-strong); }

.overlay {
  @apply fixed inset-0 z-50 flex items-center justify-center;
  background: var(--color-overlay);
  padding: var(--space-4);
}

.dialog {
  @apply w-full;
  max-width: 26rem;
  background: var(--color-surface);
  border-radius: var(--radius-2xl);
  padding: var(--space-5);
  box-shadow: var(--shadow-lg);
}

.dialog h2 {
  @apply font-semibold;
  font-size: var(--text-lg-size);
  color: var(--color-text);
  margin: 0 0 var(--space-4);
}

.field { @apply flex flex-col; gap: var(--space-1); margin-bottom: var(--space-3); }
.field > span { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text); }

.control {
  @apply w-full;
  font-family: inherit;
  font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
}
.control:focus {
  @apply outline-none;
  border-color: var(--color-brand-base);
  box-shadow: 0 0 0 3px var(--color-brand-subtle);
}

.dialog-actions { @apply flex justify-end; gap: var(--space-2); margin-top: var(--space-4); }

/* ── Tabs ──────────────────────────────────────────────────────────────── */
.tabs { display: flex; gap: 4px; border-bottom: 2px solid var(--color-border); margin: 18px 0 0; overflow-x: auto; }
.tab { background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px;
       padding: 10px 18px; font: inherit; font-size: .88rem; font-weight: 500;
       color: var(--color-text-muted); cursor: pointer; white-space: nowrap; }
.tab:hover { color: var(--color-text); }
.tab.on { color: var(--color-brand-base); border-bottom-color: var(--color-brand-base); font-weight: 600; }

/* ── Lifecycle timeline ────────────────────────────────────────────────── */
.tl-lead { padding: 0 16px; margin: -4px 0 14px; }
.timeline { list-style: none; margin: 0; padding: 0 16px 16px; display: flex; flex-direction: column; }
.tl-item { display: grid; grid-template-columns: 20px 1fr; gap: 12px; position: relative; padding-bottom: 20px; }
.tl-item:last-child { padding-bottom: 0; }
/* The connecting line, drawn behind the markers and stopped at the last one
   so the timeline does not trail off into nothing. */
.tl-item:not(:last-child)::before {
  content: ""; position: absolute; left: 9px; top: 16px; bottom: 0;
  width: 2px; background: var(--color-border);
}
.tl-dot { width: 12px; height: 12px; margin-top: 3px; border-radius: 50%;
          background: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-surface-raised); z-index: 1; }
.tl-body { display: flex; flex-direction: column; }
.tl-stage { font-size: .88rem; font-weight: 600; color: var(--color-text); }
.tl-date  { font-size: .78rem; color: var(--color-text-muted); }
.tl-who   { font-size: .78rem; color: var(--color-text-muted); }
.tl-note  { font-size: .78rem; color: var(--color-text-light); margin-top: 2px; }

.tl-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.tl-table th { text-align: left; padding: 10px 16px; font-size: .72rem; font-weight: 600;
               text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-muted);
               background: var(--color-surface-sunken); }
.tl-table td { padding: 12px 16px; border-bottom: 1px solid var(--color-border); }
.tl-table tr:last-child td { border-bottom: none; }

/* ── Seats ─────────────────────────────────────────────────────────────── */
.seat-counts { display: flex; flex-wrap: wrap; gap: 28px; padding: 4px 16px 14px; }
.seat-stat { display: flex; flex-direction: column; }
.seat-n { font-size: 1.5rem; font-weight: 600; color: var(--color-text); line-height: 1.1; }
.seat-l { font-size: .75rem; color: var(--color-text-muted); }
.seat-term { justify-content: center; }
.seat-term-dates { font-size: .85rem; color: var(--color-text); }
.seat-desc { font-size: .85rem; color: var(--color-text-muted); border-left: 3px solid var(--color-border);
             margin: 0 16px 12px; padding-left: 12px; }
.seat-lead { margin: -6px 0 14px; font-size: .82rem; }
.link-danger { background: none; border: none; padding: 0; font: inherit; font-size: .82rem;
               color: var(--color-danger-text); cursor: pointer; }
.link-danger:hover { text-decoration: underline; }

.head-actions { display: flex; align-items: center; gap: 12px; }

/* Edit: wide enough for two columns, and never taller than the window. */
.dialog-wide { max-width: 46rem; max-height: min(90vh, 52rem); display: flex; flex-direction: column; }
.edit-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; overflow-y: auto; padding-right: 6px; }
@media (max-width: 44rem) { .edit-grid { grid-template-columns: 1fr; } }
.field-span { grid-column: 1 / -1; }
.cf-lead { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em;
           color: var(--color-text-muted); border-top: 1px solid var(--color-border);
           padding-top: 12px; margin: 6px 0 10px; }
.hint-sm { font-size: .72rem; color: var(--color-text-light); }
.check-inline { display: flex; align-items: center; gap: 8px; font-size: .85rem; }

/* Captured fields on the detail page. */
.kv { padding: 4px 16px 14px; display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 10px 24px; }
.kv-row { display: flex; flex-direction: column; }
.kv-k { font-size: .72rem; color: var(--color-text-muted); }
.kv-v { font-size: .88rem; color: var(--color-text); }

.accept-strip { display: flex; align-items: center; justify-content: space-between; gap: 18px;
                border: 1px solid var(--color-brand-base); background: var(--color-brand-subtle);
                border-radius: 12px; padding: 14px 18px; margin-top: 16px; }
.accept-strip p { margin: 4px 0 0; font-size: .82rem; color: var(--color-text-muted); }
.accept-check { margin-bottom: 14px; }

/* ── Photographs ───────────────────────────────────────────────────────── */
.photo-actions { display: flex; align-items: center; gap: 14px; }
.hidden-file { display: none; }
.photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr));
              gap: 14px; padding: 4px 16px 16px; }
.photo { margin: 0; border: 1px solid var(--color-border); border-radius: 10px; overflow: hidden;
         background: var(--color-surface-sunken); }
.photo img { display: block; width: 100%; height: 9rem; object-fit: cover; }
.photo-loading { display: flex; align-items: center; justify-content: center; height: 9rem;
                 font-size: .72rem; color: var(--color-text-muted); padding: 8px; text-align: center; }
.photo figcaption { display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
                    padding: 8px 10px; font-size: .72rem; color: var(--color-text-muted); }
.tag { padding: 1px 8px; border-radius: 999px; background: var(--color-surface);
       border: 1px solid var(--color-border); }
.tag-hand { background: var(--color-brand-subtle); color: var(--color-brand-on-surface); border-color: transparent; }
.photo-date { margin-left: auto; }
</style>