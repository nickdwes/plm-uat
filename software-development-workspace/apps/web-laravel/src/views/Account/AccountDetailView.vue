<script setup lang="ts">
/**
 * One customer.
 *
 * Four tabs because they answer different questions and are maintained by
 * different people: what this company is (profile), who to call (contacts),
 * who of ours works them (team), and what we are on the hook for (contracts).
 *
 * Nothing is gated in the UI. The API refuses what the caller may not do and
 * says why — hiding a button additionally would mean two definitions of who
 * may edit a customer, and they would drift.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import EmployeePicker from "../../components/EmployeePicker.vue";

const props = defineProps<{ id: string | number }>();

interface Named { id: number; name: string }
interface Contact {
  id: number; title: string | null; first_name: string; last_name: string;
  full_name: string; designation: string | null; department: string | null;
  email: string | null; phone: string | null; is_primary: boolean; is_active: boolean;
}
interface Contract {
  id: number; name: string; contract_number: string | null; type: string | null;
  contract_value: string | number | null; start_date: string; end_date: string;
  status: string; notes: string | null; is_expired: boolean; days_to_expiry: number | null;
}
interface TeamMember { id: number; employee: { id: number | null; name: string | null }; role: string | null }
interface Account {
  id: number; name: string; status: string; country: string | null;
  physical_location: string | null; tax_pin: string | null; vat_registered: boolean;
  number_of_branches: number | null; number_of_employees: number | null;
  fund_source: string | null; details: string | null;
  account_manager?: { id: number | null; name: string | null };
  type?: Named | null; sector?: Named | null; subsector?: Named | null;
  contacts?: Contact[]; contracts?: Contract[]; team?: TeamMember[];
}

type Tab = "profile" | "contacts" | "team" | "contracts";

const router  = useRouter();
const account = ref<Account | null>(null);
const lookups = ref<{ types: Named[]; sectors: Named[]; subsectors: (Named & { account_sector_id: number })[] }>(
  { types: [], sectors: [], subsectors: [] },
);
const tab     = ref<Tab>("profile");
const loading = ref(true);
const saving  = ref(false);
const error   = ref<string | null>(null);
const notice  = ref<string | null>(null);

const org = () => currentOrgSlug.value;
const base = computed(() => `/api/v1/accounts/${props.id}`);

// ── Profile form ────────────────────────────────────────────────────────────
const form = ref({
  name: "", status: "prospect", account_manager_id: null as number | null,
  account_type_id: null as number | null, account_sector_id: null as number | null,
  account_subsector_id: null as number | null,
  country: "", physical_location: "", tax_pin: "", vat_registered: false,
  number_of_branches: null as number | null, number_of_employees: null as number | null,
  fund_source: "", details: "",
});

function fillForm(a: Account) {
  form.value = {
    name: a.name, status: a.status,
    account_manager_id: a.account_manager?.id ?? null,
    account_type_id: a.type?.id ?? null,
    account_sector_id: a.sector?.id ?? null,
    account_subsector_id: a.subsector?.id ?? null,
    country: a.country ?? "", physical_location: a.physical_location ?? "",
    tax_pin: a.tax_pin ?? "", vat_registered: a.vat_registered,
    number_of_branches: a.number_of_branches, number_of_employees: a.number_of_employees,
    fund_source: a.fund_source ?? "", details: a.details ?? "",
  };
}

/** Sub-sectors belong to a sector; showing all of them would offer nonsense. */
const subsectorOptions = computed(() =>
  form.value.account_sector_id
    ? lookups.value.subsectors.filter((s) => s.account_sector_id === form.value.account_sector_id)
    : [],
);

function onSectorChange() {
  // A sub-sector that no longer belongs to the chosen sector would be rejected
  // by the API anyway; clearing it here says so before the save.
  if (!subsectorOptions.value.some((s) => s.id === form.value.account_subsector_id)) {
    form.value.account_subsector_id = null;
  }
}

async function load() {
  loading.value = true;
  try {
    const [res, lk] = await Promise.all([
      api<{ data: Account }>("GET", base.value, { org: org() }),
      api<typeof lookups.value>("GET", "/api/v1/accounts/lookups", { org: org() }),
    ]);
    account.value = res.data;
    lookups.value = lk;
    fillForm(res.data);
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not load this customer.";
  } finally {
    loading.value = false;
  }
}

function flash(message: string) {
  notice.value = message;
  setTimeout(() => (notice.value = null), 3000);
}

async function saveProfile() {
  saving.value = true;
  error.value = null;
  try {
    const res = await api<{ data: Account }>("PUT", base.value, {
      org: org(),
      body: {
        ...form.value,
        country: form.value.country || null,
        physical_location: form.value.physical_location || null,
        tax_pin: form.value.tax_pin || null,
        fund_source: form.value.fund_source || null,
        details: form.value.details || null,
      },
    });
    // Merged rather than replaced: update() returns the profile without the
    // contacts, team and contracts the other tabs are showing.
    account.value = { ...account.value, ...res.data };
    fillForm(res.data);
    flash("Saved.");
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not save.";
  } finally { saving.value = false; }
}

// ── Contacts ────────────────────────────────────────────────────────────────
const blankContact = () => ({
  id: null as number | null, title: "", first_name: "", last_name: "",
  designation: "", department: "", email: "", phone: "", is_primary: false,
});
const contactForm = ref(blankContact());
const showContact = ref(false);

function editContact(c: Contact) {
  contactForm.value = {
    id: c.id, title: c.title ?? "", first_name: c.first_name, last_name: c.last_name,
    designation: c.designation ?? "", department: c.department ?? "",
    email: c.email ?? "", phone: c.phone ?? "", is_primary: c.is_primary,
  };
  showContact.value = true;
}

async function saveContact() {
  saving.value = true;
  error.value = null;
  const { id, ...body } = contactForm.value;
  try {
    await api(id ? "PUT" : "POST", id ? `${base.value}/contacts/${id}` : `${base.value}/contacts`, {
      org: org(),
      body: { ...body, title: body.title || null, designation: body.designation || null,
              department: body.department || null, email: body.email || null, phone: body.phone || null },
    });
    showContact.value = false;
    await load();
    flash(id ? "Contact updated." : "Contact added.");
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not save the contact.";
  } finally { saving.value = false; }
}

async function removeContact(c: Contact) {
  if (!confirm(`Remove ${c.full_name} from this customer?`)) return;
  try {
    await api("DELETE", `${base.value}/contacts/${c.id}`, { org: org() });
    await load();
    flash("Contact removed.");
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not remove the contact.";
  }
}

// ── Team ────────────────────────────────────────────────────────────────────
const newMember = ref({ employee_id: null as number | null, role: "" });

async function addMember() {
  if (!newMember.value.employee_id) return;
  saving.value = true;
  error.value = null;
  try {
    const res = await api<{ data: Account }>("POST", `${base.value}/team`, {
      org: org(), body: { ...newMember.value, role: newMember.value.role || null },
    });
    account.value = res.data;
    newMember.value = { employee_id: null, role: "" };
    flash("Added to the team.");
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not add them.";
  } finally { saving.value = false; }
}

async function removeMember(m: TeamMember) {
  try {
    const res = await api<{ data: Account }>("DELETE", `${base.value}/team/${m.id}`, { org: org() });
    account.value = res.data;
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not remove them.";
  }
}

// ── Contracts ───────────────────────────────────────────────────────────────
const blankContract = () => ({
  id: null as number | null, name: "", contract_number: "", type: "",
  contract_value: null as number | null, start_date: "", end_date: "",
  status: "active", notes: "",
});
const contractForm = ref(blankContract());
const showContract = ref(false);

function editContract(c: Contract) {
  contractForm.value = {
    id: c.id, name: c.name, contract_number: c.contract_number ?? "", type: c.type ?? "",
    contract_value: c.contract_value === null ? null : Number(c.contract_value),
    start_date: c.start_date, end_date: c.end_date, status: c.status, notes: c.notes ?? "",
  };
  showContract.value = true;
}

async function saveContract() {
  saving.value = true;
  error.value = null;
  const { id, ...body } = contractForm.value;
  try {
    await api(id ? "PUT" : "POST", id ? `${base.value}/contracts/${id}` : `${base.value}/contracts`, {
      org: org(),
      body: { ...body, contract_number: body.contract_number || null,
              type: body.type || null, notes: body.notes || null },
    });
    showContract.value = false;
    await load();
    flash(id ? "Contract updated." : "Contract added.");
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not save the contract.";
  } finally { saving.value = false; }
}

async function removeContract(c: Contract) {
  if (!confirm(`Remove "${c.name}"? The record of what was agreed goes with it.`)) return;
  try {
    await api("DELETE", `${base.value}/contracts/${c.id}`, { org: org() });
    await load();
    flash("Contract removed.");
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not remove the contract.";
  }
}

/**
 * Expiry, said in the terms somebody acts on.
 *
 * A date alone makes the reader work out whether it matters; "in 12 days" does
 * not, and 12 days is when a renewal still has time to be signed.
 */
function expiry(c: Contract): { label: string; tone: string } {
  if (c.status === "terminated") return { label: "Terminated", tone: "neutral" };
  if (c.is_expired) return { label: "Expired", tone: "danger" };
  const d = c.days_to_expiry;
  if (d !== null && d <= 90) return { label: `Expires in ${d} day${d === 1 ? "" : "s"}`, tone: "warn" };
  return { label: "Active", tone: "ok" };
}

const money = (v: string | number | null) =>
  v === null || v === "" ? "—" : Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 });

const tabs: { key: Tab; label: string }[] = [
  { key: "profile",   label: "Profile" },
  { key: "contacts",  label: "Contacts" },
  { key: "team",      label: "Team" },
  { key: "contracts", label: "Contracts" },
];

const countFor = (t: Tab) =>
  t === "contacts"  ? account.value?.contacts?.length
  : t === "team"      ? account.value?.team?.length
  : t === "contracts" ? account.value?.contracts?.length
  : undefined;

onMounted(load);
</script>

<template>
  <div class="ad-wrap">
    <button class="ad-back" @click="router.push({ name: 'accounts' })">← Customers</button>

    <div v-if="loading" class="ad-loading">Loading…</div>

    <template v-else-if="account">
      <header class="ad-header">
        <div>
          <h1 class="ad-title">{{ account.name }}</h1>
          <p class="ad-sub">
            <span :class="['pill', account.status === 'active' ? 'pill-ok' : account.status === 'inactive' ? 'pill-neutral' : 'pill-warn']">
              {{ account.status }}
            </span>
            <template v-if="account.sector?.name"> · {{ account.sector.name }}</template>
            <template v-if="account.account_manager?.name"> · Owned by {{ account.account_manager.name }}</template>
          </p>
        </div>
      </header>

      <div v-if="error" class="alert-error">{{ error }}</div>
      <div v-if="notice" class="alert-ok">{{ notice }}</div>

      <nav class="ad-tabs" role="tablist">
        <button v-for="t in tabs" :key="t.key" role="tab" :aria-selected="tab === t.key"
                :class="['ad-tab', { active: tab === t.key }]" @click="tab = t.key">
          {{ t.label }}<span v-if="countFor(t.key)" class="ad-count">{{ countFor(t.key) }}</span>
        </button>
      </nav>

      <!-- ── Profile ─────────────────────────────────────────────────── -->
      <section v-if="tab === 'profile'" class="ad-panel">
        <div class="ad-grid">
          <label class="ad-field"><span>Name</span>
            <input v-model="form.name" class="control" /></label>

          <label class="ad-field"><span>Status</span>
            <select v-model="form.status" class="control">
              <option value="prospect">Prospect</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select></label>

          <div class="ad-field ad-span">
            <span>Account manager</span>
            <EmployeePicker v-model="form.account_manager_id" placeholder="Who owns this relationship?" />
            <p v-if="account.account_manager?.name" class="ad-hint">
              Currently {{ account.account_manager.name }}. Reassignment needs its own permission —
              if the change does not stick, that is why.
            </p>
          </div>

          <label class="ad-field"><span>Type</span>
            <select v-model.number="form.account_type_id" class="control">
              <option :value="null">—</option>
              <option v-for="t in lookups.types" :key="t.id" :value="t.id">{{ t.name }}</option>
            </select></label>

          <label class="ad-field"><span>Sector</span>
            <select v-model.number="form.account_sector_id" class="control" @change="onSectorChange">
              <option :value="null">—</option>
              <option v-for="s in lookups.sectors" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select></label>

          <label class="ad-field"><span>Sub-sector</span>
            <select v-model.number="form.account_subsector_id" class="control"
                    :disabled="!form.account_sector_id || subsectorOptions.length === 0">
              <option :value="null">—</option>
              <option v-for="s in subsectorOptions" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <p v-if="form.account_sector_id && subsectorOptions.length === 0" class="ad-hint">
              No sub-sectors defined for this sector.
            </p></label>

          <label class="ad-field"><span>Country (ISO code)</span>
            <input v-model="form.country" class="control" maxlength="2" placeholder="KE" /></label>

          <label class="ad-field ad-span"><span>Physical location</span>
            <input v-model="form.physical_location" class="control" placeholder="Westlands, Nairobi" /></label>

          <label class="ad-field"><span>Tax PIN</span>
            <input v-model="form.tax_pin" class="control" /></label>

          <label class="ad-field ad-check">
            <input v-model="form.vat_registered" type="checkbox" />
            <span>VAT registered</span></label>

          <label class="ad-field"><span>Branches</span>
            <input v-model.number="form.number_of_branches" class="control" type="number" min="0" /></label>

          <label class="ad-field"><span>Employees</span>
            <input v-model.number="form.number_of_employees" class="control" type="number" min="0" /></label>

          <label class="ad-field ad-span"><span>Source of funds</span>
            <input v-model="form.fund_source" class="control" placeholder="Donor funded, treasury, private" /></label>

          <label class="ad-field ad-span"><span>Notes</span>
            <textarea v-model="form.details" class="control" rows="4"></textarea></label>
        </div>

        <div class="ad-actions">
          <button class="btn-primary" :disabled="saving" @click="saveProfile">
            {{ saving ? "Saving…" : "Save changes" }}
          </button>
        </div>
      </section>

      <!-- ── Contacts ────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'contacts'" class="ad-panel">
        <div class="ad-panel-head">
          <p class="ad-note">Who to call. One person is the primary contact.</p>
          <button class="btn-ghost" @click="contactForm = blankContact(); showContact = true">+ Contact</button>
        </div>

        <p v-if="!account.contacts?.length" class="ad-none">No contacts recorded.</p>
        <table v-else class="ad-table">
          <thead><tr><th>Name</th><th>Role</th><th>Email</th><th>Phone</th><th></th></tr></thead>
          <tbody>
            <tr v-for="c in account.contacts" :key="c.id">
              <td>
                {{ c.full_name }}
                <span v-if="c.is_primary" class="pill pill-brand">primary</span>
              </td>
              <td>{{ c.designation ?? "—" }}<template v-if="c.department"> · {{ c.department }}</template></td>
              <td>{{ c.email ?? "—" }}</td>
              <td>{{ c.phone ?? "—" }}</td>
              <td class="ad-row-actions">
                <button class="link-btn" @click="editContact(c)">Edit</button>
                <button class="link-btn link-danger" @click="removeContact(c)">Remove</button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- ── Team ────────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'team'" class="ad-panel">
        <p class="ad-note">
          Ours, not theirs. The account manager owns the relationship; these are the
          people who work it day to day.
        </p>

        <div class="ad-add-row">
          <div class="ad-add-picker">
            <EmployeePicker v-model="newMember.employee_id" placeholder="Add somebody…" />
          </div>
          <input v-model="newMember.role" class="control ad-add-role" placeholder="What they do here (optional)" />
          <button class="btn-ghost" :disabled="saving || !newMember.employee_id" @click="addMember">Add</button>
        </div>

        <p v-if="!account.team?.length" class="ad-none">Nobody assigned yet.</p>
        <table v-else class="ad-table">
          <thead><tr><th>Person</th><th>Role on this account</th><th></th></tr></thead>
          <tbody>
            <tr v-for="m in account.team" :key="m.id">
              <td>{{ m.employee.name ?? "—" }}</td>
              <td>{{ m.role ?? "—" }}</td>
              <td class="ad-row-actions">
                <button class="link-btn link-danger" @click="removeMember(m)">Remove</button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- ── Contracts ───────────────────────────────────────────────── -->
      <section v-else class="ad-panel">
        <div class="ad-panel-head">
          <p class="ad-note">What we have agreed to do, and until when.</p>
          <button class="btn-ghost" @click="contractForm = blankContract(); showContract = true">+ Contract</button>
        </div>

        <p v-if="!account.contracts?.length" class="ad-none">No contracts recorded.</p>
        <table v-else class="ad-table">
          <thead><tr><th>Contract</th><th>Type</th><th class="ad-num">Value</th><th>Period</th><th>Standing</th><th></th></tr></thead>
          <tbody>
            <tr v-for="c in account.contracts" :key="c.id">
              <td>
                {{ c.name }}
                <span v-if="c.contract_number" class="ad-meta">{{ c.contract_number }}</span>
              </td>
              <td>{{ c.type ?? "—" }}</td>
              <td class="ad-num">{{ money(c.contract_value) }}</td>
              <td>{{ c.start_date }} → {{ c.end_date }}</td>
              <td><span :class="['pill', `pill-${expiry(c).tone}`]">{{ expiry(c).label }}</span></td>
              <td class="ad-row-actions">
                <button class="link-btn" @click="editContract(c)">Edit</button>
                <button class="link-btn link-danger" @click="removeContract(c)">Remove</button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>

    <!-- Contact dialog -->
    <div v-if="showContact" class="ad-modal-backdrop" @click.self="showContact = false">
      <div class="ad-modal">
        <h3 class="ad-modal-title">{{ contactForm.id ? "Edit contact" : "New contact" }}</h3>
        <div class="ad-grid">
          <label class="ad-field"><span>Title</span>
            <input v-model="contactForm.title" class="control" placeholder="Mr, Dr, Eng" /></label>
          <label class="ad-field"><span>Designation</span>
            <input v-model="contactForm.designation" class="control" placeholder="Head of ICT" /></label>
          <label class="ad-field"><span>First name</span>
            <input v-model="contactForm.first_name" class="control" /></label>
          <label class="ad-field"><span>Last name</span>
            <input v-model="contactForm.last_name" class="control" /></label>
          <label class="ad-field"><span>Department</span>
            <input v-model="contactForm.department" class="control" /></label>
          <label class="ad-field"><span>Email</span>
            <input v-model="contactForm.email" class="control" type="email" /></label>
          <label class="ad-field"><span>Phone</span>
            <input v-model="contactForm.phone" class="control" /></label>
          <label class="ad-field ad-check">
            <input v-model="contactForm.is_primary" type="checkbox" />
            <span>Primary contact</span></label>
        </div>
        <div class="ad-modal-actions">
          <button class="btn-ghost" @click="showContact = false">Cancel</button>
          <button class="btn-primary" :disabled="saving" @click="saveContact">Save</button>
        </div>
      </div>
    </div>

    <!-- Contract dialog -->
    <div v-if="showContract" class="ad-modal-backdrop" @click.self="showContract = false">
      <div class="ad-modal">
        <h3 class="ad-modal-title">{{ contractForm.id ? "Edit contract" : "New contract" }}</h3>
        <div class="ad-grid">
          <label class="ad-field ad-span"><span>Name</span>
            <input v-model="contractForm.name" class="control" placeholder="Annual support and maintenance" /></label>
          <label class="ad-field"><span>Reference</span>
            <input v-model="contractForm.contract_number" class="control" /></label>
          <label class="ad-field"><span>Type</span>
            <input v-model="contractForm.type" class="control" placeholder="Support, licence, SLA" /></label>
          <label class="ad-field"><span>Value</span>
            <input v-model.number="contractForm.contract_value" class="control" type="number" step="0.01" min="0" /></label>
          <label class="ad-field"><span>Status</span>
            <select v-model="contractForm.status" class="control">
              <option value="draft">Draft</option>
              <option value="active">Active</option>
              <option value="expired">Expired</option>
              <option value="terminated">Terminated</option>
            </select></label>
          <label class="ad-field"><span>Starts</span>
            <input v-model="contractForm.start_date" class="control" type="date" /></label>
          <label class="ad-field"><span>Ends</span>
            <input v-model="contractForm.end_date" class="control" type="date" /></label>
          <label class="ad-field ad-span"><span>Notes</span>
            <textarea v-model="contractForm.notes" class="control" rows="3"></textarea></label>
        </div>
        <div class="ad-modal-actions">
          <button class="btn-ghost" @click="showContract = false">Cancel</button>
          <button class="btn-primary" :disabled="saving" @click="saveContract">Save</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.ad-wrap { padding: var(--space-6); max-width: 68rem; }

.ad-back { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted); margin-bottom: var(--space-4); }
.ad-back:hover { color: var(--color-text); }

.ad-loading { padding: var(--space-6); color: var(--color-text-muted); }

.ad-header { margin-bottom: var(--space-4); }
.ad-title { font-size: var(--text-2xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ad-sub   { @apply flex flex-wrap items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-2) 0 0; }

.ad-tabs { @apply flex flex-wrap gap-1; border-bottom: 1px solid var(--color-border); }
.ad-tab {
  @apply cursor-pointer border-none bg-transparent;
  padding: var(--space-2) var(--space-3);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent; margin-bottom: -1px;
}
.ad-tab:hover  { color: var(--color-text); }
.ad-tab.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }
.ad-count { margin-left: var(--space-1); font-size: var(--text-xs-size); color: var(--color-text-light); }

.ad-panel { padding: var(--space-5) 0; }
.ad-panel-head { @apply flex items-center justify-between; gap: var(--space-3); margin-bottom: var(--space-4); }

.ad-grid { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4); }
.ad-span { grid-column: 1 / -1; }
@media (max-width: 40rem) { .ad-grid { grid-template-columns: 1fr; } }

.ad-field { @apply flex flex-col; gap: var(--space-1); }
.ad-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.ad-check { @apply flex-row items-center; gap: var(--space-2); align-self: end; padding-bottom: 8px; }
.ad-check span { font-size: var(--text-sm-size); color: var(--color-text); }
.ad-hint { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0; }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }
.control:disabled { @apply cursor-not-allowed; background: var(--color-surface-sunken); color: var(--color-text-light); }

.ad-actions { @apply flex justify-end; margin-top: var(--space-5); }

.ad-add-row { @apply flex flex-wrap items-start; gap: var(--space-3); margin-bottom: var(--space-4); }
.ad-add-picker { flex: 1; min-width: 16rem; }
.ad-add-role { max-width: 18rem; }

.ad-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.ad-table th {
  text-align: left; padding: var(--space-2) var(--space-3);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  text-transform: uppercase; letter-spacing: 0.04em;
  border-bottom: 1px solid var(--color-border);
}
.ad-table td { padding: var(--space-3); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.ad-num { text-align: right; font-variant-numeric: tabular-nums; }
.ad-meta { display: block; font-size: var(--text-xs-size); color: var(--color-text-light); }
.ad-row-actions { @apply flex; gap: var(--space-3); }
.ad-none { font-size: var(--text-sm-size); color: var(--color-text-light); padding: var(--space-5) 0; }
.ad-note { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; }

.pill { display: inline-block; padding: 2px var(--space-2); border-radius: 999px; font-size: var(--text-xs-size); text-transform: capitalize; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); }
.pill-danger  { background: var(--color-danger-subtle);  color: var(--color-danger-text); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); }
.pill-brand   { background: var(--color-brand-subtle);   color: var(--color-brand-on-surface); margin-left: var(--space-2); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }
.link-danger { color: var(--color-danger-text); }

.ad-modal-backdrop { @apply fixed inset-0 flex items-center justify-center z-50; background: rgb(0 0 0 / 0.45); padding: var(--space-4); }
.ad-modal {
  @apply flex flex-col w-full overflow-y-auto; max-width: 34rem; max-height: 90vh; gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised);
  border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
}
.ad-modal-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.ad-modal-actions { @apply flex justify-end; gap: var(--space-2); }

.btn-primary, .btn-ghost {
  @apply cursor-pointer; padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-ghost   { background: transparent; color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-primary:disabled, .btn-ghost:disabled { @apply cursor-not-allowed opacity-50; }

.alert-error, .alert-ok {
  padding: var(--space-3) var(--space-4); border-radius: var(--radius-md);
  font-size: var(--text-sm-size); margin-bottom: var(--space-4);
}
.alert-error { background: var(--color-danger-subtle);  color: var(--color-danger-text); }
.alert-ok    { background: var(--color-success-subtle); color: var(--color-success-text); }
</style>
