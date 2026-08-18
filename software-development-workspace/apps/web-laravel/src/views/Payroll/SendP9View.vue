<script setup lang="ts">
/**
 * Sending a year's P9As.
 *
 * The same wizard as the payslip send, which is the point: whoever runs payroll
 * in January runs this in June, and a second screen with its own habits is how
 * the two drift apart.
 *
 * The amount column is PAYE paid for the year rather than net pay — it is the
 * figure the P9 is checked against, and showing net pay here would invite
 * somebody to reconcile the wrong number.
 */
import { computed } from "vue";
import { useRoute } from "vue-router";
import SendDocumentsWizard from "./SendDocumentsWizard.vue";

const route = useRoute();
const year = computed(() => Number(route.params.year));
</script>

<template>
  <SendDocumentsWizard
    title="Send P9 Forms"
    send-label="Send P9 Forms"
    :context="String(year)"
    context-label="Tax year"
    :recipients-url="`/api/v1/payroll/p9/${year}/delivery/recipients`"
    :send-url="`/api/v1/payroll/p9/${year}/delivery/send`"
    :default-subject="`Your P9A tax deduction card — ${year}`"
    attachment-label="PDF P9A tax deduction card, encrypted"
    amount-label="PAYE for year"
    noun="P9A"
    done-title="P9 forms sent"
    back-label="Back to P9"
  />
</template>
