<script setup lang="ts">
/**
 * One piece of research.
 *
 * The plan and the findings are kept apart on screen as they are in the record.
 * Collapsing them into one box loses what we set out to test the moment there
 * is an answer, and "we asked the wrong question" is a finding worth keeping.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import EmployeePicker from "../../components/EmployeePicker.vue";
import { safeHtml, hasText } from "../../lib/richText";

const props = defineProps<{ id: string | number }>();

interface Person { id: number | null; name: string | null }
interface TeamMember { id: number; employee: Person; role: string | null }
interface Research {
  id: number; reference: string | null; name: string;
  description: string | null; script: string | null; findings: string | null;
  status: string; priority: string; deadline: string | null;
  scope: { concept: boolean | null; architecture: boolean | null; integration: boolean | null };
  account?: { id: number; name: string; initials: string } | null;
  lead?: { id: number; reference: string | null; name: string } | null;
  opportunity?: { id: number; reference: string | null; name: string } | null;
  research_lead?: Person | null;
  team?: TeamMember[];
  created_at: string | null;
}

const router   = useRouter();
const research = ref<Research | null>(null);
const loading  = ref(true);
const saving   = ref(false);
const editing  = ref(false);

const org  = () => currentOrgSlug.value;
const base = computed(() => `/api/v1/research/${props.id}`);

function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

const form = ref({
  name: "", description: "", script: "", findings: "",
  status: "new", priority: "medium", deadline: "",
  research_lead_id: null as number | null,
  covers_concept: false, covers_architecture: false, covers_integration: false,
});

function fill(r: Research) {
  form.value = {
    name: r.name, description: r.description ?? "", script: r.script ?? "",
    findings: r.findings ?? "", status: r.status, priority: r.priority,
    deadline: r.deadline ?? "", research_lead_id: r.research_lead?.id ?? null,
    covers_concept: r.scope.concept ?? false,
    covers_architecture: r.scope.architecture ?? false,
    covers_integration: r.scope.integration ?? false,
  };
}

async function load() {
  loading.value = true;
  try {
    research.value = (await api<{ data: Research }>("GET", base.value, { org: org() })).data;
    fill(research.value);
  } catch (e) { fail(e, "Could not load this research."); }
  finally { loading.value = false; }
}

async function save() {
  saving.value = true;
  try {
    research.value = (await api<{ data: Research }>("PUT", base.value, {
      org: org(),
      body: {
        ...form.value,
        description: form.value.description || null,
        script: form.value.script || null,
        findings: form.value.findings || null,
        deadline: form.value.deadline || null,
        account_id: research.value?.account?.id ?? null,
        opportunity_id: research.value?.opportunity?.id ?? null,
        lead_id: research.value?.lead?.id ?? null,
      },
    })).data;
    editing.value = false;
    notifySaved("Saved.");
  } catch (e) { fail(e, "Could not save."); }
  finally { saving.value = false; }
}

// ── Team ────────────────────────────────────────────────────────────────────

const newMember = ref({ employee_id: null as number | null, role: "" });

async function addMember() {
  if (!newMember.value.employee_id) return;
  saving.value = true;
  try {
    research.value = (await api<{ data: Research }>("POST", `${base.value}/team`, {
      org: org(), body: { ...newMember.value, role: newMember.value.role || null },
    })).data;
    newMember.value = { employee_id: null, role: "" };
  } catch (e) { fail(e, "Could not add them."); }
  finally { saving.value = false; }
}

async function removeMember(m: TeamMember) {
  try {
    research.value = (await api<{ data: Research }>("DELETE", `${base.value}/team/${m.id}`, { org: org() })).data;
  } catch (e) { fail(e, "Could not remove them."); }
}

// ── Presentation ────────────────────────────────────────────────────────────

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

const titleCase = (v: string) => v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());

const initials = (name: string | null | undefined) =>
  (name ?? "?").split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

const SCOPE_HELP: Record<string, string> = {
  concept: "Can this be done at all?",
  architecture: "How would it be built?",
  integration: "Will it talk to what they already run?",
};

/** Only what was actually asked — an unanswered flag is not a "no". */
const scopes = computed(() => {
  const s = research.value?.scope;
  if (!s) return [];
  return [
    { key: "concept", label: "Concept", on: s.concept },
    { key: "architecture", label: "Architecture", on: s.architecture },
    { key: "integration", label: "Integration", on: s.integration },
  ].filter((x) => x.on);
});

onMounted(load);
</script>

<template>
  <div class="rd-wrap">
    <nav class="rd-crumbs">
      <button class="rd-crumb" @click="router.push({ name: 'research' })">Research</button>
      <span class="rd-sep">›</span>
      <span class="rd-current">{{ research?.reference ?? "…" }}</span>
    </nav>

    <div v-if="loading" class="rd-loading">Loading…</div>

    <template v-else-if="research">
      <header class="rd-head">
        <div>
          <h1 class="rd-title">{{ research.name }}</h1>
          <p class="rd-byline">
            Raised {{ date(research.created_at) }}
            <template v-if="research.research_lead?.name"> · led by {{ research.research_lead.name }}</template>
          </p>
        </div>
        <button class="btn-ghost" @click="editing = !editing">{{ editing ? "Cancel" : "Edit" }}</button>
      </header>

      <div class="rd-facts-row">
        <dl class="rd-facts">
          <div><dt>Deadline</dt><dd>{{ date(research.deadline) }}</dd></div>
          <div><dt>Status</dt><dd><span class="pill pill-neutral">{{ titleCase(research.status) }}</span></dd></div>
          <div><dt>Priority</dt>
            <dd :class="research.priority === 'high' ? 'rd-high' : ''">{{ titleCase(research.priority) }} priority</dd></div>
          <div><dt>Research Team</dt>
            <dd>
              <span v-if="!research.team?.length" class="rd-muted">Nobody yet</span>
              <span v-else class="rd-stack">
                <span v-for="m in research.team.slice(0, 5)" :key="m.id" class="rd-avatar"
                      :title="m.employee.name ?? ''">{{ initials(m.employee.name) }}</span>
              </span>
            </dd></div>
        </dl>

        <dl class="rd-facts">
          <div><dt>Company</dt>
            <dd>
              <button v-if="research.account" class="link-btn"
                      @click="router.push({ name: 'account', params: { id: research.account.id } })">
                {{ research.account.name }}
              </button>
              <span v-else class="rd-muted">Not attached</span>
            </dd></div>
          <div><dt>Linked Opportunity</dt>
            <dd>
              <button v-if="research.opportunity" class="link-btn"
                      @click="router.push({ name: 'opportunity', params: { id: research.opportunity.id } })">
                {{ research.opportunity.reference }}
              </button>
              <span v-else class="rd-muted">—</span>
            </dd></div>
          <div><dt>Linked Lead</dt>
            <dd>
              <button v-if="research.lead" class="link-btn"
                      @click="router.push({ name: 'lead', params: { id: research.lead.id } })">
                {{ research.lead.reference }}
              </button>
              <span v-else class="rd-muted">—</span>
            </dd></div>
          <div><dt>Research Lead</dt><dd>{{ research.research_lead?.name ?? "—" }}</dd></div>
        </dl>
      </div>

      <template v-if="!editing">
        <div class="rd-card">
          <h3 class="rd-h3">What is being asked</h3>
          <p v-if="!scopes.length" class="rd-muted">Scope not stated.</p>
          <ul v-else class="rd-scopes">
            <li v-for="s in scopes" :key="s.key">
              <strong>{{ s.label }}</strong><span class="rd-muted"> — {{ SCOPE_HELP[s.key] }}</span>
            </li>
          </ul>
          <div v-if="hasText(research.description)" class="rd-prose rich"
               v-html="safeHtml(research.description)"></div>
        </div>

        <div class="rd-two">
          <div class="rd-card">
            <h3 class="rd-h3">The plan</h3>
            <p v-if="research.script" class="rd-prose">{{ research.script }}</p>
            <p v-else class="rd-muted">No method written down.</p>
          </div>

          <div class="rd-card">
            <h3 class="rd-h3">Findings</h3>
            <p v-if="research.findings" class="rd-prose">{{ research.findings }}</p>
            <p v-else class="rd-muted">Nothing back yet.</p>
          </div>
        </div>

        <div class="rd-card">
          <h3 class="rd-h3">Research team</h3>
          <div class="rd-add-row">
            <div class="rd-add-picker">
              <EmployeePicker v-model="newMember.employee_id" placeholder="Add somebody…" />
            </div>
            <input v-model="newMember.role" class="control rd-add-role" placeholder="What they are looking at" />
            <button class="btn-primary" :disabled="saving || !newMember.employee_id" @click="addMember">Add</button>
          </div>

          <ul v-if="research.team?.length" class="rd-people">
            <li v-for="m in research.team" :key="m.id">
              <span class="rd-person">
                <span class="rd-avatar">{{ initials(m.employee.name) }}</span>{{ m.employee.name }}
                <span v-if="m.role" class="rd-muted">— {{ m.role }}</span>
              </span>
              <button class="link-btn link-danger" @click="removeMember(m)">Remove</button>
            </li>
          </ul>
        </div>
      </template>

      <div v-else class="rd-card">
        <div class="rd-grid">
          <label class="rd-field rd-span"><span>Research name</span>
            <input v-model="form.name" class="control" /></label>

          <label class="rd-field"><span>Status</span>
            <select v-model="form.status" class="control">
              <option value="new">New</option><option value="in_progress">In progress</option>
              <option value="complete">Complete</option><option value="abandoned">Abandoned</option>
            </select></label>

          <label class="rd-field"><span>Priority</span>
            <select v-model="form.priority" class="control">
              <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
            </select></label>

          <label class="rd-field"><span>Deadline</span>
            <input v-model="form.deadline" class="control" type="date" /></label>

          <div class="rd-field"><span>Research lead</span>
            <EmployeePicker v-model="form.research_lead_id" placeholder="Who is running it?" /></div>

          <fieldset class="rd-field rd-span rd-scope-set">
            <legend>What is being asked</legend>
            <label class="rd-check"><input v-model="form.covers_concept" type="checkbox" />
              <span><strong>Concept</strong> — can this be done at all?</span></label>
            <label class="rd-check"><input v-model="form.covers_architecture" type="checkbox" />
              <span><strong>Architecture</strong> — how would it be built?</span></label>
            <label class="rd-check"><input v-model="form.covers_integration" type="checkbox" />
              <span><strong>Integration</strong> — will it talk to what they run?</span></label>
          </fieldset>

          <label class="rd-field rd-span"><span>Background</span>
            <textarea v-model="form.description" class="control" rows="4"></textarea></label>

          <label class="rd-field rd-span"><span>The plan</span>
            <textarea v-model="form.script" class="control" rows="5"
                      placeholder="What we intend to do to answer it"></textarea></label>

          <label class="rd-field rd-span"><span>Findings</span>
            <textarea v-model="form.findings" class="control" rows="5"
                      placeholder="What came back"></textarea>
            <span class="rd-hint">
              Kept apart from the plan on purpose — "we asked the wrong question" is
              itself a finding, and merging the two loses it.
            </span></label>
        </div>

        <div class="rd-actions">
          <button class="btn-primary" :disabled="saving || !form.name.trim()" @click="save">
            {{ saving ? "Saving…" : "Save changes" }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.rd-wrap { @apply flex flex-col; padding: var(--space-6); max-width: 72rem; gap: var(--space-4); }

.rd-crumbs { @apply flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); }
.rd-crumb { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: inherit; color: var(--color-text-muted); }
.rd-crumb:hover { color: var(--color-text); }
.rd-sep { color: var(--color-text-light); }
.rd-current { color: var(--color-text); }

.rd-loading { padding: var(--space-6); color: var(--color-text-muted); }

.rd-head { @apply flex items-start justify-between; gap: var(--space-4); }
.rd-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.rd-byline { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

.rd-facts-row { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3) var(--space-8); }
@media (max-width: 48rem) { .rd-facts-row { grid-template-columns: 1fr; } }
.rd-facts { margin: 0; }
.rd-facts > div { @apply flex items-center; gap: var(--space-3); padding: var(--space-1) 0; min-height: 2rem; }
.rd-facts dt { font-size: var(--text-sm-size); color: var(--color-text-muted); min-width: 9rem; }
.rd-facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }
.rd-high { color: var(--color-danger-text); font-weight: 500; }

.rd-two { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4); }
@media (max-width: 48rem) { .rd-two { grid-template-columns: 1fr; } }

.rd-card {
  @apply flex flex-col; gap: var(--space-3);
  padding: var(--space-5);
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
.rd-h3 { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.rd-prose { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; white-space: pre-wrap; line-height: 1.65; }
.rd-muted { font-size: var(--text-sm-size); color: var(--color-text-light); margin: 0; }
.rd-hint { font-size: var(--text-xs-size); color: var(--color-text-light); }

.rd-scopes { @apply list-none; margin: 0; padding: 0; }
.rd-scopes li { font-size: var(--text-sm-size); color: var(--color-text); padding: 2px 0; }

.rd-grid { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4); }
.rd-span { grid-column: 1 / -1; }
@media (max-width: 40rem) { .rd-grid { grid-template-columns: 1fr; } }

.rd-field { @apply flex flex-col; gap: var(--space-1); }
.rd-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.rd-scope-set { border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-3); gap: var(--space-2); }
.rd-scope-set legend { font-size: var(--text-xs-size); color: var(--color-text-muted); padding: 0 var(--space-1); }
.rd-check { @apply flex cursor-pointer items-start; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.rd-actions { @apply flex justify-end; }
.rd-add-row { @apply flex flex-wrap items-start; gap: var(--space-3); }
.rd-add-picker { flex: 1; min-width: 15rem; }
.rd-add-role { max-width: 18rem; }

.rd-people { @apply list-none; margin: 0; padding: 0; }
.rd-people li { @apply flex items-center justify-between; gap: var(--space-3); padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border); }
.rd-people li:last-child { border-bottom: none; }
.rd-person { @apply inline-flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }

.rd-stack { @apply inline-flex items-center; }
.rd-stack .rd-avatar { margin-right: -0.35rem; border: 2px solid var(--color-surface); }
.rd-avatar {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 1.5rem; height: 1.5rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: 0.6rem; font-weight: 600;
}

.pill { display: inline-block; padding: 2px var(--space-3); border-radius: 999px; font-size: var(--text-xs-size); border: 1px solid var(--color-border); background: var(--color-surface-sunken); color: var(--color-text-muted); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); text-align: left; }
.link-btn:hover { @apply underline; }
.link-danger { color: var(--color-danger-text); }

.btn-primary, .btn-ghost {
  @apply inline-flex cursor-pointer items-center justify-center;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-ghost   { background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }
</style>
