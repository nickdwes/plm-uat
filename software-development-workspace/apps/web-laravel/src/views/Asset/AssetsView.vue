<script setup lang="ts">
/**
 * Asset register.
 *
 * A register is asked two questions more than any other: what is out of
 * warranty, and what is due a service. Both are filters here rather than
 * something to sort a table by, because they are the reason someone opens
 * this screen.
 */
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { Asset, AssetListMeta } from "../../lib/types";

const router = useRouter();

const assets  = ref<Asset[]>([]);
const meta    = ref<AssetListMeta | null>(null);
const loading = ref(false);
const error   = ref("");

const filters = ref({
  search: "",
  status: "",
  category: "",
  assigned: "" as "" | "true" | "false",
  warranty_expired: false,
  maintenance_due: false,
});

const page = ref(1);

const showCreate  = ref(false);
const saving      = ref(false);
const createError = ref("");
const form = ref({ name: "", asset_tag: "", category: "laptop", brand: "", model: "", serial_number: "" });

const STATUSES   = ["available", "assigned", "maintenance", "retired"];
const CATEGORIES = ["laptop", "desktop", "monitor", "phone", "tablet", "software", "peripheral", "other"];

function org() { return currentOrgSlug.value; }

async function load() {
  if (!org()) return;
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: Asset[]; meta: AssetListMeta }>("GET", "/api/v1/assets", {
      org: org(),
      params: {
        page: page.value,
        search: filters.value.search || undefined,
        status: filters.value.status || undefined,
        category: filters.value.category || undefined,
        assigned: filters.value.assigned || undefined,
        warranty_expired: filters.value.warranty_expired ? 1 : undefined,
        maintenance_due: filters.value.maintenance_due ? 1 : undefined,
      },
    });
    assets.value = res.data ?? [];
    meta.value   = (res as any).meta ?? null;
  } catch (e) {
    error.value = e instanceof ApiError && e.status === 403
      ? "You do not have permission to view the asset register."
      : (e instanceof Error ? e.message : "Failed to load assets.");
  } finally {
    loading.value = false;
  }
}

/** Filters reset paging — staying on page 4 of a narrower result set is a
 *  blank screen that reads as "no assets". */
function applyFilters() { page.value = 1; void load(); }

async function create() {
  saving.value = true; createError.value = "";
  try {
    const res = await api<{ data: Asset }>("POST", "/api/v1/assets", { org: org(), body: form.value });
    showCreate.value = false;
    void router.push({ name: "asset-detail", params: { id: res.data.id } });
  } catch (e) {
    createError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not create the asset.";
  } finally {
    saving.value = false;
  }
}

function openCreate() {
  form.value = { name: "", asset_tag: "", category: "laptop", brand: "", model: "", serial_number: "" };
  createError.value = "";
  showCreate.value = true;
}

const needsAttention = computed(
  () => assets.value.filter(a => a.warranty_has_expired || a.maintenance_is_due).length
);

watch(currentOrgSlug, () => { page.value = 1; void load(); });
onMounted(load);
</script>

<template>
  <div class="assets">
    <header class="head">
      <div>
        <h1>Assets</h1>
        <p class="lead">Equipment register — what the organisation owns, and who holds it.</p>
      </div>
      <button class="btn-primary" @click="openCreate">Add asset</button>
    </header>

    <p v-if="error" class="alert" role="alert">{{ error }}</p>

    <!-- Filters -->
    <div class="filters">
      <input
        v-model="filters.search" class="control search" type="search"
        placeholder="Search name, tag, serial, brand…"
        @keydown.enter="applyFilters"
      />
      <select v-model="filters.status" class="control" @change="applyFilters">
        <option value="">Any status</option>
        <option v-for="s in STATUSES" :key="s" :value="s">{{ s }}</option>
      </select>
      <select v-model="filters.category" class="control" @change="applyFilters">
        <option value="">Any category</option>
        <option v-for="c in CATEGORIES" :key="c" :value="c">{{ c }}</option>
      </select>
      <select v-model="filters.assigned" class="control" @change="applyFilters">
        <option value="">Assigned or not</option>
        <option value="true">Assigned</option>
        <option value="false">Unassigned</option>
      </select>

      <label class="check">
        <input v-model="filters.warranty_expired" type="checkbox" @change="applyFilters" />
        <span>Warranty expired</span>
      </label>
      <label class="check">
        <input v-model="filters.maintenance_due" type="checkbox" @change="applyFilters" />
        <span>Maintenance due</span>
      </label>
    </div>

    <p v-if="needsAttention" class="note">
      {{ needsAttention }} of the assets shown need attention — out of warranty or due a service.
    </p>

    <!-- Table -->
    <div class="panel">
      <p v-if="loading" class="pad muted">Loading…</p>
      <p v-else-if="!assets.length" class="pad muted">No assets match these filters.</p>

      <table v-else class="tbl">
        <thead>
          <tr>
            <th>Asset</th><th>Category</th><th>Status</th><th>Held by</th><th>Warranty</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="a in assets" :key="a.id" tabindex="0"
            @click="router.push({ name: 'asset-detail', params: { id: a.id } })"
            @keydown.enter="router.push({ name: 'asset-detail', params: { id: a.id } })"
          >
            <td>
              <div class="strong">{{ a.name }}</div>
              <div class="sub"><code>{{ a.asset_tag }}</code> {{ a.brand }} {{ a.model }}</div>
            </td>
            <td>{{ a.category }}</td>
            <td><span :class="['pill', `pill-${a.status}`]">{{ a.status }}</span></td>
            <td>
              <span v-if="a.holder">{{ a.holder.full_name }}</span>
              <span v-else class="muted">Unassigned</span>
            </td>
            <td>
              <span v-if="a.warranty_has_expired" class="pill pill-warn">Expired</span>
              <span v-else-if="a.warranty_expiry_date">{{ a.warranty_expiry_date }}</span>
              <span v-else class="muted">—</span>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="meta && meta.last_page > 1" class="pager">
        <button class="btn-secondary" :disabled="page <= 1" @click="page--; load()">Previous</button>
        <span class="muted">Page {{ meta.current_page }} of {{ meta.last_page }} · {{ meta.total }} assets</span>
        <button class="btn-secondary" :disabled="page >= meta.last_page" @click="page++; load()">Next</button>
      </div>
    </div>

    <!-- Create -->
    <div v-if="showCreate" class="overlay" @click.self="showCreate = false">
      <div class="dialog" role="dialog" aria-modal="true" aria-label="Add asset">
        <h2>Add asset</h2>

        <label class="field"><span>Name</span>
          <input v-model="form.name" class="control" placeholder="ThinkPad X1 Carbon" />
        </label>
        <label class="field"><span>Asset tag</span>
          <input v-model="form.asset_tag" class="control" placeholder="AST-0001" />
        </label>
        <label class="field"><span>Category</span>
          <select v-model="form.category" class="control">
            <option v-for="c in CATEGORIES" :key="c" :value="c">{{ c }}</option>
          </select>
        </label>
        <div class="row">
          <label class="field"><span>Brand</span><input v-model="form.brand" class="control" /></label>
          <label class="field"><span>Model</span><input v-model="form.model" class="control" /></label>
        </div>
        <label class="field"><span>Serial number</span>
          <input v-model="form.serial_number" class="control" />
        </label>

        <p v-if="createError" class="alert" role="alert">{{ createError }}</p>

        <div class="dialog-actions">
          <button class="btn-secondary" @click="showCreate = false">Cancel</button>
          <button class="btn-primary" :disabled="saving || !form.name || !form.asset_tag" @click="create">
            {{ saving ? "Adding…" : "Add asset" }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.assets { padding: var(--space-6); }

.head { @apply flex items-start justify-between; gap: var(--space-4); }

.head h1 {
  @apply font-semibold;
  font-size: var(--display-xs-size);
  line-height: var(--display-xs-line);
  color: var(--color-text);
  margin: 0;
}

.lead { font-size: var(--text-sm-size); color: var(--color-text-light); margin: var(--space-2) 0 0; }

.filters {
  @apply flex flex-wrap items-center;
  gap: var(--space-2);
  margin: var(--space-5) 0 var(--space-3);
}

.search { min-width: 18rem; flex: 1; }

.check {
  @apply flex cursor-pointer items-center;
  gap: var(--space-2);
  font-size: var(--text-sm-size);
  color: var(--color-text-muted);
}
.check input { @apply size-4 cursor-pointer; accent-color: var(--color-brand-base); }

.panel {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  overflow: hidden;
}

.pad { padding: var(--space-4); }
.muted { font-size: var(--text-xs-size); color: var(--color-text-light); }

.tbl { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }

.tbl th {
  @apply text-left font-semibold uppercase;
  font-size: var(--text-xs-size);
  letter-spacing: 0.06em;
  color: var(--color-text-light);
  padding: var(--space-3) var(--space-4);
  background: var(--color-surface-sunken);
  border-bottom: 1px solid var(--color-border);
}

.tbl td { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border); }
.tbl tbody tr { cursor: pointer; }
.tbl tbody tr:hover { background: var(--color-surface-sunken); }
.tbl tbody tr:focus-visible { @apply outline-2 outline-offset-[-2px]; outline-color: var(--color-brand-base); }

.strong { @apply font-medium; color: var(--color-text); }
.sub { font-size: var(--text-xs-size); color: var(--color-text-light); margin-top: 2px; }

code {
  font-family: var(--font-family-mono);
  font-size: var(--text-xs-size);
  background: var(--color-surface-sunken);
  padding: 1px 5px;
  border-radius: var(--radius-sm);
}

/*
 * Status pills carry a label as well as a colour, and their text is darkened
 * against the tint. The base feedback colours fail the small-text contrast
 * floor on their own light backgrounds — see docs/design-system.md.
 */
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

.pager {
  @apply flex items-center justify-between;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--color-border);
}

.note {
  font-size: var(--text-xs-size);
  color: var(--color-text-muted);
  background: var(--color-surface-sunken);
  border-left: 3px solid var(--color-warning);
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  padding: var(--space-3);
  margin: 0 0 var(--space-3);
}

.alert {
  font-size: var(--text-xs-size);
  color: var(--color-error);
  background: var(--color-error-light);
  border-radius: var(--radius-sm);
  padding: var(--space-3);
  margin: var(--space-3) 0;
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

.control {
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

.overlay {
  @apply fixed inset-0 z-50 flex items-center justify-center;
  background: var(--color-overlay);
  padding: var(--space-4);
}

.dialog {
  @apply w-full;
  max-width: 30rem;
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
.field .control { @apply w-full; }
.row { @apply grid; grid-template-columns: 1fr 1fr; gap: var(--space-3); }

.dialog-actions { @apply flex justify-end; gap: var(--space-2); margin-top: var(--space-4); }
</style>
