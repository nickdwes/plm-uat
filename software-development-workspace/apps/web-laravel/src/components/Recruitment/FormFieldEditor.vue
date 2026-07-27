<script setup lang="ts">
import { ref } from "vue";
import type { FormFieldSchema } from "../../lib/types";

const props = defineProps<{ modelValue: FormFieldSchema[] }>();
const emit = defineEmits<{ "update:modelValue": [fields: FormFieldSchema[]] }>();

const FIELD_TYPES = ["text", "email", "number", "date", "textarea", "select", "file", "url"] as const;

function update(fields: FormFieldSchema[]) {
  emit("update:modelValue", fields);
}

function addField() {
  update([...props.modelValue, { key: "", label: "", type: "text", required: false, options: [] }]);
}

function removeField(idx: number) {
  const copy = [...props.modelValue];
  copy.splice(idx, 1);
  update(copy);
}

function updateField(idx: number, partial: Partial<FormFieldSchema>) {
  const copy = props.modelValue.map((f, i) => (i === idx ? { ...f, ...partial } : f));
  update(copy);
}

// Drag-to-reorder
const dragIdx    = ref<number | null>(null);
const dragOverIdx = ref<number | null>(null);

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
  const copy = [...props.modelValue];
  const [item] = copy.splice(dragIdx.value, 1);
  copy.splice(targetIdx, 0, item);
  update(copy);
  dragIdx.value = null;
  dragOverIdx.value = null;
}

function onDragEnd() {
  dragIdx.value = null;
  dragOverIdx.value = null;
}

function optionsText(field: FormFieldSchema) {
  return (field.options ?? []).join(", ");
}


function setOptions(idx: number, text: string) {
  updateField(idx, { options: text.split(",").map((s) => s.trim()).filter(Boolean) });
}
</script>

<template>
  <div class="field-editor">
    <div
      v-for="(field, idx) in modelValue"
      :key="idx"
      class="field-row"
      :class="{ 'drag-over': dragOverIdx === idx, dragging: dragIdx === idx }"
      draggable="true"
      @dragstart="onDragStart($event, idx)"
      @dragover="onDragOver($event, idx)"
      @drop="onDrop($event, idx)"
      @dragend="onDragEnd"
    >
      <div class="drag-handle" title="Drag to reorder">⠿</div>

      <div class="field-inputs">
        <div class="field-row-top">
          <input
            class="form-input"
            placeholder="Field name (no spaces)"
            :value="field.key"
            @input="updateField(idx, { key: ($event.target as HTMLInputElement).value.replace(/\s+/g, '_').toLowerCase() })"
          />
          <input
            class="form-input"
            placeholder="Label shown to applicant"
            :value="field.label"
            @input="updateField(idx, { label: ($event.target as HTMLInputElement).value })"
          />
          <select class="form-select" :value="field.type" @change="updateField(idx, { type: ($event.target as HTMLSelectElement).value as FormFieldSchema['type'] })">
            <option v-for="t in FIELD_TYPES" :key="t" :value="t">{{ t }}</option>
          </select>
          <label class="required-toggle" :title="'Required'">
            <input type="checkbox" :checked="field.required" @change="updateField(idx, { required: ($event.target as HTMLInputElement).checked })" />
            Req.
          </label>
        </div>
        <div v-if="field.type === 'select'" class="field-row-options">
          <input
            class="form-input"
            placeholder="Options, comma-separated"
            :value="optionsText(field)"
            @input="setOptions(idx, ($event.target as HTMLInputElement).value)"
          />
        </div>
      </div>

      <button class="remove-btn" title="Remove field" @click="removeField(idx)">✕</button>
    </div>

    <div v-if="modelValue.length === 0" class="empty-hint">No fields yet. Add one below.</div>

    <button class="add-btn" @click="addField">+ Add Field</button>
  </div>
</template>

<style scoped>
.field-editor { display: flex; flex-direction: column; gap: var(--space-2); }
.field-row { display: flex; align-items: flex-start; gap: var(--space-2); background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-3); transition: border-color 0.15s; }
.field-row.drag-over { border-color: var(--color-brand-base); background: rgba(0,76,151,0.04); }
.field-row.dragging { opacity: 0.4; }
.drag-handle { cursor: grab; font-size: 18px; color: var(--color-text-muted); padding-top: 4px; user-select: none; }
.field-inputs { flex: 1; display: flex; flex-direction: column; gap: var(--space-2); }
.field-row-top { display: flex; gap: var(--space-2); flex-wrap: wrap; }
.field-row-options { display: flex; }
.form-input { flex: 1; min-width: 120px; padding: 6px 10px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--font-size-sm); }
.form-select { padding: 6px 10px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--font-size-sm); background: var(--color-surface); }
.required-toggle { display: flex; align-items: center; gap: 5px; font-size: var(--font-size-sm); white-space: nowrap; cursor: pointer; }
.remove-btn { background: none; border: none; cursor: pointer; color: var(--color-text-muted); font-size: 16px; padding: 4px; flex-shrink: 0; }
.remove-btn:hover { color: #dc2626; }
.empty-hint { text-align: center; color: var(--color-text-muted); font-size: var(--font-size-sm); padding: var(--space-4); }
.add-btn { align-self: flex-start; padding: 7px 16px; border: 1px dashed var(--color-border); border-radius: var(--radius-md); background: none; cursor: pointer; font-size: var(--font-size-sm); color: var(--color-text-muted); }
.add-btn:hover { border-color: var(--color-brand-base); color: var(--color-brand-base); }
</style>
