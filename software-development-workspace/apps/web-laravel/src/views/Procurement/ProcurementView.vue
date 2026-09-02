<script setup lang="ts">
/**
 * Procurement register — the org-wide view.
 *
 * Every item a project (and, later, an asset or consumable top-up) needs
 * bought: what, how many, from whom, by when, at what cost, and where its
 * approval stands. The same register shows on each project; this is all of it.
 */
import { ref, onMounted } from "vue";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";

interface Row {
  id: number; reference: string | null; context: string;
  project: { id: number; name: string } | null; task: { id: number; title: string } | null;
  item: string; category: string | null; quantity: number; unit: string | null;
  vendor: string | null; required_by: string | null; lead_time_days: number | null; is_long_lead: boolean;
  estimated_cost: string | null; currency: string; approval_status: string; status: string; notes: string | null;
  lifecycle_stage: string; priority: string; business_justification: string | null; department: string | null;
  budget: { result: string | null; available: number | null; committed: number | null; checked_at: string | null };
  approvals: ApprovalStep[];
}
interface ApprovalStep {
  stage: string; sequence: number; status: string; escalated: boolean;
  expected: string | null; comment: string | null; decided_at: string | null;
}
interface Summary { count: number; pending_approval: number; long_lead: number; estimated_total: number }

interface Quotation {
  id: number; vendor: string | null; vendor_partner_id: number; amount: number; currency: string;
  lead_time_days: number | null; warranty_terms: string | null; payment_terms: string | null;
  price_score: number | null; delivery_score: number | null; technical_score: number | null;
  vendor_score: number | null; total_score: number | null; is_awarded: boolean; recommended: boolean;
  award_justification: string | null;
}
interface Receipt {
  id: number; reference: string | null; quantity_received: number; variance: number;
  quality_status: string; quality_notes: string | null; on_time: boolean | null;
  received_by: string | null; received_at: string | null;
}
interface PoRow {
  id: number; reference: string | null; vendor: string | null; status: string;
  amount: number; tax_rate: number; tax_amount: number; total_amount: number; currency: string;
  quantity_ordered: number; quantity_received: number; outstanding: number;
  payment_terms: string | null; expected_delivery_date: string | null;
  issued_at: string | null; accepted_at: string | null; acceptance_note: string | null;
  material_order: string | null; receipts: Receipt[];
}
interface PoData { orders: PoRow[]; has_open_order: boolean }
interface InvoiceRow {
  id: number; reference: string | null; invoice_number: string | null; vendor: string | null;
  invoice_date: string | null; due_date: string | null;
  amount: number; tax_amount: number; total_amount: number; currency: string;
  match: { po: boolean; grn: boolean; price: boolean; status: string; reason: string | null;
           overridden_by: string | null; override_justification: string | null };
  status: string; paid_amount: number; balance: number; paid_at: string | null; payment_reference: string | null;
}
interface InvoiceData {
  invoices: InvoiceRow[]; lifecycle_stage: string | null;
  closed: boolean; closed_at: string | null; outstanding: number; paid_total: number;
}
interface RfqData {
  rfq: { id: number; reference: string | null; status: string; issue_date: string | null; due_date: string | null;
         compliance_met: boolean; quote_count: number; waiver_reason: string | null; waived_by: string | null } | null;
  invitations?: { id: number; vendor: string | null; status: string }[];
  quotations?: Quotation[];
  may_award: boolean; required_quotes: number;
  awarded?: { vendor_id: number; amount: number } | null;
}

interface OrderRow {
  id: number; reference: string | null; project: { id: number; name: string } | null;
  category: string; description: string; supplier: string | null; order_value: number; margin: number; currency: string;
  status: string; expected_delivery_date: string | null;
  fulfilled_by: { kind: string; reference: string | null; status: string | null } | null;
}
interface OrdersDashboard {
  data: OrderRow[];
  summary: { count: number; projects: number; active: number; delivered: number; unrouted: number; total_value: number; total_margin: number; by_status: Record<string, number>; by_category: Record<string, number> };
  options: { statuses: string[]; categories: string[]; projects: { id: number; name: string }[] };
}

type ModuleTab = "requests" | "orders" | "analytics";
const moduleTab = ref<ModuleTab>("requests");
const rows = ref<Row[]>([]);
const summary = ref<Summary>({ count: 0, pending_approval: 0, long_lead: 0, estimated_total: 0 });
const loading = ref(true);
const projects = ref<{ id: number; name: string }[]>([]);

const ctxFilter = ref("");
const apprFilter = ref("");
const search = ref("");

const org = () => currentOrgSlug.value;
const titleCase = (v: string) => v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
const money = (n: number) => n.toLocaleString(undefined, { maximumFractionDigits: 0 });
const date = (v: string | null) => v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

function fail(e: unknown, m: string) { notifyFailed(m, e instanceof ApiError ? (e.firstError ?? e.message) : undefined); }

async function load() {
  loading.value = true;
  try {
    const res = await api<{ data: Row[]; summary: Summary }>("GET", "/api/v1/procurement", {
      org: org(), params: {
        context: ctxFilter.value || undefined, approval_status: apprFilter.value || undefined, q: search.value || undefined,
      },
    });
    rows.value = res.data; summary.value = res.summary;
  } catch (e) { fail(e, "Could not load procurement."); }
  finally { loading.value = false; }
}
async function loadProjects() {
  try { projects.value = (await api<{ data: { id: number; name: string }[] }>("GET", "/api/v1/projects", { org: org(), params: { per_page: 200 } })).data; }
  catch { /* the picker just stays empty */ }
}

// ── New request ──────────────────────────────────────────────────────────────
const showNew = ref(false);
const blank = () => ({ context: "project", project_id: null as number | null, item: "", category: "", quantity: 1 as number | string, unit: "", vendor: "", required_by: "", lead_time_days: "" as number | string, estimated_cost: "", notes: "", business_justification: "", priority: "normal" });
const form = ref(blank());

async function create() {
  if (!form.value.item.trim()) return;
  const f = form.value;
  try {
    await api("POST", "/api/v1/procurement", {
      org: org(), body: {
        context: f.context, project_id: f.project_id, item: f.item, category: f.category || null,
        quantity: Number(f.quantity) || 1, unit: f.unit || null, vendor: f.vendor || null,
        required_by: f.required_by || null, lead_time_days: f.lead_time_days === "" ? null : Number(f.lead_time_days),
        estimated_cost: f.estimated_cost === "" ? null : Number(f.estimated_cost), notes: f.notes || null,
        business_justification: f.business_justification || null, priority: f.priority,
      },
    });
    showNew.value = false; form.value = blank();
    notifySaved("Procurement item added.");
    await load();
  } catch (e) { fail(e, "Could not add the item."); }
}
async function submitForApproval(r: Row) {
  try {
    await api("POST", `/api/v1/procurement/${r.id}/submit`, { org: org() });
    notifySaved("Submitted for approval.");
    await load();
  } catch (e) { fail(e, "Could not submit for approval."); }
}
async function decideStep(r: Row, decision: "approve" | "reject") {
  const comment = decision === "reject" ? window.prompt("Reason for rejection (optional)") : null;
  if (decision === "reject" && comment === null) return;
  const body: Record<string, unknown> = { decision, comment };
  // Over budget: finance may still pass it, but must say why.
  if (decision === "approve" && r.budget?.result === "exceeded") {
    const why = window.prompt("This purchase is over budget. Justification to approve anyway:");
    if (!why) return;
    body.budget_override_justification = why;
  }
  try { await api("POST", `/api/v1/procurement/${r.id}/decision`, { org: org(), body }); await load(); }
  catch (e) { fail(e, "Could not record the decision."); }
}
const stageLabel = (s: string) => s.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
function chainSummary(r: Row): string {
  if (!r.approvals?.length) return "";
  const done = r.approvals.filter((a) => a.status === "approved").length;
  return `${done}/${r.approvals.length}`;
}
function pendingStage(r: Row): ApprovalStep | null {
  return r.approvals?.find((a) => a.status === "pending") ?? null;
}
async function setStatus(r: Row, status: string) {
  try { await api("PUT", `/api/v1/procurement/${r.id}`, { org: org(), body: { status } }); await load(); }
  catch (e) { fail(e, "Could not update the status."); }
}
async function remove(r: Row) {
  if (!confirm(`Remove "${r.item}" from the register?`)) return;
  try { await api("DELETE", `/api/v1/procurement/${r.id}`, { org: org() }); await load(); }
  catch (e) { fail(e, "Could not remove the item."); }
}

const apprTone = (s: string) => s === "approved" ? "ok" : s === "rejected" ? "bad" : "warn";

// ── Sourcing (RFQ, quotations, award) ────────────────────────────────────────
const sourcingFor = ref<Row | null>(null);
const rfq = ref<RfqData | null>(null);
const vendors = ref<{ id: number; name: string }[]>([]);
const newQuote = ref({ vendor_partner_id: "" as number | string, amount: "" as number | string, lead_time_days: "" as number | string, warranty_terms: "", payment_terms: "", technical_score: "" as number | string, vendor_score: "" as number | string });

async function openSourcing(r: Row) {
  sourcingFor.value = r; rfq.value = null; po.value = null; inv.value = null;
  void loadPo(r.id);
  void loadInvoices(r.id);
  try {
    rfq.value = (await api<{ data: RfqData }>("GET", `/api/v1/procurement/${r.id}/rfq`, { org: org() })).data;
    if (!vendors.value.length) {
      vendors.value = (await api<{ data: { id: number; name: string }[] }>("GET", "/api/v1/vendors", { org: org(), params: { per_page: 200 } })).data;
    }
  } catch (e) { fail(e, "Could not load the sourcing round."); }
}
async function startRfq() {
  if (!sourcingFor.value) return;
  try {
    rfq.value = (await api<{ data: RfqData }>("POST", `/api/v1/procurement/${sourcingFor.value.id}/rfq`, { org: org() })).data;
    notifySaved("Sourcing round opened.");
    await load();
  } catch (e) { fail(e, "Could not open the sourcing round."); }
}
async function addQuote() {
  if (!sourcingFor.value || !newQuote.value.vendor_partner_id || newQuote.value.amount === "") return;
  const q = newQuote.value;
  try {
    rfq.value = (await api<{ data: RfqData }>("POST", `/api/v1/procurement/${sourcingFor.value.id}/rfq/quotations`, {
      org: org(), body: {
        vendor_partner_id: Number(q.vendor_partner_id), amount: Number(q.amount),
        lead_time_days: q.lead_time_days === "" ? null : Number(q.lead_time_days),
        warranty_terms: q.warranty_terms || null, payment_terms: q.payment_terms || null,
        technical_score: q.technical_score === "" ? null : Number(q.technical_score),
        vendor_score: q.vendor_score === "" ? null : Number(q.vendor_score),
      },
    })).data;
    newQuote.value = { vendor_partner_id: "", amount: "", lead_time_days: "", warranty_terms: "", payment_terms: "", technical_score: "", vendor_score: "" };
  } catch (e) { fail(e, "Could not record the quotation."); }
}
async function waiveQuotes() {
  if (!sourcingFor.value) return;
  const reason = window.prompt("Why can this be awarded without three quotations?");
  if (!reason) return;
  try {
    rfq.value = (await api<{ data: RfqData }>("POST", `/api/v1/procurement/${sourcingFor.value.id}/rfq/waive`, { org: org(), body: { reason } })).data;
  } catch (e) { fail(e, "Could not record the waiver."); }
}
// ── Purchase order and receiving ─────────────────────────────────────────────
const po = ref<PoData | null>(null);
const newPo = ref({ amount: "" as number | string, tax_rate: 16 as number | string, quantity_ordered: "" as number | string, payment_terms: "", expected_delivery_date: "" });
const newReceipt = ref<Record<number, { quantity_received: number | string; quality_status: string; quality_notes: string }>>({});

async function loadPo(prId: number) {
  try { po.value = (await api<{ data: PoData }>("GET", `/api/v1/procurement/${prId}/orders`, { org: org() })).data; }
  catch (e) { fail(e, "Could not load the purchase order."); }
}
async function issuePo() {
  if (!sourcingFor.value) return;
  const f = newPo.value;
  try {
    po.value = (await api<{ data: PoData }>("POST", `/api/v1/procurement/${sourcingFor.value.id}/orders`, {
      org: org(), body: {
        amount: f.amount === "" ? null : Number(f.amount),
        tax_rate: f.tax_rate === "" ? 0 : Number(f.tax_rate),
        quantity_ordered: f.quantity_ordered === "" ? null : Number(f.quantity_ordered),
        payment_terms: f.payment_terms || null,
        expected_delivery_date: f.expected_delivery_date || null,
      },
    })).data;
    notifySaved("Purchase order issued.");
    await load();
  } catch (e) { fail(e, "Could not issue the purchase order."); }
}
async function respondPo(order: PoRow, accepted: boolean) {
  if (!sourcingFor.value) return;
  const note = accepted ? null : window.prompt("Why did the supplier decline?");
  if (!accepted && note === null) return;
  try {
    po.value = (await api<{ data: PoData }>("POST", `/api/v1/procurement/${sourcingFor.value.id}/orders/${order.id}/response`, {
      org: org(), body: { accepted, note },
    })).data;
    await load();
  } catch (e) { fail(e, "Could not record the supplier's answer."); }
}
async function receiveGoods(order: PoRow) {
  if (!sourcingFor.value) return;
  const r = newReceipt.value[order.id];
  if (!r || r.quantity_received === "" || Number(r.quantity_received) <= 0) return;
  try {
    po.value = (await api<{ data: PoData }>("POST", `/api/v1/procurement/${sourcingFor.value.id}/orders/${order.id}/receipts`, {
      org: org(), body: {
        quantity_received: Number(r.quantity_received),
        quality_status: r.quality_status || "accepted",
        quality_notes: r.quality_notes || null,
      },
    })).data;
    newReceipt.value[order.id] = { quantity_received: "", quality_status: "accepted", quality_notes: "" };
    notifySaved("Delivery recorded.");
    await load();
  } catch (e) { fail(e, "Could not record the delivery."); }
}
function receiptDraft(orderId: number) {
  if (!newReceipt.value[orderId]) newReceipt.value[orderId] = { quantity_received: "", quality_status: "accepted", quality_notes: "" };
  return newReceipt.value[orderId];
}

// ── Supplier invoices, the three-way match, and payment ──────────────────────
const inv = ref<InvoiceData | null>(null);
const newInvoice = ref<Record<number, { invoice_number: string; amount: number | string; tax_amount: number | string }>>({});

function invoiceDraft(orderId: number) {
  if (!newInvoice.value[orderId]) newInvoice.value[orderId] = { invoice_number: "", amount: "", tax_amount: "" };
  return newInvoice.value[orderId];
}
async function loadInvoices(prId: number) {
  try { inv.value = (await api<{ data: InvoiceData }>("GET", `/api/v1/procurement/${prId}/invoices`, { org: org() })).data; }
  catch (e) { fail(e, "Could not load the invoices."); }
}
async function addInvoice(order: PoRow) {
  if (!sourcingFor.value) return;
  const d = invoiceDraft(order.id);
  if (d.amount === "") return;
  try {
    inv.value = (await api<{ data: InvoiceData }>("POST", `/api/v1/procurement/${sourcingFor.value.id}/orders/${order.id}/invoices`, {
      org: org(), body: {
        invoice_number: d.invoice_number || null,
        amount: Number(d.amount),
        tax_amount: d.tax_amount === "" ? 0 : Number(d.tax_amount),
      },
    })).data;
    newInvoice.value[order.id] = { invoice_number: "", amount: "", tax_amount: "" };
    notifySaved("Invoice recorded and matched.");
    await load();
  } catch (e) { fail(e, "Could not record the invoice."); }
}
async function overrideInvoice(i: InvoiceRow) {
  if (!sourcingFor.value) return;
  const why = window.prompt(`This invoice failed the match:\n\n${i.match.reason ?? ""}\n\nWhy should it be paid anyway?`);
  if (!why) return;
  try {
    inv.value = (await api<{ data: InvoiceData }>("POST", `/api/v1/procurement/${sourcingFor.value.id}/invoices/${i.id}/override`, {
      org: org(), body: { justification: why },
    })).data;
    notifySaved("Exception overridden.");
  } catch (e) { fail(e, "Could not override the exception."); }
}
async function payInvoice(i: InvoiceRow) {
  if (!sourcingFor.value) return;
  const ref = window.prompt(`Payment reference for ${i.reference} (${i.currency} ${money(i.total_amount)})?`);
  if (ref === null) return;
  try {
    inv.value = (await api<{ data: InvoiceData }>("POST", `/api/v1/procurement/${sourcingFor.value.id}/invoices/${i.id}/payment`, {
      org: org(), body: { payment_reference: ref || null },
    })).data;
    notifySaved("Payment recorded — the spend is now on the project.");
    await load();
  } catch (e) { fail(e, "Could not record the payment."); }
}
async function closePurchase() {
  if (!sourcingFor.value) return;
  if (!confirm("Close this purchase? A closed purchase is locked and cannot be changed again.")) return;
  try {
    inv.value = (await api<{ data: InvoiceData }>("POST", `/api/v1/procurement/${sourcingFor.value.id}/close`, { org: org() })).data;
    notifySaved("Purchase closed.");
    await load();
  } catch (e) { fail(e, "Could not close the purchase."); }
}

const matchTone = (s: string) => s === "matched" ? "ok" : "bad";
const invTone = (s: string) => s === "paid" ? "ok" : s === "exception" ? "bad" : "warn";

async function awardQuote(q: Quotation) {
  if (!sourcingFor.value) return;
  const body: Record<string, unknown> = { quotation_id: q.id };
  if (!q.recommended) {
    const why = window.prompt("This is not the highest-scoring quotation — why is it being chosen?");
    if (!why) return;
    body.justification = why;
  }
  try {
    rfq.value = (await api<{ data: RfqData }>("POST", `/api/v1/procurement/${sourcingFor.value.id}/rfq/award`, { org: org(), body })).data;
    notifySaved(`Awarded to ${q.vendor}.`);
    await loadPo(sourcingFor.value.id);
    await load();
  } catch (e) { fail(e, "Could not award the purchase."); }
}

// ── Cross-project order dashboard ─────────────────────────────────────────────
const ordersDash = ref<OrdersDashboard | null>(null);
const ordersLoading = ref(false);
const ordStatus = ref("");
const ordCategory = ref("");
const ordProject = ref("");
const ordSearch = ref("");
async function loadOrdersDash() {
  ordersLoading.value = true;
  try {
    ordersDash.value = await api<OrdersDashboard>("GET", "/api/v1/procurement/orders", {
      org: org(), params: {
        status: ordStatus.value || undefined, category: ordCategory.value || undefined,
        project_id: ordProject.value || undefined, q: ordSearch.value || undefined,
      },
    });
  } catch (e) { fail(e, "Could not load the orders dashboard."); }
  finally { ordersLoading.value = false; }
}
function switchTab(t: ModuleTab) {
  moduleTab.value = t;
  if (t === "orders" && !ordersDash.value) void loadOrdersDash();
  if (t === "analytics" && !dash.value) void loadDash();
}

// ── Analytics: spend, savings, cycle times, supplier ranking ─────────────────
interface Dashboard {
  spend: { requested: number; ordered: number; invoiced: number; paid: number; committed: number; outstanding: number };
  savings: { awarded_count: number; estimated: number; awarded: number; saved: number; saved_percent: number | null };
  cycle: { submit_to_approve: number | null; approve_to_order: number | null; order_to_delivery: number | null; invoice_to_payment: number | null; end_to_end: number | null };
  pipeline: { by_stage: Record<string, number>; awaiting_approval: number; open: number; closed: number };
  compliance: { sourcing_rounds: number; three_quote_met: number; waived: number; budget_overrides: number; invoice_exceptions: number; exceptions_overridden: number };
  vendors: { vendor_id: number; vendor: string; orders: number; spend: number; currency: string; on_time_rate: number | null; quality_rate: number | null; avg_lead_days: number | null; win_rate: number | null; overall: number | null; rating: string | null }[];
  by_category: { category: string; count: number; estimated: number; committed: number }[];
}

const dash = ref<Dashboard | null>(null);
const dashLoading = ref(false);
const dashProject = ref("");
const dashFrom = ref("");

async function loadDash() {
  dashLoading.value = true;
  try {
    dash.value = (await api<{ data: Dashboard }>("GET", "/api/v1/procurement/dashboard", {
      org: org(), params: { project_id: dashProject.value || undefined, from: dashFrom.value || undefined },
    })).data;
  } catch (e) { fail(e, "Could not load the procurement dashboard."); }
  finally { dashLoading.value = false; }
}

const num = (v: number | null, suffix = "") => v === null ? "—" : `${v}${suffix}`;
const ratingTone = (r: string | null) =>
  r === "excellent" || r === "good" ? "ok" : r === "fair" ? "warn" : r === "poor" ? "bad" : "";

// ── Lifecycle timeline ───────────────────────────────────────────────────────
interface TimelineEvent { at: string; kind: string; label: string; actor: string | null }
const timeline = ref<{ reference: string | null; stage: string | null; events: TimelineEvent[] } | null>(null);
const showTimeline = ref(false);

async function loadTimeline(prId: number) {
  timeline.value = null;
  try {
    timeline.value = (await api<{ data: typeof timeline.value }>("GET", `/api/v1/procurement/${prId}/timeline`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load the history."); }
}

const stamp = (v: string | null) => v
  ? new Date(v.replace(" ", "T")).toLocaleString(undefined, { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" })
  : "—";

const eventTone = (k: string) =>
  ["rejected", "po_declined"].includes(k) ? "bad"
  : ["approved", "paid", "closed", "received", "po_accepted", "awarded"].includes(k) ? "ok"
  : ["override", "waiver", "budget_checked"].includes(k) ? "warn" : "";

onMounted(() => { void load(); void loadProjects(); });
</script>

<template>
  <div class="pr-wrap">
    <header class="pr-head">
      <div>
        <h1 class="pr-title">Procurement</h1>
        <p class="pr-sub">The register of what needs buying across projects. Asset and consumable procurement join it next.</p>
      </div>
      <button v-if="moduleTab === 'requests'" class="btn-primary" @click="form = blank(); showNew = true">+ New request</button>
    </header>

    <nav class="pr-tabs">
      <button :class="['pr-tab', { active: moduleTab === 'requests' }]" @click="switchTab('requests')">Requests</button>
      <button :class="['pr-tab', { active: moduleTab === 'orders' }]" @click="switchTab('orders')">Orders</button>
      <button :class="['pr-tab', { active: moduleTab === 'analytics' }]" @click="switchTab('analytics')">Analytics</button>
    </nav>

    <template v-if="moduleTab === 'requests'">
    <div class="pr-kpis">
      <div class="pr-kpi"><span class="pr-kpi-n">{{ summary.count }}</span><span class="pr-kpi-l">items</span></div>
      <div class="pr-kpi warn"><span class="pr-kpi-n">{{ summary.pending_approval }}</span><span class="pr-kpi-l">pending approval</span></div>
      <div class="pr-kpi bad"><span class="pr-kpi-n">{{ summary.long_lead }}</span><span class="pr-kpi-l">long-lead</span></div>
      <div class="pr-kpi"><span class="pr-kpi-n">{{ money(summary.estimated_total) }}</span><span class="pr-kpi-l">estimated total</span></div>
    </div>

    <div class="pr-filters">
      <input v-model="search" class="control pr-search" type="search" placeholder="Search item…" @keyup.enter="load" />
      <select v-model="ctxFilter" class="control" @change="load"><option value="">All contexts</option><option value="project">Project</option><option value="asset">Asset</option><option value="consumable">Consumable</option></select>
      <select v-model="apprFilter" class="control" @change="load"><option value="">All approvals</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select>
    </div>

    <p v-if="loading" class="pr-empty">Loading…</p>
    <p v-else-if="!rows.length" class="pr-empty">No procurement requests yet.</p>
    <div v-else class="pr-card">
      <table class="pr-table">
        <thead><tr>
          <th>Ref</th><th>Item</th><th>For</th><th>Cat.</th><th>Qty</th><th>Vendor</th><th>Required by</th><th>Lead</th><th>Est. cost</th><th>Budget</th><th>Approval</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id">
            <td class="pr-mono">{{ r.reference }}</td>
            <td class="pr-item">{{ r.item }}</td>
            <td>
              <span v-if="r.project" class="pr-for">{{ r.project.name }}</span>
              <span v-else class="pr-muted">{{ titleCase(r.context) }}</span>
            </td>
            <td>{{ r.category ? titleCase(r.category) : "—" }}</td>
            <td>{{ r.quantity }}</td>
            <td>{{ r.vendor ?? "—" }}</td>
            <td class="pr-nowrap">{{ date(r.required_by) }}</td>
            <td><span v-if="r.is_long_lead" class="pr-badge bad">{{ r.lead_time_days }}d</span><span v-else>{{ r.lead_time_days != null ? r.lead_time_days + "d" : "—" }}</span></td>
            <td class="pr-nowrap">{{ r.estimated_cost != null ? r.currency + " " + money(Number(r.estimated_cost)) : "—" }}</td>
            <td class="pr-nowrap">
              <span v-if="r.budget?.result === 'ok'" class="pr-badge ok" title="Within budget">OK</span>
              <span v-else-if="r.budget?.result === 'exceeded'" class="pr-badge bad" title="Over available budget">Exceeded</span>
              <span v-else-if="r.budget?.result === 'overridden'" class="pr-badge warn" title="Approved over budget by finance">Override</span>
              <span v-else class="pr-muted">—</span>
            </td>
            <td class="pr-chain">
              <template v-if="r.approvals?.length">
                <span :class="['pr-badge', apprTone(r.approval_status)]">{{ chainSummary(r) }}</span>
                <span v-if="pendingStage(r)" class="pr-chain-at">at {{ stageLabel(pendingStage(r)!.stage) }}<span v-if="pendingStage(r)!.escalated" class="pr-esc" title="Escalated on margin">&#8593;</span></span>
                <span v-else class="pr-chain-at">{{ titleCase(r.approval_status) }}</span>
                <div v-if="pendingStage(r)" class="pr-chain-actions">
                  <button class="pr-mini ok" @click="decideStep(r, 'approve')">Approve</button>
                  <button class="pr-mini bad" @click="decideStep(r, 'reject')">Reject</button>
                </div>
              </template>
              <template v-else>
                <button v-if="r.approval_status === 'pending'" class="pr-mini" @click="submitForApproval(r)">Submit</button>
                <span v-else :class="['pr-badge', apprTone(r.approval_status)]">{{ titleCase(r.approval_status) }}</span>
              </template>
            </td>
            <td>
              <select :value="r.status" class="control pr-inline" @change="setStatus(r, ($event.target as HTMLSelectElement).value)">
                <option value="requested">Requested</option><option value="ordered">Ordered</option><option value="received">Received</option><option value="cancelled">Cancelled</option>
              </select>
            </td>
            <td class="pr-nowrap">
              <button v-if="r.approval_status === 'approved'" class="pr-mini" title="Quotations and award" @click="openSourcing(r)">Source</button>
              <button class="pr-mini" title="Everything that happened to this purchase" @click="showTimeline = true; loadTimeline(r.id)">History</button>
              <button class="pr-x" @click="remove(r)">✕</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    </template>

    <!-- ── Cross-project order dashboard ─────────────────────────────── -->
    <template v-else>
      <div v-if="ordersDash" class="pr-kpis">
        <div class="pr-kpi"><span class="pr-kpi-n">{{ ordersDash.summary.count }}</span><span class="pr-kpi-l">orders</span></div>
        <div class="pr-kpi"><span class="pr-kpi-n">{{ ordersDash.summary.projects }}</span><span class="pr-kpi-l">projects</span></div>
        <div class="pr-kpi"><span class="pr-kpi-n">{{ ordersDash.summary.active }}</span><span class="pr-kpi-l">active</span></div>
        <div class="pr-kpi"><span class="pr-kpi-n">{{ ordersDash.summary.delivered }}</span><span class="pr-kpi-l">delivered+</span></div>
        <div class="pr-kpi" :class="{ warn: ordersDash.summary.unrouted }"><span class="pr-kpi-n">{{ ordersDash.summary.unrouted }}</span><span class="pr-kpi-l">not routed</span></div>
        <div class="pr-kpi"><span class="pr-kpi-n">{{ money(ordersDash.summary.total_value) }}</span><span class="pr-kpi-l">order value</span></div>
        <div class="pr-kpi"><span class="pr-kpi-n">{{ money(ordersDash.summary.total_margin) }}</span><span class="pr-kpi-l">margin</span></div>
      </div>

      <div class="pr-filters">
        <input v-model="ordSearch" class="control pr-search" type="search" placeholder="Search order…" @keyup.enter="loadOrdersDash" />
        <select v-model="ordStatus" class="control" @change="loadOrdersDash">
          <option value="">All statuses</option>
          <option v-for="s in (ordersDash?.options.statuses ?? [])" :key="s" :value="s">{{ titleCase(s) }}</option>
        </select>
        <select v-model="ordCategory" class="control" @change="loadOrdersDash">
          <option value="">All categories</option>
          <option v-for="c in (ordersDash?.options.categories ?? [])" :key="c" :value="c">{{ titleCase(c) }}</option>
        </select>
        <select v-model="ordProject" class="control" @change="loadOrdersDash">
          <option value="">All projects</option>
          <option v-for="p in (ordersDash?.options.projects ?? [])" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
        </select>
      </div>

      <p v-if="ordersLoading" class="pr-empty">Loading…</p>
      <p v-else-if="!ordersDash || !ordersDash.data.length" class="pr-empty">No orders generated from any project's BOM yet.</p>
      <div v-else class="pr-card">
        <table class="pr-table">
          <thead><tr><th>Ref</th><th>Project</th><th>Category</th><th>Description</th><th>Value</th><th>Margin</th><th>Fulfilled by</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="o in ordersDash.data" :key="o.id">
              <td class="pr-mono">{{ o.reference }}</td>
              <td>{{ o.project?.name ?? "—" }}</td>
              <td>{{ titleCase(o.category) }}</td>
              <td class="pr-item">{{ o.description }}</td>
              <td class="pr-nowrap">{{ o.currency }} {{ money(o.order_value) }}</td>
              <td class="pr-nowrap">{{ o.currency }} {{ money(o.margin) }}</td>
              <td>
                <span v-if="o.fulfilled_by" class="pr-badge">{{ o.fulfilled_by.reference || o.fulfilled_by.kind }}<template v-if="o.fulfilled_by.status"> · {{ titleCase(o.fulfilled_by.status) }}</template></span>
                <span v-else class="pr-muted">not routed</span>
              </td>
              <td><span :class="['pr-badge', o.status === 'closed' || o.status === 'accepted' ? 'ok' : o.status === 'cancelled' ? 'bad' : '']">{{ titleCase(o.status) }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Analytics: where the money went, how long it took, and to whom -->
    <template v-if="moduleTab === 'analytics'">
      <div class="pr-filters">
        <select v-model="dashProject" class="control" @change="loadDash">
          <option value="">All projects</option>
          <option v-for="p in projects" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
        </select>
        <input v-model="dashFrom" class="control" type="date" title="Raised on or after" @change="loadDash" />
      </div>

      <p v-if="dashLoading" class="pr-empty">Loading…</p>
      <template v-else-if="dash">
        <div class="pr-kpis">
          <div class="pr-kpi"><span class="pr-kpi-n">{{ money(dash.spend.requested) }}</span><span class="pr-kpi-l">requested</span></div>
          <div class="pr-kpi"><span class="pr-kpi-n">{{ money(dash.spend.ordered) }}</span><span class="pr-kpi-l">ordered</span></div>
          <div class="pr-kpi"><span class="pr-kpi-n">{{ money(dash.spend.committed) }}</span><span class="pr-kpi-l">committed</span></div>
          <div class="pr-kpi"><span class="pr-kpi-n">{{ money(dash.spend.paid) }}</span><span class="pr-kpi-l">paid</span></div>
          <div class="pr-kpi" :class="{ warn: dash.spend.outstanding > 0 }"><span class="pr-kpi-n">{{ money(dash.spend.outstanding) }}</span><span class="pr-kpi-l">unpaid bills</span></div>
          <div class="pr-kpi"><span class="pr-kpi-n">{{ money(dash.savings.saved) }}</span><span class="pr-kpi-l">saved on award</span></div>
        </div>

        <div class="pr-analytics">
          <div class="pr-card pr-panel">
            <h4 class="pr-sec">How long it takes</h4>
            <div class="pr-cycle">
              <div class="pr-cyc"><span class="pr-cyc-n">{{ num(dash.cycle.submit_to_approve, "d") }}</span><span class="pr-cyc-l">submit → approve</span></div>
              <div class="pr-cyc"><span class="pr-cyc-n">{{ num(dash.cycle.approve_to_order, "d") }}</span><span class="pr-cyc-l">approve → order</span></div>
              <div class="pr-cyc"><span class="pr-cyc-n">{{ num(dash.cycle.order_to_delivery, "d") }}</span><span class="pr-cyc-l">order → delivery</span></div>
              <div class="pr-cyc"><span class="pr-cyc-n">{{ num(dash.cycle.invoice_to_payment, "d") }}</span><span class="pr-cyc-l">invoice → payment</span></div>
              <div class="pr-cyc"><span class="pr-cyc-n">{{ num(dash.cycle.end_to_end, "d") }}</span><span class="pr-cyc-l">end to end</span></div>
            </div>
          </div>

          <div class="pr-card pr-panel">
            <h4 class="pr-sec">Governance</h4>
            <div class="pr-cycle">
              <div class="pr-cyc"><span class="pr-cyc-n">{{ dash.compliance.three_quote_met }}/{{ dash.compliance.sourcing_rounds }}</span><span class="pr-cyc-l">rounds with 3 quotes</span></div>
              <div class="pr-cyc"><span class="pr-cyc-n">{{ dash.compliance.waived }}</span><span class="pr-cyc-l">quote rule waived</span></div>
              <div class="pr-cyc"><span class="pr-cyc-n">{{ dash.compliance.budget_overrides }}</span><span class="pr-cyc-l">budget overrides</span></div>
              <div class="pr-cyc"><span class="pr-cyc-n">{{ dash.compliance.invoice_exceptions }}</span><span class="pr-cyc-l">match exceptions</span></div>
              <div class="pr-cyc"><span class="pr-cyc-n">{{ dash.compliance.exceptions_overridden }}</span><span class="pr-cyc-l">paid on override</span></div>
            </div>
          </div>
        </div>

        <div class="pr-card" style="margin-top:var(--space-4)">
          <h4 class="pr-sec">Suppliers</h4>
          <p v-if="!dash.vendors.length" class="pr-empty">No suppliers on record.</p>
          <table v-else class="pr-table">
            <thead><tr><th>Supplier</th><th>Score</th><th>Orders</th><th>Paid</th><th>On time</th><th>Quality</th><th>Lead</th><th>Win rate</th></tr></thead>
            <tbody>
              <tr v-for="v in dash.vendors" :key="v.vendor_id">
                <td>{{ v.vendor }}</td>
                <td>
                  <span v-if="v.overall !== null" :class="['pr-badge', ratingTone(v.rating)]">{{ v.overall }} · {{ v.rating }}</span>
                  <span v-else class="pr-muted">no history</span>
                </td>
                <td>{{ v.orders }}</td>
                <td class="pr-nowrap">{{ v.currency }} {{ money(v.spend) }}</td>
                <td>{{ num(v.on_time_rate, "%") }}</td>
                <td>{{ num(v.quality_rate, "%") }}</td>
                <td>{{ num(v.avg_lead_days, "d") }}</td>
                <td>{{ num(v.win_rate, "%") }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="dash.by_category.length" class="pr-card" style="margin-top:var(--space-4)">
          <h4 class="pr-sec">By category</h4>
          <table class="pr-table">
            <thead><tr><th>Category</th><th>Requests</th><th>Estimated</th><th>Committed</th></tr></thead>
            <tbody>
              <tr v-for="c in dash.by_category" :key="c.category">
                <td>{{ titleCase(c.category) }}</td>
                <td>{{ c.count }}</td>
                <td class="pr-nowrap">{{ money(c.estimated) }}</td>
                <td class="pr-nowrap">{{ money(c.committed) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
      <p v-else class="pr-empty">Nothing to report yet.</p>
    </template>

    <!-- Sourcing: quotations and award -->
    <div v-if="sourcingFor" class="pr-modal-backdrop" @click.self="sourcingFor = null">
      <div class="pr-modal pr-modal-wide">
        <div class="pr-modal-head">
          <h3>Sourcing — {{ sourcingFor.item }}</h3>
          <button class="pr-close" @click="sourcingFor = null">×</button>
        </div>

        <template v-if="rfq && rfq.rfq">
          <div class="pr-src-head">
            <span class="pr-mono">{{ rfq.rfq.reference }}</span>
            <span :class="['pr-badge', rfq.rfq.compliance_met ? 'ok' : 'warn']">
              {{ rfq.rfq.quote_count }}/{{ rfq.required_quotes }} quotes{{ rfq.rfq.compliance_met ? ' ✓' : '' }}
            </span>
            <span v-if="rfq.rfq.waiver_reason" class="pr-badge warn" :title="rfq.rfq.waiver_reason">Waived</span>
            <span class="pr-spacer"></span>
            <button v-if="!rfq.may_award" class="pr-mini" @click="waiveQuotes">Waive 3-quote rule</button>
          </div>

          <table v-if="rfq.quotations?.length" class="pr-table pr-quotes">
            <thead><tr><th>Vendor</th><th>Amount</th><th>Lead</th><th>Price</th><th>Delivery</th><th>Score</th><th></th></tr></thead>
            <tbody>
              <tr v-for="q in rfq.quotations" :key="q.id" :class="{ 'pr-awarded': q.is_awarded }">
                <td>{{ q.vendor }}<span v-if="q.recommended && !q.is_awarded" class="pr-rec" title="Highest score">★</span></td>
                <td class="pr-nowrap">{{ q.currency }} {{ money(q.amount) }}</td>
                <td>{{ q.lead_time_days != null ? q.lead_time_days + 'd' : '—' }}</td>
                <td>{{ q.price_score ?? '—' }}</td>
                <td>{{ q.delivery_score ?? '—' }}</td>
                <td><strong>{{ q.total_score ?? '—' }}</strong></td>
                <td>
                  <span v-if="q.is_awarded" class="pr-badge ok">Awarded</span>
                  <button v-else-if="rfq.may_award" class="pr-mini ok" @click="awardQuote(q)">Award</button>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-else class="pr-empty">No quotations recorded yet.</p>

          <div v-if="!rfq.awarded" class="pr-quote-add">
            <select v-model="newQuote.vendor_partner_id" class="control">
              <option value="">— Vendor —</option>
              <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.name }}</option>
            </select>
            <input v-model="newQuote.amount" class="control" type="number" min="0" placeholder="Amount" />
            <input v-model="newQuote.lead_time_days" class="control" type="number" min="0" placeholder="Lead days" />
            <input v-model="newQuote.technical_score" class="control" type="number" min="0" max="100" placeholder="Tech /100" />
            <input v-model="newQuote.vendor_score" class="control" type="number" min="0" max="100" placeholder="Vendor /100" />
            <button class="btn-primary btn-sm" :disabled="!newQuote.vendor_partner_id || newQuote.amount === ''" @click="addQuote">Add quote</button>
          </div>
        </template>

        <!-- Purchase order and receiving -->
        <template v-if="po">
          <h4 class="pr-sec">Purchase order</h4>
          <div v-for="o in po.orders" :key="o.id" class="pr-po">
            <div class="pr-po-head">
              <span class="pr-mono">{{ o.reference }}</span>
              <span :class="['pr-badge', o.status === 'received' ? 'ok' : o.status === 'vendor_declined' || o.status === 'cancelled' ? 'bad' : 'warn']">{{ titleCase(o.status) }}</span>
              <span class="pr-po-sum">{{ o.currency }} {{ money(o.total_amount) }}<span v-if="o.tax_rate" class="pr-muted"> (incl. {{ o.tax_rate }}% tax)</span></span>
              <span v-if="o.material_order" class="pr-badge" :title="'Mirrored to ' + o.material_order">↔ {{ o.material_order }}</span>
              <span class="pr-spacer"></span>
              <template v-if="o.status === 'issued'">
                <button class="pr-mini ok" @click="respondPo(o, true)">Supplier accepted</button>
                <button class="pr-mini bad" @click="respondPo(o, false)">Declined</button>
              </template>
            </div>
            <div class="pr-po-meta">
              <span>{{ o.quantity_received }} / {{ o.quantity_ordered }} received</span>
              <span v-if="o.outstanding > 0" class="pr-muted">· {{ o.outstanding }} outstanding</span>
              <span v-if="o.expected_delivery_date" class="pr-muted">· due {{ date(o.expected_delivery_date) }}</span>
              <span v-if="o.payment_terms" class="pr-muted">· {{ o.payment_terms }}</span>
            </div>

            <table v-if="o.receipts.length" class="pr-table pr-grns">
              <thead><tr><th>GRN</th><th>Received</th><th>Outstanding</th><th>Quality</th><th>On time</th><th>By</th></tr></thead>
              <tbody>
                <tr v-for="g in o.receipts" :key="g.id">
                  <td class="pr-mono">{{ g.reference }}</td>
                  <td>{{ g.quantity_received }}</td>
                  <td>{{ g.variance }}</td>
                  <td>{{ titleCase(g.quality_status) }}</td>
                  <td><span v-if="g.on_time === true" class="pr-badge ok">Yes</span><span v-else-if="g.on_time === false" class="pr-badge bad">Late</span><span v-else class="pr-muted">—</span></td>
                  <td>{{ g.received_by ?? '—' }}</td>
                </tr>
              </tbody>
            </table>

            <div v-if="o.outstanding > 0 && o.status !== 'cancelled' && o.status !== 'vendor_declined'" class="pr-quote-add">
              <input v-model="receiptDraft(o.id).quantity_received" class="control" type="number" min="0" step="0.01" placeholder="Qty received" />
              <select v-model="receiptDraft(o.id).quality_status" class="control">
                <option value="accepted">Accepted</option><option value="partial">Partial</option><option value="rejected">Rejected</option>
              </select>
              <input v-model="receiptDraft(o.id).quality_notes" class="control" placeholder="Quality notes" />
              <button class="btn-primary btn-sm" @click="receiveGoods(o)">Record delivery</button>
            </div>

            <div v-if="inv && !inv.closed && o.status !== 'cancelled' && o.status !== 'vendor_declined'" class="pr-quote-add">
              <input v-model="invoiceDraft(o.id).invoice_number" class="control" placeholder="Supplier invoice no." />
              <input v-model="invoiceDraft(o.id).amount" class="control" type="number" min="0" step="0.01" placeholder="Amount" />
              <input v-model="invoiceDraft(o.id).tax_amount" class="control" type="number" min="0" step="0.01" placeholder="Tax" />
              <button class="btn-primary btn-sm" :disabled="invoiceDraft(o.id).amount === ''" @click="addInvoice(o)">Record invoice</button>
            </div>
          </div>

          <div v-if="!po.has_open_order && sourcingFor.approval_status === 'approved'" class="pr-quote-add">
            <input v-model="newPo.amount" class="control" type="number" min="0" placeholder="Order amount" />
            <input v-model="newPo.tax_rate" class="control" type="number" min="0" max="100" placeholder="Tax %" />
            <input v-model="newPo.quantity_ordered" class="control" type="number" min="0" step="0.01" placeholder="Qty" />
            <input v-model="newPo.payment_terms" class="control" placeholder="Payment terms" />
            <input v-model="newPo.expected_delivery_date" class="control" type="date" />
            <button class="btn-primary btn-sm" @click="issuePo">Issue purchase order</button>
          </div>
        </template>

        <template v-else-if="rfq">
          <p class="pr-empty">This purchase has not gone out to quote yet.</p>
          <button class="btn-primary btn-block" @click="startRfq">Open sourcing round</button>
        </template>
        <p v-else class="pr-empty">Loading…</p>

        <!-- Invoices, the three-way match, and payment. Deliberately outside
             the sourcing chain above: the bills stand on their own once an
             order exists, whatever state the RFQ is in. -->
        <template v-if="inv && inv.invoices.length">
          <h4 class="pr-sec">
            Invoices
            <span v-if="inv.outstanding > 0" class="pr-badge warn">{{ money(inv.outstanding) }} outstanding</span>
            <span v-else class="pr-badge ok">Settled</span>
          </h4>

          <div v-for="i in inv.invoices" :key="i.id" class="pr-inv">
            <div class="pr-po-head">
              <span class="pr-mono">{{ i.reference }}</span>
              <span v-if="i.invoice_number" class="pr-muted">{{ i.invoice_number }}</span>
              <span :class="['pr-badge', invTone(i.status)]">{{ titleCase(i.status) }}</span>
              <span class="pr-po-sum">{{ i.currency }} {{ money(i.total_amount) }}</span>
              <span class="pr-spacer"></span>
              <button v-if="i.status === 'exception' && !inv.closed" class="pr-mini" @click="overrideInvoice(i)">Override</button>
              <button v-if="i.status === 'approved_for_payment' && !inv.closed" class="pr-mini ok" @click="payInvoice(i)">Record payment</button>
            </div>

            <div class="pr-po-meta">
              <span :class="['pr-badge', matchTone(i.match.status)]" :title="i.match.reason ?? 'All three legs agree.'">
                {{ i.match.po ? '✓' : '✗' }} PO · {{ i.match.grn ? '✓' : '✗' }} GRN · {{ i.match.price ? '✓' : '✗' }} Price
              </span>
              <span v-if="i.due_date" class="pr-muted">· due {{ date(i.due_date) }}</span>
              <span v-if="i.paid_at" class="pr-muted">· paid {{ date(i.paid_at) }}<template v-if="i.payment_reference"> · {{ i.payment_reference }}</template></span>
            </div>

            <p v-if="i.match.reason && i.status !== 'paid'" class="pr-inv-why">{{ i.match.reason }}</p>
            <p v-if="i.match.overridden_by" class="pr-inv-why">
              Passed by {{ i.match.overridden_by }}: {{ i.match.override_justification }}
            </p>
          </div>

          <div v-if="inv.closed" class="pr-closed">
            Closed {{ date(inv.closed_at) }} — this purchase is locked.
          </div>
          <button v-else-if="inv.outstanding === 0 && inv.paid_total > 0" class="btn-primary btn-block" @click="closePurchase">
            Close purchase
          </button>
        </template>
      </div>
    </div>

    <!-- Lifecycle history — assembled from what the records already stamp -->
    <div v-if="showTimeline" class="pr-modal-backdrop" @click.self="showTimeline = false">
      <div class="pr-modal">
        <div class="pr-modal-head">
          <h3>History<template v-if="timeline?.reference"> — {{ timeline.reference }}</template></h3>
          <button class="pr-close" @click="showTimeline = false">×</button>
        </div>

        <p v-if="!timeline" class="pr-empty">Loading…</p>
        <p v-else-if="!timeline.events.length" class="pr-empty">Nothing has happened to this purchase yet.</p>
        <ol v-else class="pr-timeline">
          <li v-for="(e, i) in timeline.events" :key="i" :class="['pr-tl', eventTone(e.kind)]">
            <span class="pr-tl-dot"></span>
            <div class="pr-tl-body">
              <span class="pr-tl-label">{{ e.label }}</span>
              <span class="pr-tl-meta">{{ stamp(e.at) }}<template v-if="e.actor"> · {{ e.actor }}</template></span>
            </div>
          </li>
        </ol>
      </div>
    </div>

    <!-- New request -->
    <div v-if="showNew" class="pr-modal-backdrop" @click.self="showNew = false">
      <div class="pr-modal">
        <div class="pr-modal-head"><h3>New procurement request</h3><button class="pr-close" @click="showNew = false">×</button></div>
        <div class="pr-grid2">
          <label class="pr-field"><span>Context</span>
            <select v-model="form.context" class="control"><option value="project">Project</option><option value="asset">Asset</option><option value="consumable">Consumable</option></select></label>
          <label class="pr-field"><span>Project</span>
            <select v-model.number="form.project_id" class="control" :disabled="form.context !== 'project'">
              <option :value="null">— None —</option>
              <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select></label>
        </div>
        <label class="pr-field"><span>Item</span><input v-model="form.item" class="control" placeholder="What needs buying" /></label>
        <div class="pr-grid2">
          <label class="pr-field"><span>Category</span>
            <select v-model="form.category" class="control"><option value="">—</option><option value="hardware">Hardware</option><option value="software">Software</option><option value="licence">Licence</option><option value="subscription">Subscription</option><option value="service">Service</option><option value="equipment">Equipment</option><option value="consumable">Consumable</option><option value="other">Other</option></select></label>
          <label class="pr-field"><span>Quantity</span><input v-model="form.quantity" class="control" type="number" min="1" /></label>
        </div>
        <div class="pr-grid2">
          <label class="pr-field"><span>Vendor</span><input v-model="form.vendor" class="control" placeholder="Supplier" /></label>
          <label class="pr-field"><span>Required by</span><input v-model="form.required_by" class="control" type="date" /></label>
        </div>
        <div class="pr-grid2">
          <label class="pr-field"><span>Lead time (days)</span><input v-model="form.lead_time_days" class="control" type="number" min="0" placeholder="e.g. 45" /></label>
          <label class="pr-field"><span>Estimated cost</span><input v-model="form.estimated_cost" class="control" type="number" min="0" placeholder="0" /></label>
        </div>
        <label class="pr-field"><span>Notes</span><textarea v-model="form.notes" class="control" rows="2"></textarea></label>
        <label class="pr-field"><span>Business justification</span><textarea v-model="form.business_justification" class="control" rows="2" placeholder="Why this purchase is needed"></textarea></label>
        <label class="pr-field"><span>Priority</span>
          <select v-model="form.priority" class="control"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></label>
        <button class="btn-primary btn-block" :disabled="!form.item.trim()" @click="create">Add to register</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";
.pr-wrap { padding: var(--space-6); width: 100%; max-width: 100%; }
@media (max-width: 40rem) { .pr-wrap { padding: var(--space-4); } }
.pr-head { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-4); flex-wrap: wrap; margin-bottom: var(--space-4); }
.pr-title { font-size: var(--text-xl-size); font-weight: 600; margin: 0; color: var(--color-text); }
.pr-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }
.pr-tabs { display: inline-flex; gap: 4px; padding: 4px; margin-bottom: var(--space-4); background: var(--color-surface-sunken); border-radius: 10px; }
.pr-tab { cursor: pointer; border: none; background: transparent; padding: 6px 16px; border-radius: 7px; font: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted); }
.pr-tab:hover { color: var(--color-text); }
.pr-tab.active { background: var(--color-surface); color: var(--color-brand-on-surface); font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
.pr-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 12px; margin-bottom: var(--space-4); }
.pr-kpi { display: flex; flex-direction: column; gap: 2px; padding: 12px 14px; background: var(--color-surface-raised); border: 1px solid var(--color-border); border-radius: 12px; border-left: 3px solid var(--color-border-strong); }
.pr-kpi.warn { border-left-color: var(--color-warning-text); } .pr-kpi.bad { border-left-color: var(--color-danger-text); }
.pr-kpi-n { font-size: var(--text-lg-size); font-weight: 700; font-variant-numeric: tabular-nums; color: var(--color-text); }
.pr-kpi-l { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.pr-modal-wide { max-width: 52rem; }
.pr-sec { font-size: var(--text-sm-size); font-weight: 600; margin: 18px 0 8px; padding-top: 12px; border-top: 1px solid var(--color-border); }
.pr-po { border: 1px solid var(--color-border); border-radius: 10px; padding: 10px 12px; margin-bottom: 10px; }
.pr-po-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.pr-po-sum { font-weight: 600; font-variant-numeric: tabular-nums; }
.pr-po-meta { font-size: var(--text-xs-size); color: var(--color-text-muted); margin-top: 4px; display: flex; gap: 6px; flex-wrap: wrap; }
.pr-grns { margin: 10px 0; font-size: var(--text-xs-size); }
.pr-inv { border: 1px solid var(--color-border); border-radius: 10px; padding: 10px 12px; margin-bottom: 8px; }

/* Analytics */
.pr-analytics { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: var(--space-4); margin-top: var(--space-4); }
.pr-panel { padding: 0 16px 16px; }
.pr-cycle { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; }
.pr-cyc { display: flex; flex-direction: column; gap: 2px; padding: 10px; border: 1px solid var(--color-border); border-radius: 8px; }
.pr-cyc-n { font-size: var(--text-lg-size); font-weight: 600; font-variant-numeric: tabular-nums; }
.pr-cyc-l { font-size: var(--text-xs-size); color: var(--color-text-light); }

/* Timeline */
.pr-timeline { list-style: none; margin: 0; padding: 4px 0 0; }
.pr-tl { position: relative; display: flex; gap: 12px; padding: 0 0 16px 4px; }
/* The rail joins one event to the next, so the last one does not trail off. */
.pr-tl:not(:last-child)::before {
  content: ""; position: absolute; left: 8px; top: 14px; bottom: -2px; width: 1px; background: var(--color-border);
}
.pr-tl-dot { flex: none; width: 9px; height: 9px; margin-top: 5px; border-radius: 50%; background: var(--color-text-light); }
.pr-tl.ok   .pr-tl-dot { background: var(--color-success-text); }
.pr-tl.warn .pr-tl-dot { background: var(--color-warning-text); }
.pr-tl.bad  .pr-tl-dot { background: var(--color-danger-text); }
.pr-tl-body { display: flex; flex-direction: column; gap: 2px; }
.pr-tl-label { font-size: var(--text-sm-size); color: var(--color-text); }
.pr-tl-meta { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pr-inv-why { margin: 6px 0 0; font-size: var(--text-xs-size); color: var(--color-text-light); }
.pr-closed { margin-top: 10px; padding: 8px 12px; border-radius: 8px; font-size: var(--text-xs-size);
  background: var(--color-surface-sunken); color: var(--color-text-light); text-align: center; }
.pr-src-head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.pr-spacer { flex: 1; }
.pr-quotes { margin-bottom: 12px; }
.pr-awarded { background: color-mix(in oklab, var(--color-success-subtle) 45%, transparent); }
.pr-rec { color: var(--color-warning-text); margin-left: 5px; }
.pr-quote-add { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.pr-quote-add .control { width: auto; min-width: 110px; }
.pr-chain { white-space: nowrap; }
.pr-chain-at { font-size: var(--text-xs-size); color: var(--color-text-muted); margin-left: 6px; }
.pr-esc { color: var(--color-warning-text); font-weight: 700; margin-left: 3px; }
.pr-chain-actions { display: flex; gap: 4px; margin-top: 4px; }
.pr-mini { cursor: pointer; height: 24px; padding: 0 8px; font-size: 11px; border: 1px solid var(--color-border); border-radius: 6px; background: var(--color-surface); }
.pr-mini:hover { background: var(--color-surface-sunken); }
.pr-mini.ok { color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 35%, transparent); }
.pr-mini.bad { color: var(--color-danger-text); border-color: color-mix(in oklab, var(--color-danger-text) 35%, transparent); }
.pr-filters { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: var(--space-4); }
.pr-search { max-width: 22rem; }
.pr-filters .control { width: auto; }
.pr-empty { padding: var(--space-6); color: var(--color-text-muted); }
.pr-card { background: var(--color-surface-raised); border: 1px solid var(--color-border); border-radius: var(--radius-lg); overflow-x: auto; }
.pr-table { width: 100%; border-collapse: collapse; font-size: var(--text-sm-size); }
.pr-table th { text-align: left; padding: 10px 12px; font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); background: var(--color-surface-sunken); border-bottom: 1px solid var(--color-border); white-space: nowrap; }
.pr-table td { padding: 8px 12px; border-bottom: 1px solid var(--color-border); color: var(--color-text); vertical-align: middle; }
.pr-table tr:last-child td { border-bottom: none; }
.pr-item { font-weight: 500; }
.pr-mono { font-family: var(--font-mono, ui-monospace, monospace); font-size: var(--text-xs-size); color: var(--color-text-muted); }
.pr-nowrap { white-space: nowrap; }
.pr-muted { color: var(--color-text-light); }
.pr-for { color: var(--color-brand-on-surface); }
.pr-badge { font-size: var(--text-xs-size); padding: 1px 7px; border-radius: 999px; background: var(--color-surface-sunken); }
.pr-badge.bad { color: var(--color-danger-text); background: color-mix(in srgb, var(--color-danger-text) 12%, transparent); }
.pr-inline { width: auto; min-width: 7rem; padding: 4px 8px; font-size: var(--text-xs-size); }
.appr-ok { color: var(--color-success-text); } .appr-warn { color: var(--color-warning-text); } .appr-bad { color: var(--color-danger-text); }
.pr-x { cursor: pointer; border: none; background: transparent; color: var(--color-danger-text); font-size: var(--text-sm-size); }
.pr-modal-backdrop { position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; background: rgb(0 0 0 / 0.3); z-index: 50; padding: var(--space-4); }
.pr-modal { width: 100%; max-width: 32rem; display: flex; flex-direction: column; gap: 10px; padding: var(--space-5); background: var(--color-surface-raised); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); max-height: 92vh; overflow-y: auto; }
.pr-modal-head { display: flex; align-items: center; justify-content: space-between; }
.pr-modal-head h3 { margin: 0; font-size: var(--text-base-size); font-weight: 600; color: var(--color-text); }
.pr-close { cursor: pointer; border: none; background: transparent; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }
.pr-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.pr-field { display: flex; flex-direction: column; gap: 4px; }
.pr-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.control { font-family: inherit; font-size: var(--text-sm-size); padding: 8px var(--space-3); border: 1px solid var(--color-border-strong); border-radius: var(--radius-md); background: var(--color-surface); color: var(--color-text); width: 100%; }
.control:focus { outline: none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }
.btn-primary { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 8px var(--space-4); border: none; border-radius: var(--radius-md); background: var(--color-brand-base); color: var(--color-on-brand); font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; cursor: pointer; white-space: nowrap; }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.btn-block { width: 100%; margin-top: 4px; }
</style>
