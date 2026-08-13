<script setup lang="ts">
/**
 * What the tenant says we are paying for.
 *
 * Read from the last snapshot rather than from Microsoft, so opening this does
 * not depend on the tenant being reachable and does not put a directory read
 * behind every refresh. "Sync now" is the only thing that talks to Microsoft.
 */
import { computed, onMounted, ref } from "vue";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";

interface SkuRow {
  name: string; part_number: string; classification: string;
  seats: number; assigned: number; spare: number | null;
  status: string | null; present: boolean;
}

interface Stats {
  seats: { purchased: number; assigned: number; spare: number; utilisation: number | null };
  skus: { total: number; paid: number; excluded: number; excluded_nominal_seats: number };
  accounts: { total: number; enabled: number; blocked: number; matched_to_employee: number; awaiting_match: number };
  waste: {
    blocked_accounts_with_licences: number;
    licences_held: number;
    paid_licences_held: number;
    accounts_holding_paid: number;
    by_sku: { name: string; count: number; classification: string }[];
    accounts: { name: string; address: string; licences: number; holds_paid: boolean;
                skus: { name: string; paid: boolean }[] }[];
  };
  at_capacity: { name: string; seats: number; assigned: number }[];
  mostly_idle: { name: string; idle: number; seats: number }[];
  by_sku: SkuRow[];
}

interface ConnectionRow {
  id: number; name: string; sync_licences: boolean; is_active: boolean; last_synced_at: string | null;
}

const stats       = ref<Stats | null>(null);
const connections = ref<ConnectionRow[]>([]);
const loading     = ref(false);
const syncingId   = ref<number | null>(null);
const error       = ref("");
const note        = ref("");
const showAll     = ref(false);

interface AssetPreview {
  committed: boolean;
  products: { name: string; new: boolean }[];
  agreements: { name: string; seats: number; renews: string | null; status: string; new: boolean }[];
  products_new: number; agreements_new: number; seats_total: number;
  retired: number; excluded_free: number;
}

const assetPreview = ref<AssetPreview | null>(null);
const previewingId = ref<number | null>(null);
const importingId  = ref<number | null>(null);
const importNote   = ref("");

const org = computed(() => currentOrgSlug.value);
const syncable = computed(() => connections.value.filter((c) => c.sync_licences && c.is_active));

async function load(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    const res = await api<{ statistics: Stats; connections: ConnectionRow[] }>(
      "GET", "/api/v1/integrations/licence-statistics", { org: org.value },
    );
    stats.value = res.statistics;
    connections.value = res.connections;
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : "Could not load the figures.";
  } finally {
    loading.value = false;
  }
}

async function sync(c: ConnectionRow): Promise<void> {
  syncingId.value = c.id;
  error.value = "";
  note.value = "";
  try {
    const res = await api<{ result: Record<string, number>; statistics: Stats }>(
      "POST", `/api/v1/integrations/connections/${c.id}/sync`, { org: org.value },
    );
    stats.value = res.statistics;
    const r = res.result;
    note.value = `${c.name}: ${r.skus} licences and ${r.accounts} accounts read`
      + (r.vanished_accounts ? `, ${r.vanished_accounts} no longer present` : "")
      + ".";
    await load();
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : "The sync failed.";
  } finally {
    syncingId.value = null;
  }
}

/*
 * Putting the licences into the register as things.
 *
 * Separate from "Sync", which only refreshes the snapshot these counts come
 * from. This one creates assets, so it is previewed first — the same rule the
 * Azure import follows.
 */
async function previewAssets(c: ConnectionRow): Promise<void> {
  previewingId.value = c.id;
  error.value = ""; importNote.value = "";
  try {
    const res = await api<{ preview: AssetPreview }>(
      "POST", `/api/v1/integrations/connections/${c.id}/licences/preview`, { org: org.value },
    );
    assetPreview.value = res.preview;
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : "Could not read the licences.";
  } finally {
    previewingId.value = null;
  }
}

async function importAssets(c: ConnectionRow): Promise<void> {
  importingId.value = c.id;
  error.value = "";
  try {
    const res = await api<{ result: AssetPreview }>(
      "POST", `/api/v1/integrations/connections/${c.id}/licences/import`, { org: org.value },
    );
    const r = res.result;
    importNote.value = `${r.products.length} products and ${r.agreements.length} agreements in the register`
      + ` — ${r.seats_total} seats` + (r.retired ? `, ${r.retired} retired` : "") + ".";
    assetPreview.value = null;
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : "The import failed.";
  } finally {
    importingId.value = null;
  }
}

const visibleSkus = computed(() =>
  showAll.value ? (stats.value?.by_sku ?? [])
                : (stats.value?.by_sku ?? []).filter((s) => s.classification === "paid"));

function fmt(n: number): string { return n.toLocaleString(); }

onMounted(load);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <h2>Licence statistics</h2>
        <p class="view-subtitle">
          What Microsoft 365 says is bought and held. Read from the last sync —
          nothing here changes anything in the tenant.
        </p>
      </div>
      <div class="head-actions">
        <button v-for="c in syncable" :key="c.id" class="btn btn-primary btn-sm"
                :disabled="syncingId !== null" @click="sync(c)">
          {{ syncingId === c.id ? "Syncing…" : `Sync ${c.name}` }}
        </button>
      </div>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="note" class="note" role="status">{{ note }}</p>
    <p v-if="importNote" class="note" role="status">{{ importNote }}</p>
    <p v-if="loading && !stats" class="muted">Loading…</p>

    <p v-if="!loading && syncable.length === 0" class="empty">
      No active connection is set to read licences.
      <router-link :to="{ name: 'integration-connections' }">Add a tenant</router-link> first.
    </p>

    <template v-if="stats">
      <p v-for="c in connections" :key="c.id" class="synced">
        {{ c.name }} — {{ c.last_synced_at ? `last synced ${c.last_synced_at}` : "never synced" }}
      </p>

      <!-- ── Seats ────────────────────────────────────────────────────────── -->
      <div class="tiles">
        <div class="tile">
          <span class="tile-value">{{ fmt(stats.seats.purchased) }}</span>
          <span class="tile-label">Paid seats</span>
        </div>
        <div class="tile">
          <span class="tile-value">{{ fmt(stats.seats.assigned) }}</span>
          <span class="tile-label">Assigned</span>
        </div>
        <div class="tile">
          <span class="tile-value">{{ fmt(stats.seats.spare) }}</span>
          <span class="tile-label">Spare</span>
        </div>
        <div class="tile">
          <span class="tile-value">{{ stats.seats.utilisation ?? "—" }}<small v-if="stats.seats.utilisation !== null">%</small></span>
          <span class="tile-label">Utilisation</span>
        </div>
      </div>

      <!--
        Said out loud rather than quietly done. A reader who does not know the
        free SKUs were set aside cannot check the judgement — and without it the
        denominator is three million and utilisation reads as nought.
      -->
      <p class="aside">
        Counted across {{ stats.skus.paid }} paid licences.
        {{ stats.skus.excluded }} free or trial licences carrying
        {{ fmt(stats.skus.excluded_nominal_seats) }} nominal seats are set aside —
        including them would put utilisation at zero.
      </p>

      <!-- ── Into the register ────────────────────────────────────────────── -->
      <section class="panel">
        <div class="panel-head">
          <h3>Licences in the asset register</h3>
          <button v-for="c in syncable" :key="c.id" class="btn btn-sm"
                  :disabled="previewingId !== null || importingId !== null"
                  @click="previewAssets(c)">
            {{ previewingId === c.id ? "Reading…" : "Preview" }}
          </button>
        </div>
        <p class="panel-lead">
          The counts above come from a snapshot. This puts the licences into the
          register itself — a software product per licence, and a subscription
          agreement per purchase, so renewals and seats sit where the rest of the
          register expects them.
        </p>

        <div v-if="assetPreview" class="preview">
          <p class="preview-lead">
            <strong>{{ assetPreview.products.length }}</strong> products,
            <strong>{{ assetPreview.agreements.length }}</strong> agreements,
            <strong>{{ assetPreview.seats_total }}</strong> seats.
            <template v-if="assetPreview.products_new || assetPreview.agreements_new">
              {{ assetPreview.products_new }} and {{ assetPreview.agreements_new }} of them new.
            </template>
          </p>

          <table class="table">
            <thead><tr><th>Agreement</th><th>Seats</th><th>Renews</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="(a, i) in assetPreview.agreements" :key="i">
                <td>{{ a.name }}</td>
                <td>{{ a.seats }}</td>
                <td>{{ a.renews ?? "—" }}</td>
                <td>
                  <span :class="['chip', a.status === 'Enabled' ? 'chip-on' : 'chip-warn']">{{ a.status }}</span>
                </td>
              </tr>
            </tbody>
          </table>

          <!--
            One SKU can be bought several times on separate terms. Saying so
            stops the duplicate-looking rows reading as a bug.
          -->
          <p class="aside">
            A licence bought more than once appears once per purchase, because
            each has its own term and renewal date.
            {{ assetPreview.excluded_free }} free or trial licences are left out —
            their nominal seat counts would make every total in the register
            meaningless.
          </p>

          <div class="import-row">
            <button v-for="c in syncable" :key="c.id" class="btn btn-primary btn-sm"
                    :disabled="importingId !== null" @click="importAssets(c)">
              {{ importingId === c.id ? "Importing…" : "Add to the register" }}
            </button>
            <button class="btn btn-sm" @click="assetPreview = null">Cancel</button>
          </div>
        </div>
      </section>

      <!-- ── The thing to act on ──────────────────────────────────────────── -->
      <section v-if="stats.waste.blocked_accounts_with_licences > 0" class="panel panel-alert">
        <h3>Paid for, and nobody can use it</h3>

        <!--
          Leads with the paid count, not the total. A blocked account holding
          six free SKUs costs nothing, and an inflated finding is one somebody
          checks once and then stops trusting.
        -->
        <p class="panel-lead">
          <strong>{{ stats.waste.paid_licences_held }} paid licences</strong>
          are held by {{ stats.waste.accounts_holding_paid }} accounts that cannot
          sign in. This needs no employee record to be true, so it can be acted on
          now.
        </p>

        <ul class="waste-skus">
          <li v-for="w in stats.waste.by_sku" :key="w.name"
              :class="w.classification === 'paid' ? 'waste-paid' : 'waste-free'">
            <strong>{{ w.count }}×</strong> {{ w.name }}
            <span v-if="w.classification !== 'paid'" class="free-tag">free</span>
          </li>
        </ul>

        <p class="aside">
          {{ stats.waste.blocked_accounts_with_licences }} blocked accounts hold
          {{ stats.waste.licences_held }} licences in total. The free and trial
          ones are shown for completeness — they cost nothing to leave in place.
        </p>

        <details>
          <summary>Which accounts</summary>
          <table class="table">
            <thead><tr><th>Account</th><th>Address</th><th>Holds</th></tr></thead>
            <tbody>
              <tr v-for="a in stats.waste.accounts" :key="a.address">
                <td>{{ a.name }}</td>
                <td class="mono">{{ a.address }}</td>
                <td>
                  <span v-for="k in a.skus" :key="k.name"
                        :class="['chip', k.paid ? 'chip-warn' : 'chip-off']">{{ k.name }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </details>
      </section>

      <!-- ── Pressure ─────────────────────────────────────────────────────── -->
      <div class="two-up">
        <section v-if="stats.at_capacity.length" class="panel">
          <h3>No seats left</h3>
          <p class="panel-lead">
            The next person who needs one of these has nowhere to go. More urgent
            than idle seats: this goes wrong on somebody's first morning.
          </p>
          <ul class="rows">
            <li v-for="s in stats.at_capacity" :key="s.name">
              <span>{{ s.name }}</span><b>{{ s.assigned }} / {{ s.seats }}</b>
            </li>
          </ul>
        </section>

        <section v-if="stats.mostly_idle.length" class="panel">
          <h3>Mostly idle</h3>
          <p class="panel-lead">
            Under a third consumed. Money, recoverable at the next renewal.
          </p>
          <ul class="rows">
            <li v-for="s in stats.mostly_idle" :key="s.name">
              <span>{{ s.name }}</span><b>{{ s.idle }} idle of {{ s.seats }}</b>
            </li>
          </ul>
        </section>
      </div>

      <!-- ── Accounts ─────────────────────────────────────────────────────── -->
      <section class="panel">
        <h3>Directory accounts</h3>
        <div class="tiles tiles-sm">
          <div class="tile"><span class="tile-value">{{ stats.accounts.total }}</span><span class="tile-label">Licensed accounts</span></div>
          <div class="tile"><span class="tile-value">{{ stats.accounts.enabled }}</span><span class="tile-label">Can sign in</span></div>
          <div class="tile"><span class="tile-value">{{ stats.accounts.blocked }}</span><span class="tile-label">Blocked</span></div>
          <div class="tile"><span class="tile-value">{{ stats.accounts.matched_to_employee }}</span><span class="tile-label">Matched to an employee</span></div>
        </div>

        <!--
          Deliberately "awaiting a match", not "orphaned". Until employees are
          migrated every account is unmatched, and calling them all findings
          would make a page nobody can act on.
        -->
        <p v-if="stats.accounts.awaiting_match" class="aside">
          {{ stats.accounts.awaiting_match }} accounts are not yet matched to an
          employee record. That is expected until employees are migrated — it is
          not a list of leavers.
        </p>
      </section>

      <!-- ── Everything ───────────────────────────────────────────────────── -->
      <section class="panel">
        <div class="panel-head">
          <h3>Every licence</h3>
          <label class="toggle">
            <input v-model="showAll" type="checkbox" />
            <span>Include free and trial</span>
          </label>
        </div>

        <table class="table">
          <thead>
            <tr><th>Licence</th><th>Kind</th><th>Assigned</th><th>Seats</th><th>Spare</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="s in visibleSkus" :key="s.part_number">
              <td>
                {{ s.name }}
                <span v-if="!s.present" class="chip chip-off">no longer reported</span>
              </td>
              <td><span :class="['chip', s.classification === 'paid' ? 'chip-on' : 'chip-off']">{{ s.classification }}</span></td>
              <td>{{ fmt(s.assigned) }}</td>
              <td>{{ fmt(s.seats) }}</td>
              <td>{{ s.spare === null ? "—" : fmt(s.spare) }}</td>
              <td><span v-if="s.status && s.status !== 'Enabled'" class="chip chip-warn">{{ s.status }}</span></td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.head-actions { @apply flex flex-wrap gap-2; }

.synced { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0 0 4px; }

.tiles { @apply grid gap-3; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); margin: 14px 0 6px; }
.tiles-sm { margin-top: 8px; }

.tile {
  @apply flex flex-col gap-1;
  padding: 14px 16px;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
}
.tile-value { @apply font-semibold; font-size: 1.65rem; line-height: 1.1; color: var(--color-text); }
.tile-value small { font-size: 1rem; }
.tile-label { font-size: var(--text-xs-size); color: var(--color-text-light); }

.aside { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 8px 0 16px; }

.panel {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  padding: 18px 20px;
  margin-bottom: 14px;
}
.panel-alert { border-color: var(--color-error); }
.panel h3 { @apply font-semibold; margin: 0; font-size: var(--text-sm-size); color: var(--color-text); }
.panel-lead { font-size: var(--text-sm-size); color: var(--color-text-light); margin: 6px 0 12px; }
.panel-head { @apply flex flex-wrap items-center justify-between gap-3; }

.preview { margin-top: 10px; padding: 12px 14px; background: var(--color-surface-sunken); border-radius: var(--radius-md); }
.preview-lead { font-size: var(--text-sm-size); color: var(--color-text); margin: 0 0 8px; }
.import-row { @apply flex flex-wrap items-center gap-2; margin-top: 12px; }

.two-up { @apply grid gap-3; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }

.waste-skus { @apply flex flex-wrap gap-3; list-style: none; margin: 0 0 12px; padding: 0; font-size: var(--text-sm-size); }
.waste-skus li { padding: 4px 10px; border-radius: var(--radius-sm); }
.waste-paid { background: var(--color-error-light); color: var(--color-error); }
.waste-free { background: var(--color-surface-sunken); color: var(--color-text-light); }
.free-tag { font-size: var(--text-xs-size); opacity: 0.75; }

.rows { @apply flex flex-col; list-style: none; margin: 0; padding: 0; gap: 6px; }
.rows li { @apply flex items-center justify-between gap-3; font-size: var(--text-sm-size); color: var(--color-text); }
.rows b { color: var(--color-text-light); font-weight: 600; }

.table { @apply w-full; border-collapse: collapse; margin-top: 10px; font-size: var(--text-sm-size); }
.table th, .table td { text-align: left; padding: 7px 10px; border-bottom: 1px solid var(--color-border); }
.table th { font-size: var(--text-xs-size); color: var(--color-text-light); font-weight: 600; }
.mono { font-family: ui-monospace, monospace; font-size: var(--text-xs-size); }

.chip { font-size: var(--text-xs-size); padding: 1px 8px; border-radius: 999px; }
.chip-on   { background: var(--color-brand-subtle); color: var(--color-brand-base); }
.chip-off  { background: var(--color-surface-sunken); color: var(--color-text-light); }
.chip-warn { background: var(--color-warning-light); color: var(--color-warning); }

.toggle { @apply flex items-center gap-2; font-size: var(--text-xs-size); color: var(--color-text-light); }

details summary { font-size: var(--text-sm-size); color: var(--color-brand-base); cursor: pointer; }

.note {
  font-size: var(--text-sm-size);
  color: var(--color-success);
  background: var(--color-success-light);
  border-radius: var(--radius-sm);
  padding: 10px 12px;
}
.error {
  font-size: var(--text-sm-size);
  color: var(--color-error);
  background: var(--color-error-light);
  border-radius: var(--radius-sm);
  padding: 10px 12px;
}
.empty {
  padding: 20px;
  border: 1px dashed var(--color-border-strong);
  border-radius: var(--radius-xl);
  font-size: var(--text-sm-size);
  color: var(--color-text-light);
}
.muted { font-size: var(--text-xs-size); color: var(--color-text-light); }
</style>
