<script setup lang="ts">
/**
 * Role assignments.
 *
 * Roles on the left, the selected role's detail on the right — the Entra
 * layout — with the addition that model does not have: every assignment names
 * a tenant, so one identity holds different rights in different organizations.
 */
import { ref, computed, onMounted } from "vue";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

// ── Types (mirror the API resources) ─────────────────────────────────────────

interface RoleSummary {
  id: number;
  name: string;
  display_name: string;
  description: string | null;
  scope: string;
  is_system: boolean;
  permissions: string[];
  excluded_permissions: string[];
  assignable_scopes: string[] | null;
  holders_count: number;
}

type PermissionState = "granted" | "excluded" | "not_granted";

interface PermissionGroup {
  resource: string;
  permissions: { permission: string; state: PermissionState }[];
  granted_count: number;
  total: number;
}

interface Holder {
  id: number;
  user: { id: number; name: string; email: string; account_status: string };
  organization: { id: number; name: string } | null;
  scope: string;
  valid_until: string | null;
  state: "active" | "revoked" | "lapsed" | "scheduled";
}

interface RoleDetail extends RoleSummary {
  permission_groups: PermissionGroup[];
  holders: Holder[];
}

interface Options {
  organizations: { id: number; name: string; slug: string }[];
  scopes: { value: string; label: string }[];
}

// ── State ────────────────────────────────────────────────────────────────────

const roles     = ref<RoleSummary[]>([]);
const selected  = ref<RoleDetail | null>(null);
const options   = ref<Options>({ organizations: [], scopes: [] });
const tab       = ref<"general" | "assigned" | "permissions">("permissions");

const loading      = ref(false);
const loadingRole  = ref(false);
const error        = ref("");

const showGrant  = ref(false);
const granting   = ref(false);
const grantError = ref("");
const grantForm  = ref({
  user_id: "" as string | number,
  organization_id: "" as string | number,
  scope: "organization",
  valid_until: "",
  reason: "",
});

function org() { return currentOrgSlug.value; }

// ── Loading ──────────────────────────────────────────────────────────────────

async function loadRoles() {
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: RoleSummary[] }>("GET", "/api/v1/authorization/roles", { org: org() });
    roles.value = res.data ?? [];
    if (roles.value.length && !selected.value) await selectRole(roles.value[0]);
  } catch (e) {
    error.value = e instanceof ApiError && e.status === 403
      ? "You do not have permission to view role assignments."
      : (e instanceof Error ? e.message : "Failed to load roles.");
  } finally {
    loading.value = false;
  }
}

async function loadOptions() {
  try {
    const res = await api<{ data: Options }>("GET", "/api/v1/authorization/options", { org: org() });
    options.value = res.data;
  } catch { /* the grant dialog degrades to free entry */ }
}

async function selectRole(role: RoleSummary) {
  loadingRole.value = true;
  try {
    const res = await api<{ data: RoleDetail }>("GET", `/api/v1/authorization/roles/${role.id}`, { org: org() });
    selected.value = res.data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load the role.";
  } finally {
    loadingRole.value = false;
  }
}

// ── Granting and revoking ────────────────────────────────────────────────────

function openGrant() {
  grantError.value = "";
  grantForm.value = {
    user_id: "",
    organization_id: options.value.organizations[0]?.id ?? "",
    scope: "organization",
    valid_until: "",
    reason: "",
  };
  showGrant.value = true;
}

async function submitGrant() {
  if (!selected.value) return;
  granting.value = true; grantError.value = "";
  try {
    await api("POST", "/api/v1/authorization/assignments", {
      org: org(),
      body: {
        user_id: Number(grantForm.value.user_id),
        role_id: selected.value.id,
        organization_id: grantForm.value.scope === "group"
          ? null
          : Number(grantForm.value.organization_id),
        scope: grantForm.value.scope,
        valid_until: grantForm.value.valid_until || null,
        reason: grantForm.value.reason || null,
      },
    });
    showGrant.value = false;
    await selectRole(selected.value);
    await loadRoles();
  } catch (e) {
    // The API returns 422 with a readable reason when a grant would exceed
    // the granter's own rights — surface it rather than a generic failure.
    grantError.value = e instanceof ApiError
      ? (e.firstError ?? e.message)
      : "Could not grant the role.";
  } finally {
    granting.value = false;
  }
}

async function revoke(holder: Holder) {
  if (!selected.value) return;
  const reason = window.prompt(`Revoke ${selected.value.display_name} from ${holder.user.name}?\n\nReason (optional):`);
  if (reason === null) return;
  try {
    await api("DELETE", `/api/v1/authorization/assignments/${holder.id}`, {
      org: org(), body: { reason: reason || null },
    });
    await selectRole(selected.value);
    await loadRoles();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Could not revoke the assignment.";
  }
}

// ── Derived ──────────────────────────────────────────────────────────────────

const activeHolders = computed(
  () => selected.value?.holders.filter(h => h.state === "active") ?? []
);

const pastHolders = computed(
  () => selected.value?.holders.filter(h => h.state !== "active") ?? []
);

function scopeLabel(s: string) { return s.charAt(0).toUpperCase() + s.slice(1); }

onMounted(async () => { await Promise.all([loadRoles(), loadOptions()]); });
</script>

<template>
  <div class="ra">
    <header class="ra-head">
      <h1>Role assignments</h1>
      <p class="lead">
        Assign roles to identities. Each assignment names an organisation, so the same
        person can hold different rights in different tenants.
      </p>
    </header>

    <p v-if="error" class="alert" role="alert">{{ error }}</p>

    <div class="ra-split">
      <!-- ── Roles ─────────────────────────────────────────────────────── -->
      <section class="panel roles">
        <div class="panel-head"><h2>Roles</h2></div>

        <p v-if="loading" class="muted pad">Loading…</p>

        <table v-else class="tbl">
          <thead>
            <tr><th>Name</th><th>Scope</th><th class="num">Holders</th></tr>
          </thead>
          <tbody>
            <tr
              v-for="r in roles" :key="r.id"
              :class="{ 'is-selected': selected?.id === r.id }"
              tabindex="0"
              @click="selectRole(r)"
              @keydown.enter="selectRole(r)"
            >
              <td>
                <div class="role-name">
                  {{ r.display_name }}
                  <span v-if="r.is_system" class="tag">Built-in</span>
                </div>
                <div class="role-desc">{{ r.description || r.name }}</div>
              </td>
              <td>{{ scopeLabel(r.scope) }}</td>
              <td class="num">{{ r.holders_count }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- ── Detail ────────────────────────────────────────────────────── -->
      <section class="panel detail" v-if="selected">
        <div class="panel-head detail-head">
          <div>
            <h2>{{ selected.display_name }}</h2>
            <p class="muted">{{ selected.description || selected.name }}</p>
          </div>
          <button class="btn-primary" @click="openGrant">Assign</button>
        </div>

        <nav class="tabs" role="tablist">
          <button
            v-for="t in (['general','assigned','permissions'] as const)" :key="t"
            :class="['tab', { active: tab === t }]"
            role="tab" :aria-selected="tab === t"
            @click="tab = t"
          >
            {{ t === 'general' ? 'General' : t === 'assigned' ? 'Assigned' : 'Permissions' }}
          </button>
        </nav>

        <p v-if="loadingRole" class="muted pad">Loading…</p>

        <!-- General -->
        <div v-else-if="tab === 'general'" class="pad">
          <dl class="facts">
            <dt>Identifier</dt><dd><code>{{ selected.name }}</code></dd>
            <dt>Type</dt><dd>{{ selected.is_system ? "Built-in" : "Custom" }}</dd>
            <dt>Default scope</dt><dd>{{ scopeLabel(selected.scope) }}</dd>
            <dt>Assignable at</dt>
            <dd>
              <span v-if="!selected.assignable_scopes?.length">Any scope</span>
              <span v-else>{{ selected.assignable_scopes.map(scopeLabel).join(", ") }}</span>
            </dd>
            <dt>Holders</dt><dd>{{ activeHolders.length }} active</dd>
          </dl>
          <p v-if="selected.is_system" class="note">
            Built-in roles cannot be edited. Create a custom role to vary these permissions.
          </p>
        </div>

        <!-- Assigned -->
        <div v-else-if="tab === 'assigned'" class="pad">
          <p v-if="!selected.holders.length" class="muted">Nobody holds this role.</p>

          <table v-else class="tbl">
            <thead>
              <tr><th>Identity</th><th>Organisation</th><th>Scope</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="h in activeHolders" :key="h.id">
                <td>
                  <div class="role-name">{{ h.user.name }}</div>
                  <div class="role-desc">{{ h.user.email }}</div>
                </td>
                <td>{{ h.organization?.name ?? "All organisations" }}</td>
                <td>{{ scopeLabel(h.scope) }}</td>
                <td><span class="pill pill-ok">Active</span></td>
                <td class="num"><button class="btn-link" @click="revoke(h)">Revoke</button></td>
              </tr>

              <!-- Retained rather than deleted, so past access stays answerable. -->
              <tr v-for="h in pastHolders" :key="h.id" class="is-past">
                <td>
                  <div class="role-name">{{ h.user.name }}</div>
                  <div class="role-desc">{{ h.user.email }}</div>
                </td>
                <td>{{ h.organization?.name ?? "All organisations" }}</td>
                <td>{{ scopeLabel(h.scope) }}</td>
                <td><span class="pill">{{ scopeLabel(h.state) }}</span></td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Permissions -->
        <div v-else class="pad">
          <p class="muted">
            What this role can do, grouped by resource. An exclusion overrides a
            wildcard, so a role may grant a whole resource except one action.
          </p>

          <div v-for="g in selected.permission_groups" :key="g.resource" class="perm-group">
            <div class="perm-head">
              <h3>{{ g.resource }}</h3>
              <span class="muted">{{ g.granted_count }} of {{ g.total }}</span>
            </div>
            <ul class="perm-list">
              <li v-for="p in g.permissions" :key="p.permission" :class="p.state">
                <span class="mark" aria-hidden="true">
                  {{ p.state === "granted" ? "✓" : p.state === "excluded" ? "✕" : "–" }}
                </span>
                <code>{{ p.permission }}</code>
                <span v-if="p.state === 'excluded'" class="pill pill-warn">Excluded</span>
              </li>
            </ul>
          </div>
        </div>
      </section>
    </div>

    <!-- ── Grant dialog ────────────────────────────────────────────────── -->
    <div v-if="showGrant" class="overlay" @click.self="showGrant = false">
      <div class="dialog" role="dialog" aria-modal="true" aria-label="Assign role">
        <h2>Assign {{ selected?.display_name }}</h2>

        <label class="field">
          <span>Identity (user ID)</span>
          <input v-model="grantForm.user_id" type="number" class="control" placeholder="e.g. 12" />
        </label>

        <label class="field">
          <span>Scope</span>
          <select v-model="grantForm.scope" class="control">
            <option v-for="s in options.scopes" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </label>

        <label class="field" v-if="grantForm.scope !== 'group'">
          <span>Organisation</span>
          <select v-model="grantForm.organization_id" class="control">
            <option v-for="o in options.organizations" :key="o.id" :value="o.id">{{ o.name }}</option>
          </select>
        </label>
        <p v-else class="note">Group scope applies in every organisation.</p>

        <label class="field">
          <span>Expires <span class="muted">(optional)</span></span>
          <input v-model="grantForm.valid_until" type="date" class="control" />
        </label>

        <label class="field">
          <span>Reason <span class="muted">(optional)</span></span>
          <input v-model="grantForm.reason" type="text" class="control" placeholder="Recorded against the grant" />
        </label>

        <p v-if="grantError" class="alert" role="alert">{{ grantError }}</p>

        <div class="dialog-actions">
          <button class="btn-secondary" @click="showGrant = false">Cancel</button>
          <button class="btn-primary" :disabled="granting || !grantForm.user_id" @click="submitGrant">
            {{ granting ? "Assigning…" : "Assign" }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Built on the Konvergenz tokens — see docs/design-system.md. No literal
   colours: status is carried by pills whose text/background pairs were
   chosen to clear the contrast floor for small text. */
@reference "../theme.css";

.ra { padding: var(--space-6); }

.ra-head h1 {
  @apply font-semibold;
  font-size: var(--display-xs-size);
  line-height: var(--display-xs-line);
  color: var(--color-text);
  margin: 0;
}

.lead {
  font-size: var(--text-sm-size);
  color: var(--color-text-light);
  margin: var(--space-2) 0 var(--space-6);
  max-width: 68ch;
}

.ra-split {
  @apply grid items-start;
  gap: var(--space-4);
  grid-template-columns: minmax(0, 4fr) minmax(0, 6fr);
}

@media (max-width: 1100px) { .ra-split { grid-template-columns: 1fr; } }

.panel {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  overflow: hidden;
}

.panel-head {
  padding: var(--space-4);
  border-bottom: 1px solid var(--color-border);
}

.detail-head { @apply flex items-start justify-between; gap: var(--space-4); }

.panel-head h2 {
  @apply font-semibold;
  font-size: var(--text-lg-size);
  color: var(--color-text);
  margin: 0;
}

.pad { padding: var(--space-4); }
.muted { font-size: var(--text-xs-size); color: var(--color-text-light); }

/* ── Tables ──────────────────────────────────────────────────────────────── */
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

.tbl td {
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
  vertical-align: top;
}

.tbl .num { @apply text-right; font-variant-numeric: tabular-nums; }

.roles tbody tr { cursor: pointer; }
.roles tbody tr:hover { background: var(--color-surface-sunken); }

.roles tbody tr.is-selected {
  background: var(--color-brand-subtle);
  box-shadow: inset 3px 0 0 var(--color-brand-base);
}

.roles tbody tr:focus-visible {
  @apply outline-2 outline-offset-[-2px];
  outline-color: var(--color-brand-base);
}

.role-name { @apply font-medium; color: var(--color-text); }
.role-desc { font-size: var(--text-xs-size); color: var(--color-text-light); margin-top: 2px; }

.is-past { opacity: 0.6; }

/* ── Tabs ────────────────────────────────────────────────────────────────── */
.tabs { @apply flex; gap: var(--space-4); padding: 0 var(--space-4); border-bottom: 1px solid var(--color-border); }

.tab {
  @apply cursor-pointer border-none bg-transparent font-medium;
  font-family: inherit;
  font-size: var(--text-sm-size);
  padding: var(--space-3) 0;
  color: var(--color-text-light);
  border-bottom: 2px solid transparent;
}

.tab.active { color: var(--color-brand-base); border-bottom-color: var(--color-brand-base); }
.tab:focus-visible { @apply outline-2 outline-offset-2; outline-color: var(--color-brand-base); }

/* ── Facts ───────────────────────────────────────────────────────────────── */
.facts { @apply grid; grid-template-columns: max-content 1fr; gap: var(--space-2) var(--space-6); margin: 0; }
.facts dt { font-size: var(--text-xs-size); color: var(--color-text-light); }
.facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }

/* ── Permissions ─────────────────────────────────────────────────────────── */
.perm-group { margin-top: var(--space-5); }

.perm-head { @apply flex items-baseline justify-between; margin-bottom: var(--space-2); }

.perm-head h3 {
  @apply font-semibold uppercase;
  font-size: var(--text-xs-size);
  letter-spacing: 0.06em;
  color: var(--color-text);
  margin: 0;
}

.perm-list { @apply list-none; margin: 0; padding: 0; }

.perm-list li {
  @apply flex items-center;
  gap: var(--space-2);
  padding: var(--space-2) 0;
  font-size: var(--text-sm-size);
  border-bottom: 1px solid var(--color-border);
}

.perm-list .mark { width: 1rem; text-align: center; color: var(--color-text-light); }
.perm-list li.granted .mark { color: var(--color-success); }
.perm-list li.excluded .mark { color: var(--color-error); }
.perm-list li.not_granted { color: var(--color-text-light); }

code {
  font-family: var(--font-family-mono);
  font-size: var(--text-xs-size);
  background: var(--color-surface-sunken);
  padding: 2px 6px;
  border-radius: var(--radius-sm);
}

/* ── Pills ───────────────────────────────────────────────────────────────── */
.pill {
  @apply inline-flex items-center rounded-full font-medium;
  padding: 2px var(--space-2);
  font-size: var(--text-xs-size);
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  border: 1px solid var(--color-border-strong);
}

/* Text darkened against the tint rather than using the base feedback colour,
   which fails the small-text contrast floor on its own light background. */
.pill-ok   { background: var(--color-success-light); color: #065F46; border-color: transparent; }
.pill-warn { background: var(--color-warning-light); color: #7C2D12; border-color: transparent; }

.tag {
  @apply ml-2 inline-flex items-center rounded font-medium;
  padding: 1px 6px;
  font-size: 11px;
  background: var(--color-brand-light);
  color: var(--color-brand-dark);
}

/* ── Buttons ─────────────────────────────────────────────────────────────── */
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
.btn-primary:disabled { @apply cursor-not-allowed opacity-60; }

.btn-secondary {
  background: var(--color-surface);
  color: var(--color-text);
  border-color: var(--color-border-strong);
}

.btn-link {
  @apply cursor-pointer border-none bg-transparent p-0 font-medium underline-offset-2;
  font-family: inherit;
  font-size: var(--text-sm-size);
  color: var(--color-error);
}
.btn-link:hover { @apply underline; }

/* ── Dialog ──────────────────────────────────────────────────────────────── */
.overlay {
  @apply fixed inset-0 z-50 flex items-center justify-center;
  background: var(--color-overlay);
  padding: var(--space-4);
}

.dialog {
  @apply w-full;
  max-width: 28rem;
  background: var(--color-surface);
  border-radius: var(--radius-2xl);
  padding: var(--space-5);
  box-shadow: var(--shadow-lg);
}

.dialog h2 {
  @apply font-semibold;
  font-size: var(--text-lg-size);
  color: var(--color-text);
  margin: 0 0 var(--space-4);
}

.field { @apply flex flex-col; gap: var(--space-1); margin-bottom: var(--space-3); }
.field > span { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text); }

.control {
  @apply w-full;
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

.dialog-actions { @apply flex justify-end; gap: var(--space-2); margin-top: var(--space-4); }

.note {
  font-size: var(--text-xs-size);
  color: var(--color-text-muted);
  background: var(--color-surface-sunken);
  border-left: 3px solid var(--color-info);
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  padding: var(--space-3);
  margin: var(--space-3) 0;
}

.alert {
  font-size: var(--text-xs-size);
  color: var(--color-error);
  background: var(--color-error-light);
  border-radius: var(--radius-sm);
  padding: var(--space-3);
  margin: 0 0 var(--space-3);
}
</style>
