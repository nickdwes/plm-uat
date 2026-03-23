<script setup lang="ts">
/** Step 2 — Employment Details. */
import { reactive, watch } from "vue";
import type { CompanyRecord, DepartmentRecord, DesignationRecord, EmploymentTypeRecord, EmployeeRecord } from "../../lib/types";

interface EmploymentData {
  company: string;
  department: string;
  designation: string;
  employment_type: string;
  date_of_hire: string;
  reporting_manager: string;
  work_location: string;
  probation_period_months: string;
  group_function: string;
}

const props = defineProps<{
  modelValue: EmploymentData;
  companies: CompanyRecord[];
  departments: DepartmentRecord[];
  designations: DesignationRecord[];
  employmentTypes: EmploymentTypeRecord[];
  managers: EmployeeRecord[];
}>();
const emit = defineEmits<{ "update:modelValue": [v: EmploymentData] }>();

const form = reactive<EmploymentData>({ ...props.modelValue });
watch(form, (v) => emit("update:modelValue", { ...v }), { deep: true });
</script>

<template>
  <div class="ob-step">
    <h3 class="ob-step-title">Employment Details</h3>
    <p class="ob-step-hint">Define the employment structure, organisation placement, and reporting line.</p>

    <div class="form-grid">
      <div class="field">
        <label>Company <span class="req">*</span></label>
        <select v-model="form.company">
          <option value="">— Select company —</option>
          <option v-for="c in companies" :key="c.name" :value="c.name">
            {{ c.company_name || c.name }}
          </option>
        </select>
      </div>
      <div class="field">
        <label>Department <span class="req">*</span></label>
        <select v-model="form.department">
          <option value="">— Select department —</option>
          <option v-for="d in departments" :key="d.name" :value="d.name">
            {{ d.department_name || d.name }}
          </option>
        </select>
      </div>
      <div class="field">
        <label>Designation / Job Title</label>
        <select v-model="form.designation">
          <option value="">— Select designation —</option>
          <option v-for="d in designations" :key="d.name" :value="d.name">
            {{ d.designation_name || d.name }}
          </option>
        </select>
      </div>
      <div class="field">
        <label>Employment Type</label>
        <select v-model="form.employment_type">
          <option value="">— Select type —</option>
          <option v-for="t in employmentTypes" :key="t.name" :value="t.name">
            {{ t.employee_type_name || t.name }}
          </option>
        </select>
      </div>
      <div class="field">
        <label>Date of Hire <span class="req">*</span></label>
        <input v-model="form.date_of_hire" type="date" />
      </div>
      <div class="field">
        <label>Probation Period (Months)</label>
        <input v-model="form.probation_period_months" type="number" min="0" max="24" placeholder="3" />
      </div>
      <div class="field">
        <label>Reporting Manager</label>
        <select v-model="form.reporting_manager">
          <option value="">— Select manager —</option>
          <option v-for="m in managers" :key="m.name" :value="m.name">
            {{ m.employee_name }} ({{ m.name }})
          </option>
        </select>
      </div>
      <div class="field">
        <label>Work Location</label>
        <input v-model="form.work_location" type="text" placeholder="e.g. Nairobi HQ" />
      </div>
      <div class="field full-width">
        <label>Group-Level Function</label>
        <input
          v-model="form.group_function"
          type="text"
          placeholder="e.g. Group Finance — only if assigned to a cross-company function"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.ob-step-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.25rem; }
.ob-step-hint { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.35rem; }
.field.full-width { grid-column: 1 / -1; }
label { font-size: 0.8rem; font-weight: 500; color: var(--text-secondary); }
.req { color: #e53e3e; }
input, select { padding: 0.45rem 0.6rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem; background: #fff; }
input:focus, select:focus { outline: none; border-color: var(--primary); }
</style>
