<script setup lang="ts">
/**
 * The tenants and subscriptions the register reads from.
 *
 * Adding one is handling a credential that can read an entire directory, so
 * this screen is deliberately blunt about what it is asking for and what the
 * credential is allowed to do once given.
 */
import { onMounted, ref, computed } from "vue";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";

interface Subscription {
  id: number;
  subscription_id: string;
  display_name: string | null;
  state: string | null;
  is_enabled: boolean;
  resource_count: number | null;
  last_synced_at: string | null;
}

interface Connection {
  id: number;
  name: string;
  provider: string;
  tenant_id: string;
  client_id: string;
  has_secret: boolean;
  secret_expires_on: string | null;
  secret_days_left: number | null;
  secret_expiring: boolean;
  sync_licences: boolean;
  sync_azure: boolean;
  is_active: boolean;
  last_tested_at: string | null;
  last_test_status: string | null;
  last_test_message: string | null;
  granted_roles: string[];
  last_synced_at: string | null;
  subscriptions: Subscription[];
}

interface Check { ok: boolean; count: number | null; message: string | null }

interface AzurePreview {
  committed: boolean;
  to_create: { name: string; category: string; type: string }[];
  to_update: number;
  retired: number;
  untagged: number;
  skipped_types: Record<string, number>;
  skipped_total: number;
}

const connections = ref<Connection[]>([]);
const loading     = ref(false);
const error       = ref("");
const busyId      = ref<number | null>(null);
const checks      = ref<Record<number, Record<string, Check | null>>>({});

const previews = ref<Record<number, AzurePreview>>({});
const importing = ref<number | null>(null);
const imported  = ref<Record<number, string>>({});

const showForm = ref(false);
const editing  = ref<Connection | null>(null);
const saving   = ref(false);
const formError = ref("");

const blank = () => ({
  name: "", tenant_id: "", client_id: "", client_secret: "",
  secret_expires_on: "", sync_licences: true, sync_azure: false, is_active: true,
});
const form = ref(blank());

/*
 * Sent as the X-Organization header, which is what the backend middleware
 * reads. Passed as a query parameter it is silently ignored, and a group-scope
 * user then arrives with no organisation in context.
 */
const org = computed(() => currentOrgSlug.value);

async function load(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    const res = await api<{ data: Connection[] }>("GET", "/api/v1/integrations/connections", {
      org: org.value,
    });
    connections.value = res.data;
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : "Could not load connections.";
  } finally {
    loading.value = false;
  }
}

function openNew(): void {
  editing.value = null;
  form.value = blank();
  formError.value = "";
  showForm.value = true;
}

function openEdit(c: Connection): void {
  editing.value = c;
  form.value = {
    name: c.name, tenant_id: c.tenant_id, client_id: c.client_id,
    // Never populated — the API does not return it. See the hint in the form.
    client_secret: "",
    secret_expires_on: c.secret_expires_on ?? "",
    sync_licences: c.sync_licences, sync_azure: c.sync_azure, is_active: c.is_active,
  };
  formError.value = "";
  showForm.value = true;
}

async function save(): Promise<void> {
  saving.value = true;
  formError.value = "";
  try {
    const body = { ...form.value, secret_expires_on: form.value.secret_expires_on || null };
    if (editing.value) {
      await api("PUT", `/api/v1/integrations/connections/${editing.value.id}`, { body, org: org.value });
    } else {
      await api("POST", "/api/v1/integrations/connections", { body, org: org.value });
    }
    showForm.value = false;
    await load();
  } catch (err) {
    formError.value = err instanceof ApiError
      ? (err.firstError ?? err.message)
      : "Could not save.";
  } finally {
    saving.value = false;
  }
}

async function test(c: Connection): Promise<void> {
  busyId.value = c.id;
  try {
    const res = await api<{ data: Connection; checks: Record<string, Check | null> }>(
      "POST", `/api/v1/integrations/connections/${c.id}/test`, { org: org.value },
    );
    checks.value[c.id] = res.checks;
    await load();
  } catch (err) {
    error.value = err instanceof ApiError ? (err.message ?? "Test failed.") : "Test failed.";
    await load();
  } finally {
    busyId.value = null;
  }
}

async function discover(c: Connection): Promise<void> {
  busyId.value = c.id;
  try {
    await api("POST", `/api/v1/integrations/connections/${c.id}/discover`, { org: org.value });
    await load();
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : "Could not list subscriptions.";
  } finally {
    busyId.value = null;
  }
}

async function toggleSubscription(c: Connection, sub: Subscription): Promise<void> {
  const enabled = c.subscriptions
    .filter((s) => (s.id === sub.id ? !s.is_enabled : s.is_enabled))
    .map((s) => s.subscription_id);

  busyId.value = c.id;
  try {
    await api("PUT", `/api/v1/integrations/connections/${c.id}/subscriptions`, {
      body: { enabled }, org: org.value,
    });
    await load();
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : "Could not change the selection.";
  } finally {
    busyId.value = null;
  }
}

/*
 * Always previewed before it commits.
 *
 * This one writes into the asset register itself rather than a snapshot of its
 * own, so the first time somebody runs it they should see thirteen machines and
 * thirty-six skipped bits of plumbing before anything appears in their list.
 */
async function previewAzure(c: Connection): Promise<void> {
  busyId.value = c.id;
  error.value = "";
  try {
    const res = await api<{ preview: AzurePreview }>(
      "POST", `/api/v1/integrations/connections/${c.id}/azure/preview`, { org: org.value },
    );
    previews.value[c.id] = res.preview;
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : "Could not read the subscription.";
  } finally {
    busyId.value = null;
  }
}

async function importAzure(c: Connection): Promise<void> {
  importing.value = c.id;
  error.value = "";
  try {
    const res = await api<{ result: AzurePreview }>(
      "POST", `/api/v1/integrations/connections/${c.id}/azure/import`, { org: org.value },
    );
    const r = res.result;
    imported.value[c.id] = `${r.to_create.length} created, ${r.to_update} updated`
      + (r.retired ? `, ${r.retired} retired` : "") + ".";
    delete previews.value[c.id];
    await load();
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : "The import failed.";
  } finally {
    importing.value = null;
  }
}

async function remove(c: Connection): Promise<void> {
  if (!confirm(`Remove "${c.name}"? Assets already imported are left alone.`)) return;
  try {
    await api("DELETE", `/api/v1/integrations/connections/${c.id}`, { org: org.value });
    await load();
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : "Could not remove it.";
  }
}

onMounted(load);
</script>

<template>
  <div class="view view--stacked">
    <div class="view-header">
      <div>
        <h2>Connected tenants</h2>
        <p class="view-subtitle">
          Where the asset register reads licences and cloud resources from.
          Read-only — nothing here can change anything in Microsoft.
        </p>
      </div>
      <button class="btn btn-primary btn-sm" @click="openNew">+ Add tenant</button>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <p v-if="loading" class="muted">Loading…</p>

    <p v-else-if="connections.length === 0" class="empty">
      No tenants connected. Adding one needs an Entra app registration with
      <code>Organization.Read.All</code>, <code>User.Read.All</code> and
      <code>Directory.Read.All</code> granted as application permissions with
      admin consent — and, for Azure resources, the Reader role on each
      subscription.
    </p>

    <article v-for="c in connections" :key="c.id" class="card">
      <header class="card-head">
        <div>
          <h3>
            {{ c.name }}
            <span v-if="!c.is_active" class="chip chip-off">Paused</span>
          </h3>
          <p class="ids">
            <span>Tenant <code>{{ c.tenant_id }}</code></span>
            <span>App <code>{{ c.client_id }}</code></span>
          </p>
        </div>

        <div class="card-actions">
          <button class="btn btn-sm" :disabled="busyId === c.id" @click="test(c)">
            {{ busyId === c.id ? "Checking…" : "Test credentials" }}
          </button>
          <button class="btn btn-sm" @click="openEdit(c)">Edit</button>
          <button class="btn btn-sm btn-danger-ghost" @click="remove(c)">Remove</button>
        </div>
      </header>

      <!--
        The verdict is per capability, not one green tick. A tenant with the
        licence permissions and no Reader on Azure looks identical to a working
        one until a sync 403s.
      -->
      <div class="status-row">
        <span :class="['pill', c.last_test_status === 'ok' ? 'pill-ok'
                        : c.last_test_status === 'failed' ? 'pill-bad' : 'pill-idle']">
          {{ c.last_test_status === "ok" ? "Credentials good"
            : c.last_test_status === "failed" ? "Refused" : "Never tested" }}
        </span>
        <span v-if="c.last_tested_at" class="muted">checked {{ c.last_tested_at }}</span>
        <span v-if="c.last_synced_at" class="muted">· last sync {{ c.last_synced_at }}</span>
      </div>

      <p v-if="c.last_test_message" class="test-message">{{ c.last_test_message }}</p>

      <div v-if="checks[c.id]" class="checks">
        <span v-for="(chk, key) in checks[c.id]" :key="key" v-show="chk"
              :class="['check', chk!.ok ? 'check-ok' : 'check-bad']">
          {{ key }}: {{ chk!.ok ? `${chk!.count} read` : "refused" }}
        </span>
      </div>

      <p v-if="c.granted_roles.length" class="roles">
        <span class="roles-label">Permissions held</span>
        <code v-for="r in c.granted_roles" :key="r">{{ r }}</code>
      </p>

      <!--
        A secret that expires quietly stops the sync and nothing announces it.
        The register then goes stale, which is the failure this module exists
        to prevent.
      -->
      <p v-if="c.secret_expiring" class="warn">
        <template v-if="(c.secret_days_left ?? 0) < 0">
          The client secret expired {{ Math.abs(c.secret_days_left!) }} days ago. The sync is not running.
        </template>
        <template v-else>
          The client secret expires in {{ c.secret_days_left }} days. Rotate it in Entra and
          paste the new one here before it does.
        </template>
      </p>

      <div class="scope-row">
        <span :class="['chip', c.sync_licences ? 'chip-on' : 'chip-off']">Microsoft 365 licences</span>
        <span :class="['chip', c.sync_azure ? 'chip-on' : 'chip-off']">Azure resources</span>
      </div>

      <!-- ── Subscriptions ──────────────────────────────────────────────── -->
      <section v-if="c.sync_azure" class="subs">
        <div class="subs-head">
          <h4>Azure subscriptions</h4>
          <button class="btn btn-sm" :disabled="busyId === c.id" @click="discover(c)">
            {{ busyId === c.id ? "Looking…" : c.subscriptions.length ? "Refresh list" : "Find subscriptions" }}
          </button>
        </div>

        <p v-if="c.subscriptions.length === 0" class="muted">
          None found yet. "Find subscriptions" asks Microsoft what this app
          registration is allowed to see.
        </p>

        <ul v-else class="sub-list">
          <li v-for="s in c.subscriptions" :key="s.id">
            <label class="sub">
              <input type="checkbox" :checked="s.is_enabled"
                     :disabled="busyId === c.id"
                     @change="toggleSubscription(c, s)" />
              <span class="sub-name">{{ s.display_name ?? "Unnamed" }}</span>
              <code class="sub-id">{{ s.subscription_id }}</code>
              <span v-if="s.state && s.state !== 'Enabled'" class="chip chip-off">{{ s.state }}</span>
              <span v-if="s.resource_count !== null" class="muted">{{ s.resource_count }} resources</span>
            </label>
          </li>
        </ul>

        <p v-if="c.subscriptions.length" class="hint">
          Only ticked subscriptions are imported. A principal granted Reader high
          up can see other parts of the business, and those do not belong in this
          register.
        </p>

        <!-- ── Bringing them in ────────────────────────────────────────── -->
        <div v-if="c.subscriptions.some((s) => s.is_enabled)" class="import-row">
          <button class="btn btn-sm" :disabled="busyId === c.id || importing !== null"
                  @click="previewAzure(c)">
            {{ busyId === c.id ? "Reading…" : "Preview import" }}
          </button>

          <span v-if="imported[c.id]" class="ok-note">{{ imported[c.id] }}</span>
        </div>

        <div v-if="previews[c.id]" class="preview">
          <p class="preview-lead">
            <strong>{{ previews[c.id].to_create.length }}</strong> to create,
            <strong>{{ previews[c.id].to_update }}</strong> to update<template v-if="previews[c.id].retired">,
            <strong>{{ previews[c.id].retired }}</strong> to retire</template>.
          </p>

          <ul class="preview-list">
            <li v-for="r in previews[c.id].to_create" :key="r.name">
              <span class="chip chip-on">{{ r.category }}</span> {{ r.name }}
            </li>
          </ul>

          <!--
            Named, not silently dropped. Network interfaces and metric alerts
            belong to something else, but a reader who does not know they were
            left out cannot ask for them back.
          -->
          <details v-if="previews[c.id].skipped_total">
            <summary>{{ previews[c.id].skipped_total }} not imported</summary>
            <ul class="preview-list">
              <li v-for="(n, t) in previews[c.id].skipped_types" :key="t">
                <span class="chip chip-off">{{ n }}×</span> {{ t }}
              </li>
            </ul>
            <p class="hint">
              Plumbing that belongs to something else — interfaces, disks, alerts.
              Ask if any of these should become assets in their own right.
            </p>
          </details>

          <p v-if="previews[c.id].untagged" class="hint">
            {{ previews[c.id].untagged }} carry no Azure tags, so they arrive with
            no owner or environment. Tagging them in Azure is cheaper than keying
            it in here.
          </p>

          <div class="import-row">
            <button class="btn btn-primary btn-sm" :disabled="importing !== null"
                    @click="importAzure(c)">
              {{ importing === c.id ? "Importing…" : "Import into the register" }}
            </button>
            <button class="btn btn-sm" @click="delete previews[c.id]">Cancel</button>
          </div>
        </div>
      </section>
    </article>

    <!-- ── Add / edit ─────────────────────────────────────────────────────── -->
    <div v-if="showForm" class="overlay" @click.self="showForm = false">
      <div class="dialog" role="dialog" aria-modal="true" aria-label="Tenant connection">
        <h2>{{ editing ? "Edit tenant" : "Add a tenant" }}</h2>

        <div class="dialog-body">
          <label class="field"><span>Name</span>
            <input v-model="form.name" class="control" placeholder="Konvergenz — Microsoft 365" />
            <span class="hint">How it appears on this page.</span>
          </label>

          <label class="field"><span>Directory (tenant) ID</span>
            <input v-model="form.tenant_id" class="control" placeholder="00000000-0000-0000-0000-000000000000" />
          </label>

          <label class="field"><span>Application (client) ID</span>
            <input v-model="form.client_id" class="control" placeholder="00000000-0000-0000-0000-000000000000" />
          </label>

          <label class="field"><span>Client secret</span>
            <input v-model="form.client_secret" class="control" type="password"
                   :placeholder="editing?.has_secret ? 'Leave blank to keep the current one' : 'Paste the secret value'" />
            <span class="hint">
              Stored encrypted and never shown again — not even here. Paste the
              <em>value</em>, not the secret ID.
            </span>
          </label>

          <label class="field"><span>Secret expires on</span>
            <input v-model="form.secret_expires_on" class="control" type="date" />
            <span class="hint">
              Optional, and worth filling in. An expired secret stops the sync
              silently and the register goes stale without anybody being told.
            </span>
          </label>

          <fieldset class="scopes">
            <legend>What to read</legend>
            <label class="check">
              <input v-model="form.sync_licences" type="checkbox" />
              <span>Microsoft 365 licences — SKUs, seats and who holds them</span>
            </label>
            <label class="check">
              <input v-model="form.sync_azure" type="checkbox" />
              <span>Azure resources — virtual machines, storage, vaults, networking</span>
            </label>
            <label class="check">
              <input v-model="form.is_active" type="checkbox" />
              <span>Active</span>
            </label>
          </fieldset>
        </div>

        <p v-if="formError" class="error" role="alert">{{ formError }}</p>

        <div class="dialog-actions">
          <button class="btn" @click="showForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="save">
            {{ saving ? "Saving…" : editing ? "Save changes" : "Add tenant" }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  padding: 20px;
  margin-bottom: 14px;
}

.card-head { @apply flex flex-wrap items-start justify-between gap-3; }
.card-head h3 { @apply flex items-center gap-2 font-semibold; margin: 0; color: var(--color-text); }
.card-actions { @apply flex flex-wrap gap-2; }

.ids { @apply flex flex-wrap gap-4; margin: 6px 0 0; font-size: var(--text-xs-size); color: var(--color-text-light); }
.ids code, .roles code, .sub-id {
  font-size: var(--text-xs-size);
  background: var(--color-surface-sunken);
  border-radius: var(--radius-sm);
  padding: 1px 6px;
}

.status-row { @apply flex flex-wrap items-center gap-2; margin-top: 12px; font-size: var(--text-xs-size); }

.pill { @apply font-semibold; font-size: var(--text-xs-size); padding: 2px 10px; border-radius: 999px; }
.pill-ok   { background: var(--color-success-light); color: var(--color-success); }
.pill-bad  { background: var(--color-error-light);   color: var(--color-error); }
.pill-idle { background: var(--color-surface-sunken); color: var(--color-text-light); }

.test-message { margin: 8px 0 0; font-size: var(--text-sm-size); color: var(--color-text-light); }

.checks { @apply flex flex-wrap gap-2; margin-top: 10px; }
.check { font-size: var(--text-xs-size); padding: 2px 8px; border-radius: var(--radius-sm); }
.check-ok  { background: var(--color-success-light); color: var(--color-success); }
.check-bad { background: var(--color-error-light);   color: var(--color-error); }

.roles { @apply flex flex-wrap items-center gap-2; margin: 10px 0 0; }
.roles-label { font-size: var(--text-xs-size); color: var(--color-text-light); }

.warn {
  margin: 10px 0 0;
  padding: 8px 12px;
  font-size: var(--text-sm-size);
  color: var(--color-warning);
  background: var(--color-warning-light);
  border-radius: var(--radius-sm);
}

.scope-row { @apply flex flex-wrap gap-2; margin-top: 12px; }
.chip { font-size: var(--text-xs-size); padding: 2px 10px; border-radius: 999px; }
.chip-on  { background: var(--color-brand-subtle); color: var(--color-brand-base); }
.chip-off { background: var(--color-surface-sunken); color: var(--color-text-light); }

.subs { margin-top: 16px; border-top: 1px solid var(--color-border); padding-top: 14px; }
.subs-head { @apply flex items-center justify-between gap-3; }
.subs-head h4 { @apply font-semibold; margin: 0; font-size: var(--text-sm-size); color: var(--color-text); }

.sub-list { @apply flex flex-col; list-style: none; margin: 10px 0 0; padding: 0; gap: 6px; }
.sub { @apply flex flex-wrap items-center gap-2; font-size: var(--text-sm-size); cursor: pointer; }
.sub-name { color: var(--color-text); }

.import-row { @apply flex flex-wrap items-center gap-2; margin-top: 12px; }

.preview {
  margin-top: 10px;
  padding: 12px 14px;
  background: var(--color-surface-sunken);
  border-radius: var(--radius-md);
}
.preview-lead { font-size: var(--text-sm-size); color: var(--color-text); margin: 0 0 8px; }
.preview-list { @apply flex flex-col; list-style: none; margin: 0; padding: 0; gap: 4px;
  font-size: var(--text-sm-size); color: var(--color-text); }

.ok-note { font-size: var(--text-xs-size); color: var(--color-success); }

.empty {
  padding: 20px;
  border: 1px dashed var(--color-border-strong);
  border-radius: var(--radius-xl);
  font-size: var(--text-sm-size);
  color: var(--color-text-light);
}
.empty code { background: var(--color-surface-sunken); border-radius: var(--radius-sm); padding: 1px 5px; }

.muted { font-size: var(--text-xs-size); color: var(--color-text-light); }
.hint  { font-size: var(--text-xs-size); color: var(--color-text-light); }

.error {
  font-size: var(--text-sm-size);
  color: var(--color-error);
  background: var(--color-error-light);
  border-radius: var(--radius-sm);
  padding: 10px 12px;
}

/* ── Dialog ────────────────────────────────────────────────────────────── */
.overlay {
  @apply fixed inset-0 z-50 flex items-center justify-center;
  background: rgb(0 0 0 / 45%);
  padding: 20px;
}
.dialog {
  @apply flex w-full flex-col;
  max-width: 34rem;
  max-height: 90vh;
  background: var(--color-surface);
  border-radius: var(--radius-xl);
  padding: 24px;
}
.dialog h2 { @apply font-semibold; margin: 0 0 16px; color: var(--color-text); }
.dialog-body { @apply flex flex-col; gap: 14px; overflow-y: auto; }
.dialog-actions { @apply flex justify-end gap-2; margin-top: 18px; }

.field { @apply flex flex-col; gap: 6px; }
.field > span:first-child { @apply font-semibold; font-size: var(--text-xs-size); color: var(--color-text); }

.control {
  @apply w-full;
  font-family: inherit;
  font-size: var(--text-sm-size);
  padding: 0.5rem 0.75rem;
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

.scopes { @apply flex flex-col; gap: 8px; border: 0; padding: 0; margin: 0; }
.scopes legend { @apply font-semibold; font-size: var(--text-xs-size); color: var(--color-text); padding: 0 0 6px; }
.check { @apply flex items-center gap-2; font-size: var(--text-sm-size); color: var(--color-text); }
</style>
