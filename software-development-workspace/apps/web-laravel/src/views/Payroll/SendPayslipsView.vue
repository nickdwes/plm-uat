<script setup lang="ts">
/**
 * Sending a period's payslips.
 *
 * The screen itself is SendDocumentsWizard; this says which documents and where
 * they come from. Loading the period name is the only work left here.
 */
import { onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import SendDocumentsWizard from "./SendDocumentsWizard.vue";

const route = useRoute();
const periodId = Number(route.params.id);
const periodName = ref("");

onMounted(async () => {
  try {
    const period = await api<any>("GET", `/api/v1/payroll/${periodId}`, { org: currentOrgSlug.value });
    periodName.value = period.name;
  } catch {
    // The wizard still works without it; the heading simply reads bare.
  }
});
</script>

<template>
  <SendDocumentsWizard
    title="Send Payslips"
    send-label="Send Pay-slips"
    :context="periodName"
    context-label="Payroll period"
    :recipients-url="`/api/v1/payroll/${periodId}/delivery/recipients`"
    :send-url="`/api/v1/payroll/${periodId}/delivery/send`"
    :default-subject="periodName ? `Payslip — ${periodName}` : 'Payslip'"
    attachment-label="PDF payslip, encrypted"
    amount-label="Net Pay"
    noun="payslip"
    done-title="Pay-slips sent"
    back-label="Back to period"
  />
</template>
