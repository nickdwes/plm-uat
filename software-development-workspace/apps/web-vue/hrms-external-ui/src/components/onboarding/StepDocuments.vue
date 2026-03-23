<script setup lang="ts">
/** Step 6 — Documents upload manifest.
 *  Shows which documents are required and links to attach them.
 *  The actual file attachment is handled by the frappe /api/method/upload_file endpoint
 *  and the resulting URL is stored in the attach fields.
 */
import { reactive, watch } from "vue";

interface DocumentsData {
  national_id_copy: string | null;
  kra_pin_certificate: string | null;
  nssf_proof: string | null;
  nhif_proof: string | null;
  academic_certificates: string | null;
  employment_contract: string | null;
  compliance_documents: string | null;
}

const props = defineProps<{ modelValue: DocumentsData }>();
const emit = defineEmits<{ "update:modelValue": [v: DocumentsData] }>();

const form = reactive<DocumentsData>({ ...props.modelValue });
watch(form, (v) => emit("update:modelValue", { ...v }), { deep: true });

const DOCS = [
  { key: "national_id_copy", label: "National ID / Passport Copy", required: true },
  { key: "kra_pin_certificate", label: "KRA PIN Certificate", required: true },
  { key: "nssf_proof", label: "NSSF Proof", required: false },
  { key: "nhif_proof", label: "NHIF Proof", required: false },
  { key: "academic_certificates", label: "Academic Certificates (zipped)", required: false },
  { key: "employment_contract", label: "Signed Employment Contract", required: false },
  { key: "compliance_documents", label: "Other Compliance Documents", required: false },
] as const;

type DocKey = (typeof DOCS)[number]["key"];

function fileUrl(key: DocKey): string {
  return (form[key] as string | null) ?? "";
}
function setFileUrl(key: DocKey, val: string): void {
  (form as Record<string, unknown>)[key] = val || null;
}
</script>

<template>
  <div class="ob-step">
    <h3 class="ob-step-title">Documents</h3>
    <p class="ob-step-hint">
      Upload supporting documents. The system stores secure links; originals may be required for
      verification.
    </p>

    <div class="doc-grid">
      <div v-for="doc in DOCS" :key="doc.key" class="doc-card">
        <div class="doc-info">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
          </svg>
          <div>
            <p class="doc-name">{{ doc.label }} <span v-if="doc.required" class="req">*</span></p>
            <p v-if="fileUrl(doc.key)" class="doc-attached">
              ✓ Attached
              <a :href="fileUrl(doc.key)" target="_blank" rel="noopener noreferrer" class="doc-link">View</a>
            </p>
            <p v-else class="doc-missing">Not yet uploaded</p>
          </div>
        </div>
        <div class="doc-field">
          <label class="small-label">File URL (paste after upload)</label>
          <input
            :value="fileUrl(doc.key)"
            type="text"
            placeholder="/files/..."
            @input="setFileUrl(doc.key, ($event.target as HTMLInputElement).value)"
          />
        </div>
      </div>
    </div>

    <div class="upload-note">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
      Upload files via the Frappe file manager and paste the resulting URL into each field above.
      Documents marked <span class="req">*</span> must be uploaded before the record can be submitted for approval.
    </div>
  </div>
</template>

<style scoped>
.ob-step-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.25rem; }
.ob-step-hint { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem; }
.doc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
.doc-card { border: 1px solid var(--border); border-radius: 8px; padding: 0.85rem; background: #fafafa; }
.doc-info { display: flex; align-items: flex-start; gap: 0.6rem; margin-bottom: 0.6rem; }
.doc-info svg { width: 20px; height: 20px; color: #718096; flex-shrink: 0; margin-top: 2px; }
.doc-name { font-size: 0.85rem; font-weight: 500; }
.doc-attached { font-size: 0.75rem; color: #38a169; margin-top: 2px; }
.doc-missing { font-size: 0.75rem; color: var(--text-muted); margin-top: 2px; }
.doc-link { margin-left: 0.4rem; color: var(--primary); text-decoration: underline; font-size: 0.75rem; }
.doc-field { display: flex; flex-direction: column; gap: 0.3rem; }
.small-label { font-size: 0.75rem; color: var(--text-muted); }
input { padding: 0.4rem 0.55rem; border: 1px solid var(--border); border-radius: 5px; font-size: 0.8rem; width: 100%; box-sizing: border-box; }
input:focus { outline: none; border-color: var(--primary); }
.req { color: #e53e3e; }
.upload-note {
  display: flex; align-items: flex-start; gap: 0.5rem;
  font-size: 0.8rem; color: #555; padding: 0.75rem;
  background: #fffaed; border-radius: 6px; border: 1px solid #f6e05e;
  margin-top: 1.25rem;
}
.upload-note svg { width: 16px; height: 16px; flex-shrink: 0; color: #d69e2e; margin-top: 1px; }
</style>
