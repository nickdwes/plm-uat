<script setup lang="ts">
/**
 * The people report — choose the columns, check them, take them away.
 *
 * The preview and the spreadsheet are the same query and the same column
 * catalogue on the server, so what is checked here is what lands in the file.
 * The picker is a convenience, not a gate: a column somebody may not see is
 * absent from the catalogue and dropped again on export.
 */
import { ref, computed, onMounted, watch } from "vue";
import { api } from "../lib/api";
import { currentOrgSlug, getToken } from "../stores/auth";

interface Column { key: string; label: string; group: string; sensitive: boolean }
interface Preview { columns: string[]; headings: string[]; rows: (string | number | null)[][]; total: number; showing: number }

const columns  = ref<Column[]>([]);
const chosen   = ref<string[]>([]);
const seesSensitive = ref(false);

const preview  = ref<Preview | null>(null);
const loading  = ref(false);
const exporting = ref(false);
const error    = ref("");

const filters = ref({ search: "", status: "", employment_type: "", on_leave: "" });

/**
 * The password for the archive.
 *
 * Held only long enough to send it and never stored — not in localStorage, not
 * in the URL. The request is a POST for the same reason: a query string is
 * written to the access log, the browser history and every proxy in between.
 */
const password = ref("");
const showPassword = ref(false);

/** Which chosen columns force a password, named so the screen can say why. */
const passwordBecause = ref<string[]>([]);
const passwordRequired = computed(() => passwordBecause.value.length > 0);

function org(): string | null { return currentOrgSlug.value; }

/** Groups in the order the catalogue returns them, not alphabetically. */
const grouped = computed(() => {
  const out: { group: string; items: Column[] }[] = [];
  for (const c of columns.value) {
    const found = out.find(g => g.group === c.group);
    if (found) found.items.push(c);
    else out.push({ group: c.group, items: [c] });
  }
  return out;
});

const chosenLabels = computed(() =>
  chosen.value.map(k => columns.value.find(c => c.key === k)?.label ?? k)
);

async function loadColumns(): Promise<void> {
  if (!org()) return;
  try {
    const res = await api<{ data: { columns: Column[]; defaults: string[]; sees_sensitive: boolean } }>(
      "GET", "/api/v1/reports/people/columns", { org: org() });
    columns.value = res.data.columns;
    seesSensitive.value = res.data.sees_sensitive;
    if (!chosen.value.length) chosen.value = [...res.data.defaults];
    await loadPreview();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Could not load the column list.";
  }
}

function activeFilters(): Record<string, string> {
  return Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v !== ""));
}

async function loadRequirement(): Promise<void> {
  if (!org() || !chosen.value.length) { passwordBecause.value = []; return; }
  try {
    const res = await api<{ data: { password_required: boolean; because: string[] } }>(
      "GET", "/api/v1/reports/people/requirement",
      { org: org(), params: { columns: chosen.value.join(",") } });
    passwordBecause.value = res.data.because ?? [];
  } catch { passwordBecause.value = []; }
}

async function loadPreview(): Promise<void> {
  if (!org() || !chosen.value.length) { preview.value = null; return; }
  loading.value = true; error.value = "";
  try {
    preview.value = (await api<{ data: Preview }>("GET", "/api/v1/reports/people/preview", {
      org: org(),
      params: { columns: chosen.value.join(","), ...activeFilters() },
    })).data;
    void loadRequirement();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Could not build the preview.";
  } finally { loading.value = false; }
}

/**
 * Toggling keeps the order columns were added in, so the sheet comes out
 * arranged the way it was built rather than in catalogue order.
 */
function toggle(key: string): void {
  const at = chosen.value.indexOf(key);
  if (at === -1) chosen.value.push(key);
  else chosen.value.splice(at, 1);
  void loadPreview();
}

function move(i: number, by: number): void {
  const to = i + by;
  if (to < 0 || to >= chosen.value.length) return;
  const [item] = chosen.value.splice(i, 1);
  chosen.value.splice(to, 0, item);
  void loadPreview();
}

function selectGroup(group: string, on: boolean): void {
  for (const c of columns.value.filter(x => x.group === group)) {
    const at = chosen.value.indexOf(c.key);
    if (on && at === -1) chosen.value.push(c.key);
    if (!on && at !== -1) chosen.value.splice(at, 1);
  }
  void loadPreview();
}

function groupState(group: string): "all" | "some" | "none" {
  const keys = columns.value.filter(c => c.group === group).map(c => c.key);
  const n = keys.filter(k => chosen.value.includes(k)).length;
  return n === 0 ? "none" : n === keys.length ? "all" : "some";
}

/**
 * Fetched with the token and handed over as a blob.
 *
 * An <a href> cannot carry the Authorization header, and a sheet that may hold
 * salaries and national ID numbers is exactly the file that must not be
 * reachable without one.
 */
async function exportXlsx(): Promise<void> {
  if (!chosen.value.length) return;
  exporting.value = true; error.value = "";
  try {
    const base = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");
    const headers: Record<string, string> = {};
    const token = getToken();
    if (token) headers["Authorization"] = `Bearer ${token}`;
    const slug = org();
    if (slug) headers["X-Organization"] = slug;

    headers["Content-Type"] = "application/json";

    const res = await fetch(`${base}/api/v1/reports/people/export`, {
      method: "POST",
      headers,
      body: JSON.stringify({
        columns: chosen.value.join(","),
        ...activeFilters(),
        password: password.value,
      }),
    });

    if (!res.ok) {
      // The server explains which column forced the password; repeating that
      // is more use than "export failed".
      let detail = "";
      try { detail = (await res.json())?.message ?? ""; } catch { /* not json */ }
      throw new Error(detail || `Export failed (${res.status}).`);
    }

    // A protected report comes back as a zip; a plain one as the workbook.
    const protectedFile = (res.headers.get("content-type") ?? "").includes("zip");
    const stamp = new Date().toISOString().slice(0, 10);

    const url = URL.createObjectURL(await res.blob());
    const link = document.createElement("a");
    link.href = url;
    link.download = `employees-${stamp}.${protectedFile ? "zip" : "xlsx"}`;
    link.click();
    URL.revokeObjectURL(url);

    // Gone from memory the moment it has been used.
    password.value = "";
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Export failed.";
  } finally { exporting.value = false; }
}

watch(chosen, loadRequirement, { deep: true });

onMounted(loadColumns);
watch(currentOrgSlug, () => { chosen.value = []; void loadColumns(); });
</script>

<template>
  <div class="view pr">
    <header class="pr-head">
      <div>
        <h2 class="pr-title">People Report</h2>
        <p class="pr-lead">Choose the columns you need, check them below, then take the whole set away as a spreadsheet.</p>
      </div>
      <div class="pr-export">
        <label v-if="passwordRequired" class="pr-pw">
          <span>Password for the file</span>
          <span class="pr-pw-wrap">
            <input
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              class="input" autocomplete="new-password"
              placeholder="At least 8 characters"
            />
            <button type="button" class="pr-mini" @click="showPassword = !showPassword">
              {{ showPassword ? "Hide" : "Show" }}
            </button>
          </span>
        </label>
        <button
          class="btn btn-primary btn-sm"
          :disabled="exporting || !chosen.length || (passwordRequired && password.length < 8)"
          @click="exportXlsx"
        >
          {{ exporting ? "Building…" : "Export to Excel" }}
        </button>
      </div>
    </header>

    <!--
      Said before the button is pressed, not after it is refused. The list
      names the column that forced it, so the choice is obvious: supply a
      password, or drop that column.
    -->
    <p v-if="passwordRequired" class="pr-warn">
      <strong>This report is protected.</strong>
      It includes {{ passwordBecause.join(", ") }}, so it downloads as a
      <strong>password-protected zip</strong> containing the spreadsheet.
      The password is not stored anywhere — if it is lost the file cannot be opened, and it is not
      sent to the recipient with it.
      <br />
      Opening it needs 7-Zip, WinRAR, Keka or any modern archiver. Windows Explorer and the macOS
      Archive Utility do not handle this encryption and will call the file damaged rather than
      asking for a password.
    </p>

    <p v-if="error" class="error">{{ error }}</p>

    <div class="pr-grid">
      <!-- ── Columns ───────────────────────────────────────────────────── -->
      <aside class="pr-panel">
        <h3 class="pr-panel-h">Columns</h3>

        <!--
          Said outright rather than left to be inferred from a short list.
          Somebody who cannot see pay should know their report is narrower than
          a colleague's, not wonder why a column is missing.
        -->
        <p v-if="!seesSensitive" class="pr-note">
          Pay, statutory identifiers and personal details are not in this list — they need the
          sensitive-data permission.
        </p>

        <div v-for="g in grouped" :key="g.group" class="pr-group">
          <div class="pr-group-h">
            <span>{{ g.group }}</span>
            <button class="pr-mini" @click="selectGroup(g.group, groupState(g.group) !== 'all')">
              {{ groupState(g.group) === "all" ? "None" : "All" }}
            </button>
          </div>
          <label v-for="c in g.items" :key="c.key" class="pr-check">
            <input type="checkbox" :checked="chosen.includes(c.key)" @change="toggle(c.key)" />
            <span>{{ c.label }}</span>
            <em v-if="c.sensitive" class="pr-lock" title="Sensitive">restricted</em>
          </label>
        </div>
      </aside>

      <!-- ── Preview ───────────────────────────────────────────────────── -->
      <section class="pr-main">
        <div class="pr-panel pr-filters">
          <label><span>Search</span>
            <input v-model="filters.search" class="input" placeholder="Name, email or ID" @keyup.enter="loadPreview" /></label>
          <label><span>Status</span>
            <select v-model="filters.status" class="input" @change="loadPreview">
              <option value="">All but drafts</option>
              <option value="active">Active</option>
              <option value="exited">Exited</option>
              <option value="draft">Draft</option>
            </select></label>
          <label><span>Employment type</span>
            <select v-model="filters.employment_type" class="input" @change="loadPreview">
              <option value="">Any</option>
              <option value="permanent">Permanent</option>
              <option value="contract">Contract</option>
              <option value="intern">Intern</option>
              <option value="attachment">Attachment</option>
            </select></label>
          <label><span>On leave today</span>
            <select v-model="filters.on_leave" class="input" @change="loadPreview">
              <option value="">Everyone</option>
              <option value="1">Only people on leave</option>
            </select></label>
          <button class="btn btn-secondary btn-sm" @click="loadPreview">Apply</button>
        </div>

        <!-- The chosen columns in their order, so the sheet's shape is visible
             before a file exists. -->
        <div v-if="chosen.length" class="pr-order">
          <span class="pr-order-h">Column order</span>
          <ol>
            <li v-for="(label, i) in chosenLabels" :key="chosen[i]">
              <span>{{ label }}</span>
              <button class="pr-mini" :disabled="i === 0" aria-label="Move left" @click="move(i, -1)">←</button>
              <button class="pr-mini" :disabled="i === chosenLabels.length - 1" aria-label="Move right" @click="move(i, 1)">→</button>
            </li>
          </ol>
        </div>

        <div class="pr-panel">
          <div class="pr-preview-h">
            <h3 class="pr-panel-h" style="margin:0">Preview</h3>
            <span v-if="preview" class="pr-count">
              showing {{ preview.showing }} of {{ preview.total }} — the export contains all {{ preview.total }}
            </span>
          </div>

          <p v-if="loading" class="status">Loading…</p>
          <p v-else-if="!chosen.length" class="pr-note">Pick at least one column.</p>

          <div v-else-if="preview" class="pr-table-wrap">
            <table class="pr-table">
              <thead><tr><th v-for="h in preview.headings" :key="h">{{ h }}</th></tr></thead>
              <tbody>
                <tr v-for="(row, i) in preview.rows" :key="i">
                  <td v-for="(cell, j) in row" :key="j">{{ cell === null || cell === "" ? "—" : cell }}</td>
                </tr>
                <tr v-if="!preview.rows.length">
                  <td :colspan="preview.headings.length" class="pr-empty">Nobody matches those filters.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
@reference "../theme.css";

.pr { display: flex; flex-direction: column; gap: var(--space-5); }

.pr-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: var(--space-4); }
.pr-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.pr-lead  { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; max-width: 46rem; }

.pr-grid { display: grid; grid-template-columns: minmax(0, 17rem) minmax(0, 1fr); gap: var(--space-4); align-items: start; }

.pr-panel {
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  background: var(--color-surface-raised);
}
.pr-panel-h { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-3); }

.pr-note { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0 0 var(--space-3); }

.pr-group + .pr-group { margin-top: var(--space-4); }
.pr-group-h {
  display: flex; align-items: center; justify-content: space-between;
  font-size: var(--text-xs-size); font-weight: 600; text-transform: uppercase;
  letter-spacing: .04em; color: var(--color-text-muted);
  padding-bottom: var(--space-1); border-bottom: 1px solid var(--color-border); margin-bottom: var(--space-2);
}

.pr-check { display: flex; align-items: center; gap: var(--space-2); font-size: var(--text-sm-size); padding: 3px 0; cursor: pointer; }
.pr-check input { accent-color: var(--color-brand-base); }
.pr-check span { flex: 1; }
.pr-lock { font-style: normal; font-size: 10px; color: var(--color-warning); text-transform: uppercase; letter-spacing: .04em; }

.pr-mini {
  border: 1px solid var(--color-border); background: var(--color-surface);
  font-family: inherit; font-size: 11px; cursor: pointer;
  padding: 1px var(--space-2); border-radius: var(--radius-sm); color: var(--color-text-muted);
}
.pr-mini:hover:not(:disabled) { color: var(--color-brand-base); border-color: var(--color-brand-base); }
.pr-mini:disabled { opacity: .4; cursor: not-allowed; }

.pr-main { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }

.pr-filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: var(--space-3); }
.pr-filters label { display: flex; flex-direction: column; gap: 4px; font-size: var(--text-xs-size); color: var(--color-text-muted); }
.pr-filters .input { min-width: 10rem; }

.pr-order { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.pr-order-h { font-weight: 600; }
.pr-order ol { display: flex; flex-wrap: wrap; gap: var(--space-2); list-style: none; margin: var(--space-2) 0 0; padding: 0; counter-reset: c; }
.pr-order li {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 2px var(--space-2); border-radius: 999px;
  background: var(--color-brand-light); color: var(--color-brand-base);
}

.pr-preview-h { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: var(--space-2); margin-bottom: var(--space-3); }
.pr-count { font-size: var(--text-xs-size); color: var(--color-text-muted); }

.pr-table-wrap { overflow-x: auto; }
.pr-table { width: 100%; border-collapse: collapse; }
.pr-table th {
  text-align: left; white-space: nowrap; font-weight: 600;
  font-size: var(--text-xs-size); color: var(--color-text-muted);
  padding: var(--space-2) var(--space-3);
  border-bottom: 1px solid var(--color-border); background: var(--color-surface-sunken);
}
.pr-table td {
  font-size: var(--text-sm-size); color: var(--color-text); white-space: nowrap;
  padding: var(--space-2) var(--space-3); border-bottom: 1px solid var(--color-border);
}
.pr-table tbody tr:last-child td { border-bottom: none; }
.pr-empty { text-align: center; color: var(--color-text-light); padding: var(--space-6) !important; }

@media (max-width: 1024px) {
  .pr-grid { grid-template-columns: 1fr; }
}

.pr-export { display: flex; flex-wrap: wrap; align-items: flex-end; gap: var(--space-3); }
.pr-pw { display: flex; flex-direction: column; gap: 4px; font-size: var(--text-xs-size); color: var(--color-text-muted); }
.pr-pw-wrap { display: flex; align-items: center; gap: var(--space-2); }
.pr-pw .input { min-width: 12rem; }

/* A warning about what the file is, not about what went wrong. */
.pr-warn {
  font-size: var(--text-xs-size); line-height: 1.55;
  color: var(--color-text); margin: 0;
  background: var(--color-warning-light);
  border-left: 3px solid var(--color-warning);
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  padding: var(--space-3);
}
</style>
