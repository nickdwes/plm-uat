<script setup lang="ts">
/**
 * One asset.
 *
 * Custody is handled by its own actions rather than by editing a field,
 * mirroring the API: assigning equipment is a separate permission from
 * correcting a serial number, and the two should not look the same here.
 */
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { Asset } from "../../lib/types";

const props = defineProps<{ id: string | number }>();
const router = useRouter();

const asset   = ref<Asset | null>(null);
const loading = ref(false);
const error   = ref("");
const busy    = ref(false);

const showAssign  = ref(false);
const assignError = ref("");
const assignForm  = ref({ employee_id: "" as string | number, assigned_date: "" });

function org() { return currentOrgSlug.value; }

async function load() {
  if (!org()) return;
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: Asset }>("GET", `/api/v1/assets/${props.id}`, { org: org() });
    asset.value = res.data;
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
        employee_id: Number(assignForm.value.employee_id),
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
  assignForm.value = { employee_id: "", assigned_date: new Date().toISOString().slice(0, 10) };
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
        <span :class="['pill', `pill-${asset.status}`]">{{ asset.status }}</span>
      </header>

      <!-- Attention strip: the two things a register is asked about. -->
      <p v-if="asset.warranty_has_expired || asset.maintenance_is_due" class="note">
        <span v-if="asset.warranty_has_expired">Warranty expired {{ asset.warranty_expiry_date }}.</span>
        <span v-if="asset.maintenance_is_due"> Maintenance due {{ asset.next_maintenance }}.</span>
      </p>

      <!-- Custody -->
      <section class="panel">
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

      <section v-if="asset.notes" class="panel">
        <div class="panel-head"><h2>Notes</h2></div>
        <p class="pad">{{ asset.notes }}</p>
      </section>
    </template>

    <!-- Assign -->
    <div v-if="showAssign" class="overlay" @click.self="showAssign = false">
      <div class="dialog" role="dialog" aria-modal="true" aria-label="Assign asset">
        <h2>Assign {{ asset?.name }}</h2>
        <label class="field"><span>Employee ID</span>
          <input v-model="assignForm.employee_id" type="number" class="control" placeholder="e.g. 12" />
        </label>
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
</style>
