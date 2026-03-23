<script setup lang="ts">
/** Step 5 — Next of Kin & Emergency Contacts. */
import { reactive, watch } from "vue";
import type { OnboardingNextOfKin, OnboardingEmergencyContact } from "../../lib/types";

interface NokData {
  next_of_kin: OnboardingNextOfKin[];
  emergency_contacts: OnboardingEmergencyContact[];
}

const props = defineProps<{ modelValue: NokData }>();
const emit = defineEmits<{ "update:modelValue": [v: NokData] }>();

const form = reactive<NokData>({
  next_of_kin: props.modelValue.next_of_kin.map((n) => ({ ...n })),
  emergency_contacts: props.modelValue.emergency_contacts.map((e) => ({ ...e })),
});
watch(form, (v) => emit("update:modelValue", {
  next_of_kin: v.next_of_kin.map((n) => ({ ...n })),
  emergency_contacts: v.emergency_contacts.map((e) => ({ ...e })),
}), { deep: true });

function addNok(): void {
  form.next_of_kin.push({ full_name: "", relationship: "", phone_number: "", email: null, address: null });
}
function removeNok(idx: number): void { form.next_of_kin.splice(idx, 1); }

function addEmergency(): void {
  form.emergency_contacts.push({ full_name: "", relationship: "", phone_number: "", alternate_phone: null, address: null });
}
function removeEmergency(idx: number): void { form.emergency_contacts.splice(idx, 1); }
</script>

<template>
  <div class="ob-step">
    <h3 class="ob-step-title">Next of Kin &amp; Emergency Contacts</h3>
    <p class="ob-step-hint">At least one next of kin and one emergency contact are strongly recommended.</p>

    <!-- Next of Kin -->
    <div class="section-block">
      <div class="section-head">
        <h4>Next of Kin</h4>
        <button type="button" class="add-btn" @click="addNok">+ Add</button>
      </div>
      <div v-for="(n, idx) in form.next_of_kin" :key="'nok-' + idx" class="entry-row">
        <div class="entry-header">
          <span class="entry-label">Next of Kin {{ idx + 1 }}</span>
          <button type="button" class="remove-btn" @click="removeNok(idx)">Remove</button>
        </div>
        <div class="form-grid">
          <div class="field">
            <label>Full Name <span class="req">*</span></label>
            <input v-model="n.full_name" type="text" placeholder="Full name" />
          </div>
          <div class="field">
            <label>Relationship <span class="req">*</span></label>
            <input v-model="n.relationship" type="text" placeholder="e.g. Spouse, Parent" />
          </div>
          <div class="field">
            <label>Phone Number <span class="req">*</span></label>
            <input v-model="n.phone_number" type="tel" placeholder="+254 7XX XXX XXX" />
          </div>
          <div class="field">
            <label>Email</label>
            <input v-model="n.email" type="email" placeholder="optional" />
          </div>
          <div class="field full-width">
            <label>Address</label>
            <textarea v-model="n.address" rows="2" placeholder="optional" />
          </div>
        </div>
      </div>
      <p v-if="!form.next_of_kin.length" class="empty-hint">No next of kin added.</p>
    </div>

    <!-- Emergency Contacts -->
    <div class="section-block">
      <div class="section-head">
        <h4>Emergency Contacts</h4>
        <button type="button" class="add-btn" @click="addEmergency">+ Add</button>
      </div>
      <div v-for="(e, idx) in form.emergency_contacts" :key="'emg-' + idx" class="entry-row">
        <div class="entry-header">
          <span class="entry-label">Emergency Contact {{ idx + 1 }}</span>
          <button type="button" class="remove-btn" @click="removeEmergency(idx)">Remove</button>
        </div>
        <div class="form-grid">
          <div class="field">
            <label>Full Name <span class="req">*</span></label>
            <input v-model="e.full_name" type="text" placeholder="Full name" />
          </div>
          <div class="field">
            <label>Relationship <span class="req">*</span></label>
            <input v-model="e.relationship" type="text" placeholder="e.g. Sister" />
          </div>
          <div class="field">
            <label>Phone Number <span class="req">*</span></label>
            <input v-model="e.phone_number" type="tel" placeholder="+254 7XX XXX XXX" />
          </div>
          <div class="field">
            <label>Alternate Phone</label>
            <input v-model="e.alternate_phone" type="tel" placeholder="optional" />
          </div>
          <div class="field full-width">
            <label>Address</label>
            <textarea v-model="e.address" rows="2" placeholder="optional" />
          </div>
        </div>
      </div>
      <p v-if="!form.emergency_contacts.length" class="empty-hint">No emergency contacts added.</p>
    </div>
  </div>
</template>

<style scoped>
.ob-step-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.25rem; }
.ob-step-hint { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem; }
.section-block { margin-bottom: 1.5rem; }
.section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
.section-head h4 { font-size: 0.95rem; font-weight: 600; color: var(--text-secondary); }
.entry-row { border: 1px solid var(--border); border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem; background: #fafafa; }
.entry-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
.entry-label { font-size: 0.75rem; font-weight: 700; color: var(--primary); background: #e8f0fe; padding: 2px 8px; border-radius: 12px; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field.full-width { grid-column: 1 / -1; }
label { font-size: 0.8rem; font-weight: 500; color: var(--text-secondary); }
.req { color: #e53e3e; }
input, textarea { padding: 0.45rem 0.6rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem; background: #fff; }
input:focus, textarea:focus { outline: none; border-color: var(--primary); }
.add-btn { font-size: 0.8rem; padding: 0.3rem 0.75rem; border: 1px solid var(--primary); border-radius: 6px; background: none; color: var(--primary); cursor: pointer; }
.add-btn:hover { background: #eef2ff; }
.remove-btn { font-size: 0.75rem; color: #e53e3e; background: none; border: none; cursor: pointer; padding: 2px 6px; }
.remove-btn:hover { background: #fed7d7; border-radius: 4px; }
.empty-hint { font-size: 0.85rem; color: var(--text-muted); text-align: center; padding: 1.5rem; }
</style>
