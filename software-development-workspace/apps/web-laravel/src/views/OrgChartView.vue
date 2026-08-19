<script setup lang="ts">
/**
 * The organisation, as a chart.
 *
 * A page of its own rather than a tab, because it is the one view here people
 * open to look at rather than to change something — it wants the width, and it
 * is reached from the HR dashboard rather than from the middle of a settings
 * screen.
 *
 * Two pictures of the same organisation. HR means the structure — units,
 * departments, who heads them. A manager means the reporting line — who answers
 * to whom. They disagree often enough to be worth both.
 */
import { computed, onMounted, ref, watch } from "vue";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import OrgChartNode, { type ChartNode } from "../components/OrgChartNode.vue";
import type { OrgStructureData } from "../lib/types";

const chartView   = ref<"reporting" | "units">("reporting");
const chart       = ref<{ reporting: ChartNode[]; unassigned: any[]; headcount: number;
                          organization: { id: number; name: string } | null } | null>(null);
const orgStructure = ref<OrgStructureData | null>(null);
const chartBusy   = ref(false);
const chartError  = ref("");
const chartSearch = ref("");
const chartDepth  = ref(3);
const picked      = ref<ChartNode | null>(null);

async function loadChart(): Promise<void> {
  chartBusy.value = true; chartError.value = "";
  try {
    chart.value = await api("GET", "/api/v1/org/org-chart", { org: currentOrgSlug.value });
  } catch (e) {
    chartError.value = e instanceof Error ? e.message : "Could not build the chart.";
  } finally { chartBusy.value = false; }
}

async function loadStructure(): Promise<void> {
  if (orgStructure.value) return;
  try {
    orgStructure.value = await api("GET", "/api/v1/org/structure", { org: currentOrgSlug.value });
  } catch { orgStructure.value = null; }
}

/** How many people the chart actually draws, versus how many there are. */
const charted = computed(() => {
  const count = (nodes: ChartNode[]): number =>
    nodes.reduce((n, x) => n + 1 + count(x.children), 0);
  return chart.value ? count(chart.value.reporting) : 0;
});

function init(): void {
  orgStructure.value = null;
  void loadChart();
}

onMounted(init);
watch(currentOrgSlug, init);
</script>

<template>
  <div class="view">
    <div class="oc-head">
      <div>
        <h2>Org Chart</h2>
        <p class="oc-sub">{{ chart?.organization?.name ?? "" }}</p>
      </div>
    </div>

    <div class="oc-bar">
      <div class="oc-switch">
        <button :class="['oc-switch-btn', { on: chartView === 'reporting' }]"
                @click="chartView = 'reporting'">Reporting line</button>
        <button :class="['oc-switch-btn', { on: chartView === 'units' }]"
                @click="chartView = 'units'; loadStructure()">By unit</button>
      </div>

      <input v-if="chartView === 'reporting'" v-model="chartSearch" class="input oc-find"
             placeholder="Find a person, title or department" />

      <label v-if="chartView === 'reporting'" class="oc-depth">
        <span>Open to</span>
        <select v-model.number="chartDepth" class="input">
          <option :value="1">1 level</option>
          <option :value="2">2 levels</option>
          <option :value="3">3 levels</option>
          <option :value="99">Everything</option>
        </select>
      </label>

      <span class="oc-count" v-if="chart">
        {{ charted }} of {{ chart.headcount }} on the chart
      </span>
    </div>

    <p v-if="chartError" class="error">{{ chartError }}</p>
    <p v-if="chartBusy" class="muted">Building…</p>

    <template v-if="chartView === 'reporting' && chart">
      <!--
        People whose manager is not set. Surfaced rather than dropped: an org
        chart that silently omits them looks complete and is not, and the
        missing person is usually the one somebody is looking for.
      -->
      <p v-if="chart.reporting.length > 1" class="oc-note">
        {{ chart.reporting.length }} people have no manager set, so the chart has
        {{ chart.reporting.length }} tops rather than one.
      </p>
      <p v-if="chart.unassigned.length" class="oc-note">
        {{ chart.unassigned.length }} active
        {{ chart.unassigned.length === 1 ? "person is" : "people are" }} in no department:
        {{ chart.unassigned.map((u: any) => u.name).join(", ") }}
      </p>

      <div class="oc-canvas">
        <ul class="oc-root">
          <OrgChartNode
            v-for="root in chart.reporting"
            :key="root.id"
            :node="root"
            :collapse-below="chartDepth"
            :highlight="chartSearch"
            @pick="picked = $event"
          />
        </ul>
      </div>

      <p v-if="!chart.reporting.length" class="empty">
        Nobody active in this organisation yet.
      </p>
    </template>

    <!--
      The structural view, from the existing units endpoint. Departments with
      no functional unit above them are shown too — the tree used to drop them.
    -->
    <template v-else-if="chartView === 'units'">
      <div v-if="orgStructure" class="oc-units">
        <div v-for="unit in orgStructure.functional_units" :key="unit.id" class="oc-unit">
          <h4>{{ unit.name }}</h4>
          <div v-for="dept in unit.departments" :key="dept.id" class="oc-unit-dept">
            <div class="oc-unit-row">
              <strong>{{ dept.name }}</strong>
              <span class="oc-unit-meta">
                {{ dept.headcount }} {{ dept.headcount === 1 ? "person" : "people" }}
                <template v-if="dept.lead"> · led by {{ dept.lead.first_name }} {{ dept.lead.last_name }}</template>
              </span>
            </div>
            <div v-for="bu in dept.business_units" :key="bu.id" class="oc-unit-bu">
              {{ bu.name }}
              <span v-for="pr in bu.practices" :key="pr.id" class="oc-unit-practice">{{ pr.name }}</span>
            </div>
          </div>
          <p v-if="!unit.departments?.length" class="oc-unit-meta">No departments under this unit.</p>
        </div>
        <!--
          Departments with no functional unit above them. The endpoint returns
          them separately and the chart shows them, because a department that
          is simply missing from the picture reads as a department that does
          not exist.
        -->
        <div v-if="orgStructure.unassigned_departments?.length" class="oc-unit">
          <h4>Not under a functional unit</h4>
          <div v-for="dept in orgStructure.unassigned_departments" :key="dept.id" class="oc-unit-dept">
            <div class="oc-unit-row">
              <strong>{{ dept.name }}</strong>
              <span class="oc-unit-meta">
                {{ dept.headcount }} {{ dept.headcount === 1 ? "person" : "people" }}
              </span>
            </div>
          </div>
        </div>

        <p v-if="!orgStructure.functional_units?.length && !orgStructure.unassigned_departments?.length"
           class="empty">No functional units or departments configured.</p>
      </div>
    </template>

    <!-- One person, on click. -->
    <div v-if="picked" class="oc-drawer-overlay" @click.self="picked = null">
      <div class="oc-drawer" role="dialog" aria-modal="true">
        <button class="oc-drawer-close" aria-label="Close" @click="picked = null">✕</button>
        <h3>{{ picked.name }}</h3>
        <p class="oc-drawer-title">{{ picked.title ?? "No designation" }}</p>
        <dl class="oc-drawer-facts">
          <dt>Employee number</dt><dd>{{ picked.number }}</dd>
          <dt>Department</dt><dd>{{ picked.department ?? "—" }}</dd>
          <dt>Email</dt><dd>{{ picked.email ?? "—" }}</dd>
          <dt>People below</dt><dd>{{ picked.reports }}</dd>
          <dt v-if="picked.is_shared">Shared</dt>
          <dd v-if="picked.is_shared">Also works in another organisation</dd>
        </dl>
        <router-link class="btn btn-secondary btn-sm"
                     :to="{ name: 'employee-detail', params: { id: picked.id } }">
          Open employee record
        </router-link>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../theme.css";

.oc-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between;
  gap: 12px; margin-bottom: 14px; }
.oc-head h2 { margin: 0; font-weight: 600; color: var(--color-text); }
.oc-sub { margin: 2px 0 0; font-size: .85rem; color: var(--color-text-light); }
.error { font-size: .86rem; color: var(--color-error); background: var(--color-error-light);
  border-radius: 8px; padding: 10px 12px; }
.muted, .empty { font-size: .85rem; color: var(--color-text-light); }
.input { font-family: inherit; font-size: .88rem; padding: 7px 10px;
  border: 1px solid var(--color-border-strong); border-radius: 8px;
  background: var(--color-surface); color: var(--color-text); }

/* ── Org chart ─────────────────────────────────────────────────────────── */
.oc-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; }
.oc-switch { display: inline-flex; border: 1px solid var(--color-border); border-radius: 8px; overflow: hidden; }
.oc-switch-btn { padding: 6px 14px; font-size: .84rem; background: var(--color-surface);
  border: none; cursor: pointer; color: var(--color-text-light); }
.oc-switch-btn.on { background: var(--color-brand-subtle); color: var(--color-brand-base); font-weight: 600; }
.oc-find { max-width: 20rem; }
.oc-depth { display: inline-flex; align-items: center; gap: 6px; font-size: .82rem; color: var(--color-text-light); }
.oc-depth .input { width: auto; }
.oc-count { margin-left: auto; font-size: .8rem; color: var(--color-text-light); }
.oc-note { font-size: .82rem; color: var(--color-text-light); margin: 0 0 8px; }

/* Wide charts scroll inside their own box; the page never scrolls sideways. */
.oc-canvas { overflow-x: auto; padding: 8px 4px 28px; }
.oc-root { display: flex; justify-content: flex-start; gap: 24px; margin: 0; padding: 0; list-style: none; min-width: min-content; }

.oc-units { display: flex; flex-direction: column; gap: 14px; }
.oc-unit { border: 1px solid var(--color-border); border-radius: 10px; padding: 14px; background: var(--color-surface); }
.oc-unit h4 { margin: 0 0 8px; font-size: .95rem; color: var(--color-text); }
.oc-unit-dept { padding: 8px 0; border-top: 1px solid var(--color-border); }
.oc-unit-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: baseline; }
.oc-unit-meta { font-size: .78rem; color: var(--color-text-light); }
.oc-unit-bu { margin: 4px 0 0 14px; font-size: .8rem; color: var(--color-text-light); }
.oc-unit-practice { display: inline-block; margin-left: 8px; font-size: .72rem;
  padding: 1px 7px; border-radius: 999px; background: var(--color-surface-sunken); }

.oc-drawer-overlay { position: fixed; inset: 0; z-index: 50; display: flex;
  align-items: center; justify-content: center; background: rgb(0 0 0 / 45%); padding: 20px; }
.oc-drawer { position: relative; background: var(--color-surface); border-radius: 12px;
  padding: 22px; width: min(26rem, 100%); display: flex; flex-direction: column; gap: 10px; }
.oc-drawer h3 { margin: 0; color: var(--color-text); }
.oc-drawer-title { margin: 0; font-size: .86rem; color: var(--color-text-light); }
.oc-drawer-close { position: absolute; top: 12px; right: 14px; background: none;
  border: none; cursor: pointer; color: var(--color-text-light); }
.oc-drawer-facts { display: grid; grid-template-columns: 9rem 1fr; gap: 6px 12px;
  margin: 6px 0 0; font-size: .84rem; }
.oc-drawer-facts dt { color: var(--color-text-light); }
.oc-drawer-facts dd { margin: 0; color: var(--color-text); }
</style>
