<script setup lang="ts">
import { ref } from "vue";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { TrainingEvidenceFile } from "../../lib/types";

const props = withDefaults(defineProps<{
  recordId: number;
  existingFiles?: TrainingEvidenceFile[];
  category?: "certificate" | "transcript" | "screenshot" | "other";
}>(), {
  category: "certificate",
});

const emit = defineEmits<{
  (e: "uploaded", file: TrainingEvidenceFile): void;
}>();

const dragging = ref(false);
const uploading = ref(false);
const error = ref("");
const uploadedFiles = ref<TrainingEvidenceFile[]>(
  (props.existingFiles ?? []).filter(f => (f.category ?? "certificate") === props.category)
);

const ALLOWED_TYPES = [
  "application/pdf",
  "image/jpeg",
  "image/png",
  "image/webp",
  "application/msword",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
];
const MAX_BYTES = 10 * 1024 * 1024; // 10 MB

function org() { return currentOrgSlug.value; }

function formatBytes(bytes: number) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function fileIcon(mime: string) {
  if (mime === "application/pdf") return "📄";
  if (mime.startsWith("image/")) return "🖼️";
  return "📎";
}

function onDragOver(e: DragEvent) {
  e.preventDefault();
  dragging.value = true;
}

function onDragLeave() { dragging.value = false; }

function onDrop(e: DragEvent) {
  e.preventDefault();
  dragging.value = false;
  const files = e.dataTransfer?.files;
  if (files?.length) handleFiles(Array.from(files));
}

function onInputChange(e: Event) {
  const input = e.target as HTMLInputElement;
  if (input.files?.length) {
    handleFiles(Array.from(input.files));
    input.value = "";
  }
}

async function handleFiles(files: File[]) {
  error.value = "";
  for (const file of files) {
    if (!ALLOWED_TYPES.includes(file.type)) {
      error.value = `"${file.name}" has an unsupported file type. Allowed: PDF, JPEG, PNG, WEBP, DOC, DOCX.`;
      continue;
    }
    if (file.size > MAX_BYTES) {
      error.value = `"${file.name}" exceeds the 10 MB limit (${formatBytes(file.size)}).`;
      continue;
    }
    await uploadFile(file);
  }
}

async function uploadFile(file: File) {
  uploading.value = true;
  const formData = new FormData();
  formData.append("file", file);
  formData.append("category", props.category);

  try {
    const res = await api<{ data: TrainingEvidenceFile }>(
      "POST",
      `/api/v1/training/records/${props.recordId}/evidence`,
      { org: org(), formData }
    );
    uploadedFiles.value.push(res.data);
    emit("uploaded", res.data);
  } catch (e: unknown) {
    const err = e as { message?: string };
    error.value = err.message ?? `Failed to upload "${file.name}".`;
  } finally {
    uploading.value = false;
  }
}
</script>

<template>
  <div class="evidence-field">
    <!-- Drop zone -->
    <div
      class="drop-zone"
      :class="{ dragging, uploading }"
      @dragover="onDragOver"
      @dragleave="onDragLeave"
      @drop="onDrop"
      @click="($refs.fileInput as HTMLInputElement).click()"
    >
      <input
        ref="fileInput"
        type="file"
        multiple
        class="hidden-input"
        accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
        @change="onInputChange"
      />
      <div class="drop-icon">{{ uploading ? "⏳" : "📂" }}</div>
      <div class="drop-label">
        {{ uploading ? "Uploading…" : "Drop files here or click to browse" }}
      </div>
      <div class="drop-hint">PDF, JPEG, PNG, WEBP, DOC, DOCX · Max 10 MB each</div>
    </div>

    <!-- Error -->
    <div v-if="error" class="upload-error">⚠ {{ error }}</div>

    <!-- Uploaded files list -->
    <div v-if="uploadedFiles.length > 0" class="file-list">
      <div v-for="f in uploadedFiles" :key="f.id" class="file-item">
        <span class="file-icon">{{ fileIcon(f.mime_type) }}</span>
        <div class="file-info">
          <span class="file-name">{{ f.original_name }}</span>
          <span class="file-meta">{{ f.category }} · {{ formatBytes(f.size_bytes) }}</span>
        </div>
        <span class="file-date">{{ new Date(f.created_at).toLocaleDateString() }}</span>
      </div>
    </div>
    <div v-else class="no-files">No evidence files uploaded yet.</div>
  </div>
</template>

<style scoped>
.evidence-field { display: flex; flex-direction: column; gap: 12px; }

.drop-zone {
  border: 2px dashed #d1d5db;
  border-radius: 10px;
  padding: 32px 20px;
  text-align: center;
  cursor: pointer;
  transition: border-color 0.2s, background 0.2s;
  background: #fafafa;
  user-select: none;
}

.drop-zone:hover, .drop-zone.dragging {
  border-color: #3366cc;
  background: #f0f4ff;
}

.drop-zone.uploading { opacity: 0.6; pointer-events: none; }

.hidden-input { display: none; }

.drop-icon { font-size: 36px; margin-bottom: 8px; }

.drop-label {
  font-size: 14px;
  font-weight: 500;
  color: #374151;
  margin-bottom: 4px;
}

.drop-hint { font-size: 12px; color: #9ca3af; }

.upload-error {
  background: #fff3f3;
  color: #c0392b;
  border: 1px solid #f5c6c6;
  border-radius: 6px;
  padding: 8px 12px;
  font-size: 13px;
}

.file-list {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: hidden;
}

.file-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-bottom: 1px solid #f3f4f6;
  background: white;
}

.file-item:last-child { border-bottom: none; }

.file-icon { font-size: 20px; flex-shrink: 0; }

.file-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.file-name {
  font-size: 13px;
  font-weight: 500;
  color: #111;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.file-meta { font-size: 11px; color: #9ca3af; }

.file-date { font-size: 11px; color: #bbb; flex-shrink: 0; }

.no-files { font-size: 13px; color: #bbb; text-align: center; padding: 12px; }
</style>
