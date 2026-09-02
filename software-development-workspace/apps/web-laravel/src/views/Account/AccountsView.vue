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
  logo_url: string | null;
  status: string;
  country: string | null;
  last_contact_at: string | null;
  account_manager?: { id: number | null; name: string | null };
  type?: Named | null;
  sector?: Named | null;
  primary_contact?: { id: number; name: string; email: string | null; role: string | null } | null;

  // List-only. What this customer is worth and what is happening on it.
  open_deals?: number; open_pipeline?: number | null;
  projects?: number; open_tickets?: number; active_contracts?: number;
}

interface BookTotals {
  customers: number; active: number; never_contacted: number;
  unowned: number; open_pipeline: number | null;
}

const router  = useRouter();
const totals = ref<BookTotals | null>(null);

/** Full figures in the table, so a column of numbers can be compared. */
const money = (v: number) =>
  v ? `KES ${v.toLocaleString(undefined, { maximumFractionDigits: 0 })}` : "0";

/**
 * The line under the customer's name.
 *
 * Sector, type and owner used to be three columns; each was empty on nearly
 * every row, so they cost three column widths to say nothing. Here they cost
 * nothing when absent and read naturally when present.
 */
function clientSub(a: Account): string {
  return [a.sector?.name, a.type?.name, a.account_manager?.name].filter(Boolean).join(" · ");
}

/** Abbreviated for the strip; the table shows the full figure. */
function short(v: number | null | undefined): string {
  if (v === null || v === undefined) return "—";
  const abs = Math.abs(v);
  if (abs >= 1_000_000_000) return `KES ${(v / 1_000_000_000).toFixed(2)}B`;
  if (abs >= 1_000_000)     return `KES ${(v / 1_000_000).toFixed(2)}M`;
  if (abs >= 1_000)         return `KES ${(v / 1_000).toFixed(1)}K`;
  return `KES ${v.toFixed(0)}`;
}

/**
 * Open the customer.
 *
 * The whole row, because the only way in used to be a "View" button in the
 * last column — which on a table this wide sits past the right edge of most
 * screens, so the page looked like a list of things you could not open.
 *
 * The guard matters: a row-wide handler would otherwise swallow the logo
 * button and anything else interactive added here later. `closest` covers
 * elements this function has never heard of, which a list of `.stop`
 * modifiers does not.
 */
function open(a: { id: number }, event: MouseEvent) {
  const el = event.target as HTMLElement | null;
  if (el?.closest("button, a, input, select, label")) return;

  router.push({ name: "account", params: { id: a.id } });
}
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
const newLogoFile = ref<File | null>(null);
const newLogoPreview = ref<string | null>(null);

const org = () => currentOrgSlug.value;

async function load() {
  loading.value = true;
  try {
    const res = await api<{ data: Account[]; totals: BookTotals }>("GET", "/api/v1/accounts", {
      org: org(),
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        account_type_id: typeFilter.value || undefined,
        per_page: 100,
      },
    });
    rows.value = res.data;
    totals.value = res.totals ?? null;
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

// ── Logos ────────────────────────────────────────────────────────────────
// A small mark beside the client's name. The placeholder doubles as the "add"
// control — clicking any client's badge, logo or not, opens a file picker and
// posts it straight to that account.
const logoInput = ref<HTMLInputElement | null>(null);
const logoTarget = ref<Account | null>(null);

function beginLogo(a: Account) {
  logoTarget.value = a;
  logoInput.value?.click();
}

// Auto-fetch logos for clients that don't have one, from their contact-email
// domain (server-side, via Google's favicon service).
const fetchingLogos = ref(false);
async function autoFetchLogos() {
  if (fetchingLogos.value) return;
  fetchingLogos.value = true;
  try {
    const res = await api<{ data: { fetched: number; no_domain: number; failed: number } }>("POST", `/api/v1/accounts/fetch-logos`, { org: org() });
    const t = res.data;
    await load();
    if (t.fetched) {
      const skipped = t.no_domain + t.failed;
      notifySaved(`Fetched ${t.fetched} logo${t.fetched === 1 ? "" : "s"}${skipped ? ` · ${skipped} had no usable domain` : ""}.`);
    } else {
      notifyFailed("No logos could be fetched", "None of the clients without a logo have a corporate contact email to derive a website from.");
    }
  } catch (err) {
    notifyFailed("Could not auto-fetch logos.", err instanceof ApiError ? (err.firstError ?? err.message) : undefined);
  } finally {
    fetchingLogos.value = false;
  }
}

async function onLogoPicked(e: Event) {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  const a = logoTarget.value;
  input.value = "";           // let the same file be re-picked later
  if (!file || !a) return;

  const fd = new FormData();
  fd.append("logo", file);
  try {
    const res = await api<{ data: Account }>("POST", `/api/v1/accounts/${a.id}/logo`, { org: org(), formData: fd });
    const i = rows.value.findIndex((r) => r.id === a.id);
    if (i !== -1) rows.value[i] = { ...rows.value[i], logo_url: res.data.logo_url };
    notifySaved("Logo updated.");
  } catch (err) {
    notifyFailed("Could not upload the logo.", err instanceof ApiError ? (err.firstError ?? err.message) : undefined);
  }
}

// The create form's own logo — chosen before the account exists, so it is held
// and uploaded once we have an id.
function pickNewLogo(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0] ?? null;
  newLogoFile.value = file;
  newLogoPreview.value = file ? URL.createObjectURL(file) : null;
}

async function create() {
  if (!newAccount.value.name.trim()) return;
  saving.value = true;
  try {
    const res = await api<{ data: Account }>("POST", "/api/v1/accounts", {
      org: org(),
      body: { ...newAccount.value, country: newAccount.value.country || null },
    });
    // If a logo was chosen on the form, attach it now that the id exists.
    if (newLogoFile.value) {
      try {
        const fd = new FormData();
        fd.append("logo", newLogoFile.value);
        await api("POST", `/api/v1/accounts/${res.data.id}/logo`, { org: org(), formData: fd });
      } catch { /* the account is made; a failed logo is not worth blocking on */ }
    }

    showNew.value = false;
    newAccount.value = { name: "", account_type_id: null, country: "" };
    newLogoFile.value = null;
    newLogoPreview.value = null;

    // Raised before navigating: the toast lives on the shell and survives the
    // route change, which an inline banner here would not.
    notifySaved(`${res.data.name} added.`);
    // Straight into the record: a customer is created in order to fill it in.
    router.push({ name: "account", params: { id: res.data.id } });
  } catch (e) {
    notifyFailed("Could not create the customer.", e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
  } finally { saving.value = false; }
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

    <!-- What the book adds up to. Across all of it, not the page in view, so
         the sentence does not change when you turn to page two. -->
    <div v-if="totals" class="ac-kpis">
      <div class="ac-kpi">
        <span class="ac-kpi-label">Customers</span>
        <span class="ac-kpi-value">{{ totals.customers }}</span>
        <span class="ac-kpi-sub">{{ totals.active }} active</span>
      </div>
      <div class="ac-kpi">
        <span class="ac-kpi-label">Open pipeline</span>
        <span class="ac-kpi-value">{{ short(totals.open_pipeline) }}</span>
        <span class="ac-kpi-sub">{{ totals.open_pipeline === null ? "Not shown to you" : "across the book" }}</span>
      </div>
      <div class="ac-kpi">
        <span class="ac-kpi-label">Never contacted</span>
        <span :class="['ac-kpi-value', { warn: totals.never_contacted > 0 }]">{{ totals.never_contacted }}</span>
        <span class="ac-kpi-sub">no activity logged</span>
      </div>
      <div class="ac-kpi">
        <span class="ac-kpi-label">No owner</span>
        <span :class="['ac-kpi-value', { warn: totals.unowned > 0 }]">{{ totals.unowned }}</span>
        <span class="ac-kpi-sub">nobody accountable</span>
      </div>
    </div>

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

      <button class="btn-ghost" :disabled="fetchingLogos" title="Fetch logos for clients that don't have one, from their contact-email domain" @click="autoFetchLogos">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10 3a7 7 0 1 0 6.3 4M17 3v4h-4" />
        </svg>
        {{ fetchingLogos ? "Fetching…" : "Auto-fetch logos" }}
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
            <th>Client</th>
            <th class="ac-num">Open pipeline</th>
            <th class="ac-num">Deals</th>
            <th class="ac-num">Projects</th>
            <th class="ac-num">Open tickets</th>
            <th>Status</th>
            <th class="ac-col-sm">Last contact</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="rows.length === 0">
            <td colspan="7" class="ac-none">Nothing matches that search.</td>
          </tr>
          <tr v-for="a in rows" :key="a.id" class="ac-clickable" @click="open(a, $event)">
            <td class="ac-name">
              <span class="ac-client">
                <!-- The badge is the logo when there is one, the initials when
                     not — and either way it is the button that adds or changes
                     it. -->
                <button type="button" class="ac-logo" :class="{ empty: !a.logo_url }"
                        :title="a.logo_url ? 'Change logo' : 'Add a logo'" @click.stop="beginLogo(a)">
                  <img v-if="a.logo_url" :src="a.logo_url" alt="" />
                  <span v-else class="ac-logo-initials">{{ a.initials }}</span>
                  <span class="ac-logo-edit" aria-hidden="true">+</span>
                </button>
                <span class="ac-client-id">
                  <button type="button" class="ac-client-name"
                          @click.stop="router.push({ name: 'account', params: { id: a.id } })">
                    {{ a.name }}
                  </button>
                  <!-- Sector, type and owner move here out of three columns
                       that were empty on nearly every row. -->
                  <span v-if="clientSub(a)" class="ac-client-sub">{{ clientSub(a) }}</span>
                </span>
              </span>
            </td>
            <!-- Zero is a fact worth showing; a dash is not. But a customer
                 with nothing at all should not shout four zeroes, so those
                 read quietly. -->
            <td class="ac-num">
              <span :class="{ 'ac-nil': !a.open_pipeline }">{{ a.open_pipeline === null ? "—" : money(a.open_pipeline ?? 0) }}</span>
            </td>
            <td class="ac-num"><span :class="{ 'ac-nil': !a.open_deals }">{{ a.open_deals ?? 0 }}</span></td>
            <td class="ac-num"><span :class="{ 'ac-nil': !a.projects }">{{ a.projects ?? 0 }}</span></td>
            <td class="ac-num">
              <span :class="{ 'ac-nil': !a.open_tickets, 'ac-hot': (a.open_tickets ?? 0) > 0 }">{{ a.open_tickets ?? 0 }}</span>
            </td>
            <td><span :class="['pill', `pill-${statusTone(a.status)}`]">{{ a.status }}</span></td>
            <td class="ac-col-sm ac-date">{{ lastContact(a.last_contact_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- One hidden picker, shared by every row's badge. -->
    <input ref="logoInput" type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp"
           class="ac-hidden-file" @change="onLogoPicked" />

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

        <div class="ac-field"><span>Logo</span>
          <label class="ac-logo-drop" :class="{ has: newLogoPreview }">
            <img v-if="newLogoPreview" :src="newLogoPreview" alt="" class="ac-logo-drop-img" />
            <span v-else class="ac-logo-drop-hint">+ Add a logo <em>(optional)</em></span>
            <input type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp"
                   class="ac-hidden-file" @change="pickNewLogo" />
          </label>
        </div>

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

.ac-wrap { padding: var(--space-6); width: 100%; max-width: 100%; }
@media (max-width: 40rem) { .ac-wrap { padding: var(--space-4); } }

/* Dynamic to the screen: secondary columns drop as it narrows, so the client,
   its logo and its status always fit without a sideways scroll. */
@media (max-width: 64rem) { .ac-col-md { display: none; } }
@media (max-width: 44rem) { .ac-col-sm { display: none; } }

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

/* Hover is the only thing telling you the row is a way in, so it has to be
   visible rather than a two-percent tint. */
.ac-clickable { @apply cursor-pointer; transition: background-color 120ms ease; }
.ac-clickable:hover { background: var(--color-neutral-100); }

/* ── Client logo badge (list + create) ─────────────────────────────────── */
.ac-client { @apply inline-flex items-center; gap: var(--space-3); min-width: 0; }
.ac-client-name {
  @apply truncate cursor-pointer border-none bg-transparent p-0 text-left;
  font-family: inherit; font-size: inherit; font-weight: inherit;
  color: var(--color-brand-on-surface);
}
.ac-client-name:hover { text-decoration: underline; }
.ac-logo {
  @apply relative inline-flex items-center justify-center flex-none cursor-pointer overflow-hidden;
  width: 2rem; height: 2rem; border-radius: var(--radius-md);
  border: 1px solid var(--color-border); background: var(--color-surface); padding: 0;
}
.ac-logo img { @apply w-full h-full; object-fit: contain; }
.ac-logo-initials { font-size: 0.6rem; font-weight: 700; color: var(--color-brand-on-surface); letter-spacing: .02em; }
.ac-logo.empty { background: var(--color-brand-subtle); border-style: dashed; }
/* The "+" hint sits over the badge and only shows on hover, so a set logo is
   clean but every badge still says "you can change me". */
.ac-logo-edit {
  @apply absolute inset-0 flex items-center justify-center opacity-0;
  background: rgb(0 0 0 / 0.45); color: #fff; font-size: 0.9rem; transition: opacity 120ms ease;
}
.ac-logo:hover .ac-logo-edit, .ac-logo:focus-visible .ac-logo-edit { opacity: 1; }

.ac-hidden-file { display: none; }

.ac-logo-drop {
  @apply flex items-center justify-center cursor-pointer overflow-hidden;
  height: 4.5rem; border: 1px dashed var(--color-border-strong); border-radius: var(--radius-md);
  background: var(--color-surface-sunken); color: var(--color-text-muted); font-size: var(--text-sm-size);
}
.ac-logo-drop.has { border-style: solid; background: var(--color-surface); }
.ac-logo-drop-img { @apply h-full; max-width: 100%; object-fit: contain; }
.ac-logo-drop-hint em { font-style: normal; color: var(--color-text-light); font-size: var(--text-xs-size); }
.ac-date { white-space: nowrap; font-variant-numeric: tabular-nums; }
.ac-none { text-align: center; color: var(--color-text-light); padding: var(--space-6); }

/* Relationship, as a coloured marker rather than another pill — Status is the
   pill, and two pills per row stop either from standing out. */

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

/* ── Book totals ──────────────────────────────────────────────────────────
   Hairline-separated cells over the border colour, matching the customer
   record's own KPI strip so the two pages read as one place. */
.ac-kpis {
  @apply grid; grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1px; margin-bottom: var(--space-4);
  background: var(--color-border);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md); overflow: hidden;
}
@media (max-width: 60rem) { .ac-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 32rem) { .ac-kpis { grid-template-columns: 1fr; } }

.ac-kpi { @apply flex flex-col; gap: 2px; padding: var(--space-3) var(--space-4);
  background: var(--color-surface-raised); }
.ac-kpi-label {
  font-size: var(--text-xs-size); font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.07em; color: var(--color-text-light);
}
.ac-kpi-value { font-size: var(--text-xl-size); font-weight: 600; letter-spacing: -0.5px; color: var(--color-text); }
.ac-kpi-value.warn { color: var(--color-warning-text); }
.ac-kpi-sub { font-size: var(--text-xs-size); color: var(--color-text-muted); }

/* ── Rows ─────────────────────────────────────────────────────────────────
   Numbers are right-aligned and tabular so a column can be scanned rather
   than read line by line. */
.ac-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.ac-table th.ac-num { text-align: right; }

/* A customer with nothing should not shout four zeroes at the same weight as
   a customer with a pipeline. */
.ac-nil { color: var(--color-text-light); }
.ac-hot { color: var(--color-warning-text); font-weight: 600; }

.ac-client-id  { @apply flex flex-col; gap: 1px; min-width: 0; }
.ac-client-sub { font-size: var(--text-xs-size); color: var(--color-text-muted); }
</style>
