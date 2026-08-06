<script setup lang="ts">
/**
 * The customer master.
 *
 * The columns are the ones the agreed design settled on, and each answers a
 * question people actually bring: who is this, who do we call, what is our
 * relationship with them (Type), is the record live (Status), and when did we
 * last speak to them.
 *
 * Type and Status stopped overlapping in the same change that built this.
 * Type is the relationship — Prospect, Client, Partner, Supplier. Status is
 * whether the record is live. A customer used to be able to be a Client of
 * status prospect, which is why both columns are here and neither repeats the
 * other.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";

interface Named { id: number; name: string }
interface Account {
  id: number;
  reference: string | null;
  name: string;
  initials: string;
  status: string;
  country: string | null;
  last_contact_at: string | null;
  account_manager?: { id: number | null; name: string | null };
  type?: Named | null;
  sector?: Named | null;
  primary_contact?: { id: number; name: string; email: string | null; role: string | null } | null;
}

const router  = useRouter();
const rows    = ref<Account[]>([]);
const types   = ref<Named[]>([]);
const loading = ref(true);
const saving  = ref(false);

const search       = ref("");
const statusFilter = ref("");
const typeFilter   = ref("");
const showFilters  = ref(false);

const showNew    = ref(false);
const newAccount = ref({ name: "", account_type_id: null as number | null, country: "" });

const org = () => currentOrgSlug.value;

async function load() {
  loading.value = true;
  try {
    const res = await api<{ data: Account[] }>("GET", "/api/v1/accounts", {
      org: org(),
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        account_type_id: typeFilter.value || undefined,
        per_page: 100,
      },
    });
    rows.value = res.data;
  } catch (e) {
    notifyFailed("Could not load customers.", e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
  } finally {
    loading.value = false;
  }
}

async function loadLookups() {
  try {
    const res = await api<{ types: Named[] }>("GET", "/api/v1/accounts/lookups", { org: org() });
    types.value = res.types;
  } catch {
    // A missing type list must not look like "no types exist" on the create
    // form — it stays empty and the name alone is still enough to start.
  }
}

async function create() {
  if (!newAccount.value.name.trim()) return;
  saving.value = true;
  try {
    const res = await api<{ data: Account }>("POST", "/api/v1/accounts", {
      org: org(),
      body: { ...newAccount.value, country: newAccount.value.country || null },
    });
    showNew.value = false;
    newAccount.value = { name: "", account_type_id: null, country: "" };

    // Raised before navigating: the toast lives on the shell and survives the
    // route change, which an inline banner here would not.
    notifySaved(`${res.data.name} added.`);
    // Straight into the record: a customer is created in order to fill it in.
    router.push({ name: "account", params: { id: res.data.id } });
  } catch (e) {
    notifyFailed("Could not create the customer.", e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
  } finally { saving.value = false; }
}

/** Relationship, coloured so the book reads at a glance. */
function typeTone(name: string | undefined): string {
  switch ((name ?? "").toLowerCase()) {
    case "client":   return "green";
    case "prospect": return "amber";
    case "partner":  return "blue";
    case "supplier": return "violet";
    default:         return "grey";
  }
}

const statusTone = (s: string) =>
  s === "active" ? "ok" : s === "inactive" ? "neutral" : "warn";

/**
 * A date is easier to act on than a timestamp, and "never" is the answer worth
 * seeing — a customer nobody has logged a call with is the one to chase.
 */
function lastContact(iso: string | null): string {
  if (!iso) return "Never";
  return new Date(iso).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" });
}

const filtersActive = computed(() => Boolean(statusFilter.value || typeFilter.value));

function clearFilters() {
  statusFilter.value = "";
  typeFilter.value = "";
  void load();
}

onMounted(() => { void load(); void loadLookups(); });
</script>

<template>
  <div class="ac-wrap">
    <header class="ac-header">
      <div>
        <h1 class="ac-title">Accounts</h1>
        <p class="ac-sub">A quick overview of all our clients</p>
      </div>
    </header>

    <div class="ac-toolbar">
      <div class="ac-search-wrap">
        <svg class="ac-search-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
          <circle cx="9" cy="9" r="6" /><path d="M13.5 13.5L17 17" stroke-linecap="round" />
        </svg>
        <input v-model="search" class="control ac-search" type="search"
               placeholder="Search" @keyup.enter="load" />
      </div>

      <button class="btn-ghost" :class="{ 'btn-on': filtersActive }" @click="showFilters = !showFilters">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
          <path d="M3 5h14M6 10h8M8.5 15h3" />
        </svg>
        Filter
      </button>

      <button class="btn-primary" @click="showNew = true">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M10 4v12M4 10h12" />
        </svg>
        New Account
      </button>
    </div>

    <div v-if="showFilters" class="ac-filters">
      <label class="ac-field"><span>Status</span>
        <select v-model="statusFilter" class="control" @change="load">
          <option value="">All</option>
          <option value="pending">Pending</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </label>
      <label class="ac-field"><span>Type</span>
        <select v-model="typeFilter" class="control" @change="load">
          <option value="">All</option>
          <option v-for="t in types" :key="t.id" :value="String(t.id)">{{ t.name }}</option>
        </select>
      </label>
      <button v-if="filtersActive" class="link-btn ac-clear" @click="clearFilters">Clear</button>
    </div>

    <div v-if="loading" class="ac-loading">Loading…</div>

    <div v-else-if="rows.length === 0 && !search && !filtersActive" class="ac-empty">
      <h2 class="ac-empty-title">No customers yet</h2>
      <p class="ac-empty-sub">
        Travel requests, deliveries and contracts all name the customer they are
        for, so this is usually the first thing to fill in.
      </p>
      <button class="btn-primary" @click="showNew = true">New Account</button>
    </div>

    <div v-else class="ac-card">
      <table class="ac-table">
        <thead>
          <tr>
            <th>Clients</th><th>Contact Person</th><th>Email</th>
            <th>Type</th><th>Status</th><th>Last Contact</th><th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="rows.length === 0">
            <td colspan="7" class="ac-none">Nothing matches that search.</td>
          </tr>
          <tr v-for="a in rows" :key="a.id">
            <td class="ac-name">{{ a.name }}</td>
            <td>{{ a.primary_contact?.name ?? "—" }}</td>
            <td class="ac-email">{{ a.primary_contact?.email ?? "—" }}</td>
            <td>
              <span class="ac-type">
                <i :class="['ac-dot', `dot-${typeTone(a.type?.name)}`]"></i>{{ a.type?.name ?? "—" }}
              </span>
            </td>
            <td><span :class="['pill', `pill-${statusTone(a.status)}`]">{{ a.status }}</span></td>
            <td class="ac-date">{{ lastContact(a.last_contact_at) }}</td>
            <td>
              <button class="link-btn" @click="router.push({ name: 'account', params: { id: a.id } })">
                View
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- New customer -->
    <div v-if="showNew" class="ac-modal-backdrop" @click.self="showNew = false">
      <div class="ac-modal">
        <div class="ac-modal-head">
          <h3 class="ac-modal-title">New Account</h3>
          <button class="ac-close" aria-label="Close" @click="showNew = false">×</button>
        </div>

        <p class="ac-note">
          Name is enough to start. Industry, owner and contacts are added on the
          record — a customer half-entered is more useful than one nobody created.
        </p>

        <label class="ac-field"><span>Client Name</span>
          <input v-model="newAccount.name" class="control" placeholder="Kenya Commercial Bank" /></label>

        <label class="ac-field"><span>Type</span>
          <select v-model.number="newAccount.account_type_id" class="control">
            <option :value="null">Select a type</option>
            <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select></label>

        <label class="ac-field"><span>Country (ISO code)</span>
          <input v-model="newAccount.country" class="control" maxlength="2" placeholder="KE" /></label>

        <button class="btn-primary btn-block" :disabled="saving || !newAccount.name.trim()" @click="create">
          {{ saving ? "Creating…" : "Add Account" }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.ac-wrap { padding: var(--space-6); max-width: 82rem; }

.ac-header { margin-bottom: var(--space-5); }
.ac-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ac-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

.ac-loading { padding: var(--space-6); color: var(--color-text-muted); }

.ac-empty { @apply flex flex-col items-center; padding: var(--space-8) var(--space-6); text-align: center; gap: var(--space-2); }
.ac-empty-title { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ac-empty-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0 0 var(--space-3); max-width: 46ch; }

.ac-toolbar { @apply flex flex-wrap items-center justify-end; gap: var(--space-3); margin-bottom: var(--space-4); }
.ac-search-wrap { @apply relative; flex: 1; min-width: 12rem; max-width: 26rem; margin-right: auto; }
.ac-search-icon { @apply absolute; width: 1rem; height: 1rem; left: var(--space-3); top: 50%; transform: translateY(-50%); color: var(--color-text-light); pointer-events: none; }
.ac-search { padding-left: 2.25rem; }

.ac-filters { @apply flex flex-wrap items-end; gap: var(--space-3); margin-bottom: var(--space-4); }
.ac-filters .ac-field { min-width: 10rem; }
.ac-clear { align-self: center; }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.ac-card {
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow-x: auto;
}

.ac-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.ac-table th {
  text-align: left; padding: var(--space-3) var(--space-4);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border); white-space: nowrap;
}
.ac-table td { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.ac-table tr:last-child td { border-bottom: none; }
.ac-name { font-weight: 500; }
.ac-email { color: var(--color-text-muted); }
.ac-date { white-space: nowrap; font-variant-numeric: tabular-nums; }
.ac-none { text-align: center; color: var(--color-text-light); padding: var(--space-6); }

/* Relationship, as a coloured marker rather than another pill — Status is the
   pill, and two pills per row stop either from standing out. */
.ac-type { @apply inline-flex items-center; gap: var(--space-2); white-space: nowrap; }
.ac-dot { @apply inline-block; width: 6px; height: 6px; border-radius: 999px; }
.dot-green  { background: var(--color-success-text); }
.dot-amber  { background: var(--color-warning-text); }
.dot-blue   { background: var(--color-brand-base); }
.dot-violet { background: color-mix(in oklab, var(--color-brand-base) 60%, purple); }
.dot-grey   { background: var(--color-text-light); }

.ac-note { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; }
.ac-field { @apply flex flex-col; gap: var(--space-1); }
.ac-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }

.pill { display: inline-block; padding: 2px var(--space-3); border-radius: 999px; font-size: var(--text-xs-size); text-transform: capitalize; border: 1px solid transparent; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 30%, transparent); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); border-color: color-mix(in oklab, var(--color-warning-text) 30%, transparent); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); border-color: var(--color-border); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }

.ac-modal-backdrop { @apply fixed inset-0 flex items-center justify-center z-50; background: rgb(0 0 0 / 0.45); padding: var(--space-4); }
.ac-modal {
  @apply flex flex-col w-full; max-width: 26rem; gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised);
  border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
}
.ac-modal-head { @apply flex items-center justify-between; }
.ac-modal-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.ac-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

.btn-primary, .btn-ghost {
  @apply inline-flex cursor-pointer items-center justify-center;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-ghost   { background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-on      { border-color: var(--color-brand-base); color: var(--color-brand-on-surface); }
.btn-block   { width: 100%; }
.btn-primary svg, .btn-ghost svg { width: 1rem; height: 1rem; }
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }

</style>
