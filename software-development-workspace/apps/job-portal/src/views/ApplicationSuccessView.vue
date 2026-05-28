<template>
  <div class="success-page container">
    <div class="success-card">
      <div class="success-icon">🎉</div>
      <h1 class="success-title">Application Submitted!</h1>
      <p class="success-msg">Thank you for applying. Our team will review your application and be in touch shortly.</p>

      <div class="ref-box" v-if="reference">
        <span class="ref-label">Your reference number</span>
        <span class="ref-num">{{ reference }}</span>
        <p class="ref-note">Save this number for any follow-up enquiries.</p>
      </div>

      <div class="next-steps">
        <h3 class="steps-title">What happens next?</h3>
        <ol class="steps-list">
          <li>Our recruitment team reviews all applications within 5 business days.</li>
          <li>Shortlisted candidates are contacted via the email provided.</li>
          <li>Selected applicants will be invited for an interview.</li>
        </ol>
      </div>

      <div class="actions">
        <router-link to="/" class="btn btn-primary">← Browse more jobs</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useRoute } from 'vue-router'
const route     = useRoute()
const reference = route.query.ref ?? null
const jobTitle  = route.query.title ?? 'this position'
</script>

<style scoped>
.success-page { display: flex; justify-content: center; padding: 60px 24px; }
.success-card {
  max-width: 560px; width: 100%;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 16px; padding: 48px 40px; text-align: center;
  box-shadow: var(--shadow-lg);
}
.success-icon { font-size: 52px; margin-bottom: 20px; }
.success-title { font-size: 28px; font-weight: 800; margin-bottom: 12px; color: var(--text); }
.success-msg { font-size: 16px; color: var(--text-muted); line-height: 1.6; margin-bottom: 28px; }

.ref-box { background: var(--brand-light); border-radius: 10px; padding: 20px 24px; margin-bottom: 28px; }
.ref-label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: var(--brand); margin-bottom: 6px; }
.ref-num { display: block; font-size: 22px; font-weight: 800; letter-spacing: 2px; color: var(--brand); }
.ref-note { font-size: 12px; color: var(--text-muted); margin-top: 8px; }

.next-steps { background: var(--bg); border-radius: 10px; padding: 20px 24px; margin-bottom: 28px; text-align: left; }
.steps-title { font-size: 15px; font-weight: 700; margin-bottom: 12px; }
.steps-list { list-style: decimal; padding-left: 18px; display: flex; flex-direction: column; gap: 8px; font-size: 14px; color: var(--text-muted); line-height: 1.6; }

.actions { display: flex; justify-content: center; }
</style>
