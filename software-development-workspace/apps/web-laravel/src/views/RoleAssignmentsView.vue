<script setup lang="ts">
/**
 * Role assignments.
 *
 * Roles on the left, the selected role's detail on the right — the Entra
 * layout — with the addition that model does not have: every assignment names
 * a tenant, so one identity holds different rights in different organizations.
 */
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import { can } from "../stores/permissions";

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
  permissions?: string[];
}

interface DenyRow {
  id: number;
  user: { id: number; name: string; email: string } | null;
  permission: string;
  organization: { id: number; name: string } | null;
  expires_at: string | null;
  expired: boolean;
  reason: string;
  created_by: string | null;
  created_at: string | null;
}

// ── State ────────────────────────────────────────────────────────────────────

const roles     = ref<RoleSummary[]>([]);
const selected  = ref<RoleDetail | null>(null);
const options   = ref<Options>({ organizations: [], scopes: [] });
const tab       = ref<"general" | "assigned" | "permissions">("permissions");

const router = useRouter();

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
  grantPicker.reset();
  void loadDirectory();
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

const canManage = computed(() => can("roles.manage"));

// ── The person picker ────────────────────────────────────────────────────────
//
// Assignments and denies target the LOGIN identity (a user id), which is not
// the employee id — the raw number input this replaces made people go look
// one up. The directory this screen already reads feeds a searchable list
// instead; picking a row is what fills the id.

interface DirectoryUser { id: number; name: string; email: string; status: string }

const directory       = ref<DirectoryUser[]>([]);
const directoryLoaded = ref(false);

async function loadDirectory() {
  if (directoryLoaded.value) return;
  try {
    const res = await api<{ data: DirectoryUser[] }>("GET", "/api/v1/authorization/users", { org: org() });
    directory.value = res.data ?? [];
    directoryLoaded.value = true;
  } catch { /* the dialogs degrade to keeping the field empty */ }
}

function makePicker(assign: (id: number | "") => void) {
  const query = ref("");
  const matches = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return [];
    return directory.value
      .filter(u => u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q))
      .slice(0, 8);
  });
  const pick = (u: DirectoryUser) => { assign(u.id); query.value = `${u.name} — ${u.email}`; };
  const onInput = () => assign(""); // typing again un-picks
  const reset = () => { query.value = ""; assign(""); };
  return { query, matches, pick, onInput, reset };
}

const grantPicker = makePicker(id => { grantForm.value.user_id = id; });

// ── The role editor ──────────────────────────────────────────────────────────
//
// One dialog, two modes. The matrix edits two sets that mirror the role row:
// granted strings (which may be `resource.*` wildcards) and exclusions. A
// checkbox shows the EFFECTIVE state — granted-or-covered minus excluded —
// because that is what the engine answers; unchecking a box that a wildcard
// covers writes an exclusion, which is the only honest representation of
// "everything except this".

const showEditor   = ref(false);
const editorMode   = ref<"create" | "edit">("create");
const editorRoleId = ref<number | null>(null);
const editorSaving = ref(false);
const editorError  = ref("");
const editorForm   = ref({ display_name: "", description: "", scope: "organization" });
const editorPerms  = ref<Set<string>>(new Set());
const editorExcl   = ref<Set<string>>(new Set());

/**
 * The catalogue grouped by prefix — the same rule wildcards match by, so a
 * resource row's "all" toggle and its `resource.*` string always agree.
 */
const catalogue = computed(() => {
  const by: Record<string, string[]> = {};
  for (const p of options.value.permissions ?? []) {
    const r = p.split(".")[0];
    (by[r] ??= []).push(p);
  }
  return Object.entries(by).map(([resource, perms]) => ({ resource, perms }));
});

function actionOf(p: string) { return p.split(".").slice(1).join("."); }

function effectiveIn(p: string): boolean {
  if (editorExcl.value.has(p)) return false;
  return editorPerms.value.has(p) || editorPerms.value.has(`${p.split(".")[0]}.*`);
}

function togglePerm(p: string) {
  if (effectiveIn(p)) {
    if (editorPerms.value.has(p)) editorPerms.value.delete(p);
    else editorExcl.value.add(p); // covered by a wildcard — exclude it
  } else {
    if (editorExcl.value.has(p)) editorExcl.value.delete(p);
    else editorPerms.value.add(p);
  }
}

function allOn(perms: string[]): boolean {
  return perms.every(effectiveIn);
}

function toggleAll(resource: string, perms: string[]) {
  const wildcard = `${resource}.*`;
  const clear = () => {
    editorPerms.value.delete(wildcard);
    for (const p of perms) { editorPerms.value.delete(p); editorExcl.value.delete(p); }
  };
  if (allOn(perms)) clear();
  else { clear(); editorPerms.value.add(wildcard); }
}

function openCreate() {
  editorMode.value = "create"; editorRoleId.value = null;
  editorForm.value = { display_name: "", description: "", scope: "organization" };
  editorPerms.value = new Set(); editorExcl.value = new Set();
  editorError.value = ""; showEditor.value = true;
}

function openEdit() {
  if (!selected.value) return;
  editorMode.value = "edit"; editorRoleId.value = selected.value.id;
  editorForm.value = {
    display_name: selected.value.display_name,
    description: selected.value.description ?? "",
    scope: selected.value.scope,
  };
  editorPerms.value = new Set(selected.value.permissions);
  editorExcl.value = new Set(selected.value.excluded_permissions);
  editorError.value = ""; showEditor.value = true;
}

async function saveEditor() {
  editorSaving.value = true; editorError.value = "";
  const body = {
    display_name: editorForm.value.display_name,
    description: editorForm.value.description || null,
    scope: editorForm.value.scope,
    assignable_scopes: editorMode.value === "edit" ? (selected.value?.assignable_scopes ?? null) : null,
    permissions: [...editorPerms.value],
    excluded_permissions: [...editorExcl.value],
  };
  try {
    let id = editorRoleId.value;
    if (editorMode.value === "create") {
      const res = await api<{ data: { id: number } }>("POST", "/api/v1/authorization/roles", { org: org(), body });
      id = res.data.id;
    } else {
      await api("PUT", `/api/v1/authorization/roles/${id}`, { org: org(), body });
    }
    showEditor.value = false;
    await loadRoles();
    const row = roles.value.find(r => r.id === id);
    if (row) await selectRole(row);
  } catch (e) {
    editorError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not save the role.";
  } finally {
    editorSaving.value = false;
  }
}

async function cloneRole() {
  if (!selected.value) return;
  const name = window.prompt(
    `Clone "${selected.value.display_name}" as:`,
    `${selected.value.display_name} (copy)`,
  );
  if (!name) return;
  try {
    const res = await api<{ data: { id: number } }>(
      "POST", `/api/v1/authorization/roles/${selected.value.id}/clone`,
      { org: org(), body: { display_name: name } },
    );
    await loadRoles();
    const copy = roles.value.find(r => r.id === res.data.id);
    if (copy) { await selectRole(copy); openEdit(); }
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Could not clone the role.";
  }
}

async function deleteRole() {
  if (!selected.value) return;
  if (!window.confirm(`Delete the role "${selected.value.display_name}"? This cannot be undone.`)) return;
  try {
    await api("DELETE", `/api/v1/authorization/roles/${selected.value.id}`, { org: org() });
    selected.value = null;
    await loadRoles();
  } catch (e) {
    // 422 carries the useful message ("3 people still hold this role…").
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not delete the role.";
  }
}

// ── Denies ───────────────────────────────────────────────────────────────────

const topTab        = ref<"roles" | "denies">("roles");
const denies        = ref<DenyRow[]>([]);
const loadingDenies = ref(false);
const deniesLoaded  = ref(false);

const showDeny  = ref(false);
const denying   = ref(false);
const denyError = ref("");
const denyForm  = ref({
  user_id: "" as string | number,
  permission: "",
  organization_id: "" as string | number,
  expires_at: "",
  reason: "",
  confirm_wildcard: false,
});

const denyIsWildcard = computed(() => denyForm.value.permission.includes("*"));

const denyPicker = makePicker(id => { denyForm.value.user_id = id; });

/** Strings the deny dialog offers: every concrete permission, every resource wildcard, and the full veto. */
const denyChoices = computed(() => [
  ...(options.value.permissions ?? []),
  ...catalogue.value.map(g => `${g.resource}.*`),
  "*",
]);

async function loadDenies() {
  loadingDenies.value = true;
  try {
    const res = await api<{ data: DenyRow[] }>("GET", "/api/v1/authorization/denies", { org: org() });
    denies.value = res.data ?? [];
    deniesLoaded.value = true;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load denies.";
  } finally {
    loadingDenies.value = false;
  }
}

watch(topTab, (t) => { if (t === "denies" && !deniesLoaded.value) void loadDenies(); });

function openDeny() {
  denyError.value = "";
  denyPicker.reset();
  void loadDirectory();
  denyForm.value = { user_id: "", permission: "", organization_id: "", expires_at: "", reason: "", confirm_wildcard: false };
  showDeny.value = true;
}

async function submitDeny() {
  denying.value = true; denyError.value = "";
  try {
    await api("POST", "/api/v1/authorization/denies", {
      org: org(),
      body: {
        user_id: Number(denyForm.value.user_id),
        permission: denyForm.value.permission.trim(),
        organization_id: denyForm.value.organization_id === "" ? null : Number(denyForm.value.organization_id),
        expires_at: denyForm.value.expires_at || null,
        reason: denyForm.value.reason,
        confirm_wildcard: denyForm.value.confirm_wildcard,
      },
    });
    showDeny.value = false;
    await loadDenies();
  } catch (e) {
    denyError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not place the deny.";
  } finally {
    denying.value = false;
  }
}

async function liftDeny(d: DenyRow) {
  if (!window.confirm(`Lift the deny of "${d.permission}" on ${d.user?.name ?? "this user"}?`)) return;
  try {
    await api("DELETE", `/api/v1/authorization/denies/${d.id}`, { org: org() });
    await loadDenies();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Could not lift the deny.";
  }
}

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

    <!-- Three registers: what a login may do, what it is forbidden despite
         its roles, and who it belongs to. -->
    <nav class="ra-tabs">
      <button :class="['ra-tab', { active: topTab === 'roles' }]" @click="topTab = 'roles'">Roles</button>
      <button :class="['ra-tab', { active: topTab === 'denies' }]" @click="topTab = 'denies'">Denies</button>
      <button class="ra-tab" @click="router.push({ name: 'user-directory' })">Users</button>
    </nav>

    <p v-if="error" class="alert" role="alert">{{ error }}</p>

    <!-- ── Denies ─────────────────────────────────────────────────────── -->
    <section v-if="topTab === 'denies'" class="panel">
      <div class="panel-head detail-head">
        <div>
          <h2>Deny assignments</h2>
          <p class="muted">
            A deny beats every grant, whatever roles the person holds. Each one
            records who placed it and why.
          </p>
        </div>
        <button v-if="canManage" class="btn-primary" @click="openDeny">Place a deny</button>
      </div>

      <p v-if="loadingDenies" class="muted pad">Loading…</p>
      <p v-else-if="!denies.length" class="muted pad">No denies are in force.</p>

      <table v-else class="tbl">
        <thead>
          <tr><th>Identity</th><th>Permission</th><th>Organisation</th><th>Expires</th><th>Reason</th><th>Placed by</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="d in denies" :key="d.id" :class="{ 'is-past': d.expired }">
            <td>
              <div class="role-name">{{ d.user?.name ?? "—" }}</div>
              <div class="role-desc">{{ d.user?.email }}</div>
            </td>
            <td><code>{{ d.permission }}</code></td>
            <td>{{ d.organization?.name ?? "All organisations" }}</td>
            <td>
              <span v-if="d.expired" class="pill">Expired</span>
              <span v-else>{{ d.expires_at ?? "Never" }}</span>
            </td>
            <td>{{ d.reason }}</td>
            <td>{{ d.created_by ?? "—" }}</td>
            <td class="num">
              <button v-if="canManage && !d.expired" class="btn-link" @click="liftDeny(d)">Lift</button>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <div v-else class="ra-split">
      <!-- ── Roles ─────────────────────────────────────────────────────── -->
      <section class="panel roles">
        <div class="panel-head detail-head">
          <h2>Roles</h2>
          <button v-if="canManage" class="btn-secondary" @click="openCreate">New role</button>
        </div>

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
          <div class="head-actions">
            <!-- Built-ins are templates: the write path is clone-then-edit. -->
            <button v-if="canManage" class="btn-secondary" @click="cloneRole">Clone</button>
            <button v-if="canManage && !selected.is_system" class="btn-secondary" @click="openEdit">Edit</button>
            <button
              v-if="canManage && !selected.is_system && !activeHolders.length"
              class="btn-danger" @click="deleteRole"
            >Delete</button>
            <button class="btn-primary" @click="openGrant">Assign</button>
          </div>
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

    <!-- ── Role editor ─────────────────────────────────────────────────── -->
    <div v-if="showEditor" class="overlay" @click.self="showEditor = false">
      <div class="dialog dialog-wide" role="dialog" aria-modal="true" aria-label="Role editor">
        <h2>{{ editorMode === "create" ? "New role" : `Edit ${editorForm.display_name}` }}</h2>

        <div class="editor-fields">
          <label class="field">
            <span>Name</span>
            <input v-model="editorForm.display_name" type="text" class="control" placeholder="e.g. Records Clerk" />
          </label>
          <label class="field">
            <span>Default scope</span>
            <select v-model="editorForm.scope" class="control">
              <option v-for="s in options.scopes" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </label>
        </div>
        <label class="field">
          <span>Description <span class="muted">(optional)</span></span>
          <input v-model="editorForm.description" type="text" class="control" placeholder="What this role is for" />
        </label>

        <p class="muted">
          A ticked box is what a holder can actually do. Ticking a whole resource
          grants its wildcard, so permissions added to the catalogue later are
          included; unticking one action under it records an exclusion.
        </p>

        <div class="matrix">
          <fieldset v-for="g in catalogue" :key="g.resource" class="mx-group">
            <legend class="mx-head">
              <label class="mx-all">
                <input
                  type="checkbox"
                  :checked="allOn(g.perms)"
                  @change="toggleAll(g.resource, g.perms)"
                />
                <strong>{{ g.resource }}</strong>
              </label>
              <code v-if="editorPerms.has(`${g.resource}.*`)" class="mx-wild">{{ g.resource }}.*</code>
            </legend>
            <div class="mx-perms">
              <label v-for="p in g.perms" :key="p" class="mx-perm">
                <input type="checkbox" :checked="effectiveIn(p)" @change="togglePerm(p)" />
                <span>{{ actionOf(p) }}</span>
                <span v-if="editorExcl.has(p)" class="pill pill-warn">Excluded</span>
              </label>
            </div>
          </fieldset>
        </div>

        <p v-if="editorError" class="alert" role="alert">{{ editorError }}</p>

        <div class="dialog-actions">
          <button class="btn-secondary" @click="showEditor = false">Cancel</button>
          <button
            class="btn-primary"
            :disabled="editorSaving || !editorForm.display_name.trim()"
            @click="saveEditor"
          >
            {{ editorSaving ? "Saving…" : (editorMode === "create" ? "Create role" : "Save changes") }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Deny dialog ─────────────────────────────────────────────────── -->
    <div v-if="showDeny" class="overlay" @click.self="showDeny = false">
      <div class="dialog" role="dialog" aria-modal="true" aria-label="Place a deny">
        <h2>Place a deny</h2>

        <label class="field">
          <span>Person</span>
          <div class="picker">
            <input
              v-model="denyPicker.query.value" type="text" class="control"
              placeholder="Search by name or email…"
              @input="denyPicker.onInput()"
            />
            <ul v-if="denyPicker.matches.value.length && !denyForm.user_id" class="picker-list">
              <li v-for="u in denyPicker.matches.value" :key="u.id">
                <button type="button" class="picker-row" @click="denyPicker.pick(u)">
                  <span class="picker-name">{{ u.name }}</span>
                  <span class="picker-mail">{{ u.email }}</span>
                </button>
              </li>
            </ul>
          </div>
        </label>

        <label class="field">
          <span>Permission</span>
          <input v-model="denyForm.permission" type="text" class="control" list="deny-permissions" placeholder="e.g. payroll.view, payroll.*, or *" />
          <datalist id="deny-permissions">
            <option v-for="p in denyChoices" :key="p" :value="p" />
          </datalist>
        </label>

        <label class="field">
          <span>Organisation</span>
          <select v-model="denyForm.organization_id" class="control">
            <option value="">All organisations</option>
            <option v-for="o in options.organizations" :key="o.id" :value="o.id">{{ o.name }}</option>
          </select>
        </label>

        <label class="field">
          <span>Expires <span class="muted">(optional)</span></span>
          <input v-model="denyForm.expires_at" type="date" class="control" />
        </label>

        <label class="field">
          <span>Reason</span>
          <input v-model="denyForm.reason" type="text" class="control" placeholder="Required — recorded against the deny" />
        </label>

        <label v-if="denyIsWildcard" class="confirm">
          <input v-model="denyForm.confirm_wildcard" type="checkbox" />
          <span>
            This is a wildcard — it closes <em>every</em> permission it matches.
            <template v-if="denyForm.permission.trim() === '*'">A bare <code>*</code> is a soft account-disable.</template>
          </span>
        </label>

        <p v-if="denyError" class="alert" role="alert">{{ denyError }}</p>

        <div class="dialog-actions">
          <button class="btn-secondary" @click="showDeny = false">Cancel</button>
          <button
            class="btn-primary"
            :disabled="denying || !denyForm.user_id || !denyForm.permission.trim() || !denyForm.reason.trim() || (denyIsWildcard && !denyForm.confirm_wildcard)"
            @click="submitDeny"
          >
            {{ denying ? "Placing…" : "Place deny" }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Grant dialog ────────────────────────────────────────────────── -->
    <div v-if="showGrant" class="overlay" @click.self="showGrant = false">
      <div class="dialog" role="dialog" aria-modal="true" aria-label="Assign role">
        <h2>Assign {{ selected?.display_name }}</h2>

        <!--
          Targets the login identity (a user id), not an employee record —
          the directory search resolves the right id so nobody has to.
        -->
        <label class="field">
          <span>Person</span>
          <div class="picker">
            <input
              v-model="grantPicker.query.value" type="text" class="control"
              placeholder="Search by name or email…"
              @input="grantPicker.onInput()"
            />
            <ul v-if="grantPicker.matches.value.length && !grantForm.user_id" class="picker-list">
              <li v-for="u in grantPicker.matches.value" :key="u.id">
                <button type="button" class="picker-row" @click="grantPicker.pick(u)">
                  <span class="picker-name">{{ u.name }}</span>
                  <span class="picker-mail">{{ u.email }}</span>
                  <span v-if="u.status !== 'active' && u.status !== 'pending'" class="pill">{{ u.status }}</span>
                </button>
              </li>
            </ul>
          </div>
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

/* ── Person picker ───────────────────────────────────────────────────────── */
.picker { position: relative; }

.picker-list {
  @apply absolute left-0 right-0 z-10 list-none overflow-y-auto;
  top: calc(100% + 4px); max-height: 16rem;
  margin: 0; padding: var(--space-1);
  background: var(--color-surface);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-lg);
}

.picker-row {
  @apply flex w-full cursor-pointer items-center border-none bg-transparent text-left;
  gap: var(--space-2);
  font-family: inherit;
  font-size: var(--text-sm-size);
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-sm);
  color: var(--color-text);
}
.picker-row:hover, .picker-row:focus-visible { background: var(--color-surface-sunken); }

.picker-name { @apply font-medium; white-space: nowrap; }
.picker-mail {
  @apply overflow-hidden text-ellipsis whitespace-nowrap;
  font-size: var(--text-xs-size);
  color: var(--color-text-light);
}

/* ── Role editor matrix ──────────────────────────────────────────────────── */
.dialog-wide { max-width: 46rem; max-height: 88vh; overflow-y: auto; }

.editor-fields { @apply grid; grid-template-columns: 1fr max-content; gap: var(--space-3); }

.head-actions { @apply flex flex-wrap justify-end; gap: var(--space-2); }

.btn-danger {
  @apply cursor-pointer font-medium;
  font-family: inherit;
  font-size: var(--text-sm-size);
  padding: 8px var(--space-4);
  border-radius: var(--radius-md);
  border: 1px solid var(--color-error);
  background: var(--color-surface);
  color: var(--color-error);
}

.matrix { margin-top: var(--space-3); }

.mx-group {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-3);
  margin: 0 0 var(--space-3);
}

.mx-head { @apply flex items-center; gap: var(--space-2); padding: 0 var(--space-1); }
.mx-all  { @apply flex cursor-pointer items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }
.mx-wild { font-size: var(--text-xs-size); }

.mx-perms {
  @apply grid;
  grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
  gap: var(--space-1) var(--space-3);
  margin-top: var(--space-2);
}

.mx-perm {
  @apply flex cursor-pointer items-center;
  gap: var(--space-2);
  font-size: var(--text-sm-size);
  color: var(--color-text);
  padding: 2px 0;
}

.confirm {
  @apply flex cursor-pointer items-start;
  gap: var(--space-2);
  font-size: var(--text-xs-size);
  color: var(--color-text);
  background: var(--color-warning-light);
  border-radius: var(--radius-sm);
  padding: var(--space-3);
  margin-bottom: var(--space-3);
}
.confirm input { margin-top: 2px; }

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
.ra-tabs { @apply flex; gap: var(--space-1); margin-bottom: var(--space-5);
  border-bottom: 1px solid var(--color-border); }
.ra-tab {
  @apply cursor-pointer border-none bg-transparent;
  padding: var(--space-2) var(--space-3); margin-bottom: -1px;
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent;
}
.ra-tab:hover { color: var(--color-text); }
.ra-tab.active { color: var(--color-text); font-weight: 600; border-bottom-color: var(--color-brand-base); }
</style>
