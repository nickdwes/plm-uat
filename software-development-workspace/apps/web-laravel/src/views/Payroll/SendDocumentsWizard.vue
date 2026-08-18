<script setup lang="ts">
/**
 * Sending a batch of payroll documents: recipients, options, review.
 *
 * Three steps because the designs draw three, and because they are three
 * genuinely different questions — who, how, and are you sure. The last exists
 * to be read: this is the screen after which a hundred and fifty people have
 * their pay details in a personal inbox, and there is no recalling it.
 *
 * Payslips and P9As use this same screen. They differ in four things — a title,
 * two endpoints and what the amount column means — and in nothing that matters
 * to the person operating it. A second copy would have been the one that
 * quietly stopped listing the people who cannot be sent one.
 */
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";

const props = defineProps<{
  /** "Send Payslips" — the heading and the button. */
  title: string;
  sendLabel: string;
  /** What the batch is about: a period name, or a tax year. */
  context: string;
  /** How the review step names it: "Payroll period" / "Tax year". */
  contextLabel: string;
  recipientsUrl: string;
  sendUrl: string;
  defaultSubject: string;
  /** "PDF payslip, encrypted" — what is going in the attachment. */
  attachmentLabel: string;
  /** Header for the money column, which is not the same figure in both flows. */
  amountLabel: string;
  /** "payslip" / "P9A", for the sentences that name it. */
  noun: string;
  doneTitle: string;
  backLabel: string;
}>();

const router = useRouter();
const org = () => currentOrgSlug.value;


interface Recipient {
  employee_id: number; name: string; employee_number: string;
  department: string | null; email: string | null; net_pay: number;
  sendable: boolean; blocked_reason: string | null;
}

const step         = ref(1);
const loading      = ref(true);
const error        = ref("");
const recipients   = ref<Recipient[]>([]);
const chosen       = ref<Set<number>>(new Set());
const query        = ref("");
const passwordHint = ref("");
const options      = ref({ subject: "", message: "", portal: false });
const sending      = ref(false);
const result       = ref<{ sent: number; held: number; failed: number; results: any[] } | null>(null);

const sendable = computed(() => recipients.value.filter((r) => r.sendable));
const blocked  = computed(() => recipients.value.filter((r) => !r.sendable));
const chosenCount = computed(() => chosen.value.size);
const visible = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return recipients.value;
  return recipients.value.filter((r) =>
    r.name.toLowerCase().includes(q)
    || (r.email ?? "").toLowerCase().includes(q)
    || r.employee_number.toLowerCase().includes(q));
});

async function load(): Promise<void> {
  loading.value = true; error.value = "";
  try {
    const [delivery, docs] = await Promise.all([
      api<{ recipients: Recipient[] }>("GET", props.recipientsUrl, { org: org() }),
      api<{ summary: string }>("GET", "/api/v1/payroll-config/document-settings", { org: org() }),
    ]);
        recipients.value = delivery.recipients;
    passwordHint.value = docs.summary;
    options.value.subject = props.defaultSubject;
    // Only those who can actually receive one start ticked.
    chosen.value = new Set(delivery.recipients.filter((r) => r.sendable).map((r) => r.employee_id));
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : "Could not load the recipients.";
  } finally { loading.value = false; }
}

function toggle(r: Recipient): void {
  if (!r.sendable) return;
  const next = new Set(chosen.value);
  if (next.has(r.employee_id)) next.delete(r.employee_id); else next.add(r.employee_id);
  chosen.value = next;
}

function toggleAll(): void {
  chosen.value = chosenCount.value === sendable.value.length
    ? new Set()
    : new Set(sendable.value.map((r) => r.employee_id));
}

async function send(): Promise<void> {
  sending.value = true; error.value = "";
  try {
    result.value = await api("POST", props.sendUrl, {
      org: org(),
      body: {
        employee_ids: [...chosen.value],
        subject: options.value.subject || null,
        message: options.value.message || null,
        portal: options.value.portal,
      },
    });
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "The send failed.";
  } finally { sending.value = false; }
}

function money(n: number): string {
  return new Intl.NumberFormat("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
}

onMounted(load);
</script>

<template>
  <div class="view">
    <div class="sp-head">
      <div>
        <h2>{{ title }} <span class="sp-period">{{ context }}</span></h2>
        <button class="link" @click="router.back()">Cancel</button>
      </div>
      <div>
        <button v-if="step < 3" class="btn btn-primary" :disabled="step === 1 && !chosenCount"
                @click="step = step + 1">Next</button>
        <button v-else class="btn btn-primary" :disabled="sending || !chosenCount" @click="send">
          {{ sending ? "Sending…" : sendLabel }}
        </button>
      </div>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="loading" class="quiet">Loading…</p>

    <div v-else class="sp-body">
      <ol class="sp-steps">
        <li v-for="(s, i) in ['Select Recipients', 'Delivery Options', 'Review & Confirm']" :key="s"
            :class="['sp-step', { on: step === i + 1, done: step > i + 1 }]"
            @click="step > i + 1 ? (step = i + 1) : null">
          <span class="sp-step-n">{{ step > i + 1 ? "✓" : i + 1 }}</span>
          <span>
            <strong>{{ s }}</strong>
            <em>{{ ["Choose employees", "Choose how to send", "Review & Send"][i] }}</em>
          </span>
        </li>
      </ol>

      <div class="sp-panel">
        <template v-if="step === 1">
          <div class="sp-panel-head">
            <label class="sp-all">
              <input type="checkbox" :checked="chosenCount === sendable.length && sendable.length > 0"
                     @change="toggleAll" />
              <span>Select All</span>
            </label>
            <input v-model="query" class="control sp-search" placeholder="Search by name, email, id" />
          </div>

          <!--
            The people who cannot be sent one are shown rather than silently
            dropped. A document that never arrives is noticed by the person it
            belonged to, usually at the worst moment.
          -->
          <p v-if="blocked.length" class="sp-blocked">
            <strong>{{ blocked.length }}</strong>
            {{ blocked.length === 1 ? "employee cannot be sent one" : "employees cannot be sent one" }}
            and {{ blocked.length === 1 ? "is" : "are" }} not selectable below. Fix the record and come back.
          </p>

          <table class="sp-table">
            <thead>
              <tr><th></th><th>Employee</th><th>ID</th><th>Department</th><th>Email</th><th class="num">{{ amountLabel }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="r in visible" :key="r.employee_id" :class="{ 'sp-off': !r.sendable }">
                <td>
                  <input type="checkbox" :disabled="!r.sendable"
                         :checked="chosen.has(r.employee_id)" @change="toggle(r)" />
                </td>
                <td>
                  <span class="sp-name">{{ r.name }}</span>
                  <span v-if="r.blocked_reason" class="sp-why">{{ r.blocked_reason }}</span>
                </td>
                <td class="mono">{{ r.employee_number }}</td>
                <td>{{ r.department ?? "—" }}</td>
                <td class="mono">{{ r.email ?? "—" }}</td>
                <td class="num">{{ money(r.net_pay) }}</td>
              </tr>
            </tbody>
          </table>
        </template>

        <template v-else-if="step === 2">
          <h3 class="sp-h3">Delivery Options</h3>
          <p class="sp-sub">How the {{ noun }}s reach people.</p>

          <label class="field"><span>Email subject</span>
            <input v-model="options.subject" class="control" />
          </label>

          <label class="field"><span>Message</span>
            <textarea v-model="options.message" class="control" rows="4"
                      placeholder="Anything to say alongside it. Left blank, the email still explains what is attached and how to open it."></textarea>
          </label>

          <label class="check">
            <input v-model="options.portal" type="checkbox" />
            <span>Also post a notification in the portal</span>
          </label>
          <p class="sp-note">Only reaches employees whose record is linked to a login.</p>

          <!--
            Stated as settled rather than offered. The designs draw "Attach PDF"
            and "Password Protect" as choices; neither is one here — the document
            is the attachment, and it cannot be produced unprotected.
          -->
          <div class="sp-fixed">
            <p><strong>The PDF is attached and encrypted.</strong> Neither is optional.</p>
            <p>It opens with: <strong>{{ passwordHint }}</strong></p>
            <router-link class="link" :to="{ name: 'payroll-config' }">Change how documents are locked</router-link>
          </div>
        </template>

        <template v-else>
          <h3 class="sp-h3">Review &amp; Confirm</h3>
          <p class="sp-sub">There is no recalling this. Read it before you send it.</p>

          <dl class="sp-review">
            <dt>{{ contextLabel }}</dt><dd>{{ context }}</dd>
            <dt>Recipients</dt>
            <dd>
              {{ chosenCount }} {{ chosenCount === 1 ? "employee" : "employees" }}
              <button class="link" @click="step = 1">Edit</button>
            </dd>
            <dt>Delivery</dt>
            <dd>
              Email<template v-if="options.portal">, portal notification</template>
              <button class="link" @click="step = 2">Edit</button>
            </dd>
            <dt>Subject</dt><dd>{{ options.subject || "—" }}</dd>
            <dt>Attachment</dt><dd>{{ attachmentLabel }}</dd>
            <dt>Opens with</dt><dd>{{ passwordHint }}</dd>
            <template v-if="blocked.length">
              <dt>Held back</dt>
              <dd>{{ blocked.length }} not sent — {{ blocked.map(b => b.name).join(", ") }}</dd>
            </template>
          </dl>
        </template>
      </div>
    </div>

    <div v-if="result" class="overlay" @click.self="result = null">
      <div class="dialog" role="dialog" aria-modal="true">
        <div class="done-mark" aria-hidden="true">✓</div>
        <h2>{{ doneTitle }}</h2>
        <p class="muted">
          {{ result.sent }} sent for {{ context }}.
          <template v-if="result.held">{{ result.held }} held back.</template>
          <template v-if="result.failed">{{ result.failed }} failed.</template>
        </p>

        <!-- Named, because a count nobody can act on is not a result. -->
        <ul v-if="result.held || result.failed" class="sp-outcome">
          <li v-for="r in result.results.filter((x) => x.status !== 'sent')" :key="r.employee_id">
            <strong>{{ r.name }}</strong> — {{ r.detail ?? r.status }}
          </li>
        </ul>

        <div class="dialog-actions">
          <button class="btn btn-primary" @click="router.push({ name: 'payroll' })">{{ backLabel }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.sp-head { @apply flex flex-wrap items-start justify-between gap-3; margin-bottom: 18px; }
.sp-head h2 { @apply font-semibold; margin: 0; color: var(--color-text); }
.sp-period { color: var(--color-text-light); font-weight: 400; }

.sp-body { @apply grid gap-5; grid-template-columns: 220px minmax(0, 1fr); align-items: start; }
@media (max-width: 860px) { .sp-body { grid-template-columns: 1fr; } }

.sp-steps { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.sp-step { display: flex; gap: 10px; padding: 10px 12px; border-radius: 8px; font-size: .85rem; }
.sp-step.done { cursor: pointer; }
.sp-step.on { background: var(--color-brand-subtle); }
.sp-step-n { width: 22px; height: 22px; border-radius: 999px; display: grid; place-items: center;
  background: var(--color-surface-sunken); font-size: .75rem; flex-shrink: 0; }
.sp-step.on .sp-step-n { background: var(--color-brand-base); color: var(--color-on-brand); }
.sp-step.done .sp-step-n { background: var(--color-success-light); color: var(--color-success); }
.sp-step strong { display: block; color: var(--color-text); }
.sp-step em { font-style: normal; color: var(--color-text-light); font-size: .78rem; }

.sp-panel { background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: var(--radius-xl); padding: 20px; }
.sp-panel-head { @apply flex flex-wrap items-center justify-between gap-3; margin-bottom: 14px; }
.sp-all { @apply flex items-center gap-2; font-size: .85rem; cursor: pointer; }
.sp-search { max-width: 20rem; }

.sp-h3 { @apply font-semibold; margin: 0; color: var(--color-text); }
.sp-sub { font-size: .85rem; color: var(--color-text-light); margin: 4px 0 18px; }

.sp-table { @apply w-full; border-collapse: collapse; font-size: .86rem; }
.sp-table th, .sp-table td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--color-border); }
.sp-table th { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em;
  color: var(--color-text-muted); background: var(--color-surface-sunken); }
.sp-table td.num, .sp-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
.sp-name { display: block; color: var(--color-text); }
.sp-why { display: block; font-size: .74rem; color: var(--color-warning); }
.sp-off { opacity: .6; }

.sp-blocked { font-size: .84rem; padding: 10px 12px; border-radius: 8px;
  background: var(--color-warning-light); color: #7C2D12; margin-bottom: 12px; }

.sp-note { font-size: .78rem; color: var(--color-text-light); margin: 4px 0 0 26px; }
.sp-fixed { margin-top: 18px; padding: 14px; border-radius: 8px;
  background: var(--color-surface-sunken); font-size: .85rem; }
.sp-fixed p { margin: 0 0 6px; }

.sp-review { display: grid; grid-template-columns: 160px 1fr; gap: 10px 16px; font-size: .88rem; }
.sp-review dt { color: var(--color-text-light); }
.sp-review dd { margin: 0; color: var(--color-text); }

.sp-outcome { list-style: none; margin: 12px 0 0; padding: 0; font-size: .82rem;
  display: flex; flex-direction: column; gap: 4px; max-height: 12rem; overflow-y: auto; }

.field { @apply flex flex-col; gap: 6px; margin-bottom: 14px; }
.field > span { @apply font-semibold; font-size: .78rem; color: var(--color-text); }
.control { @apply w-full; font-family: inherit; font-size: .88rem; padding: 8px 10px;
  border: 1px solid var(--color-border-strong); border-radius: 8px;
  background: var(--color-surface); color: var(--color-text); }
.check { @apply flex items-center gap-2; font-size: .88rem; cursor: pointer; }

.overlay { @apply fixed inset-0 z-50 flex items-center justify-center; background: rgb(0 0 0 / 45%); padding: 20px; }
.dialog { background: var(--color-surface); border-radius: var(--radius-xl); padding: 24px; width: min(30rem, 100%); }
.dialog h2 { @apply font-semibold; margin: 0 0 6px; color: var(--color-text); }
.dialog-actions { @apply flex justify-end gap-2; margin-top: 18px; }
.done-mark { width: 40px; height: 40px; border-radius: 999px; display: grid; place-items: center;
  background: var(--color-success-light); color: var(--color-success); font-size: 1.2rem; margin-bottom: 12px; }
.muted { font-size: .88rem; color: var(--color-text-light); margin: 0; }
.quiet { font-size: .85rem; color: var(--color-text-light); }
.error { font-size: .86rem; color: var(--color-error); background: var(--color-error-light);
  border-radius: 8px; padding: 10px 12px; }
.mono { font-family: ui-monospace, monospace; font-size: .78rem; }
</style>
