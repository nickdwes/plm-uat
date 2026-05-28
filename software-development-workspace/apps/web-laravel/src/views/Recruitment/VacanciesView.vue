<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";

const router = useRouter();
import type { Vacancy, Paginator } from "../../lib/types";

const vacancies = ref<Vacancy[]>([]);
const total     = ref(0);
const lastPage  = ref(1);
const page      = ref(1);
const status    = ref("");
const search    = ref("");
const loading   = ref(false);
const error     = ref("");
const perPage   = 20;

const hasPrev = computed(() => page.value > 1);
const hasNext = computed(() => page.value < lastPage.value);

const statusOptions = [
  { label: "All",    value: "" },
  { label: "Open",   value: "open" },
  { label: "Draft",  value: "draft" },
  { label: "Closed", value: "closed" },
];

function org() { return currentOrgSlug.value; }

async function load(p = 1) {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true;
  error.value   = "";
  page.value    = p;
  try {
    const res = await api<Paginator<Vacancy>>("GET", "/api/v1/recruitment/vacancies", {
      org: org(),
      params: { page: p, per_page: perPage, status: status.value || undefined, search: search.value || undefined },
    });
    vacancies.value = res.data;
    total.value     = res.meta.total;
    lastPage.value  = res.meta.last_page;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load vacancies.";
  } finally {
    loading.value = false;
  }
}

let debounceTimer: ReturnType<typeof setTimeout>;
function onSearchInput() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => load(1), 350);
}

function clearSearch() {
  search.value = "";
  load(1);
}

function setStatus(val: string) {
  status.value = val;
  load(1);
}

function statusClass(s: string) {
  return s === "open" ? "badge-green" : s === "closed" ? "badge-red" : "badge-yellow";
}

onMounted(() => load(1));
watch(currentOrgSlug, () => load(1));
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <button class="back-btn" @click="router.back()">← Back</button>
        <h2>Vacancies</h2>
      </div>
      <router-link :to="{ name: 'vacancy-new' }" class="btn btn-primary">+ New Vacancy</router-link>
    </div>

    <!-- ── Search bar ───────────────────────────────────────────────────── -->
    <div class="search-bar">
      <div class="search-field">
        <svg class="search-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
          <circle cx="8.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.6"/>
          <path d="M13 13l3.5 3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
        <input
          v-model="search"
          type="search"
          class="search-input"
          placeholder="Search by name, title or location…"
          @input="onSearchInput"
          @keyup.enter="load(1)"
        />
        <button v-if="search" class="search-clear" @click="clearSearch" aria-label="Clear search">
          <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
          </svg>
        </button>
      </div>

      <div class="status-pills" role="group" aria-label="Filter by status">
        <button
          v-for="opt in statusOptions"
          :key="opt.value"
          class="status-pill"
          :class="{ active: status === opt.value }"
          @click="setStatus(opt.value)"
        >{{ opt.label }}</button>
      </div>

      <span v-if="!loading" class="result-count">
        {{ total.toLocaleString() }} {{ total === 1 ? "vacancy" : "vacancies" }}
      </span>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error" class="error">{{ error }}</p>

    <div v-if="!loading" class="table-card">
      <table class="data-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Job Title</th>
            <th>Location</th>
            <th>Open Positions</th>
            <th>Status</th>
            <th>Closing Date</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="vacancies.length === 0">
            <td colspan="7" class="empty-row">No vacancies found.</td>
          </tr>
          <tr v-for="v in vacancies" :key="v.id" class="table-row">
            <td class="font-medium">{{ v.name }}</td>
            <td>{{ v.job_title }}</td>
            <td>{{ v.location ?? "—" }}</td>
            <td>{{ v.open_positions }}</td>
            <td><span class="badge" :class="statusClass(v.status)">{{ v.status }}</span></td>
            <td>{{ v.closing_date ? new Date(v.closing_date).toLocaleDateString() : "—" }}</td>
            <td>
              <router-link :to="{ name: 'vacancy-detail', params: { id: v.id } }" class="link-btn">View</router-link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="lastPage > 1" class="pagination">
      <button :disabled="!hasPrev" class="btn btn-sm" @click="load(page - 1)">← Prev</button>
      <span class="page-info">Page {{ page }} of {{ lastPage }}</span>
      <button :disabled="!hasNext" class="btn btn-sm" @click="load(page + 1)">Next →</button>
    </div>
  </div>
</template>

<style scoped>
.back-btn { background: none; border: none; cursor: pointer; color: var(--color-text-muted); font-size: var(--font-size-sm); margin-bottom: 4px; display: block; }
.back-btn:hover { color: var(--color-text); }
/* ── Search bar ─────────────────────────────────────────────────────────── */
.search-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.search-field {
  position: relative;
  flex: 1 1 280px;
  min-width: 200px;
  max-width: 440px;
}

.search-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  width: 16px;
  height: 16px;
  color: var(--color-text-light);
  pointer-events: none;
}

.search-input {
  width: 100%;
  padding: 9px 36px 9px 38px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  font-size: var(--font-size-base);
  font-family: var(--font-family);
  color: var(--color-text);
  background: var(--color-surface);
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s;
  /* hide native search clear button */
  -webkit-appearance: none;
  appearance: none;
}
.search-input::-webkit-search-cancel-button { display: none; }
.search-input::placeholder { color: var(--color-text-light); }
.search-input:focus {
  border-color: var(--color-brand-base);
  box-shadow: 0 0 0 3px var(--color-brand-subtle);
}

.search-clear {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  padding: 0;
  border: none;
  background: var(--color-neutral-200);
  border-radius: 50%;
  cursor: pointer;
  color: var(--color-text-muted);
  transition: background 0.12s, color 0.12s;
}
.search-clear:hover {
  background: var(--color-neutral-300, #d1d5db);
  color: var(--color-text);
}
.search-clear svg { width: 10px; height: 10px; }

/* ── Status pills ───────────────────────────────────────────────────────── */
.status-pills {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.status-pill {
  padding: 6px 14px;
  border-radius: var(--radius-full);
  font-size: var(--font-size-sm);
  font-family: var(--font-family);
  font-weight: var(--font-weight-semibold);
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-text-muted);
  cursor: pointer;
  transition: all 0.15s;
  white-space: nowrap;
}
.status-pill:hover:not(.active) {
  background: var(--color-neutral-100);
  color: var(--color-text);
}
.status-pill.active {
  background: var(--color-brand-base);
  border-color: var(--color-brand-base);
  color: #fff;
}

/* ── Result count ───────────────────────────────────────────────────────── */
.result-count {
  margin-left: auto;
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  white-space: nowrap;
}
</style>
