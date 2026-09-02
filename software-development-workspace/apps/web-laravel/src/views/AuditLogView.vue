<script setup lang="ts">
/**
 * The audit trail — who did what, and when.
 *
 * A single searchable, filterable feed of the events an auditor asks about:
 * logins and failed logins, cross-tenant access attempts, role grants, changes
 * to sensitive records and reads of private documents. Read-only by design —
 * an audit log you can edit is not one.
 */
import { ref, onMounted } from "vue";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

interface AuditRow {
  id: number;
  action: string;
  actor: string;
  description: string | null;
  subject: string | null;
  changes: Record<string, { old?: unknown; new?: unknown; masked?: boolean }> | null;
  ip_address: string | null;
  route: string | null;
  at: string | null;
}

function org() { return currentOrgSlug.value; }

const rows = ref<AuditRow[]>([]);
const actions = ref<string[]>([]);
const loading = ref(false);
const error = ref("");
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const expanded = ref<Set<number>>(new Set());

const filters = ref({ action: "", q: "", from: "", to: "" });

const ACTION_TONE: Record<string, string> = {
  login: "ok", logout: "mute", login_failed: "bad", login_locked: "bad",
  access_denied: "bad", created: "ok", updated: "warn", deleted: "bad",
  downloaded: "warn", role_granted: "warn", role_revoked: "bad", action: "mute",
};
function tone(action: string): string { return ACTION_TONE[action] ?? "mute"; }
function label(action: string): string {
  return action.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

async function loadActions(): Promise<void> {
  try {
    const res = await api<{ actions: string[] }>("GET", "/api/v1/audit-logs/actions", { org: org() });
    actions.value = res.actions;
  } catch { /* filter just stays empty */ }
}

async function load(toPage = 1): Promise<void> {
  loading.value = true; error.value = "";
  page.value = toPage;
  try {
    const res = await api<{ data: AuditRow[]; current_page: number; last_page: number; total: number }>(
      "GET", "/api/v1/audit-logs", {
        org: org(),
        params: {
          page: toPage, per_page: 40,
          action: filters.value.action || undefined,
          q: filters.value.q || undefined,
          from: filters.value.from || undefined,
          to: filters.value.to || undefined,
        },
      });
    rows.value = res.data;
    lastPage.value = res.last_page;
    total.value = res.total;
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not load the audit log.";
  } finally { loading.value = false; }
}

function applyFilters(): void { load(1); }
function resetFilters(): void { filters.value = { action: "", q: "", from: "", to: "" }; load(1); }
function toggle(id: number): void {
  const next = new Set(expanded.value);
  next.has(id) ? next.delete(id) : next.add(id);
  expanded.value = next;
}

onMounted(() => { void loadActions(); void load(1); });
</script>

<template>
  <div class="view audit">
    <div class="audit-head">
      <div>
        <h2>Audit Log</h2>
        <p class="audit-sub">Who did what, and when — every action and edit. Personal and financial data (IDs, salary, health, bank details) is recorded as changed but its value is withheld under data-protection rules.</p>
      </div>
      <span class="audit-total">{{ total }} event{{ total === 1 ? "" : "s" }}</span>
    </div>

    <!-- Filters -->
    <div class="audit-filters">
      <label>Action
        <select v-model="filters.action" @change="applyFilters">
          <option value="">All actions</option>
          <option v-for="a in actions" :key="a" :value="a">{{ label(a) }}</option>
        </select>
      </label>
      <label>Search
        <input v-model="filters.q" placeholder="Actor, description, IP…" @keyup.enter="applyFilters" />
      </label>
      <label>From
        <input v-model="filters.from" type="date" @change="applyFilters" />
      </label>
      <label>To
        <input v-model="filters.to" type="date" @change="applyFilters" />
      </label>
      <button class="btn btn-primary btn-sm" @click="applyFilters">Apply</button>
      <button class="btn btn-ghost btn-sm" @click="resetFilters">Reset</button>
    </div>

    <p v-if="error" class="audit-error">{{ error }}</p>

    <div class="audit-table-wrap">
      <table class="audit-table">
        <thead>
          <tr>
            <th>When</th><th>Actor</th><th>Action</th><th>Detail</th><th>Subject</th><th>IP</th><th></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="r in rows" :key="r.id">
            <tr>
              <td class="audit-when">{{ r.at }}</td>
              <td>{{ r.actor }}</td>
              <td><span :class="['audit-pill', 'audit-' + tone(r.action)]">{{ label(r.action) }}</span></td>
              <td class="audit-detail">{{ r.description }}</td>
              <td class="audit-subject">{{ r.subject ?? "—" }}</td>
              <td class="audit-ip">{{ r.ip_address ?? "—" }}</td>
              <td>
                <button v-if="r.changes && Object.keys(r.changes).length" class="link" @click="toggle(r.id)">
                  {{ expanded.has(r.id) ? "Hide" : "Changes" }}
                </button>
              </td>
            </tr>
            <tr v-if="expanded.has(r.id) && r.changes" class="audit-changes-row">
              <td colspan="7">
                <table class="audit-changes">
                  <thead><tr><th>Field</th><th>From</th><th>To</th></tr></thead>
                  <tbody>
                    <tr v-for="(v, field) in r.changes" :key="field">
                      <td>{{ field }}</td>
                      <template v-if="v.masked">
                        <td colspan="2" class="audit-masked">🔒 changed — value withheld</td>
                      </template>
                      <template v-else>
                        <td class="audit-old">{{ v.old ?? "—" }}</td>
                        <td class="audit-new">{{ v.new ?? "—" }}</td>
                      </template>
                    </tr>
                  </tbody>
                </table>
              </td>
            </tr>
          </template>
          <tr v-if="!rows.length && !loading">
            <td colspan="7" class="audit-empty">No audit events match these filters.</td>
          </tr>
          <tr v-if="loading">
            <td colspan="7" class="audit-empty">Loading…</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="lastPage > 1" class="audit-pager">
      <button class="btn btn-ghost btn-sm" :disabled="page <= 1" @click="load(page - 1)">‹ Newer</button>
      <span>Page {{ page }} of {{ lastPage }}</span>
      <button class="btn btn-ghost btn-sm" :disabled="page >= lastPage" @click="load(page + 1)">Older ›</button>
    </div>
  </div>
</template>

<style scoped>
@reference "../theme.css";

.audit-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.audit-head h2 { margin: 0; }
.audit-sub { font-size: .85rem; color: var(--color-text-light); margin: 2px 0 0; max-width: 60ch; }
.audit-total { font-size: .82rem; color: var(--color-text-light); white-space: nowrap; }

.audit-filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; margin-bottom: 16px; padding: 14px 16px; background: var(--color-surface-sunken); border: 1px solid var(--color-border); border-radius: var(--radius-lg); }
.audit-filters label { display: flex; flex-direction: column; gap: 4px; font-size: .74rem; font-weight: 600; color: var(--color-text-muted); }
.audit-filters select, .audit-filters input { font-size: .84rem; padding: 7px 9px; border: 1px solid var(--color-border-strong); border-radius: 8px; background: var(--color-surface); color: var(--color-text); }

.audit-error { font-size: .85rem; color: var(--color-error); background: var(--color-error-light); border-radius: 8px; padding: 9px 12px; margin-bottom: 12px; }

.audit-table-wrap { overflow-x: auto; border: 1px solid var(--color-border); border-radius: var(--radius-lg); }
.audit-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
.audit-table th { text-align: left; font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-muted); padding: 10px 12px; background: var(--color-surface-sunken); border-bottom: 1px solid var(--color-border); white-space: nowrap; }
.audit-table td { padding: 9px 12px; border-bottom: 1px solid var(--color-border); vertical-align: top; }
.audit-when { white-space: nowrap; color: var(--color-text-light); font-variant-numeric: tabular-nums; }
.audit-detail { min-width: 18rem; }
.audit-subject { white-space: nowrap; color: var(--color-text-light); }
.audit-ip { white-space: nowrap; font-family: ui-monospace, monospace; font-size: .78rem; color: var(--color-text-light); }

.audit-pill { display: inline-block; font-size: .7rem; font-weight: 600; padding: 2px 9px; border-radius: 999px; white-space: nowrap; }
.audit-ok   { background: var(--color-success-light); color: var(--color-success); }
.audit-warn { background: var(--color-warning-light); color: #7C2D12; }
.audit-bad  { background: var(--color-error-light); color: var(--color-error); }
.audit-mute { background: var(--color-surface-sunken); color: var(--color-text-light); }

.audit-changes-row td { background: var(--color-surface-sunken); }
.audit-changes { width: 100%; border-collapse: collapse; font-size: .78rem; }
.audit-changes th { font-size: .64rem; padding: 4px 8px; background: transparent; border: none; color: var(--color-text-muted); }
.audit-changes td { padding: 3px 8px; border: none; }
.audit-old { color: var(--color-error); text-decoration: line-through; }
.audit-new { color: var(--color-success); }
.audit-masked { color: var(--color-text-muted); font-style: italic; }

.audit-empty { text-align: center; color: var(--color-text-light); padding: 24px; }
.audit-pager { display: flex; align-items: center; justify-content: center; gap: 14px; margin-top: 14px; font-size: .82rem; color: var(--color-text-light); }
</style>
