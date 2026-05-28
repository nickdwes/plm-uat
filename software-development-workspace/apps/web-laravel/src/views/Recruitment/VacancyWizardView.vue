<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { VacancyTemplate, RecruitmentWorkflow, FormFieldSchema } from "../../lib/types";
import FormFieldEditor from "../../components/Recruitment/FormFieldEditor.vue";

const router = useRouter();

const step      = ref(1); // 1=Details, 2=Form, 3=Review
const saving    = ref(false);
const error     = ref("");
const templates = ref<VacancyTemplate[]>([]);
const workflows = ref<RecruitmentWorkflow[]>([]);

const form = ref({
  name: "",
  job_title: "",
  location: "",
  open_positions: 1,
  closing_date: "",
  description: "",
  template_id: null as number | null,
  workflow_id: null as number | null,
  application_form_schema: [] as FormFieldSchema[],
});

function org() { return currentOrgSlug.value; }

async function loadOptions() {
  if (!org()) return;
  try {
    const [tRes, wRes] = await Promise.all([
      api<{ data: VacancyTemplate[] }>("GET", "/api/v1/recruitment/templates", { org: org() }),
      api<{ data: RecruitmentWorkflow[] }>("GET", "/api/v1/recruitment/workflows", { org: org() }),
    ]);
    templates.value = tRes.data;
    workflows.value = wRes.data;
    const def = wRes.data.find((w) => w.is_default);
    if (def) form.value.workflow_id = def.id;
  } catch { /* non-fatal */ }
}

function applyTemplate(id: number | null) {
  if (!id) return;
  const t = templates.value.find((t) => t.id === id);
  if (!t) return;
  form.value.template_id = id;
  form.value.job_title   = form.value.job_title || t.job_title;
  form.value.location    = form.value.location  || (t.location ?? "");
  form.value.open_positions = form.value.open_positions || (t.default_positions ?? 1);
  form.value.application_form_schema = t.application_form_schema ?? [];
}

async function submit() {
  if (!org()) { error.value = "Select an organisation first."; return; }
  saving.value = true;
  error.value  = "";
  try {
    await api("POST", "/api/v1/recruitment/vacancies", { org: org(), body: form.value });
    router.push({ name: "vacancies" });
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to create vacancy.";
  } finally {
    saving.value = false;
  }
}

onMounted(loadOptions);
watch(currentOrgSlug, loadOptions);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <button class="back-btn" @click="router.back()">← Back</button>
        <h2>New Vacancy</h2>
      </div>
      <div class="steps">
        <span v-for="n in 3" :key="n" :class="['step-dot', { active: step === n, done: step > n }]">{{ n }}</span>
      </div>
    </div>

    <p v-if="error" class="error">{{ error }}</p>

    <div class="wizard-card">
      <!-- Step 1: Details -->
      <div v-if="step === 1" class="step-body">
        <h3 class="step-title">Step 1 — Details</h3>

        <div class="form-grid">
          <div class="form-group">
            <label>Template (optional)</label>
            <select class="form-select" :value="form.template_id" @change="applyTemplate(Number(($event.target as HTMLSelectElement).value) || null)">
              <option value="">— None —</option>
              <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
            </select>
          </div>

          <div class="form-group">
            <label>Vacancy Name <span class="req">*</span></label>
            <input v-model="form.name" class="form-input" placeholder="e.g. Q3 Software Engineer Hire" />
          </div>

          <div class="form-group">
            <label>Job Title <span class="req">*</span></label>
            <input v-model="form.job_title" class="form-input" placeholder="e.g. Software Engineer" />
          </div>

          <div class="form-group">
            <label>Location</label>
            <input v-model="form.location" class="form-input" placeholder="e.g. Nairobi, Remote" />
          </div>

          <div class="form-group">
            <label>Open Positions</label>
            <input v-model.number="form.open_positions" type="number" min="1" class="form-input" />
          </div>

          <div class="form-group">
            <label>Closing Date</label>
            <input v-model="form.closing_date" type="date" class="form-input" />
          </div>

          <div class="form-group span-2">
            <label>Workflow</label>
            <select v-model="form.workflow_id" class="form-select">
              <option :value="null">— None —</option>
              <option v-for="w in workflows" :key="w.id" :value="w.id">{{ w.name }}</option>
            </select>
          </div>

          <div class="form-group span-2">
            <label>Description</label>
            <textarea v-model="form.description" class="form-input" rows="4" placeholder="Job description…" />
          </div>
        </div>

        <div class="step-footer">
          <button class="btn btn-primary" :disabled="!form.name || !form.job_title" @click="step = 2">Next →</button>
        </div>
      </div>

      <!-- Step 2: Application Form Schema -->
      <div v-if="step === 2" class="step-body">
        <h3 class="step-title">Step 2 — Application Form</h3>
        <p class="step-desc">Define the fields applicants will fill when applying to this vacancy.</p>
        <FormFieldEditor v-model="form.application_form_schema" />
        <div class="step-footer">
          <button class="btn" @click="step = 1">← Back</button>
          <button class="btn btn-primary" @click="step = 3">Next →</button>
        </div>
      </div>

      <!-- Step 3: Review -->
      <div v-if="step === 3" class="step-body">
        <h3 class="step-title">Step 3 — Review</h3>
        <dl class="review-list">
          <dt>Vacancy Name</dt><dd>{{ form.name }}</dd>
          <dt>Job Title</dt><dd>{{ form.job_title }}</dd>
          <dt>Location</dt><dd>{{ form.location || "—" }}</dd>
          <dt>Open Positions</dt><dd>{{ form.open_positions }}</dd>
          <dt>Closing Date</dt><dd>{{ form.closing_date || "—" }}</dd>
          <dt>Workflow</dt><dd>{{ workflows.find((w) => w.id === form.workflow_id)?.name ?? "—" }}</dd>
          <dt>Form Fields</dt><dd>{{ form.application_form_schema.length }} defined</dd>
        </dl>
        <div class="step-footer">
          <button class="btn" @click="step = 2">← Back</button>
          <button class="btn btn-primary" :disabled="saving" @click="submit">
            {{ saving ? "Saving…" : "Create Vacancy" }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.back-btn { background: none; border: none; cursor: pointer; color: var(--color-text-muted); font-size: var(--font-size-sm); margin-bottom: 4px; display: block; }
.steps { display: flex; gap: var(--space-2); align-items: center; }
.step-dot { width: 28px; height: 28px; border-radius: 50%; border: 2px solid var(--color-border); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: var(--color-text-muted); }
.step-dot.active { border-color: var(--color-brand-base, #004C97); color: var(--color-brand-base, #004C97); }
.step-dot.done { background: var(--color-brand-base, #004C97); border-color: var(--color-brand-base, #004C97); color: #fff; }
.wizard-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: var(--space-6); max-width: 720px; }
.step-title { font-size: var(--font-size-lg); font-weight: var(--font-weight-semibold); margin-bottom: var(--space-4); }
.step-desc { font-size: var(--font-size-sm); color: var(--color-text-muted); margin-bottom: var(--space-4); }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); }
.form-group { display: flex; flex-direction: column; gap: var(--space-1); }
.form-group.span-2 { grid-column: span 2; }
.form-group label { font-size: var(--font-size-sm); font-weight: 500; }
.form-input, .form-select { padding: 8px 12px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--font-size-base); }
.form-input[type="date"] { font-family: inherit; }
textarea.form-input { resize: vertical; }
.req { color: #dc2626; }
.step-footer { display: flex; gap: var(--space-3); justify-content: flex-end; margin-top: var(--space-6); }
.review-list { display: grid; grid-template-columns: auto 1fr; gap: 8px 20px; font-size: var(--font-size-sm); margin-bottom: var(--space-5); }
.review-list dt { color: var(--color-text-muted); }
.review-list dd { font-weight: 500; }
</style>
