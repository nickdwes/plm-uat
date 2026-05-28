<script setup lang="ts">
import { ref, computed } from "vue";
import type { Application, WorkflowStage } from "../../lib/types";

const props = defineProps<{
  stages: WorkflowStage[];
  applications: Application[];
  loading?: boolean;
}>();

const emit = defineEmits<{
  "stage-changed": [appId: number, stageId: number];
  "open-app": [app: Application];
}>();

// Group applications by current_stage id
const columns = computed(() =>
  props.stages.map((stage) => ({
    stage,
    apps: props.applications.filter((a) => a.current_stage?.id === stage.id),
  }))
);

const dragging    = ref<Application | null>(null);
const dragOverId  = ref<number | null>(null);

function onDragStart(e: DragEvent, app: Application) {
  dragging.value = app;
  e.dataTransfer!.effectAllowed = "move";
  e.dataTransfer!.setData("text/plain", String(app.id));
}

function onDragEnd() {
  dragging.value = null;
  dragOverId.value = null;
}

function onDragOver(e: DragEvent, stageId: number) {
  e.preventDefault();
  e.dataTransfer!.dropEffect = "move";
  dragOverId.value = stageId;
}

function onDragLeave() {
  dragOverId.value = null;
}

function onDrop(e: DragEvent, stageId: number) {
  e.preventDefault();
  dragOverId.value = null;
  if (!dragging.value) return;
  if (dragging.value.current_stage?.id === stageId) return;
  emit("stage-changed", dragging.value.id, stageId);
  dragging.value = null;
}

function stageColor(type: string) {
  const map: Record<string, string> = {
    hired: "#059669",
    rejected: "#dc2626",
    offer: "#d97706",
    interview: "#6366f1",
    screening: "#0ea5e9",
    applied: "#6b7280",
  };
  return map[type] ?? "#9ca3af";
}
</script>

<template>
  <div class="pipeline-board" :class="{ 'is-loading': loading }">
    <div
      v-for="col in columns"
      :key="col.stage.id"
      class="pipeline-column"
      :class="{ 'drag-over': dragOverId === col.stage.id }"
      @dragover="onDragOver($event, col.stage.id)"
      @dragleave="onDragLeave"
      @drop="onDrop($event, col.stage.id)"
    >
      <div class="col-header">
        <span class="col-dot" :style="{ background: stageColor(col.stage.stage_type) }"></span>
        <span class="col-title">{{ col.stage.name }}</span>
        <span class="col-badge">{{ col.apps.length }}</span>
      </div>

      <div class="col-cards">
        <div
          v-for="app in col.apps"
          :key="app.id"
          class="app-card"
          :class="{ dragging: dragging?.id === app.id }"
          draggable="true"
          @dragstart="onDragStart($event, app)"
          @dragend="onDragEnd"
          @click="emit('open-app', app)"
        >
          <div class="card-name">{{ app.candidate?.full_name ?? "Unknown" }}</div>
          <div class="card-sub">{{ app.candidate?.email }}</div>
          <div v-if="app.screening_score != null" class="card-score">
            <span class="score-bar-bg">
              <span class="score-bar-fill" :style="{ width: app.screening_score + '%' }"></span>
            </span>
            <span class="score-label">{{ app.screening_score }}%</span>
          </div>
          <div class="card-footer">
            <span class="card-src">{{ app.candidate?.source ?? "" }}</span>
            <span class="card-date">{{ new Date(app.applied_at).toLocaleDateString() }}</span>
          </div>
        </div>

        <div v-if="col.apps.length === 0" class="empty-col">No applicants</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.pipeline-board { display: flex; gap: var(--space-4); overflow-x: auto; padding-bottom: var(--space-3); min-height: 480px; }
.pipeline-column { flex: 0 0 240px; background: var(--color-bg); border-radius: var(--radius-lg); border: 2px solid transparent; transition: border-color 0.15s; display: flex; flex-direction: column; }
.pipeline-column.drag-over { border-color: var(--color-brand-base, #004C97); background: rgba(0,76,151,0.04); }
.col-header { display: flex; align-items: center; gap: var(--space-2); padding: var(--space-3) var(--space-3) var(--space-2); }
.col-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.col-title { font-weight: var(--font-weight-semibold); font-size: var(--font-size-sm); flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.col-badge { background: var(--color-border); border-radius: 10px; padding: 1px 7px; font-size: 11px; font-weight: 600; color: var(--color-text-muted); }
.col-cards { flex: 1; display: flex; flex-direction: column; gap: var(--space-2); padding: var(--space-2) var(--space-3) var(--space-3); overflow-y: auto; }
.app-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-3); cursor: grab; display: flex; flex-direction: column; gap: 4px; transition: box-shadow 0.15s, opacity 0.15s; }
.app-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.10); }
.app-card.dragging { opacity: 0.4; cursor: grabbing; }
.card-name { font-weight: var(--font-weight-semibold); font-size: var(--font-size-sm); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.card-sub { font-size: 11px; color: var(--color-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.score-bar-bg { display: inline-block; width: 60px; height: 5px; border-radius: 3px; background: var(--color-border); vertical-align: middle; }
.score-bar-fill { display: block; height: 100%; border-radius: 3px; background: var(--color-brand-base, #004C97); }
.card-score { display: flex; align-items: center; gap: var(--space-2); }
.score-label { font-size: 11px; color: var(--color-text-muted); }
.card-footer { display: flex; justify-content: space-between; font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }
.empty-col { text-align: center; color: var(--color-text-muted); font-size: var(--font-size-sm); padding: var(--space-6); }
.is-loading { opacity: 0.55; pointer-events: none; }
</style>
