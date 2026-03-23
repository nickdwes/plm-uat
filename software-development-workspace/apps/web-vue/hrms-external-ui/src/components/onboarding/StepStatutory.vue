<script setup lang="ts">
/** Step 3 — Statutory & Compliance (Kenya). */
import { reactive, watch } from "vue";

interface StatutoryData {
  kra_pin: string;
  nssf_number: string;
  nhif_number: string;
  sha_number: string;
  bank_name: string;
  bank_branch: string;
  account_number: string;
  account_name: string;
  tax_obligations_flag: boolean;
}

const props = defineProps<{ modelValue: StatutoryData }>();
const emit = defineEmits<{ "update:modelValue": [v: StatutoryData] }>();

const form = reactive<StatutoryData>({ ...props.modelValue });
watch(form, (v) => emit("update:modelValue", { ...v }), { deep: true });
</script>

<template>
  <div class="ob-step">
    <h3 class="ob-step-title">Statutory &amp; Compliance</h3>
    <p class="ob-step-hint">
      Kenya statutory registration numbers and banking details. All fields are optional at this stage
      but must be complete before payroll processing.
    </p>

    <div class="statutory-section">
      <h4 class="section-sub">Tax &amp; Social Security</h4>
      <div class="form-grid">
        <div class="field">
          <label>KRA PIN</label>
          <input v-model="form.kra_pin" type="text" placeholder="A000000000Z" maxlength="11" />
          <span class="hint">Format: A000000000Z</span>
        </div>
        <div class="field">
          <label>NSSF Member Number</label>
          <input v-model="form.nssf_number" type="text" placeholder="6–10 digits" maxlength="10" />
        </div>
        <div class="field">
          <label>NHIF Member Number</label>
          <input v-model="form.nhif_number" type="text" placeholder="6–10 digits" maxlength="10" />
        </div>
        <div class="field">
          <label>SHA Number</label>
          <input v-model="form.sha_number" type="text" placeholder="Social Health Authority" maxlength="10" />
        </div>
      </div>
      <div class="field mt-1">
        <label class="checkbox-label">
          <input v-model="form.tax_obligations_flag" type="checkbox" />
          Employee has additional tax obligations (e.g. rental income, business income)
        </label>
      </div>
    </div>

    <div class="statutory-section">
      <h4 class="section-sub">Bank Account Details</h4>
      <div class="form-grid">
        <div class="field">
          <label>Bank Name</label>
          <input v-model="form.bank_name" type="text" placeholder="e.g. KCB Bank" />
        </div>
        <div class="field">
          <label>Bank Branch</label>
          <input v-model="form.bank_branch" type="text" placeholder="e.g. Westlands" />
        </div>
        <div class="field">
          <label>Account Number</label>
          <input v-model="form.account_number" type="text" placeholder="5–20 characters" />
        </div>
        <div class="field">
          <label>Account Name</label>
          <input v-model="form.account_name" type="text" placeholder="Name as on bank records" />
        </div>
      </div>
    </div>

    <div class="privacy-note">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
      Statutory and banking fields are encrypted at rest and masked in the UI for non-HR users.
    </div>
  </div>
</template>

<style scoped>
.ob-step-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.25rem; }
.ob-step-hint { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem; }
.statutory-section { margin-bottom: 1.5rem; }
.section-sub { font-size: 0.9rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.75rem; border-bottom: 1px solid var(--border); padding-bottom: 0.35rem; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.mt-1 { margin-top: 0.75rem; }
label { font-size: 0.8rem; font-weight: 500; color: var(--text-secondary); }
.hint { font-size: 0.73rem; color: var(--text-muted); }
input[type=text] { padding: 0.45rem 0.6rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem; }
input[type=text]:focus { outline: none; border-color: var(--primary); }
.checkbox-label { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; cursor: pointer; }
.privacy-note {
  display: flex; align-items: center; gap: 0.5rem;
  font-size: 0.8rem; color: #555; padding: 0.75rem;
  background: #f9f9f9; border-radius: 6px; border: 1px solid var(--border);
  margin-top: 1rem;
}
.privacy-note svg { width: 16px; height: 16px; flex-shrink: 0; color: var(--primary); }
</style>
