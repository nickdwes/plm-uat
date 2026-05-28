<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";

const router = useRouter();
import type { VacancyTemplate, RecruitmentWorkflow, FormFieldSchema } from "../../lib/types";
import FormFieldEditor from "../../components/Recruitment/FormFieldEditor.vue";
import DynamicForm from "../../components/Recruitment/DynamicForm.vue";

const templates   = ref<VacancyTemplate[]>([]);
const workflows   = ref<RecruitmentWorkflow[]>([]);
const loading     = ref(false);
const error       = ref("");
const saving      = ref(false);
const showForm    = ref(false);
const editTarget  = ref<VacancyTemplate | null>(null);
const previewMode = ref(false);

const form = ref({
  name: "", job_title: "", location: "", default_positions: 1,
  workflow_id: null as number | null,
  application_form_schema: [] as FormFieldSchema[],
  screening_rules: [] as { rule_type: string; value: string; weight: number }[],
});

function org() { return currentOrgSlug.value; }

async function load() {
  if (!org()) return;
  loading.value = true;
  error.value   = "";
  try {
    const [tRes, wRes] = await Promise.all([
      api<{ data: VacancyTemplate[] }>("GET", "/api/v1/recruitment/templates", { org: org() }),
      api<{ data: RecruitmentWorkflow[] }>("GET", "/api/v1/recruitment/workflows", { org: org() }),
    ]);
    templates.value = tRes.data;
    workflows.value = wRes.data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load templates.";
  } finally {
    loading.value = false;
  }
}

function openCreate() {
  editTarget.value = null;
  form.value = { name: "", job_title: "", location: "", default_positions: 1, workflow_id: null, application_form_schema: [], screening_rules: [] };
  const def = workflows.value.find((w) => w.is_default);
  if (def) form.value.workflow_id = def.id;
  showForm.value = true;
}

function openEdit(t: VacancyTemplate) {
  editTarget.value = t;
  form.value = {
    name: t.name,
    job_title: t.job_title,
    location: t.location ?? "",
    default_positions: t.default_positions ?? 1,
    workflow_id: t.workflow?.id ?? null,
    application_form_schema: t.application_form_schema ?? [],
    screening_rules: (t.screening_rules ?? []) as { rule_type: string; value: string; weight: number }[],
  };
  showForm.value = true;
}

async function save() {
  if (!org()) return;
  saving.value = true;
  error.value  = "";
  try {
    if (editTarget.value) {
      await api("PUT", `/api/v1/recruitment/templates/${editTarget.value.id}`, { org: org(), body: form.value });
    } else {
      await api("POST", "/api/v1/recruitment/templates", { org: org(), body: form.value });
    }
    showForm.value = false;
    await load();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to save template.";
  } finally {
    saving.value = false;
  }
}

async function duplicate(t: VacancyTemplate) {
  if (!org()) return;
  try {
    await api("POST", `/api/v1/recruitment/templates/${t.id}/duplicate`, { org: org() });
    await load();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to duplicate.";
  }
}

async function deleteTemplate(t: VacancyTemplate) {
  if (!confirm(`Delete template "${t.name}"?`)) return;
  try {
    await api("DELETE", `/api/v1/recruitment/templates/${t.id}`, { org: org() });
    await load();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to delete.";
  }
}

onMounted(load);
watch(currentOrgSlug, load);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <button class="back-btn" @click="router.back()">← Back</button>
        <h2>Vacancy Templates</h2>
      </div>
      <button class="btn btn-primary" @click="openCreate">+ New Template</button>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error" class="error">{{ error }}</p>

    <!-- Template list -->
    <div v-if="!loading && !showForm" class="template-grid">
      <div v-if="templates.length === 0" class="empty-msg">No templates yet. Create one to get started.</div>
      <div v-for="t in templates" :key="t.id" class="template-card">
        <div class="template-header">
          <div>
            <div class="template-name">{{ t.name }}</div>
            <div class="template-title">{{ t.job_title }}</div>
          </div>
          <div class="template-actions">
            <button class="icon-btn" title="Edit" @click="openEdit(t)">✎</button>
            <button class="icon-btn" title="Duplicate" @click="duplicate(t)">⎘</button>
            <button class="icon-btn danger" title="Delete" @click="deleteTemplate(t)">✕</button>
          </div>
        </div>
        <div class="template-meta">
          {{ t.location ?? "No location" }} · {{ t.default_positions ?? 1 }} position(s)
          · {{ (t.application_form_schema ?? []).length }} form fields
        </div>
      </div>
    </div>

    <!-- Create / Edit form -->
    <div v-if="showForm" class="wizard-card">
      <h3 class="step-title">{{ editTarget ? "Edit Template" : "New Template" }}</h3>

      <div class="form-grid">
        <div class="form-group">
          <label>Template Name <span class="req">*</span></label>
          <input v-model="form.name" class="form-input" placeholder="e.g. Software Engineer Template" />
        </div>
        <div class="form-group">
          <label>Job Title <span class="req">*</span></label>
          <input v-model="form.job_title" class="form-input" placeholder="e.g. Software Engineer" />
        </div>
        <div class="form-group">
          <label>Location</label>
          <input v-model="form.location" class="form-input" placeholder="e.g. Nairobi" />
        </div>
        <div class="form-group">
          <label>Default Positions</label>
          <input v-model.number="form.default_positions" type="number" min="1" class="form-input" />
        </div>
        <div class="form-group span-2">
          <label>Workflow</label>
          <select v-model="form.workflow_id" class="form-select">
            <option :value="null">— None —</option>
            <option v-for="w in workflows" :key="w.id" :value="w.id">{{ w.name }}</option>
          </select>
        </div>
      </div>

      <div class="section-divider">
        <span>Application Form Fields</span>
        <button class="btn btn-sm" @click="previewMode = !previewMode">
          {{ previewMode ? "Edit Fields" : "Preview Form" }}
        </button>
      </div>

      <FormFieldEditor v-if="!previewMode" v-model="form.application_form_schema" />
      <DynamicForm v-else :fields="form.application_form_schema" :model-value="{}" readonly />

      <div class="step-footer">
        <button class="btn" @click="showForm = false">Cancel</button>
        <button class="btn btn-primary" :disabled="saving || !form.name || !form.job_title" @click="save">
          {{ saving ? "Saving…" : editTarget ? "Update" : "Create" }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.back-btn { background: none; border: none; cursor: pointer; color: var(--color-text-muted); font-size: var(--font-size-sm); margin-bottom: 4px; display: block; }
.back-btn:hover { color: var(--color-text); }
.template-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--space-4); }
.template-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: var(--space-4); }
.template-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: var(--space-2); }
.template-name { font-weight: 600; font-size: var(--font-size-base); }
.template-title { font-size: var(--font-size-sm); color: var(--color-text-muted); }
.template-actions { display: flex; gap: 4px; }
.template-meta { font-size: 12px; color: var(--color-text-muted); }
.icon-btn { background: none; border: none; cursor: pointer; padding: 4px 6px; border-radius: var(--radius-sm); color: var(--color-text-muted); font-size: 15px; }
.icon-btn:hover { background: var(--color-bg); color: var(--color-text); }
.icon-btn.danger:hover { color: #dc2626; }
.empty-msg { grid-column: 1/-1; text-align: center; padding: var(--space-8); color: var(--color-text-muted); }
.wizard-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: var(--space-6); max-width: 720px; }
.step-title { font-size: var(--font-size-lg); font-weight: 600; margin-bottom: var(--space-4); }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); }
.form-group { display: flex; flex-direction: column; gap: var(--space-1); }
.form-group.span-2 { grid-column: span 2; }
.form-group label { font-size: var(--font-size-sm); font-weight: 500; }
.form-input, .form-select { padding: 8px 12px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--font-size-base); }
.req { color: #dc2626; }
.section-divider { display: flex; align-items: center; justify-content: space-between; border-top: 1px solid var(--color-border); padding-top: var(--space-4); margin: var(--space-5) 0 var(--space-4); font-size: var(--font-size-sm); font-weight: 600; color: var(--color-text-muted); }
.step-footer { display: flex; gap: var(--space-3); justify-content: flex-end; margin-top: var(--space-5); }
</style>
