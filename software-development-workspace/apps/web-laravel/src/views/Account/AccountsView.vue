<script setup lang="ts">
/**
 * The customer master.
 *
 * Backed an API since Phase A and never had a screen, so customers could only
 * be created by whatever consumed them — a travel request could pick a client
 * that nothing could add.
 *
 * What the list shows is chosen for the question people bring to it: who owns
 * this customer, and are we still working them. Sector and type are here
 * because they are the axes the book gets reported on.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";

interface Named { id: number; name: string }
interface Account {
  id: number;
  name: string;
  status: string;
  country: string | null;
  account_manager?: { id: number | null; name: string | null };
  type?: Named | null;
  sector?: Named | null;
}

const router  = useRouter();
const rows    = ref<Account[]>([]);
const loading = ref(true);
const saving  = ref(false);
const error   = ref<string | null>(null);
const search  = ref("");
const statusFilter = ref("");
const showNew = ref(false);
const newAccount = ref({ name: "", status: "prospect", country: "" });

const org = () => currentOrgSlug.value;

async function load() {
  loading.value = true;
  try {
    const res = await api<{ data: Account[] }>("GET", "/api/v1/accounts", {
      org: org(),
      params: { search: search.value || undefined, status: statusFilter.value || undefined, per_page: 100 },
    });
    rows.value = res.data;
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not load customers.";
  } finally {
    loading.value = false;
  }
}

async function create() {
  if (!newAccount.value.name.trim()) return;
  saving.value = true;
  error.value = null;
  try {
    const res = await api<{ data: Account }>("POST", "/api/v1/accounts", {
      org: org(),
      body: { ...newAccount.value, country: newAccount.value.country || null },
    });
    showNew.value = false;
    newAccount.value = { name: "", status: "prospect", country: "" };
    // Straight into the record: a customer is created in order to fill it in.
    router.push({ name: "account", params: { id: res.data.id } });
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not create the customer.";
  } finally { saving.value = false; }
}

const statusTone = (s: string) =>
  s === "active" ? "ok" : s === "inactive" ? "neutral" : "warn";

const filtered = computed(() => rows.value);

onMounted(load);
</script>

<template>
  <div class="ac-wrap">
    <header class="ac-header">
      <div>
        <h1 class="ac-title">Customers</h1>
        <p class="ac-sub">Who we work with, who owns the relationship, and who to call</p>
      </div>
      <button class="btn-primary" @click="showNew = true">+ Customer</button>
    </header>

    <div v-if="error" class="alert-error">{{ error }}</div>
    <div v-if="loading" class="ac-loading">Loading…</div>

    <div v-else-if="rows.length === 0 && !search" class="ac-empty">
      <h2 class="ac-empty-title">No customers yet</h2>
      <p class="ac-empty-sub">
        Travel requests and deliveries name the customer they are for, so this is
        usually the first thing to fill in.
      </p>
      <button class="btn-primary" @click="showNew = true">+ Customer</button>
    </div>

    <template v-else>
      <div class="ac-toolbar">
        <input v-model="search" class="control ac-search" type="search"
               placeholder="Search by name, tax PIN or location" @keyup.enter="load" />
        <select v-model="statusFilter" class="control ac-filter" @change="load">
          <option value="">All statuses</option>
          <option value="prospect">Prospect</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
        <button class="btn-ghost" @click="load">Search</button>
      </div>

      <table class="ac-table">
        <thead>
          <tr><th>Customer</th><th>Owner</th><th>Sector</th><th>Type</th><th>Country</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-if="filtered.length === 0">
            <td colspan="7" class="ac-none">Nothing matches that search.</td>
          </tr>
          <tr v-for="a in filtered" :key="a.id">
            <td class="ac-name">{{ a.name }}</td>
            <td>{{ a.account_manager?.name ?? "—" }}</td>
            <td>{{ a.sector?.name ?? "—" }}</td>
            <td>{{ a.type?.name ?? "—" }}</td>
            <td>{{ a.country ?? "—" }}</td>
            <td><span :class="['pill', `pill-${statusTone(a.status)}`]">{{ a.status }}</span></td>
            <td>
              <button class="link-btn" @click="router.push({ name: 'account', params: { id: a.id } })">
                Open
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </template>

    <!-- New customer -->
    <div v-if="showNew" class="ac-modal-backdrop" @click.self="showNew = false">
      <div class="ac-modal">
        <h3 class="ac-modal-title">New customer</h3>
        <p class="ac-note">
          Name is enough to start. Sector, owner and contacts are added on the record —
          a customer half-entered is more useful than one nobody created.
        </p>

        <label class="ac-field"><span>Name</span>
          <input v-model="newAccount.name" class="control" placeholder="Kenya Commercial Bank" /></label>
        <label class="ac-field"><span>Status</span>
          <select v-model="newAccount.status" class="control">
            <option value="prospect">Prospect</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </label>
        <label class="ac-field"><span>Country (ISO code)</span>
          <input v-model="newAccount.country" class="control" maxlength="2" placeholder="KE" /></label>

        <div class="ac-modal-actions">
          <button class="btn-ghost" @click="showNew = false">Cancel</button>
          <button class="btn-primary" :disabled="saving || !newAccount.name.trim()" @click="create">Create</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.ac-wrap { padding: var(--space-6); max-width: 78rem; }

.ac-header { @apply flex items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-5); }
.ac-title { font-size: var(--text-2xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ac-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

.ac-loading { padding: var(--space-6); color: var(--color-text-muted); }

.ac-empty { @apply flex flex-col items-center; padding: var(--space-8) var(--space-6); text-align: center; gap: var(--space-2); }
.ac-empty-title { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ac-empty-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0 0 var(--space-3); max-width: 46ch; }

.ac-toolbar { @apply flex flex-wrap; gap: var(--space-3); margin-bottom: var(--space-4); }
.ac-search { max-width: 22rem; flex: 1; }
.ac-filter { max-width: 12rem; }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.ac-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.ac-table th {
  text-align: left; padding: var(--space-2) var(--space-3);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  text-transform: uppercase; letter-spacing: 0.04em;
  border-bottom: 1px solid var(--color-border);
}
.ac-table td { padding: var(--space-3); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.ac-name { font-weight: 500; }
.ac-none { text-align: center; color: var(--color-text-light); padding: var(--space-6); }

.ac-note { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; }
.ac-field { @apply flex flex-col; gap: var(--space-1); }
.ac-field span { font-size: var(--text-xs-size); color: var(--color-text-muted); }

.pill { display: inline-block; width: fit-content; padding: 2px var(--space-2); border-radius: 999px; font-size: var(--text-xs-size); text-transform: capitalize; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }

.ac-modal-backdrop { @apply fixed inset-0 flex items-center justify-center z-50; background: rgb(0 0 0 / 0.45); padding: var(--space-4); }
.ac-modal {
  @apply flex flex-col w-full; max-width: 26rem; gap: var(--space-3);
  padding: var(--space-5); background: var(--color-surface-raised);
  border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
}
.ac-modal-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.ac-modal-actions { @apply flex justify-end; gap: var(--space-2); margin-top: var(--space-2); }

.btn-primary, .btn-ghost {
  @apply cursor-pointer; padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-ghost   { background: transparent; color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }

.alert-error {
  padding: var(--space-3) var(--space-4); border-radius: var(--radius-md);
  font-size: var(--text-sm-size);
  background: var(--color-danger-subtle); color: var(--color-danger-text);
  margin-bottom: var(--space-4);
}
</style>
