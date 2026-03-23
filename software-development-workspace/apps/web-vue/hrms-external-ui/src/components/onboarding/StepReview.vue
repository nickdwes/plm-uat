<script setup lang="ts">
/** Step 7 — Review & Submit: shows a summary of all entered data. */

defineProps<{
  personal: Record<string, unknown>;
  employment: Record<string, unknown>;
  statutory: Record<string, unknown>;
  qualifications: Record<string, unknown>[];
  nextOfKin: Record<string, unknown>[];
  emergencyContacts: Record<string, unknown>[];
  documents: Record<string, unknown>;
  consentCaptured: boolean;
}>();
</script>

<template>
  <div class="ob-step">
    <h3 class="ob-step-title">Review &amp; Submit</h3>
    <p class="ob-step-hint">
      Review all information before submitting for HR approval. You can navigate back to any step
      to make corrections.
    </p>

    <!-- Personal -->
    <div class="review-section">
      <h4>Personal Details</h4>
      <dl class="review-dl">
        <div v-for="[k, v] in Object.entries(personal).filter(([, v]) => v)" :key="k" class="review-row">
          <dt>{{ k.replace(/_/g, " ") }}</dt>
          <dd>{{ v }}</dd>
        </div>
      </dl>
    </div>

    <!-- Employment -->
    <div class="review-section">
      <h4>Employment Details</h4>
      <dl class="review-dl">
        <div v-for="[k, v] in Object.entries(employment).filter(([, v]) => v)" :key="k" class="review-row">
          <dt>{{ k.replace(/_/g, " ") }}</dt>
          <dd>{{ v }}</dd>
        </div>
      </dl>
    </div>

    <!-- Statutory (masked display) -->
    <div class="review-section">
      <h4>Statutory &amp; Compliance</h4>
      <dl class="review-dl">
        <div v-if="statutory['kra_pin']" class="review-row">
          <dt>KRA PIN</dt><dd class="masked">{{ statutory['kra_pin'] }}</dd>
        </div>
        <div v-if="statutory['nssf_number']" class="review-row">
          <dt>NSSF</dt><dd class="masked">{{ statutory['nssf_number'] }}</dd>
        </div>
        <div v-if="statutory['nhif_number']" class="review-row">
          <dt>NHIF</dt><dd class="masked">{{ statutory['nhif_number'] }}</dd>
        </div>
        <div v-if="statutory['bank_name']" class="review-row">
          <dt>Bank</dt><dd>{{ statutory['bank_name'] }} – {{ statutory['bank_branch'] }}</dd>
        </div>
        <div v-if="statutory['account_number']" class="review-row">
          <dt>Account</dt><dd class="masked">{{ (statutory['account_number'] as string).replace(/.(?=.{4})/g, '*') }}</dd>
        </div>
      </dl>
    </div>

    <!-- Qualifications -->
    <div class="review-section">
      <h4>Qualifications ({{ qualifications.length }})</h4>
      <div v-for="(q, i) in qualifications" :key="i" class="review-item">
        <strong>{{ q['qualification_type'] }}</strong> — {{ q['course_or_certification'] }},
        {{ q['institution_name'] }} ({{ q['year_completed'] || 'year n/a' }})
      </div>
      <p v-if="!qualifications.length" class="review-empty">None added.</p>
    </div>

    <!-- Next of Kin -->
    <div class="review-section">
      <h4>Next of Kin ({{ nextOfKin.length }})</h4>
      <div v-for="(n, i) in nextOfKin" :key="i" class="review-item">
        {{ n['full_name'] }} — {{ n['relationship'] }} — {{ n['phone_number'] }}
      </div>
      <p v-if="!nextOfKin.length" class="review-empty">None added.</p>
    </div>

    <!-- Emergency Contacts -->
    <div class="review-section">
      <h4>Emergency Contacts ({{ emergencyContacts.length }})</h4>
      <div v-for="(e, i) in emergencyContacts" :key="i" class="review-item">
        {{ e['full_name'] }} — {{ e['relationship'] }} — {{ e['phone_number'] }}
      </div>
      <p v-if="!emergencyContacts.length" class="review-empty">None added.</p>
    </div>

    <!-- Documents -->
    <div class="review-section">
      <h4>Documents</h4>
      <dl class="review-dl">
        <div v-for="[k, v] in Object.entries(documents).filter(([, v]) => v)" :key="k" class="review-row">
          <dt>{{ k.replace(/_/g, " ") }}</dt>
          <dd><a :href="v as string" target="_blank" rel="noopener noreferrer" class="doc-link">View file</a></dd>
        </div>
      </dl>
      <p v-if="!Object.values(documents).some(Boolean)" class="review-empty">No documents uploaded yet.</p>
    </div>

    <!-- Consent confirmation -->
    <div :class="['consent-summary', consentCaptured ? 'ok' : 'warn']">
      <svg viewBox="0 0 20 20" fill="currentColor">
        <path v-if="consentCaptured" fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
        <path v-else fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
      </svg>
      <span v-if="consentCaptured">Personal data consent has been captured. ✓</span>
      <span v-else>⚠ Personal data consent has NOT been captured. Go back to Step 1 to confirm consent.</span>
    </div>
  </div>
</template>

<style scoped>
.ob-step-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.25rem; }
.ob-step-hint { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem; }
.review-section { margin-bottom: 1.25rem; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.review-section h4 { font-size: 0.85rem; font-weight: 600; background: #f7f8fa; padding: 0.5rem 0.85rem; border-bottom: 1px solid var(--border); color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.04em; }
.review-dl { padding: 0.75rem 0.85rem; }
.review-row { display: grid; grid-template-columns: 180px 1fr; gap: 0.5rem; margin-bottom: 0.35rem; font-size: 0.85rem; }
dt { color: var(--text-muted); text-transform: capitalize; }
dd { font-weight: 500; }
.masked { font-family: monospace; letter-spacing: 0.05em; }
.review-item { font-size: 0.85rem; padding: 0.4rem 0.85rem; border-bottom: 1px solid var(--border); }
.review-item:last-child { border-bottom: none; }
.review-empty { font-size: 0.8rem; color: var(--text-muted); padding: 0.75rem 0.85rem; }
.doc-link { color: var(--primary); text-decoration: underline; font-size: 0.85rem; }
.consent-summary {
  display: flex; align-items: center; gap: 0.6rem;
  padding: 0.85rem; border-radius: 8px; font-size: 0.85rem; margin-top: 0.5rem;
}
.consent-summary svg { width: 18px; height: 18px; flex-shrink: 0; }
.consent-summary.ok { background: #f0fff4; border: 1px solid #9ae6b4; color: #276749; }
.consent-summary.ok svg { color: #38a169; }
.consent-summary.warn { background: #fffaed; border: 1px solid #f6e05e; color: #744210; }
.consent-summary.warn svg { color: #d69e2e; }
</style>
