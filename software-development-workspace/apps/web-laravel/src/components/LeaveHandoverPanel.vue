<script setup lang="ts">
/**
 * Who covers your work while you are away — and what nobody can.
 *
 * The legacy asked for a name against every open task: 4,361 rows across 548
 * leave requests, whose notes are mostly the word "Test". That is what a form
 * looks like once people stop reading it, and they stopped because most of what
 * it asked made no sense — nobody can sit your certification exam or take over
 * your research thread for a fortnight.
 *
 * So this asks two different questions instead of one wrong one.
 *
 *   Coverable work asks who. Support, tickets, accounts and live deals, where
 *   the cost of waiting a fortnight lands on somebody outside the company.
 *
 *   The rest asks what to do about it — but only the ones falling due while you
 *   are away, because those are the only ones where the answer matters. Work
 *   due after you are back is simply waiting for you.
 */
import { ref, computed, onMounted } from "vue";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import { notifySaved, notifyFailed } from "../lib/toast";
import EmployeePicker from "./EmployeePicker.vue";

const props = defineProps<{ leaveId: number; label?: string }>();
const emit  = defineEmits<{ (e: "close"): void }>();

interface Row {
  id: number; reference: string | null; title: string;
  category: string; status: string; priority: string;
  deadline: string | null; is_overdue: boolean;
  project: { id: number; name: string } | null;
  is_transferable: boolean; block_reason: string | null;
  covered_by: { id: number; name: string } | null;
  handover_id: number | null; handover_note: string | null;
}
interface Payload {
  coverable: Row[]; at_risk: Row[]; personal: Row[];
  summary: { coverable: number; covered: number; at_risk: number; personal: number };
}

const data    = ref<Payload | null>(null);
const loading = ref(true);
const saving  = ref(false);

/** Who is being named against which task, before it is saved. */
const picking = ref<Record<number, number | null>>({});
const noteFor = ref<Record<number, string>>({});

const org = () => currentOrgSlug.value;

function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

async function load() {
  loading.value = true;
  try {
    data.value = await api<Payload>("GET", `/api/v1/leave/${props.leaveId}/handover`, { org: org() });
  } catch (e) { fail(e, "Could not load the handover."); }
  finally { loading.value = false; }
}

async function hand(row: Row) {
  const to = picking.value[row.id];
  if (!to) return;

  saving.value = true;
  try {
    data.value = await api<Payload>("POST", `/api/v1/leave/${props.leaveId}/handover`, {
      org: org(), body: { task_id: row.id, to_employee_id: to, note: noteFor.value[row.id] || null },
    });
    picking.value[row.id] = null;
    noteFor.value[row.id] = "";
    notifySaved("Cover arranged.");
  } catch (e) { fail(e, "Could not hand that over."); }
  finally { saving.value = false; }
}

async function takeBack(row: Row) {
  if (!row.handover_id) return;
  saving.value = true;
  try {
    data.value = await api<Payload>(
      "DELETE", `/api/v1/leave/${props.leaveId}/handover/${row.handover_id}`, { org: org() },
    );
    notifySaved("Cover removed.");
  } catch (e) { fail(e, "Could not remove the cover."); }
  finally { saving.value = false; }
}

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "No deadline";

const titleCase = (v: string) => v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());

/** Everything coverable that still has nobody against it. */
const uncovered = computed(() => (data.value?.coverable ?? []).filter((r) => !r.covered_by).length);

onMounted(load);
</script>

<template>
  <div class="hv-backdrop" @click.self="emit('close')">
    <aside class="hv-panel" role="dialog" aria-modal="true" aria-label="Leave handover">
      <header class="hv-head">
        <div>
          <h2 class="hv-title">Handover</h2>
          <p class="hv-sub">{{ label ?? "While you are away" }}</p>
        </div>
        <button class="hv-close" aria-label="Close" @click="emit('close')">×</button>
      </header>

      <p v-if="loading" class="hv-quiet">Loading…</p>

      <template v-else-if="data">
        <!-- The two numbers that decide whether anything needs doing. -->
        <div class="hv-summary">
          <span :class="['hv-pill', uncovered ? 'warn' : 'ok']">
            {{ data.summary.covered }} of {{ data.summary.coverable }} covered
          </span>
          <span v-if="data.summary.at_risk" class="hv-pill bad">
            {{ data.summary.at_risk }} will fall due with nobody on it
          </span>
        </div>

        <!-- ── Needs somebody ────────────────────────────────────────────── -->
        <section>
          <h3 class="hv-section">Needs cover</h3>
          <p class="hv-hint">
            Customer-facing and time-critical work. Waiting a fortnight costs
            somebody outside the company, so somebody here has to hold it.
          </p>

          <p v-if="!data.coverable.length" class="hv-quiet">Nothing here needs covering.</p>

          <article v-for="r in data.coverable" :key="r.id" class="hv-task">
            <div class="hv-task-head">
              <span class="hv-tag">{{ titleCase(r.category) }}</span>
              <span :class="['hv-due', { late: r.is_overdue }]">{{ date(r.deadline) }}</span>
            </div>
            <p class="hv-task-title">{{ r.title }}</p>

            <div v-if="r.covered_by" class="hv-covered">
              <span class="hv-pill ok">{{ r.covered_by.name }} is covering</span>
              <button class="link-btn" :disabled="saving" @click="takeBack(r)">Remove</button>
            </div>

            <div v-else class="hv-assign">
              <EmployeePicker v-model="picking[r.id]" placeholder="Who covers it?" />
              <input v-model="noteFor[r.id]" class="control" placeholder="Anything they need to know" />
              <button class="btn-primary btn-sm" :disabled="saving || !picking[r.id]" @click="hand(r)">
                Hand over
              </button>
            </div>
          </article>
        </section>

        <!-- ── Needs a decision ──────────────────────────────────────────── -->
        <section v-if="data.at_risk.length">
          <h3 class="hv-section">Falls due while you are away</h3>
          <p class="hv-hint">
            Nobody else can pick these up, and they will be late unless you
            finish them first, move the date, or decide to let them slip.
          </p>

          <article v-for="r in data.at_risk" :key="r.id" class="hv-task risk">
            <div class="hv-task-head">
              <span class="hv-tag">{{ titleCase(r.category) }}</span>
              <span class="hv-due late">{{ date(r.deadline) }}</span>
            </div>
            <p class="hv-task-title">{{ r.title }}</p>
            <p class="hv-reason">{{ r.block_reason }}</p>
          </article>
        </section>

        <!-- ── Waiting for them ──────────────────────────────────────────── -->
        <section v-if="data.personal.length">
          <h3 class="hv-section">Waiting until you are back</h3>
          <p class="hv-hint">
            Yours alone, and not due before you return. Nothing to decide.
          </p>

          <article v-for="r in data.personal" :key="r.id" class="hv-task muted">
            <div class="hv-task-head">
              <span class="hv-tag">{{ titleCase(r.category) }}</span>
              <span class="hv-due">{{ date(r.deadline) }}</span>
            </div>
            <p class="hv-task-title">{{ r.title }}</p>
            <p class="hv-reason">{{ r.block_reason }}</p>
          </article>
        </section>
      </template>
    </aside>
  </div>
</template>

<style scoped>
@reference "../theme.css";

.hv-backdrop { @apply fixed inset-0 flex justify-end z-50; background: rgb(0 0 0 / 0.28); }
.hv-panel {
  @apply flex flex-col overflow-y-auto; width: min(30rem, 100%); gap: var(--space-5);
  padding: var(--space-5); background: var(--color-surface-raised); box-shadow: var(--shadow-lg);
  animation: hv-in 180ms ease;
}
@keyframes hv-in { from { transform: translateX(12px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .hv-panel { animation: none; } }

.hv-head { @apply flex items-start justify-between; gap: var(--space-3); }
.hv-title { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); margin: 0; }
.hv-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }
.hv-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

.hv-summary { @apply flex flex-wrap; gap: var(--space-2); }
.hv-pill { padding: 2px var(--space-3); border-radius: 999px; font-size: var(--text-xs-size); border: 1px solid transparent; }
.hv-pill.ok   { background: var(--color-success-subtle); color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 30%, transparent); }
.hv-pill.warn { background: var(--color-warning-subtle); color: var(--color-warning-text); border-color: color-mix(in oklab, var(--color-warning-text) 30%, transparent); }
.hv-pill.bad  { background: var(--color-danger-subtle);  color: var(--color-danger-text);  border-color: color-mix(in oklab, var(--color-danger-text) 30%, transparent); }

.hv-section { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-1); }
.hv-hint { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0 0 var(--space-3); }
.hv-quiet { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; }

.hv-task {
  @apply flex flex-col; gap: var(--space-2);
  padding: var(--space-3); margin-bottom: var(--space-2);
  border: 1px solid var(--color-border); border-radius: var(--radius-md);
  background: var(--color-surface);
}
.hv-task.risk { border-color: color-mix(in oklab, var(--color-danger-text) 35%, transparent); background: var(--color-danger-subtle); }
.hv-task.muted { opacity: 0.75; }
.hv-task-head { @apply flex flex-wrap items-center justify-between; gap: var(--space-2); }
.hv-task-title { font-size: var(--text-sm-size); font-weight: 500; color: var(--color-text); margin: 0; }
.hv-reason { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; }

.hv-tag { padding: 1px var(--space-2); border-radius: var(--radius-sm); background: var(--color-surface-sunken); color: var(--color-text-muted); font-size: var(--text-xs-size); }
.hv-due { font-size: var(--text-xs-size); color: var(--color-text-muted); white-space: nowrap; }
.hv-due.late { color: var(--color-danger-text); font-weight: 500; }

.hv-covered { @apply flex flex-wrap items-center justify-between; gap: var(--space-2); }
.hv-assign { @apply flex flex-col; gap: var(--space-2); }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }
.link-btn:disabled { @apply cursor-not-allowed no-underline opacity-40; }

.btn-primary {
  @apply inline-flex cursor-pointer items-center justify-center border-none;
  padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500;
  background: var(--color-brand-base); color: var(--color-on-brand);
}
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }
.btn-sm { padding: 5px var(--space-3); font-size: var(--text-xs-size); }
</style>
