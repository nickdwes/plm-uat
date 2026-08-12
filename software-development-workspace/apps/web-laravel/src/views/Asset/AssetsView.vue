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
import EmployeePicker from "../../components/EmployeePicker.vue";
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

/**
 * Categories come from the organisation, not from this file.
 *
 * They were a hardcoded array here, which made adding "UPS" a developer task
 * with a build and a deploy behind it. They are configured per tenant now — a
 * training company and a healthcare integrator do not own the same things.
 */
interface AssetCategory {
  id: number; asset_class: string; name: string;
  description: string | null; is_active: boolean;
}

const categories = ref<AssetCategory[]>([]);

const categoriesFor = computed(() =>
  categories.value.filter(c => c.asset_class === form.value.asset_class));

/** Whichever category was chosen, if it is still on the list. */
const chosenCategory = computed(() =>
  categoriesFor.value.find(c => c.name === form.value.category) ?? null);

/**
 * "Other" is the one that asks a follow-up.
 *
 * A register full of things filed under "other" is a register that cannot be
 * reported on, so choosing it has to cost one sentence saying what the thing
 * actually is.
 */
const needsOwnName = computed(
  () => (form.value.category ?? "").toLowerCase() === "other");

/**
 * The extra questions the chosen category asks.
 *
 * Fetched when the category changes rather than shipped with the whole list —
 * most categories have none, and loading every definition to render one form is
 * work nobody asked for.
 */
const categoryFields = ref<any[]>([]);

async function loadCategoryFields() {
  categoryFields.value = [];
  const c = chosenCategory.value;
  if (!c) return;
  try {
    const res = await api<{ data: any[] }>("GET", `/api/v1/org/asset-categories/${c.id}/fields`, { org: org() });
    categoryFields.value = res.data ?? [];
    // Start each answer empty so v-model has something to bind to.
    for (const f of categoryFields.value) {
      if (!(f.key in form.value.custom_fields)) {
        form.value.custom_fields[f.key] = f.type === "boolean" ? false : "";
      }
    }
  } catch { categoryFields.value = []; }
}

async function loadCategories() {
  try {
    const res = await api<{ data: AssetCategory[] }>("GET", "/api/v1/org/asset-categories", {
      org: org(), params: { usable: 1 },
    });
    categories.value = res.data ?? [];
  } catch { categories.value = []; }
}

const blankForm = () => ({
  asset_class: "physical",
  name: "", asset_tag: "", category: "laptop", vendor: "",
  /*
   * What the licence lets somebody do — kept apart from operational notes.
   * This is what the person deciding whether to buy another seat reads.
   */
  description: "",
  /*
   * What the thing is, when the category cannot say. Only asked for "other",
   * and only sent then.
   */
  other_name: "", other_description: "",
  /** Answers to whatever the chosen category asks. */
  custom_fields: {} as Record<string, any>,
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
  // The old category cannot stand — it meant something in the class we left.
  form.value.category = categoriesFor.value[0]?.name ?? "";
  form.value.other_name = "";
  form.value.other_description = "";
  onCategoryChange();
}

/** A different category asks different questions, so the old answers go. */
function onCategoryChange() {
  form.value.custom_fields = {};
  void loadCategoryFields();
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
      /*
       * "Other" is not a category anybody can report on, so what the person
       * typed becomes the category and their sentence becomes the description.
       */
      category: needsOwnName.value && f.other_name.trim() ? f.other_name.trim() : f.category,
      vendor: f.vendor || undefined,
    };

    if (Object.keys(f.custom_fields).length) {
      body.custom_fields = f.custom_fields;
    }

    if (needsOwnName.value && f.other_description.trim()) {
      body.description = f.other_description.trim();
    }

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
  void loadCategoryFields();
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
type Tab = "overview" | "mine" | "requests" | "employee" | "inventory" | "maintenance" | "renewals" | "disposals";

const TABS: { key: Tab; label: string }[] = [
  { key: "overview",    label: "Overview" },
  { key: "mine",        label: "My Assets" },
  { key: "requests",    label: "Assets Requests" },
  { key: "employee",    label: "Employee Assets" },
  { key: "inventory",   label: "Assets Inventory" },
  { key: "maintenance", label: "Maintenance" },
  { key: "renewals",    label: "Renewals" },
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
const renewals  = ref<any[]>([]);

// ── Acting on a request ─────────────────────────────────────────────────────

/**
 * One request, open for a decision.
 *
 * The stages are the point: HR asks whether this person should have one,
 * finance whether we can afford it, procurement who we buy it from. The panel
 * shows only the action that belongs to the stage it is at.
 */
const openRequest = ref<any | null>(null);
const requestBusy = ref(false);
const declineReason = ref("");
const showDecline = ref(false);

const STAGE_LABEL: Record<string, string> = {
  pending: "Pending", hr_review: "HR review", awaiting_finance: "Awaiting finance",
  procurement: "With procurement", approved: "Approved",
  rejected: "Declined", fulfilled: "Fulfilled",
};

/** What advancing would do next, in words. */
const NEXT_LABEL: Record<string, string> = {
  pending: "Send to HR review",
  hr_review: "Send to finance",
  awaiting_finance: "Approve",
  procurement: "Mark as bought",
};

async function actOnRequest(action: "advance" | "forward", note?: string) {
  if (!openRequest.value) return;
  requestBusy.value = true;
  try {
    await api("POST", `/api/v1/assets/requests/${openRequest.value.id}/${action}`, {
      org: org(), body: { note: note ?? null },
    });
    openRequest.value = null;
    await loadRequestsTab();
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not do that.";
  } finally { requestBusy.value = false; }
}

async function declineRequest() {
  if (!openRequest.value || !declineReason.value.trim()) return;
  requestBusy.value = true;
  try {
    await api("POST", `/api/v1/assets/requests/${openRequest.value.id}/decline`, {
      org: org(), body: { reason: declineReason.value.trim() },
    });
    showDecline.value = false;
    openRequest.value = null;
    declineReason.value = "";
    await loadRequestsTab();
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not decline that.";
  } finally { requestBusy.value = false; }
}

async function loadRequestsTab() {
  requests.value = (await api<{ data: any }>("GET", "/api/v1/assets/requests", { org: org() })).data?.data ?? [];
}
const renewalSummary = ref<any>(null);

/** Who to tell, being edited for one asset at a time. */
const editingWatchers = ref<any | null>(null);
const watcherIds = ref<number[]>([]);
const watcherSaving = ref(false);
const watcherPick = ref<number | null>(null);

/**
 * Names for the ids being edited.
 *
 * Held here rather than refetched: the list came with the row, and somebody
 * adding three people should not wait for three round trips to see them.
 */
const watcherNames = ref<Record<number, string>>({});

const watcherChips = computed(() =>
  watcherIds.value.map(id => ({ id, name: watcherNames.value[id] ?? `#${id}` })));

function addWatcher() {
  const id = watcherPick.value;
  if (!id || watcherIds.value.includes(id)) { watcherPick.value = null; return; }
  watcherIds.value = [...watcherIds.value, id];
  watcherPick.value = null;
}

function removeWatcher(id: number) {
  watcherIds.value = watcherIds.value.filter(x => x !== id);
}

async function loadRenewals() {
  const res = await api<{ data: any[]; summary: any }>("GET", "/api/v1/assets/renewals", {
    org: org(), params: { all: 1 },
  });
  renewals.value = res.data ?? [];
  renewalSummary.value = res.summary ?? null;
}

function openWatchers(r: any) {
  editingWatchers.value = r;
  watcherIds.value = (r.watchers ?? []).map((w: any) => w.employee_id);
  watcherNames.value = Object.fromEntries((r.watchers ?? []).map((w: any) => [w.employee_id, w.name]));
  watcherPick.value = null;
}

async function saveWatchers(remindersOn: boolean) {
  if (!editingWatchers.value) return;
  watcherSaving.value = true;
  try {
    await api("PUT", `/api/v1/assets/${editingWatchers.value.id}/renewal-watchers`, {
      org: org(),
      body: { reminders_on: remindersOn, employee_ids: watcherIds.value },
    });
    editingWatchers.value = null;
    await loadRenewals();
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not save that.";
  } finally { watcherSaving.value = false; }
}
const held      = ref<Asset[]>([]);

async function openTab(next: Tab) {
  tab.value = next;
  if (loaded.value.has(next)) return;

  try {
    if (next === "overview") {
      summary.value = await api<Summary>("GET", "/api/v1/assets/summary", { org: org() });
    } else if (next === "requests") {
      await loadRequestsTab();
    } else if (next === "maintenance") {
      servicing.value = (await api<{ data: any }>("GET", "/api/v1/assets/maintenance", { org: org() })).data?.data ?? [];
    } else if (next === "renewals") {
      await loadRenewals();
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

onMounted(() => { void load(); void openTab("overview"); void loadCategories(); });
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
          <tr><th>Request</th><th>Item</th><th>Employee</th><th>Urgency</th><th>Submitted</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="r in requests" :key="r.id">
            <td class="mono">{{ r.reference }}</td>
            <td>{{ r.item }}</td>
            <td>{{ r.employee ?? '—' }}</td>
            <td>{{ r.urgency ?? '—' }}</td>
            <td>{{ r.submitted_on }}</td>
            <td>
              <span :class="['pill', r.status === 'rejected' ? 'pill-bad' : r.status === 'fulfilled' ? 'pill-ok' : '']">
                {{ STAGE_LABEL[r.status] ?? r.status }}
              </span>
            </td>
            <td><button class="link" @click="openRequest = r">View</button></td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- ── Renewals ─────────────────────────────────────────────────────── -->
    <section v-else-if="tab === 'renewals'" class="pane">
      <div v-if="renewalSummary" class="counts">
        <!-- Expired leads. Something that lapsed last week is a worse problem
             than something lapsing next month. -->
        <div :class="['count', { bad: renewalSummary.expired }]">
          <span class="count-n">{{ renewalSummary.expired }}</span><span class="count-l">already expired</span>
        </div>
        <div class="count">
          <span class="count-n">{{ renewalSummary.this_month }}</span><span class="count-l">within 30 days</span>
        </div>
        <div class="count">
          <span class="count-n">{{ renewalSummary.total }}</span><span class="count-l">with a date</span>
        </div>
        <div class="count">
          <span class="count-n">{{ money(renewalSummary.estimated) }}</span>
          <!-- Labelled a guess, because it is one. -->
          <span class="count-l">estimated to renew</span>
        </div>
      </div>

      <p v-if="!renewals.length" class="quiet">Nothing on the register has a renewal date.</p>

      <table v-else class="grid">
        <thead>
          <tr>
            <th>What</th><th>Expires</th><th>Seats</th>
            <th>Last cost</th><th>Bought from</th><th>Estimated</th><th>Reminders</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in renewals" :key="r.id" :class="{ flagged: r.days_to_expiry < 0 }">
            <td>
              <router-link :to="{ name: 'asset-detail', params: { id: r.id } }" class="link">{{ r.name }}</router-link>
              <div class="sub">{{ r.category }}</div>
            </td>
            <td>
              {{ r.expires_on }}
              <div :class="['sub', r.days_to_expiry < 0 ? 'sub-bad' : '']">
                {{ r.days_to_expiry < 0
                    ? `${Math.abs(r.days_to_expiry)} days ago`
                    : `in ${r.days_to_expiry} days` }}
                <template v-if="r.auto_renews"> · auto-renews</template>
              </div>
            </td>
            <td>
              <template v-if="r.seats_total">{{ r.seats_used }}/{{ r.seats_total }}</template>
              <span v-else class="quiet">—</span>
            </td>
            <!-- What it actually cost, against what it is expected to. One is a
                 fact and one is a guess, so they do not share a column. -->
            <td>{{ r.last_cost ? money(Number(r.last_cost)) : '—' }}</td>
            <td>{{ r.last_vendor ?? '—' }}</td>
            <td>{{ r.estimated_cost ? money(Number(r.estimated_cost)) : '—' }}</td>
            <td>
              <button class="link" @click="openWatchers(r)">
                <template v-if="r.reminders_on">
                  {{ r.watchers.length }} {{ r.watchers.length === 1 ? 'person' : 'people' }}
                </template>
                <template v-else>off</template>
              </button>
            </td>
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

    <!-- ── Who to tell ──────────────────────────────────────────────────── -->
    <div v-if="editingWatchers" class="overlay" @click.self="editingWatchers = null">
      <div class="dialog dialog-narrow" role="dialog" aria-modal="true" aria-label="Renewal reminders">
        <h2>Renewal reminders</h2>
        <p class="quiet reminder-lead">
          <strong>{{ editingWatchers.name }}</strong> expires {{ editingWatchers.expires_on }}.
        </p>

        <!--
          The shape of what they are agreeing to, rather than a switch that
          promises "reminders" and never says how many or when.
        -->
        <ul class="sched">
          <li v-for="m in editingWatchers.schedule" :key="m.on">
            <span class="sched-when">{{ m.label }}</span>
            <span class="sched-on">{{ m.on }}</span>
          </li>
          <li class="sched-tail">then every day until it is renewed</li>
        </ul>

        <label class="field field-wide"><span>Who should hear about it</span>
          <div class="watcher-add">
            <EmployeePicker v-model="watcherPick" />
            <button class="btn-secondary" :disabled="!watcherPick" @click="addWatcher">Add</button>
          </div>
        </label>

        <p v-if="!watcherIds.length" class="quiet">
          Nobody yet. A reminder with no recipients is a setting that looks
          configured and does nothing.
        </p>
        <div v-else class="chips">
          <span v-for="w in watcherChips" :key="w.id" class="chip">
            {{ w.name }}
            <button class="chip-x" aria-label="Remove" @click="removeWatcher(w.id)">×</button>
          </span>
        </div>

        <div class="dialog-actions">
          <button class="btn-secondary" :disabled="watcherSaving" @click="saveWatchers(false)">Turn off</button>
          <button class="btn-primary" :disabled="watcherSaving || !watcherIds.length" @click="saveWatchers(true)">
            {{ watcherSaving ? "Saving…" : "Remind these people" }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── One request ──────────────────────────────────────────────────── -->
    <div v-if="openRequest" class="overlay" @click.self="openRequest = null">
      <div class="dialog dialog-narrow" role="dialog" aria-modal="true" aria-label="Request">
        <h2>{{ openRequest.item }}</h2>
        <p class="quiet reminder-lead">
          <code>{{ openRequest.reference }}</code> · {{ openRequest.employee }} ·
          requested {{ openRequest.submitted_on }}
        </p>

        <!-- Why it was declined, shown to the person who asked rather than
             buried in an internal note. -->
        <p v-if="openRequest.status === 'rejected'" class="alert">
          Declined — {{ openRequest.decision_note ?? openRequest.rejection_reason ?? 'no reason recorded' }}
        </p>

        <div class="kv-list">
          <div><span>Stage</span><strong>{{ STAGE_LABEL[openRequest.status] ?? openRequest.status }}</strong></div>
          <div><span>Category</span><strong>{{ openRequest.category ?? '—' }}</strong></div>
          <div><span>Quantity</span><strong>{{ openRequest.quantity ?? 1 }}</strong></div>
          <div><span>Urgency</span><strong>{{ openRequest.urgency ?? '—' }}</strong></div>
        </div>

        <p v-if="openRequest.reason" class="req-reason">"{{ openRequest.reason }}"</p>

        <div v-if="!['rejected','fulfilled'].includes(openRequest.status)" class="dialog-actions req-actions">
          <button class="btn-danger" :disabled="requestBusy" @click="showDecline = true">Decline</button>
          <!-- Nothing suitable on the shelf is a different situation from
               awaiting a decision, so it is its own action. -->
          <button class="btn-secondary" :disabled="requestBusy" @click="actOnRequest('forward')">
            Forward to procurement
          </button>
          <button class="btn-primary" :disabled="requestBusy" @click="actOnRequest('advance')">
            {{ NEXT_LABEL[openRequest.status] ?? 'Advance' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Declining, with a reason ─────────────────────────────────────── -->
    <div v-if="showDecline" class="overlay" @click.self="showDecline = false">
      <div class="dialog dialog-narrow" role="dialog" aria-modal="true" aria-label="Decline request">
        <h2>Decline request</h2>
        <p class="quiet reminder-lead">
          The reason goes back to whoever asked. Not being told why is the part
          people actually mind.
        </p>
        <label class="field field-wide"><span>Reason</span>
          <textarea v-model="declineReason" class="control" rows="3"
                    placeholder="Budget frozen this quarter — revisit in October."></textarea>
        </label>
        <div class="dialog-actions">
          <button class="btn-secondary" @click="showDecline = false">Cancel</button>
          <button class="btn-danger" :disabled="requestBusy || !declineReason.trim()" @click="declineRequest">
            Decline request
          </button>
        </div>
      </div>
    </div>

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
          <select v-model="form.category" class="control" @change="onCategoryChange">
            <option value="">Choose one…</option>
            <option v-for="c in categoriesFor" :key="c.id" :value="c.name">{{ c.name }}</option>
          </select>
          <!-- The difference between "peripheral" and "other" is obvious to
               whoever wrote the list and to nobody else. -->
          <span v-if="chosenCategory?.description" class="hint">{{ chosenCategory.description }}</span>
        </label>

        <!--
          Choosing "other" costs one sentence.

          A register full of things filed under "other" is a register nobody can
          report on, so the catch-all asks what the thing actually is — and that
          answer becomes the category rather than the word "other".
        -->
        <div v-if="needsOwnName" class="field-wide other-box">
          <p class="other-lead">
            "Other" cannot be reported on. Say what this is and it will be filed
            under that instead.
          </p>
          <label class="field"><span>What is it?</span>
            <input v-model="form.other_name" class="control" placeholder="UPS, projector, signage screen…" />
          </label>
          <label class="field"><span>Description</span>
            <textarea v-model="form.other_description" class="control" rows="2"
                      placeholder="What it is for, and anything the next person should know."></textarea>
          </label>
        </div>

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

        <!-- ── What this category asks for ────────────────────────────────── -->
        <template v-if="categoryFields.length">
          <p class="field-wide cf-lead">For a {{ form.category }}</p>

          <label v-for="cf in categoryFields" :key="cf.key"
                 :class="['field', cf.type === 'textarea' ? 'field-wide' : '']">
            <span>{{ cf.label }}<template v-if="cf.is_required"> *</template></span>

            <textarea v-if="cf.type === 'textarea'" v-model="form.custom_fields[cf.key]"
                      class="control" rows="3"></textarea>
            <select v-else-if="cf.type === 'select'" v-model="form.custom_fields[cf.key]" class="control">
              <option value="">—</option>
              <option v-for="o in (cf.options ?? [])" :key="o" :value="o">{{ o }}</option>
            </select>
            <label v-else-if="cf.type === 'boolean'" class="check">
              <input v-model="form.custom_fields[cf.key]" type="checkbox" /><span>Yes</span>
            </label>
            <input v-else v-model="form.custom_fields[cf.key]" class="control"
                   :type="cf.type === 'number' ? 'number' : cf.type === 'date' ? 'date' : 'text'" />

            <span v-if="cf.help" class="hint">{{ cf.help }}</span>
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

/* The follow-up when somebody picks the catch-all. Set apart so it reads as a
   question being asked rather than two more fields. */
.other-box { border: 1px solid var(--color-border); border-left: 3px solid var(--color-brand-base);
             border-radius: 8px; padding: 12px 14px 2px; margin-bottom: var(--space-3);
             background: var(--color-surface-sunken); }
.other-lead { font-size: .78rem; color: var(--color-text-muted); margin: 0 0 10px; }
.link { color: var(--color-brand-on-surface); }

/* ── Renewals ──────────────────────────────────────────────────────────── */
.counts { display: flex; flex-wrap: wrap; gap: 28px; margin-bottom: 18px; }
.count { display: flex; flex-direction: column; }
.count-n { font-size: 1.5rem; font-weight: 600; color: var(--color-text); line-height: 1.1; }
.count-l { font-size: .75rem; color: var(--color-text-muted); }
.count.bad .count-n { color: var(--color-danger-text); }
.sub { font-size: .75rem; color: var(--color-text-muted); }
.sub-bad { color: var(--color-danger-text); }
.grid tr.flagged td { background: color-mix(in oklab, var(--color-danger-subtle) 35%, transparent); }

.dialog-narrow { max-width: 32rem; }
.reminder-lead { margin: -6px 0 14px; }
/* What they are agreeing to receive, rather than a switch promising
   "reminders" without saying how many or when. */
.sched { list-style: none; margin: 0 0 16px; padding: 10px 12px; border-radius: 8px;
         background: var(--color-surface-sunken); display: flex; flex-direction: column; gap: 4px; }
.sched li { display: flex; justify-content: space-between; font-size: .78rem; }
.sched-when { color: var(--color-text); }
.sched-on { color: var(--color-text-muted); font-family: ui-monospace, monospace; }
.sched-tail { color: var(--color-text-muted); font-style: italic; }

.watcher-add { display: flex; gap: 8px; align-items: center; }
.watcher-add > :first-child { flex: 1; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
.chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px;
        background: var(--color-brand-subtle); color: var(--color-brand-on-surface); font-size: .78rem; }
.chip-x { background: none; border: none; padding: 0; cursor: pointer; color: inherit; font-size: 1rem; line-height: 1; }

.cf-lead { font-size: .78rem; font-weight: 600; color: var(--color-text-muted);
           text-transform: uppercase; letter-spacing: .04em;
           border-top: 1px solid var(--color-border); padding-top: 12px; margin: 6px 0 10px; }

.kv-list { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 18px; margin-bottom: 14px; }
.kv-list > div { display: flex; flex-direction: column; }
.kv-list span { font-size: .72rem; color: var(--color-text-muted); }
.kv-list strong { font-size: .88rem; font-weight: 500; color: var(--color-text); }
.req-reason { font-size: .85rem; color: var(--color-text-muted); border-left: 3px solid var(--color-border);
              padding-left: 12px; margin: 0 0 16px; }
.req-actions { flex-wrap: wrap; }
.btn-danger { padding: 8px 16px; border-radius: var(--radius-md); border: 1px solid var(--color-danger-text);
              background: transparent; color: var(--color-danger-text); font: inherit; font-size: .85rem; cursor: pointer; }
.btn-danger:disabled { opacity: .5; cursor: default; }
</style>