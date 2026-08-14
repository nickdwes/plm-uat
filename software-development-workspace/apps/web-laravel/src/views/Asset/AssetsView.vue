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
  /* assignee | custodian | none — whether this kind of thing is carried by a
     person, looked after by one, or consumed as seats. */
  custody_model?: string;
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

/** Software products a subscription can be attached to. */
const softwareProducts = ref<{ id: number; name: string }[]>([]);

async function loadSoftwareProducts() {
  try {
    const res = await api<{ data: any[] }>("GET", "/api/v1/assets", {
      org: org(), params: { asset_class: "software", per_page: 200 },
    });
    softwareProducts.value = (res.data ?? []).map(a => ({ id: a.id, name: a.name }));
  } catch { softwareProducts.value = []; }
}

/**
 * The fields as the form draws them: grouped under their headings, in order.
 *
 * Software asks twenty-eight questions across five sections before its
 * category adds any. Without the headings that is a wall, and a wall does not
 * get filled in.
 */
const fieldSections = computed(() => {
  const out: { title: string; fields: any[] }[] = [];
  for (const f of categoryFields.value) {
    const title = f.section ?? "";
    const last = out[out.length - 1];
    if (last && last.title === title) last.fields.push(f);
    else out.push({ title, fields: [f] });
  }
  return out;
});

async function loadCategoryFields() {
  categoryFields.value = [];
  const c = chosenCategory.value;
  if (!c) return;
  try {
    // Class-level questions and the category's own, in one call and already
    // ordered — the form should not have to know they come from two places.
    const res = await api<{ data: any[] }>("GET", "/api/v1/org/asset-form-fields", {
      org: org(), params: { asset_class: form.value.asset_class, category: c.name },
    });
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
  /** The software product a subscription pays for. */
  parent_asset_id: null as number | null,
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

    if (f.asset_class === "subscription" && f.parent_asset_id) {
      body.parent_asset_id = f.parent_asset_id;
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
  void loadSoftwareProducts();
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
  totals: {
    assigned: number; available: number; pending_requests: number; under_repair: number;
    // Added with the cloud class — optional so an older backend does not blank
    // the whole tile row on a mismatched deploy.
    in_service?: number; looked_after?: number; suspended?: number;
  };
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

/*
 * ── Raising a request ────────────────────────────────────────────────────
 *
 * A 420px side drawer, per the designs — not a centred modal. The chain could
 * advance, decline, forward and fulfil since the approval work went in, and
 * nothing could raise one, so the tab said "No requests have been raised" and
 * always would have.
 */
const showRequest    = ref(false);
const requestSaving  = ref(false);
const requestError   = ref("");
const requestForm    = ref<{
  employee_id: number | null; item: string; category: string;
  quantity: number; urgency: string; reason: string;
}>({ employee_id: null, item: "", category: "", quantity: 1, urgency: "normal", reason: "" });

/*
 * The designs label priority P0/P1/P2 rather than low/normal/high. Their
 * numbering is not the usual one — P0 is the *lowest* there — so the mapping is
 * written out rather than computed, and the stored value stays the plain word.
 */
const URGENCY = [
  { value: "high",   label: "High (P1)" },
  { value: "normal", label: "Medium (P2)" },
  { value: "low",    label: "Low (P0)" },
];
const urgencyLabel = (u: string | null) =>
  URGENCY.find((x) => x.value === u)?.label ?? u ?? "—";

function startRequest() {
  requestForm.value = { employee_id: null, item: "", category: "", quantity: 1, urgency: "normal", reason: "" };
  requestError.value = "";
  showRequest.value = true;
}

async function submitRequest() {
  requestSaving.value = true; requestError.value = "";
  try {
    await api("POST", "/api/v1/assets/requests", {
      org: org(),
      // Omitted rather than sent null, so the backend can default it to the
      // caller's own employee record.
      body: {
        ...requestForm.value,
        employee_id: requestForm.value.employee_id ?? undefined,
      },
    });
    showRequest.value = false;
    await loadRequestsTab();
    tab.value = "requests";
  } catch (e) {
    requestError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not raise the request.";
  } finally { requestSaving.value = false; }
}

/*
 * ── Assigning ────────────────────────────────────────────────────────────
 *
 * Employee, then category, then the asset — in that order, because the asset
 * list is only useful once it is narrowed. The designs show the available
 * assets as a radio list with the condition beside each, which is the fact that
 * decides between two identical models.
 */
const showAssign     = ref(false);
const assignSaving   = ref(false);
const assignError    = ref("");
const assignDone     = ref<{ asset: string; employee: string; id: number; kind: "equipment" | "licence" } | null>(null);
const assignable     = ref<Asset[]>([]);
/*
 * Equipment or a licence.
 *
 * To whoever is standing there it is one job — give this person the thing they
 * need — so it is one drawer. Underneath they are different acts: equipment has
 * one holder and comes back, a licence is a seat drawn from a batch and is
 * released rather than returned. Software has no route at all without this:
 * the categories are custody_model `none`, so the equipment picker correctly
 * excludes them, and two hundred spare seats sat in the register unreachable.
 */
const assignKind = ref<"equipment" | "licence">("equipment");
const licences   = ref<Asset[]>([]);
const licenceQuery = ref("");

const assignForm     = ref<{
  employee_id: number | null; category: string; asset_id: number | null;
  accessories: string[]; assigned_date: string; expected_return_date: string; note: string;
}>({ employee_id: null, category: "", asset_id: null, accessories: [], assigned_date: "", expected_return_date: "", note: "" });

/** Only agreements with a seat left, newest term first. */
const licenceMatches = computed(() => {
  const q = licenceQuery.value.trim().toLowerCase();
  return licences.value
    .filter((l) => ((l as any).seats_spare ?? 0) > 0)
    .filter((l) => !q || l.name.toLowerCase().includes(q));
});

// The designs' set. It belongs in category configuration eventually — a vehicle
// goes out with keys and a logbook, not a laptop bag.
const ACCESSORIES = ["Charger", "Mouse", "Laptop bag"];

/*
 * Only things a person can actually be handed.
 *
 * The unfiltered list offered virtual machines, key vaults and storage accounts
 * — nobody carries those home, and the register would then claim somebody was
 * holding a subscription. Categories say which model they follow; this is that
 * answer applied.
 */
const assignableCategories = computed(() =>
  categories.value.filter((c) => (c.custody_model ?? "assignee") === "assignee"));

function openAssign() {
  assignKind.value = "equipment";
  licenceQuery.value = "";
  assignForm.value = {
    employee_id: null, category: "", asset_id: null,
    accessories: [], assigned_date: "", expected_return_date: "", note: "",
  };
  assignable.value = [];
  assignError.value = "";
  assignDone.value = null;
  showAssign.value = true;
}

/**
 * Agreements with a seat to give.
 *
 * No category step: the imported agreements all sit under "saas", so filtering
 * by category would find nothing, and the names say what they are anyway.
 */
async function loadLicences() {
  assignForm.value.asset_id = null;
  try {
    licences.value = (await api<{ data: Asset[] }>("GET", "/api/v1/assets", {
      org: org(), params: { has_spare_seats: 1, per_page: 200 },
    })).data ?? [];
  } catch { licences.value = []; }
}

function pickKind(kind: "equipment" | "licence") {
  assignKind.value = kind;
  assignForm.value.asset_id = null;
  assignForm.value.category = "";
  if (kind === "licence" && !licences.value.length) void loadLicences();
}

/** Only what is actually free to give out, narrowed by category. */
async function loadAssignable() {
  assignForm.value.asset_id = null;
  if (!assignForm.value.category) { assignable.value = []; return; }
  try {
    assignable.value = (await api<{ data: Asset[] }>("GET", "/api/v1/assets", {
      org: org(),
      params: { status: "available", category: assignForm.value.category, per_page: 100 },
    })).data ?? [];
  } catch { assignable.value = []; }
}

async function submitAssign() {
  assignSaving.value = true; assignError.value = "";
  try {
    if (assignKind.value === "licence") {
      // A seat off a batch, not a handover. `allocateSeat` refuses to
      // over-allocate, which is the compliance check worth having at the
      // moment somebody is added rather than in an audit later.
      const res = await api<{ data: Asset }>("POST", `/api/v1/assets/${assignForm.value.asset_id}/seats`, {
        org: org(),
        body: {
          employee_id: assignForm.value.employee_id,
          allocated_on: assignForm.value.assigned_date || null,
          note: assignForm.value.note || null,
        },
      });
      /*
       * Named from the seat that was just written, rather than a placeholder.
       * The response carries the holders, so the person is knowable — and a
       * confirmation that says "them" is one nobody can check.
       */
      const seat = ((res.data as any).seat_holders ?? [])
        .filter((h: any) => h.employee_id === assignForm.value.employee_id)
        .pop();
      assignDone.value = {
        asset: res.data.name,
        employee: seat?.employee ?? "the employee",
        id: res.data.id,
        kind: "licence",
      };
      showAssign.value = false;
      await loadLicences();
      return;
    }

    const res = await api<{ data: Asset }>("POST", `/api/v1/assets/${assignForm.value.asset_id}/assign`, {
      org: org(),
      body: {
        employee_id: assignForm.value.employee_id,
        assigned_date: assignForm.value.assigned_date || null,
        expected_return_date: assignForm.value.expected_return_date || null,
        accessories: assignForm.value.accessories,
      },
    });
    assignDone.value = {
      asset: res.data.name,
      employee: (res.data as any).holder?.full_name ?? "the employee",
      id: res.data.id,
      kind: "equipment",
    };
    showAssign.value = false;
    loaded.value.delete("employee");
    await openTab("employee");
  } catch (e) {
    // 422 here is usually the tenancy check: a real employee id, wrong org.
    assignError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not assign it.";
  } finally { assignSaving.value = false; }
}

async function loadRequestsTab() {
  requests.value = (await api<{ data: any }>("GET", "/api/v1/assets/requests", { org: org() })).data?.data ?? [];
}
const renewalSummary = ref<any>(null);

/**
 * The software estate, counted.
 *
 * On the Overview rather than a ninth tab: it answers the same question the
 * cards above it do, for the half of the register that is not equipment.
 */
const software = ref<any>(null);

async function loadSoftware() {
  try {
    software.value = await api<any>("GET", "/api/v1/assets/software", { org: org() });
  } catch { software.value = null; }
}

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
      await loadSoftware();
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
              <!-- Running and looked after: cloud resources and racked kit,
                   which the tiles omitted entirely before this existed. -->
              <div class="stat"><span class="stat-n">{{ summary.totals.in_service ?? 0 }}</span><span class="stat-l">In service</span></div>
              <div class="stat"><span class="stat-n">{{ summary.totals.looked_after ?? 0 }}</span><span class="stat-l">Has a custodian</span></div>
              <div class="stat"><span class="stat-n">{{ summary.totals.suspended ?? 0 }}</span><span class="stat-l">Suspended</span></div>
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

        <!-- ── Software ─────────────────────────────────────────────────── -->
        <article v-if="software && software.totals.products" class="card software-card">
          <h2 class="card-title">Software</h2>
          <div class="counts">
            <div class="count">
              <span class="count-n">{{ software.totals.products }}</span>
              <span class="count-l">products</span>
            </div>
            <div class="count">
              <span class="count-n">{{ software.totals.agreements }}</span>
              <span class="count-l">agreements</span>
            </div>
            <div class="count">
              <span class="count-n">{{ software.seats.purchased }}</span>
              <span class="count-l">seats bought</span>
            </div>
            <!--
              The vendor's count while nothing is matched, and labelled as
              theirs. Showing the register's nought here would report a fully
              consumed tenant as entirely idle.
            -->
            <div class="count">
              <span class="count-n">{{ software.seats.assigned_reported ?? software.seats.assigned }}</span>
              <span class="count-l">allocated</span>
            </div>
            <div :class="['count', { bad: software.seats.spare }]">
              <span class="count-n">{{ software.seats.spare }}</span>
              <span class="count-l">available</span>
            </div>
            <div :class="['count', { bad: software.totals.unsupported }]">
              <span class="count-n">{{ software.totals.unsupported }}</span>
              <span class="count-l">unsupported</span>
            </div>
            <div class="count">
              <span class="count-n">{{ money(software.renewals.forecast_12_months) }}</span>
              <span class="count-l">renewal forecast</span>
            </div>
          </div>

          <!--
            Five columns, and every one of them populated. The previous version
            carried Annual and Criticality, which are empty for every Microsoft
            licence because Graph publishes no price and nobody has set a
            criticality — two columns of dashes that pushed the table wider than
            its card and left the header band adrift from the rows.
          -->
          <div class="table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Tenant</th>
                  <th class="num">Licences</th>
                  <th class="num">Allocated</th>
                  <th class="num">Available</th>
                  <th>Renews</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in software.by_product" :key="p.id">
                  <td>
                    <router-link :to="{ name: 'asset-detail', params: { id: p.id } }" class="link">{{ p.name }}</router-link>
                    <!-- A security finding, not a label somebody forgot to update. -->
                    <span v-if="p.unsupported" class="pill pill-bad">unsupported</span>
                    <div class="sub">
                      <!-- A product nobody is paying for is worth seeing. It may
                           be perpetual, or free, or forgotten. -->
                      <template v-if="p.agreements">
                        {{ p.agreements }} agreement{{ p.agreements === 1 ? "" : "s" }}
                      </template>
                      <template v-else>nothing tracked</template>
                    </div>
                  </td>
                  <!--
                    Which directory it came from. Identical on every row while
                    there is one tenant, and the thing that stops two companies'
                    identical licences reading as duplicates once there are two.
                  -->
                  <td>
                    <template v-if="p.tenant">{{ p.tenant }}</template>
                    <span v-else class="sub">entered by hand</span>
                  </td>
                  <td class="num">{{ p.seats || "—" }}</td>
                  <td class="num">{{ p.in_use || "—" }}</td>
                  <td :class="['num', { spare: p.available > 0 }]">{{ p.available || "—" }}</td>
                  <td>
                    <template v-if="p.next_renewal">
                      {{ p.next_renewal }}
                      <!-- Two purchases of one product renew twice; this is the
                           next one, which is what somebody is scanning for. -->
                      <div v-if="p.agreements > 1" class="sub">first of {{ p.agreements }}</div>
                    </template>
                    <span v-else class="sub">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <p class="table-note">
            Allocated is Microsoft's own count. The register will show seats it
            can name a person for once employees are matched, and the difference
            between the two is worth looking at when it appears.
          </p>
        </article>

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
      <!--
        The designs put these at the top right of their tabs: "Assign Asset" on
        Employee Assets, and asking for something on My Assets. Both were
        missing, which is what made the module look read-only.
      -->
      <div class="pane-actions">
        <button v-if="tab === 'employee'" class="btn-primary" @click="openAssign">Assign Asset</button>
        <button v-else class="btn-primary" @click="startRequest">Request an asset</button>
      </div>

      <p v-if="!(tab === 'mine' ? mine : held).length" class="quiet">
        {{ tab === 'mine' ? 'Nothing is assigned to you.' : 'Nothing is assigned to anybody yet.' }}
      </p>
      <table v-else class="data-table">
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
      <div class="pane-actions">
        <button class="btn-primary" @click="startRequest">Request an asset</button>
      </div>

      <p v-if="!requests.length" class="quiet">
        No requests have been raised. "Request an asset" starts one — it goes to HR first.
      </p>
      <table v-else class="data-table">
        <thead>
          <tr><th>Request ID</th><th>Asset</th><th>Employee</th><th>Priority</th><th>Submitted On</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <tr v-for="r in requests" :key="r.id">
            <td class="mono">{{ r.reference }}</td>
            <td>{{ r.item }}</td>
            <td>{{ r.employee ?? '—' }}</td>
            <!-- The designs show priority as a flag when it is high; a row of
                 identical grey words tells nobody which to read first. -->
            <td :class="{ urgent: r.urgency === 'high' }">
              <span v-if="r.urgency === 'high'" aria-hidden="true">⚑ </span>{{ urgencyLabel(r.urgency) }}
            </td>
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

      <table v-else class="data-table">
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
      <table v-else class="data-table">
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
      <table v-else class="data-table">
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

        <!--
          ── The commercial agreement ─────────────────────────────────────

          A subscription only. Software is the product; a subscription is what
          pays for access to it, and one product can carry several — an
          enterprise agreement, an executive tier, a monthly CSP — each with its
          own term, seats and cost. Asking a product when it renews is asking
          the wrong thing of the wrong record.
        -->
        <template v-else-if="form.asset_class === 'subscription'">
          <label class="field field-wide"><span>What this pays for</span>
            <select v-model="form.parent_asset_id" class="control">
              <option :value="null">Not tied to a software product</option>
              <option v-for="p in softwareProducts" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <span class="hint">
              One product can have several agreements. Linking them means seats
              and spend can be totalled across the lot.
            </span>
          </label>

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

        <!-- Software: everything it is asked is configured, nothing hardcoded. -->
        <template v-else>
          <label class="field field-wide"><span>What this software does</span>
            <textarea v-model="form.description" class="control" rows="3"
                      placeholder="What it is for, and who depends on it."></textarea>
          </label>
        </template>

        <!-- ── What this class and category ask for ───────────────────────── -->
        <template v-for="sec in fieldSections" :key="sec.title || 'ungrouped'">
          <p class="field-wide cf-lead">{{ sec.title || `For a ${form.category}` }}</p>

          <label v-for="cf in sec.fields" :key="cf.key"
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
    <!-- ══ Request an asset ═══════════════════════════════════════════════
         A right-hand drawer at the designs' 420px, not a centred modal. -->
    <div v-if="showRequest" class="overlay drawer-overlay" @click.self="showRequest = false">
      <aside class="drawer" role="dialog" aria-modal="true" aria-label="Request an asset">
        <header class="drawer-head">
          <h2>Request an asset</h2>
          <button class="drawer-x" aria-label="Close" @click="showRequest = false">✕</button>
        </header>

        <div class="drawer-body">
          <!--
            Who it is for. Blank means the person filling it in, which is the
            common case — but an account with no employee record behind it has
            nobody to default to, and without this field the refusal was a dead
            end with nothing to act on.
          -->
          <div class="field"><span>Requested for</span>
            <EmployeePicker v-model="requestForm.employee_id" />
            <span class="hint">Leave blank to request for yourself.</span>
          </div>

          <label class="field"><span>What do you need <b>*</b></span>
            <input v-model="requestForm.item" class="control" placeholder="Laptop, 16GB RAM" />
          </label>

          <label class="field"><span>Asset category <b>*</b></span>
            <select v-model="requestForm.category" class="control">
              <option value="">Choose one…</option>
              <option v-for="c in categories" :key="c.id" :value="c.name">{{ c.name }}</option>
            </select>
          </label>

          <div class="row">
            <label class="field"><span>Quantity <b>*</b></span>
              <input v-model.number="requestForm.quantity" class="control" type="number" min="1" max="500" />
            </label>
            <label class="field"><span>Priority <b>*</b></span>
              <select v-model="requestForm.urgency" class="control">
                <option v-for="u in URGENCY" :key="u.value" :value="u.value">{{ u.label }}</option>
              </select>
            </label>
          </div>

          <label class="field"><span>Description <b>*</b></span>
            <textarea v-model="requestForm.reason" class="control" rows="5"
                      placeholder="What it is for, and why the current one will not do."></textarea>
            <!-- The reason is what HR reads and what a decline is argued
                 against, so it is asked for rather than left optional. -->
            <span class="hint">This is what HR sees when they decide.</span>
          </label>
        </div>

        <p v-if="requestError" class="alert" role="alert">{{ requestError }}</p>

        <footer class="drawer-foot">
          <button class="btn-secondary" @click="showRequest = false">Cancel</button>
          <button class="btn-primary"
                  :disabled="requestSaving || !requestForm.item.trim() || !requestForm.category
                             || !requestForm.reason.trim()"
                  @click="submitRequest">
            {{ requestSaving ? "Submitting…" : "Submit Request" }}
          </button>
        </footer>
      </aside>
    </div>

    <!-- ══ Assign an Asset ════════════════════════════════════════════════ -->
    <div v-if="showAssign" class="overlay drawer-overlay" @click.self="showAssign = false">
      <aside class="drawer" role="dialog" aria-modal="true" aria-label="Assign an asset">
        <header class="drawer-head">
          <h2>Assign an Asset</h2>
          <button class="drawer-x" aria-label="Close" @click="showAssign = false">✕</button>
        </header>

        <div class="drawer-body">
          <div class="field"><span>Assign to <b>*</b></span>
            <EmployeePicker v-model="assignForm.employee_id" />
          </div>

          <!--
            Equipment or a licence. One drawer because it is one job; two
            branches because a laptop has a holder and comes back, while a seat
            is drawn from a batch and is released.
          -->
          <div class="field"><span>What are you giving them <b>*</b></span>
            <div class="kind-pick">
              <button type="button" :class="['kind-btn', { on: assignKind === 'equipment' }]"
                      @click="pickKind('equipment')">Equipment</button>
              <button type="button" :class="['kind-btn', { on: assignKind === 'licence' }]"
                      @click="pickKind('licence')">Software licence</button>
            </div>
          </div>

          <label v-if="assignKind === 'equipment'" class="field"><span>Asset Category <b>*</b></span>
            <select v-model="assignForm.category" class="control" @change="loadAssignable">
              <option value="">Select Asset Category</option>
              <option v-for="c in assignableCategories" :key="c.id" :value="c.name">{{ c.name }}</option>
            </select>
          </label>

          <!--
            No category step for licences: the imported agreements all sit under
            "saas", so a category filter would find nothing, and the names say
            what they are. Spare seats are shown because that is the number that
            decides whether this one can be given at all.
          -->
          <div v-else class="field">
            <span>Licence <b>*</b></span>
            <input v-model="licenceQuery" class="control" placeholder="Search licences…" />
            <p v-if="!licenceMatches.length" class="hint">
              {{ licences.length ? "Nothing matches that." : "No licence has a seat free." }}
            </p>
            <ul v-else class="pick-list">
              <li v-for="l in licenceMatches" :key="l.id">
                <label class="pick">
                  <input v-model="assignForm.asset_id" type="radio" :value="l.id" />
                  <span class="pick-name">{{ l.name }}</span>
                  <span class="chip">{{ (l as any).seats_spare }} spare</span>
                </label>
              </li>
            </ul>
          </div>

          <!--
            Only what is free to give out. Condition sits beside each because it
            is the fact that decides between two of the same model.
          -->
          <div v-if="assignKind === 'equipment' && assignForm.category" class="field">
            <span>Asset <b>*</b></span>
            <p v-if="!assignable.length" class="hint">
              Nothing available in that category. It would have to be requested from procurement.
            </p>
            <ul v-else class="pick-list">
              <li v-for="a in assignable" :key="a.id">
                <label class="pick">
                  <input v-model="assignForm.asset_id" type="radio" :value="a.id" />
                  <span class="pick-name">{{ a.name }}</span>
                  <code class="pick-id">{{ a.asset_tag }}</code>
                  <span v-if="a.condition" class="chip">{{ a.condition }}</span>
                </label>
              </li>
            </ul>
          </div>

          <!-- A seat has no charger and is not returned at an exit interview. -->
          <template v-if="assignKind === 'licence' && assignForm.asset_id">
            <label class="field"><span>Allocated on</span>
              <input v-model="assignForm.assigned_date" class="control" type="date" />
              <span class="hint">Today, if left blank.</span>
            </label>
            <label class="field"><span>Note</span>
              <input v-model="assignForm.note" class="control" placeholder="Why they need it, if worth recording" />
            </label>
          </template>

          <template v-if="assignKind === 'equipment' && assignForm.asset_id">
            <div class="field">
              <span>Accessories</span>
              <div class="checks">
                <label v-for="acc in ACCESSORIES" :key="acc" class="check-inline">
                  <input v-model="assignForm.accessories" type="checkbox" :value="acc" />
                  <span>{{ acc }}</span>
                </label>
              </div>
              <!-- Ticked here so the return screen can tick them back off. -->
              <span class="hint">Recorded so the return form can check them back in.</span>
            </div>

            <div class="row">
              <label class="field"><span>Assignment Date</span>
                <input v-model="assignForm.assigned_date" class="control" type="date" />
                <span class="hint">Today, if left blank.</span>
              </label>
              <label class="field"><span>Expected Return Date</span>
                <input v-model="assignForm.expected_return_date" class="control" type="date" />
                <span class="hint">Blank means no date agreed.</span>
              </label>
            </div>
          </template>
        </div>

        <p v-if="assignError" class="alert" role="alert">{{ assignError }}</p>

        <footer class="drawer-foot">
          <button class="btn-secondary" @click="showAssign = false">Cancel</button>
          <button class="btn-primary"
                  :disabled="assignSaving || !assignForm.employee_id || !assignForm.asset_id"
                  @click="submitAssign">
            {{ assignSaving ? "Assigning…" : assignKind === "licence" ? "Allocate seat" : "Assign Asset" }}
          </button>
        </footer>
      </aside>
    </div>

    <!-- ══ Assigned ═══════════════════════════════════════════════════════ -->
    <div v-if="assignDone" class="overlay" @click.self="assignDone = null">
      <div class="dialog dialog-narrow" role="dialog" aria-modal="true" aria-label="Assigned">
        <div class="done-mark" aria-hidden="true">✓</div>
        <h2>{{ assignDone.kind === "licence" ? "Seat allocated" : "Asset Assigned Successfully" }}</h2>
        <p class="muted">
          <template v-if="assignDone.kind === 'licence'">
            {{ assignDone.employee }} now holds a seat on {{ assignDone.asset }}.
          </template>
          <template v-else>
            {{ assignDone.asset }} is now with {{ assignDone.employee }}.
          </template>
        </p>
        <div class="dialog-actions">
          <button class="btn-secondary" @click="assignDone = null">Close</button>
          <router-link class="btn-primary" :to="{ name: 'asset-detail', params: { id: assignDone.id } }">
            View asset
          </router-link>
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
.pane-actions { display: flex; justify-content: flex-end; margin-bottom: 12px; }
.urgent { color: var(--color-error); font-weight: 600; }

/* ── Side drawers ──────────────────────────────────────────────────────────
   The designs use a 420px right-hand panel for these, not a centred modal. */
.drawer-overlay { align-items: stretch; justify-content: flex-end; padding: 0; }
.drawer {
  display: flex; flex-direction: column;
  width: min(420px, 100%); height: 100%;
  background: var(--color-surface);
  border-left: 1px solid var(--color-border);
}
.drawer-head { display: flex; align-items: center; justify-content: space-between;
  padding: 20px 24px; border-bottom: 1px solid var(--color-border); }
.drawer-head h2 { margin: 0; font-size: 1.05rem; font-weight: 600; color: var(--color-text); }
.drawer-x { border: none; background: none; cursor: pointer; font-size: 1rem;
  color: var(--color-text-muted); padding: 4px; }
.drawer-body { flex: 1; overflow-y: auto; padding: 20px 24px;
  display: flex; flex-direction: column; gap: 16px; }
.drawer-foot { display: flex; gap: 10px; padding: 16px 24px;
  border-top: 1px solid var(--color-border); }
.drawer-foot .btn-secondary, .drawer-foot .btn-primary { flex: 1; justify-content: center; }
.field b { color: var(--color-error); }

.pick-list { list-style: none; margin: 6px 0 0; padding: 0; display: flex; flex-direction: column;
  gap: 4px; max-height: 15rem; overflow-y: auto; }
.pick { display: flex; align-items: center; gap: 8px; padding: 8px 10px; cursor: pointer;
  border: 1px solid var(--color-border); border-radius: 8px; font-size: .85rem; }
.pick:hover { background: var(--color-surface-sunken); }
.pick-name { flex: 1; color: var(--color-text); }
.pick-id { font-size: .72rem; color: var(--color-text-muted); }
.checks { display: flex; flex-wrap: wrap; gap: 12px; }

.kind-pick { display: flex; gap: 8px; margin-top: 6px; }
.kind-btn { flex: 1; padding: 8px 10px; font-size: .85rem; cursor: pointer;
  border: 1px solid var(--color-border-strong); border-radius: 8px;
  background: var(--color-surface); color: var(--color-text); }
.kind-btn.on { border-color: var(--color-brand-base); background: var(--color-brand-subtle);
  color: var(--color-brand-base); font-weight: 600; }

.done-mark { width: 40px; height: 40px; border-radius: 999px; display: grid; place-items: center;
  background: var(--color-success-light); color: var(--color-success); font-size: 1.2rem; margin-bottom: 12px; }
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
/*
 * Not `.grid`.
 *
 * Tailwind owns that class name and defines `display: grid` on it, which turned
 * every table in this view into a grid container: `thead` became a block, the
 * table layout algorithm was discarded, and the header cells sized themselves
 * independently of the rows. The visible symptom was a header band that stopped
 * short of the data — reported as "this page appears very skewed", and true of
 * all six tables here, not just the software one.
 */
.data-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
.data-table th { text-align: left; padding: 10px 12px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase;
           letter-spacing: .04em; color: var(--color-text-muted); background: var(--color-surface-sunken); white-space: nowrap; }
.data-table td { padding: 12px; border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.data-table tr:last-child td { border-bottom: none; }
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
.data-table tr.flagged td { background: color-mix(in oklab, var(--color-danger-subtle) 35%, transparent); }

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

.software-card { margin-top: 16px; }
/* The card is narrower than the widest sensible table, and a table that
   overflows leaves its header band adrift from the rows. */
.table-scroll { overflow-x: auto; }
.data-table td.num, .data-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
.data-table td.spare { color: var(--color-warning, var(--color-text)); font-weight: 600; }
.table-note { margin: 10px 2px 0; font-size: .75rem; color: var(--color-text-muted); }
.software-card .counts { margin-bottom: 16px; }
</style>