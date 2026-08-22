<script setup lang="ts">
/**
 * Bringing people in from a spreadsheet.
 *
 * Three steps, in the order that keeps somebody out of trouble: choose the
 * file, confirm what each column is, then rehearse before anything is written.
 * The rehearsal is not optional — the Import button only appears once one has
 * been run, because the useful moment to find out that a sheet names a
 * department nobody has heard of is before it lands.
 */
import { ref, computed } from "vue";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

interface Field { key: string; label: string; group: string; required: boolean; type: string; values: string[] | null }
interface Inspection { headings: string[]; sample: (string | number | null)[][]; rows: number; suggested: Record<string, string>; fields: Field[] }
interface Report {
  applied: boolean; rows_read: number; created: number; updated: number; skipped: number;
  problems: { row: number; reason: string }[]; problem_count: number;
  unmatched: Record<string, Record<string, number>>;
}

const file       = ref<File | null>(null);
const inspection = ref<Inspection | null>(null);
const mapping    = ref<Record<string, string>>({});
const report     = ref<Report | null>(null);
const busy       = ref(false);
const error      = ref("");

function org(): string | null { return currentOrgSlug.value; }

const requiredMissing = computed(() => {
  if (!inspection.value) return [];
  const mapped = Object.values(mapping.value);
  return inspection.value.fields
    .filter(f => f.required && !mapped.includes(f.key))
    .map(f => f.label);
});

/** A rehearsal that found nothing to write is not a reason to enable Import. */
const canApply = computed(() =>
  !!report.value && !report.value.applied && (report.value.created + report.value.updated) > 0
);

function chooseFile(event: Event): void {
  const input = event.target as HTMLInputElement;
  file.value = input.files?.[0] ?? null;
  inspection.value = null;
  report.value = null;
  error.value = "";
  if (file.value) void inspect();
}

/** A multipart request, so the sheet is sent as a file rather than encoded. */
async function send<T>(path: string, extra: Record<string, string> = {}): Promise<T> {
  const form = new FormData();
  form.append("file", file.value as File);
  for (const [k, v] of Object.entries(extra)) form.append(k, v);

  return await api<T>("POST", path, { org: org(), formData: form });
}

async function inspect(): Promise<void> {
  busy.value = true; error.value = "";
  try {
    const res = await send<{ data: Inspection }>("/api/v1/employees/import/inspect");
    inspection.value = res.data;
    mapping.value = { ...res.data.suggested };
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not read that file.";
  } finally { busy.value = false; }
}

async function run(apply: boolean): Promise<void> {
  if (apply && !confirm(`Write ${report.value?.created} new and ${report.value?.updated} updated records?`)) return;

  busy.value = true; error.value = "";
  try {
    const res = await send<{ data: Report }>("/api/v1/employees/import/run", {
      mapping: JSON.stringify(mapping.value),
      apply: apply ? "1" : "0",
    });
    report.value = res.data;
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "The import failed.";
  } finally { busy.value = false; }
}

/** Unmapped columns are ignored, which is a choice worth being able to make. */
function setMapping(heading: string, key: string): void {
  if (key === "") delete mapping.value[heading];
  else mapping.value[heading] = key;
  report.value = null;
}

function fieldLabel(key: string): string {
  return inspection.value?.fields.find(f => f.key === key)?.label ?? key;
}

const groups = computed(() => {
  const out: { group: string; items: Field[] }[] = [];
  for (const f of inspection.value?.fields ?? []) {
    const g = out.find(x => x.group === f.group);
    g ? g.items.push(f) : out.push({ group: f.group, items: [f] });
  }
  return out;
});
</script>

<template>
  <div class="view ei">
    <header class="ei-head">
      <div>
        <h2 class="ei-title">Import Employees</h2>
        <p class="ei-lead">
          Upload a spreadsheet, say which column is which, then rehearse it. Nothing is written
          until you have seen what would happen.
        </p>
      </div>
    </header>

    <p v-if="error" class="error">{{ error }}</p>

    <!-- ── 1. The file ─────────────────────────────────────────────────── -->
    <div class="ei-panel">
      <h3 class="ei-h">1 · The sheet</h3>
      <label class="ei-file">
        <input type="file" accept=".xlsx,.xls,.csv" @change="chooseFile" />
      </label>
      <p class="ei-note">
        The first row must be the column headings — it is what the mapping refers to.
        Format phone numbers as <strong>text</strong> in the sheet: a spreadsheet reads
        <code>+254…</code> as a number and drops the plus before this ever sees it.
      </p>
      <p v-if="inspection" class="ei-ok">
        Read {{ inspection.rows }} row{{ inspection.rows === 1 ? "" : "s" }}
        and {{ inspection.headings.length }} columns.
      </p>
    </div>

    <!-- ── 2. Mapping ──────────────────────────────────────────────────── -->
    <div v-if="inspection" class="ei-panel">
      <h3 class="ei-h">2 · What each column is</h3>
      <p class="ei-note">
        Guessed from the headings where they matched. Anything left as “Ignore” is not imported.
      </p>

      <p v-if="requiredMissing.length" class="ei-warn">
        <strong>{{ requiredMissing.join(", ") }}</strong>
        {{ requiredMissing.length === 1 ? "is" : "are" }} not mapped. Existing people can still be
        updated, but no new person can be created without
        {{ requiredMissing.length === 1 ? "it" : "them" }}.
      </p>

      <div class="ei-table-wrap">
        <table class="ei-table">
          <thead><tr><th>Column in your sheet</th><th>Becomes</th><th>First values</th></tr></thead>
          <tbody>
            <tr v-for="(h, i) in inspection.headings" :key="h + i">
              <td><strong>{{ h || "(no heading)" }}</strong></td>
              <td>
                <select class="input" :value="mapping[h] ?? ''" @change="setMapping(h, ($event.target as HTMLSelectElement).value)">
                  <option value="">Ignore this column</option>
                  <optgroup v-for="g in groups" :key="g.group" :label="g.group">
                    <option v-for="f in g.items" :key="f.key" :value="f.key">
                      {{ f.label }}{{ f.required ? " *" : "" }}
                    </option>
                  </optgroup>
                </select>
              </td>
              <td class="ei-sample">
                <span v-for="(row, r) in inspection.sample" :key="r">{{ row[i] ?? "—" }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="ei-actions">
        <button class="btn btn-secondary btn-sm" :disabled="busy" @click="run(false)">
          {{ busy ? "Working…" : "Rehearse" }}
        </button>
      </div>
    </div>

    <!-- ── 3. The rehearsal ────────────────────────────────────────────── -->
    <div v-if="report" class="ei-panel">
      <h3 class="ei-h">3 · {{ report.applied ? "What happened" : "What would happen" }}</h3>

      <div class="ei-figures">
        <div class="ei-fig"><span>Rows read</span><strong>{{ report.rows_read }}</strong></div>
        <div class="ei-fig"><span>{{ report.applied ? "Created" : "Would create" }}</span><strong>{{ report.created }}</strong></div>
        <div class="ei-fig"><span>{{ report.applied ? "Updated" : "Would update" }}</span><strong>{{ report.updated }}</strong></div>
        <div class="ei-fig" :class="{ 'is-bad': report.skipped > 0 }">
          <span>Skipped</span><strong>{{ report.skipped }}</strong>
        </div>
      </div>

      <p v-if="report.applied" class="ei-ok">
        Written. Running the same sheet again will correct these people rather than duplicate them.
      </p>

      <!--
        Named and counted, never created. A sheet with three spellings of one
        department must not become three departments — so this is a list of
        decisions for a person, not a warning to scroll past.
      -->
      <template v-if="Object.keys(report.unmatched).length">
        <h4 class="ei-sub">Names nothing here matches</h4>
        <p class="ei-note">
          These were left unset rather than created. Add them under Organisation if they are real,
          or correct the spelling in the sheet — then run it again.
        </p>
        <div v-for="(values, field) in report.unmatched" :key="field" class="ei-unmatched">
          <span class="ei-unmatched-h">{{ fieldLabel(String(field)) }}</span>
          <ul>
            <li v-for="(count, name) in values" :key="name">
              {{ name }} <em>× {{ count }}</em>
            </li>
          </ul>
        </div>
      </template>

      <template v-if="report.problems.length">
        <h4 class="ei-sub">Rows that were not imported ({{ report.problem_count }})</h4>
        <table class="ei-table">
          <thead><tr><th>Row</th><th>Why</th></tr></thead>
          <tbody>
            <tr v-for="p in report.problems" :key="p.row"><td>{{ p.row }}</td><td>{{ p.reason }}</td></tr>
          </tbody>
        </table>
        <p v-if="report.problem_count > report.problems.length" class="ei-note">
          Showing the first {{ report.problems.length }} of {{ report.problem_count }}.
        </p>
      </template>

      <div v-if="canApply" class="ei-actions">
        <button class="btn btn-primary btn-sm" :disabled="busy" @click="run(true)">
          {{ busy ? "Importing…" : `Import ${report.created + report.updated} records` }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../theme.css";

.ei { display: flex; flex-direction: column; gap: var(--space-4); }
.ei-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ei-lead  { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; max-width: 46rem; }

.ei-panel {
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  background: var(--color-surface-raised);
}
.ei-h   { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-3); }
.ei-sub { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: var(--space-4) 0 var(--space-2); }

.ei-note { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: var(--space-2) 0 0; line-height: 1.55; }
.ei-note code { font-family: var(--font-mono, monospace); }
.ei-ok   { font-size: var(--text-xs-size); color: var(--color-success); margin: var(--space-2) 0 0; }

.ei-warn {
  font-size: var(--text-xs-size); color: var(--color-text); line-height: 1.55;
  background: var(--color-warning-light);
  border-left: 3px solid var(--color-warning);
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  padding: var(--space-3); margin: var(--space-3) 0 0;
}

.ei-file input { font-size: var(--text-sm-size); }

.ei-table-wrap { overflow-x: auto; margin-top: var(--space-3); }
.ei-table { width: 100%; border-collapse: collapse; }
.ei-table th {
  text-align: left; font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  padding: var(--space-2) var(--space-3);
  border-bottom: 1px solid var(--color-border); background: var(--color-surface-sunken);
}
.ei-table td { font-size: var(--text-sm-size); padding: var(--space-2) var(--space-3); border-bottom: 1px solid var(--color-border); vertical-align: top; }
.ei-table tbody tr:last-child td { border-bottom: none; }

.ei-sample { color: var(--color-text-muted); font-size: var(--text-xs-size); }
.ei-sample span { display: block; }

.ei-actions { display: flex; justify-content: flex-end; gap: var(--space-2); margin-top: var(--space-4); }

.ei-figures { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: var(--space-3); }
.ei-fig {
  display: flex; flex-direction: column;
  padding: var(--space-3);
  border: 1px solid var(--color-border); border-radius: var(--radius-lg);
  background: var(--color-surface);
}
.ei-fig span   { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.ei-fig strong { font-size: var(--text-2xl-size); font-weight: 600; color: var(--color-text); }
/* Skipped rows are the number worth noticing, so they do not look like the others. */
.ei-fig.is-bad { border-color: var(--color-warning); background: var(--color-warning-light); }

.ei-unmatched { margin-top: var(--space-3); }
.ei-unmatched-h { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text); }
.ei-unmatched ul { display: flex; flex-wrap: wrap; gap: var(--space-2); list-style: none; margin: var(--space-2) 0 0; padding: 0; }
.ei-unmatched li {
  font-size: var(--text-xs-size);
  padding: 2px var(--space-3); border-radius: 999px;
  background: var(--color-warning-light); color: var(--color-text);
}
.ei-unmatched em { font-style: normal; color: var(--color-text-muted); }
</style>
