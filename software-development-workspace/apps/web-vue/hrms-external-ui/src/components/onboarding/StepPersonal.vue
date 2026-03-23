<script setup lang="ts">
/** Step 1 — Personal Details capture form. */
import { reactive, watch } from "vue";

interface PersonalData {
  first_name: string;
  middle_name: string;
  last_name: string;
  national_id_type: string;
  national_id_number: string;
  date_of_birth: string;
  gender: string;
  marital_status: string;
  phone_number: string;
  personal_email: string;
  work_email: string;
  physical_address: string;
}

const props = defineProps<{ modelValue: PersonalData; consentCaptured: boolean }>();
const emit = defineEmits<{
  "update:modelValue": [v: PersonalData];
  "update:consentCaptured": [v: boolean];
}>();

const form = reactive<PersonalData>({ ...props.modelValue });
let consentLocal = props.consentCaptured;

watch(form, (v) => emit("update:modelValue", { ...v }), { deep: true });
</script>

<template>
  <div class="ob-step">
    <h3 class="ob-step-title">Personal Details</h3>
    <p class="ob-step-hint">Enter the new employee's personal information as it appears on official documents.</p>

    <div class="form-grid">
      <div class="field">
        <label>First Name <span class="req">*</span></label>
        <input v-model="form.first_name" type="text" placeholder="First name" required />
      </div>
      <div class="field">
        <label>Middle Name</label>
        <input v-model="form.middle_name" type="text" placeholder="Middle name (optional)" />
      </div>
      <div class="field">
        <label>Last Name <span class="req">*</span></label>
        <input v-model="form.last_name" type="text" placeholder="Last name" required />
      </div>

      <div class="field">
        <label>ID Type <span class="req">*</span></label>
        <select v-model="form.national_id_type">
          <option value="">— Select —</option>
          <option value="National ID">National ID</option>
          <option value="Passport">Passport</option>
        </select>
      </div>
      <div class="field">
        <label>ID / Passport Number <span class="req">*</span></label>
        <input v-model="form.national_id_number" type="text" placeholder="e.g. 12345678" />
      </div>

      <div class="field">
        <label>Date of Birth <span class="req">*</span></label>
        <input v-model="form.date_of_birth" type="date" />
      </div>
      <div class="field">
        <label>Gender</label>
        <select v-model="form.gender">
          <option value="">— Select —</option>
          <option>Male</option>
          <option>Female</option>
          <option>Other</option>
        </select>
      </div>
      <div class="field">
        <label>Marital Status</label>
        <select v-model="form.marital_status">
          <option value="">— Select —</option>
          <option>Single</option>
          <option>Married</option>
          <option>Divorced</option>
          <option>Widowed</option>
        </select>
      </div>

      <div class="field">
        <label>Phone Number <span class="req">*</span></label>
        <input v-model="form.phone_number" type="tel" placeholder="+254 7XX XXX XXX" />
      </div>
      <div class="field">
        <label>Personal Email <span class="req">*</span></label>
        <input v-model="form.personal_email" type="email" placeholder="personal@example.com" />
      </div>
      <div class="field">
        <label>Work Email</label>
        <input v-model="form.work_email" type="email" placeholder="work@company.com" />
      </div>

      <div class="field full-width">
        <label>Physical Address</label>
        <textarea v-model="form.physical_address" rows="2" placeholder="Street, City, County" />
      </div>
    </div>

    <!-- Consent capture -->
    <div class="consent-block">
      <label class="consent-label">
        <input
          type="checkbox"
          :checked="consentCaptured"
          @change="emit('update:consentCaptured', ($event.target as HTMLInputElement).checked)"
        />
        <span>
          I confirm that the employee has been informed of, and consents to, the collection and
          processing of their personal data in accordance with the organisation's data protection
          policy and applicable law.
          <span class="req">*</span>
        </span>
      </label>
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
input, select, textarea {
  padding: 0.45rem 0.6rem; border: 1px solid var(--border); border-radius: 6px;
  font-size: 0.875rem; background: #fff; color: var(--text);
  transition: border-color 0.15s;
}
input:focus, select:focus, textarea:focus { outline: none; border-color: var(--primary); }
.consent-block { margin-top: 1.5rem; padding: 1rem; background: #f0f7ff; border-radius: 8px; border: 1px solid #bee3f8; }
.consent-label { display: flex; gap: 0.75rem; align-items: flex-start; font-size: 0.85rem; cursor: pointer; }
.consent-label input[type=checkbox] { margin-top: 2px; width: 16px; height: 16px; flex-shrink: 0; }
</style>
