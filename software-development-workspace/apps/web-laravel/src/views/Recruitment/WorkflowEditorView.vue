<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";

const router = useRouter();
import type { RecruitmentWorkflow } from "../../lib/types";

const STAGE_TYPES = ["applied", "screening", "interview", "shortlisted", "offer", "hired", "rejected", "pre_onboarding"] as const;
type StageType = (typeof STAGE_TYPES)[number];

const workflows   = ref<RecruitmentWorkflow[]>([]);
const loading     = ref(false);
const saving      = ref(false);
const error       = ref("");
const selected    = ref<RecruitmentWorkflow | null>(null);
const editStages  = ref<{ name: string; stage_type: StageType }[]>([]);
const showNewWf   = ref(false);
const newWfName   = ref("");
const newWfDesc   = ref("");
const creatingWf  = ref(false);

// Drag-to-reorder state
const dragIdx    = ref<number | null>(null);
const dragOverIdx = ref<number | null>(null);

function org() { return currentOrgSlug.value; }

async function load() {
  if (!org()) return;
  loading.value = true;
  error.value   = "";
  try {
    const res = await api<{ data: RecruitmentWorkflow[] }>("GET", "/api/v1/recruitment/workflows", { org: org() });
    workflows.value = res.data;
    if (!selected.value && res.data.length) selectWorkflow(res.data[0]);
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load workflows.";
  } finally {
    loading.value = false;
  }
}

function selectWorkflow(wf: RecruitmentWorkflow) {
  selected.value  = wf;
  editStages.value = (wf.stages ?? []).map((s) => ({ name: s.name, stage_type: s.stage_type as StageType }));
}

function addStage() {
  editStages.value = [...editStages.value, { name: "", stage_type: "interview" }];
}

function removeStage(idx: number) {
  editStages.value = editStages.value.filter((_, i) => i !== idx);
}

function updateStage(idx: number, partial: Partial<{ name: string; stage_type: StageType }>) {
  editStages.value = editStages.value.map((s, i) => (i === idx ? { ...s, ...partial } : s));
}

async function saveStages() {
  if (!selected.value || !org()) return;
  saving.value = true;
  error.value  = "";
  try {
    const res = await api<{ data: RecruitmentWorkflow }>("PUT", `/api/v1/recruitment/workflows/${selected.value.id}/stages`, {
      org: org(),
      body: { stages: editStages.value },
    });
    const idx = workflows.value.findIndex((w) => w.id === res.data.id);
    if (idx !== -1) workflows.value[idx] = res.data;
    selected.value = res.data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to save stages.";
  } finally {
    saving.value = false;
  }
}

async function createWorkflow() {
  if (!org() || !newWfName.value.trim()) return;
  creatingWf.value = true;
  try {
    await api("POST", "/api/v1/recruitment/workflows", {
      org: org(),
      body: { name: newWfName.value.trim(), description: newWfDesc.value.trim(), stages: [] },
    });
    newWfName.value = "";
    newWfDesc.value = "";
    showNewWf.value = false;
    await load();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to create workflow.";
  } finally {
    creatingWf.value = false;
  }
}

// Drag-to-reorder handlers
function onDragStart(e: DragEvent, idx: number) {
  dragIdx.value = idx;
  e.dataTransfer!.effectAllowed = "move";
}

function onDragOver(e: DragEvent, idx: number) {
  e.preventDefault();
  dragOverIdx.value = idx;
}

function onDrop(e: DragEvent, targetIdx: number) {
  e.preventDefault();
  if (dragIdx.value === null || dragIdx.value === targetIdx) return;
  const copy = [...editStages.value];
  const [item] = copy.splice(dragIdx.value, 1);
  copy.splice(targetIdx, 0, item);
  editStages.value = copy;
  dragIdx.value = null;
  dragOverIdx.value = null;
}

function onDragEnd() {
  dragIdx.value = null;
  dragOverIdx.value = null;
}

function stageColor(type: string) {
  const m: Record<string, string> = { hired: "#059669", rejected: "#dc2626", offer: "#d97706", interview: "#6366f1", screening: "#0ea5e9", applied: "#6b7280", shortlisted: "#f59e0b", pre_onboarding: "#8b5cf6" };
  return m[type] ?? "#9ca3af";
}

onMounted(load);
watch(currentOrgSlug, () => { selected.value = null; load(); });
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <button class="back-btn" @click="router.back()">← Back</button>
        <h2>Recruitment Workflows</h2>
      </div>
      <button class="btn btn-primary" @click="showNewWf = !showNewWf">+ New Workflow</button>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error" class="error">{{ error }}</p>

    <!-- New workflow form -->
    <div v-if="showNewWf" class="new-wf-card">
      <h4>Create Workflow</h4>
      <div class="form-row">
        <input v-model="newWfName" class="form-input" placeholder="Workflow name *" />
        <input v-model="newWfDesc" class="form-input" placeholder="Description (optional)" />
        <button class="btn btn-primary" :disabled="creatingWf || !newWfName.trim()" @click="createWorkflow">
          {{ creatingWf ? "Creating…" : "Create" }}
        </button>
        <button class="btn" @click="showNewWf = false">Cancel</button>
      </div>
    </div>

    <div class="editor-layout">
      <!-- Workflow list sidebar -->
      <div class="wf-list">
        <div
          v-for="wf in workflows"
          :key="wf.id"
          :class="['wf-item', { active: selected?.id === wf.id }]"
          @click="selectWorkflow(wf)"
        >
          <div class="wf-name">{{ wf.name }}</div>
          <div class="wf-meta">{{ (wf.stages ?? []).length }} stages{{ wf.is_default ? " · default" : "" }}</div>
        </div>
        <div v-if="workflows.length === 0" class="empty-msg">No workflows. Create one above.</div>
      </div>

      <!-- Stage editor -->
      <div v-if="selected" class="stage-editor">
        <div class="editor-header">
          <h3>{{ selected.name }}</h3>
          <button class="btn btn-primary btn-sm" :disabled="saving" @click="saveStages">
            {{ saving ? "Saving…" : "Save Stages" }}
          </button>
        </div>

        <div class="stages-list">
          <div
            v-for="(stage, idx) in editStages"
            :key="idx"
            class="stage-row"
            :class="{ 'drag-over': dragOverIdx === idx, dragging: dragIdx === idx }"
            draggable="true"
            @dragstart="onDragStart($event, idx)"
            @dragover="onDragOver($event, idx)"
            @drop="onDrop($event, idx)"
            @dragend="onDragEnd"
          >
            <div class="stage-drag-handle" title="Drag to reorder">⠿</div>
            <span class="stage-badge" :style="{ background: stageColor(stage.stage_type) }">{{ idx + 1 }}</span>
            <input
              class="form-input stage-name"
              placeholder="Stage name"
              :value="stage.name"
              @input="updateStage(idx, { name: ($event.target as HTMLInputElement).value })"
            />
            <select
              class="form-select stage-type"
              :value="stage.stage_type"
              @change="updateStage(idx, { stage_type: ($event.target as HTMLSelectElement).value as StageType })"
            >
              <option v-for="t in STAGE_TYPES" :key="t" :value="t">{{ t }}</option>
            </select>
            <button class="icon-btn danger" @click="removeStage(idx)">✕</button>
          </div>

          <div v-if="editStages.length === 0" class="empty-stages">No stages yet.</div>
          <button class="add-stage-btn" @click="addStage">+ Add Stage</button>
        </div>
      </div>

      <div v-else class="no-selection">Select a workflow to edit its stages.</div>
    </div>
  </div>
</template>

<style scoped>
.back-btn { background: none; border: none; cursor: pointer; color: var(--color-text-muted); font-size: var(--font-size-sm); margin-bottom: 4px; display: block; }
.back-btn:hover { color: var(--color-text); }
.new-wf-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: var(--space-4); margin-bottom: var(--space-5); }
.new-wf-card h4 { font-weight: 600; margin-bottom: var(--space-3); }
.form-row { display: flex; gap: var(--space-2); flex-wrap: wrap; }
.form-input, .form-select { padding: 8px 12px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--font-size-sm); }
.form-input { flex: 1; min-width: 160px; }
.editor-layout { display: grid; grid-template-columns: 220px 1fr; gap: var(--space-5); }
.wf-list { display: flex; flex-direction: column; gap: var(--space-1); }
.wf-item { padding: var(--space-3); border-radius: var(--radius-md); cursor: pointer; border: 1px solid transparent; }
.wf-item:hover { background: var(--color-bg); }
.wf-item.active { background: var(--color-bg); border-color: var(--color-brand-base); }
.wf-name { font-weight: 600; font-size: var(--font-size-sm); }
.wf-meta { font-size: 11px; color: var(--color-text-muted); }
.empty-msg { padding: var(--space-4); color: var(--color-text-muted); font-size: var(--font-size-sm); text-align: center; }
.stage-editor { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: var(--space-5); }
.editor-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4); }
.editor-header h3 { font-weight: 600; font-size: var(--font-size-lg); }
.stages-list { display: flex; flex-direction: column; gap: var(--space-2); }
.stage-row { display: flex; align-items: center; gap: var(--space-2); padding: var(--space-3); background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.stage-row.drag-over { border-color: var(--color-brand-base); background: rgba(0,76,151,0.04); }
.stage-row.dragging { opacity: 0.4; }
.stage-drag-handle { cursor: grab; color: var(--color-text-muted); font-size: 18px; user-select: none; }
.stage-badge { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 11px; font-weight: 700; flex-shrink: 0; }
.stage-name { flex: 1; }
.stage-type { width: 130px; }
.icon-btn { background: none; border: none; cursor: pointer; padding: 4px; color: var(--color-text-muted); font-size: 15px; flex-shrink: 0; }
.icon-btn.danger:hover { color: #dc2626; }
.empty-stages { text-align: center; padding: var(--space-5); color: var(--color-text-muted); font-size: var(--font-size-sm); }
.add-stage-btn { align-self: flex-start; padding: 7px 16px; border: 1px dashed var(--color-border); border-radius: var(--radius-md); background: none; cursor: pointer; font-size: var(--font-size-sm); color: var(--color-text-muted); margin-top: var(--space-2); }
.add-stage-btn:hover { border-color: var(--color-brand-base); color: var(--color-brand-base); }
.no-selection { display: flex; align-items: center; justify-content: center; color: var(--color-text-muted); font-size: var(--font-size-sm); }
</style>
