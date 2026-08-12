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
/**
 * What kind of thing is being registered, and the fields that follow from it.
 *
 * The form used to ask a laptop's questions of everything: brand, model, serial
 * number. An SSL certificate has none of those and one that hardware does not —
 * a date after which a public site stops working. Asking for a serial number
 * teaches people the register is not for their licence.
 */
const CLASSES = [
  { key: "physical",     label: "Physical",     hint: "Laptops, monitors, phones, furniture — something you can hand to somebody" },
  { key: "software",     label: "Software",     hint: "Licences bought by the seat — Microsoft 365, Adobe, CAD tools" },
  { key: "subscription", label: "Subscription", hint: "Anything that expires and must be renewed — SSL certificates, domains, SaaS" },
] as const;

const CATEGORIES_BY_CLASS: Record<string, string[]> = {
  physical:     ["laptop", "desktop", "monitor", "phone", "tablet", "peripheral", "furniture", "other"],
  software:     ["licence", "design tool", "developer tool", "security", "other"],
  subscription: ["ssl certificate", "domain", "saas", "cloud service", "support contract", "other"],
};

const blankForm = () => ({
  asset_class: "physical",
  name: "", asset_tag: "", category: "laptop", vendor: "",
  /*
   * What the licence lets somebody do — kept apart from operational notes.
   * This is what the person deciding whether to buy another seat reads.
   */
  description: "",
  // Physical only.
  brand: "", model: "", serial_number: "",
  // A batch runs from a date to a date.
  starts_on: "", expires_on: "",
  seats_total: "" as string | number,
  renewal_cost: "" as string | number, auto_renews: false,
  renewal_notice_days: "" as string | number,
  vendor_contact: "",
});

const form = ref(blankForm());

/** Changing the kind changes what a category means, so the old one cannot stand. */
function onClassChange() {
  form.value.category = CATEGORIES_BY_CLASS[form.value.asset_class][0];
}

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
    /*
     * Only the fields that apply. Sending an empty serial number on a
     * certificate would write a blank where the register should hold nothing —
     * and "" and null read differently in every report downstream.
     */
    const f = form.value;
    const body: Record<string, unknown> = {
      asset_class: f.asset_class,
      name: f.name,
      asset_tag: f.asset_tag,
      category: f.category,
      vendor: f.vendor || undefined,
    };

    if (f.asset_class === "physical") {
      Object.assign(body, {
        brand: f.brand || undefined,
        model: f.model || undefined,
        serial_number: f.serial_number || undefined,
      });
    } else {
      Object.assign(body, {
        description: f.description || undefined,
        starts_on: f.starts_on || undefined,
        expires_on: f.expires_on || undefined,
        // How many the batch was bought with. Seats in use are counted from
        // who holds them, so there is nothing to keep in step.
        seats_total: f.seats_total === "" ? undefined : Number(f.seats_total),
        renewal_cost: f.renewal_cost === "" ? undefined : Number(f.renewal_cost),
        renewal_notice_days: f.renewal_notice_days === "" ? undefined : Number(f.renewal_notice_days),
        auto_renews: f.auto_renews,
        vendor_contact: f.vendor_contact || undefined,
      });
    }

    const res = await api<{ data: Asset }>("POST", "/api/v1/assets", { org: org(), body });
    showCreate.value = false;
    void router.push({ name: "asset-detail", params: { id: res.data.id } });
  } catch (e) {
    createError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not create the asset.";
  } finally {
    saving.value = false;
  }
}

function openCreate() {
  form.value = blankForm();
  createError.value = "";
  showCreate.value = true;
}

const needsAttention = computed(
  () => assets.value.filter(a => a.warranty_has_expired || a.maintenance_is_due).length
);

// ── The tabs ────────────────────────────────────────────────────────────────

/**
 * Seven views over one register.
 *
 * Tabs rather than seven routes: they are the same records asked different
 * questions, and a person moving between "what do we own" and "who has what"
 * is not navigating anywhere. Each loads on first open and not before —
 * opening Assets should not fetch six lists nobody looked at.
 */
type Tab = "overview" | "mine" | "requests" | "employee" | "inventory" | "maintenance" | "disposals";

const TABS: { key: Tab; label: string }[] = [
  { key: "overview",    label: "Overview" },
  { key: "mine",        label: "My Assets" },
  { key: "requests",    label: "Assets Requests" },
  { key: "employee",    label: "Employee Assets" },
  { key: "inventory",   label: "Assets Inventory" },
  { key: "maintenance", label: "Maintenance" },
  { key: "disposals",   label: "Disposals" },
];

const tab = ref<Tab>("overview");
const loaded = ref<Set<Tab>>(new Set());

interface Summary {
  totals: { assigned: number; available: number; pending_requests: number; under_repair: number };
  details: { licences_expiring: number; due_for_return: number; maintenance_due: number; purchase_value: number };
  by_category: { label: string; count: number }[];
  by_department: { label: string; count: number }[];
  by_status: { label: string; count: number }[];
}

const summary   = ref<Summary | null>(null);
const requests  = ref<any[]>([]);
const disposals = ref<any[]>([]);
const mine      = ref<Asset[]>([]);
const servicing = ref<any[]>([]);
const held      = ref<Asset[]>([]);

async function openTab(next: Tab) {
  tab.value = next;
  if (loaded.value.has(next)) return;

  try {
    if (next === "overview") {
      summary.value = await api<Summary>("GET", "/api/v1/assets/summary", { org: org() });
    } else if (next === "requests") {
      requests.value = (await api<{ data: any }>("GET", "/api/v1/assets/requests", { org: org() })).data?.data ?? [];
    } else if (next === "maintenance") {
      servicing.value = (await api<{ data: any }>("GET", "/api/v1/assets/maintenance", { org: org() })).data?.data ?? [];
    } else if (next === "disposals") {
      disposals.value = (await api<{ data: any }>("GET", "/api/v1/assets/disposals", { org: org() })).data?.data ?? [];
    } else if (next === "mine") {
      // The register filtered to the person asking, rather than its own
      // endpoint — one query with one set of rules behind it.
      mine.value = (await api<{ data: Asset[] }>("GET", "/api/v1/assets", {
        org: org(), params: { mine: 1, per_page: 100 },
      })).data ?? [];
    } else if (next === "employee") {
      held.value = (await api<{ data: Asset[] }>("GET", "/api/v1/assets", {
        org: org(), params: { assigned: "true", per_page: 100 },
      })).data ?? [];
    }
    loaded.value.add(next);
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not load that tab.";
  }
}

/** KES, grouped, no decimals — an asset register is not a ledger. */
const money = (n: number) =>
  "KES " + new Intl.NumberFormat("en-KE", { maximumFractionDigits: 0 }).format(n ?? 0);

/** Widest slice in a set, so bars are comparable inside their own chart. */
const peak = (rows: { count: number }[]) => Math.max(1, ...rows.map(r => r.count));

watch(currentOrgSlug, () => {
  page.value = 1;
  loaded.value = new Set();
  void load();
  void openTab(tab.value);
});

onMounted(() => { void load(); void openTab("overview"); });
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

    <!-- ── Tabs ─────────────────────────────────────────────────────────── -->
    <nav class="tabs" role="tablist">
      <button v-for="t in TABS" :key="t.key" role="tab"
              :class="['tab', { on: tab === t.key }]"
              :aria-selected="tab === t.key"
              @click="openTab(t.key)">{{ t.label }}</button>
    </nav>

    <!-- ── Overview ─────────────────────────────────────────────────────── -->
    <section v-if="tab === 'overview'" class="pane">
      <p v-if="!summary" class="quiet">Loading…</p>

      <template v-else>
        <div class="cards">
          <article class="card">
            <h2 class="card-title">Total Assets</h2>
            <div class="stats">
              <div class="stat"><span class="stat-n">{{ summary.totals.assigned }}</span><span class="stat-l">Assigned</span></div>
              <div class="stat"><span class="stat-n">{{ summary.totals.available }}</span><span class="stat-l">Available</span></div>
              <div class="stat"><span class="stat-n">{{ summary.totals.pending_requests }}</span><span class="stat-l">Pending requests</span></div>
              <div class="stat"><span class="stat-n">{{ summary.totals.under_repair }}</span><span class="stat-l">Under repair</span></div>
            </div>
          </article>

          <article class="card">
            <h2 class="card-title">Asset Details</h2>
            <div class="stats">
              <!-- Answerable only since subscriptions became a class of their
                   own; a hardware-shaped register could not produce it. -->
              <div class="stat"><span class="stat-n">{{ summary.details.licences_expiring }}</span><span class="stat-l">Licences expiring</span></div>
              <div class="stat"><span class="stat-n">{{ summary.details.due_for_return }}</span><span class="stat-l">Due for return</span></div>
              <div class="stat"><span class="stat-n">{{ summary.details.maintenance_due }}</span><span class="stat-l">Maintenance due</span></div>
              <div class="stat">
                <span class="stat-n">{{ money(summary.details.purchase_value) }}</span>
                <!-- Said plainly: there is no depreciation in this system, and
                     a purchase total presented as current worth is a number
                     finance quotes back at you a year later. -->
                <span class="stat-l">Purchase value</span>
              </div>
            </div>
          </article>
        </div>

        <div class="charts">
          <article class="card">
            <h2 class="card-title">Assets by Category</h2>
            <p v-if="!summary.by_category.length" class="quiet">Nothing on the register yet.</p>
            <ul v-else class="bars">
              <li v-for="r in summary.by_category" :key="r.label" class="bar-row">
                <span class="bar-label">{{ r.label }}</span>
                <span class="bar-track"><span class="bar-fill" :style="{ width: (100 * r.count / peak(summary.by_category)) + '%' }" /></span>
                <span class="bar-n">{{ r.count }}</span>
              </li>
            </ul>
          </article>

          <article class="card">
            <h2 class="card-title">Allocation by Department</h2>
            <p v-if="!summary.by_department.length" class="quiet">Nothing allocated yet.</p>
            <ul v-else class="bars">
              <li v-for="r in summary.by_department" :key="r.label" class="bar-row">
                <span class="bar-label">{{ r.label }}</span>
                <span class="bar-track"><span class="bar-fill" :style="{ width: (100 * r.count / peak(summary.by_department)) + '%' }" /></span>
                <span class="bar-n">{{ r.count }}</span>
              </li>
            </ul>
          </article>
        </div>
      </template>
    </section>

    <!-- ── My assets / Employee assets ──────────────────────────────────── -->
    <section v-else-if="tab === 'mine' || tab === 'employee'" class="pane">
      <p v-if="!(tab === 'mine' ? mine : held).length" class="quiet">
        {{ tab === 'mine' ? 'Nothing is assigned to you.' : 'Nothing is assigned to anybody yet.' }}
      </p>
      <table v-else class="grid">
        <thead>
          <tr>
            <th>Asset</th><th>Serial</th><th v-if="tab === 'employee'">Holder</th>
            <th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in (tab === 'mine' ? mine : held)" :key="a.id">
            <td>{{ a.name }}</td>
            <td class="mono">{{ a.serial_number ?? '—' }}</td>
            <td v-if="tab === 'employee'">{{ (a as any).holder?.full_name ?? '—' }}</td>
            <td><span class="pill">{{ a.status }}</span></td>
            <td><router-link :to="{ name: 'asset-detail', params: { id: a.id } }" class="link">View</router-link></td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- ── Requests ─────────────────────────────────────────────────────── -->
    <section v-else-if="tab === 'requests'" class="pane">
      <p v-if="!requests.length" class="quiet">No requests have been raised.</p>
      <table v-else class="grid">
        <thead>
          <tr><th>Request</th><th>Item</th><th>Employee</th><th>Urgency</th><th>Submitted</th><th>Status</th></tr>
        </thead>
        <tbody>
          <tr v-for="r in requests" :key="r.id">
            <td class="mono">{{ r.reference }}</td>
            <td>{{ r.item }}</td>
            <td>{{ r.employee ?? '—' }}</td>
            <td>{{ r.urgency ?? '—' }}</td>
            <td>{{ r.submitted_on }}</td>
            <td><span class="pill">{{ r.status }}</span></td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- ── Disposals ────────────────────────────────────────────────────── -->
    <section v-else-if="tab === 'disposals'" class="pane">
      <p v-if="!disposals.length" class="quiet">Nothing has been disposed of.</p>
      <table v-else class="grid">
        <thead>
          <tr><th>Asset</th><th>Serial</th><th>Category</th><th>Purchased</th><th>Disposed</th><th>Method</th><th>Certificate</th></tr>
        </thead>
        <tbody>
          <tr v-for="d in disposals" :key="d.id">
            <td>{{ d.asset }}</td>
            <td class="mono">{{ d.serial_number ?? '—' }}</td>
            <td>{{ d.category }}</td>
            <td>{{ d.purchase_date ?? '—' }}</td>
            <td>{{ d.disposed_on }}</td>
            <td>{{ d.method }}</td>
            <td>
              <!-- E-waste with no certificate on file is the row an auditor
                   asks about, so it is flagged rather than left blank. -->
              <span v-if="d.requires_safe_disposal && !d.certificate_received_on" class="pill pill-bad">
                needs certificate
              </span>
              <span v-else-if="d.certificate_received_on">{{ d.certificate_received_on }}</span>
              <span v-else class="quiet">n/a</span>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- ── Maintenance ──────────────────────────────────────────────────── -->
    <section v-else-if="tab === 'maintenance'" class="pane">
      <p v-if="!servicing.length" class="quiet">No servicing has been recorded.</p>
      <table v-else class="grid">
        <thead>
          <tr><th>ID</th><th>Asset</th><th>Type</th><th>Reason</th><th>Date</th><th>Cost</th><th>Status</th></tr>
        </thead>
        <tbody>
          <tr v-for="m in servicing" :key="m.id">
            <td class="mono">{{ m.reference }}</td>
            <td>{{ m.asset }}</td>
            <td>{{ m.type }}</td>
            <td>{{ m.reason }}</td>
            <td>{{ m.performed_on ?? m.scheduled_for ?? '—' }}</td>
            <td>{{ m.cost ? money(Number(m.cost)) : '—' }}</td>
            <td>
              <!-- The two waiting states read alike on a board and need
                   different people to clear them, so they are named apart. -->
              <span :class="['pill', m.status === 'completed' ? 'pill-ok' : m.status.startsWith('awaiting') ? 'pill-wait' : '']">
                {{ m.status.replace(/_/g, ' ') }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- ── Inventory: the register itself ───────────────────────────────── -->
    <template v-else>
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

    </template>

    <!-- Create -->
    <div v-if="showCreate" class="overlay" @click.self="showCreate = false">
      <div class="dialog" role="dialog" aria-modal="true" aria-label="Add asset">
        <h2>Add asset</h2>

        <!--
          Asked first, because it decides which of the rest are even sensible
          questions. Three cards rather than a dropdown: the difference between
          them is not obvious from a word, and the hint is doing the work.
        -->
        <div class="dialog-body">
        <div class="form-grid">
        <fieldset class="kinds">
          <legend class="field-legend">What are you registering?</legend>
          <label v-for="c in CLASSES" :key="c.key" :class="['kind', { on: form.asset_class === c.key }]">
            <input v-model="form.asset_class" type="radio" :value="c.key" @change="onClassChange" />
            <span class="kind-label">{{ c.label }}</span>
            <span class="kind-hint">{{ c.hint }}</span>
          </label>
        </fieldset>

        <label class="field field-wide"><span>Name</span>
          <input v-model="form.name" class="control"
                 :placeholder="form.asset_class === 'physical' ? 'ThinkPad X1 Carbon'
                   : form.asset_class === 'software' ? 'Adobe Creative Cloud'
                   : 'wildcard SSL — konvergenz.co.ke'" />
        </label>
        <label class="field"><span>Asset tag</span>
          <input v-model="form.asset_tag" class="control" placeholder="AST-0001" />
        </label>
        <label class="field"><span>Category</span>
          <select v-model="form.category" class="control">
            <option v-for="c in CATEGORIES_BY_CLASS[form.asset_class]" :key="c" :value="c">{{ c }}</option>
          </select>
        </label>

        <!-- Kept out of the way: it means the same thing for every kind. -->
        <label class="field"><span>Vendor</span>
          <input v-model="form.vendor" class="control" placeholder="Who it was bought from" />
        </label>

        <!-- ── Physical only ──────────────────────────────────────────────── -->
        <template v-if="form.asset_class === 'physical'">
          <div class="row">
            <label class="field"><span>Brand</span><input v-model="form.brand" class="control" /></label>
            <label class="field"><span>Model</span><input v-model="form.model" class="control" /></label>
          </div>
          <label class="field"><span>Serial number</span>
            <input v-model="form.serial_number" class="control" />
          </label>
        </template>

        <!-- ── Licences and subscriptions ─────────────────────────────────── -->
        <template v-else>
          <!--
            A batch runs from a date to a date. The end alone tells you when
            somebody gets locked out; both together tell you what the term was
            when the invoice is queried.
          -->
          <div class="row">
            <label class="field"><span>Subscription starts</span>
              <input v-model="form.starts_on" class="control" type="date" />
            </label>
            <label class="field"><span>Proposed end</span>
              <input v-model="form.expires_on" class="control" type="date" />
              <span class="hint">
                A certificate nobody renewed is a public site down; a licence
                nobody renewed is a team locked out on a Monday.
              </span>
            </label>
          </div>

          <label class="field"><span>Seats in this batch</span>
            <input v-model="form.seats_total" class="control" type="number" min="1" placeholder="25" />
            <span class="hint">
              How many the batch was bought with. Seats in use are counted from
              who holds them — you allocate them to people from the licence
              itself, and each allocation comes off the batch.
            </span>
          </label>

          <div class="row">
            <label class="field"><span>Renewal cost</span>
              <input v-model="form.renewal_cost" class="control" type="number" min="0" />
            </label>
            <label class="field"><span>Warn me this many days ahead</span>
              <input v-model="form.renewal_notice_days" class="control" type="number" min="1" max="365"
                     placeholder="30" />
              <span class="hint">A domain wants a fortnight; an enterprise agreement wants a quarter.</span>
            </label>
          </div>

          <label class="check">
            <input v-model="form.auto_renews" type="checkbox" />
            <span>Renews automatically</span>
          </label>

          <label class="field"><span>Vendor contact</span>
            <input v-model="form.vendor_contact" class="control" placeholder="Who to chase when it is due" />
          </label>

          <!--
            What it actually lets somebody do. Separate from notes, which are
            operational — where the key is kept, who to ring. This is the
            capability, and it is what somebody deciding whether to buy another
            seat needs to read.
          -->
          <label class="field field-wide"><span>What this licence covers</span>
            <textarea v-model="form.description" class="control" rows="4"
                      placeholder="Full Creative Cloud — all apps, 100GB storage, commercial use. Named-user licensing, two machines per seat."></textarea>
          </label>
        </template>

        </div>
        </div>

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

/*
 * Wide enough for two columns, and never taller than the window.
 *
 * The form grew from six fields to nearer twenty once a licence stopped
 * pretending to be a laptop, and a 30rem column turned that into a scroll on
 * every screen. It now takes the width it can get and scrolls its body rather
 * than the page — the actions stay put at the bottom, which is where somebody
 * looks for them after filling in twenty fields.
 */
.dialog {
  @apply w-full flex flex-col;
  max-width: 52rem;
  max-height: min(90vh, 56rem);
  background: var(--color-surface);
  border-radius: var(--radius-2xl);
  padding: var(--space-5);
  box-shadow: var(--shadow-lg);
}

/* The scrolling part. Padding on the right so a scrollbar does not sit on top
   of the inputs. */
.dialog-body { @apply flex-1 overflow-y-auto; padding-right: var(--space-2); margin-right: calc(-1 * var(--space-2)); }

/*
 * Two columns where there is room, one where there is not.
 *
 * Fields that carry a sentence of explanation — the ones with hints — span
 * both, because a hint wrapped into four lines beside a date picker is worse
 * than no hint.
 */
.form-grid { @apply grid; grid-template-columns: 1fr 1fr; gap: 0 var(--space-4); }
@media (max-width: 46rem) { .form-grid { grid-template-columns: 1fr; } }
.field-wide { grid-column: 1 / -1; }
.form-grid .row { grid-column: 1 / -1; }
.form-grid .kinds { grid-column: 1 / -1; }

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

/* ── Tabs ──────────────────────────────────────────────────────────────── */
.tabs { display: flex; gap: 4px; border-bottom: 2px solid var(--color-border); margin: 20px 0 0; overflow-x: auto; }
.tab {
  background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px;
  padding: 10px 18px; font: inherit; font-size: 0.88rem; font-weight: 500;
  color: var(--color-text-muted); cursor: pointer; white-space: nowrap;
}
.tab:hover { color: var(--color-text); }
.tab.on { color: var(--color-brand-base); border-bottom-color: var(--color-brand-base); font-weight: 600; }

.pane { margin-top: 20px; }
.quiet { color: var(--color-text-muted); font-size: 0.88rem; }

/* ── Overview ──────────────────────────────────────────────────────────── */
.cards, .charts { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.charts { margin-top: 16px; }
@media (max-width: 60rem) { .cards, .charts { grid-template-columns: 1fr; } }

.card { border: 1px solid var(--color-border); border-radius: 12px; padding: 18px; background: var(--color-surface-raised); }
.card-title { font-size: 0.95rem; font-weight: 600; margin: 0 0 14px; color: var(--color-text); }

.stats { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.stat { display: flex; flex-direction: column; }
.stat-n { font-size: 1.5rem; font-weight: 600; color: var(--color-text); line-height: 1.1; }
.stat-l { font-size: 0.75rem; color: var(--color-text-muted); }

/* Bars rather than donuts: a donut of two categories is a pie chart, and a
   pie chart of eight is unreadable. Length compares; angle does not. */
.bars { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.bar-row { display: grid; grid-template-columns: 8rem 1fr 2.5rem; align-items: center; gap: 10px; }
.bar-label { font-size: 0.8rem; color: var(--color-text-muted); overflow-wrap: anywhere; }
.bar-track { height: 8px; border-radius: 999px; background: var(--color-surface-sunken); overflow: hidden; }
.bar-fill  { display: block; height: 100%; background: var(--color-brand-base); }
.bar-n { font-size: 0.8rem; color: var(--color-text); text-align: right; }

/* ── Tab tables ────────────────────────────────────────────────────────── */
.grid { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
.grid th { text-align: left; padding: 10px 12px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase;
           letter-spacing: .04em; color: var(--color-text-muted); background: var(--color-surface-sunken); white-space: nowrap; }
.grid td { padding: 12px; border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.grid tr:last-child td { border-bottom: none; }
.mono { font-family: ui-monospace, monospace; font-size: 0.82rem; }
.pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 0.75rem;
        background: var(--color-surface-sunken); color: var(--color-text-muted); }
.pill-bad { background: var(--color-danger-subtle); color: var(--color-danger-text); }
.pill-ok   { background: var(--color-success-subtle); color: var(--color-success-text); }
.pill-wait { background: var(--color-warning-subtle, var(--color-surface-sunken)); color: var(--color-text); }

/* ── What kind of thing is this ────────────────────────────────────────── */
.kinds { border: none; margin: 0 0 14px; padding: 0; display: grid;
         grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 8px; }
.field-legend { font-size: .78rem; color: var(--color-text-muted); padding: 0; margin-bottom: 8px; }
.kind {
  display: grid; grid-template-columns: 18px 1fr; gap: 4px 10px; align-items: start;
  padding: 10px 12px; border: 1px solid var(--color-border); border-radius: 8px; cursor: pointer;
}
.kind.on { border-color: var(--color-brand-base); background: var(--color-brand-subtle); }
.kind input { grid-row: 1 / span 2; margin-top: 3px; }
.kind-label { font-size: .88rem; font-weight: 600; color: var(--color-text); }
.kind-hint  { font-size: .75rem; color: var(--color-text-muted); }
.hint { font-size: .72rem; color: var(--color-text-light); }
.check { display: flex; align-items: center; gap: 8px; font-size: .85rem; margin-bottom: 12px; }
.link { color: var(--color-brand-on-surface); }
</style>