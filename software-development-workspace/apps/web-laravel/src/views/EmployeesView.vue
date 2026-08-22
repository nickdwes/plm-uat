<script setup lang="ts">
/**
 * The employee directory, adopted from the HR portal designs.
 *
 * Four figures rather than the design's five. Employee status holds only
 * `active` and `exited` and `probation_end_date` is populated for nobody, so
 * On Probation has nothing behind it — and a card reading 0 asserts that
 * nobody is on probation, which is a different claim from not knowing. It
 * comes back when probation is something the company records.
 */
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import type { Paginator, Employee, DirectoryFigures } from "../lib/types";

const router = useRouter();

const employees   = ref<Employee[]>([]);
const total       = ref(0);
const currentPage = ref(1);
const lastPage    = ref(1);
const perPage     = 20;
const search      = ref("");
const loading     = ref(false);
const error       = ref("");

const figures        = ref<DirectoryFigures | null>(null);
const figuresLoading = ref(false);

/**
 * The status tabs. "On leave" is not a status — it is a fact about today,
 * derived from approved leave — so it travels as its own filter rather than
 * being squeezed into the status column.
 */
type Filter = "all" | "active" | "on_leave" | "exited";
const filter = ref<Filter>("all");

/* Declared here rather than inline: a template expression is not a place a
   type assertion can live. */
const statusTabs: { key: Filter; label: string }[] = [
  { key: "all",      label: "All Employees" },
  { key: "active",   label: "Active" },
  { key: "on_leave", label: "On Leave" },
  { key: "exited",   label: "Exited" },
];

const employmentTypeFilter = ref("");
const filtersOpen = ref(false);

const hasPrev = computed(() => currentPage.value > 1);
const hasNext = computed(() => currentPage.value < lastPage.value);

function org(): string | null { return currentOrgSlug.value; }

function params(page: number): Record<string, string | number> {
  const p: Record<string, string | number> = { page, per_page: perPage };

  if (search.value.trim()) p.search = search.value.trim();
  if (employmentTypeFilter.value) p.employment_type = employmentTypeFilter.value;

  if (filter.value === "on_leave") p.on_leave = 1;
  else if (filter.value !== "all") p.status = filter.value;

  return p;
}

async function load(page = 1): Promise<void> {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true;
  error.value   = "";
  currentPage.value = page;
  try {
    const res = await api<Paginator<Employee>>("GET", "/api/v1/employees", {
      org: org(), params: params(page),
    });
    employees.value = res.data;
    total.value     = res.meta.total;
    lastPage.value  = res.meta.last_page;
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load employees.";
  } finally {
    loading.value = false;
  }
}

async function loadFigures(): Promise<void> {
  if (!org()) return;
  figuresLoading.value = true;
  try {
    const res = await api<{ data: DirectoryFigures }>("GET", "/api/v1/employees/summary", { org: org() });
    figures.value = res.data;
  } catch { figures.value = null; }
  finally { figuresLoading.value = false; }
}

function pick(f: Filter): void {
  filter.value = f;
  void load(1);
}

function statusBadge(status: string): string {
  return {
    active: "badge-green",
    exited: "badge-gray",
    inactive: "badge-yellow",
    draft: "badge-gray",
  }[status] ?? "badge-gray";
}

/**
 * Initials, because nothing populates `photo_url` yet.
 *
 * The designs lean on avatars throughout; a broken image on every row would
 * be a worse answer than two letters.
 */
function initials(e: Employee): string {
  const parts = (e.full_name ?? "").trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return "—";
  return ((parts[0][0] ?? "") + (parts.length > 1 ? parts[parts.length - 1][0] : "")).toUpperCase();
}

function jobTitle(e: Employee): string | null {
  return e.host_title ?? e.designation?.name ?? null;
}

function refresh(): void {
  void load(1);
  void loadFigures();
}

onMounted(refresh);
watch(currentOrgSlug, refresh);
</script>

<template>
  <div class="view ev">
    <header class="ev-head">
      <div>
        <h2 class="ev-title">Employees</h2>
        <p class="ev-lead">View and manage employee information across the organisation</p>
      </div>
      <div class="ev-head-actions">
        <!-- Export is the People Report, which does more than a button here
             could: chosen columns, ordering and a protected file. -->
        <router-link :to="{ name: 'employee-import' }" class="btn btn-secondary btn-sm">Import</router-link>
        <router-link :to="{ name: 'people-report' }" class="btn btn-secondary btn-sm">Export</router-link>
        <router-link :to="{ name: 'onboard' }" class="btn btn-primary btn-sm">+ Add Employee</router-link>
      </div>
    </header>

    <!--
      Four figures, counted over the same population the table below lists —
      employed here or placed here. See the comment on DirectoryFigures for
      why On Probation is absent.
    -->
    <div v-if="figures" class="ev-figures">
      <div class="ev-fig">
        <span>Total Employees</span>
        <strong>{{ figures.total }}</strong>
      </div>
      <div class="ev-fig">
        <span>Active Employees</span>
        <strong>{{ figures.active }}</strong>
      </div>
      <div class="ev-fig">
        <span>On Leave</span>
        <strong>{{ figures.on_leave }}</strong>
        <em>today</em>
      </div>
      <div class="ev-fig">
        <span>New (this month)</span>
        <strong>{{ figures.new_this_month }}</strong>
      </div>
    </div>

    <div class="ev-panel">
      <div class="ev-toolbar">
        <nav class="ev-tabs" role="tablist" aria-label="Filter employees">
          <button
            v-for="t in statusTabs"
            :key="t.key"
            role="tab"
            :aria-selected="filter === t.key"
            :class="['ev-tab', { 'is-on': filter === t.key }]"
            @click="pick(t.key)"
          >{{ t.label }}</button>
        </nav>

        <div class="ev-tool-right">
          <label class="ev-search">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
              <circle cx="9" cy="9" r="5.5" /><path d="M13 13l4 4" />
            </svg>
            <input
              v-model="search" type="search" placeholder="Search by name, email, id"
              aria-label="Search employees" @keyup.enter="load(1)"
            />
          </label>
          <button class="btn btn-secondary btn-sm" :aria-expanded="filtersOpen" @click="filtersOpen = !filtersOpen">
            Filter
          </button>
        </div>
      </div>

      <div v-if="filtersOpen" class="ev-filters">
        <label>
          <span>Employment type</span>
          <select v-model="employmentTypeFilter" class="input" @change="load(1)">
            <option value="">Any</option>
            <option value="permanent">Permanent</option>
            <option value="contract">Contract</option>
            <option value="intern">Intern</option>
            <option value="attachment">Attachment</option>
          </select>
        </label>
        <button class="btn btn-secondary btn-sm" @click="employmentTypeFilter = ''; search = ''; load(1)">Clear</button>
      </div>

      <p v-if="loading" class="status">Loading…</p>
      <p v-if="error" class="error">{{ error }}</p>

      <div v-if="!loading" class="ev-table-wrap">
        <table class="ev-table">
          <thead>
            <tr>
              <th>Employee</th><th>ID</th><th>Department</th><th>Role</th>
              <th>Emp Type</th><th>Status</th><th>Date Joined</th><th class="ev-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="emp in employees" :key="emp.id">
              <td>
                <span class="ev-who">
                  <img v-if="emp.photo_url" :src="emp.photo_url" alt="" class="ev-photo" />
                  <span v-else class="ev-avatar" aria-hidden="true">{{ initials(emp) }}</span>
                  <span class="ev-who-text">
                    <strong>
                      {{ emp.full_name }}
                      <!--
                        Somebody placed here from another company in the group.
                        Without saying so the only clue is a department name
                        that looks unfamiliar.
                      -->
                      <span v-if="emp.is_visiting" class="visiting" :title="`Employed by ${emp.home_organization}`">
                        {{ emp.home_organization }}
                      </span>
                    </strong>
                    <em v-if="emp.work_email">{{ emp.work_email }}</em>
                  </span>
                </span>
              </td>
              <td class="ev-id">{{ emp.employee_number ?? "—" }}</td>
              <!-- A visitor's department and title are the ones their placement
                   gives them here; their own belong to a tenant this
                   organisation cannot see. -->
              <td>{{ emp.host_department ?? emp.department?.name ?? "—" }}</td>
              <td>
                <template v-if="jobTitle(emp)">{{ jobTitle(emp) }}</template>
                <!-- Distinct from a dash: no designations exist yet, so this is
                     a gap to fill rather than a blank field. -->
                <span v-else class="ev-none">not set</span>
              </td>
              <td class="ev-type">{{ emp.employment_type ?? "—" }}</td>
              <td><span :class="['badge', statusBadge(emp.status)]">{{ emp.status }}</span></td>
              <td>{{ emp.date_of_joining ?? "—" }}</td>
              <td class="ev-right">
                <button class="ev-link" @click="router.push({ name: 'employee-detail', params: { id: emp.id } })">
                  View
                </button>
              </td>
            </tr>
            <tr v-if="employees.length === 0">
              <td colspan="8" class="empty">
                {{ filter === "on_leave" ? "Nobody is on leave today." : "No employees found." }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="!loading" class="ev-pager">
        <span>Page {{ currentPage }} of {{ lastPage }} · {{ total }} total</span>
        <div>
          <button class="btn btn-secondary btn-sm" :disabled="!hasPrev" @click="load(currentPage - 1)">← Previous</button>
          <button class="btn btn-secondary btn-sm" :disabled="!hasNext" @click="load(currentPage + 1)">Next →</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Konvergenz tokens throughout — see docs/design-system.md. */
@reference "../theme.css";

.ev { display: flex; flex-direction: column; gap: var(--space-5); }

.ev-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: var(--space-4); }
.ev-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ev-lead  { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }
.ev-head-actions { display: flex; gap: var(--space-2); }

/* ── Figures ───────────────────────────────────────────────────────────── */
.ev-figures {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  background: var(--color-surface-raised);
}
.ev-fig { display: flex; flex-direction: column; padding: 0 var(--space-4); }
.ev-fig + .ev-fig { border-left: 1px solid var(--color-border); }
.ev-fig span   { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.ev-fig strong { font-size: var(--text-2xl-size); font-weight: 600; color: var(--color-text); margin-top: var(--space-1); }
.ev-fig em     { font-style: normal; font-size: var(--text-xs-size); color: var(--color-text-light); }

/* ── Panel ─────────────────────────────────────────────────────────────── */
.ev-panel {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  background: var(--color-surface-raised);
  overflow: hidden;
}

.ev-toolbar {
  display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4) 0;
  border-bottom: 1px solid var(--color-border);
}

.ev-tabs { display: flex; flex-wrap: wrap; gap: var(--space-5); }
.ev-tab {
  border: none; background: none; cursor: pointer; font-family: inherit;
  font-size: var(--text-sm-size); font-weight: 500;
  color: var(--color-text-muted);
  padding: var(--space-2) 0 var(--space-3);
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
}
.ev-tab:hover  { color: var(--color-text); }
.ev-tab.is-on  { color: var(--color-brand-base); border-bottom-color: var(--color-brand-base); }

.ev-tool-right { display: flex; align-items: center; gap: var(--space-2); padding-bottom: var(--space-3); }

.ev-search {
  display: flex; align-items: center; gap: var(--space-2);
  min-width: 15rem;
  padding: 0 var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface);
}
.ev-search svg { width: 1rem; height: 1rem; flex-shrink: 0; color: var(--color-text-light); }
.ev-search input {
  width: 100%; border: none; background: none; font-family: inherit;
  font-size: var(--text-sm-size); color: var(--color-text); padding: 0.5rem 0;
}
.ev-search input:focus { outline: none; }

.ev-filters {
  display: flex; flex-wrap: wrap; align-items: flex-end; gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-sunken);
}
.ev-filters label { display: flex; flex-direction: column; gap: 4px; font-size: var(--text-xs-size); color: var(--color-text-muted); }

/* ── Table ─────────────────────────────────────────────────────────────── */
.ev-table-wrap { overflow-x: auto; }
.ev-table { width: 100%; border-collapse: collapse; }

.ev-table th {
  text-align: left; white-space: nowrap; font-weight: 600;
  font-size: var(--text-xs-size); color: var(--color-text-muted);
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-sunken);
}
.ev-table td {
  font-size: var(--text-sm-size); color: var(--color-text);
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
  vertical-align: middle;
}
.ev-table tbody tr:last-child td { border-bottom: none; }
.ev-table tbody tr:hover { background: var(--color-surface-sunken); }

.ev-right { text-align: right; }
.ev-id    { font-variant-numeric: tabular-nums; color: var(--color-text-muted); }
.ev-type  { text-transform: capitalize; }

.ev-who { display: flex; align-items: center; gap: var(--space-3); }
.ev-who-text { display: flex; flex-direction: column; }
.ev-who-text strong { font-weight: 600; font-size: var(--text-sm-size); }
.ev-who-text em { font-style: normal; font-size: var(--text-xs-size); color: var(--color-text-muted); }

.ev-photo, .ev-avatar { width: 2.25rem; height: 2.25rem; border-radius: 999px; flex-shrink: 0; }
.ev-photo { object-fit: cover; }
.ev-avatar {
  display: inline-flex; align-items: center; justify-content: center;
  font-size: var(--text-xs-size); font-weight: 600;
  background: var(--color-brand-light); color: var(--color-brand-base);
}

/* Nothing recorded, rather than an empty value. */
.ev-none { font-size: var(--text-xs-size); color: var(--color-text-light); }

.ev-link {
  border: none; background: none; cursor: pointer; padding: 0;
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500;
  color: var(--color-brand-base);
}
.ev-link:hover { text-decoration: underline; }

.ev-pager {
  display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--color-border);
  font-size: var(--text-xs-size); color: var(--color-text-muted);
}
.ev-pager > div { display: flex; gap: var(--space-2); }

/*
 * A visiting colleague's employer, beside their name. Deliberately quiet — it
 * is a fact about somebody's contract, not a warning, and on a directory where
 * several people are seconded a loud badge on each would be the first thing
 * the eye lands on instead of the names.
 */
.visiting {
  display: inline-block;
  margin-left: 8px;
  padding: 1px 7px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 500;
  color: var(--color-text-light);
  background: var(--color-surface-sunken);
  border: 1px solid var(--color-border);
  vertical-align: middle;
}

@media (max-width: 640px) {
  .ev-fig + .ev-fig {
    border-left: none;
    border-top: 1px solid var(--color-border);
    padding-top: var(--space-3);
    margin-top: var(--space-3);
  }
  .ev-toolbar { padding: var(--space-3); }
  .ev-tabs { gap: var(--space-4); }
}
</style>
