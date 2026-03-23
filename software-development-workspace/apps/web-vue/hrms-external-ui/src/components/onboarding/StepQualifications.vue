<script setup lang="ts">
/** Step 4 — Academic & Professional Qualifications. */
import { reactive, watch } from "vue";
import type { OnboardingQualification } from "../../lib/types";

interface QualificationsData {
  qualifications: OnboardingQualification[];
}

const props = defineProps<{ modelValue: QualificationsData }>();
const emit = defineEmits<{ "update:modelValue": [v: QualificationsData] }>();

const form = reactive<QualificationsData>({
  qualifications: props.modelValue.qualifications.map((q) => ({ ...q })),
});
watch(form, (v) => emit("update:modelValue", { qualifications: v.qualifications.map((q) => ({ ...q })) }), { deep: true });

function addRow(): void {
  form.qualifications.push({
    qualification_type: "Academic",
    institution_name: "",
    course_or_certification: "",
    year_completed: "",
    supporting_document: null,
  });
}
function removeRow(idx: number): void {
  form.qualifications.splice(idx, 1);
}
</script>

<template>
  <div class="ob-step">
    <h3 class="ob-step-title">Qualifications</h3>
    <p class="ob-step-hint">List academic degrees and professional certifications. Attach supporting documents where available.</p>

    <div v-for="(q, idx) in form.qualifications" :key="idx" class="qual-row">
      <div class="qual-header">
        <span class="qual-num">{{ idx + 1 }}</span>
        <button type="button" class="remove-btn" @click="removeRow(idx)">Remove</button>
      </div>
      <div class="form-grid">
        <div class="field">
          <label>Type <span class="req">*</span></label>
          <select v-model="q.qualification_type">
            <option value="Academic">Academic</option>
            <option value="Professional">Professional</option>
          </select>
        </div>
        <div class="field">
          <label>Institution / Certifying Body <span class="req">*</span></label>
          <input v-model="q.institution_name" type="text" placeholder="e.g. University of Nairobi" />
        </div>
        <div class="field">
          <label>Course / Certification <span class="req">*</span></label>
          <input v-model="q.course_or_certification" type="text" placeholder="e.g. BSc Computer Science" />
        </div>
        <div class="field">
          <label>Year Completed</label>
          <input v-model="q.year_completed" type="text" placeholder="e.g. 2019" maxlength="4" />
        </div>
      </div>
    </div>

    <button type="button" class="add-btn" @click="addRow">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
      Add Qualification
    </button>

    <p v-if="!form.qualifications.length" class="empty-hint">
      No qualifications added yet. Click "Add Qualification" to start.
    </p>
  </div>
</template>

<style scoped>
.ob-step-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.25rem; }
.ob-step-hint { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem; }
.qual-row { border: 1px solid var(--border); border-radius: 8px; padding: 1rem; margin-bottom: 1rem; background: #fafafa; }
.qual-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
.qual-num { font-size: 0.75rem; font-weight: 700; color: var(--primary); background: #e8f0fe; padding: 2px 8px; border-radius: 12px; }
.remove-btn { font-size: 0.75rem; color: #e53e3e; background: none; border: none; cursor: pointer; padding: 2px 6px; border-radius: 4px; }
.remove-btn:hover { background: #fed7d7; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
label { font-size: 0.8rem; font-weight: 500; color: var(--text-secondary); }
.req { color: #e53e3e; }
input, select { padding: 0.45rem 0.6rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem; background: #fff; }
input:focus, select:focus { outline: none; border-color: var(--primary); }
.add-btn {
  display: flex; align-items: center; gap: 0.4rem;
  padding: 0.5rem 1rem; border: 1px dashed var(--primary);
  border-radius: 6px; background: none; color: var(--primary);
  font-size: 0.85rem; cursor: pointer; margin-top: 0.5rem;
}
.add-btn svg { width: 16px; height: 16px; }
.add-btn:hover { background: #eef2ff; }
.empty-hint { font-size: 0.85rem; color: var(--text-muted); text-align: center; padding: 2rem; }
</style>
