<script setup lang="ts">
/**
 * A single travel request.
 *
 * Five tabs because they answer different questions: is this trip necessary
 * (description, itinerary), is it affordable (expenses), what was actually
 * bought (bookings), and what has happened to it (activity).
 *
 * The approval panel appears only for the person the current stage is waiting
 * on. Showing approve and reject to everyone and refusing on click teaches
 * people to try, then to ask over email when it fails.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";

const props = defineProps<{ id: string }>();
const router = useRouter();
const org = () => currentOrgSlug.value;

type Tab = "description" | "itinerary" | "expenses" | "bookings" | "reconciliation" | "activity";

const STAGE_LABELS: Record<string, string> = {
  department_head: "Head of Department",
  function_head:   "Chief Manager (Function)",
  finance:         "Finance",
  executive:       "CFO",
};

const tab      = ref<Tab>("description");
const request  = ref<any>(null);
const loading  = ref(true);
const saving   = ref(false);
const error    = ref<string | null>(null);
const notice   = ref<string | null>(null);

const showBooking = ref(false);
const newBooking  = ref({
  booking_type: "flight", vendor: "", status: "pending",
  from_location: "", to_location: "", booking_date: "", total_cost: "", currency: "KES",
});
const decisionComment = ref("");

// ── Reconciliation ──────────────────────────────────────────────────────────
const evidenceFor = ref<any>(null);
const evidenceForm = ref({ explanation: "", amount: "", file: null as File | null });

const NEEDS: Record<string, string> = {
  receipt: "A receipt", acknowledgement: "Your confirmation", none: "Nothing",
};
const FROM: Record<string, string> = {
  requestor: "the traveller", finance: "finance", either: "either party",
};

/** Only once a trip has been approved is there anything to reconcile. */
const canReconcile = computed(() =>
  ["approved", "disbursed"].includes(request.value?.status));

const outstanding = computed(() =>
  (request.value?.items ?? []).filter((i: any) => i.awaiting_evidence));

function openEvidence(item: any) {
  evidenceFor.value = item;
  evidenceForm.value = { explanation: "", amount: item.amount_claimed ?? "", file: null };
}

function pickFile(e: Event) {
  evidenceForm.value.file = (e.target as HTMLInputElement).files?.[0] ?? null;
}

async function submitEvidence() {
  const item = evidenceFor.value;
  if (!item || !evidenceForm.value.explanation.trim()) return;

  saving.value = true;
  error.value = null;
  try {
    if (item.evidence_type === "receipt") {
      // Multipart, because a receipt is a file.
      const fd = new FormData();
      fd.append("kind", "receipt");
      fd.append("explanation", evidenceForm.value.explanation);
      if (evidenceForm.value.amount) fd.append("amount", String(evidenceForm.value.amount));
      if (evidenceForm.value.file) fd.append("receipt", evidenceForm.value.file);

      await api("POST", `/api/v1/travel-requests/${props.id}/items/${item.id}/evidence`,
        { org: org(), formData: fd });
    } else {
      await api("POST", `/api/v1/travel-requests/${props.id}/items/${item.id}/evidence`, {
        org: org(),
        body: { kind: "acknowledgement", explanation: evidenceForm.value.explanation },
      });
    }
    evidenceFor.value = null;
    notice.value = "Evidence recorded.";
    await load();
  } catch (e) { fail(e); } finally { saving.value = false; }
}

async function closeRequest() {
  saving.value = true;
  error.value = null;
  try {
    await api("POST", `/api/v1/travel-requests/${props.id}/close`, { org: org() });
    notice.value = "Trip closed.";
    await load();
  } catch (e) { fail(e); } finally { saving.value = false; }
}

function evidenceUrl(id: number): string {
  return `/api/v1/travel-requests/${props.id}/evidence/${id}/download`;
}

function fail(e: unknown) {
  error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Something went wrong.";
}

async function load() {
  loading.value = true;
  try {
    const res = await api<{ data: any }>("GET", `/api/v1/travel-requests/${props.id}`, { org: org() });
    request.value = res.data;
  } catch (e) { fail(e); } finally { loading.value = false; }
}

/** The stage the request is waiting on, if any. */
const pending = computed(() =>
  (request.value?.approvals ?? [])
    .filter((a: any) => a.status === "pending")
    .sort((a: any, b: any) => a.sequence - b.sequence)[0] ?? null);

/**
 * Whether this viewer can decide.
 *
 * The API is the authority — it returns 403 otherwise — so this only decides
 * whether to offer the action. Guessing the rule here would drift from the
 * policy, which also honours named delegates.
 */
const canDecide = computed(() => !!pending.value && request.value?.can_decide === true);

async function decide(decision: "approve" | "reject") {
  saving.value = true;
  error.value = null;
  try {
    const res = await api<{ data: any }>("POST", `/api/v1/travel-requests/${props.id}/decision`, {
      org: org(), body: { decision, comment: decisionComment.value || null },
    });
    request.value = { ...request.value, ...res.data };
    decisionComment.value = "";
    notice.value = decision === "approve" ? "Approved." : "Returned to the traveller.";
    await load();
  } catch (e) { fail(e); } finally { saving.value = false; }
}

async function addBooking() {
  saving.value = true;
  try {
    const body: Record<string, unknown> = { ...newBooking.value };
    Object.keys(body).forEach((k) => body[k] === "" && delete body[k]);

    await api("POST", `/api/v1/travel-requests/${props.id}/bookings`, { org: org(), body });
    showBooking.value = false;
    newBooking.value = { booking_type: "flight", vendor: "", status: "pending",
      from_location: "", to_location: "", booking_date: "", total_cost: "", currency: "KES" };
    await load();
  } catch (e) { fail(e); } finally { saving.value = false; }
}

const money = (v: unknown) =>
  v === null || v === undefined || v === "" ? "—" : Number(v).toLocaleString();

const statusTone = (s: string) =>
  s === "approved" || s === "disbursed" ? "ok"
  : s === "rejected" ? "bad"
  : s === "draft" || s === "cancelled" ? "neutral" : "warn";

/** Estimated against actual, which is the comparison the tab exists for. */
const actualTotal = computed(() =>
  (request.value?.bookings ?? []).reduce((sum: number, b: any) => sum + Number(b.total_cost ?? 0), 0));

onMounted(load);
</script>

<template>
  <div class="td-wrap">
    <button class="link-btn" @click="router.push({ name: 'travel-requests' })">← Travel Requests</button>

    <div v-if="error" class="alert-error">{{ error }}</div>
    <div v-if="notice" class="alert-success">{{ notice }}</div>
    <div v-if="loading" class="td-loading">Loading…</div>

    <template v-else-if="request">
      <header class="td-header">
        <h1 class="td-title">{{ request.reference ?? "Travel request" }}</h1>
        <span :class="['pill', `pill-${statusTone(request.status)}`]">
          {{ String(request.status).replace("_", " ") }}
        </span>
      </header>

      <dl class="td-summary">
        <div><dt>Travel dates</dt><dd>{{ request.departure_date }} → {{ request.return_date }} ({{ request.days }} days)</dd></div>
        <div><dt>Destination</dt><dd>{{ request.destination?.name ?? "—" }}</dd></div>
        <div><dt>Traveller</dt><dd>{{ request.employee?.name ?? "—" }}</dd></div>
        <div><dt>Client</dt><dd>{{ request.account?.name ?? "—" }}</dd></div>
        <div><dt>Priority</dt><dd>{{ request.priority ?? "—" }}</dd></div>
        <div><dt>Linked task</dt><dd>{{ request.linked_task_ref ?? "—" }}</dd></div>
      </dl>

      <!-- The chain, in full. A single "approved by" would read as finished
           while the request is still two signatures short. -->
      <section v-if="request.approvals?.length" class="td-chain">
        <h2 class="td-chain-title">Approval chain</h2>
        <ol class="td-stages">
          <li v-for="a in request.approvals" :key="a.id" :class="['td-stage', `is-${a.status}`]">
            <span class="td-stage-name">{{ STAGE_LABELS[a.stage] ?? a.stage }}</span>
            <span class="td-stage-status">{{ a.status }}</span>
            <span v-if="a.expected_approver" class="td-stage-who">{{ a.expected_approver.name }}</span>
            <span v-if="a.comment" class="td-stage-comment">“{{ a.comment }}”</span>
          </li>
        </ol>

        <div v-if="canDecide" class="td-decide">
          <textarea v-model="decisionComment" class="control" rows="2"
                    placeholder="Comment (optional)"></textarea>
          <div class="td-decide-actions">
            <button class="btn-ghost" :disabled="saving" @click="decide('reject')">Reject</button>
            <button class="btn-primary" :disabled="saving" @click="decide('approve')">Approve</button>
          </div>
        </div>
      </section>

      <nav class="td-tabs" role="tablist">
        <button v-for="t in (['description','itinerary','expenses','bookings','reconciliation','activity'] as Tab[])"
                :key="t" :class="['td-tab', { active: tab === t }]" @click="tab = t">
          {{ t.charAt(0).toUpperCase() + t.slice(1) }}
        </button>
      </nav>

      <section class="td-panel">
        <!-- Description -->
        <template v-if="tab === 'description'">
          <h3 class="td-h3">Purpose</h3>
          <p class="td-text">{{ request.purpose || "—" }}</p>
          <h3 class="td-h3">Business impact if not approved</h3>
          <p class="td-text">{{ request.business_impact || "—" }}</p>
          <template v-if="request.advance_required">
            <h3 class="td-h3">Advance</h3>
            <p class="td-text">
              {{ request.currency }} {{ money(request.advance_required) }} —
              {{ request.advance_justification || "no justification given" }}
            </p>
          </template>
        </template>

        <!-- Itinerary -->
        <template v-else-if="tab === 'itinerary'">
          <table class="td-table">
            <thead><tr><th>Date</th><th>Activity</th><th>Time</th><th>Estimated</th></tr></thead>
            <tbody>
              <tr v-if="!request.itinerary?.length"><td colspan="4" class="td-none">No itinerary recorded.</td></tr>
              <tr v-for="i in request.itinerary" :key="i.id">
                <td>{{ String(i.starts_on).slice(0, 10) }}</td>
                <td>{{ i.activity }}</td>
                <td>{{ i.at_time ?? "Full day" }}</td>
                <td class="td-num">{{ money(i.estimated_cost) }}</td>
              </tr>
            </tbody>
          </table>
        </template>

        <!-- Expenses: estimated against actual -->
        <template v-else-if="tab === 'expenses'">
          <table class="td-table">
            <thead><tr><th>Category</th><th>Description</th><th>Estimated</th><th>Policy</th></tr></thead>
            <tbody>
              <tr v-if="!request.items?.length"><td colspan="4" class="td-none">No expenses.</td></tr>
              <tr v-for="i in request.items" :key="i.id">
                <td>{{ i.category?.name ?? "—" }}</td>
                <td>{{ i.description ?? "—" }}</td>
                <td class="td-num">{{ request.currency }} {{ money(i.amount_claimed) }}</td>
                <td>
                  <span v-if="i.exceeds_policy" class="pill pill-warn">Exceeds policy</span>
                  <span v-else-if="i.policy_limit" class="pill pill-ok">Within policy</span>
                  <span v-else class="pill pill-neutral">No limit set</span>
                </td>
              </tr>
              <tr class="td-total">
                <td colspan="2">Estimated total</td>
                <td class="td-num">{{ request.currency }} {{ money(request.total_claimed) }}</td>
                <td></td>
              </tr>
              <tr v-if="actualTotal > 0" class="td-total">
                <td colspan="2">Actually booked</td>
                <td class="td-num">{{ request.currency }} {{ money(actualTotal) }}</td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </template>

        <!-- Bookings -->
        <template v-else-if="tab === 'bookings'">
          <div class="td-panel-head">
            <p class="td-note">
              What was actually bought. Kept apart from the estimate, so the figure the
              approval was given against is not overwritten.
            </p>
            <button class="btn-primary" @click="showBooking = true">+ New booking</button>
          </div>

          <table class="td-table">
            <thead><tr><th>Type</th><th>Vendor</th><th>Route</th><th>Date</th><th>Cost</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-if="!request.bookings?.length"><td colspan="6" class="td-none">No bookings yet.</td></tr>
              <tr v-for="b in request.bookings" :key="b.id">
                <td class="td-cap">{{ b.booking_type }}</td>
                <td>{{ b.vendor ?? "—" }}</td>
                <td>{{ b.from_location || "—" }} → {{ b.to_location || "—" }}</td>
                <td>{{ b.booking_date ? String(b.booking_date).slice(0, 10) : "—" }}</td>
                <td class="td-num">{{ b.currency }} {{ money(b.total_cost) }}</td>
                <td><span class="pill pill-neutral td-cap">{{ b.status }}</span></td>
              </tr>
            </tbody>
          </table>
        </template>

        <!-- Reconciliation -->
        <template v-else-if="tab === 'reconciliation'">
          <p v-if="!canReconcile" class="td-note">
            Reconciliation opens once the trip is approved. There is nothing to evidence
            until the money has been committed.
          </p>

          <template v-else>
            <div class="td-panel-head">
              <p class="td-note">
                What each line needs to close, and whose job it is. Asking the wrong party is
                what leaves requests open indefinitely.
              </p>
              <button v-if="request.can_close && outstanding.length === 0 && request.status !== 'closed'"
                      class="btn-primary" :disabled="saving" @click="closeRequest">
                Close trip
              </button>
            </div>

            <table class="td-table">
              <thead><tr><th>Category</th><th>To close</th><th>From</th><th>Evidence</th><th></th></tr></thead>
              <tbody>
                <tr v-for="i in request.items" :key="i.id">
                  <td>{{ i.category?.name ?? "—" }}</td>
                  <td>{{ NEEDS[i.evidence_type] ?? "—" }}</td>
                  <td class="td-cap">{{ i.evidence_type === 'none' ? "—" : FROM[i.evidence_provider] }}</td>
                  <td>
                    <span v-if="i.evidence_type === 'none'" class="pill pill-neutral">Settled</span>
                    <ul v-else-if="i.evidence?.length" class="td-evidence">
                      <li v-for="e in i.evidence" :key="e.id">
                        <a v-if="e.has_file" :href="evidenceUrl(e.id)" target="_blank" class="link-btn">
                          {{ e.original_name }}
                        </a>
                        <span v-else class="pill pill-ok">Acknowledged</span>
                        <em>{{ e.explanation }}</em>
                        <em v-if="e.submitted_by" class="td-evidence-who">— {{ e.submitted_by }}</em>
                      </li>
                    </ul>
                    <span v-else class="pill pill-warn">Outstanding</span>
                  </td>
                  <td>
                    <button v-if="i.awaiting_evidence && request.status !== 'closed'"
                            class="link-btn" @click="openEvidence(i)">
                      {{ i.evidence_type === 'receipt' ? "Upload receipt" : "Acknowledge" }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>

            <p v-if="outstanding.length && request.can_close" class="td-note">
              Cannot close yet — still waiting on
              {{ outstanding.map((i: any) => i.category?.name).join(", ") }}.
            </p>
          </template>
        </template>

        <!-- Activity -->
        <template v-else>
          <ol class="td-timeline">
            <li v-if="!request.activity?.length" class="td-none">Nothing recorded yet.</li>
            <li v-for="a in request.activity" :key="a.id" class="td-event">
              <span class="td-event-when">{{ a.occurred_at?.slice(0, 10) }}</span>
              <span class="td-event-what">
                <strong class="td-cap">{{ a.event }}</strong>
                <em v-if="a.description">{{ a.description }}</em>
                <em v-if="a.actor" class="td-event-who">by {{ a.actor }}</em>
              </span>
            </li>
          </ol>
        </template>
      </section>
    </template>

    <!-- Evidence -->
    <div v-if="evidenceFor" class="td-modal-backdrop" @click.self="evidenceFor = null">
      <div class="td-modal">
        <h3 class="td-modal-title">
          {{ evidenceFor.evidence_type === "receipt" ? "Upload receipt" : "Acknowledge receipt" }}
          — {{ evidenceFor.category?.name }}
        </h3>

        <p v-if="evidenceFor.evidence_type !== 'receipt'" class="td-note">
          There is no document to produce for this, but an allowance nobody confirms
          receiving is one nobody can show was paid.
        </p>

        <label v-if="evidenceFor.evidence_type === 'receipt'" class="td-field">
          <span>Receipt (PDF or image)</span>
          <input class="control" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" @change="pickFile" />
        </label>

        <label v-if="evidenceFor.evidence_type === 'receipt'" class="td-field">
          <span>Amount actually spent</span>
          <input v-model="evidenceForm.amount" class="control" type="number" min="0" />
        </label>

        <label class="td-field">
          <!-- Required: a receipt nobody explained cannot be checked without
               asking the person who uploaded it. -->
          <span>Explanation</span>
          <textarea v-model="evidenceForm.explanation" class="control" rows="3"
                    placeholder="What this was for"></textarea>
        </label>

        <div class="td-modal-actions">
          <button class="btn-ghost" @click="evidenceFor = null">Cancel</button>
          <button class="btn-primary" :disabled="saving || !evidenceForm.explanation.trim()"
                  @click="submitEvidence">Save</button>
        </div>
      </div>
    </div>

    <!-- New booking -->
    <div v-if="showBooking" class="td-modal-backdrop" @click.self="showBooking = false">
      <div class="td-modal">
        <h3 class="td-modal-title">New booking</h3>

        <label class="td-field"><span>Type</span>
          <select v-model="newBooking.booking_type" class="control">
            <option value="flight">Flight</option><option value="hotel">Hotel</option>
            <option value="transport">Transport</option><option value="other">Other</option>
          </select>
        </label>
        <label class="td-field"><span>Vendor / provider</span>
          <input v-model="newBooking.vendor" class="control" /></label>
        <label class="td-field"><span>Status</span>
          <select v-model="newBooking.status" class="control">
            <option value="pending">Pending</option><option value="confirmed">Confirmed</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </label>
        <div class="td-row">
          <label class="td-field"><span>From</span><input v-model="newBooking.from_location" class="control" /></label>
          <label class="td-field"><span>To</span><input v-model="newBooking.to_location" class="control" /></label>
        </div>
        <div class="td-row">
          <label class="td-field"><span>Booking date</span><input v-model="newBooking.booking_date" class="control" type="date" /></label>
          <label class="td-field"><span>Total cost</span><input v-model="newBooking.total_cost" class="control" type="number" min="0" /></label>
        </div>

        <div class="td-modal-actions">
          <button class="btn-ghost" @click="showBooking = false">Cancel</button>
          <button class="btn-primary" :disabled="saving" @click="addBooking">Save</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.td-wrap { padding: var(--space-6); max-width: 72rem; @apply flex flex-col; gap: var(--space-4); }
.td-loading { color: var(--color-text-muted); padding: var(--space-6); }

.td-header { @apply flex items-center; gap: var(--space-3); }
.td-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }

.td-summary { @apply grid; gap: var(--space-3); grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); margin: 0; }
.td-summary dt { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.td-summary dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; text-transform: capitalize; }

.td-chain {
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-surface-sunken);
}
.td-chain-title { font-size: var(--text-sm-size); font-weight: 600; margin: 0 0 var(--space-3); color: var(--color-text); }
.td-stages { list-style: none; margin: 0; padding: 0; @apply flex flex-col; gap: var(--space-2); }
.td-stage { @apply flex flex-wrap items-baseline; gap: var(--space-2); font-size: var(--text-sm-size); }
.td-stage-name { color: var(--color-text); font-weight: 500; min-width: 12rem; }
.td-stage-status { font-size: var(--text-xs-size); text-transform: capitalize; color: var(--color-text-muted); }
.td-stage.is-approved .td-stage-status { color: var(--color-success-text); }
.td-stage.is-rejected .td-stage-status { color: var(--color-danger-text); }
.td-stage.is-pending  .td-stage-status { color: var(--color-warning-text); }
.td-stage-who { font-size: var(--text-xs-size); color: var(--color-text-light); }
.td-stage-comment { font-size: var(--text-xs-size); color: var(--color-text-muted); font-style: italic; }

.td-decide { margin-top: var(--space-4); @apply flex flex-col; gap: var(--space-2); }
.td-decide-actions { @apply flex justify-end; gap: var(--space-2); }

.td-tabs { @apply flex flex-wrap gap-1; border-bottom: 1px solid var(--color-border); }
.td-tab {
  @apply cursor-pointer border-none bg-transparent;
  padding: var(--space-3) var(--space-4);
  font-family: inherit; font-size: var(--text-sm-size);
  color: var(--color-text-muted);
  border-bottom: 2px solid transparent;
}
.td-tab:hover  { color: var(--color-text); }
.td-tab.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }

.td-panel { @apply flex flex-col; gap: var(--space-3); }
.td-panel-head { @apply flex flex-wrap items-center justify-between; gap: var(--space-3); }
.td-h3 { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.td-text { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; white-space: pre-wrap; }
.td-note { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; max-width: 58ch; }

.td-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.td-table th {
  text-align: left; padding: var(--space-2) var(--space-3);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  text-transform: uppercase; letter-spacing: 0.04em;
  border-bottom: 1px solid var(--color-border);
}
.td-table td { padding: var(--space-3); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.td-num { font-variant-numeric: tabular-nums; }
.td-cap { text-transform: capitalize; }
.td-total td { font-weight: 600; }
.td-none { text-align: center; color: var(--color-text-light); padding: var(--space-5); }

.td-timeline { list-style: none; margin: 0; padding: 0; @apply flex flex-col; gap: var(--space-3); }
.td-event { @apply flex; gap: var(--space-4); font-size: var(--text-sm-size); }
.td-event-when { color: var(--color-text-muted); min-width: 6rem; font-variant-numeric: tabular-nums; }
.td-event-what { @apply flex flex-col; }
.td-event-what em { font-style: normal; font-size: var(--text-xs-size); color: var(--color-text-muted); }
.td-event-who { color: var(--color-text-light); }

.td-evidence { list-style: none; margin: 0; padding: 0; @apply flex flex-col; gap: var(--space-1); }
.td-evidence li { @apply flex flex-wrap items-baseline; gap: var(--space-2); }
.td-evidence em { font-style: normal; font-size: var(--text-xs-size); color: var(--color-text-muted); }
.td-evidence-who { color: var(--color-text-light); }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.td-row { @apply flex; gap: var(--space-3); }
.td-field { @apply flex flex-col; gap: var(--space-1); flex: 1; }
.td-field span { font-size: var(--text-xs-size); color: var(--color-text-muted); }

.pill { display: inline-block; width: fit-content; padding: 2px var(--space-2); border-radius: 999px; font-size: var(--text-xs-size); }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); }
.pill-bad     { background: var(--color-danger-subtle);  color: var(--color-danger-text); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); }

.td-modal-backdrop { @apply fixed inset-0 flex items-center justify-center z-50; background: rgb(0 0 0 / 0.45); padding: var(--space-4); }
.td-modal {
  @apply flex flex-col w-full; max-width: 30rem; gap: var(--space-3);
  padding: var(--space-5); background: var(--color-surface-raised);
  border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
  max-height: 90vh; overflow-y: auto;
}
.td-modal-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.td-modal-actions { @apply flex justify-end; gap: var(--space-2); margin-top: var(--space-2); }

.btn-primary, .btn-ghost {
  @apply cursor-pointer; padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-ghost   { background: transparent; color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-primary:disabled, .btn-ghost:disabled { @apply cursor-not-allowed opacity-50; }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0 self-start; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }

.alert-error, .alert-success { padding: var(--space-3) var(--space-4); border-radius: var(--radius-md); font-size: var(--text-sm-size); }
.alert-error   { background: var(--color-danger-subtle);  color: var(--color-danger-text); }
.alert-success { background: var(--color-success-subtle); color: var(--color-success-text); }
</style>
