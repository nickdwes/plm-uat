<script setup lang="ts">
/**
 * One requisition — what was asked, who has to agree, and where it has got to.
 *
 * The two columns answer different questions. The left is the request as the
 * approvers will read it. The right is the decision: how far it has come, and
 * who it is sitting with.
 *
 * The Actions menu only appears for the person the chain is actually waiting
 * on. Showing Approve to somebody the server will refuse is worse than showing
 * nothing — it teaches people the buttons are decorative.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";

const props = defineProps<{ id: string }>();

interface Person { id: number; name: string; email: string | null }
interface Approval {
  id: number; position: number; label: string; status: string;
  reason: string | null; actioned_at: string | null;
  approver: Person | null; unassigned: boolean;
}
interface Event { status: string; note: string | null; at: string | null; actor: string | null }
interface Requisition {
  id: number; reference: string; job_title: string;
  department: { id: number; name: string } | null;
  positions: number; priority: string; status: string;
  target_hire_date: string | null; created_at: string | null;
  employment_type: string | null; period: string | null;
  job_summary: string | null;
  key_responsibilities: string | null;
  required_qualifications: string | null;
  skills: string[];
  decision_reason: string | null;
  is_approved: boolean;
  requester: Person | null;
  vacancy: { id: number; name: string; status: string } | null;
  template: { id: number; name: string; form_fields: number; has_screening_rules: boolean } | null;
  pipeline: { id: number; name: string; stages: { name: string; type: string }[] } | null;
  approvals: Approval[];
  timeline: Event[];
  can_decide: boolean;
  awaiting: { label: string; position: number } | null;
}

const router = useRouter();
const req = ref<Requisition | null>(null);
const loading = ref(true);
const saving = ref(false);

const org = () => currentOrgSlug.value;

function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

async function load() {
  loading.value = true;
  try {
    req.value = (await api<{ data: Requisition }>("GET", `/api/v1/requisitions/${props.id}`, {
      org: org(),
    })).data;
  } catch (e) { fail(e, "Could not load the requisition."); }
  finally { loading.value = false; }
}

// ── Deciding ────────────────────────────────────────────────────────────────

const showActions = ref(false);
/** Hold and decline both owe a reason, so both go through the same prompt. */
const deciding = ref<"held" | "declined" | null>(null);
const reason = ref("");

async function decide(decision: string, why?: string) {
  saving.value = true;
  try {
    req.value = (await api<{ data: Requisition }>("POST", `/api/v1/requisitions/${props.id}/decide`, {
      org: org(), body: { decision, reason: why ?? null },
    })).data;
    deciding.value = null;
    reason.value = "";
    showActions.value = false;
    notifySaved(
      decision === "approved" ? "Approved." : decision === "held" ? "Put on hold." : "Declined.",
    );
  } catch (e) { fail(e, "Could not record that decision."); }
  finally { saving.value = false; }
}

async function act(path: string, done: string) {
  saving.value = true;
  try {
    req.value = (await api<{ data: Requisition }>("POST", `/api/v1/requisitions/${props.id}/${path}`, {
      org: org(), body: {},
    })).data;
    showActions.value = false;
    notifySaved(done);
  } catch (e) { fail(e, "Could not do that."); }
  finally { saving.value = false; }
}

// ── Presentation ────────────────────────────────────────────────────────────

const STATUS_LABEL: Record<string, string> = {
  draft: "Draft", in_approval: "In Approval", approved: "Approved",
  open: "Open", on_hold: "On Hold", declined: "Declined", closed: "Closed",
};

const statusTone = (s: string) =>
  s === "open" || s === "approved" ? "ok"
    : s === "in_approval" ? "info"
      : s === "on_hold" ? "warn"
        : s === "declined" ? "bad" : "neutral";

const date = (v: string | null) =>
  v ? new Date(v + (v.length === 10 ? "T00:00:00" : "")).toLocaleDateString(undefined, {
    day: "2-digit", month: "short", year: "numeric",
  }) : "—";

const initials = (n: string | null | undefined) =>
  (n ?? "?").split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

/** Free text kept as text, but rendered as the list people typed. */
const lines = (v: string | null) =>
  (v ?? "").split("\n").map((l) => l.replace(/^[-•]\s*/, "").trim()).filter(Boolean);

/**
 * The five stages across the top of the timeline.
 *
 * Fixed, so the shape of the journey is the same on every requisition and two
 * can be compared at a glance. A hold is shown against the stage it interrupted
 * rather than as a sixth step, which is how the designs draw it.
 */
const STAGES = ["requested", "in_approval", "approved", "open", "closed"] as const;

const reachedAt = (stage: string): string | null => {
  const hit = (req.value?.timeline ?? []).filter((e) => e.status === stage);
  return hit.length ? hit[hit.length - 1].at : null;
};

const stageState = (stage: string, i: number) => {
  const r = req.value;
  if (!r) return "todo";
  if (r.status === "declined" && stage !== "requested") return "todo";
  const current = STAGES.indexOf(r.status as typeof STAGES[number]);
  if (reachedAt(stage) && (current === -1 || i < current)) return "done";
  if (r.status === stage) return "current";
  return reachedAt(stage) ? "done" : "todo";
};

/** Holds, shown beneath the track because they interrupt rather than advance. */
const holds = computed(() =>
  (req.value?.timeline ?? []).filter((e) => e.status === "on_hold"));

const approvalTone = (s: string) =>
  s === "approved" ? "ok" : s === "declined" ? "bad" : s === "held" ? "warn" : "muted";

const approvalLabel = (s: string) =>
  ({ approved: "Approved", declined: "Declined", held: "On hold", pending: "Pending Approval" }[s] ?? s);

onMounted(load);
</script>

<template>
  <div class="rq-wrap">
    <p v-if="loading" class="rq-quiet">Loading…</p>

    <template v-else-if="req">
      <nav class="rq-crumb">
        <button class="link-btn" @click="router.push({ name: 'requisitions' })">Talent Requisition</button>
        <span>›</span><span class="rq-crumb-current">#{{ req.reference }}</span>
      </nav>

      <header class="rq-header">
        <div>
          <h1 class="rq-title">
            {{ req.job_title }}
            <span :class="['pill', `pill-${statusTone(req.status)}`]">{{ STATUS_LABEL[req.status] }}</span>
          </h1>
          <p class="rq-sub">{{ req.reference }} • Created on {{ date(req.created_at) }}</p>
        </div>

        <div class="rq-actions">
          <!-- Only for the person the chain is waiting on. An Approve button
               the server will refuse teaches people the buttons are for show. -->
          <template v-if="req.can_decide">
            <button class="btn-ghost" :disabled="saving" @click="deciding = 'held'">Hold</button>
            <button class="btn-ghost danger" :disabled="saving" @click="deciding = 'declined'">Reject</button>
            <button class="btn-primary" :disabled="saving" @click="decide('approved')">Approve</button>
          </template>

          <button v-if="req.status === 'on_hold'" class="btn-ghost" :disabled="saving"
                  @click="act('resume', 'Back with the approver.')">Resume</button>

          <!-- Approved is the gate: a job opening cannot exist before the
               headcount was agreed. -->
          <button v-if="req.is_approved && !req.vacancy" class="btn-primary" disabled
                  title="Job openings arrive in the next increment">
            Create Job Opening
          </button>
          <button v-if="req.vacancy" class="btn-ghost"
                  @click="router.push({ name: 'vacancy-detail', params: { id: req.vacancy.id } })">
            View job opening
          </button>
        </div>
      </header>

      <!-- Why it was held or declined, said where the decision is read. -->
      <p v-if="req.decision_reason && ['on_hold', 'declined'].includes(req.status)"
         :class="['rq-reason', req.status === 'declined' ? 'bad' : '']">
        {{ req.status === "declined" ? "Declined" : "On hold" }}: “{{ req.decision_reason }}”
      </p>

      <dl class="rq-facts">
        <div><dt>Department</dt><dd>{{ req.department?.name ?? "—" }}</dd></div>
        <div><dt>Requested by</dt>
          <dd class="rq-person">
            <span class="rq-avatar">{{ initials(req.requester?.name) }}</span>{{ req.requester?.name ?? "—" }}
          </dd></div>
        <div><dt>No. of Positions</dt><dd>{{ req.positions }}</dd></div>
        <div><dt>Priority</dt>
          <dd :class="['rq-flag', req.priority]">⚑ {{ req.priority }} priority</dd></div>
        <div><dt>Target Hire Date</dt><dd>{{ date(req.target_hire_date) }}</dd></div>
        <div><dt>Employment</dt>
          <dd>{{ req.employment_type ?? "—" }}<template v-if="req.period"> · {{ req.period }}</template></dd></div>
      </dl>

      <div class="rq-columns">
        <!-- ── The request ────────────────────────────────────────────── -->
        <section class="rq-card">
          <h2 class="rq-card-title">Job Summary</h2>
          <p class="rq-text">{{ req.job_summary || "—" }}</p>

          <h2 class="rq-card-title">Key Responsibilities</h2>
          <ul v-if="lines(req.key_responsibilities).length" class="rq-list">
            <li v-for="(l, i) in lines(req.key_responsibilities)" :key="i">{{ l }}</li>
          </ul>
          <p v-else class="rq-muted">—</p>

          <h2 class="rq-card-title">Required Qualifications</h2>
          <ul v-if="lines(req.required_qualifications).length" class="rq-list">
            <li v-for="(l, i) in lines(req.required_qualifications)" :key="i">{{ l }}</li>
          </ul>
          <p v-else class="rq-muted">—</p>

          <template v-if="req.skills.length">
            <h2 class="rq-card-title">Skills</h2>
            <div class="rq-skills">
              <span v-for="s in req.skills" :key="s" class="rq-skill">{{ s }}</span>
            </div>
          </template>
        </section>

        <!-- ── The decision ───────────────────────────────────────────── -->
        <div class="rq-side">
          <section class="rq-card">
            <h2 class="rq-card-title">Requisition Timeline</h2>
            <ol class="rq-track">
              <li v-for="(s, i) in STAGES" :key="s" :class="['rq-stage', stageState(s, i)]">
                <span class="rq-dot">{{ stageState(s, i) === "done" ? "✓" : "" }}</span>
                <span class="rq-stage-name">{{ STATUS_LABEL[s] }}</span>
                <span class="rq-stage-date">{{ reachedAt(s) ? date(reachedAt(s)) : "-" }}</span>
              </li>
            </ol>

            <!-- Holds interrupt rather than advance, so they sit under the
                 track rather than becoming a sixth stage. -->
            <p v-for="(h, i) in holds" :key="i" class="rq-hold">
              Held {{ date(h.at) }}<template v-if="h.note"> — “{{ h.note }}”</template>
            </p>
          </section>

          <!--
            What this requisition inherited, shown so the requester can see the
            whole journey before agreeing to it — which form candidates fill in
            and which board they move down. All of it was configured before
            requisitions existed; none of it is worth defining twice.
          -->
          <section v-if="req.pipeline || req.template" class="rq-card">
            <h2 class="rq-card-title">Hiring process</h2>

            <p v-if="req.template" class="rq-config">
              From template <strong>{{ req.template.name }}</strong>
              <template v-if="req.template.form_fields">
                · {{ req.template.form_fields }}-field application form
              </template>
              <template v-if="req.template.has_screening_rules"> · screening rules</template>
            </p>

            <template v-if="req.pipeline">
              <p class="rq-config">Pipeline <strong>{{ req.pipeline.name }}</strong></p>
              <div class="rq-stages">
                <span v-for="s in req.pipeline.stages" :key="s.name" class="rq-stage-chip">{{ s.name }}</span>
              </div>
            </template>
          </section>

          <section class="rq-card">
            <h2 class="rq-card-title">Approvals</h2>

            <!-- The requester leads, as the designs show. It is not a decision;
                 reading it as one would suggest people approve their own. -->
            <div class="rq-approval">
              <span class="rq-avatar">{{ initials(req.requester?.name) }}</span>
              <span class="rq-approval-who">
                <span>{{ req.requester?.name ?? "—" }}</span>
                <span class="rq-approval-mail">{{ req.requester?.email }}</span>
              </span>
              <span class="rq-approval-state muted">Requested</span>
            </div>

            <div v-for="a in req.approvals" :key="a.id" class="rq-approval">
              <span class="rq-avatar">{{ initials(a.approver?.name) }}</span>
              <span class="rq-approval-who">
                <span>{{ a.approver?.name ?? "Nobody assigned" }} <span class="rq-approval-role">· {{ a.label }}</span></span>
                <span class="rq-approval-mail">{{ a.approver?.email }}</span>
                <span v-if="a.reason" class="rq-approval-reason">“{{ a.reason }}”</span>
              </span>
              <span :class="['rq-approval-state', approvalTone(a.status)]">
                {{ approvalLabel(a.status) }}
                <span class="rq-approval-date">{{ a.actioned_at ? date(a.actioned_at) : "" }}</span>
              </span>
            </div>

            <!-- A rung nobody fills. Shown, or the chain looks followed when
                 it cannot be. -->
            <p v-if="req.approvals.some((a) => a.unassigned)" class="rq-warn">
              A step has nobody to fill it — an administrator needs to fix the approval chain
              for this department before it can move.
            </p>
          </section>
        </div>
      </div>
    </template>

    <!-- ── Hold / decline ───────────────────────────────────────────────── -->
    <div v-if="deciding" class="rq-modal-backdrop" @click.self="deciding = null">
      <div class="rq-modal" role="dialog" aria-modal="true" aria-label="Reason">
        <div class="rq-modal-head">
          <h3 class="rq-modal-title">
            {{ deciding === "held" ? "Hold Requisition Request" : "Decline Requisition Request" }}
          </h3>
          <button class="rq-close" aria-label="Close" @click="deciding = null">×</button>
        </div>

        <p class="rq-hint">
          {{ deciding === "held"
            ? "A hold pauses this with you — it comes back to you when the requester resumes it."
            : "Declining ends the chain. The requester can revise it and start again." }}
        </p>

        <label class="rq-field"><span>Reason</span>
          <textarea v-model="reason" class="control" rows="4" placeholder="Type here…"></textarea></label>

        <button :class="['btn-block', deciding === 'held' ? 'btn-primary' : 'btn-danger']"
                :disabled="saving || !reason.trim()" @click="decide(deciding!, reason.trim())">
          {{ deciding === "held" ? "Submit" : "Decline Request" }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.rq-wrap { padding: var(--space-6); max-width: 90rem; }
.rq-quiet { padding: var(--space-6); color: var(--color-text-muted); }
.rq-muted { color: var(--color-text-light); }

.rq-crumb { @apply flex flex-wrap items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text-muted); margin-bottom: var(--space-3); }
.rq-crumb-current { color: var(--color-text); }

.rq-header { @apply flex flex-wrap items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-4); }
.rq-title { @apply flex flex-wrap items-center; gap: var(--space-3); font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.rq-sub { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }
.rq-actions { @apply flex flex-wrap items-center; gap: var(--space-2); }

.rq-reason { font-size: var(--text-sm-size); color: var(--color-warning-text); background: var(--color-warning-subtle); padding: var(--space-3) var(--space-4); border-radius: var(--radius-md); margin: 0 0 var(--space-4); }
.rq-reason.bad { color: var(--color-danger-text); background: var(--color-danger-subtle); }

.rq-facts { @apply grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: var(--space-4); margin: 0 0 var(--space-5); }
.rq-facts dt { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.rq-facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 2px 0 0; }
.rq-flag.high { color: var(--color-danger-text); }
.rq-flag.medium { color: var(--color-warning-text); }
.rq-flag.low { color: var(--color-text-muted); }
.rq-flag { text-transform: capitalize; }

.rq-columns { @apply grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: var(--space-4); align-items: start; }
@media (max-width: 60rem) { .rq-columns { grid-template-columns: 1fr; } }
.rq-side { @apply flex flex-col; gap: var(--space-4); }

.rq-card { padding: var(--space-5); border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface-raised); }
.rq-card-title { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-2); }
.rq-card-title:not(:first-child) { margin-top: var(--space-5); }
.rq-text { font-size: var(--text-sm-size); color: var(--color-text); white-space: pre-wrap; margin: 0; }
.rq-list { @apply flex flex-col; gap: var(--space-1); margin: 0; padding-left: var(--space-5); font-size: var(--text-sm-size); color: var(--color-text); }

.rq-skills { @apply flex flex-wrap; gap: var(--space-2); }
.rq-skill { padding: 2px var(--space-4); border-radius: 999px; border: 1px solid var(--color-border); font-size: var(--text-xs-size); color: var(--color-text); }

.rq-track { @apply flex; list-style: none; margin: 0; padding: 0; }
.rq-stage { @apply relative flex flex-1 flex-col items-center; gap: 4px; text-align: center; }
.rq-stage::before {
  content: ""; position: absolute; top: 0.7rem; left: -50%; width: 100%; height: 2px;
  background: var(--color-border);
}
.rq-stage:first-child::before { display: none; }
.rq-stage.done::before, .rq-stage.current::before { background: var(--color-success-text); }
.rq-dot {
  @apply relative inline-flex items-center justify-center;
  width: 1.4rem; height: 1.4rem; border-radius: 999px;
  border: 2px solid var(--color-border); background: var(--color-surface);
  font-size: 0.7rem; color: var(--color-on-brand);
}
.rq-stage.done .rq-dot { background: var(--color-success-text); border-color: var(--color-success-text); }
.rq-stage.current .rq-dot { border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }
.rq-stage-name { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.rq-stage.current .rq-stage-name { color: var(--color-brand-on-surface); font-weight: 500; }
.rq-stage-date { font-size: 0.6rem; color: var(--color-text-light); }
.rq-hold { font-size: var(--text-xs-size); color: var(--color-warning-text); margin: var(--space-3) 0 0; }

.rq-config { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0 0 var(--space-2); }
.rq-stages { @apply flex flex-wrap; gap: var(--space-1); }
.rq-stage-chip { padding: 1px var(--space-3); border-radius: 999px; background: var(--color-surface-sunken); color: var(--color-text-muted); font-size: var(--text-xs-size); }

.rq-approval { @apply flex items-start; gap: var(--space-3); padding: var(--space-3) 0; border-bottom: 1px solid var(--color-border); }
.rq-approval:last-of-type { border-bottom: none; }
.rq-approval-who { @apply flex flex-1 flex-col; font-size: var(--text-sm-size); color: var(--color-text); min-width: 0; }
.rq-approval-role { color: var(--color-text-muted); font-size: var(--text-xs-size); }
.rq-approval-mail { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.rq-approval-reason { font-size: var(--text-xs-size); color: var(--color-text-muted); font-style: italic; }
.rq-approval-state { @apply flex flex-col items-end; font-size: var(--text-xs-size); white-space: nowrap; }
.rq-approval-state.ok    { color: var(--color-success-text); }
.rq-approval-state.warn  { color: var(--color-warning-text); }
.rq-approval-state.bad   { color: var(--color-danger-text); }
.rq-approval-state.muted { color: var(--color-text-muted); }
.rq-approval-date { color: var(--color-text-light); }
.rq-warn { font-size: var(--text-xs-size); color: var(--color-danger-text); margin: var(--space-3) 0 0; }

.rq-person { @apply flex items-center; gap: var(--space-2); }
.rq-avatar {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 1.75rem; height: 1.75rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: 0.6rem; font-weight: 600;
}

.rq-modal-backdrop { @apply fixed inset-0 flex items-center justify-center z-50; background: rgb(0 0 0 / 0.32); padding: var(--space-4); }
.rq-modal {
  @apply flex flex-col; width: min(24rem, 100%); gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised);
  border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
}
.rq-modal-head { @apply flex items-center justify-between; }
.rq-modal-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.rq-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }
.rq-field { @apply flex flex-col; gap: var(--space-1); }
.rq-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.rq-hint { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0; }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.pill { display: inline-block; padding: 2px var(--space-3); border-radius: 999px; font-size: var(--text-xs-size); border: 1px solid transparent; white-space: nowrap; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 30%, transparent); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); border-color: color-mix(in oklab, var(--color-warning-text) 30%, transparent); }
.pill-bad     { background: var(--color-danger-subtle);  color: var(--color-danger-text);  border-color: color-mix(in oklab, var(--color-danger-text) 30%, transparent); }
.pill-info    { background: var(--color-brand-subtle);   color: var(--color-brand-on-surface); border-color: color-mix(in oklab, var(--color-brand-base) 30%, transparent); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); border-color: var(--color-border); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }

.btn-primary {
  @apply inline-flex cursor-pointer items-center justify-center border-none;
  padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
  background: var(--color-brand-base); color: var(--color-on-brand);
}
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }
.btn-danger {
  @apply inline-flex cursor-pointer items-center justify-center border-none;
  padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500;
  background: var(--color-danger-text); color: #fff;
}
.btn-danger:disabled { @apply cursor-not-allowed opacity-50; }
.btn-block { width: 100%; }

.btn-ghost {
  @apply inline-flex cursor-pointer items-center justify-center;
  padding: 8px var(--space-4); border-radius: var(--radius-md);
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text);
}
.btn-ghost.danger { color: var(--color-danger-text); border-color: color-mix(in oklab, var(--color-danger-text) 40%, transparent); }
.btn-ghost:disabled { @apply cursor-not-allowed opacity-50; }
</style>
