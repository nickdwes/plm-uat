<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

interface PublicHoliday {
  id: number;
  name: string;
  local_name: string | null;
  date: string;
  year: number;
  country_code: string;
  source: "manual" | "imported";
  is_active: boolean;
}

const holidays   = ref<PublicHoliday[]>([]);
const year       = ref(new Date().getFullYear());
const loading    = ref(false);
const error      = ref("");
const importing  = ref(false);
const importMsg  = ref("");
const importErr  = ref("");
const saving     = ref(false);
const saveError  = ref("");

const showForm   = ref(false);
const editId     = ref<number | null>(null);
const form       = ref({ name: "", local_name: "", date: "", is_active: true });

function org() { return currentOrgSlug.value; }

// ── Data loading ─────────────────────────────────────────────────────────────

async function load() {
  if (!org()) return;
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: PublicHoliday[]; year: number }>(
      "GET", "/api/v1/public-holidays", { org: org(), params: { year: year.value } }
    );
    holidays.value = (res as any).data ?? [];
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load holidays.";
  } finally {
    loading.value = false;
  }
}

// ── Import from Nager.Date ───────────────────────────────────────────────────

async function importHolidays() {
  if (!org()) return;
  importMsg.value = ""; importErr.value = "";
  importing.value = true;
  try {
    const res = await api<{ message: string; created: number; skipped: number }>(
      "POST", "/api/v1/public-holidays/import",
      { org: org(), body: { year: year.value, country_code: "KE" } }
    );
    importMsg.value = (res as any).message ?? "Done";
    await load();
  } catch (e) {
    importErr.value = e instanceof Error ? e.message : "Import failed.";
  } finally {
    importing.value = false;
  }
}

// ── Add / Edit form ──────────────────────────────────────────────────────────

function openNew() {
  editId.value = null;
  form.value = { name: "", local_name: "", date: `${year.value}-01-01`, is_active: true };
  showForm.value = true;
  saveError.value = "";
}

function openEdit(h: PublicHoliday) {
  editId.value = h.id;
  form.value = { name: h.name, local_name: h.local_name ?? "", date: h.date, is_active: h.is_active };
  showForm.value = true;
  saveError.value = "";
}

async function save() {
  saving.value = true; saveError.value = "";
  try {
    const body = { ...form.value, local_name: form.value.local_name || null };
    if (editId.value) {
      await api("PUT", `/api/v1/public-holidays/${editId.value}`, { org: org(), body });
    } else {
      await api("POST", "/api/v1/public-holidays", { org: org(), body });
    }
    showForm.value = false;
    await load();
  } catch (e) {
    saveError.value = e instanceof Error ? e.message : "Save failed.";
  } finally {
    saving.value = false;
  }
}

async function remove(h: PublicHoliday) {
  if (!confirm(`Remove "${h.name}" from the list?`)) return;
  await api("DELETE", `/api/v1/public-holidays/${h.id}`, { org: org() });
  await load();
}

// ── Computed helpers ─────────────────────────────────────────────────────────

const activeCount   = computed(() => holidays.value.filter(h => h.is_active).length);
const passedCount   = computed(() => holidays.value.filter(h => h.is_active && new Date(h.date) < new Date()).length);
const upcomingCount = computed(() => holidays.value.filter(h => h.is_active && new Date(h.date) >= new Date()).length);

function formatDate(d: string) {
  return new Date(d).toLocaleDateString("en-KE", { weekday: "short", day: "numeric", month: "long" });
}

function isPast(d: string) {
  return new Date(d) < new Date(new Date().toDateString());
}

function isToday(d: string) {
  return new Date(d).toDateString() === new Date().toDateString();
}

onMounted(load);
watch(year, load);
watch(currentOrgSlug, load);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <h2>Public Holidays</h2>
        <p class="view-subtitle">Kenyan national holidays observed by the organisation.</p>
      </div>
      <button class="btn btn-primary btn-sm" @click="openNew">+ Add Holiday</button>
    </div>

    <p v-if="error" class="error">{{ error }}</p>

    <!-- ── Import bar ──────────────────────────────────────────────────────── -->
    <div class="import-bar">
      <div class="import-bar-left">
        <svg class="import-icon" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
        <div>
          <p class="import-title">Sync from Nager.Date</p>
          <p class="import-sub">
            Live data from
            <a href="https://date.nager.at/api/v3/PublicHolidays" target="_blank" rel="noopener" class="ext-link">
              date.nager.at
            </a>
            &mdash; updated annually for 100+ countries.
          </p>
        </div>
      </div>
      <div class="import-bar-right">
        <div class="year-selector">
          <label>Year</label>
          <input v-model.number="year" type="number" class="input year-input" min="2020" max="2100" />
        </div>
        <button class="btn btn-secondary btn-sm" :disabled="importing" @click="importHolidays">
          <svg v-if="importing" class="spin-icon" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-dasharray="31.4 31.4" />
          </svg>
          {{ importing ? "Syncing…" : `Sync Kenya ${year}` }}
        </button>
      </div>
    </div>
    <p v-if="importMsg" class="import-msg success">✓ {{ importMsg }}</p>
    <p v-if="importErr" class="import-msg error">{{ importErr }}</p>

    <!-- ── Metric pills ────────────────────────────────────────────────────── -->
    <div class="metrics-row">
      <div class="metric-pill">
        <span class="metric-num">{{ activeCount }}</span>
        <span class="metric-label">Total holidays</span>
      </div>
      <div class="metric-pill green">
        <span class="metric-num">{{ upcomingCount }}</span>
        <span class="metric-label">Upcoming</span>
      </div>
      <div class="metric-pill muted">
        <span class="metric-num">{{ passedCount }}</span>
        <span class="metric-label">Passed</span>
      </div>
    </div>

    <!-- ── Add / Edit form ─────────────────────────────────────────────────── -->
    <div v-if="showForm" class="card form-card">
      <div class="form-card-header">
        <span class="form-card-title">{{ editId ? "Edit Holiday" : "Add Holiday" }}</span>
        <button class="btn btn-ghost btn-sm" @click="showForm = false">✕</button>
      </div>
      <p v-if="saveError" class="error">{{ saveError }}</p>
      <div class="form-grid-3">
        <div class="form-field">
          <label>Name <span class="required">*</span></label>
          <input v-model="form.name" type="text" class="input" placeholder="e.g. Mashujaa Day" />
        </div>
        <div class="form-field">
          <label>Local / Swahili Name</label>
          <input v-model="form.local_name" type="text" class="input" placeholder="Optional" />
        </div>
        <div class="form-field">
          <label>Date <span class="required">*</span></label>
          <input v-model="form.date" type="date" class="input" />
        </div>
      </div>
      <div class="toggle-row">
        <label class="toggle-label">
          <span class="toggle-track" :class="{ on: form.is_active }" @click="form.is_active = !form.is_active">
            <span class="toggle-thumb" />
          </span>
          Active
        </label>
      </div>
      <div class="form-actions">
        <button class="btn btn-ghost" @click="showForm = false">Cancel</button>
        <button class="btn btn-primary" :disabled="saving" @click="save">
          {{ saving ? "Saving…" : editId ? "Save Changes" : "Add Holiday" }}
        </button>
      </div>
    </div>

    <!-- ── Holidays table ──────────────────────────────────────────────────── -->
    <p v-if="loading" class="status">Loading…</p>
    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Name</th>
            <th>Local Name</th>
            <th>Source</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!holidays.length">
            <td colspan="6" class="empty">
              No holidays for {{ year }}. Use "Sync Kenya {{ year }}" above to import them.
            </td>
          </tr>
          <tr
            v-for="h in holidays"
            :key="h.id"
            :class="{ 'row-past': isPast(h.date) && h.is_active, 'row-today': isToday(h.date), 'row-inactive': !h.is_active }"
          >
            <td class="cell-date">
              <span class="date-badge" :class="{ past: isPast(h.date), today: isToday(h.date) }">
                {{ formatDate(h.date) }}
              </span>
              <span v-if="isToday(h.date)" class="today-chip">Today</span>
            </td>
            <td class="cell-name">{{ h.name }}</td>
            <td class="cell-local">{{ h.local_name ?? "—" }}</td>
            <td>
              <span class="badge" :class="h.source === 'imported' ? 'badge-blue' : 'badge-gray'">
                {{ h.source === "imported" ? "Synced" : "Manual" }}
              </span>
            </td>
            <td>
              <span class="badge" :class="h.is_active ? 'badge-green' : 'badge-gray'">
                {{ h.is_active ? "Active" : "Inactive" }}
              </span>
            </td>
            <td class="cell-actions">
              <button class="btn btn-ghost btn-sm" @click="openEdit(h)">Edit</button>
              <button class="btn btn-ghost btn-sm btn-danger-ghost" @click="remove(h)">Remove</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
/* ── Import bar ──────────────────────────────────────────────────────────── */
.import-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  padding: 16px 20px;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-xs);
  margin-bottom: 8px;
}
.import-bar-left {
  display: flex;
  align-items: flex-start;
  gap: 14px;
}
.import-icon {
  width: 22px;
  height: 22px;
  color: var(--color-brand-base);
  flex-shrink: 0;
  margin-top: 2px;
}
.import-title {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
  margin: 0 0 2px;
}
.import-sub {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin: 0;
}
.ext-link {
  color: var(--color-brand-base);
  text-decoration: underline;
}
.import-bar-right {
  display: flex;
  align-items: center;
  gap: 12px;
}
.year-selector {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text);
}
.year-input { width: 88px; }

.import-msg {
  font-size: var(--font-size-sm);
  margin: 6px 0 12px;
  padding: 8px 14px;
  border-radius: var(--radius-lg);
}
.import-msg.success {
  background: var(--color-success-light);
  color: var(--color-success);
  border: 1px solid rgba(16, 137, 129, 0.2);
}

/* Spinner */
.spin-icon {
  width: 14px;
  height: 14px;
  animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Metric pills ────────────────────────────────────────────────────────── */
.metrics-row {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 20px;
}
.metric-pill {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 18px;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-xs);
}
.metric-pill.green { border-color: var(--color-success); background: var(--color-success-light); }
.metric-pill.muted { background: var(--color-neutral-100, #F9FAFB); }

.metric-num {
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
  line-height: 1;
}
.metric-pill.green .metric-num { color: var(--color-success); }
.metric-pill.muted  .metric-num { color: var(--color-text-muted); }
.metric-label {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
}

/* ── Form card ───────────────────────────────────────────────────────────── */
.form-card {
  padding: 24px;
  margin-bottom: 20px;
}
.form-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}
.form-card-title {
  font-size: var(--font-size-md);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
}
.required { color: var(--color-error); }

.form-grid-3 {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-bottom: 16px;
}
@media (max-width: 720px) { .form-grid-3 { grid-template-columns: 1fr; } }

.toggle-row {
  display: flex;
  gap: 20px;
  flex-wrap: wrap;
  margin-bottom: 20px;
  padding: 12px 16px;
  background: var(--color-neutral-100, #F9FAFB);
  border-radius: var(--radius-lg);
}
.toggle-label {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text);
  cursor: pointer;
  user-select: none;
}
.toggle-track {
  position: relative;
  display: inline-block;
  width: 36px;
  height: 20px;
  background: var(--color-neutral-300, #D1D5DB);
  border-radius: var(--radius-full);
  transition: background 0.18s;
  flex-shrink: 0;
}
.toggle-track.on { background: var(--color-brand-base); }
.toggle-thumb {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 16px;
  height: 16px;
  background: #fff;
  border-radius: 50%;
  box-shadow: 0 1px 3px rgba(0,0,0,0.2);
  transition: left 0.18s;
}
.toggle-track.on .toggle-thumb { left: 18px; }

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

/* ── Table overrides ─────────────────────────────────────────────────────── */
.cell-date { white-space: nowrap; }
.date-badge {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text);
}
.date-badge.past  { color: var(--color-text-muted); }
.date-badge.today { color: var(--color-brand-base); }
.today-chip {
  display: inline-block;
  margin-left: 8px;
  padding: 1px 7px;
  background: var(--color-brand-base);
  color: #fff;
  border-radius: var(--radius-full);
  font-size: 10px;
  font-weight: var(--font-weight-bold);
  letter-spacing: 0.04em;
  vertical-align: middle;
}
.cell-name  { font-weight: var(--font-weight-semibold); }
.cell-local { color: var(--color-text-muted); font-style: italic; }
.cell-actions { text-align: right; white-space: nowrap; }

.row-past td { opacity: 0.65; }
.row-today { background: var(--color-brand-subtle); }
.row-inactive td { opacity: 0.45; text-decoration: line-through; }

.btn-danger-ghost { color: var(--color-error); }
.btn-danger-ghost:hover:not(:disabled) {
  color: var(--color-error);
  background: var(--color-error-light);
}
</style>
