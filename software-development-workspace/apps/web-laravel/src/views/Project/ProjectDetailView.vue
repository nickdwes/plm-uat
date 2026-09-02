<script setup lang="ts">
/**
 * One project.
 *
 * The design's four tabs plus Milestones. That fifth one is an addition, not
 * something the mockups show — the legacy's 82 contract milestones had to live
 * somewhere, and a project is where they attach. If it is unwanted it is one
 * tab to remove, but hiding what the customer agreed to pay is worse.
 *
 * The task drawer is where most of the work happens: sub-tasks, dependencies,
 * assignees, assisting, resources. It opens over the list rather than
 * navigating, so the list stays as context.
 */
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug, getToken } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import EmployeePicker from "../../components/EmployeePicker.vue";
import VendorPartnerPicker from "../../components/VendorPartnerPicker.vue";
import { safeHtml, hasText } from "../../lib/richText";

const props = defineProps<{ id: string | number }>();

interface Person { id: number | null; name: string | null }
interface Member { id: number; employee: Person }
interface Doc {
  id: number; label: string; document_type: string; original_name: string;
  size_bytes: number | null; project_task_id: number | null; uploaded_at: string | null;
}
interface Comment {
  id: number; body: string; author: Person; created_at: string | null; replies: Comment[];
}
interface Task {
  id: number; reference: string | null; title: string; description: string | null;
  status: string; priority: string; deadline: string | null; completed_at: string | null;
  started_on?: string | null; phase_id?: number | null;
  position: number; parent_task_id: number | null; weekly_okr?: boolean; effort_estimate_days?: number | string | null;
  is_done: boolean; is_overdue: boolean; is_blocked: boolean;
  assignees: Member[]; assisting: Member[];
  dependencies: { id: number; title: string; status: string }[];
  subtasks: Task[]; attachments: Doc[]; comments: Comment[];
  logistics?: TaskLogistics | null;
  prerequisites?: Prerequisite[];
}
interface Prerequisite {
  id: number; type: string; label: string; status: string;
  detail: string | null; procurement_needed: boolean; due_on: string | null;
  linked_kind: string | null; linked_reference: string | null; can_raise: boolean; raise_label: string | null;
}
interface TaskLogistics {
  needs_travel: boolean; needs_client_access: boolean; needs_accommodation: boolean;
  needs_asset: boolean; needs_software: boolean; needs_procurement: boolean;
  needs_external_vendors: boolean; needs_professional_services: boolean; needs_subcontractors: boolean;
  travel_location: string | null; travel_mode: string | null;
  details: string | null; requires_approval: boolean; needs_any: boolean;
}
interface MilestoneRequirement { type: "task" | "phase"; id: number; title: string; status: string; done: boolean }
interface MilestoneProof { id: number; label: string; size_bytes: number | null; uploaded_at: string | null }
interface Milestone {
  id: number; reference: string | null; name: string; status: string;
  currency: string | null; value: string | null;
  due_on: string | null; invoiced_at: string | null; paid_at: string | null;
  invoice_reference: string | null;
  is_paid: boolean; is_outstanding: boolean; is_overdue: boolean;
  work_is_done: boolean | null;
  task: { id: number; title: string; status: string } | null;
  requirements: MilestoneRequirement[];
  requirements_met: boolean | null;
  proofs: MilestoneProof[];
  has_proof: boolean;
  ready_to_invoice: boolean;
}
interface Activity { id: number; type: string; body: string | null; occurred_at: string | null; actor: Person }
interface AccountContact { id: number; name: string; designation: string | null }
interface Project {
  id: number; reference: string | null; name: string; description: string | null;
  commercials_visible?: boolean;
  status: string; priority: string; deadline: string | null;
  progress: number | null; is_overdue: boolean; was_late: boolean | null;
  account?: { id: number; name: string; contacts?: AccountContact[] } | null;
  opportunity?: { id: number; reference: string | null; name: string } | null;
  project_lead?: Person | null;
  team?: { id: number; employee: Person; role: string | null }[];
  delivery?: {
    vendor_partner?: { id: number; name: string } | null;
    days_own: string | null; days_oem: string | null; total_days: number | null;
    oem_share: number | null; oem_share_drift: number | null; oem_ps_cost: string | null;
  };
  // Charter
  scope: string | null; charter: string | null; pip: string | null;
  product: string | null; project_email: string | null;
  execution_start: string | null; execution_end: string | null;
  charter_completion_date: string | null; contract_completion_date: string | null;
  client_contact_id: number | null; client_sponsor_contact_id: number | null;
  client_contact?: { id: number; name: string } | null;
  client_sponsor?: { id: number; name: string } | null;
  created_at: string | null;
}
interface ProjectDoc {
  id: number; label: string; document_type: string; original_name: string;
  size_bytes: number | null; uploaded_at: string | null;
}

type Tab = "details" | "tasks" | "phases" | "team" | "issues" | "dependencies" | "risks" | "delivery" | "milestones" | "budget" | "bom" | "orders" | "training" | "weekly" | "correspondence" | "comments" | "activities" | "reports" | "logistics";
type TeamSub = "internal" | "customer" | "professional" | "subcontractors";

interface PhaseRow {
  id: number; name: string; due_on: string | null; status: string; progress: number;
  tasks: number; completed: number; overdue: number; at_risk: boolean;
  children: { id: number; name: string; due_on: string | null }[];
}
interface TeamPerson { id: number; employee_id: number; name: string; email: string | null; role_kind: string; is_lead?: boolean; capacity_percent?: number | null; assigned_from?: string | null; assigned_to?: string | null }
interface TeamData {
  managers: TeamPerson[]; leads: TeamPerson[];
  governance: Record<string, TeamPerson[]>;
  roles: { id: number; name: string; team_key: string | null; description: string | null; members: TeamPerson[] }[];
}
interface GovPerson { id: number; name: string }
interface GovRole {
  id: number | null; role_key: string; label: string; is_mandatory: boolean; present: boolean;
  assignee: GovPerson | null; backup: GovPerson | null;
  responsibility: string | null; escalation_authority: string | null;
  approval_authority: string | null; department: string | null;
  contact_info: string | null; start_date: string | null; end_date: string | null;
  filled: boolean;
}
interface CustContact { id: number; name: string; email: string | null; category: string }
interface CustRole {
  id: number | null; role_key: string; label: string; present: boolean; account_contact_id: number | null;
  contact: { id: number; name: string; email: string | null } | null; responsibility: string | null;
}
interface PsEntry { id: number; role: string; role_label: string; name: string; organization: string | null; description: string | null; contact_info: string | null; start_date: string | null; end_date: string | null }
interface SubEntry { id: number; role: string; role_label: string; company: string; contact_person: string | null; contact_info: string | null; scope_of_work: string | null; contract_reference: string | null; approval_status: string; onboarding_status: string; start_date: string | null; end_date: string | null }
interface Catalog { key: string; label: string }
interface PsData { data: PsEntry[]; catalog: Catalog[] }
interface SubData { data: SubEntry[]; catalog: Catalog[] }
interface Stakeholder {
  id: number; name: string; title: string | null; email: string | null; phone: string | null;
  persona: string | null; influence: string | null; interest: string | null;
  responsibility: string | null; account_contact_id: number | null;
}
interface CustomerTeam {
  organization: { id: number; name: string } | null;
  roles: CustRole[]; contacts: CustContact[]; stakeholders: Stakeholder[];
}
interface GovReportRow { role: string; role_key: string; is_mandatory: boolean; holder: string | null; backup: string | null; responsibility: string | null; escalation_authority: string | null; approval_authority: string | null }
interface RaciRow { role: string; person: string | null; responsible_for: string | null; accountable: string | null; consulted: string | null; informed: string | null }
interface OrgTeam { name: string; description: string | null; lead: string | null; members: { name: string; is_lead: boolean; capacity_percent: number | null }[] }
interface ResRow { task_id: number; title: string; unit: string | null; people: string[]; people_count: number; effort_days: number | null; site: string; location: string | null; travel: boolean }
interface ResUtil { name: string; total_capacity: number; over_allocated: boolean; teams: number }
interface TravelReq { id: number; reference: string | null; purpose: string; location: string | null; start_date: string | null; end_date: string | null; travel_days: number | null; mode: string | null; accommodation: boolean; estimated_cost: string | null; currency: string; tasks: { id: number; title: string }[]; personnel: string[]; approval_status: string; notes: string | null; module_requests: number }
interface ApprovalRow {
  kind: "travel" | "procurement"; id: number; reference: string | null; title: string; detail: string;
  amount: number | null; currency: string; needed_by: string | null; requested_by: string | null; requested_on: string | null;
  status: string; decided_by: string | null; decided_at: string | null; flags: string[];
}
interface ApprovalSummary { total: number; pending: number; approved: number; rejected: number; pending_value: number; awaiting_costing: number }
interface AlertRow { severity: "critical" | "warning" | "info"; category: string; title: string; detail: string; tab: string; count: number }
interface AlertSummary { total: number; critical: number; warning: number; info: number }
interface LogisticsSummary {
  requirements: { tasks_with_needs: number } & Record<string, number>;
  prerequisites: { total: number; done: number; open: number; in_progress: number; blocked: number; procurement_needed: number; cleared_pct: number | null };
  travel: { cycles: number; sites: number; travel_days: number; people: number; requests: number; requests_pending: number };
  procurement: { items: number; pending_approval: number; long_lead: number; estimated_total: number };
  resourcing: { tasks: number; on_site: number; remote: number; effort_days: number };
}
interface Proc { id: number; reference: string | null; item: string; category: string | null; quantity: number; vendor: string | null; required_by: string | null; lead_time_days: number | null; is_long_lead: boolean; estimated_cost: string | null; currency: string; approval_status: string; status: string; task: { id: number; title: string } | null }
interface VendorEngagement { id: number; reference: string | null; engagement_type: string; vendor: string | null; vendor_partner_id: number | null; vendor_name: string | null; scope: string | null; status: string; notes: string | null; task: string | null; task_id: number | null }
interface EngagementData { data: VendorEngagement[]; summary: { count: number; open: number; active: number }; options: { types: string[]; statuses: string[] } }
interface TravelCycle { number: number; location: string; tasks: { id: number; title: string }[]; team: string[]; start: string | null; end: string | null; duration_days: number | null; travel_days: number | null; modes: string[]; accommodation: boolean }
interface TravelTip { type: string; title: string; detail: string; cycles: number[]; saving_days: number }
interface EstRate { amount: number; unit: string; source: string }
interface TravelEstimateRates { currency: string; per_diem: EstRate; accommodation: EstRate; incidentals: EstRate; transport: Record<string, number> }
interface TravelTipSummary { cycles: number; tips: number; potential_saving_days: number }
interface ProjectReports {
  raci: RaciRow[];
  org_chart: { leadership: { role: string; name: string | null }[]; teams: OrgTeam[] };
  governance: GovReportRow[];
  stakeholders: { name: string; title: string | null; email: string | null; phone: string | null; persona: string | null; influence: string | null; interest: string | null; responsibility: string | null }[];
  customer_directory: { organization: string | null; contacts: { name: string; category: string; email: string | null; phone: string | null; project_role: string | null }[] };
  escalation: { level: number; role: string; holder: string | null; escalates_to: string | null }[];
  customer_escalation: { role: string; contact: string; email: string | null; escalates_to: string | null }[];
  professional_services: { role: string; name: string; organization: string | null; contact_info: string | null; period: string | null }[];
  subcontractors: { role: string; company: string; contact_person: string | null; scope_of_work: string | null; contract_reference: string | null; approval_status: string; onboarding_status: string }[];
  risk_dependency: {
    risks: { id: number; title: string; likelihood: string; impact: string; score: number; level: string; status: string; owner: string | null; mitigation: string | null; against: string | null }[];
    dependencies: { id: number; title: string; kind: string; status: string; owner: string | null; due_on: string | null; overdue: boolean; against: string | null; waits_on: string | null }[];
    summary: { risks_total: number; risks_open: number; risks_high: number; top_risk: string | null; deps_total: number; deps_unresolved: number; deps_overdue: number };
  };
}

interface Owner { id: number; name: string }
interface DeliveryMilestone {
  id: number; title: string; description: string | null; target_date: string | null;
  status: string; achieved_on: string | null;
  is_achieved: boolean; is_overdue: boolean; is_at_risk: boolean; owner: Owner | null;
}
interface Issue {
  id: number; title: string; description: string | null; status: string; severity: string;
  task: { id: number; title: string } | null; escalated: boolean; escalated_to: string | null;
}
interface TaskIssueRow {
  id: number; title: string; details: string | null; priority: string; status: string;
  task: { id: number; title: string; reference: string | null } | null; raised_by: string | null;
}
interface BomItem {
  id: number; line_no: number; category: string; description: string; part_number: string | null;
  vendor_partner_id: number | null; supplier: string | null; quantity: number; unit: string | null;
  unit_cost: number; selling_price: number; currency: string; margin: number; order_value: number;
  expected_delivery_date: string | null; phase: string | null; notes: string | null;
}
interface BomData {
  bom: { id: number; version: number; status: string; approved_at: string | null; approved_by: string | null; notes: string | null };
  items: BomItem[];
  summary: { lines: number; total_cost: number; total_value: number; total_margin: number; by_category: Record<string, number> };
  options: { categories: string[] };
}
interface OrderRow {
  id: number; reference: string | null; category: string; description: string; part_number: string | null;
  supplier: string | null; quantity: number; unit_cost: number; order_value: number; margin: number; currency: string;
  status: string; expected_delivery_date: string | null;
  fulfilled_by: { kind: string; reference: string | null; status: string | null } | null;
  can_raise: boolean; accepted_by: string | null; acceptance_note: string | null;
}
interface OrdersRollup {
  data: OrderRow[];
  summary: { count: number; active: number; delivered: number; unlinked: number; total_value: number; total_margin: number; by_status: Record<string, number> };
}
interface OrderRelease {
  readiness: { gate: string; suggested_gate: string | null; awarded: boolean; contract_ready: boolean; contract_auto: boolean; lpo_received: boolean; ready: boolean; blocker: string | null };
  settings: { order_release_gate: string; contract_ready_override: boolean | null };
  customer_lpo: { number: string | null; date: string | null; value: number | null; currency: string | null };
  bom_approved: boolean; orders_exist: boolean;
}
interface Trainee {
  id: number; party: string; employee_id: number | null; name: string; email: string | null; designation: string | null;
  needs_travel: boolean; travel_location: string | null; travel_cost: number | null;
}
interface TrainingFulfillment {
  id: number; order_reference: string | null; title: string; training_item_id: number | null;
  course: { id: number; title: string; code: string | null } | null;
  cost_per_person: number; currency: string; mode: string | null; status: string; notes: string | null;
  trainees: Trainee[]; trainee_count: number; travel_total: number; total_cost: number;
}
interface TrainingData {
  data: TrainingFulfillment[];
  catalog: { id: number; title: string; code: string | null; delivery: string | null; cost: number | null }[];
  options: { modes: string[]; statuses: string[]; parties: string[] };
}
interface Dependency {
  id: number; title: string; description: string | null; kind: string; status: string;
  due_on: string | null; task: { id: number; title: string } | null;
  depends_on_task: { id: number; title: string; status: string } | null; owner: Owner | null;
}
interface RiskEvidence { id: number; label: string; size_bytes: number | null }
interface Risk {
  id: number; title: string; description: string | null; likelihood: string; impact: string;
  score: number; status: string; mitigation: string | null; owner: Owner | null;
  task_id: number | null; phase_id: number | null;
  task: { id: number; title: string; status: string } | null;
  phase: { id: number; name: string } | null;
  evidence: RiskEvidence[];
}
interface FinanceSummary {
  currency: string; budgeted: number; spent: number; remaining: number;
  utilization: number | null; over_budget: boolean;
}
interface BudgetLine { id: number; label: string; category: string | null; source: string; currency: string; amount: number; note: string | null }
interface Expense {
  id: number; label: string; category: string | null; currency: string; amount: number;
  spent_on: string | null; note: string | null;
  task_id: number | null; task: string | null; travel_request_id: number | null; travel: string | null;
}
interface FinanceData { summary: FinanceSummary[]; budget_lines: BudgetLine[]; expenses: Expense[]; categories: string[] }
interface FinCatRow { category: string; budgeted: number; spent: number; variance: number; over: boolean; utilization: number | null }
interface FinBurnRow { month: string; spent: number; cumulative: number }
interface FinForecast {
  window_start: string; window_end: string; total_days: number; elapsed_days: number; elapsed_pct: number;
  burn_per_day: number; projected_total: number; projected_variance: number; projected_over: boolean;
}
interface FinAnalytics {
  currency: string; budgeted: number; spent: number; remaining: number; variance: number;
  utilization: number | null; over_budget: boolean;
  by_category: FinCatRow[]; burn: FinBurnRow[]; undated_spend: number;
  forecast: FinForecast | null; linkage: { to_tasks: number; to_travel: number; unlinked: number };
}
interface Vendor { id: number; name: string; note: string | null; partner: { id: number; name: string } | null }
interface VendorData { data: Vendor[]; partners: { id: number; name: string }[] }

interface OrderTracker { id: number; name: string; details: string | null; happened_on: string | null; by: string | null }
interface OrderUpdate { id: number; latest_supplier_update: string | null; delivery_plan_status: string | null; by: string | null; at: string | null }
interface OrderContact { id: number; name: string; email: string | null; phone: string | null; address: string | null }
interface OrderDoc { id: number; label: string; size_bytes: number | null }
interface MaterialOrder {
  id: number; reference: string | null; name: string; description: string | null;
  status: string; due_date: string | null; is_overdue: boolean; lpo_reference: string | null;
  supplier: { id: number; name: string } | null; supplier_name: string | null; vendor_partner_id: number | null;
  trackers: OrderTracker[]; updates: OrderUpdate[]; contacts: OrderContact[]; documents: OrderDoc[];
}
interface OrdersData { data: MaterialOrder[]; partners: { id: number; name: string }[] }

interface OkrRow { id: number; title: string; status: string; done: boolean }
interface RiskDigest { id: number; title: string; score: number; status: string }
interface WeekFigures {
  rag: string; progress_pct: number; completed_tasks: number; total_tasks: number;
  overdue_tasks: number; open_issues: number; open_dependencies: number;
  okrs?: OkrRow[]; risks?: RiskDigest[];
}
interface WeekSnapshot extends WeekFigures {
  id?: number; week_start: string; summary: string | null;
  published_by?: string | null; published_at?: string | null;
}
interface WeeklyReport {
  week_start: string; published: boolean; live: WeekFigures;
  last_week: WeekSnapshot | null;
  deltas: { progress_pct: number; rag_changed: boolean; rag_was: string } | null;
}

interface Attendee { name: string; address: string }
interface AttendeeSuggestion extends Attendee { group: string }
interface Meeting {
  id: number; subject: string; scheduled_at: string; duration_minutes: number;
  attendees: Attendee[]; join_url: string | null; recording_url: string | null;
  transcript: string | null; has_transcript: boolean;
  status: string; created_by: string | null;
}
interface ProjectEmailItem {
  id: number; from_name: string | null; from_email: string | null; subject: string | null;
  body_preview: string | null; body_html: string | null; has_attachments: boolean; received_at: string | null;
}

const router  = useRouter();
const project = ref<Project | null>(null);
const tasks   = ref<Task[]>([]);
const milestones = ref<Milestone[]>([]);
const milestoneTotals = ref<Record<string, Record<string, number>> | null>(null);
const commercialsVisible = ref(true);
const comments   = ref<Comment[]>([]);
const activities = ref<Activity[]>([]);
const phases     = ref<PhaseRow[]>([]);
const team       = ref<TeamData | null>(null);
const governance = ref<GovRole[] | null>(null);
const vacantMandatory = ref<string[]>([]);
const customerTeam = ref<CustomerTeam | null>(null);
const psData = ref<PsData | null>(null);
const subData = ref<SubData | null>(null);
const reports = ref<ProjectReports | null>(null);
const travelSchedule = ref<TravelCycle[] | null>(null);
const procurement = ref<Proc[] | null>(null);
const vendorEngagements = ref<EngagementData | null>(null);
const travelReqs = ref<TravelReq[] | null>(null);
const approvals = ref<ApprovalRow[] | null>(null);
const approvalSummary = ref<ApprovalSummary | null>(null);
const alerts = ref<AlertRow[] | null>(null);
const alertSummary = ref<AlertSummary | null>(null);
const logisticsSummary = ref<LogisticsSummary | null>(null);
const travelTips = ref<TravelTip[] | null>(null);
const travelTipSummary = ref<TravelTipSummary | null>(null);
const travelEstimateRates = ref<TravelEstimateRates | null>(null);
const resourcePlan = ref<{ data: ResRow[]; utilization: ResUtil[]; summary: { total_effort_days: number; tasks_with_travel: number; tasks_on_site: number; people_count: number; over_allocated: number } } | null>(null);
const travelReqSummary = ref<{ count: number; pending_approval: number; estimated_total: number } | null>(null);
const procSummary = ref<{ count: number; pending_approval: number; long_lead: number; estimated_total: number } | null>(null);
const newProc = ref({ item: "", category: "", quantity: 1 as number | string, vendor: "", required_by: "", lead_time_days: "" as number | string, estimated_cost: "" as number | string });
const issues       = ref<Issue[]>([]);
const taskIssues   = ref<TaskIssueRow[]>([]);
const dependencies = ref<Dependency[]>([]);
const risks        = ref<Risk[]>([]);
const registerLoaded = ref(false);
const deliveryMilestones = ref<DeliveryMilestone[]>([]);
const deliveryLoaded = ref(false);
interface ClosureGate { met: boolean; open?: number; total?: number }
interface ClosureData {
  ready: boolean; closed: boolean; closed_at: string | null; closed_by: string | null; bom_approved: boolean;
  gates: { orders_settled: ClosureGate; billing_settled: ClosureGate; within_budget: ClosureGate };
}
const finance      = ref<FinanceData | null>(null);
const closure      = ref<ClosureData | null>(null);
const finAnalytics = ref<FinAnalytics | null>(null);
const vendors      = ref<VendorData | null>(null);
const weekly       = ref<WeeklyReport | null>(null);
const weeklyHistory = ref<WeekSnapshot[]>([]);
const weeklySummary = ref("");
const publishing    = ref(false);
const meetings       = ref<Meeting[]>([]);
const meetingSuggestions = ref<AttendeeSuggestion[]>([]);
const correspondenceLoaded = ref(false);
const emails         = ref<ProjectEmailItem[]>([]);
const openEmail      = ref<number | null>(null);
const fetchingEmail  = ref(false);
const orders         = ref<MaterialOrder[]>([]);
const orderPartners  = ref<{ id: number; name: string }[]>([]);
const ordersLoaded   = ref(false);
const bom            = ref<BomData | null>(null);
const genOrders      = ref<OrdersRollup | null>(null);
const orderRelease   = ref<OrderRelease | null>(null);
const lpoForm        = ref({ number: "", date: "", value: "" as number | string, currency: "KES" });
const training       = ref<TrainingData | null>(null);
const newTrainee     = ref<Record<number, { party: string; employee_id: number | null; name: string; email: string; needs_travel: boolean; travel_location: string; travel_cost: number | string }>>({});
const newBomItem     = ref({ category: "hardware", description: "", part_number: "", quantity: 1 as number | string, unit: "", unit_cost: "" as number | string, selling_price: "" as number | string, currency: "KES" });
const bomImportInput = ref<HTMLInputElement | null>(null);

const tab     = ref<Tab>("details");
const teamSub = ref<TeamSub>("internal");
const teamSubs: { key: TeamSub; label: string }[] = [
  { key: "internal",       label: "Internal Team" },
  { key: "customer",       label: "Customer" },
  { key: "professional",   label: "Professional Services" },
  { key: "subcontractors", label: "Subcontractors" },
];
const loading = ref(true);
const saving  = ref(false);

const org  = () => currentOrgSlug.value;

// The project lead is drawn from the PMO team — the "Project Management Office"
// department under the Technical function. Resolved from the org structure by
// name (not a hard-coded id) so the picker constrains to whoever sits there.
const pmoDepartmentId = ref<number | null>(null);
async function loadPmoDepartment() {
  try {
    interface Dept { id: number; name: string }
    interface Unit { id: number; name: string; departments: Dept[] }
    const res = await api<{ functional_units: Unit[]; unassigned_departments: Dept[] }>(
      "GET", "/api/v1/org/structure", { org: org() });
    const isPmo = (n: string) => {
      const l = n.toLowerCase();
      return l.includes("project management") || l === "pmo" || l.includes("pmo");
    };
    // Prefer a PMO department under a "Technical" function; fall back to any PMO.
    const technical = res.functional_units.find((u) => u.name.toLowerCase().includes("technical"));
    const all = res.functional_units.flatMap((u) => u.departments ?? []).concat(res.unassigned_departments ?? []);
    const dept = (technical?.departments ?? []).find((d) => isPmo(d.name)) ?? all.find((d) => isPmo(d.name));
    pmoDepartmentId.value = dept?.id ?? null;
  } catch { /* leave null; the picker then searches everyone rather than blocking */ }
}
const base = computed(() => `/api/v1/projects/${props.id}`);

/*
 * Two money tabs follow the server's own `commercials_visible` flag — the
 * same one that already nulls the figures inside them. A Budget tab whose
 * every number renders "•••" is not access, it is furniture; hiding it says
 * the truth sooner. Everything else here is this project's own data, already
 * behind the project `view` policy that opened the page.
 */
const COMMERCIAL_TABS: Tab[] = ["budget", "milestones"];

const ALL_TABS: { key: Tab; label: string }[] = [
  { key: "details",    label: "Details" },
  { key: "team",       label: "Team & Roles" },
  { key: "phases",     label: "Phases" },
  { key: "budget",     label: "Budget" },
  { key: "tasks",      label: "Tasks" },
  { key: "issues",       label: "Issues" },
  { key: "dependencies", label: "Dependencies" },
  { key: "risks",        label: "Risks" },
  { key: "delivery",   label: "Milestones" },
  { key: "milestones", label: "Payment Milestones" },
  { key: "bom",        label: "BOM" },
  { key: "orders",     label: "Orders" },
  { key: "training",   label: "Training & Enablement" },
  { key: "weekly",     label: "Weekly Report" },
  { key: "correspondence", label: "Correspondence" },
  { key: "comments",   label: "Comments" },
  { key: "activities", label: "Activities" },
  { key: "reports",    label: "Reports" },
  { key: "logistics",  label: "Logistics" },
];

const tabs = computed(() =>
  ALL_TABS.filter((t) => !COMMERCIAL_TABS.includes(t.key) || commercialsVisible.value));

function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

async function load() {
  loading.value = true;
  try {
    project.value = (await api<{ data: Project }>("GET", base.value, { org: org() })).data;
    // The detail payload carries the same flag the milestones endpoint does;
    // reading it here means the Budget tab is honest from first paint rather
    // than from the first click on the tab it exists to hide.
    commercialsVisible.value = project.value.commercials_visible ?? true;
    syncCharterForm();
    void loadDocuments();
  } catch (e) { fail(e, "Could not load this project."); }
  finally { loading.value = false; }
}

async function loadTasks() {
  try {
    tasks.value = (await api<{ data: Task[] }>("GET", `${base.value}/tasks`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load tasks."); }
}

async function loadMilestones() {
  try {
    const res = await api<{ data: Milestone[]; totals: typeof milestoneTotals.value; commercials_visible: boolean }>(
      "GET", `${base.value}/milestones`, { org: org() });
    milestones.value = res.data;
    milestoneTotals.value = res.totals;
    commercialsVisible.value = res.commercials_visible;
  } catch (e) { fail(e, "Could not load milestones."); }
}

async function loadComments() {
  try {
    comments.value = (await api<{ data: Comment[] }>("GET", `${base.value}/comments`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load comments."); }
}

async function loadActivities() {
  try {
    activities.value = (await api<{ data: Activity[] }>("GET", `${base.value}/activities`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load the timeline."); }
}

async function loadPhases() {
  try {
    phases.value = (await api<{ data: PhaseRow[] }>("GET", `${base.value}/phases`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load phases."); }
}

async function loadTeam() {
  try {
    team.value = (await api<{ data: TeamData }>("GET", `${base.value}/team`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load the team."); }
}

async function loadRegister() {
  try {
    const [i, d, r] = await Promise.all([
      api<{ data: Issue[]; task_issues: TaskIssueRow[] }>("GET", `${base.value}/issues`, { org: org() }),
      api<{ data: Dependency[] }>("GET", `${base.value}/dependencies`, { org: org() }),
      api<{ data: Risk[] }>("GET", `${base.value}/risks`, { org: org() }),
    ]);
    issues.value = i.data; taskIssues.value = i.task_issues ?? []; dependencies.value = d.data; risks.value = r.data;
    registerLoaded.value = true;
  } catch (e) { fail(e, "Could not load the register."); }
}

async function loadDelivery() {
  try {
    deliveryMilestones.value = (await api<{ data: DeliveryMilestone[] }>("GET", `${base.value}/delivery-milestones`, { org: org() })).data;
    deliveryLoaded.value = true;
  } catch (e) { fail(e, "Could not load milestones."); }
}

async function loadBudget() {
  try {
    const [f, v, a] = await Promise.all([
      api<FinanceData>("GET", `${base.value}/finance`, { org: org() }),
      api<VendorData>("GET", `${base.value}/vendors`, { org: org() }),
      api<FinAnalytics>("GET", `${base.value}/finance/analytics`, { org: org() }),
    ]);
    finance.value = f; vendors.value = v; finAnalytics.value = a;
    if (!travelReqs.value) void loadTravelRequests();
    void loadClosure();
  } catch (e) { fail(e, "Could not load the budget."); }
}
async function loadClosure() {
  try { closure.value = await api<ClosureData>("GET", `${base.value}/closure`, { org: org() }); }
  catch (e) { fail(e, "Could not load closure status."); }
}
async function closeProject() {
  try { closure.value = await api<ClosureData>("POST", `${base.value}/closure/close`, { org: org() }); notifySaved("Project financially closed."); }
  catch (e) { fail(e, "Could not close the project."); }
}
async function reopenProject() {
  try { closure.value = await api<ClosureData>("POST", `${base.value}/closure/reopen`, { org: org() }); }
  catch (e) { fail(e, "Could not reopen the project."); }
}

async function reloadAnalytics() {
  try { finAnalytics.value = await api<FinAnalytics>("GET", `${base.value}/finance/analytics`, { org: org() }); }
  catch { /* analytics is best-effort; the registers already updated */ }
}
function burnPct(spent: number): number {
  const peak = Math.max(1, ...(finAnalytics.value?.burn ?? []).map((b) => b.spent));
  return Math.round((spent / peak) * 100);
}

async function loadWeekly() {
  try {
    const [r, h] = await Promise.all([
      api<{ data: WeeklyReport }>("GET", `${base.value}/weekly-report`, { org: org() }),
      api<{ data: WeekSnapshot[] }>("GET", `${base.value}/weekly-report/history`, { org: org() }),
    ]);
    weekly.value = r.data; weeklyHistory.value = h.data;
  } catch (e) { fail(e, "Could not load the weekly report."); }
}

async function loadCorrespondence() {
  try {
    const [m, e] = await Promise.all([
      api<{ data: Meeting[]; suggestions: AttendeeSuggestion[]; project_email: string | null }>("GET", `${base.value}/meetings`, { org: org() }),
      api<{ data: ProjectEmailItem[] }>("GET", `${base.value}/emails`, { org: org() }),
    ]);
    meetings.value = m.data;
    meetingSuggestions.value = m.suggestions;
    emails.value = e.data;
    correspondenceLoaded.value = true;
  } catch (err) { fail(err, "Could not load correspondence."); }
}

async function fetchEmails() {
  fetchingEmail.value = true;
  try {
    const res = await api<{ data: ProjectEmailItem[]; new: number; fetched: number }>(
      "POST", `${base.value}/emails/sync`, { org: org() });
    emails.value = res.data;
    notifySaved(res.new ? `${res.new} new email${res.new === 1 ? "" : "s"} pulled.` : "No new email — you're up to date.");
  } catch (e) { fail(e, "Could not fetch email."); }
  finally { fetchingEmail.value = false; }
}
function emailWhen(iso: string | null): string {
  if (!iso) return "";
  return new Date(iso).toLocaleString(undefined, { year: "numeric", month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
}

// ── Bill of Materials ────────────────────────────────────────────────────────
async function loadBom() {
  try { bom.value = await api<BomData>("GET", `${base.value}/bom`, { org: org() }); void loadOrderRelease(); }
  catch (e) { fail(e, "Could not load the BOM."); }
}
async function loadOrderRelease() {
  try {
    orderRelease.value = await api<OrderRelease>("GET", `${base.value}/order-release`, { org: org() });
    const l = orderRelease.value.customer_lpo;
    lpoForm.value = { number: l.number ?? "", date: l.date ?? "", value: l.value ?? "", currency: l.currency ?? "KES" };
  } catch (e) { fail(e, "Could not load order release."); }
}
async function saveOrderRelease(patch: Record<string, unknown>) {
  try {
    const res = await api<OrderRelease & { generated?: unknown }>("PUT", `${base.value}/order-release`, { org: org(), body: patch });
    orderRelease.value = res;
    if (res.generated) { genOrders.value = null; bom.value && void loadBom(); notifySaved("Ordering released — orders generated."); }
  } catch (e) { fail(e, "Could not update order release."); }
}
function saveLpo() {
  const f = lpoForm.value;
  void saveOrderRelease({
    customer_lpo_number: f.number || null, customer_lpo_date: f.date || null,
    customer_lpo_value: f.value === "" ? null : Number(f.value), customer_lpo_currency: f.currency.toUpperCase(),
  });
}
async function generateOrdersNow() {
  try { genOrders.value = await api<OrdersRollup>("POST", `${base.value}/orders/generate`, { org: org() }); void loadOrderRelease(); notifySaved("Orders generated."); }
  catch (e) { fail(e, "Could not generate orders."); }
}
const gateLabels: Record<string, string> = { manual: "Manual (Approve BOM)", on_award: "On award", on_contract: "On contract ready", on_lpo: "On customer LPO" };

// ── Training & enablement ─────────────────────────────────────────────────────
function blankTrainee() { return { party: "internal", employee_id: null as number | null, name: "", email: "", needs_travel: false, travel_location: "", travel_cost: "" as number | string }; }
async function loadTraining() {
  try {
    training.value = await api<TrainingData>("GET", `${base.value}/training`, { org: org() });
    for (const f of training.value.data) if (!newTrainee.value[f.id]) newTrainee.value[f.id] = blankTrainee();
  } catch (e) { fail(e, "Could not load training."); }
}
async function updateFulfillment(f: TrainingFulfillment, patch: Record<string, unknown>) {
  try { training.value = await api<TrainingData>("PUT", `${base.value}/training/${f.id}`, { org: org(), body: patch }); }
  catch (e) { fail(e, "Could not update the training."); }
}
async function addTrainee(f: TrainingFulfillment) {
  const t = newTrainee.value[f.id];
  if (!t || (!t.name.trim() && !t.employee_id)) return;
  try {
    training.value = await api<TrainingData>("POST", `${base.value}/training/${f.id}/trainees`, { org: org(), body: {
      party: t.party, employee_id: t.party === "internal" ? t.employee_id : null,
      name: t.name.trim() || null, email: t.email || null,
      needs_travel: t.needs_travel, travel_location: t.travel_location || null,
      travel_cost: t.travel_cost === "" ? null : Number(t.travel_cost),
    } });
    newTrainee.value[f.id] = blankTrainee();
  } catch (e) { fail(e, "Could not add the trainee."); }
}
async function deleteTrainee(f: TrainingFulfillment, id: number) {
  try { training.value = await api<TrainingData>("DELETE", `${base.value}/training/${f.id}/trainees/${id}`, { org: org() }); }
  catch (e) { fail(e, "Could not remove the trainee."); }
}
function partyLabel(p: string): string { return p === "internal" ? "Internal" : p === "customer" ? "Customer" : "Partner"; }
async function addBomItem() {
  const f = newBomItem.value;
  if (!f.description.trim()) return;
  try {
    bom.value = await api<BomData>("POST", `${base.value}/bom/items`, { org: org(), body: {
      category: f.category, description: f.description.trim(), part_number: f.part_number || null,
      quantity: Number(f.quantity) || 1, unit: f.unit || null,
      unit_cost: f.unit_cost === "" ? 0 : Number(f.unit_cost),
      selling_price: f.selling_price === "" ? 0 : Number(f.selling_price),
      currency: f.currency.toUpperCase(),
    } });
    newBomItem.value = { category: f.category, description: "", part_number: "", quantity: 1, unit: "", unit_cost: "", selling_price: "", currency: f.currency };
  } catch (e) { fail(e, "Could not add the line."); }
}
async function deleteBomItem(id: number) {
  try { bom.value = await api<BomData>("DELETE", `${base.value}/bom/items/${id}`, { org: org() }); }
  catch (e) { fail(e, "Could not remove the line."); }
}
async function importBom(e: Event) {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  input.value = "";
  if (!file) return;
  const fd = new FormData();
  fd.append("file", file);
  try {
    const res = await api<BomData & { imported: number }>("POST", `${base.value}/bom/import`, { org: org(), formData: fd });
    bom.value = res;
    notifySaved(`Imported ${res.imported} line${res.imported === 1 ? "" : "s"}.`);
  } catch (err) { fail(err, "Could not import the CSV."); }
}
async function approveBom() {
  try {
    const res = await api<BomData & { generated?: { created: number; spawned: number } | null; held?: boolean }>("POST", `${base.value}/bom/approve`, { org: org() });
    bom.value = res;
    const g = res.generated;
    if (res.held) notifySaved("BOM approved — orders are held until the order-release gate is met.");
    else notifySaved(g ? `BOM approved — ${g.created} order${g.created === 1 ? "" : "s"} generated${g.spawned ? `, ${g.spawned} routed to a workflow` : ""}.` : "BOM approved.");
    genOrders.value = null;   // Orders tab now stale
    void loadOrderRelease();
  } catch (e) { fail(e, "Could not approve the BOM."); }
}
async function loadGenOrders() {
  try { genOrders.value = await api<OrdersRollup>("GET", `${base.value}/orders`, { org: org() }); }
  catch (e) { fail(e, "Could not load orders."); }
}
async function raiseGenOrder(o: OrderRow) {
  try {
    genOrders.value = await api<OrdersRollup>("POST", `${base.value}/orders/${o.id}/raise`, { org: org() });
    notifySaved("Fulfilment record raised and linked.");
  } catch (e) { fail(e, "Could not raise the record."); }
}
async function setGenOrderStatus(o: OrderRow, status: string) {
  const body: Record<string, unknown> = { status };
  if (status === "accepted") {
    const note = window.prompt("Customer acceptance note (optional) — who signed off, reference, etc.");
    if (note === null) return;   // cancelled
    body.acceptance_note = note;
  }
  try { genOrders.value = await api<OrdersRollup>("PUT", `${base.value}/orders/${o.id}/status`, { org: org(), body }); }
  catch (e) { fail(e, "Could not update the order."); }
}
async function reopenBom() {
  try { bom.value = await api<BomData>("POST", `${base.value}/bom/reopen`, { org: org() }); }
  catch (e) { fail(e, "Could not reopen the BOM."); }
}
function bomCategoryLabel(c: string): string {
  return c.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
}

// ── Supplier material orders ─────────────────────────────────────────────────
async function loadOrders() {
  try {
    const res = await api<OrdersData>("GET", `${base.value}/material-orders`, { org: org() });
    orders.value = res.data; orderPartners.value = res.partners; ordersLoaded.value = true;
  } catch (e) { fail(e, "Could not load orders."); }
}
const newOrder = ref({ name: "", vendor_partner_id: "", supplier_name: "", lpo_reference: "", due_date: "" });
const openOrder = ref<number | null>(null);
async function addOrder() {
  if (!newOrder.value.name.trim()) return;
  try {
    const res = await api<{ data: MaterialOrder }>("POST", `${base.value}/material-orders`, {
      org: org(),
      body: {
        name: newOrder.value.name.trim(),
        vendor_partner_id: newOrder.value.vendor_partner_id ? Number(newOrder.value.vendor_partner_id) : null,
        supplier_name: newOrder.value.supplier_name.trim() || null,
        lpo_reference: newOrder.value.lpo_reference.trim() || null,
        due_date: newOrder.value.due_date || null,
      },
    });
    orders.value.unshift(res.data);
    openOrder.value = res.data.id;
    newOrder.value = { name: "", vendor_partner_id: "", supplier_name: "", lpo_reference: "", due_date: "" };
  } catch (e) { fail(e, "Could not add the order."); }
}
function replaceOrder(o: MaterialOrder) {
  const i = orders.value.findIndex((x) => x.id === o.id);
  if (i >= 0) orders.value[i] = o;
}
async function setOrderStatus(o: MaterialOrder, status: string) {
  try { replaceOrder((await api<{ data: MaterialOrder }>("PUT", `${base.value}/material-orders/${o.id}`, { org: org(), body: { status } })).data); }
  catch (e) { fail(e, "Could not update the order."); }
}
async function deleteOrder(id: number) {
  try { await api("DELETE", `${base.value}/material-orders/${id}`, { org: org() }); orders.value = orders.value.filter((x) => x.id !== id); }
  catch (e) { fail(e, "Could not remove the order."); }
}
// Nested: tracker, update, contact
const newTracker = ref({ name: "", details: "", happened_on: "" });
async function addTracker(o: MaterialOrder) {
  if (!newTracker.value.name.trim()) return;
  try {
    replaceOrder((await api<{ data: MaterialOrder }>("POST", `${base.value}/material-orders/${o.id}/trackers`, {
      org: org(), body: { name: newTracker.value.name.trim(), details: newTracker.value.details || null, happened_on: newTracker.value.happened_on || null },
    })).data);
    newTracker.value = { name: "", details: "", happened_on: "" };
  } catch (e) { fail(e, "Could not add the checkpoint."); }
}
async function deleteTracker(o: MaterialOrder, id: number) {
  try { replaceOrder((await api<{ data: MaterialOrder }>("DELETE", `${base.value}/material-orders/${o.id}/trackers/${id}`, { org: org() })).data); }
  catch (e) { fail(e, "Could not remove the checkpoint."); }
}
const newUpdate = ref({ latest_supplier_update: "", delivery_plan_status: "" });
async function addUpdate(o: MaterialOrder) {
  if (!newUpdate.value.latest_supplier_update.trim() && !newUpdate.value.delivery_plan_status.trim()) return;
  try {
    replaceOrder((await api<{ data: MaterialOrder }>("POST", `${base.value}/material-orders/${o.id}/updates`, {
      org: org(), body: { latest_supplier_update: newUpdate.value.latest_supplier_update || null, delivery_plan_status: newUpdate.value.delivery_plan_status || null },
    })).data);
    newUpdate.value = { latest_supplier_update: "", delivery_plan_status: "" };
  } catch (e) { fail(e, "Could not add the update."); }
}
async function deleteUpdate(o: MaterialOrder, id: number) {
  try { replaceOrder((await api<{ data: MaterialOrder }>("DELETE", `${base.value}/material-orders/${o.id}/updates/${id}`, { org: org() })).data); }
  catch (e) { fail(e, "Could not remove the update."); }
}
const newContact = ref({ name: "", email: "", phone: "", address: "" });
async function addContact(o: MaterialOrder) {
  if (!newContact.value.name.trim()) return;
  try {
    replaceOrder((await api<{ data: MaterialOrder }>("POST", `${base.value}/material-orders/${o.id}/contacts`, {
      org: org(), body: { name: newContact.value.name.trim(), email: newContact.value.email || null, phone: newContact.value.phone || null, address: newContact.value.address || null },
    })).data);
    newContact.value = { name: "", email: "", phone: "", address: "" };
  } catch (e) { fail(e, "Could not add the contact."); }
}
async function deleteContact(o: MaterialOrder, id: number) {
  try { replaceOrder((await api<{ data: MaterialOrder }>("DELETE", `${base.value}/material-orders/${o.id}/contacts/${id}`, { org: org() })).data); }
  catch (e) { fail(e, "Could not remove the contact."); }
}
const orderDocUploading = ref<number | null>(null);
async function uploadOrderDoc(o: MaterialOrder, e: Event) {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  orderDocUploading.value = o.id;
  try {
    const body = new FormData();
    body.append("file", file);
    replaceOrder((await api<{ data: MaterialOrder }>("POST", `${base.value}/material-orders/${o.id}/documents`, { org: org(), body })).data);
  } catch (err) { fail(err, "Could not attach the document."); }
  finally { orderDocUploading.value = null; input.value = ""; }
}
async function deleteOrderDoc(o: MaterialOrder, id: number) {
  try { replaceOrder((await api<{ data: MaterialOrder }>("DELETE", `${base.value}/material-orders/${o.id}/documents/${id}`, { org: org() })).data); }
  catch (e) { fail(e, "Could not remove the document."); }
}
function orderDocUrl(o: MaterialOrder, id: number): string {
  const root = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");
  return `${root}${base.value}/material-orders/${o.id}/documents/${id}/download?org=${org()}`;
}

async function publishWeek() {
  publishing.value = true;
  try {
    await api("POST", `${base.value}/weekly-report/publish`, { org: org(), body: { summary: weeklySummary.value || null } });
    weeklySummary.value = "";
    await loadWeekly();
    await load(); // project rag may have moved
    notifySaved("Weekly report published.");
  } catch (e) { fail(e, "Could not publish the weekly report."); }
  finally { publishing.value = false; }
}

async function deleteWeek(id: number) {
  try {
    await api("DELETE", `${base.value}/weekly-report/${id}`, { org: org() });
    await loadWeekly();
  } catch (e) { fail(e, "Could not remove the report."); }
}

const exportingPdf = ref(false);
async function exportWeeklyPdf() {
  exportingPdf.value = true;
  try {
    const res = await fetch(
      `${import.meta.env.VITE_LARAVEL_API_URL ?? ""}${base.value}/weekly-report/pdf?org=${org() ?? ""}`,
      { headers: { Authorization: `Bearer ${getToken() ?? ""}`, "X-Organization": org() ?? "" } },
    );
    if (!res.ok) { notifyFailed("Could not build the report PDF."); return; }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `Weekly-Report-${project.value?.reference ?? "project"}-${weekly.value?.week_start ?? ""}.pdf`;
    a.click();
    URL.revokeObjectURL(url);
  } catch { notifyFailed("Could not reach the server."); }
  finally { exportingPdf.value = false; }
}

// ── Correspondence: project mailbox + Teams meetings ─────────────────────────
const savingEmail = ref(false);
async function saveProjectEmail() {
  if (!project.value) return;
  savingEmail.value = true;
  try {
    project.value = (await api<{ data: Project }>("PUT", base.value, {
      org: org(), body: { name: project.value.name, project_email: project.value.project_email || null },
    })).data;
    notifySaved("Project mailbox saved.");
  } catch (e) { fail(e, "Could not save the mailbox."); }
  finally { savingEmail.value = false; }
}

const blankMeeting = () => ({
  subject: "", scheduled_at: "", duration_minutes: 30, agenda: "",
  attendees: [] as Attendee[], extraEmail: "",
});
const meetingForm = ref(blankMeeting());
const showMeeting = ref(false);
const schedulingMeeting = ref(false);

function toggleAttendee(s: AttendeeSuggestion) {
  const i = meetingForm.value.attendees.findIndex((a) => a.address === s.address);
  if (i >= 0) meetingForm.value.attendees.splice(i, 1);
  else meetingForm.value.attendees.push({ name: s.name, address: s.address });
}
function isPicked(address: string): boolean {
  return meetingForm.value.attendees.some((a) => a.address === address);
}
function addExtraAttendee() {
  const email = meetingForm.value.extraEmail.trim().toLowerCase();
  if (!email || meetingForm.value.attendees.some((a) => a.address === email)) return;
  meetingForm.value.attendees.push({ name: email, address: email });
  meetingForm.value.extraEmail = "";
}
function openMeetingDialog() {
  meetingForm.value = blankMeeting();
  showMeeting.value = true;
}
async function scheduleMeeting() {
  const f = meetingForm.value;
  if (!f.subject.trim() || !f.scheduled_at || !f.attendees.length) return;
  schedulingMeeting.value = true;
  try {
    await api("POST", `${base.value}/meetings`, {
      org: org(),
      body: {
        subject: f.subject.trim(), scheduled_at: f.scheduled_at,
        duration_minutes: f.duration_minutes, agenda: f.agenda || null, attendees: f.attendees,
      },
    });
    showMeeting.value = false;
    await loadCorrespondence();
    notifySaved("Meeting scheduled.");
  } catch (e) { fail(e, "Could not schedule the meeting."); }
  finally { schedulingMeeting.value = false; }
}
async function cancelMeeting(m: Meeting) {
  try {
    const res = await api<{ data: Meeting }>("DELETE", `${base.value}/meetings/${m.id}`, { org: org() });
    Object.assign(m, res.data);
  } catch (e) { fail(e, "Could not cancel the meeting."); }
}

const syncingMeeting = ref<number | null>(null);
const openTranscript = ref<number | null>(null);
async function syncMeeting(m: Meeting) {
  syncingMeeting.value = m.id;
  try {
    const res = await api<{ data: Meeting; status: string; message: string | null }>(
      "POST", `${base.value}/meetings/${m.id}/sync`, { org: org() });
    Object.assign(m, res.data);
    if (res.status === "updated") notifySaved(res.message ?? "Updated.");
    else notifyFailed(res.message ?? "Nothing to update yet.");
  } catch (e) { fail(e, "Could not fetch the recording."); }
  finally { syncingMeeting.value = null; }
}
/** Meetings that have already started can have a recording to fetch. */
function meetingHasPassed(m: Meeting): boolean {
  return new Date(m.scheduled_at).getTime() <= Date.now();
}
function meetingWhen(iso: string): string {
  const d = new Date(iso);
  return d.toLocaleString(undefined, { weekday: "short", year: "numeric", month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
}

watch(tab, (t) => {
  if (t === "tasks" && !tasks.value.length) void loadTasks();
  if (t === "phases" && !phases.value.length) void loadPhases();
  if (t === "team") loadTeamSub(teamSub.value);
  if (t === "reports" && !reports.value) void loadReports();
  if (t === "logistics" && !travelSchedule.value) { void loadTravelSchedule(); void loadTravelRequests(); void loadProcurement(); void loadResourcePlan(); void loadApprovals(); void loadAlerts(); void loadLogisticsSummary(); void loadTravelTips(); void loadTravelEstimate(); void loadEngagements(); }
  if ((t === "issues" || t === "dependencies" || t === "risks") && !registerLoaded.value) void loadRegister();
  if (t === "delivery" && !deliveryLoaded.value) void loadDelivery();
  if (t === "milestones" && !milestones.value.length) void loadMilestones();
  if (t === "budget" && !finance.value) void loadBudget();
  if (t === "bom" && !bom.value) void loadBom();
  if (t === "orders" && !ordersLoaded.value) { void loadOrders(); void loadGenOrders(); }
  if (t === "training" && !training.value) void loadTraining();
  if (t === "weekly" && !weekly.value) void loadWeekly();
  if (t === "correspondence" && !correspondenceLoaded.value) void loadCorrespondence();
  if (t === "comments" && !comments.value.length) void loadComments();
  if (t === "activities" && !activities.value.length) void loadActivities();
});

// The Team & Roles tab holds four sub-views; each loads on first open.
function loadTeamSub(s: TeamSub) {
  if (s === "internal" && !team.value) { void loadTeam(); void loadGovernance(); }
  if (s === "customer" && !customerTeam.value) void loadCustomerTeam();
  if (s === "professional" && !psData.value) void loadPS();
  if (s === "subcontractors" && !subData.value) void loadSubs();
}
watch(teamSub, (s) => { if (tab.value === "team") loadTeamSub(s); });

// ── Phases ───────────────────────────────────────────────────────────────────
const newPhaseName = ref("");
async function generateDefaultPhases() {
  try { phases.value = (await api<{ data: PhaseRow[] }>("POST", `${base.value}/phases/generate-defaults`, { org: org() })).data; }
  catch (e) { fail(e, "Could not generate phases."); }
}
async function addPhase() {
  if (!newPhaseName.value.trim()) return;
  try {
    phases.value = (await api<{ data: PhaseRow[] }>("POST", `${base.value}/phases`, { org: org(), body: { name: newPhaseName.value.trim() } })).data;
    newPhaseName.value = "";
  } catch (e) { fail(e, "Could not add the phase."); }
}
async function deletePhase(id: number) {
  try { phases.value = (await api<{ data: PhaseRow[] }>("DELETE", `${base.value}/phases/${id}`, { org: org() })).data; }
  catch (e) { fail(e, "Could not remove the phase."); }
}
const subPhaseName = ref<Record<number, string>>({});
async function addSubPhase(parentId: number) {
  const name = (subPhaseName.value[parentId] ?? "").trim();
  if (!name) return;
  try {
    phases.value = (await api<{ data: PhaseRow[] }>("POST", `${base.value}/phases`, { org: org(), body: { name, parent_id: parentId } })).data;
    subPhaseName.value[parentId] = "";
  } catch (e) { fail(e, "Could not add the sub-phase."); }
}

// ── Team & roles ─────────────────────────────────────────────────────────────
const newRoleName = ref("");
async function generateDefaultTeams() {
  try { team.value = (await api<{ data: TeamData }>("POST", `${base.value}/team/roles/generate-teams`, { org: org() })).data; }
  catch (e) { fail(e, "Could not generate teams."); }
}
async function updateRoleDescription(role: { id: number; description: string | null }) {
  try { team.value = (await api<{ data: TeamData }>("PUT", `${base.value}/team/roles/${role.id}`, { org: org(), body: { description: role.description || null } })).data; }
  catch (e) { fail(e, "Could not save the description."); }
}
// New-member draft, per team (keyed by role id).
const memberDraft = ref<Record<number, { is_lead: boolean; capacity_percent: string | number; assigned_from: string; assigned_to: string }>>({});
function draftFor(roleId: number) {
  if (!memberDraft.value[roleId]) memberDraft.value[roleId] = { is_lead: false, capacity_percent: "", assigned_from: "", assigned_to: "" };
  return memberDraft.value[roleId];
}
async function addTeamMember(roleId: number, employeeId: number) {
  const d = draftFor(roleId);
  try {
    const res = await api<{ data: TeamData; warning: string | null }>("POST", `${base.value}/team/people`, {
      org: org(), body: {
        employee_id: employeeId, role_kind: "member", project_role_id: roleId,
        is_lead: d.is_lead, capacity_percent: d.capacity_percent === "" ? null : Number(d.capacity_percent),
        assigned_from: d.assigned_from || null, assigned_to: d.assigned_to || null,
      },
    });
    team.value = res.data;
    memberDraft.value[roleId] = { is_lead: false, capacity_percent: "", assigned_from: "", assigned_to: "" };
    // Resource-availability check — surfaced, not blocked.
    if (res.warning) notifyFailed("Over-allocated", res.warning);
  } catch (e) { fail(e, "Could not add the member."); }
}
async function toggleMemberLead(m: TeamPerson) {
  try { team.value = (await api<{ data: TeamData }>("PUT", `${base.value}/team/people/${m.id}`, { org: org(), body: { is_lead: !m.is_lead } })).data; }
  catch (e) { fail(e, "Could not update the member."); }
}
async function addRole() {
  if (!newRoleName.value.trim()) return;
  try {
    team.value = (await api<{ data: TeamData }>("POST", `${base.value}/team/roles`, { org: org(), body: { name: newRoleName.value.trim() } })).data;
    newRoleName.value = "";
  } catch (e) { fail(e, "Could not add the role."); }
}
async function deleteRole(id: number) {
  try { team.value = (await api<{ data: TeamData }>("DELETE", `${base.value}/team/roles/${id}`, { org: org() })).data; }
  catch (e) { fail(e, "Could not remove the role."); }
}
async function assignPerson(employeeId: number, roleKind: string, roleId?: number) {
  try {
    team.value = (await api<{ data: TeamData }>("POST", `${base.value}/team/people`, {
      org: org(), body: { employee_id: employeeId, role_kind: roleKind, project_role_id: roleId ?? null },
    })).data;
  } catch (e) { fail(e, "Could not assign the person."); }
}
async function removePerson(personId: number) {
  try { team.value = (await api<{ data: TeamData }>("DELETE", `${base.value}/team/people/${personId}`, { org: org() })).data; }
  catch (e) { fail(e, "Could not remove the person."); }
}

// ── Governance committee ─────────────────────────────────────────────────────
async function loadGovernance() {
  try {
    const res = await api<{ data: GovRole[]; vacant_mandatory: string[] }>("GET", `${base.value}/governance`, { org: org() });
    governance.value = res.data;
    vacantMandatory.value = res.vacant_mandatory;
  } catch (e) { fail(e, "Could not load governance."); }
}
async function generateGovernanceTemplate() {
  try {
    const res = await api<{ data: GovRole[]; vacant_mandatory: string[] }>("POST", `${base.value}/governance/generate-template`, { org: org() });
    governance.value = res.data;
    vacantMandatory.value = res.vacant_mandatory;
  } catch (e) { fail(e, "Could not generate the governance template."); }
}

// List view: only roles that have actually been added; the rest are offered in
// the "Add role" picker.
const governanceAssigned = computed(() => (governance.value ?? []).filter((r) => r.present));

/*
 * A seat can be filled and still not be anybody's, if its term has run out or
 * has not started. Read separately from `filled` so the table can say which —
 * "Expired" and "Vacant" mean different things to whoever has to fix it.
 */
const today = () => new Date().toISOString().slice(0, 10);
const govExpired = (r: GovRole) => !!r.end_date && r.end_date < today();
const govStarted = (r: GovRole) => !r.start_date || r.start_date <= today();
const governanceAvailable = computed(() => (governance.value ?? []).filter((r) => !r.present));

const blankGovForm = () => ({
  role_key: "", assignee_id: null as number | null, backup_id: null as number | null,
  department: "", responsibility: "", escalation_authority: "", approval_authority: "",
  start_date: "", end_date: "", contact_info: "", editing: false as boolean, label: "",
});
const govModalOpen = ref(false);
const govForm = ref(blankGovForm());

function openGovAdd() {
  govForm.value = blankGovForm();
  govModalOpen.value = true;
}
function openGovEdit(r: GovRole) {
  govForm.value = {
    role_key: r.role_key, label: r.label, editing: true,
    assignee_id: r.assignee?.id ?? null, backup_id: r.backup?.id ?? null,
    department: r.department ?? "", responsibility: r.responsibility ?? "",
    escalation_authority: r.escalation_authority ?? "", approval_authority: r.approval_authority ?? "",
    start_date: r.start_date ?? "", end_date: r.end_date ?? "", contact_info: r.contact_info ?? "",
  };
  govModalOpen.value = true;
}
async function saveGovForm() {
  const f = govForm.value;
  if (!f.role_key) return;
  try {
    const res = await api<{ data: GovRole[]; vacant_mandatory: string[] }>("POST", `${base.value}/governance`, {
      org: org(), body: {
        role_key: f.role_key, assignee_employee_id: f.assignee_id, backup_employee_id: f.backup_id,
        department: f.department || null, responsibility: f.responsibility || null,
        escalation_authority: f.escalation_authority || null, approval_authority: f.approval_authority || null,
        start_date: f.start_date || null, end_date: f.end_date || null, contact_info: f.contact_info || null,
      },
    });
    governance.value = res.data;
    vacantMandatory.value = res.vacant_mandatory;
    govModalOpen.value = false;
  } catch (e) { fail(e, "Could not save the governance role."); }
}
async function removeGovRole(r: GovRole) {
  if (!r.id) { govModalOpen.value = false; return; }
  try {
    const res = await api<{ data: GovRole[]; vacant_mandatory: string[] }>("DELETE", `${base.value}/governance/${r.id}`, { org: org() });
    governance.value = res.data;
    vacantMandatory.value = res.vacant_mandatory;
    govModalOpen.value = false;
  } catch (e) { fail(e, "Could not remove the role."); }
}

// ── Customer team & stakeholders ─────────────────────────────────────────────
const newCustContact = ref({ first_name: "", last_name: "", title: "", email: "", phone: "", category: "other" });
const newStakeholder = ref({ name: "", persona: "", influence: "", interest: "", responsibility: "" });

async function loadCustomerTeam() {
  try { customerTeam.value = await api<CustomerTeam>("GET", `${base.value}/customer-team`, { org: org() }); }
  catch (e) { fail(e, "Could not load the customer team."); }
}
async function addCustomerContact() {
  if (!newCustContact.value.first_name.trim()) return;
  try {
    customerTeam.value = await api<CustomerTeam>("POST", `${base.value}/customer-team/contacts`, { org: org(), body: { ...newCustContact.value } });
    newCustContact.value = { first_name: "", last_name: "", title: "", email: "", phone: "", category: "other" };
  } catch (e) { fail(e, "Could not add the contact."); }
}
async function addStakeholder() {
  if (!newStakeholder.value.name.trim()) return;
  try {
    customerTeam.value = await api<CustomerTeam>("POST", `${base.value}/customer-team/stakeholders`, {
      org: org(), body: {
        name: newStakeholder.value.name, persona: newStakeholder.value.persona || null,
        influence: newStakeholder.value.influence || null, interest: newStakeholder.value.interest || null,
        responsibility: newStakeholder.value.responsibility || null,
      },
    });
    newStakeholder.value = { name: "", persona: "", influence: "", interest: "", responsibility: "" };
  } catch (e) { fail(e, "Could not add the stakeholder."); }
}
async function removeStakeholder(id: number) {
  try { customerTeam.value = await api<CustomerTeam>("DELETE", `${base.value}/customer-team/stakeholders/${id}`, { org: org() }); }
  catch (e) { fail(e, "Could not remove the stakeholder."); }
}

// ── Customer roles: list + modal ─────────────────────────────────────────────
const customerAssigned = computed(() => (customerTeam.value?.roles ?? []).filter((r) => r.present));
const customerAvailable = computed(() => (customerTeam.value?.roles ?? []).filter((r) => !r.present));
const custModalOpen = ref(false);
const custForm = ref({ role_key: "", label: "", editing: false, id: null as number | null, account_contact_id: null as number | null, responsibility: "" });
function openCustAdd() { custForm.value = { role_key: "", label: "", editing: false, id: null, account_contact_id: null, responsibility: "" }; custModalOpen.value = true; }
function openCustEdit(r: CustRole) { custForm.value = { role_key: r.role_key, label: r.label, editing: true, id: r.id, account_contact_id: r.account_contact_id, responsibility: r.responsibility ?? "" }; custModalOpen.value = true; }
async function saveCustForm() {
  const f = custForm.value; if (!f.role_key) return;
  try {
    customerTeam.value = await api<CustomerTeam>("POST", `${base.value}/customer-team/roles`, {
      org: org(), body: { role_key: f.role_key, account_contact_id: f.account_contact_id, responsibility: f.responsibility || null },
    });
    custModalOpen.value = false;
  } catch (e) { fail(e, "Could not save the customer role."); }
}
async function removeCustRole() {
  const f = custForm.value; if (!f.id) { custModalOpen.value = false; return; }
  try { customerTeam.value = await api<CustomerTeam>("DELETE", `${base.value}/customer-team/roles/${f.id}`, { org: org() }); custModalOpen.value = false; }
  catch (e) { fail(e, "Could not remove the role."); }
}
async function generateCustomerTemplate() {
  try { customerTeam.value = await api<CustomerTeam>("POST", `${base.value}/customer-team/roles/generate-template`, { org: org() }); }
  catch (e) { fail(e, "Could not generate the customer roles."); }
}

// ── Professional services ────────────────────────────────────────────────────
async function loadPS() {
  try { psData.value = await api<PsData>("GET", `${base.value}/professional-services`, { org: org() }); }
  catch (e) { fail(e, "Could not load professional services."); }
}
const blankPs = () => ({ id: null as number | null, editing: false, role: "", name: "", assignee_id: null as number | null, organization: "", description: "", contact_info: "", start_date: "", end_date: "" });
const psModalOpen = ref(false);
const psForm = ref(blankPs());
function openPsAdd() { psForm.value = blankPs(); psModalOpen.value = true; }
function openPsEdit(r: PsEntry) { psForm.value = { id: r.id, editing: true, role: r.role, name: r.name, assignee_id: null, organization: r.organization ?? "", description: r.description ?? "", contact_info: r.contact_info ?? "", start_date: r.start_date ?? "", end_date: r.end_date ?? "" }; psModalOpen.value = true; }
async function savePs() {
  const f = psForm.value; if (!f.role || !f.name.trim()) return;
  const body = { role: f.role, name: f.name, organization: f.organization || null, description: f.description || null, contact_info: f.contact_info || null, start_date: f.start_date || null, end_date: f.end_date || null };
  try {
    psData.value = f.editing && f.id
      ? await api<PsData>("PUT", `${base.value}/professional-services/${f.id}`, { org: org(), body })
      : await api<PsData>("POST", `${base.value}/professional-services`, { org: org(), body });
    psModalOpen.value = false;
  } catch (e) { fail(e, "Could not save the resource."); }
}
async function removePs() {
  const f = psForm.value; if (!f.id) { psModalOpen.value = false; return; }
  try { psData.value = await api<PsData>("DELETE", `${base.value}/professional-services/${f.id}`, { org: org() }); psModalOpen.value = false; }
  catch (e) { fail(e, "Could not remove the resource."); }
}

// ── Subcontractors ───────────────────────────────────────────────────────────
async function loadSubs() {
  try { subData.value = await api<SubData>("GET", `${base.value}/subcontractors`, { org: org() }); }
  catch (e) { fail(e, "Could not load subcontractors."); }
}
const blankSub = () => ({ id: null as number | null, editing: false, role: "", company: "", contact_person: "", contact_info: "", scope_of_work: "", contract_reference: "", approval_status: "pending", onboarding_status: "not_started", start_date: "", end_date: "" });
const subModalOpen = ref(false);
const subForm = ref(blankSub());
function openSubAdd() { subForm.value = blankSub(); subModalOpen.value = true; }
function openSubEdit(r: SubEntry) { subForm.value = { id: r.id, editing: true, role: r.role, company: r.company, contact_person: r.contact_person ?? "", contact_info: r.contact_info ?? "", scope_of_work: r.scope_of_work ?? "", contract_reference: r.contract_reference ?? "", approval_status: r.approval_status, onboarding_status: r.onboarding_status, start_date: r.start_date ?? "", end_date: r.end_date ?? "" }; subModalOpen.value = true; }
async function saveSub() {
  const f = subForm.value; if (!f.role || !f.company.trim()) return;
  const body = { role: f.role, company: f.company, contact_person: f.contact_person || null, contact_info: f.contact_info || null, scope_of_work: f.scope_of_work || null, contract_reference: f.contract_reference || null, approval_status: f.approval_status, onboarding_status: f.onboarding_status, start_date: f.start_date || null, end_date: f.end_date || null };
  try {
    subData.value = f.editing && f.id
      ? await api<SubData>("PUT", `${base.value}/subcontractors/${f.id}`, { org: org(), body })
      : await api<SubData>("POST", `${base.value}/subcontractors`, { org: org(), body });
    subModalOpen.value = false;
  } catch (e) { fail(e, "Could not save the subcontractor."); }
}
async function removeSub() {
  const f = subForm.value; if (!f.id) { subModalOpen.value = false; return; }
  try { subData.value = await api<SubData>("DELETE", `${base.value}/subcontractors/${f.id}`, { org: org() }); subModalOpen.value = false; }
  catch (e) { fail(e, "Could not remove the subcontractor."); }
}

// ── Reports ──────────────────────────────────────────────────────────────────
async function loadReports() {
  try { reports.value = await api<ProjectReports>("GET", `${base.value}/reports`, { org: org() }); }
  catch (e) { fail(e, "Could not load the reports."); }
}
async function loadTravelSchedule() {
  try { travelSchedule.value = (await api<{ cycles: TravelCycle[] }>("GET", `${base.value}/travel-schedule`, { org: org() })).cycles; }
  catch (e) { fail(e, "Could not load the travel schedule."); }
}
async function loadTravelRequests() {
  try {
    const res = await api<{ data: TravelReq[]; summary: typeof travelReqSummary.value }>("GET", `${base.value}/travel-requests`, { org: org() });
    travelReqs.value = res.data; travelReqSummary.value = res.summary;
  } catch (e) { fail(e, "Could not load travel requests."); }
}
async function loadResourcePlan() {
  try { resourcePlan.value = await api("GET", `${base.value}/resource-plan`, { org: org() }); }
  catch (e) { fail(e, "Could not load the resource plan."); }
}
async function loadApprovals() {
  try {
    const res = await api<{ data: ApprovalRow[]; summary: ApprovalSummary }>("GET", `${base.value}/approvals`, { org: org() });
    approvals.value = res.data; approvalSummary.value = res.summary;
  } catch (e) { fail(e, "Could not load approvals."); }
}
async function loadAlerts() {
  try {
    const res = await api<{ data: AlertRow[]; summary: AlertSummary }>("GET", `${base.value}/alerts`, { org: org() });
    alerts.value = res.data; alertSummary.value = res.summary;
  } catch (e) { fail(e, "Could not load alerts."); }
}
async function loadLogisticsSummary() {
  try { logisticsSummary.value = await api<LogisticsSummary>("GET", `${base.value}/logistics-summary`, { org: org() }); }
  catch (e) { fail(e, "Could not load the logistics summary."); }
}
async function loadTravelTips() {
  try {
    const res = await api<{ data: TravelTip[]; summary: TravelTipSummary }>("GET", `${base.value}/travel-optimization`, { org: org() });
    travelTips.value = res.data; travelTipSummary.value = res.summary;
  } catch (e) { fail(e, "Could not load travel tips."); }
}
async function decideApproval(r: ApprovalRow, status: string) {
  try {
    const res = await api<{ data: ApprovalRow[]; summary: ApprovalSummary }>("POST", `${base.value}/approvals/${r.kind}/${r.id}/decision`, { org: org(), body: { approval_status: status } });
    approvals.value = res.data; approvalSummary.value = res.summary;
    // Keep the underlying registers in step with the decision made here.
    if (r.kind === "travel") void loadTravelRequests();
    else void loadProcurement();
    void loadAlerts();
  } catch (e) { fail(e, "Could not record the decision."); }
}
async function generateTravelRequests() {
  try {
    const res = await api<{ data: TravelReq[]; summary: typeof travelReqSummary.value; created: number }>("POST", `${base.value}/travel-requests/generate`, { org: org() });
    travelReqs.value = res.data; travelReqSummary.value = res.summary;
    notifySaved(res.created ? `Generated ${res.created} travel request${res.created === 1 ? "" : "s"}.` : "All cycles already have a request.");
  } catch (e) { fail(e, "Could not generate travel requests."); }
}
async function loadTravelEstimate() {
  try { travelEstimateRates.value = (await api<{ rates: TravelEstimateRates }>("GET", `${base.value}/travel-estimate`, { org: org() })).rates; }
  catch { /* rate basis is best-effort context for the estimate button */ }
}
async function applyTravelEstimate() {
  try {
    const res = await api<{ applied: number; estimate: { rates: TravelEstimateRates; total: number } }>("POST", `${base.value}/travel-estimate/apply`, { org: org() });
    travelEstimateRates.value = res.estimate.rates;
    await loadTravelRequests();
    if (approvals.value) void loadApprovals();
    notifySaved(res.applied ? `Estimated ${res.applied} request${res.applied === 1 ? "" : "s"} from the rate card.` : "All requests already have a cost — nothing to estimate.");
  } catch (e) { fail(e, "Could not estimate travel costs."); }
}
async function setTravelReqCost(r: TravelReq, value: string) {
  try {
    const res = await api<{ data: TravelReq[]; summary: typeof travelReqSummary.value }>("PUT", `${base.value}/travel-requests/${r.id}`, { org: org(), body: { estimated_cost: value === "" ? null : Number(value) } });
    travelReqs.value = res.data; travelReqSummary.value = res.summary;
  } catch (e) { fail(e, "Could not update the cost."); }
}
async function decideTravelReq(r: TravelReq, status: string) {
  try {
    const res = await api<{ data: TravelReq[]; summary: typeof travelReqSummary.value }>("POST", `${base.value}/travel-requests/${r.id}/decision`, { org: org(), body: { approval_status: status } });
    travelReqs.value = res.data; travelReqSummary.value = res.summary;
    if (approvals.value) void loadApprovals();
  } catch (e) { fail(e, "Could not update the approval."); }
}
async function removeTravelReq(r: TravelReq) {
  try {
    const res = await api<{ data: TravelReq[]; summary: typeof travelReqSummary.value }>("DELETE", `${base.value}/travel-requests/${r.id}`, { org: org() });
    travelReqs.value = res.data; travelReqSummary.value = res.summary;
  } catch (e) { fail(e, "Could not remove the request."); }
}
async function raiseTravelInModule(r: TravelReq) {
  try {
    const res = await api<{ data: TravelReq[]; summary: typeof travelReqSummary.value; raised: { raised: number; submitted: number; existing: number } }>("POST", `${base.value}/travel-requests/${r.id}/raise-in-module`, { org: org() });
    travelReqs.value = res.data; travelReqSummary.value = res.summary;
    const g = res.raised;
    notifySaved(g.raised
      ? `Raised ${g.raised} traveller request${g.raised === 1 ? "" : "s"} in the Travel module — ${g.submitted} costed & submitted for approval.`
      : `Already raised (${g.existing}) in the Travel module.`);
  } catch (e) { fail(e, "Could not raise in the Travel module."); }
}
async function loadProcurement() {
  try {
    const res = await api<{ data: Proc[]; summary: typeof procSummary.value }>("GET", `${base.value}/procurement`, { org: org() });
    procurement.value = res.data; procSummary.value = res.summary;
  } catch (e) { fail(e, "Could not load procurement."); }
}
async function loadEngagements() {
  try { vendorEngagements.value = await api<EngagementData>("GET", `${base.value}/vendor-engagements`, { org: org() }); }
  catch (e) { fail(e, "Could not load vendor engagements."); }
}
async function updateEngagement(e: VendorEngagement, patch: Partial<VendorEngagement>) {
  try { vendorEngagements.value = await api<EngagementData>("PUT", `${base.value}/vendor-engagements/${e.id}`, { org: org(), body: patch }); }
  catch (err) { fail(err, "Could not update the engagement."); }
}
async function removeEngagement(e: VendorEngagement) {
  try { vendorEngagements.value = await api<EngagementData>("DELETE", `${base.value}/vendor-engagements/${e.id}`, { org: org() }); }
  catch (err) { fail(err, "Could not remove the engagement."); }
}
function engagementTypeLabel(t: string): string {
  return t === "external_vendor" ? "External vendor" : t === "professional_services" ? "Professional services" : "Subcontractor";
}
async function addProcurement() {
  const f = newProc.value;
  if (!f.item.trim()) return;
  try {
    const res = await api<{ data: Proc[]; summary: typeof procSummary.value }>("POST", `${base.value}/procurement`, {
      org: org(), body: {
        item: f.item, category: f.category || null, quantity: Number(f.quantity) || 1, vendor: f.vendor || null,
        required_by: f.required_by || null, lead_time_days: f.lead_time_days === "" ? null : Number(f.lead_time_days),
        estimated_cost: f.estimated_cost === "" ? null : Number(f.estimated_cost),
      },
    });
    procurement.value = res.data; procSummary.value = res.summary;
    newProc.value = { item: "", category: "", quantity: 1, vendor: "", required_by: "", lead_time_days: "", estimated_cost: "" };
  } catch (e) { fail(e, "Could not add the item."); }
}
async function decideProc(p: Proc, status: string) {
  try {
    await api("POST", `/api/v1/procurement/${p.id}/decision`, { org: org(), body: { approval_status: status } });
    await loadProcurement();
    if (approvals.value) void loadApprovals();
  } catch (e) { fail(e, "Could not update the approval."); }
}
async function removeProc(p: Proc) {
  try { await api("DELETE", `/api/v1/procurement/${p.id}`, { org: org() }); await loadProcurement(); }
  catch (e) { fail(e, "Could not remove the item."); }
}
function printReports() { window.print(); }

// ── Register: issues, dependencies, risks ────────────────────────────────────
const newIssue = ref({ title: "", severity: "medium" });
async function addIssue() {
  if (!newIssue.value.title.trim()) return;
  try {
    const res = await api<{ data: Issue }>("POST", `${base.value}/issues`, { org: org(), body: { title: newIssue.value.title.trim(), severity: newIssue.value.severity } });
    issues.value.unshift(res.data);
    newIssue.value = { title: "", severity: "medium" };
  } catch (e) { fail(e, "Could not add the issue."); }
}
async function setIssueStatus(it: Issue, status: string) {
  try {
    const res = await api<{ data: Issue }>("PUT", `${base.value}/issues/${it.id}`, { org: org(), body: { status } });
    Object.assign(it, res.data);
  } catch (e) { fail(e, "Could not update the issue."); }
}
async function deleteIssue(id: number) {
  try { await api("DELETE", `${base.value}/issues/${id}`, { org: org() }); issues.value = issues.value.filter((x) => x.id !== id); }
  catch (e) { fail(e, "Could not remove the issue."); }
}

const newDep = ref({ title: "", kind: "external", due_on: "" });
async function addProjectDep() {
  if (!newDep.value.title.trim()) return;
  try {
    const res = await api<{ data: Dependency }>("POST", `${base.value}/dependencies`, {
      org: org(), body: { title: newDep.value.title.trim(), kind: newDep.value.kind, due_on: newDep.value.due_on || null },
    });
    dependencies.value.unshift(res.data);
    newDep.value = { title: "", kind: "external", due_on: "" };
  } catch (e) { fail(e, "Could not add the dependency."); }
}
async function setDepStatus(d: Dependency, status: string) {
  try {
    const res = await api<{ data: Dependency }>("PUT", `${base.value}/dependencies/${d.id}`, { org: org(), body: { status } });
    Object.assign(d, res.data);
  } catch (e) { fail(e, "Could not update the dependency."); }
}
async function setDepOwner(d: Dependency, employeeId: number | null) {
  try {
    const res = await api<{ data: Dependency }>("PUT", `${base.value}/dependencies/${d.id}`, { org: org(), body: { owner_employee_id: employeeId } });
    Object.assign(d, res.data);
  } catch (e) { fail(e, "Could not set the owner."); }
}
async function deleteDependency(id: number) {
  try { await api("DELETE", `${base.value}/dependencies/${id}`, { org: org() }); dependencies.value = dependencies.value.filter((x) => x.id !== id); }
  catch (e) { fail(e, "Could not remove the dependency."); }
}

const newRisk = ref({ title: "", likelihood: "medium", impact: "medium" });
async function addRisk() {
  if (!newRisk.value.title.trim()) return;
  try {
    const res = await api<{ data: Risk }>("POST", `${base.value}/risks`, {
      org: org(), body: { title: newRisk.value.title.trim(), likelihood: newRisk.value.likelihood, impact: newRisk.value.impact },
    });
    risks.value.unshift(res.data);
    newRisk.value = { title: "", likelihood: "medium", impact: "medium" };
  } catch (e) { fail(e, "Could not add the risk."); }
}
async function updateRisk(r: Risk, body: Record<string, unknown>) {
  try {
    const res = await api<{ data: Risk }>("PUT", `${base.value}/risks/${r.id}`, { org: org(), body });
    Object.assign(r, res.data);
  } catch (e) { fail(e, "Could not update the risk."); }
}
async function deleteRisk(id: number) {
  try { await api("DELETE", `${base.value}/risks/${id}`, { org: org() }); risks.value = risks.value.filter((x) => x.id !== id); }
  catch (e) { fail(e, "Could not remove the risk."); }
}
// Risk detail: association (task/phase) + evidence
const openRisk = ref<number | null>(null);
const riskEvUploading = ref<number | null>(null);
function toggleRisk(r: Risk) {
  openRisk.value = openRisk.value === r.id ? null : r.id;
  if (openRisk.value === r.id) { if (!tasks.value.length) void loadTasks(); if (!phases.value.length) void loadPhases(); }
}
async function uploadRiskEvidence(r: Risk, e: Event) {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  riskEvUploading.value = r.id;
  try {
    const body = new FormData();
    body.append("file", file);
    const res = await api<{ data: Risk }>("POST", `${base.value}/risks/${r.id}/evidence`, { org: org(), body });
    Object.assign(r, res.data);
  } catch (err) { fail(err, "Could not attach the evidence."); }
  finally { riskEvUploading.value = null; input.value = ""; }
}
async function deleteRiskEvidence(r: Risk, id: number) {
  try { Object.assign(r, (await api<{ data: Risk }>("DELETE", `${base.value}/risks/${r.id}/evidence/${id}`, { org: org() })).data); }
  catch (e) { fail(e, "Could not remove the evidence."); }
}
function riskEvidenceUrl(r: Risk, id: number): string {
  const root = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");
  return `${root}${base.value}/risks/${r.id}/evidence/${id}/download?org=${org()}`;
}

// ── Delivery milestones ──────────────────────────────────────────────────────
const newDelivery = ref({ title: "", target_date: "" });
async function addDelivery() {
  if (!newDelivery.value.title.trim()) return;
  try {
    const res = await api<{ data: DeliveryMilestone }>("POST", `${base.value}/delivery-milestones`, {
      org: org(), body: { title: newDelivery.value.title.trim(), target_date: newDelivery.value.target_date || null },
    });
    deliveryMilestones.value.push(res.data);
    newDelivery.value = { title: "", target_date: "" };
  } catch (e) { fail(e, "Could not add the milestone."); }
}
async function updateDelivery(m: DeliveryMilestone, body: Record<string, unknown>) {
  try {
    const res = await api<{ data: DeliveryMilestone }>("PUT", `${base.value}/delivery-milestones/${m.id}`, { org: org(), body });
    Object.assign(m, res.data);
  } catch (e) { fail(e, "Could not update the milestone."); }
}
async function deleteDelivery(id: number) {
  try { await api("DELETE", `${base.value}/delivery-milestones/${id}`, { org: org() }); deliveryMilestones.value = deliveryMilestones.value.filter((x) => x.id !== id); }
  catch (e) { fail(e, "Could not remove the milestone."); }
}

// ── Budget: budget lines, expenses, vendors ──────────────────────────────────
const newBudget  = ref({ label: "", category: "", currency: "KES", amount: "" });
const newExpense = ref({ label: "", category: "", currency: "KES", amount: "", spent_on: "", task_id: "", travel_request_id: "" });
async function addBudgetLine() {
  if (!newBudget.value.label.trim() || newBudget.value.amount === "") return;
  try {
    finance.value = await api<FinanceData>("POST", `${base.value}/finance/budget-lines`, {
      org: org(), body: {
        label: newBudget.value.label.trim(), category: newBudget.value.category || null,
        currency: newBudget.value.currency.toUpperCase(), amount: Number(newBudget.value.amount),
      },
    });
    newBudget.value = { label: "", category: newBudget.value.category, currency: newBudget.value.currency, amount: "" };
    void reloadAnalytics();
  } catch (e) { fail(e, "Could not add the budget line."); }
}
async function deleteBudgetLine(id: number) {
  try { finance.value = await api<FinanceData>("DELETE", `${base.value}/finance/budget-lines/${id}`, { org: org() }); void reloadAnalytics(); }
  catch (e) { fail(e, "Could not remove the budget line."); }
}
async function generateBudget() {
  try {
    const res = await api<FinanceData & { generated: { created: number; updated: number; removed: number } }>("POST", `${base.value}/finance/budget-lines/generate`, { org: org() });
    finance.value = res;
    const g = res.generated;
    const bits = [g.created ? `${g.created} added` : "", g.updated ? `${g.updated} updated` : "", g.removed ? `${g.removed} removed` : ""].filter(Boolean);
    notifySaved(bits.length ? `Budget generated — ${bits.join(", ")}.` : "Budget already up to date with the plan.");
    void reloadAnalytics();
  } catch (e) { fail(e, "Could not generate the budget."); }
}
async function addExpense() {
  if (!newExpense.value.label.trim() || newExpense.value.amount === "") return;
  try {
    finance.value = await api<FinanceData>("POST", `${base.value}/finance/expenses`, {
      org: org(), body: {
        label: newExpense.value.label.trim(), category: newExpense.value.category || null,
        currency: newExpense.value.currency.toUpperCase(),
        amount: Number(newExpense.value.amount), spent_on: newExpense.value.spent_on || null,
        task_id: newExpense.value.task_id ? Number(newExpense.value.task_id) : null,
        travel_request_id: newExpense.value.travel_request_id ? Number(newExpense.value.travel_request_id) : null,
      },
    });
    newExpense.value = { label: "", category: newExpense.value.category, currency: newExpense.value.currency, amount: "", spent_on: "", task_id: "", travel_request_id: "" };
    void reloadAnalytics();
  } catch (e) { fail(e, "Could not add the expense."); }
}
async function deleteExpense(id: number) {
  try { finance.value = await api<FinanceData>("DELETE", `${base.value}/finance/expenses/${id}`, { org: org() }); void reloadAnalytics(); }
  catch (e) { fail(e, "Could not remove the expense."); }
}

const newVendor = ref({ vendor_partner_id: "", name: "" });
async function addVendor() {
  const partnerId = newVendor.value.vendor_partner_id ? Number(newVendor.value.vendor_partner_id) : null;
  if (!partnerId && !newVendor.value.name.trim()) return;
  try {
    const res = await api<{ data: Vendor }>("POST", `${base.value}/vendors`, {
      org: org(), body: { vendor_partner_id: partnerId, name: newVendor.value.name.trim() || null },
    });
    vendors.value?.data.push(res.data);
    newVendor.value = { vendor_partner_id: "", name: "" };
  } catch (e) { fail(e, "Could not add the vendor."); }
}
async function deleteVendor(id: number) {
  try { await api("DELETE", `${base.value}/vendors/${id}`, { org: org() }); if (vendors.value) vendors.value.data = vendors.value.data.filter((x) => x.id !== id); }
  catch (e) { fail(e, "Could not remove the vendor."); }
}
function fmtMoney(n: number, ccy: string): string {
  return `${ccy} ${n.toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
}

// ── Details / Charter ─────────────────────────────────────────────────────────

const blankCharter = () => ({
  description: "", scope: "", charter: "", pip: "", product: "", project_email: "",
  execution_start: "", execution_end: "",
  charter_completion_date: "", contract_completion_date: "",
  vendor_partner_id: null as number | null,
  project_lead_id: null as number | null,
  client_contact_id: null as number | null,
  client_sponsor_contact_id: null as number | null,
});
const form = ref(blankCharter());

/** Copy the loaded project onto the editable charter form. */
function syncCharterForm() {
  const p = project.value;
  if (!p) return;
  form.value = {
    description: p.description ?? "", scope: p.scope ?? "", charter: p.charter ?? "",
    pip: p.pip ?? "", product: p.product ?? "", project_email: p.project_email ?? "",
    execution_start: p.execution_start ?? "", execution_end: p.execution_end ?? "",
    charter_completion_date: p.charter_completion_date ?? "",
    contract_completion_date: p.contract_completion_date ?? "",
    vendor_partner_id: p.delivery?.vendor_partner?.id ?? null,
    project_lead_id: p.project_lead?.id ?? null,
    client_contact_id: p.client_contact_id ?? null,
    client_sponsor_contact_id: p.client_sponsor_contact_id ?? null,
  };
}

const clientContacts = computed<AccountContact[]>(() => project.value?.account?.contacts ?? []);

/** The three charter sections that each carry a summary and a document. */
const charterDocs = [
  { type: "scope", field: "scope", title: "Scope", hint: "What is in and out of scope for the project." },
  { type: "charter", field: "charter", title: "Charter", hint: "Objectives, governance, and success criteria." },
  { type: "pip", field: "pip", title: "PIP", hint: "Project implementation plan — approach, phases, dependencies." },
] as const;

// ── Project documents (charter set) ───────────────────────────────────────────
const documents = ref<ProjectDoc[]>([]);
const docUploading = ref<string | null>(null);
async function loadDocuments() {
  try {
    documents.value = (await api<{ data: ProjectDoc[] }>("GET", `${base.value}/documents`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load the project documents."); }
}
function docsOfType(type: string): ProjectDoc[] {
  return documents.value.filter((d) => d.document_type === type);
}
async function uploadDoc(type: string, e: Event) {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  docUploading.value = type;
  try {
    const body = new FormData();
    body.append("file", file);
    body.append("document_type", type);
    await api("POST", `${base.value}/attachments`, { org: org(), body });
    await loadDocuments();
    notifySaved("Document attached.");
  } catch (err) { fail(err, "Could not attach the document."); }
  finally { docUploading.value = null; input.value = ""; }
}
async function deleteDoc(id: number) {
  try { await api("DELETE", `${base.value}/attachments/${id}`, { org: org() }); documents.value = documents.value.filter((d) => d.id !== id); }
  catch (e) { fail(e, "Could not remove the document."); }
}
function docUrl(id: number): string {
  const root = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");
  return `${root}${base.value}/attachments/${id}/download?org=${org()}`;
}

async function saveDetails() {
  if (!project.value) return;
  saving.value = true;
  try {
    const f = form.value;
    project.value = (await api<{ data: Project }>("PUT", base.value, {
      org: org(),
      body: {
        name: project.value.name,
        status: project.value.status, priority: project.value.priority,
        deadline: project.value.deadline,
        description: f.description || null,
        scope: f.scope || null, charter: f.charter || null, pip: f.pip || null,
        product: f.product || null, project_email: f.project_email || null,
        execution_start: f.execution_start || null, execution_end: f.execution_end || null,
        charter_completion_date: f.charter_completion_date || null,
        contract_completion_date: f.contract_completion_date || null,
        vendor_partner_id: f.vendor_partner_id, project_lead_id: f.project_lead_id,
        client_contact_id: f.client_contact_id,
        client_sponsor_contact_id: f.client_sponsor_contact_id,
      },
    })).data;
    syncCharterForm();
    notifySaved("Charter saved.");
  } catch (e) { fail(e, "Could not save."); }
  finally { saving.value = false; }
}

// ── Tasks ───────────────────────────────────────────────────────────────────

const openTask = ref<Task | null>(null);
interface TaskAssignee { employee_id: number; name: string; capacity: "assignee" | "assisting" }
const NEED_FLAGS = [
  { key: "needs_travel", label: "Travel" },
  { key: "needs_client_access", label: "Client site access required" },
  { key: "needs_accommodation", label: "Accommodation required" },
  { key: "needs_asset", label: "Asset / equipment required" },
  { key: "needs_software", label: "Software / license required" },
  { key: "needs_procurement", label: "Procurement required" },
  { key: "needs_external_vendors", label: "External vendor required" },
  { key: "needs_professional_services", label: "Professional services required" },
  { key: "needs_subcontractors", label: "Contractor / subcontractor required" },
] as const;
const blankLogistics = () => ({
  needs_travel: false, needs_client_access: false, needs_accommodation: false,
  needs_asset: false, needs_software: false, needs_procurement: false,
  needs_external_vendors: false, needs_professional_services: false, needs_subcontractors: false,
  travel_location: "", travel_mode: "",
  details: "", requires_approval: false,
});
const blankTask = () => ({
  id: null as number | null, title: "", description: "",
  status: "todo", priority: "medium",
  started_on: "", deadline: "", parent_task_id: null as number | null,
  weekly_okr: false, effort_estimate_days: "" as string | number,
  phase_id: null as number | null, subphase_id: null as number | null,
  role_id: null as number | null,
  assignees: [] as TaskAssignee[],
  depends_on_task_ids: [] as number[],
  logistics: blankLogistics(),
});
const taskForm = ref(blankTask());

// Filter the task list by requirement — the reportable, filterable view a PM
// uses for procurement and resource planning. Client-side, since every task
// already carries its logistics.
const logiFilter = ref("");
const filteredTasks = computed(() => {
  if (!logiFilter.value) return tasks.value;
  return tasks.value.filter((t) => {
    const l = t.logistics;
    if (!l) return false;
    return logiFilter.value === "approval" ? l.requires_approval : (l as Record<string, unknown>)[logiFilter.value];
  });
});
const needLabels = (l: TaskLogistics | null | undefined) =>
  l ? NEED_FLAGS.filter((f) => (l as unknown as Record<string, unknown>)[f.key]).map((f) => f.label) : [];
const showTask = ref(false);

/** Make sure the pickers (phases, roles, tasks) have data to offer. */
function ensureTaskPickerData() {
  if (!phases.value.length) void loadPhases();
  if (!team.value) void loadTeam();
  if (!tasks.value.length) void loadTasks();
}

function newTask(parentId: number | null = null) {
  taskForm.value = { ...blankTask(), parent_task_id: parentId };
  openTask.value = null;
  ensureTaskPickerData();
  showTask.value = true;
}

const generatingPm = ref(false);
async function generatePmActivities() {
  generatingPm.value = true;
  try {
    const res = await api<{ data: Task[]; created: number }>("POST", `${base.value}/tasks/generate-pm-activities`, { org: org() });
    tasks.value = res.data;
    await load(); // progress may have moved
    notifySaved(res.created ? `Added ${res.created} standard PM ${res.created === 1 ? "activity" : "activities"}.` : "All PM activities are already present.");
  } catch (e) { fail(e, "Could not generate the PM activities."); }
  finally { generatingPm.value = false; }
}

// ── Enriched creator: phases, role-based assignment, dependencies ────────────
/** Sub-phases available under the chosen top-level phase. */
const subphaseOptions = computed(() => phases.value.find((p) => p.id === taskForm.value.phase_id)?.children ?? []);
/** Engineers configured on the chosen role. */
const roleEngineers = computed(() => team.value?.roles.find((r) => r.id === taskForm.value.role_id)?.members ?? []);

function toggleAssignee(employeeId: number, name: string) {
  const list = taskForm.value.assignees;
  const i = list.findIndex((a) => a.employee_id === employeeId);
  if (i >= 0) { list.splice(i, 1); return; }
  // First person picked carries primary responsibility; the rest assist.
  const capacity = list.some((a) => a.capacity === "assignee") ? "assisting" : "assignee";
  list.push({ employee_id: employeeId, name, capacity });
}
function isAssignee(employeeId: number): boolean {
  return taskForm.value.assignees.some((a) => a.employee_id === employeeId);
}
function setResponsibility(employeeId: number, capacity: "assignee" | "assisting") {
  const a = taskForm.value.assignees.find((x) => x.employee_id === employeeId);
  if (a) a.capacity = capacity;
}
function toggleTaskDependency(taskId: number) {
  const list = taskForm.value.depends_on_task_ids;
  const i = list.indexOf(taskId);
  if (i >= 0) list.splice(i, 1); else list.push(taskId);
}

async function saveTask() {
  if (!taskForm.value.title.trim()) return;
  saving.value = true;
  const f = taskForm.value;
  const { id, subphase_id, role_id, phase_id, assignees, depends_on_task_ids, logistics, ...raw } = f;
  const body: Record<string, unknown> = {
    ...raw,
    started_on: f.started_on || null,
    deadline: f.deadline || null,
    description: f.description || null,
    // A sub-phase, when chosen, is the phase the work actually sits under.
    phase_id: subphase_id ?? phase_id,
    effort_estimate_days: f.effort_estimate_days === "" ? null : Number(f.effort_estimate_days),
    // Requirements & logistics — always sent (the section is mandatory).
    logistics: { ...logistics, details: logistics.details || null },
  };
  // Assignment and prerequisites are only set when creating.
  if (!id) {
    if (assignees.length) body.assignees = assignees.map((a) => ({ employee_id: a.employee_id, capacity: a.capacity }));
    if (depends_on_task_ids.length) body.depends_on_task_ids = depends_on_task_ids;
  }
  try {
    const res = await api<{ data: Task }>(
      id ? "PUT" : "POST", id ? `${base.value}/tasks/${id}` : `${base.value}/tasks`,
      { org: org(), body },
    );
    showTask.value = false;
    await Promise.all([loadTasks(), load()]);
    openTask.value = res.data;
    notifySaved(id ? "Task updated." : "Task added.");
  } catch (e) { fail(e, "Could not save the task."); }
  finally { saving.value = false; }
}

/** Ticking the box is one gesture, so it goes through the same update. */
async function toggleDone(t: Task, event?: Event) {
  // Finishing a top-level task now needs a word about what came of it. Rather
  // than bouncing the user to the Tasks page with a validation error, the same
  // form is asked for here.
  if (!t.is_done && !t.parent_task_id) {
    // The box ticked itself on click, but nothing is finished until the summary
    // is written — so put it back, or cancelling would leave a tick behind that
    // says the task is done when it is not.
    if (event) (event.target as HTMLInputElement).checked = false;
    openClosure(t);
    return;
  }

  try {
    await api("PUT", `${base.value}/tasks/${t.id}`, {
      org: org(),
      body: { title: t.title, status: t.is_done ? "in_progress" : "completed" },
    });
    await Promise.all([loadTasks(), load()]);
    if (openTask.value?.id === t.id) void reopenDrawer(t.id);
  } catch (e) { fail(e, "Could not change the task."); }
}

// ── Closing a task off ──────────────────────────────────────────────────────
/*
 * A completion date on its own tells a project review nothing. The summary is
 * captured against the task itself — the same record the Tasks page writes, so
 * the two doors cannot disagree about what a finished task carries.
 */
const closingTask = ref<Task | null>(null);
const closureError = ref("");
const closureForm = ref({
  work_completed: "", deliverables: "", challenges: "", outcomes: "", lessons_learned: "",
});

const CLOSURE_FIELDS = [
  { key: "work_completed", label: "Work completed", hint: "What was actually done.", required: true },
  { key: "deliverables",   label: "Deliverables achieved", hint: "What was handed over.", required: true },
  { key: "challenges",     label: "Challenges encountered", hint: "What got in the way — write “none” if nothing did.", required: true },
  { key: "outcomes",       label: "Key outcomes", hint: "What it achieved.", required: true },
  { key: "lessons_learned", label: "Lessons learned", hint: "Optional — only if there is one.", required: false },
] as const;

function openClosure(t: Task) {
  closureForm.value = { work_completed: "", deliverables: "", challenges: "", outcomes: "", lessons_learned: "" };
  closureError.value = "";
  closingTask.value = t;
}

const closureReady = computed(() =>
  CLOSURE_FIELDS.filter((f) => f.required)
    .every((f) => closureForm.value[f.key as keyof typeof closureForm.value].trim().length > 0));

async function submitClosure() {
  if (!closureReady.value || !closingTask.value) return;
  const f = closureForm.value;
  closureError.value = "";
  saving.value = true;
  try {
    // The task-scoped endpoint, not the project-nested one: the summary belongs
    // to the task wherever it is finished from.
    await api("POST", `/api/v1/tasks/${closingTask.value.id}/closure`, {
      org: org(),
      body: { ...f, lessons_learned: f.lessons_learned.trim() || null },
    });
    const id = closingTask.value.id;
    closingTask.value = null;
    await Promise.all([loadTasks(), load()]);
    if (openTask.value?.id === id) void reopenDrawer(id);
    notifySaved("Closed off.");
  } catch (e) {
    closureError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not close the task.";
  } finally { saving.value = false; }
}

const PREREQ_STATUSES = [
  { key: "open", label: "Open" },
  { key: "in_progress", label: "In progress" },
  { key: "blocked", label: "Blocked" },
  { key: "done", label: "Done" },
];
async function updatePrerequisite(p: Prerequisite, status: string) {
  if (!openTask.value) return;
  try {
    await api("PUT", `${base.value}/tasks/${openTask.value.id}/prerequisites/${p.id}`, { org: org(), body: { status } });
    await reopenDrawer(openTask.value.id);
  } catch (e) { fail(e, "Could not update the prerequisite."); }
}
async function raisePrerequisite(p: Prerequisite) {
  if (!openTask.value) return;
  try {
    await api("POST", `${base.value}/tasks/${openTask.value.id}/prerequisites/${p.id}/raise`, { org: org() });
    await reopenDrawer(openTask.value.id);
    notifySaved("Record raised and linked to this requirement.");
    // Refresh the registers this may have added to, if they're already loaded.
    if (procurement.value) void loadProcurement();
    if (vendorEngagements.value) void loadEngagements();
    if (approvals.value) void loadApprovals();
  } catch (e) { fail(e, "Could not raise the record."); }
}

async function reopenDrawer(id: number) {
  try {
    openTask.value = (await api<{ data: Task }>("GET", `${base.value}/tasks/${id}`, { org: org() })).data;
  } catch (e) { fail(e, "Could not load the task."); }
}

function editTask(t: Task) {
  taskForm.value = {
    ...blankTask(),
    id: t.id, title: t.title, description: t.description ?? "",
    status: t.status, priority: t.priority,
    started_on: t.started_on ?? "", deadline: t.deadline ?? "",
    parent_task_id: t.parent_task_id, weekly_okr: t.weekly_okr ?? false,
    effort_estimate_days: t.effort_estimate_days ?? "",
    phase_id: t.phase_id ?? null,
    logistics: t.logistics ? {
      needs_travel: t.logistics.needs_travel, needs_client_access: t.logistics.needs_client_access,
      needs_accommodation: t.logistics.needs_accommodation, needs_asset: t.logistics.needs_asset,
      needs_software: t.logistics.needs_software, needs_procurement: t.logistics.needs_procurement,
      needs_external_vendors: t.logistics.needs_external_vendors,
      needs_professional_services: t.logistics.needs_professional_services,
      needs_subcontractors: t.logistics.needs_subcontractors,
      travel_location: t.logistics.travel_location ?? "", travel_mode: t.logistics.travel_mode ?? "",
      details: t.logistics.details ?? "", requires_approval: t.logistics.requires_approval,
    } : blankLogistics(),
  };
  ensureTaskPickerData();
  showTask.value = true;
}

// ── Task members and dependencies ───────────────────────────────────────────

const newMember = ref({ employee_id: null as number | null, capacity: "assignee" });

async function addMember() {
  if (!openTask.value || !newMember.value.employee_id) return;
  saving.value = true;
  try {
    openTask.value = (await api<{ data: Task }>("POST", `${base.value}/tasks/${openTask.value.id}/members`, {
      org: org(), body: { ...newMember.value },
    })).data;
    newMember.value = { employee_id: null, capacity: "assignee" };
    await loadTasks();
  } catch (e) { fail(e, "Could not add them."); }
  finally { saving.value = false; }
}

async function removeMember(m: Member) {
  if (!openTask.value) return;
  try {
    openTask.value = (await api<{ data: Task }>(
      "DELETE", `${base.value}/tasks/${openTask.value.id}/members/${m.id}`, { org: org() })).data;
    await loadTasks();
  } catch (e) { fail(e, "Could not remove them."); }
}

const newDependency = ref<number | null>(null);

/** Everything else on the project, flattened — a task can wait on a sub-task. */
const dependencyOptions = computed(() => {
  const flat: Task[] = [];
  const walk = (list: Task[]) => list.forEach((t) => { flat.push(t); walk(t.subtasks ?? []); });
  walk(tasks.value);
  return flat.filter((t) => t.id !== openTask.value?.id);
});

async function addDependency() {
  if (!openTask.value || !newDependency.value) return;
  saving.value = true;
  try {
    openTask.value = (await api<{ data: Task }>("POST", `${base.value}/tasks/${openTask.value.id}/dependencies`, {
      org: org(), body: { depends_on_task_id: newDependency.value },
    })).data;
    newDependency.value = null;
  } catch (e) { fail(e, "Could not add that dependency."); }
  finally { saving.value = false; }
}

async function removeDependency(id: number) {
  if (!openTask.value) return;
  try {
    openTask.value = (await api<{ data: Task }>(
      "DELETE", `${base.value}/tasks/${openTask.value.id}/dependencies/${id}`, { org: org() })).data;
  } catch (e) { fail(e, "Could not remove that dependency."); }
}

// ── Milestones ──────────────────────────────────────────────────────────────

const showMilestone = ref(false);
const blankMilestone = () => ({
  name: "", value: null as number | null, currency: "",
  due_on: "", description: "",
  task_ids: [] as number[], phase_ids: [] as number[],
});
const milestoneForm = ref(blankMilestone());

/** Open the create dialog, making sure tasks and phases are on hand to pick. */
async function openMilestoneDialog() {
  milestoneForm.value = blankMilestone();
  if (!tasks.value.length) void loadTasks();
  if (!phases.value.length) void loadPhases();
  showMilestone.value = true;
}

async function saveMilestone() {
  if (!milestoneForm.value.name.trim() || milestoneForm.value.value === null) return;
  saving.value = true;
  try {
    await api("POST", `${base.value}/milestones`, {
      org: org(),
      body: {
        name: milestoneForm.value.name,
        value: milestoneForm.value.value,
        currency: milestoneForm.value.currency || undefined,
        due_on: milestoneForm.value.due_on || null,
        description: milestoneForm.value.description || null,
        task_ids: milestoneForm.value.task_ids,
        phase_ids: milestoneForm.value.phase_ids,
      },
    });
    showMilestone.value = false;
    milestoneForm.value = blankMilestone();
    await loadMilestones();
    notifySaved("Milestone added.");
  } catch (e) { fail(e, "Could not add the milestone."); }
  finally { saving.value = false; }
}

// ── Milestone proof of delivery ──────────────────────────────────────────────
const proofUploading = ref<number | null>(null);
async function attachProof(m: Milestone, e: Event) {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  proofUploading.value = m.id;
  try {
    const body = new FormData();
    body.append("file", file);
    const res = await api<{ data: Milestone }>("POST", `${base.value}/milestones/${m.id}/proofs`, { org: org(), body });
    Object.assign(m, res.data);
    notifySaved("Proof attached.");
  } catch (err) { fail(err, "Could not attach the proof."); }
  finally { proofUploading.value = null; input.value = ""; }
}
async function deleteProof(m: Milestone, proofId: number) {
  try {
    const res = await api<{ data: Milestone }>("DELETE", `${base.value}/milestones/${m.id}/proofs/${proofId}`, { org: org() });
    Object.assign(m, res.data);
  } catch (e) { fail(e, "Could not remove the proof."); }
}
function proofUrl(m: Milestone, proofId: number): string {
  const root = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");
  return `${root}${base.value}/milestones/${m.id}/proofs/${proofId}/download?org=${org()}`;
}

async function transition(m: Milestone, status: string) {
  try {
    await api("POST", `${base.value}/milestones/${m.id}/transition`, { org: org(), body: { status } });
    await loadMilestones();
    notifySaved(`Marked ${status}.`);
  } catch (e) { fail(e, "Could not update the milestone."); }
}

// ── Comments and resources ──────────────────────────────────────────────────

const newComment = ref("");
const replyTo    = ref<Comment | null>(null);

async function postComment() {
  if (!newComment.value.trim()) return;
  saving.value = true;
  try {
    comments.value = (await api<{ data: Comment[] }>("POST", `${base.value}/comments`, {
      org: org(),
      body: { body: newComment.value, parent_id: replyTo.value?.id ?? null },
    })).data;
    newComment.value = "";
    replyTo.value = null;
  } catch (e) { fail(e, "Could not post that."); }
  finally { saving.value = false; }
}

const uploading = ref(false);

async function upload(event: Event) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;

  uploading.value = true;
  try {
    const body = new FormData();
    body.append("file", file);
    if (openTask.value) body.append("project_task_id", String(openTask.value.id));

    await api("POST", `${base.value}/attachments`, { org: org(), body });
    if (openTask.value) await reopenDrawer(openTask.value.id);
    notifySaved("Resource attached.");
  } catch (e) { fail(e, "Could not attach the resource."); }
  finally { uploading.value = false; input.value = ""; }
}

function downloadUrl(d: Doc): string {
  const root = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");
  return `${root}${base.value}/attachments/${d.id}/download?org=${org()}`;
}

// ── Presentation ────────────────────────────────────────────────────────────

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

const titleCase = (v: string | null) =>
  v ? v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase()) : "—";

const initials = (name: string | null | undefined) =>
  (name ?? "?").split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

const kb = (b: number | null) => (b === null ? "" : `${Math.round(b / 1024)} KB`);

function money(m: Milestone): string {
  if (!commercialsVisible.value) return "•••";
  return m.value === null ? "—" : `${m.currency} ${Number(m.value).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

function ago(iso: string | null): string {
  if (!iso) return "";
  const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  const steps: [number, Intl.RelativeTimeFormatUnit][] =
    [[60, "second"], [3600, "minute"], [86400, "hour"], [604800, "day"], [2629800, "week"], [31557600, "month"]];
  const fmt = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });
  let prev = 1;
  for (const [limit, unit] of steps) {
    if (secs < limit) return fmt.format(-Math.floor(secs / prev), unit);
    prev = limit;
  }
  return fmt.format(-Math.floor(secs / 31557600), "year");
}

const statusTone = (s: string) =>
  s === "completed" ? "ok" : s === "awaiting_approval" ? "warn" : s === "blocked" ? "danger" : "info";

onMounted(() => { load(); loadPmoDepartment(); });
</script>

<template>
  <div class="pd-wrap">
    <nav class="pd-crumbs">
      <button class="pd-crumb" @click="router.push({ name: 'projects' })">Projects</button>
      <span class="pd-sep">›</span>
      <span class="pd-current">{{ project?.reference ?? "…" }}</span>
    </nav>

    <div v-if="loading" class="pd-loading">Loading…</div>

    <template v-else-if="project">
      <header class="pd-head">
        <div>
          <h1 class="pd-title">{{ project.name }}</h1>
          <p class="pd-byline">
            Created {{ date(project.created_at) }}
            <template v-if="project.account"> · for {{ project.account.name }}</template>
          </p>
        </div>
        <div v-if="project.progress !== null" class="pd-progress">
          <span class="pd-progress-value">{{ project.progress }}%</span>
          <span class="pd-progress-label">complete</span>
        </div>
      </header>

      <div class="pd-facts-row">
        <dl class="pd-facts">
          <div><dt>Deadline</dt>
            <dd :class="project.is_overdue ? 'pd-late' : ''">
              📅 {{ date(project.deadline) }}
              <span v-if="project.is_overdue" class="pd-late">· overdue</span>
              <span v-else-if="project.was_late" class="pd-late">· delivered late</span>
            </dd></div>
          <div><dt>Status</dt><dd class="pd-status">{{ titleCase(project.status) }}</dd></div>
          <div><dt>Priority</dt>
            <dd :class="project.priority === 'high' ? 'pd-late' : ''">⚑ {{ titleCase(project.priority) }} priority</dd></div>
        </dl>

        <dl class="pd-facts">
          <div><dt>Project Lead</dt><dd>{{ project.project_lead?.name ?? "—" }}</dd></div>
          <div><dt>Assignees</dt>
            <dd>
              <span v-if="!project.team?.length" class="pd-muted">Nobody yet</span>
              <span v-else class="pd-stack">
                <span v-for="m in project.team.slice(0, 5)" :key="m.id" class="pd-avatar"
                      :title="m.employee.name ?? ''">{{ initials(m.employee.name) }}</span>
              </span>
            </dd></div>
          <div v-if="project.delivery && project.delivery.oem_share !== null"><dt>Delivery split</dt>
            <dd>
              {{ 100 - project.delivery.oem_share }}% us ·
              {{ project.delivery.oem_share }}% {{ project.delivery.vendor_partner?.name ?? "OEM" }}
              <!-- Delivered against scoped. A deal quoted at 20% OEM and
                   delivered at 60% either mis-scoped or lost people, and both
                   matter before the next quote. -->
              <span v-if="project.delivery.oem_share_drift" class="pd-drift"
                    :title="`${Math.abs(project.delivery.oem_share_drift)} points ${project.delivery.oem_share_drift > 0 ? 'more' : 'less'} OEM than scoped`">
                {{ project.delivery.oem_share_drift > 0 ? "+" : "" }}{{ project.delivery.oem_share_drift }} pts vs plan
              </span>
            </dd></div>
          <div v-if="project.opportunity"><dt>From deal</dt>
            <dd><button class="link-btn"
                        @click="router.push({ name: 'opportunity', params: { id: project.opportunity.id } })">
              {{ project.opportunity.reference }}</button></dd></div>
        </dl>
      </div>

      <nav class="pd-tabs" role="tablist">
        <button v-for="t in tabs" :key="t.key" role="tab" :aria-selected="tab === t.key"
                :class="['pd-tab', { active: tab === t.key }]" @click="tab = t.key">{{ t.label }}</button>
      </nav>

      <!-- ── Details / Charter ───────────────────────────────────────── -->
      <section v-if="tab === 'details'" class="pd-panel">
        <!-- Overview -->
        <div class="pd-card">
          <h3 class="pd-h3">Overview</h3>
          <label class="pd-field"><span>Description</span>
            <textarea v-model="form.description" class="control" rows="6"
                      placeholder="What the project is setting out to do."></textarea></label>
          <div class="pd-grid2">
            <label class="pd-field"><span>Product / solution</span>
              <input v-model="form.product" class="control" placeholder="e.g. Versa SD-WAN" /></label>
            <div class="pd-field"><span>Vendor</span>
              <VendorPartnerPicker v-model="form.vendor_partner_id" placeholder="Pick the delivery vendor…" /></div>
            <div class="pd-field"><span>Client</span>
              <div class="pd-readonly">{{ project.account?.name ?? "—" }}</div></div>
            <div class="pd-field"><span>Project lead</span>
              <EmployeePicker v-model="form.project_lead_id" :department-id="pmoDepartmentId"
                              :placeholder="pmoDepartmentId ? 'Pick from the PMO team…' : 'Pick the project lead…'" />
              <span class="pd-hint">Project leads come from the Technical › PMO team.</span></div>
          </div>
        </div>

        <!-- Scope, Charter, PIP — a summary and the signed document -->
        <div v-for="doc in charterDocs" :key="doc.type" class="pd-card">
          <h3 class="pd-h3">{{ doc.title }}</h3>
          <textarea v-model="(form as any)[doc.field]" class="control" rows="5" :placeholder="doc.hint"></textarea>
          <div class="pd-docs">
            <div v-if="docsOfType(doc.type).length" class="pd-doc-list">
              <span v-for="d in docsOfType(doc.type)" :key="d.id" class="pd-doc">
                <a :href="docUrl(d.id)" target="_blank" rel="noopener" class="link-btn">📄 {{ d.label }}</a>
                <button class="pd-x" title="Remove" @click="deleteDoc(d.id)">✕</button>
              </span>
            </div>
            <label class="pd-doc-add">
              <input type="file" class="pd-hidden-file"
                accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.zip"
                @change="(e) => uploadDoc(doc.type, e)" />
              <span>{{ docUploading === doc.type ? "Uploading…" : "+ Attach " + doc.title + " document" }}</span>
            </label>
          </div>
        </div>

        <!-- Timeline -->
        <div class="pd-card">
          <h3 class="pd-h3">Execution &amp; completion</h3>
          <div class="pd-grid2">
            <label class="pd-field"><span>Execution start</span>
              <input v-model="form.execution_start" type="date" class="control" /></label>
            <label class="pd-field"><span>Execution end</span>
              <input v-model="form.execution_end" type="date" class="control" /></label>
            <label class="pd-field"><span>Est. completion — per charter</span>
              <input v-model="form.charter_completion_date" type="date" class="control" /></label>
            <label class="pd-field"><span>Est. completion — per contract</span>
              <input v-model="form.contract_completion_date" type="date" class="control" /></label>
          </div>
        </div>

        <!-- Client people -->
        <div class="pd-card">
          <h3 class="pd-h3">Client contacts</h3>
          <p v-if="!clientContacts.length" class="pd-empty-inline">
            No contacts on {{ project.account?.name ?? "this client" }} yet — add them on the account to pick here.
          </p>
          <div v-else class="pd-grid2">
            <label class="pd-field"><span>Client contact</span>
              <select v-model.number="form.client_contact_id" class="control">
                <option :value="null">— None —</option>
                <option v-for="c in clientContacts" :key="c.id" :value="c.id">
                  {{ c.name }}{{ c.designation ? " · " + c.designation : "" }}
                </option>
              </select></label>
            <label class="pd-field"><span>Client project sponsor</span>
              <select v-model.number="form.client_sponsor_contact_id" class="control">
                <option :value="null">— None —</option>
                <option v-for="c in clientContacts" :key="c.id" :value="c.id">
                  {{ c.name }}{{ c.designation ? " · " + c.designation : "" }}
                </option>
              </select></label>
          </div>
          <p class="pd-hint">The internal team — sponsor, owner, project manager, engineer — is set on the Team &amp; Roles tab.</p>
        </div>

        <div class="pd-charter-save">
          <button class="btn-primary" :disabled="saving" @click="saveDetails">
            {{ saving ? "Saving…" : "Save charter" }}
          </button>
        </div>
      </section>

      <!-- ── Tasks ───────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'tasks'" class="pd-panel">
        <div class="pd-panel-actions">
          <button class="btn-primary" @click="newTask()">+ Add Task</button>
          <button class="btn-secondary" :disabled="generatingPm" @click="generatePmActivities">
            {{ generatingPm ? "Seeding…" : "✨ Generate PM activities" }}
          </button>
          <select v-model="logiFilter" class="control pd-logi-filter" title="Filter by requirement">
            <option value="">All requirements</option>
            <option v-for="n in NEED_FLAGS" :key="n.key" :value="n.key">Needs: {{ n.label }}</option>
            <option value="approval">Needs approval</option>
          </select>
        </div>

        <p v-if="!tasks.length" class="pd-empty">
          Nothing planned yet. Until there is, the project has no progress to report —
          which is different from being at zero.
        </p>
        <p v-else-if="!filteredTasks.length" class="pd-empty">No task matches that requirement filter.</p>

        <div v-else class="pd-card pd-table-card">
          <table class="pd-table">
            <thead><tr><th></th><th>Task</th><th>Status</th><th>Assignee</th><th>Due Date</th><th>Priority</th><th></th></tr></thead>
            <tbody>
              <template v-for="t in filteredTasks" :key="t.id">
                <tr :class="{ done: t.is_done }">
                  <td class="pd-tick">
                    <input type="checkbox" :checked="t.is_done" @change="toggleDone(t, $event)" />
                  </td>
                  <td>
                    <span class="pd-task-title">{{ t.title }}</span>
                    <!-- Reported, not enforced: refusing to start a blocked
                         task gets worked around by deleting the dependency. -->
                    <span v-if="t.is_blocked" class="pd-flag" title="Waiting on another task">blocked</span>
                    <span v-if="t.logistics?.needs_any" class="pd-logi-badge" :title="needLabels(t.logistics).join(', ')">🧰 {{ needLabels(t.logistics).length }}</span>
                    <span v-if="t.logistics?.requires_approval" class="pd-logi-badge warn" title="Needs approval">✍ approval</span>
                    <span v-if="t.subtasks.length" class="pd-sub">{{ t.subtasks.length }} sub-task{{ t.subtasks.length === 1 ? "" : "s" }}</span>
                  </td>
                  <td><span :class="['pill', `pill-${statusTone(t.status)}`]">{{ titleCase(t.status) }}</span></td>
                  <td>
                    <span v-if="!t.assignees.length" class="pd-muted">—</span>
                    <span v-else class="pd-stack">
                      <span v-for="a in t.assignees.slice(0, 3)" :key="a.id" class="pd-avatar"
                            :title="a.employee.name ?? ''">{{ initials(a.employee.name) }}</span>
                    </span>
                  </td>
                  <td :class="['pd-nowrap', { 'pd-late': t.is_overdue }]">{{ date(t.deadline) }}</td>
                  <td><span :class="['pd-priority', `prio-${t.priority}`]">⚑ {{ titleCase(t.priority) }}</span></td>
                  <td><button class="link-btn" @click="reopenDrawer(t.id)">View</button></td>
                </tr>
                <tr v-for="s in t.subtasks" :key="s.id" :class="['pd-subrow', { done: s.is_done }]">
                  <td class="pd-tick"><input type="checkbox" :checked="s.is_done" @change="toggleDone(s, $event)" /></td>
                  <td><span class="pd-subtask">↳ {{ s.title }}</span></td>
                  <td><span :class="['pill', `pill-${statusTone(s.status)}`]">{{ titleCase(s.status) }}</span></td>
                  <td>
                    <span v-if="!s.assignees.length" class="pd-muted">—</span>
                    <span v-else class="pd-stack">
                      <span v-for="a in s.assignees.slice(0, 3)" :key="a.id" class="pd-avatar">{{ initials(a.employee.name) }}</span>
                    </span>
                  </td>
                  <td :class="['pd-nowrap', { 'pd-late': s.is_overdue }]">{{ date(s.deadline) }}</td>
                  <td><span :class="['pd-priority', `prio-${s.priority}`]">⚑ {{ titleCase(s.priority) }}</span></td>
                  <td><button class="link-btn" @click="reopenDrawer(s.id)">View</button></td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── Phases ──────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'phases'" class="pd-panel">
        <div class="pd-panel-actions">
          <button v-if="!phases.length" class="btn-primary" @click="generateDefaultPhases">Generate default phases</button>
          <div class="pd-inline-add">
            <input v-model="newPhaseName" class="pd-input" placeholder="Add a phase…" @keyup.enter="addPhase" />
            <button class="btn-secondary" @click="addPhase">Add</button>
          </div>
        </div>

        <ul v-if="phases.length" class="pd-phases">
          <li v-for="ph in phases" :key="ph.id" :class="['pd-phase', { 'at-risk': ph.at_risk }]">
            <div class="pd-phase-head">
              <span class="pd-phase-name">{{ ph.name }}</span>
              <span class="pd-phase-status" :class="'pd-st-' + ph.status">{{ ph.status }}</span>
              <span v-if="ph.at_risk" class="pd-phase-risk">At risk</span>
              <span class="pd-phase-due">{{ ph.due_on ? "Due " + ph.due_on : "" }}</span>
              <button class="pd-x" title="Remove" @click="deletePhase(ph.id)">✕</button>
            </div>
            <div class="pd-phase-bar">
              <span class="pd-phase-fill" :style="{ width: ph.progress + '%' }"></span>
            </div>
            <div class="pd-phase-meta">
              <span>{{ ph.progress }}%</span>
              <span>{{ ph.completed }}/{{ ph.tasks }} tasks<template v-if="ph.overdue"> · {{ ph.overdue }} overdue</template></span>
              <span v-if="ph.children.length">{{ ph.children.length }} sub-phase{{ ph.children.length === 1 ? "" : "s" }}</span>
            </div>

            <!-- Sub-phases -->
            <ul v-if="ph.children.length" class="pd-subphases">
              <li v-for="sp in ph.children" :key="sp.id" class="pd-subphase">
                <span class="pd-subphase-name">↳ {{ sp.name }}</span>
                <span v-if="sp.due_on" class="pd-phase-due">Due {{ sp.due_on }}</span>
                <button class="pd-x" title="Remove sub-phase" @click="deletePhase(sp.id)">✕</button>
              </li>
            </ul>
            <div class="pd-subphase-add">
              <input v-model="subPhaseName[ph.id]" class="pd-input pd-mini-input" placeholder="Add a sub-phase…" @keyup.enter="addSubPhase(ph.id)" />
              <button class="btn-secondary pd-mini-btn" @click="addSubPhase(ph.id)">+ Sub-phase</button>
            </div>
          </li>
        </ul>
        <p v-else class="pd-empty">No phases yet. Generate the default 9-stage lifecycle, or add your own.</p>
      </section>

      <!-- ── Team & Roles ────────────────────────────────────────────── -->
      <section v-else-if="tab === 'team'" class="pd-panel">
        <nav class="pd-subtabs">
          <button v-for="s in teamSubs" :key="s.key" type="button"
                  :class="['pd-subtab', { active: teamSub === s.key }]" @click="teamSub = s.key">{{ s.label }}</button>
        </nav>

        <!-- Internal delivery team -->
        <div v-show="teamSub === 'internal'" class="pd-subpanel">
        <template v-if="team">
          <div class="pd-team-leads">
            <div class="pd-team-group">
              <div class="pd-team-group-head"><span>Project Managers</span>
                <EmployeePicker :model-value="null" placeholder="Add a manager…" @update:model-value="(id: number | null) => id && assignPerson(id, 'manager')" />
              </div>
              <div class="pd-chips">
                <span v-for="m in team.managers" :key="m.id" class="pd-chip">{{ m.name }}<button class="pd-chip-x" @click="removePerson(m.id)">✕</button></span>
                <span v-if="!team.managers.length" class="pd-empty-inline">None yet</span>
              </div>
            </div>
            <div class="pd-team-group">
              <div class="pd-team-group-head"><span>Project Leads</span>
                <EmployeePicker :model-value="null" placeholder="Add a lead…" @update:model-value="(id: number | null) => id && assignPerson(id, 'lead')" />
              </div>
              <div class="pd-chips">
                <span v-for="l in team.leads" :key="l.id" class="pd-chip">{{ l.name }}<button class="pd-chip-x" @click="removePerson(l.id)">✕</button></span>
                <span v-if="!team.leads.length" class="pd-empty-inline">None yet</span>
              </div>
            </div>
          </div>

          <!-- Governance committee — list + add-role modal -->
          <div class="pd-gov-head">
            <h3 class="pd-h3 pd-team-heading">Governance committee</h3>
            <div class="pd-gov-head-actions">
              <button v-if="governance && !governanceAssigned.length" class="btn-secondary btn-sm" @click="generateGovernanceTemplate">Generate template</button>
              <button class="btn-primary btn-sm" :disabled="!governanceAvailable.length" @click="openGovAdd">+ Add role</button>
            </div>
          </div>

          <p v-if="vacantMandatory.length" class="pd-gov-warn">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
              <path d="M8 2.5L14.5 13.5h-13z" /><path d="M8 6.5v3M8 11.5v.5" />
            </svg>
            Mandatory governance not yet assigned: <strong>{{ vacantMandatory.join(", ") }}</strong>.
            Fill these before initiating the project.
          </p>

          <div v-if="!governance" class="pd-muted">Loading committee…</div>
          <div v-else-if="!governanceAssigned.length" class="pd-empty">No governance roles yet. Add one, or generate the mandatory template.</div>
          <div v-else class="pd-card pd-table-card">
            <!--
              Every column here was already being captured by the add-role form
              and thrown away by this table — a backup, an escalation path, an
              approval limit and a term, all typed in and then invisible. That is
              why they are blank on almost every project: people filled them once,
              saw nothing, and stopped.
            -->
            <table class="pd-table pd-gov-table">
              <thead><tr>
                <th>Role</th><th>Assigned to</th><th>Backup</th>
                <th>Escalates to</th><th>Can approve</th><th>Term</th><th>Status</th><th></th>
              </tr></thead>
              <tbody>
                <tr v-for="r in governanceAssigned" :key="r.role_key">
                  <td>
                    {{ r.label }}<span v-if="r.is_mandatory" class="pd-report-req" title="Mandatory">*</span>
                    <span v-if="r.responsibility" class="pd-gov-sub" :title="r.responsibility">{{ r.responsibility }}</span>
                  </td>
                  <td>
                    {{ r.assignee?.name ?? "—" }}
                    <span v-if="r.department || r.contact_info" class="pd-gov-sub">
                      {{ [r.department, r.contact_info].filter(Boolean).join(" · ") }}
                    </span>
                  </td>
                  <!-- Who covers the seat. Blank is worth seeing: a committee of
                       one deep is a committee that stops when somebody is away. -->
                  <td :class="{ 'pd-gov-gap': r.filled && !r.backup }">{{ r.backup?.name ?? "none" }}</td>
                  <td :class="{ 'pd-gov-gap': !r.escalation_authority }">{{ r.escalation_authority ?? "not set" }}</td>
                  <td :class="{ 'pd-gov-gap': !r.approval_authority }">{{ r.approval_authority ?? "not set" }}</td>
                  <td class="pd-nowrap">
                    <template v-if="r.start_date || r.end_date">
                      {{ r.start_date ? date(r.start_date) : "—" }}<template v-if="r.end_date"> → {{ date(r.end_date) }}</template>
                    </template>
                    <span v-else class="pd-gov-sub">open</span>
                  </td>
                  <td>
                    <!-- A term that has run out is not the same as an empty seat,
                         and both read as "nobody is on this" if they look alike. -->
                    <span v-if="govExpired(r)" class="pill pill-bad" :title="'Term ended ' + date(r.end_date!)">Expired</span>
                    <span v-else-if="!govStarted(r)" class="pill pill-warn" :title="'Starts ' + date(r.start_date!)">Not yet</span>
                    <span v-else :class="['pill', r.filled ? 'pill-ok' : 'pill-warn']">{{ r.filled ? "Assigned" : "Vacant" }}</span>
                  </td>
                  <td><button class="link-btn" @click="openGovEdit(r)">Edit</button></td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="pd-gov-note">
            Escalation and approval authority are recorded here for reference. Task
            escalation does not read them yet — it resolves from the delivery teams
            below and the department lead.
          </p>

          <h3 class="pd-h3 pd-team-heading">Delivery teams</h3>
          <div class="pd-panel-actions">
            <button v-if="!team.roles.length" class="btn-primary" @click="generateDefaultTeams">Generate default teams</button>
            <div class="pd-inline-add">
              <input v-model="newRoleName" class="pd-input" placeholder="Add a team…" @keyup.enter="addRole" />
              <button class="btn-secondary" @click="addRole">Add team</button>
            </div>
          </div>

          <div v-if="team.roles.length" class="pd-teams">
            <div v-for="r in team.roles" :key="r.id" class="pd-team-card">
              <div class="pd-team-card-head">
                <span class="pd-role-name">{{ r.name }}</span>
                <button class="pd-x" title="Remove team" @click="deleteRole(r.id)">✕</button>
              </div>
              <input v-model="r.description" class="control pd-team-desc" placeholder="Role description — what this team delivers"
                     @change="updateRoleDescription(r)" />

              <table v-if="r.members.length" class="pd-team-members">
                <thead><tr><th>Member</th><th>Lead</th><th>Capacity</th><th>Assignment</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="p in r.members" :key="p.id">
                    <td>{{ p.name }}</td>
                    <td><button :class="['pd-lead-toggle', { on: p.is_lead }]" @click="toggleMemberLead(p)">{{ p.is_lead ? "Lead" : "—" }}</button></td>
                    <td>{{ p.capacity_percent != null ? p.capacity_percent + "%" : "—" }}</td>
                    <td class="pd-team-dates">{{ p.assigned_from ? date(p.assigned_from) : "—" }}<template v-if="p.assigned_to"> → {{ date(p.assigned_to) }}</template></td>
                    <td><button class="pd-chip-x" @click="removePerson(p.id)">✕</button></td>
                  </tr>
                </tbody>
              </table>
              <p v-else class="pd-empty-inline">Nobody assigned</p>

              <!-- Add a member with capacity and dates -->
              <div class="pd-member-add">
                <label class="pd-member-check"><input type="checkbox" v-model="draftFor(r.id).is_lead" /> Lead</label>
                <input v-model="draftFor(r.id).capacity_percent" class="control pd-member-cap" type="number" min="0" max="100" placeholder="% cap" />
                <input v-model="draftFor(r.id).assigned_from" class="control pd-member-date" type="date" title="From" />
                <input v-model="draftFor(r.id).assigned_to" class="control pd-member-date" type="date" title="To" />
                <EmployeePicker :model-value="null" placeholder="Add member…"
                  @update:model-value="(id: number | null) => id && addTeamMember(r.id, id)" />
              </div>
            </div>
          </div>
          <p v-else class="pd-empty">No teams yet. Generate the ten standing delivery teams, or add your own.</p>
        </template>
        <p v-else class="pd-empty">Loading…</p>
        </div>

        <!-- Customer organization -->
        <div v-show="teamSub === 'customer'" class="pd-subpanel">
        <template v-if="customerTeam">
          <div class="pd-cust-head">
            <h3 class="pd-h3 pd-team-heading">Customer team &amp; stakeholders</h3>
            <div class="pd-gov-head-actions">
              <span class="pd-cust-org">{{ customerTeam.organization?.name ?? "No client linked" }}</span>
              <button v-if="!customerAssigned.length" class="btn-secondary btn-sm" @click="generateCustomerTemplate">Generate roles</button>
              <button class="btn-primary btn-sm" :disabled="!customerAvailable.length" @click="openCustAdd">+ Add role</button>
            </div>
          </div>

          <!-- Customer roles list -->
          <div v-if="!customerAssigned.length" class="pd-empty">No customer roles yet. Add one, or generate the standard set.</div>
          <div v-else class="pd-card pd-table-card">
            <table class="pd-table">
              <thead><tr><th>Role</th><th>Contact</th><th>Responsibility</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <tr v-for="r in customerAssigned" :key="r.role_key">
                  <td>{{ r.label }}</td><td>{{ r.contact?.name ?? "—" }}</td><td>{{ r.responsibility ?? "—" }}</td>
                  <td><span :class="['pill', r.contact ? 'pill-ok' : 'pill-warn']">{{ r.contact ? "Assigned" : "Vacant" }}</span></td>
                  <td><button class="link-btn" @click="openCustEdit(r)">Edit</button></td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Add a customer contact -->
          <details class="pd-cust-add">
            <summary>+ Add a customer contact</summary>
            <div class="pd-cust-add-grid">
              <input v-model="newCustContact.first_name" class="control" placeholder="First name" />
              <input v-model="newCustContact.last_name" class="control" placeholder="Last name" />
              <input v-model="newCustContact.title" class="control" placeholder="Title" />
              <input v-model="newCustContact.email" class="control" placeholder="Email" />
              <input v-model="newCustContact.phone" class="control" placeholder="Phone" />
              <select v-model="newCustContact.category" class="control">
                <option value="primary">Primary</option><option value="technical">Technical</option>
                <option value="finance">Finance</option><option value="procurement">Procurement</option>
                <option value="other">Other</option>
              </select>
              <button class="btn-secondary btn-sm" :disabled="!newCustContact.first_name.trim()" @click="addCustomerContact">Add contact</button>
            </div>
          </details>

          <!-- Stakeholder register -->
          <h4 class="pd-h4 pd-cust-sub">Stakeholder register</h4>
          <div class="pd-card pd-table-card">
            <table class="pd-table">
              <thead><tr><th>Stakeholder</th><th>Persona</th><th>Influence</th><th>Interest</th><th>Responsibility</th><th></th></tr></thead>
              <tbody>
                <tr v-for="s in customerTeam.stakeholders" :key="s.id">
                  <td>{{ s.name }}<template v-if="s.title"> · {{ s.title }}</template></td>
                  <td>{{ s.persona ?? "—" }}</td>
                  <td>{{ s.influence ?? "—" }}</td>
                  <td>{{ s.interest ?? "—" }}</td>
                  <td>{{ s.responsibility ?? "—" }}</td>
                  <td><button class="link-btn link-danger" @click="removeStakeholder(s.id)">✕</button></td>
                </tr>
                <tr v-if="!customerTeam.stakeholders.length"><td colspan="6" class="pd-muted">No stakeholders yet.</td></tr>
              </tbody>
            </table>
          </div>
          <div class="pd-cust-add-grid pd-stake-add">
            <input v-model="newStakeholder.name" class="control" placeholder="Name" />
            <input v-model="newStakeholder.persona" class="control" placeholder="Persona (e.g. Decision maker)" />
            <select v-model="newStakeholder.influence" class="control"><option value="">Influence…</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select>
            <select v-model="newStakeholder.interest" class="control"><option value="">Interest…</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select>
            <input v-model="newStakeholder.responsibility" class="control" placeholder="Responsibility" />
            <button class="btn-secondary btn-sm" :disabled="!newStakeholder.name.trim()" @click="addStakeholder">Add stakeholder</button>
          </div>
        </template>
        <p v-else class="pd-empty">Loading…</p>
        </div>

        <!-- Professional services -->
        <div v-show="teamSub === 'professional'" class="pd-subpanel">
        <template v-if="psData">
          <div class="pd-cust-head">
            <h3 class="pd-h3 pd-team-heading">Professional services</h3>
            <button class="btn-primary btn-sm" @click="openPsAdd">+ Add resource</button>
          </div>
          <div v-if="!psData.data.length" class="pd-empty">No professional-services resources yet — consultants, SMEs, trainers.</div>
          <div v-else class="pd-card pd-table-card">
            <table class="pd-table">
              <thead><tr><th>Role</th><th>Name</th><th>Provider</th><th>Contact</th><th>Assignment</th><th></th></tr></thead>
              <tbody>
                <tr v-for="r in psData.data" :key="r.id">
                  <td>{{ r.role_label }}</td><td>{{ r.name }}</td><td>{{ r.organization ?? "—" }}</td><td>{{ r.contact_info ?? "—" }}</td>
                  <td class="pd-team-dates">{{ r.start_date ? date(r.start_date) : "—" }}<template v-if="r.end_date"> → {{ date(r.end_date) }}</template></td>
                  <td><button class="link-btn" @click="openPsEdit(r)">Edit</button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
        <p v-else class="pd-empty">Loading…</p>
        </div>

        <!-- Subcontractors -->
        <div v-show="teamSub === 'subcontractors'" class="pd-subpanel">
        <template v-if="subData">
          <div class="pd-cust-head">
            <h3 class="pd-h3 pd-team-heading">Subcontractors</h3>
            <button class="btn-primary btn-sm" @click="openSubAdd">+ Add subcontractor</button>
          </div>
          <div v-if="!subData.data.length" class="pd-empty">No subcontractors engaged yet.</div>
          <div v-else class="pd-card pd-table-card">
            <table class="pd-table">
              <thead><tr><th>Type</th><th>Company</th><th>Contact</th><th>Contract ref</th><th>Approval</th><th>Onboarding</th><th></th></tr></thead>
              <tbody>
                <tr v-for="r in subData.data" :key="r.id">
                  <td>{{ r.role_label }}</td><td>{{ r.company }}</td><td>{{ r.contact_person ?? "—" }}</td><td>{{ r.contract_reference ?? "—" }}</td>
                  <td><span :class="['pill', r.approval_status === 'approved' ? 'pill-ok' : r.approval_status === 'rejected' ? 'pill-bad' : 'pill-warn']">{{ titleCase(r.approval_status) }}</span></td>
                  <td><span :class="['pill', r.onboarding_status === 'complete' ? 'pill-ok' : r.onboarding_status === 'in_progress' ? 'pill-warn' : 'pill-neutral']">{{ titleCase(r.onboarding_status) }}</span></td>
                  <td><button class="link-btn" @click="openSubEdit(r)">Edit</button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
        </div>
      </section>

      <!-- ── Issues ──────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'issues'" class="pd-panel">
        <!-- Issues -->
        <div class="pd-reg-block">
          <div class="pd-reg-head"><h3>Issues</h3><span class="pd-reg-count">{{ issues.length }}</span></div>
          <div class="pd-inline-add">
            <input v-model="newIssue.title" class="pd-input pd-grow" placeholder="Describe an issue…" @keyup.enter="addIssue" />
            <select v-model="newIssue.severity" class="pd-input">
              <option value="low">Low</option><option value="medium">Medium</option>
              <option value="high">High</option><option value="critical">Critical</option>
            </select>
            <button class="btn-secondary" @click="addIssue">Add</button>
          </div>
          <table v-if="issues.length" class="pd-reg-table">
            <thead><tr><th>Issue</th><th>Severity</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="it in issues" :key="it.id">
                <td>{{ it.title }}<span v-if="it.escalated" class="pd-reg-esc">escalated → {{ it.escalated_to }}</span></td>
                <td><span :class="['pd-sev', 'pd-sev-' + it.severity]">{{ titleCase(it.severity) }}</span></td>
                <td>
                  <select :value="it.status" class="pd-mini" @change="setIssueStatus(it, ($event.target as HTMLSelectElement).value)">
                    <option value="open">Open</option><option value="in_progress">In progress</option><option value="resolved">Resolved</option>
                  </select>
                </td>
                <td><button class="pd-x" @click="deleteIssue(it.id)">✕</button></td>
              </tr>
            </tbody>
          </table>
          <p v-else class="pd-empty-inline">No issues raised.</p>
        </div>

        <!-- Issues raised on tasks (read-only; managed on the task) -->
        <div v-if="taskIssues.length" class="pd-reg-block" style="margin-top:22px">
          <div class="pd-reg-head"><h3>Raised on tasks</h3><span class="pd-reg-count">{{ taskIssues.length }}</span></div>
          <p class="pd-hint" style="margin:-4px 0 10px">Issues logged against this project's tasks. Open the task to work or resolve them.</p>
          <table class="pd-reg-table">
            <thead><tr><th>Issue</th><th>Task</th><th>Priority</th><th>Raised by</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="ti in taskIssues" :key="'ti' + ti.id" :class="{ 'pd-ti-resolved': ti.status === 'resolved' }">
                <td>{{ ti.title }}</td>
                <td><span v-if="ti.task" class="pd-ti-task">{{ ti.task.reference ? ti.task.reference + ' · ' : '' }}{{ ti.task.title }}</span><span v-else class="pd-muted">—</span></td>
                <td><span :class="['pd-sev', 'pd-sev-' + ti.priority]">{{ titleCase(ti.priority) }}</span></td>
                <td>{{ ti.raised_by ?? "—" }}</td>
                <td><span :class="['pill', ti.status === 'resolved' ? 'pill-ok' : 'pill-warn']">{{ titleCase(ti.status) }}</span></td>
                <td><button v-if="ti.task" class="link-btn" @click="ti.task && reopenDrawer(ti.task.id)">Open task →</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── Dependencies ────────────────────────────────────────────── -->
      <section v-else-if="tab === 'dependencies'" class="pd-panel">
        <!-- Dependencies -->
        <div class="pd-reg-block">
          <div class="pd-reg-head"><h3>Dependencies</h3><span class="pd-reg-count">{{ dependencies.length }}</span></div>
          <div class="pd-inline-add">
            <input v-model="newDep.title" class="pd-input pd-grow" placeholder="What are we waiting on…" @keyup.enter="addProjectDep" />
            <select v-model="newDep.kind" class="pd-input">
              <option value="external">External</option><option value="vendor">Vendor</option>
              <option value="task">Task</option><option value="other">Other</option>
            </select>
            <input v-model="newDep.due_on" type="date" class="pd-input" />
            <button class="btn-secondary" @click="addProjectDep">Add</button>
          </div>
          <table v-if="dependencies.length" class="pd-reg-table">
            <thead><tr><th>Dependency</th><th>Kind</th><th>Owner</th><th>Due</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="d in dependencies" :key="d.id">
                <td>{{ d.title }}</td>
                <td><span class="pd-tag">{{ titleCase(d.kind) }}</span></td>
                <td>
                  <span v-if="d.owner">{{ d.owner.name }}</span>
                  <EmployeePicker v-else :model-value="null" placeholder="Owner…" @update:model-value="(id: number | null) => setDepOwner(d, id)" />
                </td>
                <td class="pd-nowrap">{{ d.due_on || "—" }}</td>
                <td>
                  <select :value="d.status" class="pd-mini" @change="setDepStatus(d, ($event.target as HTMLSelectElement).value)">
                    <option value="pending">Pending</option><option value="in_progress">In progress</option><option value="resolved">Resolved</option>
                  </select>
                </td>
                <td><button class="pd-x" @click="deleteDependency(d.id)">✕</button></td>
              </tr>
            </tbody>
          </table>
          <p v-else class="pd-empty-inline">No dependencies logged.</p>
        </div>
      </section>

      <!-- ── Risks ───────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'risks'" class="pd-panel">
        <!-- Risks -->
        <div class="pd-reg-block">
          <div class="pd-reg-head"><h3>Risks</h3><span class="pd-reg-count">{{ risks.length }}</span></div>
          <div class="pd-inline-add">
            <input v-model="newRisk.title" class="pd-input pd-grow" placeholder="Describe a risk…" @keyup.enter="addRisk" />
            <label class="pd-mini-label">Likelihood
              <select v-model="newRisk.likelihood" class="pd-input"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select>
            </label>
            <label class="pd-mini-label">Impact
              <select v-model="newRisk.impact" class="pd-input"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select>
            </label>
            <button class="btn-secondary" @click="addRisk">Add</button>
          </div>
          <table v-if="risks.length" class="pd-reg-table">
            <thead><tr><th></th><th>Risk</th><th>Against</th><th>L×I</th><th>Heat</th><th>Owner</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <template v-for="r in risks" :key="r.id">
                <tr :class="{ 'pd-risk-closed': r.status === 'closed' }">
                  <td class="pd-risk-caret" @click="toggleRisk(r)">{{ openRisk === r.id ? "▾" : "▸" }}</td>
                  <td class="pd-risk-title-cell" @click="toggleRisk(r)">{{ r.title }}
                    <span v-if="r.evidence.length" class="pd-tag" title="Evidence attached">📎 {{ r.evidence.length }}</span>
                  </td>
                  <td class="pd-nowrap">
                    <span v-if="r.task" class="pd-tag">task: {{ r.task.title }}</span>
                    <span v-if="r.phase" class="pd-tag">phase: {{ r.phase.name }}</span>
                    <span v-if="!r.task && !r.phase" class="pd-muted">—</span>
                  </td>
                  <td class="pd-nowrap">{{ titleCase(r.likelihood) }} × {{ titleCase(r.impact) }}</td>
                  <td><span :class="['pd-heat', r.score >= 6 ? 'pd-heat-hi' : r.score >= 3 ? 'pd-heat-mid' : 'pd-heat-lo']">{{ r.score }}</span></td>
                  <td>
                    <span v-if="r.owner">{{ r.owner.name }}</span>
                    <EmployeePicker v-else :model-value="null" placeholder="Owner…" @update:model-value="(id: number | null) => updateRisk(r, { owner_employee_id: id })" />
                  </td>
                  <td>
                    <select :value="r.status" class="pd-mini" @change="updateRisk(r, { status: ($event.target as HTMLSelectElement).value })">
                      <option value="open">Open</option><option value="mitigating">Mitigating</option><option value="closed">Closed</option>
                    </select>
                  </td>
                  <td><button class="pd-x" @click="deleteRisk(r.id)">✕</button></td>
                </tr>
                <tr v-if="openRisk === r.id" class="pd-risk-detail-row">
                  <td></td>
                  <td colspan="7">
                    <div class="pd-risk-detail">
                      <div class="pd-risk-assoc">
                        <label class="pd-field"><span>Associate a task</span>
                          <select :value="r.task_id ?? ''" class="pd-input" @change="updateRisk(r, { task_id: ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null })">
                            <option value="">— None —</option>
                            <option v-for="t in dependencyOptions" :key="t.id" :value="t.id">{{ t.title }}</option>
                          </select></label>
                        <label class="pd-field"><span>Associate a phase</span>
                          <select :value="r.phase_id ?? ''" class="pd-input" @change="updateRisk(r, { phase_id: ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null })">
                            <option value="">— None —</option>
                            <option v-for="ph in phases" :key="ph.id" :value="ph.id">{{ ph.name }}</option>
                          </select></label>
                      </div>
                      <div class="pd-risk-evidence">
                        <div class="pd-risk-ev-head">Evidence</div>
                        <ul v-if="r.evidence.length" class="pd-order-docs">
                          <li v-for="ev in r.evidence" :key="ev.id">
                            <a :href="riskEvidenceUrl(r, ev.id)" target="_blank" rel="noopener" class="link-btn">📄 {{ ev.label }}</a>
                            <button class="pd-x" @click="deleteRiskEvidence(r, ev.id)">✕</button>
                          </li>
                        </ul>
                        <p v-else class="pd-empty-inline">No evidence attached.</p>
                        <label class="pd-doc-add">
                          <input type="file" class="pd-hidden-file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.zip" @change="(e) => uploadRiskEvidence(r, e)" />
                          <span>{{ riskEvUploading === r.id ? "Uploading…" : "+ Attach evidence" }}</span>
                        </label>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
          <p v-else class="pd-empty-inline">No risks on the register.</p>
        </div>
      </section>

      <!-- ── Delivery milestones (dated checkpoints, no money) ───────── -->
      <section v-else-if="tab === 'delivery'" class="pd-panel">
        <div class="pd-reg-block">
          <div class="pd-reg-head"><h3>Delivery milestones</h3><span class="pd-reg-count">{{ deliveryMilestones.length }}</span></div>
          <div class="pd-inline-add">
            <input v-model="newDelivery.title" class="pd-input pd-grow" placeholder="Deliverable — e.g. Design sign-off…" @keyup.enter="addDelivery" />
            <input v-model="newDelivery.target_date" type="date" class="pd-input" />
            <button class="btn-secondary" @click="addDelivery">Add</button>
          </div>
          <table v-if="deliveryMilestones.length" class="pd-reg-table">
            <thead><tr><th></th><th>Deliverable</th><th>Owner</th><th>Target</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="m in deliveryMilestones" :key="m.id" :class="{ 'pd-dm-done': m.is_achieved }">
                <td class="pd-tick">
                  <input type="checkbox" :checked="m.is_achieved"
                    @change="updateDelivery(m, { status: m.is_achieved ? 'in_progress' : 'achieved' })" />
                </td>
                <td>
                  <span class="pd-dm-title">{{ m.title }}</span>
                  <span v-if="m.is_overdue" class="pd-dm-flag overdue">Overdue</span>
                  <span v-else-if="m.is_at_risk" class="pd-dm-flag risk">Due soon</span>
                </td>
                <td>
                  <span v-if="m.owner">{{ m.owner.name }}</span>
                  <EmployeePicker v-else :model-value="null" placeholder="Owner…" @update:model-value="(id: number | null) => updateDelivery(m, { owner_employee_id: id })" />
                </td>
                <td class="pd-nowrap">{{ m.achieved_on ? "✓ " + m.achieved_on : (m.target_date || "—") }}</td>
                <td>
                  <select :value="m.status" class="pd-mini" @change="updateDelivery(m, { status: ($event.target as HTMLSelectElement).value })">
                    <option value="planned">Planned</option><option value="in_progress">In progress</option><option value="achieved">Achieved</option>
                  </select>
                </td>
                <td><button class="pd-x" @click="deleteDelivery(m.id)">✕</button></td>
              </tr>
            </tbody>
          </table>
          <p v-else class="pd-empty-inline">No delivery milestones yet. Add the deliverables this project is measured against.</p>
        </div>
      </section>

      <!-- ── Payment milestones (billing) ────────────────────────────── -->
      <section v-else-if="tab === 'milestones'" class="pd-panel">
        <div class="pd-panel-actions">
          <button class="btn-primary" @click="openMilestoneDialog">
            + Add Milestone
          </button>
        </div>

        <!-- Totals per currency, never summed across: adding KES to USD states
             a figure that means nothing. -->
        <div v-if="milestoneTotals" class="pd-totals">
          <div v-for="(t, ccy) in milestoneTotals" :key="ccy" class="pd-total">
            <span class="pd-total-ccy">{{ ccy }}</span>
            <span class="pd-total-item">agreed <strong>{{ t.agreed.toLocaleString() }}</strong></span>
            <span class="pd-total-item">paid <strong>{{ t.paid.toLocaleString() }}</strong></span>
            <span class="pd-total-item outstanding">outstanding <strong>{{ t.outstanding.toLocaleString() }}</strong></span>
          </div>
        </div>
        <p v-else-if="!commercialsVisible" class="pd-masked">
          Milestone values are hidden. They need the commercial-data permission.
        </p>

        <p v-if="!milestones.length" class="pd-empty">Nothing agreed yet.</p>

        <div v-else class="pd-card pd-table-card">
          <table class="pd-table pd-ms-table">
            <thead><tr><th>ID</th><th>Milestone</th><th>Requirements</th><th>Proof of delivery</th>
              <th class="pd-num">Value</th><th>Due</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="m in milestones" :key="m.id">
                <td class="pd-ref">{{ m.reference ?? "—" }}</td>
                <td class="pd-task-title">{{ m.name }}</td>

                <!-- Required tasks/phases, each showing whether it is done -->
                <td>
                  <div v-if="m.requirements.length" class="pd-req-chips">
                    <span v-for="r in m.requirements" :key="r.type + r.id"
                      :class="['pd-req-chip', r.done ? 'done' : 'open']" :title="r.done ? 'Complete' : 'Not complete'">
                      <span class="pd-req-kind">{{ r.type }}</span>{{ r.title }}
                      <span class="pd-req-tick">{{ r.done ? "✓" : "○" }}</span>
                    </span>
                  </div>
                  <span v-else-if="m.task">
                    {{ m.task.title }}
                    <span v-if="m.work_is_done === false" class="pd-flag" title="The work is not finished">work open</span>
                  </span>
                  <span v-else class="pd-muted">No requirements</span>
                </td>

                <!-- Proof documents finance invoices against -->
                <td>
                  <ul v-if="m.proofs.length" class="pd-proofs">
                    <li v-for="p in m.proofs" :key="p.id">
                      <a :href="proofUrl(m, p.id)" class="link-btn" target="_blank" rel="noopener">{{ p.label }}</a>
                      <button class="pd-x" title="Remove" @click="deleteProof(m, p.id)">✕</button>
                    </li>
                  </ul>
                  <span v-else class="pd-muted">None attached</span>
                  <label class="pd-proof-add">
                    <input type="file" class="pd-hidden-file"
                      accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.zip"
                      @change="(e) => attachProof(m, e)" />
                    <span>{{ proofUploading === m.id ? "Uploading…" : "+ Attach proof" }}</span>
                  </label>
                </td>

                <td class="pd-num">{{ money(m) }}</td>
                <td :class="['pd-nowrap', { 'pd-late': m.is_overdue }]">{{ date(m.due_on) }}</td>
                <td>
                  <span :class="['pill', m.is_paid ? 'pill-ok' : m.is_overdue ? 'pill-danger' : m.is_outstanding ? 'pill-warn' : 'pill-neutral']">
                    {{ titleCase(m.status) }}
                  </span>
                  <span v-if="m.status === 'pending' && m.ready_to_invoice" class="pd-ready" title="Work done and proof attached">Ready</span>
                </td>
                <td class="pd-row-actions">
                  <button v-if="m.status === 'pending'" class="link-btn" :disabled="!m.ready_to_invoice"
                    :title="m.ready_to_invoice ? 'Raise the invoice' : (m.requirements_met === false ? 'The linked work is not complete' : !m.has_proof ? 'Attach proof of delivery first' : '')"
                    @click="transition(m, 'invoiced')">Invoice</button>
                  <button v-if="m.status === 'invoiced'" class="link-btn" @click="transition(m, 'paid')">Mark paid</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── Budget: budget-vs-expenditure · expenses · vendors ──────── -->
      <section v-else-if="tab === 'budget'" class="pd-panel">
        <!-- ── Financial closure ─────────────────────────────────────── -->
        <div v-if="closure" class="pd-closure" :class="{ 'pd-closure-done': closure.closed }">
          <div class="pd-closure-head">
            <h3 class="pd-h3">Financial closure</h3>
            <span v-if="closure.closed" class="pill pill-ok">Closed</span>
            <span v-else-if="closure.ready" class="pill pill-ok">Ready to close</span>
            <span v-else class="pill pill-warn">Not ready</span>
            <span class="pd-spacer" style="flex:1"></span>
            <button v-if="closure.closed" class="btn-secondary btn-sm" @click="reopenProject">Reopen</button>
            <button v-else class="btn-primary btn-sm" :disabled="!closure.ready" @click="closeProject">Close project</button>
          </div>
          <p v-if="closure.closed" class="pd-hint">Closed by {{ closure.closed_by }}<template v-if="closure.closed_at"> · {{ closure.closed_at.slice(0, 10) }}</template>.</p>
          <div class="pd-closure-gates">
            <div class="pd-closure-gate" :class="closure.gates.orders_settled.met ? 'ok' : 'pending'">
              <span class="pd-closure-tick">{{ closure.gates.orders_settled.met ? "✓" : "•" }}</span>
              <span>Orders accepted<span v-if="!closure.gates.orders_settled.met" class="pd-closure-sub"> — {{ closure.gates.orders_settled.open }} of {{ closure.gates.orders_settled.total }} still open</span></span>
            </div>
            <div class="pd-closure-gate" :class="closure.gates.billing_settled.met ? 'ok' : 'pending'">
              <span class="pd-closure-tick">{{ closure.gates.billing_settled.met ? "✓" : "•" }}</span>
              <span>Billing settled<span v-if="!closure.gates.billing_settled.met" class="pd-closure-sub"> — {{ closure.gates.billing_settled.open }} outstanding</span></span>
            </div>
            <div class="pd-closure-gate" :class="closure.gates.within_budget.met ? 'ok' : 'pending'">
              <span class="pd-closure-tick">{{ closure.gates.within_budget.met ? "✓" : "•" }}</span>
              <span>Within budget<span v-if="!closure.gates.within_budget.met" class="pd-closure-sub"> — spend has passed budget</span></span>
            </div>
          </div>
        </div>

        <template v-if="finance">
          <!-- Budget vs actual, per currency -->
          <div v-if="finance.summary.length" class="pd-fin-summary">
            <div v-for="s in finance.summary" :key="s.currency" :class="['pd-fin-card', { 'pd-fin-over': s.over_budget }]">
              <div class="pd-fin-ccy">{{ s.currency }}</div>
              <div class="pd-fin-row"><span>Budgeted</span><strong>{{ fmtMoney(s.budgeted, s.currency) }}</strong></div>
              <div class="pd-fin-row"><span>Spent</span><strong>{{ fmtMoney(s.spent, s.currency) }}</strong></div>
              <div class="pd-fin-row pd-fin-remain"><span>Remaining</span><strong>{{ fmtMoney(s.remaining, s.currency) }}</strong></div>
              <div v-if="s.utilization !== null" class="pd-fin-bar">
                <span class="pd-fin-fill" :class="{ 'pd-fin-fill-over': s.over_budget }" :style="{ width: Math.min(s.utilization, 100) + '%' }"></span>
              </div>
              <div v-if="s.utilization !== null" class="pd-fin-util">
                {{ s.utilization }}% utilised<span v-if="s.over_budget" class="pd-fin-flag"> · over budget</span>
              </div>
            </div>
          </div>
          <p v-else class="pd-empty-inline">No budget or spend recorded yet.</p>

          <!-- ── Expense tracking: variance by category · burn · forecast ── -->
          <div v-if="finAnalytics && (finAnalytics.budgeted > 0 || finAnalytics.spent > 0)" class="pd-track">
            <div class="pd-track-head">
              <h3>Expense tracking</h3>
              <span class="pd-track-ccy">{{ finAnalytics.currency }}</span>
            </div>

            <!-- Forecast band -->
            <div v-if="finAnalytics.forecast" class="pd-fc" :class="{ 'pd-fc-over': finAnalytics.forecast.projected_over }">
              <div class="pd-fc-cell">
                <span class="pd-fc-l">Elapsed</span>
                <strong>{{ finAnalytics.forecast.elapsed_pct }}%</strong>
                <span class="pd-fc-sub">{{ finAnalytics.forecast.elapsed_days }} / {{ finAnalytics.forecast.total_days }} days</span>
              </div>
              <div class="pd-fc-cell">
                <span class="pd-fc-l">Burn / day</span>
                <strong>{{ fmtMoney(finAnalytics.forecast.burn_per_day, finAnalytics.currency) }}</strong>
                <span class="pd-fc-sub">{{ finAnalytics.utilization ?? 0 }}% of budget spent</span>
              </div>
              <div class="pd-fc-cell">
                <span class="pd-fc-l">Projected at close</span>
                <strong>{{ fmtMoney(finAnalytics.forecast.projected_total, finAnalytics.currency) }}</strong>
                <span class="pd-fc-sub" :class="finAnalytics.forecast.projected_over ? 'pd-fc-bad' : 'pd-fc-good'">
                  {{ finAnalytics.forecast.projected_over ? "over by " : "under by " }}{{ fmtMoney(Math.abs(finAnalytics.forecast.projected_variance), finAnalytics.currency) }}
                </span>
              </div>
            </div>
            <p v-else class="pd-empty-inline">Set the project's execution window to forecast the burn.</p>

            <div class="pd-track-grid">
              <!-- Variance by category -->
              <div class="pd-track-block">
                <h4>Budget vs actual by category</h4>
                <table v-if="finAnalytics.by_category.length" class="pd-reg-table pd-cat-table">
                  <thead><tr><th>Category</th><th class="pd-num">Budget</th><th class="pd-num">Spent</th><th class="pd-num">Variance</th></tr></thead>
                  <tbody>
                    <tr v-for="c in finAnalytics.by_category" :key="c.category">
                      <td><span class="pd-cat-chip">{{ c.category }}</span></td>
                      <td class="pd-num pd-nowrap">{{ fmtMoney(c.budgeted, finAnalytics.currency) }}</td>
                      <td class="pd-num pd-nowrap">{{ fmtMoney(c.spent, finAnalytics.currency) }}</td>
                      <td class="pd-num pd-nowrap" :class="c.over ? 'pd-var-bad' : (c.variance > 0 ? 'pd-var-good' : '')">
                        {{ c.variance < 0 ? "−" : "" }}{{ fmtMoney(Math.abs(c.variance), finAnalytics.currency) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
                <p v-else class="pd-empty-inline">No categorised budget or spend.</p>
              </div>

              <!-- Monthly burn -->
              <div class="pd-track-block">
                <h4>Monthly burn</h4>
                <div v-if="finAnalytics.burn.length" class="pd-burn">
                  <div v-for="b in finAnalytics.burn" :key="b.month" class="pd-burn-row">
                    <span class="pd-burn-m">{{ b.month }}</span>
                    <div class="pd-burn-bar">
                      <span class="pd-burn-fill" :style="{ width: burnPct(b.spent) + '%' }"></span>
                    </div>
                    <span class="pd-burn-v">{{ fmtMoney(b.spent, finAnalytics.currency) }}</span>
                  </div>
                  <p v-if="finAnalytics.undated_spend > 0" class="pd-burn-note">
                    + {{ fmtMoney(finAnalytics.undated_spend, finAnalytics.currency) }} undated
                  </p>
                </div>
                <p v-else class="pd-empty-inline">No dated expenses to chart.</p>
                <div class="pd-link-stats">
                  <span>◆ {{ finAnalytics.linkage.to_tasks }} to tasks</span>
                  <span>✈ {{ finAnalytics.linkage.to_travel }} to travel</span>
                  <span class="pd-muted">{{ finAnalytics.linkage.unlinked }} unlinked</span>
                </div>
              </div>
            </div>
          </div>

          <div class="pd-budget-cols">
            <!-- Budget lines -->
            <div class="pd-reg-block">
              <div class="pd-reg-head">
                <h3>Budget lines</h3>
                <button class="pd-gen-btn" title="Build budget lines from costed travel requests and procurement items" @click="generateBudget">⚡ Generate from plan</button>
              </div>
              <div class="pd-inline-add">
                <input v-model="newBudget.label" class="pd-input pd-grow" placeholder="Line item…" @keyup.enter="addBudgetLine" />
                <select v-model="newBudget.category" class="pd-input pd-cat">
                  <option value="">— Category —</option>
                  <option v-for="c in finance.categories" :key="c" :value="c">{{ c }}</option>
                </select>
                <input v-model="newBudget.currency" class="pd-input pd-ccy" maxlength="3" placeholder="KES" />
                <input v-model="newBudget.amount" type="number" min="0" step="0.01" class="pd-input pd-amt" placeholder="Amount" @keyup.enter="addBudgetLine" />
                <button class="btn-secondary" @click="addBudgetLine">Add</button>
              </div>
              <table v-if="finance.budget_lines.length" class="pd-reg-table">
                <thead><tr><th>Item</th><th>Category</th><th class="pd-num">Amount</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="l in finance.budget_lines" :key="l.id">
                    <td>{{ l.label }}<span v-if="l.source && l.source !== 'manual'" class="pd-src-chip" :title="'Generated from ' + l.source">⚡ {{ l.source }}</span></td>
                    <td><span v-if="l.category" class="pd-cat-chip">{{ l.category }}</span><span v-else class="pd-muted">—</span></td>
                    <td class="pd-num pd-nowrap">{{ fmtMoney(l.amount, l.currency) }}</td>
                    <td><button class="pd-x" @click="deleteBudgetLine(l.id)">✕</button></td>
                  </tr>
                </tbody>
              </table>
              <p v-else class="pd-empty-inline">Nothing budgeted.</p>
            </div>

            <!-- Expenses -->
            <div class="pd-reg-block">
              <div class="pd-reg-head"><h3>Expenses</h3></div>
              <div class="pd-inline-add">
                <input v-model="newExpense.label" class="pd-input pd-grow" placeholder="What was spent on…" @keyup.enter="addExpense" />
                <select v-model="newExpense.category" class="pd-input pd-cat">
                  <option value="">— Category —</option>
                  <option v-for="c in finance.categories" :key="c" :value="c">{{ c }}</option>
                </select>
                <input v-model="newExpense.currency" class="pd-input pd-ccy" maxlength="3" placeholder="KES" />
                <input v-model="newExpense.amount" type="number" min="0" step="0.01" class="pd-input pd-amt" placeholder="Amount" @keyup.enter="addExpense" />
                <input v-model="newExpense.spent_on" type="date" class="pd-input" />
                <select v-model="newExpense.task_id" class="pd-input pd-link">
                  <option value="">— Link a task —</option>
                  <option v-for="t in tasks" :key="t.id" :value="String(t.id)">{{ t.title }}</option>
                </select>
                <select v-model="newExpense.travel_request_id" class="pd-input pd-link">
                  <option value="">— Link travel —</option>
                  <option v-for="r in travelReqs ?? []" :key="r.id" :value="String(r.id)">{{ r.purpose }}</option>
                </select>
                <button class="btn-secondary" @click="addExpense">Add</button>
              </div>
              <table v-if="finance.expenses.length" class="pd-reg-table">
                <thead><tr><th>Item</th><th>Category</th><th class="pd-num">Amount</th><th>Date</th><th>Linked to</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="e in finance.expenses" :key="e.id">
                    <td>{{ e.label }}</td>
                    <td><span v-if="e.category" class="pd-cat-chip">{{ e.category }}</span><span v-else class="pd-muted">—</span></td>
                    <td class="pd-num pd-nowrap">{{ fmtMoney(e.amount, e.currency) }}</td>
                    <td class="pd-nowrap">{{ e.spent_on || "—" }}</td>
                    <td>
                      <span v-if="e.task" class="pd-link-chip" title="Task">◆ {{ e.task }}</span>
                      <span v-else-if="e.travel" class="pd-link-chip" title="Travel">✈ {{ e.travel }}</span>
                      <span v-else class="pd-muted">—</span>
                    </td>
                    <td><button class="pd-x" @click="deleteExpense(e.id)">✕</button></td>
                  </tr>
                </tbody>
              </table>
              <p v-else class="pd-empty-inline">No expenses recorded.</p>
            </div>
          </div>

          <!-- Vendors -->
          <div class="pd-reg-block">
            <div class="pd-reg-head"><h3>Vendors</h3></div>
            <div class="pd-inline-add">
              <select v-model="newVendor.vendor_partner_id" class="pd-input pd-grow">
                <option value="">— Known partner —</option>
                <option v-for="p in vendors?.partners ?? []" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
              </select>
              <span class="pd-or">or</span>
              <input v-model="newVendor.name" class="pd-input pd-grow" placeholder="Type a vendor name…" @keyup.enter="addVendor" />
              <button class="btn-secondary" @click="addVendor">Add vendor</button>
            </div>
            <div v-if="vendors?.data.length" class="pd-chips">
              <span v-for="v in vendors.data" :key="v.id" class="pd-chip">
                {{ v.name }}<span v-if="v.partner" class="pd-chip-tag">partner</span>
                <button class="pd-chip-x" @click="deleteVendor(v.id)">✕</button>
              </span>
            </div>
            <p v-else class="pd-empty-inline">No vendors engaged.</p>
          </div>
        </template>
        <p v-else class="pd-empty">Loading…</p>
      </section>

      <!-- ── Bill of Materials ───────────────────────────────────────── -->
      <section v-else-if="tab === 'bom'" class="pd-panel">
        <template v-if="bom">
          <div class="pd-panel-actions">
            <h3 class="pd-h3">Bill of Materials</h3>
            <span :class="['pill', bom.bom.status === 'approved' ? 'pill-ok' : 'pill-neutral']">{{ titleCase(bom.bom.status) }}</span>
            <span v-if="bom.bom.approved_by" class="pd-hint">Approved by {{ bom.bom.approved_by }}<template v-if="bom.bom.approved_at"> · {{ bom.bom.approved_at.slice(0, 10) }}</template></span>
            <span class="pd-spacer" style="flex:1"></span>
            <input ref="bomImportInput" type="file" accept=".csv,text/csv" class="pd-hidden-file" @change="importBom" />
            <button v-if="bom.bom.status !== 'approved'" class="btn-secondary btn-sm" @click="bomImportInput?.click()">Import CSV</button>
            <button v-if="bom.bom.status !== 'approved'" class="btn-primary btn-sm" :disabled="!bom.items.length" @click="approveBom">Approve BOM</button>
            <button v-else class="btn-secondary btn-sm" @click="reopenBom">Reopen</button>
          </div>

          <!-- Order release — the gate deciding when ordering may begin -->
          <div v-if="orderRelease" class="pd-release">
            <div class="pd-release-head">
              <span class="pd-release-title">Order release</span>
              <span :class="['pill', orderRelease.readiness.ready ? 'pill-ok' : 'pill-warn']">{{ orderRelease.readiness.ready ? "Released" : "Held" }}</span>
              <span v-if="!orderRelease.readiness.ready && orderRelease.readiness.blocker" class="pd-hint">{{ orderRelease.readiness.blocker }}</span>
              <span class="pd-spacer" style="flex:1"></span>
              <label class="pd-release-gate">Gate:
                <select :value="orderRelease.settings.order_release_gate" class="control pd-inline-select" @change="saveOrderRelease({ order_release_gate: ($event.target as HTMLSelectElement).value })">
                  <option v-for="(lbl, key) in gateLabels" :key="key" :value="key">{{ lbl }}</option>
                </select>
              </label>
              <span v-if="orderRelease.readiness.suggested_gate && orderRelease.readiness.suggested_gate !== orderRelease.settings.order_release_gate" class="pd-hint">suggested: {{ gateLabels[orderRelease.readiness.suggested_gate] }}</span>
            </div>

            <div class="pd-release-signals">
              <span :class="['pd-sig', orderRelease.readiness.awarded ? 'ok' : 'off']">{{ orderRelease.readiness.awarded ? "✓" : "○" }} Awarded</span>
              <span :class="['pd-sig', orderRelease.readiness.contract_ready ? 'ok' : 'off']">
                {{ orderRelease.readiness.contract_ready ? "✓" : "○" }} Contract ready
                <label class="pd-sig-override" title="Confirm/deny contract readiness (overrides auto-detect)">
                  <input type="checkbox" :checked="orderRelease.settings.contract_ready_override === true" @change="saveOrderRelease({ contract_ready_override: ($event.target as HTMLInputElement).checked ? true : null })" /> confirm
                </label>
              </span>
              <span :class="['pd-sig', orderRelease.readiness.lpo_received ? 'ok' : 'off']">{{ orderRelease.readiness.lpo_received ? "✓" : "○" }} Customer LPO</span>
              <button v-if="orderRelease.bom_approved && orderRelease.readiness.ready && !orderRelease.orders_exist" class="pd-appr-btn pd-mini-good" @click="generateOrdersNow">Generate orders now</button>
            </div>

            <div class="pd-release-lpo">
              <span class="pd-release-lpo-l">Customer LPO:</span>
              <input v-model="lpoForm.number" class="control" placeholder="LPO number" style="width:150px" />
              <input v-model="lpoForm.date" type="date" class="control" title="LPO date" />
              <input v-model="lpoForm.value" type="number" min="0" class="control" placeholder="Value" style="width:130px" />
              <button class="btn-secondary btn-sm" @click="saveLpo">Save LPO</button>
            </div>
          </div>

          <div v-if="bom.items.length" class="pd-bom-totals">
            <div class="pd-bom-kpi"><span class="pd-bom-n">{{ bom.summary.lines }}</span><span class="pd-bom-l">Lines</span></div>
            <div class="pd-bom-kpi"><span class="pd-bom-n">{{ fmtMoney(bom.summary.total_cost, "KES") }}</span><span class="pd-bom-l">Total cost</span></div>
            <div class="pd-bom-kpi"><span class="pd-bom-n">{{ fmtMoney(bom.summary.total_value, "KES") }}</span><span class="pd-bom-l">Order value</span></div>
            <div class="pd-bom-kpi"><span class="pd-bom-n">{{ fmtMoney(bom.summary.total_margin, "KES") }}</span><span class="pd-bom-l">Margin</span></div>
          </div>

          <div class="pd-card pd-table-card">
            <table class="pd-table pd-bom-table">
              <thead><tr><th>#</th><th>Category</th><th>Description</th><th>Part no.</th><th>Supplier</th><th class="pd-num">Qty</th><th class="pd-num">Unit cost</th><th class="pd-num">Sell</th><th class="pd-num">Value</th><th class="pd-num">Margin</th><th></th></tr></thead>
              <tbody>
                <tr v-for="it in bom.items" :key="it.id">
                  <td class="pd-muted">{{ it.line_no }}</td>
                  <td><span class="pd-cat-chip">{{ bomCategoryLabel(it.category) }}</span></td>
                  <td>{{ it.description }}</td>
                  <td class="pd-nowrap">{{ it.part_number ?? "—" }}</td>
                  <td>{{ it.supplier ?? "—" }}</td>
                  <td class="pd-num">{{ it.quantity }}{{ it.unit ? " " + it.unit : "" }}</td>
                  <td class="pd-num pd-nowrap">{{ fmtMoney(it.unit_cost, it.currency) }}</td>
                  <td class="pd-num pd-nowrap">{{ fmtMoney(it.selling_price, it.currency) }}</td>
                  <td class="pd-num pd-nowrap">{{ fmtMoney(it.order_value, it.currency) }}</td>
                  <td class="pd-num pd-nowrap" :class="it.margin < 0 ? 'pd-var-bad' : 'pd-var-good'">{{ fmtMoney(it.margin, it.currency) }}</td>
                  <td><button v-if="bom.bom.status !== 'approved'" class="pd-x" @click="deleteBomItem(it.id)">✕</button></td>
                </tr>
                <tr v-if="!bom.items.length"><td colspan="11" class="pd-muted">No lines yet — add them below or import a CSV.</td></tr>
              </tbody>
            </table>
          </div>

          <div v-if="bom.bom.status !== 'approved'" class="pd-bom-add">
            <select v-model="newBomItem.category" class="control">
              <option v-for="c in bom.options.categories" :key="c" :value="c">{{ bomCategoryLabel(c) }}</option>
            </select>
            <input v-model="newBomItem.description" class="control pd-grow" placeholder="Description…" @keyup.enter="addBomItem" />
            <input v-model="newBomItem.part_number" class="control" placeholder="Part no." style="width:110px" />
            <input v-model="newBomItem.quantity" type="number" min="0" step="0.01" class="control" placeholder="Qty" style="width:80px" />
            <input v-model="newBomItem.unit_cost" type="number" min="0" step="0.01" class="control" placeholder="Unit cost" style="width:110px" />
            <input v-model="newBomItem.selling_price" type="number" min="0" step="0.01" class="control" placeholder="Sell" style="width:110px" />
            <button class="btn-primary btn-sm" :disabled="!newBomItem.description.trim()" @click="addBomItem">Add line</button>
          </div>
          <p v-if="bom.bom.status === 'approved'" class="pd-hint" style="margin-top:12px">Approved — orders will be generated from these lines. Reopen to edit.</p>
        </template>
        <p v-else class="pd-empty">Loading…</p>
      </section>

      <!-- ── Supplier material orders & delivery ─────────────────────── -->
      <section v-else-if="tab === 'orders'" class="pd-panel">
        <!-- Orders generated from the BOM -->
        <div v-if="genOrders && genOrders.data.length" class="pd-reg-block" style="margin-bottom:24px">
          <div class="pd-reg-head"><h3>Orders from the BOM</h3><span class="pd-reg-count">{{ genOrders.summary.count }}</span></div>
          <div class="pd-bom-totals">
            <div class="pd-bom-kpi"><span class="pd-bom-n">{{ genOrders.summary.active }}</span><span class="pd-bom-l">Active</span></div>
            <div class="pd-bom-kpi"><span class="pd-bom-n">{{ genOrders.summary.delivered }}</span><span class="pd-bom-l">Delivered+</span></div>
            <div class="pd-bom-kpi" :class="{ 'pd-rd-hot': genOrders.summary.unlinked }"><span class="pd-bom-n">{{ genOrders.summary.unlinked }}</span><span class="pd-bom-l">Not yet routed</span></div>
            <div class="pd-bom-kpi"><span class="pd-bom-n">{{ fmtMoney(genOrders.summary.total_value, "KES") }}</span><span class="pd-bom-l">Order value</span></div>
            <div class="pd-bom-kpi"><span class="pd-bom-n">{{ fmtMoney(genOrders.summary.total_margin, "KES") }}</span><span class="pd-bom-l">Margin</span></div>
          </div>
          <div class="pd-card pd-table-card">
            <table class="pd-table">
              <thead><tr><th>Ref</th><th>Category</th><th>Description</th><th class="pd-num">Qty</th><th class="pd-num">Value</th><th>Fulfilled by</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="o in genOrders.data" :key="o.id">
                  <td class="pd-nowrap">{{ o.reference }}</td>
                  <td><span class="pd-cat-chip">{{ bomCategoryLabel(o.category) }}</span></td>
                  <td>{{ o.description }}</td>
                  <td class="pd-num">{{ o.quantity }}</td>
                  <td class="pd-num pd-nowrap">{{ fmtMoney(o.order_value, o.currency) }}</td>
                  <td>
                    <span v-if="o.fulfilled_by" class="pd-link-chip" :title="o.fulfilled_by.kind">
                      {{ o.fulfilled_by.reference || o.fulfilled_by.kind }}<template v-if="o.fulfilled_by.status"> · {{ titleCase(o.fulfilled_by.status) }}</template>
                    </span>
                    <button v-else-if="o.can_raise" class="pd-appr-btn pd-mini-good" @click="raiseGenOrder(o)">Raise</button>
                    <span v-else class="pd-muted">—</span>
                  </td>
                  <td>
                    <select :value="o.status" class="control pd-proc-approve" @change="setGenOrderStatus(o, ($event.target as HTMLSelectElement).value)">
                      <option value="draft">Draft</option><option value="pending_approval">Pending approval</option>
                      <option value="approved">Approved</option><option value="po_released">PO released</option>
                      <option value="ordered">Ordered</option><option value="fulfilled">Fulfilled</option>
                      <option value="delivered">Delivered</option><option value="accepted">Accepted</option>
                      <option value="closed">Closed</option><option value="cancelled">Cancelled</option>
                    </select>
                    <div v-if="o.accepted_by" class="pd-appr-sub" :title="o.acceptance_note ?? ''">✓ {{ o.accepted_by }}</div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="pd-reg-block">
          <div class="pd-reg-head"><h3>Raise a material order</h3></div>
          <div class="pd-inline-add">
            <input v-model="newOrder.name" class="pd-input pd-grow" placeholder="What is being ordered…" @keyup.enter="addOrder" />
            <select v-model="newOrder.vendor_partner_id" class="pd-input">
              <option value="">— Supplier (partner) —</option>
              <option v-for="p in orderPartners" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
            </select>
            <input v-model="newOrder.supplier_name" class="pd-input" placeholder="or supplier name" />
            <input v-model="newOrder.lpo_reference" class="pd-input pd-ccy" style="width:110px" placeholder="LPO no." />
            <input v-model="newOrder.due_date" type="date" class="pd-input" />
            <button class="btn-secondary" @click="addOrder">Add order</button>
          </div>
        </div>

        <p v-if="!orders.length" class="pd-empty-inline">No material orders yet.</p>

        <div v-for="o in orders" :key="o.id" class="pd-reg-block pd-order">
          <div class="pd-order-head" @click="openOrder = openOrder === o.id ? null : o.id">
            <span class="pd-order-ref">{{ o.reference ?? "—" }}</span>
            <span class="pd-order-name">{{ o.name }}</span>
            <span class="pd-tag">{{ o.supplier?.name ?? o.supplier_name ?? "No supplier" }}</span>
            <span v-if="o.due_date" :class="['pd-nowrap', 'pd-order-due', { 'pd-late': o.is_overdue }]">Due {{ o.due_date }}<template v-if="o.is_overdue"> · overdue</template></span>
            <select :value="o.status" class="pd-mini" @click.stop @change="setOrderStatus(o, ($event.target as HTMLSelectElement).value)">
              <option value="pending">Pending</option><option value="ordered">Ordered</option>
              <option value="in_transit">In transit</option><option value="delivered">Delivered</option>
              <option value="cancelled">Cancelled</option>
            </select>
            <button class="pd-x" title="Remove order" @click.stop="deleteOrder(o.id)">✕</button>
            <span class="pd-order-caret">{{ openOrder === o.id ? "▾" : "▸" }}</span>
          </div>

          <div v-if="openOrder === o.id" class="pd-order-body">
            <p v-if="o.lpo_reference" class="pd-order-lpo">LPO: <strong>{{ o.lpo_reference }}</strong></p>

            <div class="pd-order-cols">
              <!-- Tracker -->
              <div class="pd-order-sec">
                <h4>Tracker</h4>
                <ul v-if="o.trackers.length" class="pd-order-timeline">
                  <li v-for="t in o.trackers" :key="t.id">
                    <span class="pd-ot-date">{{ t.happened_on || "—" }}</span>
                    <span class="pd-ot-body"><strong>{{ t.name }}</strong><template v-if="t.details"> — {{ t.details }}</template></span>
                    <button class="pd-x" @click="deleteTracker(o, t.id)">✕</button>
                  </li>
                </ul>
                <p v-else class="pd-empty-inline">No checkpoints yet.</p>
                <div class="pd-inline-add pd-order-add">
                  <input v-model="newTracker.name" class="pd-input pd-grow" placeholder="Checkpoint…" />
                  <input v-model="newTracker.happened_on" type="date" class="pd-input" />
                  <button class="btn-secondary" @click="addTracker(o)">Add</button>
                </div>
              </div>

              <!-- Delivery updates -->
              <div class="pd-order-sec">
                <h4>Delivery updates</h4>
                <ul v-if="o.updates.length" class="pd-order-updates">
                  <li v-for="u in o.updates" :key="u.id">
                    <div v-if="u.latest_supplier_update"><span class="pd-ou-label">Supplier:</span> {{ u.latest_supplier_update }}</div>
                    <div v-if="u.delivery_plan_status"><span class="pd-ou-label">Plan:</span> {{ u.delivery_plan_status }}</div>
                    <div class="pd-ou-meta">{{ u.by || "" }}<template v-if="u.at"> · {{ u.at }}</template><button class="pd-x" @click="deleteUpdate(o, u.id)">✕</button></div>
                  </li>
                </ul>
                <p v-else class="pd-empty-inline">No updates yet.</p>
                <div class="pd-order-add">
                  <input v-model="newUpdate.latest_supplier_update" class="pd-input pd-block" placeholder="Latest supplier update…" />
                  <input v-model="newUpdate.delivery_plan_status" class="pd-input pd-block" placeholder="Delivery plan status…" />
                  <button class="btn-secondary" @click="addUpdate(o)">Add update</button>
                </div>
              </div>

              <!-- Logistics contacts -->
              <div class="pd-order-sec">
                <h4>Logistics contacts</h4>
                <ul v-if="o.contacts.length" class="pd-order-contacts">
                  <li v-for="c in o.contacts" :key="c.id">
                    <strong>{{ c.name }}</strong>
                    <span v-if="c.email" class="pd-oc-x">{{ c.email }}</span>
                    <span v-if="c.phone" class="pd-oc-x">{{ c.phone }}</span>
                    <span v-if="c.address" class="pd-oc-x">{{ c.address }}</span>
                    <button class="pd-x" @click="deleteContact(o, c.id)">✕</button>
                  </li>
                </ul>
                <p v-else class="pd-empty-inline">No contacts yet.</p>
                <div class="pd-order-add">
                  <input v-model="newContact.name" class="pd-input pd-block" placeholder="Contact name…" />
                  <div class="pd-inline-add">
                    <input v-model="newContact.email" class="pd-input pd-grow" placeholder="Email" />
                    <input v-model="newContact.phone" class="pd-input pd-grow" placeholder="Phone" />
                  </div>
                  <input v-model="newContact.address" class="pd-input pd-block" placeholder="Address" />
                  <button class="btn-secondary" @click="addContact(o)">Add contact</button>
                </div>
              </div>

              <!-- Documents -->
              <div class="pd-order-sec">
                <h4>Documents (LPO, delivery notes)</h4>
                <ul v-if="o.documents.length" class="pd-order-docs">
                  <li v-for="d in o.documents" :key="d.id">
                    <a :href="orderDocUrl(o, d.id)" target="_blank" rel="noopener" class="link-btn">📄 {{ d.label }}</a>
                    <button class="pd-x" @click="deleteOrderDoc(o, d.id)">✕</button>
                  </li>
                </ul>
                <p v-else class="pd-empty-inline">No documents attached.</p>
                <label class="pd-doc-add">
                  <input type="file" class="pd-hidden-file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.zip" @change="(e) => uploadOrderDoc(o, e)" />
                  <span>{{ orderDocUploading === o.id ? "Uploading…" : "+ Attach document" }}</span>
                </label>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ── Training & Enablement ───────────────────────────────────── -->
      <section v-else-if="tab === 'training'" class="pd-panel">
        <template v-if="training">
          <p v-if="!training.data.length" class="pd-empty">
            No training to plan. A <strong>training</strong> line on the BOM becomes an order — raise it on the Orders tab to activate its enablement plan here.
          </p>
          <div v-for="f in training.data" :key="f.id" class="pd-train-card">
            <div class="pd-train-head">
              <h3 class="pd-h3">{{ f.title }}</h3>
              <span v-if="f.order_reference" class="pd-link-chip">{{ f.order_reference }}</span>
              <span class="pd-spacer" style="flex:1"></span>
              <select :value="f.status" class="control pd-inline-select" @change="updateFulfillment(f, { status: ($event.target as HTMLSelectElement).value })">
                <option v-for="s in training.options.statuses" :key="s" :value="s">{{ titleCase(s) }}</option>
              </select>
            </div>

            <div class="pd-train-grid">
              <label class="pd-train-field">Course
                <select :value="f.training_item_id ?? ''" class="control" @change="updateFulfillment(f, { training_item_id: ($event.target as HTMLSelectElement).value || null })">
                  <option value="">— Pick from catalog —</option>
                  <option v-for="c in training.catalog" :key="c.id" :value="c.id">{{ c.title }}{{ c.code ? ' (' + c.code + ')' : '' }}</option>
                </select>
              </label>
              <label class="pd-train-field">Cost / person
                <input :value="f.cost_per_person" type="number" min="0" class="control" @change="updateFulfillment(f, { cost_per_person: Number(($event.target as HTMLInputElement).value) })" />
              </label>
              <label class="pd-train-field">Mode
                <select :value="f.mode ?? ''" class="control" @change="updateFulfillment(f, { mode: ($event.target as HTMLSelectElement).value || null })">
                  <option value="">—</option>
                  <option v-for="m in training.options.modes" :key="m" :value="m">{{ titleCase(m) }}</option>
                </select>
              </label>
              <div class="pd-train-totals">
                <div><span class="pd-bom-n">{{ f.trainee_count }}</span><span class="pd-bom-l">trainees</span></div>
                <div><span class="pd-bom-n">{{ fmtMoney(f.total_cost, f.currency) }}</span><span class="pd-bom-l">total (course + travel)</span></div>
              </div>
            </div>

            <h5 class="pd-h5">Parties to be trained</h5>
            <table v-if="f.trainees.length" class="pd-reg-table">
              <thead><tr><th>Party</th><th>Name</th><th>Designation</th><th>Travel</th><th class="pd-num">Travel cost</th><th></th></tr></thead>
              <tbody>
                <tr v-for="t in f.trainees" :key="t.id">
                  <td><span class="pd-cat-chip">{{ partyLabel(t.party) }}</span></td>
                  <td>{{ t.name }}<span v-if="t.email" class="pd-appr-sub">{{ t.email }}</span></td>
                  <td>{{ t.designation ?? "—" }}</td>
                  <td>{{ t.needs_travel ? (t.travel_location || "Yes") : "—" }}</td>
                  <td class="pd-num pd-nowrap">{{ t.travel_cost != null ? fmtMoney(t.travel_cost, f.currency) : "—" }}</td>
                  <td><button class="pd-x" @click="deleteTrainee(f, t.id)">✕</button></td>
                </tr>
              </tbody>
            </table>
            <p v-else class="pd-empty-inline">No trainees yet.</p>

            <div v-if="newTrainee[f.id]" class="pd-train-add">
              <select v-model="newTrainee[f.id].party" class="control" style="width:120px">
                <option v-for="p in training.options.parties" :key="p" :value="p">{{ partyLabel(p) }}</option>
              </select>
              <div v-if="newTrainee[f.id].party === 'internal'" class="pd-train-picker">
                <EmployeePicker v-model="newTrainee[f.id].employee_id" placeholder="Pick employee…" />
              </div>
              <input v-else v-model="newTrainee[f.id].name" class="control pd-grow" placeholder="Name" />
              <input v-if="newTrainee[f.id].party !== 'internal'" v-model="newTrainee[f.id].email" class="control" placeholder="Email" style="width:170px" />
              <label class="pd-train-check"><input type="checkbox" v-model="newTrainee[f.id].needs_travel" /> Travel</label>
              <input v-if="newTrainee[f.id].needs_travel" v-model="newTrainee[f.id].travel_location" class="control" placeholder="Location" style="width:130px" />
              <input v-if="newTrainee[f.id].needs_travel" v-model="newTrainee[f.id].travel_cost" type="number" min="0" class="control" placeholder="Travel cost" style="width:120px" />
              <button class="btn-primary btn-sm" @click="addTrainee(f)">Add</button>
            </div>
          </div>
        </template>
        <p v-else class="pd-empty">Loading…</p>
      </section>

      <!-- ── Weekly Report (RAG · OKRs · history) ────────────────────── -->
      <section v-else-if="tab === 'weekly'" class="pd-panel">
        <template v-if="weekly">
          <div class="pd-wk-toolbar">
            <button class="btn-secondary" :disabled="exportingPdf" @click="exportWeeklyPdf">
              {{ exportingPdf ? "Building…" : "⭳ Export PDF" }}
            </button>
          </div>
          <!-- Status banner: RAG-accented cover with a progress ring -->
          <div :class="['pd-wk-banner', 'rag-' + weekly.live.rag]">
            <div class="pd-wk-ring-wrap">
              <svg class="pd-wk-ring" viewBox="0 0 120 120" role="img" :aria-label="`${weekly.live.progress_pct}% complete`">
                <circle class="pd-wk-ring-track" cx="60" cy="60" r="52" />
                <circle class="pd-wk-ring-fill" cx="60" cy="60" r="52"
                  stroke-dasharray="326.73"
                  :stroke-dashoffset="326.73 * (1 - weekly.live.progress_pct / 100)" />
                <text x="60" y="58" class="pd-wk-ring-pct">{{ weekly.live.progress_pct }}%</text>
                <text x="60" y="78" class="pd-wk-ring-lbl">complete</text>
              </svg>
            </div>
            <div class="pd-wk-banner-body">
              <div class="pd-wk-banner-top">
                <span :class="['pd-rag', 'pd-rag-' + weekly.live.rag]"><span class="pd-rag-dot"></span>{{ weekly.live.rag.toUpperCase() }}</span>
                <span class="pd-wk-week">Week of {{ weekly.week_start }}</span>
                <span :class="weekly.published ? 'pd-wk-badge-pub' : 'pd-wk-badge-draft'">{{ weekly.published ? "Published" : "Draft" }}</span>
              </div>
              <div class="pd-wk-banner-headline">{{ weekly.live.completed_tasks }} of {{ weekly.live.total_tasks }} tasks complete</div>
              <div v-if="weekly.deltas" :class="['pd-wk-delta', weekly.deltas.progress_pct > 0 ? 'up' : weekly.deltas.progress_pct < 0 ? 'down' : 'flat']">
                <template v-if="weekly.deltas.progress_pct !== 0">{{ weekly.deltas.progress_pct > 0 ? "▲" : "▼" }} {{ Math.abs(weekly.deltas.progress_pct) }} pts vs last week</template>
                <template v-else>No change vs last week</template>
                <span v-if="weekly.deltas.rag_changed" class="pd-wk-ragmoved">RAG was {{ weekly.deltas.rag_was.toUpperCase() }}</span>
              </div>
              <div v-else class="pd-wk-delta flat">First reported week — nothing to compare against yet.</div>
            </div>
          </div>

          <!-- Live signal chips, coloured by whether they need attention -->
          <div class="pd-wk-signals">
            <div :class="['pd-wk-sig', weekly.live.overdue_tasks ? 'bad' : 'ok']"><b>{{ weekly.live.overdue_tasks }}</b><span>Overdue</span></div>
            <div :class="['pd-wk-sig', weekly.live.open_issues ? 'warn' : 'ok']"><b>{{ weekly.live.open_issues }}</b><span>Open issues</span></div>
            <div :class="['pd-wk-sig', weekly.live.open_dependencies ? 'warn' : 'ok']"><b>{{ weekly.live.open_dependencies }}</b><span>Dependencies</span></div>
            <div class="pd-wk-sig"><b>{{ weekly.live.okrs?.length ?? 0 }}</b><span>Objectives</span></div>
          </div>

          <div class="pd-wk-cols">
            <!-- This week's objectives (OKRs) -->
            <div class="pd-wk-card">
              <div class="pd-wk-card-head accent-brand"><h3>This week's objectives</h3></div>
              <ul v-if="weekly.live.okrs?.length" class="pd-wk-okrs">
                <li v-for="o in weekly.live.okrs" :key="o.id" :class="{ done: o.done }">
                  <span class="pd-wk-okr-tick">{{ o.done ? "✓" : "○" }}</span>
                  <span class="pd-wk-okr-title">{{ o.title }}</span>
                  <span :class="['pill', `pill-${statusTone(o.status)}`]">{{ titleCase(o.status) }}</span>
                </li>
              </ul>
              <p v-else class="pd-wk-empty">No tasks flagged as weekly objectives.<br /><small>Open a task and tick “Weekly objective (OKR)”.</small></p>
            </div>

            <!-- Key risks digest -->
            <div class="pd-wk-card">
              <div class="pd-wk-card-head accent-warn"><h3>Key risks</h3></div>
              <ul v-if="weekly.live.risks?.length" class="pd-wk-risks">
                <li v-for="r in weekly.live.risks" :key="r.id">
                  <span :class="['pd-heat', r.score >= 6 ? 'pd-heat-hi' : r.score >= 3 ? 'pd-heat-mid' : 'pd-heat-lo']">{{ r.score }}</span>
                  <span class="pd-wk-risk-title">{{ r.title }}</span>
                  <span class="pd-tag">{{ titleCase(r.status) }}</span>
                </li>
              </ul>
              <p v-else class="pd-wk-empty">No open risks on the register.</p>
            </div>
          </div>

          <!-- Publish -->
          <div class="pd-wk-card pd-wk-publish-card">
            <div class="pd-wk-card-head accent-brand">
              <h3>{{ weekly.published ? "Update this week" : "Publish this week" }}</h3>
              <span class="pd-wk-publish-hint">{{ weekly.published
                ? "Re-publishing overwrites this week with the current figures."
                : "Records an immutable snapshot for week-over-week history." }}</span>
            </div>
            <textarea v-model="weeklySummary" class="pd-input pd-wk-note" rows="3"
              :placeholder="weekly.published ? 'Update this week\'s narrative…' : 'A short narrative for this week (optional)…'"></textarea>
            <div class="pd-wk-publish-actions">
              <button class="btn-primary" :disabled="publishing" @click="publishWeek">
                {{ publishing ? "Publishing…" : weekly.published ? "Re-publish this week" : "Publish this week" }}
              </button>
            </div>
          </div>

          <!-- History -->
          <div class="pd-wk-card pd-wk-history">
            <div class="pd-wk-card-head"><h3>Published history</h3><span class="pd-reg-count">{{ weeklyHistory.length }}</span></div>
            <table v-if="weeklyHistory.length" class="pd-reg-table pd-wk-htable">
              <thead><tr><th>Week</th><th>RAG</th><th class="pd-num">Progress</th><th class="pd-num">Done</th><th>Summary</th><th>By</th><th></th></tr></thead>
              <tbody>
                <tr v-for="s in weeklyHistory" :key="s.id">
                  <td class="pd-nowrap">{{ s.week_start }}</td>
                  <td><span :class="['pd-rag-chip', 'pd-rag-' + s.rag]">{{ s.rag.toUpperCase() }}</span></td>
                  <td class="pd-num">
                    <span class="pd-wk-hbar"><span class="pd-wk-hbar-fill" :style="{ width: s.progress_pct + '%' }"></span></span>
                    {{ s.progress_pct }}%
                  </td>
                  <td class="pd-num pd-nowrap">{{ s.completed_tasks }}/{{ s.total_tasks }}</td>
                  <td class="pd-wk-hsummary">{{ s.summary || "—" }}</td>
                  <td class="pd-nowrap">{{ s.published_by || "—" }}</td>
                  <td><button class="pd-x" @click="s.id && deleteWeek(s.id)">✕</button></td>
                </tr>
              </tbody>
            </table>
            <p v-else class="pd-wk-empty">Nothing published yet — publish this week to start the trend.</p>
          </div>
        </template>
        <p v-else class="pd-empty">Loading…</p>
      </section>

      <!-- ── Correspondence — mailbox + Teams meetings ───────────────── -->
      <section v-else-if="tab === 'correspondence'" class="pd-panel">
        <!-- Project mailbox -->
        <div class="pd-card">
          <h3 class="pd-h3">Project mailbox</h3>
          <p class="pd-hint">The shared email this project corresponds from. Used as the organiser for meetings when set.</p>
          <div class="pd-inline-add">
            <input v-model="project.project_email" class="pd-input pd-grow" type="email" placeholder="project@company.com" @keyup.enter="saveProjectEmail" />
            <button class="btn-secondary" :disabled="savingEmail" @click="saveProjectEmail">{{ savingEmail ? "Saving…" : "Save" }}</button>
          </div>
        </div>

        <!-- Inbound email -->
        <div class="pd-reg-block">
          <div class="pd-reg-head">
            <h3>Inbox</h3><span class="pd-reg-count">{{ emails.length }}</span>
            <button class="btn-secondary pd-ms-schedule" :disabled="fetchingEmail || !project?.project_email" @click="fetchEmails">
              {{ fetchingEmail ? "Fetching…" : "⭳ Fetch emails" }}
            </button>
          </div>
          <p v-if="!project?.project_email" class="pd-empty-inline">Set the project mailbox above to pull its inbox.</p>
          <p v-else-if="!emails.length" class="pd-empty-inline">No email pulled yet — hit “Fetch emails”.</p>
          <ul v-else class="pd-emails">
            <li v-for="em in emails" :key="em.id" class="pd-email">
              <div class="pd-email-row" @click="openEmail = openEmail === em.id ? null : em.id">
                <span class="pd-email-from">{{ em.from_name || em.from_email || "Unknown" }}</span>
                <span class="pd-email-subject">{{ em.subject || "(no subject)" }}
                  <span v-if="em.has_attachments" class="pd-tag">📎</span>
                </span>
                <span class="pd-email-when">{{ emailWhen(em.received_at) }}</span>
                <span class="pd-order-caret">{{ openEmail === em.id ? "▾" : "▸" }}</span>
              </div>
              <div v-if="openEmail !== em.id" class="pd-email-preview">{{ em.body_preview }}</div>
              <!-- Untrusted external HTML — rendered in a scripts-off sandbox. -->
              <iframe v-else class="pd-email-body" sandbox="" :srcdoc="em.body_html || em.body_preview || ''"></iframe>
            </li>
          </ul>
        </div>

        <!-- Meetings -->
        <div class="pd-reg-block">
          <div class="pd-reg-head">
            <h3>Meetings &amp; calendar</h3><span class="pd-reg-count">{{ meetings.length }}</span>
            <button class="btn-primary pd-ms-schedule" @click="openMeetingDialog">+ Schedule meeting</button>
          </div>

          <p v-if="!meetings.length" class="pd-empty-inline">No meetings scheduled yet.</p>
          <ul v-else class="pd-meetings">
            <li v-for="m in meetings" :key="m.id" :class="['pd-meeting', { cancelled: m.status === 'cancelled' }]">
              <div class="pd-meeting-row">
                <div class="pd-meeting-when">
                  <span class="pd-meeting-date">{{ meetingWhen(m.scheduled_at) }}</span>
                  <span class="pd-meeting-dur">{{ m.duration_minutes }} min</span>
                </div>
                <div class="pd-meeting-body">
                  <div class="pd-meeting-subject">{{ m.subject }}
                    <span v-if="m.status === 'cancelled'" class="pd-tag">cancelled</span>
                    <span v-if="m.has_transcript" class="pd-tag pd-tag-ok">transcript</span>
                  </div>
                  <div class="pd-meeting-att">
                    <span v-for="a in m.attendees" :key="a.address" class="pd-att-chip" :title="a.address">{{ a.name }}</span>
                  </div>
                </div>
                <div class="pd-meeting-actions">
                  <a v-if="m.join_url && m.status !== 'cancelled'" :href="m.join_url" target="_blank" rel="noopener" class="btn-secondary pd-join">Join</a>
                  <a v-if="m.recording_url" :href="m.recording_url" target="_blank" rel="noopener" class="link-btn">▶ Watch recording</a>
                  <button v-if="m.has_transcript" class="link-btn" @click="openTranscript = openTranscript === m.id ? null : m.id">
                    {{ openTranscript === m.id ? "Hide transcript" : "Transcript" }}
                  </button>
                  <button v-if="m.status !== 'cancelled' && meetingHasPassed(m)" class="link-btn" :disabled="syncingMeeting === m.id" @click="syncMeeting(m)">
                    {{ syncingMeeting === m.id ? "Fetching…" : "Fetch recording" }}
                  </button>
                  <button v-if="m.status !== 'cancelled'" class="link-btn link-danger" @click="cancelMeeting(m)">Cancel</button>
                </div>
              </div>
              <pre v-if="openTranscript === m.id && m.transcript" class="pd-transcript">{{ m.transcript }}</pre>
            </li>
          </ul>
        </div>
      </section>

      <!-- ── Comments ────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'comments'" class="pd-panel">
        <div class="pd-card">
          <p v-if="replyTo" class="pd-replying">
            Replying to {{ replyTo.author.name }}
            <button class="link-btn" @click="replyTo = null">cancel</button>
          </p>
          <textarea v-model="newComment" class="control" rows="3"
                    placeholder="Add a comment…" @keyup.ctrl.enter="postComment"></textarea>
          <div class="pd-actions">
            <button class="btn-primary" :disabled="saving || !newComment.trim()" @click="postComment">Post</button>
          </div>
        </div>

        <p v-if="!comments.length" class="pd-empty">Nothing said yet.</p>

        <ul v-else class="pd-comments">
          <li v-for="c in comments" :key="c.id" class="pd-comment">
            <div class="pd-comment-row">
              <span class="pd-avatar">{{ initials(c.author.name) }}</span>
              <div class="pd-comment-body">
                <p class="pd-comment-who">
                  <strong>{{ c.author.name ?? "Somebody" }}</strong>
                  <span class="pd-comment-when">{{ ago(c.created_at) }}</span>
                </p>
                <div class="pd-comment-text rich" v-html="safeHtml(c.body)"></div>
                <button class="link-btn pd-reply" @click="replyTo = c">Reply</button>
              </div>
            </div>

            <ul v-if="c.replies.length" class="pd-replies">
              <li v-for="r in c.replies" :key="r.id" class="pd-comment-row">
                <span class="pd-avatar">{{ initials(r.author.name) }}</span>
                <div class="pd-comment-body">
                  <p class="pd-comment-who">
                    <strong>{{ r.author.name ?? "Somebody" }}</strong>
                    <span class="pd-comment-when">{{ ago(r.created_at) }}</span>
                  </p>
                  <div class="pd-comment-text rich" v-html="safeHtml(r.body)"></div>
                </div>
              </li>
            </ul>
          </li>
        </ul>
      </section>

      <!-- ── Activities ──────────────────────────────────────────────── -->
      <section v-else-if="tab === 'activities'" class="pd-panel">
        <p v-if="!activities.length" class="pd-empty">
          Nothing recorded yet. The timeline fills itself as tasks are added and
          finished — nobody has to remember to write it.
        </p>

        <ul v-else class="pd-feed">
          <li v-for="a in activities" :key="a.id" class="pd-event">
            <span class="pd-avatar">{{ initials(a.actor.name) }}</span>
            <div>
              <p class="pd-event-who">
                <strong>{{ a.actor.name ?? "System" }}</strong>
                <span class="pd-comment-when">{{ ago(a.occurred_at) }}</span>
              </p>
              <div class="pd-comment-text rich" v-html="safeHtml(a.body)"></div>
            </div>
          </li>
        </ul>
      </section>

      <section v-else-if="tab === 'reports'" class="pd-panel pd-reports">
        <div class="pd-panel-actions">
          <h3 class="pd-h3">Governance reports</h3>
          <button class="btn-secondary" @click="printReports">Print / export</button>
        </div>
        <p v-if="!reports" class="pd-empty">Loading…</p>
        <template v-else>
          <!-- Governance committee -->
          <div class="pd-report-block">
            <h4 class="pd-h4">Governance committee structure</h4>
            <div class="pd-card pd-table-card"><table class="pd-table">
              <thead><tr><th>Role</th><th>Holder</th><th>Backup</th><th>Responsibility</th></tr></thead>
              <tbody>
                <tr v-for="r in reports.governance" :key="r.role_key" :class="{ 'pd-report-vacant': r.is_mandatory && !r.holder }">
                  <td>{{ r.role }}<span v-if="r.is_mandatory" class="pd-report-req">*</span></td>
                  <td>{{ r.holder ?? "—" }}</td><td>{{ r.backup ?? "—" }}</td><td>{{ r.responsibility ?? "—" }}</td>
                </tr>
              </tbody>
            </table></div>
          </div>

          <!-- RACI -->
          <div class="pd-report-block">
            <h4 class="pd-h4">Responsibility (RACI) matrix</h4>
            <div class="pd-card pd-table-card"><table class="pd-table">
              <thead><tr><th>Role</th><th>Person (R)</th><th>Responsible for</th><th>Accountable (A)</th><th>Consulted (C)</th><th>Informed (I)</th></tr></thead>
              <tbody>
                <tr v-for="(r, i) in reports.raci" :key="i">
                  <td>{{ r.role }}</td><td>{{ r.person ?? "—" }}</td><td>{{ r.responsible_for ?? "—" }}</td>
                  <td>{{ r.accountable ?? "—" }}</td><td>{{ r.consulted ?? "—" }}</td><td>{{ r.informed ?? "—" }}</td>
                </tr>
                <tr v-if="!reports.raci.length"><td colspan="6" class="pd-muted">Assign governance and team leads to populate this.</td></tr>
              </tbody>
            </table></div>
          </div>

          <!-- Org chart -->
          <div class="pd-report-block">
            <h4 class="pd-h4">Project organization chart</h4>
            <div class="pd-org-leadership">
              <div v-for="(l, i) in reports.org_chart.leadership" :key="i" class="pd-org-node">
                <span class="pd-org-role">{{ l.role }}</span><span class="pd-org-name">{{ l.name ?? "Vacant" }}</span>
              </div>
              <p v-if="!reports.org_chart.leadership.length" class="pd-muted">No leadership assigned.</p>
            </div>
            <div class="pd-org-teams">
              <div v-for="(t, i) in reports.org_chart.teams" :key="i" class="pd-org-team">
                <div class="pd-org-team-name">{{ t.name }}</div>
                <div class="pd-org-lead">Lead: {{ t.lead ?? "—" }}</div>
                <ul><li v-for="(m, j) in t.members" :key="j">{{ m.name }}<span v-if="m.capacity_percent != null" class="pd-org-cap">· {{ m.capacity_percent }}%</span></li></ul>
              </div>
              <p v-if="!reports.org_chart.teams.length" class="pd-muted">No delivery teams yet.</p>
            </div>
          </div>

          <!-- Escalation matrix -->
          <div class="pd-report-block">
            <h4 class="pd-h4">Escalation matrix</h4>
            <div class="pd-card pd-table-card"><table class="pd-table">
              <thead><tr><th>Level</th><th>Role</th><th>Holder</th><th>Escalates to</th></tr></thead>
              <tbody>
                <tr v-for="(e, i) in reports.escalation" :key="i"><td>{{ e.level }}</td><td>{{ e.role }}</td><td>{{ e.holder ?? "—" }}</td><td>{{ e.escalates_to ?? "—" }}</td></tr>
                <tr v-if="!reports.escalation.length"><td colspan="4" class="pd-muted">Set escalation authority on governance roles to populate this.</td></tr>
              </tbody>
            </table></div>
          </div>

          <!-- Stakeholder register -->
          <div class="pd-report-block">
            <h4 class="pd-h4">Stakeholder register</h4>
            <div class="pd-card pd-table-card"><table class="pd-table">
              <thead><tr><th>Stakeholder</th><th>Persona</th><th>Influence</th><th>Interest</th><th>Responsibility</th></tr></thead>
              <tbody>
                <tr v-for="(s, i) in reports.stakeholders" :key="i"><td>{{ s.name }}</td><td>{{ s.persona ?? "—" }}</td><td>{{ s.influence ?? "—" }}</td><td>{{ s.interest ?? "—" }}</td><td>{{ s.responsibility ?? "—" }}</td></tr>
                <tr v-if="!reports.stakeholders.length"><td colspan="5" class="pd-muted">No stakeholders registered.</td></tr>
              </tbody>
            </table></div>
          </div>

          <!-- Customer contact directory -->
          <div class="pd-report-block">
            <h4 class="pd-h4">Customer contact directory <span v-if="reports.customer_directory.organization" class="pd-muted">— {{ reports.customer_directory.organization }}</span></h4>
            <div class="pd-card pd-table-card"><table class="pd-table">
              <thead><tr><th>Name</th><th>Project role</th><th>Category</th><th>Email</th><th>Phone</th></tr></thead>
              <tbody>
                <tr v-for="(c, i) in reports.customer_directory.contacts" :key="i"><td>{{ c.name }}</td><td>{{ c.project_role ?? "—" }}</td><td>{{ c.category }}</td><td>{{ c.email ?? "—" }}</td><td>{{ c.phone ?? "—" }}</td></tr>
                <tr v-if="!reports.customer_directory.contacts.length"><td colspan="5" class="pd-muted">No customer contacts.</td></tr>
              </tbody>
            </table></div>
          </div>

          <!-- Customer escalation matrix -->
          <div class="pd-report-block">
            <h4 class="pd-h4">Customer escalation matrix</h4>
            <div class="pd-card pd-table-card"><table class="pd-table">
              <thead><tr><th>Role</th><th>Contact</th><th>Email</th><th>Escalates to</th></tr></thead>
              <tbody>
                <tr v-for="(e, i) in reports.customer_escalation" :key="i"><td>{{ e.role }}</td><td>{{ e.contact }}</td><td>{{ e.email ?? "—" }}</td><td>{{ e.escalates_to ?? "—" }}</td></tr>
                <tr v-if="!reports.customer_escalation.length"><td colspan="4" class="pd-muted">Assign customer roles to a contact to build this.</td></tr>
              </tbody>
            </table></div>
          </div>

          <!-- Professional services -->
          <div class="pd-report-block">
            <h4 class="pd-h4">Professional services</h4>
            <div class="pd-card pd-table-card"><table class="pd-table">
              <thead><tr><th>Role</th><th>Name</th><th>Provider</th><th>Contact</th><th>Period</th></tr></thead>
              <tbody>
                <tr v-for="(r, i) in reports.professional_services" :key="i"><td>{{ r.role }}</td><td>{{ r.name }}</td><td>{{ r.organization ?? "—" }}</td><td>{{ r.contact_info ?? "—" }}</td><td>{{ r.period ?? "—" }}</td></tr>
                <tr v-if="!reports.professional_services.length"><td colspan="5" class="pd-muted">No professional-services resources.</td></tr>
              </tbody>
            </table></div>
          </div>

          <!-- Subcontractors -->
          <div class="pd-report-block">
            <h4 class="pd-h4">Subcontractors</h4>
            <div class="pd-card pd-table-card"><table class="pd-table">
              <thead><tr><th>Type</th><th>Company</th><th>Contact</th><th>Scope</th><th>Contract</th><th>Approval</th><th>Onboarding</th></tr></thead>
              <tbody>
                <tr v-for="(r, i) in reports.subcontractors" :key="i">
                  <td>{{ r.role }}</td><td>{{ r.company }}</td><td>{{ r.contact_person ?? "—" }}</td><td>{{ r.scope_of_work ?? "—" }}</td><td>{{ r.contract_reference ?? "—" }}</td>
                  <td>{{ titleCase(r.approval_status) }}</td><td>{{ titleCase(r.onboarding_status) }}</td>
                </tr>
                <tr v-if="!reports.subcontractors.length"><td colspan="7" class="pd-muted">No subcontractors.</td></tr>
              </tbody>
            </table></div>
          </div>

          <!-- Risk & Dependency report -->
          <div class="pd-report-block">
            <h4 class="pd-h4">Risk &amp; dependency report</h4>
            <div class="pd-rd-kpis">
              <div class="pd-rd-kpi"><span class="pd-rd-n">{{ reports.risk_dependency.summary.risks_open }}</span><span class="pd-rd-l">Open risks</span></div>
              <div class="pd-rd-kpi" :class="{ 'pd-rd-hot': reports.risk_dependency.summary.risks_high }"><span class="pd-rd-n">{{ reports.risk_dependency.summary.risks_high }}</span><span class="pd-rd-l">High &amp; open</span></div>
              <div class="pd-rd-kpi"><span class="pd-rd-n">{{ reports.risk_dependency.summary.deps_unresolved }}</span><span class="pd-rd-l">Open dependencies</span></div>
              <div class="pd-rd-kpi" :class="{ 'pd-rd-hot': reports.risk_dependency.summary.deps_overdue }"><span class="pd-rd-n">{{ reports.risk_dependency.summary.deps_overdue }}</span><span class="pd-rd-l">Overdue</span></div>
            </div>

            <h5 class="pd-h5">Risk register <span class="pd-muted">— ranked by likelihood × impact</span></h5>
            <div class="pd-card pd-table-card"><table class="pd-table">
              <thead><tr><th>Risk</th><th>Heat</th><th>Likelihood</th><th>Impact</th><th>Status</th><th>Owner</th><th>Against</th><th>Mitigation</th></tr></thead>
              <tbody>
                <tr v-for="r in reports.risk_dependency.risks" :key="'rk' + r.id">
                  <td>{{ r.title }}</td>
                  <td><span :class="['pd-heat', 'pd-heat-' + r.level]">{{ titleCase(r.level) }} · {{ r.score }}</span></td>
                  <td>{{ titleCase(r.likelihood) }}</td><td>{{ titleCase(r.impact) }}</td>
                  <td>{{ titleCase(r.status) }}</td><td>{{ r.owner ?? "—" }}</td><td>{{ r.against ?? "—" }}</td><td>{{ r.mitigation ?? "—" }}</td>
                </tr>
                <tr v-if="!reports.risk_dependency.risks.length"><td colspan="8" class="pd-muted">No risks logged.</td></tr>
              </tbody>
            </table></div>

            <h5 class="pd-h5">Dependency register</h5>
            <div class="pd-card pd-table-card"><table class="pd-table">
              <thead><tr><th>Dependency</th><th>Kind</th><th>Status</th><th>Owner</th><th>Due</th><th>Against</th><th>Waits on</th></tr></thead>
              <tbody>
                <tr v-for="d in reports.risk_dependency.dependencies" :key="'dp' + d.id" :class="{ 'pd-rd-overdue': d.overdue }">
                  <td>{{ d.title }}</td><td>{{ titleCase(d.kind) }}</td>
                  <td>{{ titleCase(d.status) }}<span v-if="d.overdue" class="pd-rd-flag">overdue</span></td>
                  <td>{{ d.owner ?? "—" }}</td><td class="pd-nowrap">{{ d.due_on ?? "—" }}</td><td>{{ d.against ?? "—" }}</td><td>{{ d.waits_on ?? "—" }}</td>
                </tr>
                <tr v-if="!reports.risk_dependency.dependencies.length"><td colspan="7" class="pd-muted">No dependencies logged.</td></tr>
              </tbody>
            </table></div>
          </div>
        </template>
      </section>

      <!-- ── Logistics: Travel Schedule ──────────────────────────────── -->
      <section v-else-if="tab === 'logistics'" class="pd-panel">
        <!-- ── Logistics summary: the whole posture at a glance ───────── -->
        <div v-if="logisticsSummary" class="pd-lsum">
          <div class="pd-lsum-card">
            <div class="pd-lsum-h">Prerequisites</div>
            <div class="pd-lsum-big">{{ logisticsSummary.prerequisites.cleared_pct ?? 0 }}%<span class="pd-lsum-unit"> cleared</span></div>
            <div class="pd-lsum-bar"><span class="pd-lsum-fill" :style="{ width: (logisticsSummary.prerequisites.cleared_pct ?? 0) + '%' }"></span></div>
            <div class="pd-lsum-sub">{{ logisticsSummary.prerequisites.done }}/{{ logisticsSummary.prerequisites.total }} done<span v-if="logisticsSummary.prerequisites.blocked"> · {{ logisticsSummary.prerequisites.blocked }} blocked</span></div>
          </div>
          <div class="pd-lsum-card">
            <div class="pd-lsum-h">Travel</div>
            <div class="pd-lsum-big">{{ logisticsSummary.travel.cycles }}<span class="pd-lsum-unit"> cycle{{ logisticsSummary.travel.cycles === 1 ? '' : 's' }}</span></div>
            <div class="pd-lsum-sub">{{ logisticsSummary.travel.sites }} site{{ logisticsSummary.travel.sites === 1 ? '' : 's' }} · {{ logisticsSummary.travel.travel_days }} travel days · {{ logisticsSummary.travel.people }} people</div>
            <div v-if="logisticsSummary.travel.requests_pending" class="pd-lsum-flag">{{ logisticsSummary.travel.requests_pending }} request{{ logisticsSummary.travel.requests_pending === 1 ? '' : 's' }} pending</div>
          </div>
          <div class="pd-lsum-card">
            <div class="pd-lsum-h">Procurement</div>
            <div class="pd-lsum-big">{{ logisticsSummary.procurement.items }}<span class="pd-lsum-unit"> item{{ logisticsSummary.procurement.items === 1 ? '' : 's' }}</span></div>
            <div class="pd-lsum-sub">{{ fmtMoney(logisticsSummary.procurement.estimated_total, "KES") }} est.</div>
            <div v-if="logisticsSummary.procurement.long_lead || logisticsSummary.procurement.pending_approval" class="pd-lsum-flag">
              <template v-if="logisticsSummary.procurement.long_lead">{{ logisticsSummary.procurement.long_lead }} long-lead</template>
              <template v-if="logisticsSummary.procurement.long_lead && logisticsSummary.procurement.pending_approval"> · </template>
              <template v-if="logisticsSummary.procurement.pending_approval">{{ logisticsSummary.procurement.pending_approval }} to approve</template>
            </div>
          </div>
          <div class="pd-lsum-card">
            <div class="pd-lsum-h">Resourcing</div>
            <div class="pd-lsum-big">{{ logisticsSummary.resourcing.effort_days }}<span class="pd-lsum-unit"> effort days</span></div>
            <div class="pd-lsum-sub">{{ logisticsSummary.resourcing.on_site }} on-site · {{ logisticsSummary.resourcing.remote }} remote</div>
            <div class="pd-lsum-sub">{{ logisticsSummary.requirements.tasks_with_needs }} of {{ logisticsSummary.resourcing.tasks }} tasks have logistics needs</div>
          </div>
        </div>

        <!-- ── Controls & alerts: conditions worth acting on ──────────── -->
        <div v-if="alerts && alerts.length" class="pd-alerts">
          <div class="pd-alerts-head">
            <h3 class="pd-h3">Controls &amp; alerts</h3>
            <span class="pd-alerts-counts">
              <span v-if="alertSummary && alertSummary.critical" class="pd-alert-chip pd-alert-critical">{{ alertSummary.critical }} critical</span>
              <span v-if="alertSummary && alertSummary.warning" class="pd-alert-chip pd-alert-warning">{{ alertSummary.warning }} warning</span>
              <span v-if="alertSummary && alertSummary.info" class="pd-alert-chip pd-alert-info">{{ alertSummary.info }} info</span>
            </span>
          </div>
          <ul class="pd-alert-list">
            <li v-for="(a, i) in alerts" :key="i" :class="['pd-alert', 'pd-alert-' + a.severity]">
              <span class="pd-alert-dot"></span>
              <div class="pd-alert-body">
                <div class="pd-alert-title">{{ a.title }}<span v-if="a.count > 1" class="pd-alert-n">{{ a.count }}</span></div>
                <div class="pd-alert-detail">{{ a.detail }}</div>
              </div>
              <span class="pd-alert-cat">{{ a.category }}</span>
            </li>
          </ul>
        </div>

        <!-- ── Approval tracker: one queue over travel + procurement ──── -->
        <div class="pd-panel-actions">
          <h3 class="pd-h3">Approvals</h3>
          <span class="pd-hint">Everything on this project waiting on a sign-off — travel and procurement — in one queue.</span>
        </div>
        <div v-if="approvalSummary" class="pd-appr-kpis">
          <div class="pd-appr-kpi pd-appr-pending"><span class="pd-appr-n">{{ approvalSummary.pending }}</span><span class="pd-appr-l">Pending</span></div>
          <div class="pd-appr-kpi"><span class="pd-appr-n">{{ approvalSummary.approved }}</span><span class="pd-appr-l">Approved</span></div>
          <div class="pd-appr-kpi"><span class="pd-appr-n">{{ approvalSummary.rejected }}</span><span class="pd-appr-l">Rejected</span></div>
          <div class="pd-appr-kpi"><span class="pd-appr-n">{{ fmtMoney(approvalSummary.pending_value, "KES") }}</span><span class="pd-appr-l">Pending value</span></div>
          <div v-if="approvalSummary.awaiting_costing" class="pd-appr-kpi pd-appr-warn"><span class="pd-appr-n">{{ approvalSummary.awaiting_costing }}</span><span class="pd-appr-l">Not yet costed</span></div>
        </div>
        <p v-if="approvals && !approvals.length" class="pd-empty-inline">Nothing to approve yet. Travel requests and procurement items appear here.</p>
        <div v-else-if="approvals" class="pd-card pd-table-card">
          <table class="pd-table pd-appr-table">
            <thead><tr><th>Item</th><th>Type</th><th>Requested by</th><th>Needed by</th><th class="pd-num">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="a in approvals" :key="a.kind + a.id" :class="{ 'pd-appr-row-pending': a.status === 'pending' }">
                <td>
                  <div class="pd-appr-title">{{ a.title }}</div>
                  <div class="pd-appr-detail">
                    <span v-if="a.reference" class="pd-appr-ref">{{ a.reference }}</span>{{ a.detail }}
                    <span v-for="f in a.flags" :key="f" class="pd-appr-flag">{{ f.replace('-', ' ') }}</span>
                  </div>
                </td>
                <td><span :class="['pd-kind-chip', 'pd-kind-' + a.kind]">{{ a.kind === 'travel' ? '✈ Travel' : '⛬ Procurement' }}</span></td>
                <td>{{ a.requested_by || "—" }}<span v-if="a.requested_on" class="pd-appr-sub">{{ a.requested_on }}</span></td>
                <td class="pd-nowrap">{{ a.needed_by || "—" }}</td>
                <td class="pd-num pd-nowrap">{{ a.amount != null ? fmtMoney(a.amount, a.currency) : "—" }}</td>
                <td>
                  <span :class="['pill', a.status === 'approved' ? 'pill-ok' : a.status === 'rejected' ? 'pill-danger' : 'pill-warn']">{{ titleCase(a.status) }}</span>
                  <div v-if="a.decided_by" class="pd-appr-sub">{{ a.decided_by }}<template v-if="a.decided_at"> · {{ a.decided_at.slice(0, 10) }}</template></div>
                </td>
                <td class="pd-nowrap">
                  <template v-if="a.status === 'pending'">
                    <button class="pd-appr-btn pd-mini-good" @click="decideApproval(a, 'approved')">Approve</button>
                    <button class="pd-appr-btn pd-mini-bad" @click="decideApproval(a, 'rejected')">Reject</button>
                  </template>
                  <button v-else class="pd-appr-btn" @click="decideApproval(a, 'pending')">Reopen</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="pd-panel-actions" style="margin-top:28px">
          <h3 class="pd-h3">Travel schedule</h3>
          <span class="pd-hint">Task travel consolidated into cost-effective cycles — same site, overlapping dates, shared team.</span>
        </div>
        <p v-if="!travelSchedule" class="pd-empty">Loading…</p>
        <p v-else-if="!travelSchedule.length" class="pd-empty">No task travel flagged yet. Tick “Travel” on a task's requirements and give it a site.</p>
        <div v-else class="pd-card pd-table-card">
          <table class="pd-table">
            <thead><tr><th>Cycle</th><th>Site / location</th><th>Tasks</th><th>Team</th><th>Dates</th><th>Duration</th><th>Travel days</th><th>Mode</th><th>Accommodation</th></tr></thead>
            <tbody>
              <tr v-for="c in travelSchedule" :key="c.number">
                <td>#{{ c.number }}</td>
                <td>{{ c.location }}</td>
                <td>
                  <span v-for="t in c.tasks" :key="t.id" class="pd-logi-badge">{{ t.title }}</span>
                </td>
                <td>{{ c.team.length ? c.team.join(", ") : "—" }}</td>
                <td class="pd-team-dates">{{ c.start ? date(c.start) : "—" }}<template v-if="c.end"> → {{ date(c.end) }}</template></td>
                <td>{{ c.duration_days != null ? c.duration_days + "d" : "—" }}</td>
                <td>{{ c.travel_days != null ? c.travel_days + "d" : "—" }}</td>
                <td>{{ c.modes.length ? c.modes.map(m => titleCase(m)).join(", ") : "—" }}</td>
                <td><span :class="['pill', c.accommodation ? 'pill-warn' : 'pill-neutral']">{{ c.accommodation ? "Required" : "No" }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Travel cycle optimisation tips -->
        <div v-if="travelTips && travelTips.length" class="pd-tips">
          <div class="pd-tips-head">
            <span class="pd-tips-title">💡 Optimisation tips</span>
            <span v-if="travelTipSummary && travelTipSummary.potential_saving_days" class="pd-tips-save">~{{ travelTipSummary.potential_saving_days }} travel day{{ travelTipSummary.potential_saving_days === 1 ? '' : 's' }} saveable</span>
          </div>
          <div v-for="(t, i) in travelTips" :key="i" class="pd-tip">
            <span :class="['pd-tip-tag', 'pd-tip-' + t.type]">{{ t.type }}</span>
            <div class="pd-tip-body">
              <div class="pd-tip-title">{{ t.title }}<span v-if="t.saving_days" class="pd-tip-save">saves ~{{ t.saving_days }}d</span></div>
              <div class="pd-tip-detail">{{ t.detail }}</div>
              <div v-if="t.cycles.length" class="pd-tip-cycles">Cycles: {{ t.cycles.map(n => '#' + n).join(', ') }}</div>
            </div>
          </div>
        </div>

        <!-- Travel request register -->
        <div class="pd-panel-actions" style="margin-top:28px">
          <h3 class="pd-h3">Travel requests</h3>
          <span v-if="travelReqSummary" class="pd-hint">
            {{ travelReqSummary.count }} request{{ travelReqSummary.count === 1 ? "" : "s" }} ·
            {{ travelReqSummary.pending_approval }} pending · est. {{ fmtMoney(travelReqSummary.estimated_total, "KES") }}
          </span>
          <button class="btn-secondary btn-sm" @click="generateTravelRequests">Generate from schedule</button>
          <button class="btn-secondary btn-sm" title="Price un-costed requests from the allowance rate card" @click="applyTravelEstimate">⚡ Estimate costs</button>
        </div>
        <p v-if="travelEstimateRates" class="pd-hint pd-est-basis">
          Rate basis — per diem {{ fmtMoney(travelEstimateRates.per_diem.amount, travelEstimateRates.currency) }}/day
          <span class="pd-est-src">({{ travelEstimateRates.per_diem.source === 'rate_card' ? 'rate card' : 'default' }})</span> ·
          accommodation {{ fmtMoney(travelEstimateRates.accommodation.amount, travelEstimateRates.currency) }}/night ·
          incidentals {{ fmtMoney(travelEstimateRates.incidentals.amount, travelEstimateRates.currency) }}/trip ·
          transport by mode (air {{ fmtMoney(travelEstimateRates.transport.air, travelEstimateRates.currency) }})
        </p>
        <div class="pd-card pd-table-card">
          <table class="pd-table">
            <thead><tr><th>Ref</th><th>Purpose</th><th>Location</th><th>Dates</th><th>Personnel</th><th>Accom.</th><th>Est. cost</th><th>Approval</th><th>Travel module</th><th></th></tr></thead>
            <tbody>
              <tr v-for="r in (travelReqs ?? [])" :key="r.id">
                <td class="pd-team-dates">{{ r.reference }}</td>
                <td>{{ r.purpose }}</td>
                <td>{{ r.location ?? "—" }}</td>
                <td class="pd-team-dates">{{ r.start_date ? date(r.start_date) : "—" }}<template v-if="r.end_date"> → {{ date(r.end_date) }}</template></td>
                <td>{{ r.personnel.length ? r.personnel.join(", ") : "—" }}</td>
                <td><span :class="['pill', r.accommodation ? 'pill-warn' : 'pill-neutral']">{{ r.accommodation ? "Yes" : "No" }}</span></td>
                <td><input :value="r.estimated_cost ?? ''" class="control pd-proc-approve" type="number" min="0" placeholder="—" @change="setTravelReqCost(r, ($event.target as HTMLInputElement).value)" style="width:8rem" /></td>
                <td>
                  <select :value="r.approval_status" class="control pd-proc-approve" @change="decideTravelReq(r, ($event.target as HTMLSelectElement).value)">
                    <option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option>
                  </select>
                </td>
                <td class="pd-nowrap">
                  <span v-if="r.module_requests" class="pd-link-chip" title="Raised in the Travel module">✈ In module ×{{ r.module_requests }}</span>
                  <button v-else class="pd-appr-btn pd-mini-good" title="Raise per-traveller requests in the Travel module" @click="raiseTravelInModule(r)">Raise in module</button>
                </td>
                <td><button class="link-btn link-danger" @click="removeTravelReq(r)">✕</button></td>
              </tr>
              <tr v-if="travelReqs && !travelReqs.length"><td colspan="10" class="pd-muted">No travel requests yet. Generate them from the schedule above.</td></tr>
            </tbody>
          </table>
        </div>

        <!-- Procurement register -->
        <div class="pd-panel-actions" style="margin-top:28px">
          <h3 class="pd-h3">Procurement register</h3>
          <span v-if="procSummary" class="pd-hint">
            {{ procSummary.count }} item{{ procSummary.count === 1 ? "" : "s" }} ·
            {{ procSummary.pending_approval }} pending approval ·
            {{ procSummary.long_lead }} long-lead ·
            est. {{ fmtMoney(procSummary.estimated_total, "KES") }}
          </span>
        </div>
        <div class="pd-card pd-table-card">
          <table class="pd-table">
            <thead><tr><th>Ref</th><th>Item</th><th>Cat.</th><th>Qty</th><th>Vendor</th><th>Required by</th><th>Lead</th><th>Est. cost</th><th>Approval</th><th></th></tr></thead>
            <tbody>
              <tr v-for="p in (procurement ?? [])" :key="p.id">
                <td class="pd-team-dates">{{ p.reference }}</td>
                <td>{{ p.item }}</td>
                <td>{{ p.category ? titleCase(p.category) : "—" }}</td>
                <td>{{ p.quantity }}</td>
                <td>{{ p.vendor ?? "—" }}</td>
                <td class="pd-team-dates">{{ p.required_by ? date(p.required_by) : "—" }}</td>
                <td><span v-if="p.is_long_lead" class="pd-logi-badge warn">{{ p.lead_time_days }}d long-lead</span><span v-else>{{ p.lead_time_days != null ? p.lead_time_days + "d" : "—" }}</span></td>
                <td>{{ p.estimated_cost != null ? fmtMoney(Number(p.estimated_cost), p.currency) : "—" }}</td>
                <td>
                  <select :value="p.approval_status" class="control pd-proc-approve" @change="decideProc(p, ($event.target as HTMLSelectElement).value)">
                    <option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option>
                  </select>
                </td>
                <td><button class="link-btn link-danger" @click="removeProc(p)">✕</button></td>
              </tr>
              <tr v-if="procurement && !procurement.length"><td colspan="10" class="pd-muted">Nothing to procure yet.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="pd-cust-add-grid" style="margin-top:10px">
          <input v-model="newProc.item" class="control" placeholder="Item…" />
          <select v-model="newProc.category" class="control"><option value="">Category…</option><option value="hardware">Hardware</option><option value="software">Software</option><option value="licence">Licence</option><option value="subscription">Subscription</option><option value="service">Service</option><option value="equipment">Equipment</option><option value="other">Other</option></select>
          <input v-model="newProc.quantity" class="control" type="number" min="1" placeholder="Qty" />
          <input v-model="newProc.vendor" class="control" placeholder="Vendor" />
          <input v-model="newProc.required_by" class="control" type="date" title="Required by" />
          <input v-model="newProc.lead_time_days" class="control" type="number" min="0" placeholder="Lead days" />
          <input v-model="newProc.estimated_cost" class="control" type="number" min="0" placeholder="Est. cost" />
          <button class="btn-primary btn-sm" :disabled="!newProc.item.trim()" @click="addProcurement">Add item</button>
        </div>

        <!-- Vendor engagement register -->
        <div class="pd-panel-actions" style="margin-top:28px">
          <h3 class="pd-h3">Vendor engagements</h3>
          <span v-if="vendorEngagements" class="pd-hint">
            {{ vendorEngagements.summary.count }} engagement{{ vendorEngagements.summary.count === 1 ? "" : "s" }} ·
            {{ vendorEngagements.summary.open }} open · {{ vendorEngagements.summary.active }} active
            <template v-if="!vendorEngagements.summary.count"> — raise one from a task's vendor / PS / subcontractor requirement</template>
          </span>
        </div>
        <div v-if="vendorEngagements && vendorEngagements.data.length" class="pd-card pd-table-card">
          <table class="pd-table">
            <thead><tr><th>Ref</th><th>Type</th><th>Vendor</th><th>Scope</th><th>For task</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="e in vendorEngagements.data" :key="e.id">
                <td class="pd-team-dates">{{ e.reference }}</td>
                <td>{{ engagementTypeLabel(e.engagement_type) }}</td>
                <td><input :value="e.vendor_name ?? ''" class="control" placeholder="Name a vendor…" style="min-width:9rem" @change="updateEngagement(e, { vendor_name: ($event.target as HTMLInputElement).value })" /></td>
                <td>{{ e.scope ?? "—" }}</td>
                <td>{{ e.task ?? "—" }}</td>
                <td>
                  <select :value="e.status" class="control pd-proc-approve" @change="updateEngagement(e, { status: ($event.target as HTMLSelectElement).value })">
                    <option v-for="s in vendorEngagements.options.statuses" :key="s" :value="s">{{ titleCase(s) }}</option>
                  </select>
                </td>
                <td><button class="link-btn link-danger" @click="removeEngagement(e)">✕</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else-if="vendorEngagements" class="pd-empty-inline">No vendor engagements yet. Tick “External vendor”, “Professional services” or “Subcontractors” on a task and raise the engagement from its requirement.</p>

        <!-- Resource planning register -->
        <div class="pd-panel-actions" style="margin-top:28px">
          <h3 class="pd-h3">Resource plan</h3>
          <span v-if="resourcePlan" class="pd-hint">
            {{ resourcePlan.summary.total_effort_days }} effort-days ·
            {{ resourcePlan.summary.people_count }} people ·
            {{ resourcePlan.summary.tasks_on_site }} on-site ·
            {{ resourcePlan.summary.tasks_with_travel }} need travel<template v-if="resourcePlan.summary.over_allocated"> · <span class="pd-logi-badge warn">{{ resourcePlan.summary.over_allocated }} over-allocated</span></template>
          </span>
        </div>
        <div v-if="resourcePlan" class="pd-card pd-table-card">
          <table class="pd-table">
            <thead><tr><th>Task</th><th>Unit</th><th>People</th><th>Effort</th><th>Site</th><th>Travel</th></tr></thead>
            <tbody>
              <tr v-for="r in resourcePlan.data" :key="r.task_id">
                <td>{{ r.title }}</td>
                <td>{{ r.unit ?? "—" }}</td>
                <td>{{ r.people.length ? r.people.join(", ") : "—" }}</td>
                <td>{{ r.effort_days != null ? r.effort_days + "d" : "—" }}</td>
                <td>{{ r.site }}<template v-if="r.location"> · {{ r.location }}</template></td>
                <td><span :class="['pill', r.travel ? 'pill-warn' : 'pill-neutral']">{{ r.travel ? "Yes" : "No" }}</span></td>
              </tr>
              <tr v-if="!resourcePlan.data.length"><td colspan="6" class="pd-muted">No tasks yet.</td></tr>
            </tbody>
          </table>
        </div>

        <!-- Utilization / staffing conflicts -->
        <template v-if="resourcePlan && resourcePlan.utilization.length">
          <h4 class="pd-h4" style="margin-top:16px">Team utilization</h4>
          <div class="pd-card pd-table-card">
            <table class="pd-table">
              <thead><tr><th>Person</th><th>Teams</th><th>Total capacity</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="(u, i) in resourcePlan.utilization" :key="i">
                  <td>{{ u.name }}</td><td>{{ u.teams }}</td><td>{{ u.total_capacity }}%</td>
                  <td><span :class="['pill', u.over_allocated ? 'pill-bad' : 'pill-ok']">{{ u.over_allocated ? "Over-allocated" : "OK" }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </section>
    </template>

    <!-- ── Task drawer ───────────────────────────────────────────────── -->
    <div v-if="openTask" class="pd-sheet-backdrop" @click.self="openTask = null">
      <aside class="pd-sheet pd-sheet-wide" role="dialog" aria-label="Task">
        <div class="pd-sheet-head">
          <h3 class="pd-sheet-title">{{ openTask.title }}</h3>
          <button class="pd-close" aria-label="Close" @click="openTask = null">×</button>
        </div>

        <dl class="pd-mini">
          <div><dt>Status</dt><dd><span :class="['pill', `pill-${statusTone(openTask.status)}`]">{{ titleCase(openTask.status) }}</span></dd></div>
          <div><dt>Deadline</dt><dd>{{ date(openTask.deadline) }}</dd></div>
          <div><dt>Priority</dt><dd>{{ titleCase(openTask.priority) }}</dd></div>
          <div v-if="openTask.parent_task_id"><dt>Parent</dt><dd>Sub-task</dd></div>
        </dl>

        <div v-if="hasText(openTask.description)" class="pd-prose rich"
             v-html="safeHtml(openTask.description)"></div>

        <!-- Sub-tasks -->
        <div class="pd-block">
          <div class="pd-block-head">
            <h4 class="pd-h4">Sub-tasks</h4>
            <button class="link-btn" @click="newTask(openTask!.id)">+ Add</button>
          </div>
          <p v-if="!openTask.subtasks.length" class="pd-muted">None.</p>
          <ul v-else class="pd-list">
            <li v-for="s in openTask.subtasks" :key="s.id">
              <input type="checkbox" :checked="s.is_done" @change="toggleDone(s, $event)" />
              <span :class="{ struck: s.is_done }">{{ s.title }}</span>
            </li>
          </ul>
        </div>

        <!-- Dependencies -->
        <div class="pd-block">
          <h4 class="pd-h4">Dependencies</h4>
          <p class="pd-hint">
            What has to finish first. Shown rather than enforced — a blocked task you
            cannot start just gets its dependency deleted.
          </p>
          <ul v-if="openTask.dependencies.length" class="pd-list">
            <li v-for="d in openTask.dependencies" :key="d.id">
              <span :class="d.status === 'completed' ? 'pd-done-dep' : ''">{{ d.title }}</span>
              <button class="link-btn link-danger" @click="removeDependency(d.id)">×</button>
            </li>
          </ul>
          <div class="pd-add-row">
            <select v-model.number="newDependency" class="control">
              <option :value="null">Waits on…</option>
              <option v-for="o in dependencyOptions" :key="o.id" :value="o.id">{{ o.title }}</option>
            </select>
            <button class="btn-ghost" :disabled="saving || !newDependency" @click="addDependency">Add</button>
          </div>
        </div>

        <!-- Requirements & Prerequisites -->
        <div v-if="openTask.prerequisites?.length" class="pd-block">
          <h4 class="pd-h4">Requirements &amp; prerequisites</h4>
          <p v-if="openTask.logistics?.requires_approval && openTask.status !== 'completed'" class="pd-prereq-note">
            ⏳ Awaiting approval — sign-off is needed before the flagged prerequisites are actioned.
          </p>
          <ul class="pd-prereq-list">
            <li v-for="p in openTask.prerequisites" :key="p.id" :class="['pd-prereq', `st-${p.status}`]">
              <div class="pd-prereq-main">
                <span class="pd-prereq-label">{{ p.label }}</span>
                <span v-if="p.procurement_needed && !p.linked_kind" class="pd-prereq-tag warn">Procurement needed</span>
                <span v-if="p.due_on" class="pd-prereq-tag">Due {{ date(p.due_on) }}</span>
                <span v-if="p.linked_kind === 'procurement'" class="pd-prereq-tag linked" title="A procurement request was raised">⛬ {{ p.linked_reference || 'Procurement raised' }}</span>
                <span v-else-if="p.linked_kind === 'travel'" class="pd-prereq-tag linked" title="Covered by a travel request">✈ {{ p.linked_reference || 'Travel request' }}</span>
                <span v-else-if="p.linked_kind === 'vendor'" class="pd-prereq-tag linked" title="A vendor engagement was raised">🤝 {{ p.linked_reference || 'Engagement' }}</span>
                <p v-if="p.detail" class="pd-prereq-detail">{{ p.detail }}</p>
              </div>
              <div class="pd-prereq-actions">
                <button v-if="p.can_raise" class="pd-appr-btn pd-mini-good" :title="p.raise_label ?? 'Raise the linked record'" @click="raisePrerequisite(p)">{{ p.raise_label ?? 'Raise' }}</button>
                <select :value="p.status" class="control pd-prereq-status" @change="updatePrerequisite(p, ($event.target as HTMLSelectElement).value)">
                  <option v-for="s in PREREQ_STATUSES" :key="s.key" :value="s.key">{{ s.label }}</option>
                </select>
              </div>
            </li>
          </ul>
        </div>

        <!-- People -->
        <div class="pd-block">
          <h4 class="pd-h4">Assignees and assisting</h4>
          <div class="pd-people">
            <span v-for="a in openTask.assignees" :key="a.id" class="pd-person-chip">
              {{ a.employee.name }}<button class="link-btn link-danger" @click="removeMember(a)">×</button>
            </span>
            <span v-for="a in openTask.assisting" :key="a.id" class="pd-person-chip assisting">
              {{ a.employee.name }} · assisting<button class="link-btn link-danger" @click="removeMember(a)">×</button>
            </span>
          </div>
          <div class="pd-add-row">
            <div class="pd-add-picker">
              <EmployeePicker v-model="newMember.employee_id" placeholder="Add somebody…" />
            </div>
            <select v-model="newMember.capacity" class="control pd-capacity">
              <option value="assignee">Assignee</option>
              <option value="assisting">Assisting</option>
            </select>
            <button class="btn-ghost" :disabled="saving || !newMember.employee_id" @click="addMember">Add</button>
          </div>
        </div>

        <!-- Resources -->
        <div class="pd-block">
          <div class="pd-block-head">
            <h4 class="pd-h4">Resources</h4>
            <label class="link-btn pd-upload">
              {{ uploading ? "Uploading…" : "+ Add" }}
              <input type="file" hidden :disabled="uploading" @change="upload" />
            </label>
          </div>
          <p v-if="!openTask.attachments.length" class="pd-muted">Nothing attached.</p>
          <ul v-else class="pd-list">
            <li v-for="d in openTask.attachments" :key="d.id">
              <a :href="downloadUrl(d)" class="link-btn">{{ d.label }}</a>
              <span class="pd-muted">{{ kb(d.size_bytes) }}</span>
            </li>
          </ul>
        </div>

        <div class="pd-sheet-actions">
          <button class="btn-ghost" @click="editTask(openTask!)">Edit</button>
          <button class="btn-primary" @click="openTask = null">Close</button>
        </div>
      </aside>
    </div>

    <!-- Task form -->
    <!-- Governance role add/edit modal -->
    <!-- Closing a task off. The same summary the Tasks page asks for, so a task
         finished from here carries the same record as one finished there. -->
    <div v-if="closingTask" class="pd-sheet-backdrop" @click.self="closingTask = null">
      <aside class="pd-sheet" role="dialog" aria-label="Close this task off">
        <div class="pd-sheet-head">
          <h3 class="pd-sheet-title">Close off — {{ closingTask.title }}</h3>
          <button class="pd-close" aria-label="Close" @click="closingTask = null">×</button>
        </div>

        <p class="pd-muted">
          A completion date on its own tells a review nothing. This is what it reads.
        </p>

        <label v-for="f in CLOSURE_FIELDS" :key="f.key" class="pd-field">
          <span>{{ f.label }}<em v-if="!f.required" class="pd-optional"> · optional</em></span>
          <textarea v-model="closureForm[f.key as keyof typeof closureForm]" class="control" rows="2"
                    :placeholder="f.hint"></textarea>
        </label>

        <p v-if="closureError" class="pd-error">{{ closureError }}</p>

        <div class="pd-sheet-actions">
          <button class="btn-ghost" @click="closingTask = null">Cancel</button>
          <button class="btn-primary" :disabled="!closureReady || saving" @click="submitClosure">
            Close off task
          </button>
        </div>
      </aside>
    </div>

    <div v-if="govModalOpen" class="pd-sheet-backdrop" @click.self="govModalOpen = false">
      <aside class="pd-sheet" role="dialog" aria-label="Governance role">
        <div class="pd-sheet-head">
          <h3 class="pd-sheet-title">{{ govForm.editing ? "Edit " + govForm.label : "Add governance role" }}</h3>
          <button class="pd-close" aria-label="Close" @click="govModalOpen = false">×</button>
        </div>

        <label v-if="!govForm.editing" class="pd-field"><span>Role</span>
          <select v-model="govForm.role_key" class="control">
            <option value="">— Select a role —</option>
            <option v-for="r in governanceAvailable" :key="r.role_key" :value="r.role_key">{{ r.label }}{{ r.is_mandatory ? " (mandatory)" : "" }}</option>
          </select></label>

        <div class="pd-grid2">
          <div class="pd-field"><span>Assigned person</span>
            <EmployeePicker v-model="govForm.assignee_id" placeholder="Assign…" /></div>
          <div class="pd-field"><span>Backup / delegate</span>
            <EmployeePicker v-model="govForm.backup_id" placeholder="Backup…" /></div>
        </div>
        <label class="pd-field"><span>Department / organisation</span>
          <input v-model="govForm.department" class="control" placeholder="e.g. PMO, KNS" /></label>
        <label class="pd-field"><span>Role description</span>
          <textarea v-model="govForm.responsibility" class="control" rows="2" placeholder="What this role is accountable for"></textarea></label>
        <div class="pd-grid2">
          <label class="pd-field"><span>Escalation authority</span>
            <input v-model="govForm.escalation_authority" class="control" placeholder="Escalates to…" /></label>
          <label class="pd-field"><span>Approval authority</span>
            <input v-model="govForm.approval_authority" class="control" placeholder="Can approve…" /></label>
        </div>
        <div class="pd-grid2">
          <label class="pd-field"><span>Start date</span>
            <input v-model="govForm.start_date" class="control" type="date" /></label>
          <label class="pd-field"><span>End date</span>
            <input v-model="govForm.end_date" class="control" type="date" /></label>
        </div>
        <label class="pd-field"><span>Contact information</span>
          <input v-model="govForm.contact_info" class="control" placeholder="Email / phone" /></label>

        <div class="pd-sheet-actions">
          <button v-if="govForm.editing" class="btn-ghost btn-danger-text" @click="removeGovRole(governanceAssigned.find(r => r.role_key === govForm.role_key)!)">Remove</button>
          <button class="btn-primary btn-block" :disabled="!govForm.role_key" @click="saveGovForm">{{ govForm.editing ? "Save" : "Add role" }}</button>
        </div>
      </aside>
    </div>

    <!-- Customer role modal -->
    <div v-if="custModalOpen && customerTeam" class="pd-sheet-backdrop" @click.self="custModalOpen = false">
      <aside class="pd-sheet" role="dialog" aria-label="Customer role">
        <div class="pd-sheet-head">
          <h3 class="pd-sheet-title">{{ custForm.editing ? "Edit " + custForm.label : "Add customer role" }}</h3>
          <button class="pd-close" aria-label="Close" @click="custModalOpen = false">×</button>
        </div>
        <label v-if="!custForm.editing" class="pd-field"><span>Role</span>
          <select v-model="custForm.role_key" class="control">
            <option value="">— Select a role —</option>
            <option v-for="r in customerAvailable" :key="r.role_key" :value="r.role_key">{{ r.label }}</option>
          </select></label>
        <label class="pd-field"><span>Contact</span>
          <select v-model.number="custForm.account_contact_id" class="control">
            <option :value="null">— Not set —</option>
            <option v-for="c in customerTeam.contacts" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select></label>
        <label class="pd-field"><span>Engagement responsibility</span>
          <textarea v-model="custForm.responsibility" class="control" rows="2" placeholder="What they own on the client side"></textarea></label>
        <div class="pd-sheet-actions">
          <button v-if="custForm.editing" class="btn-ghost btn-danger-text" @click="removeCustRole">Remove</button>
          <button class="btn-primary btn-block" :disabled="!custForm.role_key" @click="saveCustForm">{{ custForm.editing ? "Save" : "Add role" }}</button>
        </div>
      </aside>
    </div>

    <!-- Professional-services modal -->
    <div v-if="psModalOpen && psData" class="pd-sheet-backdrop" @click.self="psModalOpen = false">
      <aside class="pd-sheet" role="dialog" aria-label="Professional services resource">
        <div class="pd-sheet-head">
          <h3 class="pd-sheet-title">{{ psForm.editing ? "Edit resource" : "Add professional-services resource" }}</h3>
          <button class="pd-close" aria-label="Close" @click="psModalOpen = false">×</button>
        </div>
        <div class="pd-grid2">
          <label class="pd-field"><span>Role</span>
            <select v-model="psForm.role" class="control">
              <option value="">— Select —</option>
              <option v-for="c in psData.catalog" :key="c.key" :value="c.key">{{ c.label }}</option>
            </select></label>
          <label class="pd-field"><span>Name</span>
            <input v-model="psForm.name" class="control" placeholder="Person" /></label>
        </div>
        <label class="pd-field"><span>Provider / organisation</span>
          <input v-model="psForm.organization" class="control" placeholder="Firm or internal" /></label>
        <label class="pd-field"><span>Description</span>
          <textarea v-model="psForm.description" class="control" rows="2" placeholder="What they advise on"></textarea></label>
        <label class="pd-field"><span>Contact information</span>
          <input v-model="psForm.contact_info" class="control" placeholder="Email / phone" /></label>
        <div class="pd-grid2">
          <label class="pd-field"><span>Start date</span><input v-model="psForm.start_date" class="control" type="date" /></label>
          <label class="pd-field"><span>End date</span><input v-model="psForm.end_date" class="control" type="date" /></label>
        </div>
        <div class="pd-sheet-actions">
          <button v-if="psForm.editing" class="btn-ghost btn-danger-text" @click="removePs">Remove</button>
          <button class="btn-primary btn-block" :disabled="!psForm.role || !psForm.name.trim()" @click="savePs">{{ psForm.editing ? "Save" : "Add resource" }}</button>
        </div>
      </aside>
    </div>

    <!-- Subcontractor modal -->
    <div v-if="subModalOpen && subData" class="pd-sheet-backdrop" @click.self="subModalOpen = false">
      <aside class="pd-sheet" role="dialog" aria-label="Subcontractor">
        <div class="pd-sheet-head">
          <h3 class="pd-sheet-title">{{ subForm.editing ? "Edit subcontractor" : "Add subcontractor" }}</h3>
          <button class="pd-close" aria-label="Close" @click="subModalOpen = false">×</button>
        </div>
        <div class="pd-grid2">
          <label class="pd-field"><span>Type</span>
            <select v-model="subForm.role" class="control">
              <option value="">— Select —</option>
              <option v-for="c in subData.catalog" :key="c.key" :value="c.key">{{ c.label }}</option>
            </select></label>
          <label class="pd-field"><span>Company</span>
            <input v-model="subForm.company" class="control" placeholder="Contractor firm" /></label>
        </div>
        <div class="pd-grid2">
          <label class="pd-field"><span>Contact person</span><input v-model="subForm.contact_person" class="control" /></label>
          <label class="pd-field"><span>Contact information</span><input v-model="subForm.contact_info" class="control" placeholder="Email / phone" /></label>
        </div>
        <label class="pd-field"><span>Scope of work</span>
          <textarea v-model="subForm.scope_of_work" class="control" rows="2"></textarea></label>
        <label class="pd-field"><span>Contract reference</span><input v-model="subForm.contract_reference" class="control" /></label>
        <div class="pd-grid2">
          <label class="pd-field"><span>Approval status</span>
            <select v-model="subForm.approval_status" class="control"><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select></label>
          <label class="pd-field"><span>Onboarding status</span>
            <select v-model="subForm.onboarding_status" class="control"><option value="not_started">Not started</option><option value="in_progress">In progress</option><option value="complete">Complete</option></select></label>
        </div>
        <div class="pd-grid2">
          <label class="pd-field"><span>Start date</span><input v-model="subForm.start_date" class="control" type="date" /></label>
          <label class="pd-field"><span>End date</span><input v-model="subForm.end_date" class="control" type="date" /></label>
        </div>
        <div class="pd-sheet-actions">
          <button v-if="subForm.editing" class="btn-ghost btn-danger-text" @click="removeSub">Remove</button>
          <button class="btn-primary btn-block" :disabled="!subForm.role || !subForm.company.trim()" @click="saveSub">{{ subForm.editing ? "Save" : "Add subcontractor" }}</button>
        </div>
      </aside>
    </div>

    <div v-if="showTask" class="pd-sheet-backdrop" @click.self="showTask = false">
      <aside class="pd-sheet" role="dialog" aria-label="Task form">
        <div class="pd-sheet-head">
          <h3 class="pd-sheet-title">{{ taskForm.id ? "Edit task" : "Add new task" }}</h3>
          <button class="pd-close" aria-label="Close" @click="showTask = false">×</button>
        </div>

        <p class="pd-task-kind">Project task on <strong>{{ project?.name }}</strong></p>

        <label class="pd-field"><span>Task Title</span>
          <input v-model="taskForm.title" class="control" placeholder="Prototype the screens" /></label>

        <!-- Phase & sub-phase -->
        <div class="pd-grid2">
          <label class="pd-field"><span>Phase</span>
            <select v-model.number="taskForm.phase_id" class="control" @change="taskForm.subphase_id = null">
              <option :value="null">— None —</option>
              <option v-for="ph in phases" :key="ph.id" :value="ph.id">{{ ph.name }}</option>
            </select></label>
          <label class="pd-field"><span>Sub-phase</span>
            <select v-model.number="taskForm.subphase_id" class="control" :disabled="!subphaseOptions.length">
              <option :value="null">{{ subphaseOptions.length ? "— None —" : "No sub-phases" }}</option>
              <option v-for="sp in subphaseOptions" :key="sp.id" :value="sp.id">{{ sp.name }}</option>
            </select></label>
        </div>

        <!-- Start & expected closure -->
        <div class="pd-grid2">
          <label class="pd-field"><span>Start date</span>
            <input v-model="taskForm.started_on" class="control" type="date" /></label>
          <label class="pd-field"><span>Expected closure</span>
            <input v-model="taskForm.deadline" class="control" type="date" /></label>
        </div>

        <div class="pd-grid2">
          <label class="pd-field"><span>Status</span>
            <select v-model="taskForm.status" class="control">
              <option value="todo">To Do</option>
              <option value="in_progress">In Progress</option>
              <option value="awaiting_approval">Awaiting Approval</option>
              <option value="blocked">Blocked</option>
              <option value="completed">Completed</option>
            </select></label>
          <label class="pd-field"><span>Priority</span>
            <select v-model="taskForm.priority" class="control">
              <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
            </select></label>
          <label class="pd-field"><span>Effort (days)</span>
            <input v-model="taskForm.effort_estimate_days" class="control" type="number" min="0" step="0.5" placeholder="e.g. 4" /></label>
        </div>

        <!-- Assign by role → engineers, with primary/secondary responsibility -->
        <template v-if="!taskForm.id">
          <div class="pd-field"><span>Assign to — role</span>
            <select v-model.number="taskForm.role_id" class="control">
              <option :value="null">— Pick a role —</option>
              <option v-for="r in team?.roles ?? []" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
            <span class="pd-hint">Wires the task to the engineers configured on that role in Team &amp; Roles.</span>
          </div>

          <div v-if="taskForm.role_id" class="pd-assign-pick">
            <p v-if="!roleEngineers.length" class="pd-empty-inline">No engineers on this role yet — add them on Team &amp; Roles.</p>
            <label v-for="m in roleEngineers" :key="m.id" class="pd-assign-opt">
              <input type="checkbox" :checked="isAssignee(m.employee_id)" @change="toggleAssignee(m.employee_id, m.name)" />
              <span>{{ m.name }}</span>
            </label>
          </div>

          <div v-if="taskForm.assignees.length" class="pd-resp">
            <div class="pd-resp-title">Responsibility — who gives updates</div>
            <div v-for="a in taskForm.assignees" :key="a.employee_id" class="pd-resp-row">
              <span class="pd-resp-name">{{ a.name }}</span>
              <div class="pd-resp-toggle">
                <button type="button" :class="{ on: a.capacity === 'assignee' }" @click="setResponsibility(a.employee_id, 'assignee')">Primary</button>
                <button type="button" :class="{ on: a.capacity === 'assisting' }" @click="setResponsibility(a.employee_id, 'assisting')">Secondary</button>
              </div>
              <button type="button" class="pd-x" @click="toggleAssignee(a.employee_id, a.name)">✕</button>
            </div>
          </div>

          <!-- Dependencies — other tasks this one waits on -->
          <div class="pd-field"><span>Depends on</span>
            <span class="pd-hint">Other tasks that must be done first.</span>
            <div v-if="dependencyOptions.length" class="pd-assign-pick pd-dep-pick">
              <label v-for="t in dependencyOptions" :key="t.id" class="pd-assign-opt">
                <input type="checkbox" :checked="taskForm.depends_on_task_ids.includes(t.id)" @change="toggleTaskDependency(t.id)" />
                <span>{{ t.title }}</span>
              </label>
            </div>
            <p v-else class="pd-empty-inline">No other tasks on this project yet.</p>
          </div>
        </template>

        <label class="pd-field"><span>Description</span>
          <textarea v-model="taskForm.description" class="control" rows="5"></textarea></label>

        <!-- ── Requirements & Logistics (mandatory) ─────────────────────── -->
        <div class="pd-logi">
          <div class="pd-logi-head">
            <span class="pd-logi-title">Task Requirements &amp; Logistics</span>
            <span class="pd-req">Required</span>
          </div>
          <p class="pd-hint">Prerequisites to arrange before this task can start — travel, access, assets, licenses, procurement, vendors and approvals.</p>

          <div class="pd-assign-pick pd-logi-needs">
            <label v-for="n in NEED_FLAGS" :key="n.key" class="pd-assign-opt">
              <input type="checkbox" v-model="taskForm.logistics[n.key]" />
              <span>{{ n.label }}</span>
            </label>
          </div>

          <!-- Travel detail — the site and mode feed the travel-cycle schedule. -->
          <div v-if="taskForm.logistics.needs_travel" class="pd-grid2">
            <label class="pd-field"><span>Travel to (site)</span>
              <input v-model="taskForm.logistics.travel_location" class="control" placeholder="e.g. Mombasa" /></label>
            <label class="pd-field"><span>Mode of travel</span>
              <select v-model="taskForm.logistics.travel_mode" class="control">
                <option value="">—</option><option value="air">Air</option><option value="inter_city">Inter-city</option>
                <option value="road">Road</option><option value="local">Local</option>
              </select></label>
          </div>

          <label class="pd-okr-toggle">
            <input type="checkbox" v-model="taskForm.logistics.requires_approval" />
            <span>Approvals required<small>Sign-off is needed before the flagged prerequisites can be actioned.</small></span>
          </label>

          <label class="pd-field"><span>Additional requirements / constraints</span>
            <textarea v-model="taskForm.logistics.details" class="control" rows="2"
                      placeholder="What is needed, from whom, any constraints"></textarea></label>
        </div>

        <label class="pd-okr-toggle">
          <input type="checkbox" v-model="taskForm.weekly_okr" />
          <span>Weekly objective (OKR)<small>Show this task on the weekly report as a tracked objective.</small></span>
        </label>

        <p v-if="taskForm.parent_task_id" class="pd-hint">
          This will be a sub-task. Sub-tasks carry everything a task does — owner,
          date, status.
        </p>

        <button class="btn-primary btn-block" :disabled="saving || !taskForm.title.trim()" @click="saveTask">
          {{ taskForm.id ? "Save Changes" : "Add Task" }}
        </button>
      </aside>
    </div>

    <!-- Milestone form -->
    <div v-if="showMilestone" class="pd-sheet-backdrop" @click.self="showMilestone = false">
      <aside class="pd-sheet" role="dialog" aria-label="New milestone">
        <div class="pd-sheet-head">
          <h3 class="pd-sheet-title">New Milestone</h3>
          <button class="pd-close" aria-label="Close" @click="showMilestone = false">×</button>
        </div>

        <label class="pd-field"><span>Milestone</span>
          <input v-model="milestoneForm.name" class="control" placeholder="On delivery of Phase 1" /></label>

        <label class="pd-field"><span>Value</span>
          <input v-model.number="milestoneForm.value" class="control" type="number" min="0" step="0.01" /></label>

        <label class="pd-field"><span>Currency</span>
          <input v-model="milestoneForm.currency" class="control" maxlength="3" placeholder="Blank uses the organisation's" />
          <span class="pd-hint">
            Kept per milestone because it varies — 15 of the legacy's 82 were USD.
          </span></label>

        <label class="pd-field"><span>Due</span>
          <input v-model="milestoneForm.due_on" class="control" type="date" /></label>

        <div class="pd-field"><span>Required to become billable</span>
          <span class="pd-hint">
            The tasks and phases that must be complete before this milestone is
            earned and right for payment. Proof of delivery is attached later,
            on the milestone itself.
          </span>
          <div class="pd-req-picker">
            <div v-if="phases.length" class="pd-req-group">
              <div class="pd-req-group-title">Phases</div>
              <label v-for="ph in phases" :key="'ph' + ph.id" class="pd-req-opt">
                <input type="checkbox" :value="ph.id" v-model="milestoneForm.phase_ids" />
                <span>{{ ph.name }}</span>
              </label>
            </div>
            <div class="pd-req-group">
              <div class="pd-req-group-title">Tasks</div>
              <p v-if="!dependencyOptions.length" class="pd-empty-inline">No tasks on this project yet.</p>
              <label v-for="t in dependencyOptions" :key="'t' + t.id" class="pd-req-opt">
                <input type="checkbox" :value="t.id" v-model="milestoneForm.task_ids" />
                <span>{{ t.title }}</span>
              </label>
            </div>
          </div>
        </div>

        <button class="btn-primary btn-block"
                :disabled="saving || !milestoneForm.name.trim() || milestoneForm.value === null"
                @click="saveMilestone">Add Milestone</button>
      </aside>
    </div>

    <!-- Schedule-meeting dialog -->
    <div v-if="showMeeting" class="pd-sheet-backdrop" @click.self="showMeeting = false">
      <aside class="pd-sheet" role="dialog" aria-label="Schedule meeting">
        <div class="pd-sheet-head">
          <h3 class="pd-sheet-title">Schedule Teams meeting</h3>
          <button class="pd-close" aria-label="Close" @click="showMeeting = false">×</button>
        </div>

        <label class="pd-field"><span>Subject</span>
          <input v-model="meetingForm.subject" class="control" placeholder="Kick-off / status review…" /></label>

        <div class="pd-grid2">
          <label class="pd-field"><span>When</span>
            <input v-model="meetingForm.scheduled_at" type="datetime-local" class="control" /></label>
          <label class="pd-field"><span>Duration (min)</span>
            <input v-model.number="meetingForm.duration_minutes" type="number" min="5" max="480" step="5" class="control" /></label>
        </div>

        <label class="pd-field"><span>Agenda</span>
          <textarea v-model="meetingForm.agenda" class="control" rows="3" placeholder="Optional agenda…"></textarea></label>

        <div class="pd-field"><span>Attendees</span>
          <p v-if="!meetingSuggestions.length" class="pd-empty-inline">No people on the project or client yet — add an email below.</p>
          <div v-else class="pd-att-picker">
            <label v-for="s in meetingSuggestions" :key="s.address" class="pd-att-opt">
              <input type="checkbox" :checked="isPicked(s.address)" @change="toggleAttendee(s)" />
              <span>{{ s.name }} <small>· {{ s.group }}</small></span>
            </label>
          </div>
          <div class="pd-inline-add pd-att-extra">
            <input v-model="meetingForm.extraEmail" class="pd-input pd-grow" type="email" placeholder="Add another email…" @keyup.enter="addExtraAttendee" />
            <button class="btn-secondary" @click="addExtraAttendee">Add</button>
          </div>
          <div v-if="meetingForm.attendees.length" class="pd-chips pd-att-selected">
            <span v-for="a in meetingForm.attendees" :key="a.address" class="pd-chip">{{ a.name }}</span>
          </div>
        </div>

        <button class="btn-primary btn-block"
          :disabled="schedulingMeeting || !meetingForm.subject.trim() || !meetingForm.scheduled_at || !meetingForm.attendees.length"
          @click="scheduleMeeting">{{ schedulingMeeting ? "Scheduling…" : "Schedule meeting" }}</button>
      </aside>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.pd-wrap { @apply flex flex-col; padding: var(--space-6); width: 100%; max-width: 100%; gap: var(--space-4); }
@media (max-width: 640px) { .pd-wrap { padding: var(--space-4); } }

.pd-crumbs { @apply flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); }
.pd-crumb { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: inherit; color: var(--color-text-muted); }
.pd-crumb:hover { color: var(--color-text); }
.pd-sep { color: var(--color-text-light); }
.pd-current { color: var(--color-text); }
.pd-loading { padding: var(--space-6); color: var(--color-text-muted); }

.pd-head { @apply flex items-start justify-between; gap: var(--space-4); }
.pd-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.pd-byline { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }
.pd-progress { @apply flex flex-col items-end; }
.pd-progress-value { font-size: var(--text-xl-size); font-weight: 700; color: var(--color-brand-on-surface); }
.pd-progress-label { font-size: var(--text-xs-size); color: var(--color-text-muted); }

.pd-facts-row { @apply grid; grid-template-columns: repeat(2, minmax(0, 26rem)); justify-content: start; gap: var(--space-3) var(--space-8); }
@media (max-width: 48rem) { .pd-facts-row { grid-template-columns: 1fr; } }
.pd-facts { margin: 0; }
.pd-facts > div { @apply flex items-center; gap: var(--space-3); padding: var(--space-1) 0; min-height: 2rem; }
.pd-facts dt { font-size: var(--text-sm-size); color: var(--color-text-muted); min-width: 7rem; }
.pd-facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }
.pd-status { border-left: 2px solid var(--color-brand-base); padding-left: var(--space-2); }
.pd-late { color: var(--color-danger-text); }
.pd-drift {
  margin-left: var(--space-2); padding: 1px var(--space-2); border-radius: var(--radius-sm);
  background: var(--color-warning-subtle); color: var(--color-warning-text);
  font-size: var(--text-xs-size); cursor: help;
}

/* One row that scrolls sideways when the tabs don't fit — no wrap, so no
   accidental vertical scroll (overflow-x:auto forces overflow-y to auto). */
.pd-tabs { @apply flex flex-nowrap gap-1; overflow-x: auto; overflow-y: hidden; border-bottom: 1px solid var(--color-border); scrollbar-width: thin; }
.pd-tabs::-webkit-scrollbar { height: 6px; }
.pd-tabs::-webkit-scrollbar-thumb { background: var(--color-border-strong); border-radius: 3px; }
.pd-tab {
  @apply cursor-pointer border-none bg-transparent;
  flex: 0 0 auto;
  padding: var(--space-2) var(--space-3);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap;
}
.pd-tab:hover { color: var(--color-text); }
.pd-tab.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }

/* Sub-tabs inside the Team & Roles panel */
.pd-subtabs { display: inline-flex; flex-wrap: wrap; gap: 4px; padding: 4px; margin-bottom: 20px; background: var(--color-surface-sunken); border-radius: 10px; }
.pd-subtab {
  cursor: pointer; border: none; background: transparent; flex: 0 0 auto;
  padding: 6px 14px; border-radius: 7px; font-family: inherit; font-size: var(--text-sm-size);
  color: var(--color-text-muted); white-space: nowrap;
}
.pd-subtab:hover { color: var(--color-text); }
.pd-subtab.active { background: var(--color-surface); color: var(--color-brand-on-surface); font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
.pd-subpanel { animation: pd-fade .15s ease; }
@keyframes pd-fade { from { opacity: 0; } to { opacity: 1; } }

.pd-panel { @apply flex flex-col; gap: var(--space-4); }
.pd-panel-actions { @apply flex justify-between items-center flex-wrap; gap: var(--space-3); margin-bottom: var(--space-4); }
.pd-inline-add { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.pd-input { height: 38px; font-size: var(--text-sm-size); padding: 0 11px; border: 1px solid var(--color-border-strong); border-radius: 8px; background: var(--color-surface); color: var(--color-text); }
.pd-input:focus { outline: none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle, rgba(0,119,219,.12)); }
select.pd-input { padding-right: 8px; cursor: pointer; }
textarea.pd-input { height: auto; padding: 9px 11px; line-height: 1.5; }
.pd-inline-add input[type="date"].pd-input { width: 160px; flex: 0 0 auto; }
.pd-inline-add .btn-secondary, .pd-inline-add button { flex: 0 0 auto; height: 38px; }
.pd-x { background: none; border: none; cursor: pointer; color: var(--color-text-light); font-size: .85rem; margin-left: auto; }
.pd-x:hover { color: var(--color-error); }
.pd-empty-inline { font-size: var(--text-xs-size); color: var(--color-text-light); }

/* Phases */
.pd-phases { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.pd-phase { border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 12px 14px; background: var(--color-surface-raised); }
.pd-phase.at-risk { border-color: var(--color-error); }
.pd-phase-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.pd-phase-name { font-weight: 600; font-size: var(--text-sm-size); }
.pd-phase-status { font-size: .68rem; font-weight: 600; text-transform: uppercase; padding: 1px 8px; border-radius: 999px; }
.pd-st-pending { background: var(--color-surface-sunken); color: var(--color-text-light); }
.pd-st-ongoing { background: #eef0ff; color: #4338ca; }
.pd-st-completed { background: var(--color-success-light); color: var(--color-success); }
.pd-phase-risk { font-size: .66rem; font-weight: 700; color: var(--color-error); background: var(--color-error-light); padding: 1px 7px; border-radius: 999px; }
.pd-phase-due { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-phase-bar { height: 8px; border-radius: 999px; background: var(--color-surface-sunken); overflow: hidden; }
.pd-phase-fill { display: block; height: 100%; border-radius: 999px; background: var(--color-brand-base); min-width: 2px; }
.pd-phase-meta { display: flex; gap: 14px; margin-top: 6px; font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-subphases { list-style: none; margin: 10px 0 0; padding: 0 0 0 6px; display: flex; flex-direction: column; gap: 4px; border-left: 2px solid var(--color-border); }
.pd-subphase { display: flex; align-items: center; gap: 10px; font-size: var(--text-sm-size); color: var(--color-text); padding: 3px 0 3px 8px; }
.pd-subphase-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pd-subphase-add { display: flex; gap: 6px; margin-top: 8px; padding-left: 8px; }
.pd-mini-input { height: 32px; font-size: var(--text-xs-size); flex: 0 1 220px; }
.pd-mini-btn { height: 32px; padding: 0 12px; font-size: var(--text-xs-size); }

/* Team & roles */
.pd-gov-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 18px; }
.pd-gov-head-actions { display: flex; gap: 8px; align-items: center; }
.pd-sheet-actions { @apply flex justify-end items-center; gap: var(--space-2); margin-top: auto; padding-top: var(--space-3); }
.pd-sheet-actions .btn-block { flex: 1; }
.btn-danger-text { color: var(--color-danger-text); }
.pd-gov-warn { @apply flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-warning-text); background: var(--color-warning-subtle); border-radius: 10px; padding: 8px 12px; margin: 8px 0 12px; }
.pd-gov-warn svg { width: 1rem; height: 1rem; flex: none; }

/* The governance register carries eight columns, so it scrolls in its own box
   rather than pushing the page sideways. */
.pd-gov-table { min-width: 60rem; }
/* A second line under a name — the department, the contact, what the role is
   accountable for. Truncated, with the full text on hover. */
.pd-gov-sub { display: block; max-width: 18rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: var(--text-xs-size); color: var(--color-text-light); }
/* Something that should be filled in and is not. Stated rather than left blank:
   an empty cell reads as "no data", and this is a gap somebody owns. */
.pd-gov-gap { color: var(--color-text-light); font-style: italic; }
.pd-gov-note { margin: var(--space-2) 0 var(--space-4); font-size: var(--text-xs-size); color: var(--color-text-light); max-width: 62ch; }
.pd-gov-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(22rem, 1fr)); gap: 12px; }
.pd-gov-role { border: 1px solid var(--color-border); border-radius: 12px; padding: 12px 14px; background: var(--color-surface-raised); border-left: 3px solid var(--color-border-strong); }
.pd-gov-role.vacant { border-left-color: var(--color-warning-text); }
.pd-gov-role-head { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.pd-gov-role-name { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); }
.pd-gov-req { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-warning-text); text-transform: uppercase; letter-spacing: .04em; }
.pd-gov-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 10px; }
.pd-gov-wide { grid-column: 1 / -1; }
@media (max-width: 34rem) { .pd-gov-fields { grid-template-columns: 1fr; } }
.pd-cust-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 24px; }
.pd-cust-org { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-brand-on-surface); }
.pd-cust-add { margin: 10px 0; }
.pd-cust-add summary { cursor: pointer; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.pd-cust-add-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 8px; margin-top: 8px; align-items: center; }
.pd-cust-sub { margin-top: 18px; }
.pd-stake-add { margin-top: 10px; }
.pd-team-leads { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 18px; }
@media (max-width: 48rem) { .pd-team-leads { grid-template-columns: 1fr; } }
.pd-team-group { border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 12px 14px; }
.pd-team-group-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; font-size: var(--text-sm-size); font-weight: 600; }
.pd-roles { display: flex; flex-direction: column; gap: 10px; }
.pd-teams { display: grid; grid-template-columns: repeat(auto-fill, minmax(24rem, 1fr)); gap: 12px; }
.pd-reports .pd-report-block { margin-bottom: 20px; }
.pd-h5 { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 14px 0 6px; }
.pd-rd-kpis { display: flex; flex-wrap: wrap; gap: 10px; margin: 6px 0 4px; }
.pd-rd-kpi { display: flex; flex-direction: column; gap: 2px; min-width: 110px; padding: 10px 14px; border: 1px solid var(--color-border); border-radius: 10px; background: var(--color-surface); }
.pd-rd-kpi.pd-rd-hot { border-color: color-mix(in oklab, var(--color-danger-text) 40%, transparent); background: color-mix(in oklab, var(--color-danger-subtle) 40%, transparent); }
.pd-rd-n { font-size: var(--text-lg-size); font-weight: 700; font-variant-numeric: tabular-nums; }
.pd-rd-l { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-heat { display: inline-block; white-space: nowrap; font-weight: 700; border-radius: 6px; padding: 1px 9px; font-size: var(--text-xs-size); }
.pd-heat-low { color: var(--color-success-text); background: var(--color-success-subtle); }
.pd-heat-medium { color: var(--color-warning-text); background: var(--color-warning-subtle); }
.pd-heat-high { color: var(--color-danger-text); background: var(--color-danger-subtle); }
.pd-rd-overdue { background: color-mix(in oklab, var(--color-danger-subtle) 30%, transparent); }
.pd-rd-flag { margin-left: 6px; font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--color-danger-text); background: var(--color-danger-subtle); border-radius: 4px; padding: 0 5px; }
.pd-report-vacant td:first-child { color: var(--color-warning-text); }
.pd-report-req { color: var(--color-warning-text); margin-left: 3px; }
.pd-org-leadership { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
.pd-org-node { border: 1px solid var(--color-border); border-radius: 10px; padding: 8px 12px; background: var(--color-surface-raised); min-width: 10rem; }
.pd-org-role { display: block; font-size: var(--text-xs-size); color: var(--color-text-muted); }
.pd-org-name { font-weight: 600; color: var(--color-text); }
.pd-org-teams { display: grid; grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)); gap: 10px; }
.pd-org-team { border: 1px solid var(--color-border); border-top: 3px solid var(--color-brand-base); border-radius: 10px; padding: 8px 12px; background: var(--color-surface-raised); }
.pd-org-team-name { font-weight: 600; font-size: var(--text-sm-size); }
.pd-org-lead { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 2px 0 6px; }
.pd-org-team ul { list-style: none; padding: 0; margin: 0; font-size: var(--text-sm-size); }
.pd-org-team li { padding: 2px 0; }
.pd-org-cap { color: var(--color-text-muted); font-size: var(--text-xs-size); }
@media print { .pd-tabs, .pd-panel-actions button, .app-nav, nav { display: none !important; } .pd-report-block { break-inside: avoid; } }
.pd-team-card { border: 1px solid var(--color-border); border-radius: 12px; padding: 12px 14px; background: var(--color-surface-raised); }
.pd-team-card-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.pd-team-desc { margin-bottom: 8px; }
.pd-team-members { width: 100%; border-collapse: collapse; font-size: var(--text-xs-size); margin-bottom: 8px; }
.pd-team-members th { text-align: left; color: var(--color-text-muted); font-weight: 600; padding: 2px 6px; border-bottom: 1px solid var(--color-border); }
.pd-team-members td { padding: 4px 6px; border-bottom: 1px solid var(--color-border); }
.pd-team-dates { white-space: nowrap; color: var(--color-text-muted); }
.pd-lead-toggle { cursor: pointer; border: 1px solid var(--color-border); background: var(--color-surface); border-radius: 999px; padding: 1px 8px; font-size: var(--text-xs-size); font-family: inherit; color: var(--color-text-muted); }
.pd-lead-toggle.on { background: var(--color-brand-subtle); color: var(--color-brand-on-surface); border-color: var(--color-brand-base); font-weight: 600; }
.pd-member-add { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.pd-member-check { display: inline-flex; align-items: center; gap: 4px; font-size: var(--text-xs-size); color: var(--color-text-muted); white-space: nowrap; }
.pd-member-cap { width: 5rem; }
.pd-member-date { width: 9rem; }
.pd-role { border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 12px 14px; }
.pd-role-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.pd-role-name { font-weight: 600; font-size: var(--text-sm-size); }
.pd-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.pd-chip { display: inline-flex; align-items: center; gap: 6px; font-size: var(--text-xs-size); padding: 3px 4px 3px 10px; border-radius: 999px; background: var(--color-brand-subtle); color: var(--color-brand-base); }
.pd-chip-x { border: none; background: rgba(0,0,0,.08); border-radius: 50%; width: 15px; height: 15px; cursor: pointer; font-size: 10px; line-height: 1; color: inherit; }
.pd-chip-x:hover { background: rgba(0,0,0,.18); }

.pd-card {
  @apply flex flex-col; gap: var(--space-3);
  padding: var(--space-5);
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
.pd-table-card { padding: 0; overflow-x: auto; }
.pd-h3 { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.pd-h4 { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.04em; margin: 0; }
.pd-prose { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; white-space: pre-wrap; line-height: 1.6; }
.pd-muted { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-optional { font-style: normal; font-weight: 400; color: var(--color-text-light); }
.pd-error { margin: var(--space-2) 0 0; font-size: var(--text-xs-size); color: var(--color-danger-text); }
.pd-hint { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0; }
.pd-empty { font-size: var(--text-sm-size); color: var(--color-text-light); padding: var(--space-5) 0; margin: 0; max-width: 60ch; }
.pd-actions { @apply flex justify-end; }
.pd-nowrap { white-space: nowrap; }

.pd-masked {
  padding: var(--space-2) var(--space-3); border-radius: var(--radius-md);
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: var(--text-xs-size); margin: 0;
}

.pd-totals { @apply flex flex-wrap; gap: var(--space-3); }
.pd-total {
  @apply inline-flex flex-wrap items-baseline; gap: var(--space-3);
  padding: var(--space-2) var(--space-4); border-radius: var(--radius-md);
  border: 1px solid var(--color-border); background: var(--color-surface-raised);
}
.pd-total-ccy { font-size: var(--text-xs-size); font-weight: 700; color: var(--color-text-muted); }
.pd-total-item { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.pd-total-item strong { color: var(--color-text); font-variant-numeric: tabular-nums; }
.pd-total-item.outstanding strong { color: var(--color-warning-text); }

.pd-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.pd-table th {
  text-align: left; padding: var(--space-3); background: var(--color-surface-sunken);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); white-space: nowrap;
}
.pd-table td { padding: var(--space-3); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.pd-table tr:last-child td { border-bottom: none; }
.pd-table tr.done .pd-task-title { text-decoration: line-through; color: var(--color-text-light); }
.pd-subrow td { background: var(--color-surface); }
.pd-subtask { color: var(--color-text-muted); font-size: var(--text-xs-size); }
.pd-tick { width: 2rem; }
.pd-task-title { font-weight: 500; }
.pd-sub { @apply block; font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-ref { font-variant-numeric: tabular-nums; color: var(--color-text-muted); }
.pd-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.pd-row-actions { @apply flex; gap: var(--space-3); }
.pd-flag {
  margin-left: var(--space-2); padding: 1px var(--space-2); border-radius: var(--radius-sm);
  background: var(--color-warning-subtle); color: var(--color-warning-text);
  font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.04em;
}
.pd-priority { font-size: var(--text-xs-size); white-space: nowrap; }
.prio-high { color: var(--color-danger-text); font-weight: 500; }
.prio-medium { color: var(--color-warning-text); }
.prio-low { color: var(--color-text-muted); }

.pd-comments, .pd-replies, .pd-feed, .pd-list, .pd-people { @apply list-none; margin: 0; padding: 0; }
.pd-comment { padding: var(--space-3) 0; border-bottom: 1px solid var(--color-border); }
.pd-comment:last-child { border-bottom: none; }
.pd-comment-row { @apply flex; gap: var(--space-3); padding: var(--space-2) 0; }
.pd-comment-body { flex: 1; }
.pd-comment-who { @apply flex flex-wrap items-baseline; gap: var(--space-2); font-size: var(--text-sm-size); margin: 0; color: var(--color-text); }
.pd-comment-when { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-comment-text { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; white-space: pre-wrap; }
.pd-replies { margin-left: var(--space-6); border-left: 2px solid var(--color-border); padding-left: var(--space-3); }
.pd-reply { font-size: var(--text-xs-size); margin-top: var(--space-1); }
.pd-replying { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; }

.pd-event { @apply flex; gap: var(--space-3); padding: var(--space-3) 0; border-bottom: 1px solid var(--color-border); }
.pd-event:last-child { border-bottom: none; }
.pd-event-who { @apply flex flex-wrap items-baseline; gap: var(--space-2); font-size: var(--text-sm-size); margin: 0; color: var(--color-text); }

.pd-block { @apply flex flex-col; width: 100%; gap: var(--space-2); padding-top: var(--space-3); border-top: 1px solid var(--color-border); }
.pd-block-head { @apply flex items-center justify-between; }
.pd-list li { @apply flex items-center; gap: var(--space-2); padding: var(--space-1) 0; font-size: var(--text-sm-size); color: var(--color-text); }
.pd-list .struck, .pd-done-dep { text-decoration: line-through; color: var(--color-text-light); }
.pd-people { @apply flex flex-wrap; gap: var(--space-2); }
.pd-person-chip {
  @apply inline-flex items-center; gap: var(--space-1);
  padding: 2px var(--space-2); border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text);
  font-size: var(--text-xs-size);
}
.pd-person-chip.assisting { background: var(--color-brand-subtle); color: var(--color-brand-on-surface); }
.pd-add-row { @apply flex flex-wrap items-start; gap: var(--space-2); }
.pd-add-picker { flex: 1; min-width: 12rem; }
.pd-capacity { max-width: 9rem; }
.pd-upload { @apply cursor-pointer; }

.pd-stack { @apply inline-flex items-center; }
.pd-avatar {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 1.5rem; height: 1.5rem; margin-right: -0.35rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  border: 2px solid var(--color-surface-raised);
  font-size: 0.6rem; font-weight: 600;
}

.pd-mini { margin: 0; font-size: var(--text-xs-size); padding: 3px 6px; border: 1px solid var(--color-border-strong); border-radius: 6px; background: var(--color-surface); color: var(--color-text); }
.pd-mini > div { @apply flex items-baseline justify-between; gap: var(--space-3); padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border); }
.pd-mini > div:last-child { border-bottom: none; }
.pd-mini dt { font-size: var(--text-sm-size); color: var(--color-text-muted); }
.pd-mini dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }

.pd-field { @apply flex flex-col; gap: var(--space-1); }
.pd-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.pill { display: inline-block; padding: 2px var(--space-3); border-radius: 999px; font-size: var(--text-xs-size); border: 1px solid transparent; white-space: nowrap; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 30%, transparent); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); border-color: color-mix(in oklab, var(--color-warning-text) 30%, transparent); }
.pill-danger  { background: var(--color-danger-subtle);  color: var(--color-danger-text);  border-color: color-mix(in oklab, var(--color-danger-text) 30%, transparent); }
.pill-info    { background: var(--color-brand-subtle);   color: var(--color-brand-on-surface); border-color: color-mix(in oklab, var(--color-brand-base) 30%, transparent); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); border-color: var(--color-border); }

/* ── Logistics summary rollup ─────────────────────────────────────────── */
.pd-lsum { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 22px; }
.pd-lsum-card { border: 1px solid var(--color-border); border-radius: 12px; background: var(--color-surface); padding: 14px 16px; }
.pd-lsum-h { font-size: var(--text-xs-size); text-transform: uppercase; letter-spacing: .05em; color: var(--color-text-light); font-weight: 600; }
.pd-lsum-big { font-size: 1.6rem; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }
.pd-lsum-unit { font-size: var(--text-sm-size); font-weight: 500; color: var(--color-text-light); }
.pd-lsum-bar { height: 6px; background: rgba(0,0,0,.06); border-radius: 999px; overflow: hidden; margin: 8px 0 6px; }
.pd-lsum-fill { display: block; height: 100%; background: var(--color-brand-base); border-radius: 999px; }
.pd-lsum-sub { font-size: var(--text-xs-size); color: var(--color-text-light); margin-top: 2px; }
.pd-lsum-flag { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-warning-text); margin-top: 4px; }
@media (max-width: 900px) { .pd-lsum { grid-template-columns: repeat(2, 1fr); } }

.pd-est-basis { margin: -4px 0 10px; }
.pd-est-src { color: var(--color-text-muted); font-style: italic; }

/* ── Travel optimisation tips ─────────────────────────────────────────── */
.pd-tips { margin-top: 16px; border: 1px solid color-mix(in oklab, var(--color-brand-base) 25%, var(--color-border)); border-radius: 12px; background: color-mix(in oklab, var(--color-brand-subtle) 30%, transparent); padding: 14px 16px; }
.pd-tips-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.pd-tips-title { font-weight: 600; }
.pd-tips-save { font-size: var(--text-xs-size); font-weight: 700; color: var(--color-success-text); background: var(--color-success-subtle); border-radius: 999px; padding: 2px 10px; }
.pd-tip { display: grid; grid-template-columns: auto 1fr; gap: 10px; align-items: start; padding: 8px 0; border-top: 1px solid var(--color-border); }
.pd-tip:first-of-type { border-top: none; }
.pd-tip-tag { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; border-radius: 5px; padding: 2px 7px; margin-top: 2px; white-space: nowrap; }
.pd-tip-merge { color: var(--color-brand-on-surface); background: var(--color-brand-subtle); }
.pd-tip-batch { color: var(--color-warning-text); background: var(--color-warning-subtle); }
.pd-tip-title { font-weight: 600; display: flex; align-items: center; gap: 8px; }
.pd-tip-save { font-size: 11px; font-weight: 700; color: var(--color-success-text); }
.pd-tip-detail { font-size: var(--text-sm-size); color: var(--color-text-light); margin-top: 2px; }
.pd-tip-cycles { font-size: var(--text-xs-size); color: var(--color-text-muted); margin-top: 3px; font-variant-numeric: tabular-nums; }

.pd-ti-resolved { opacity: .6; }
.pd-ti-task { font-size: var(--text-xs-size); color: var(--color-text-muted); }

/* ── Financial closure ────────────────────────────────────────────────── */
/* The financial-closure panel. Named `pd-closure`, not `pd-close`: nine × buttons
   also carried `.pd-close`, and their rule — declared later — stripped this
   panel's border, background and padding. */
.pd-closure { border: 1px solid var(--color-border); border-radius: 12px; background: var(--color-surface); padding: 14px 16px; margin-bottom: 22px; }
.pd-closure-done { border-color: color-mix(in oklab, var(--color-success-text) 35%, transparent); background: color-mix(in oklab, var(--color-success-subtle) 30%, transparent); }
.pd-closure-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.pd-closure-gates { display: flex; flex-wrap: wrap; gap: 8px 20px; margin-top: 8px; }
.pd-closure-gate { display: flex; align-items: center; gap: 8px; font-size: var(--text-sm-size); }
.pd-closure-gate.ok { color: var(--color-success-text); }
.pd-closure-gate.pending { color: var(--color-text); }
.pd-closure-tick { font-weight: 800; width: 16px; text-align: center; }
.pd-closure-gate.pending .pd-closure-tick { color: var(--color-warning-text); }
.pd-closure-sub { color: var(--color-text-light); font-size: var(--text-xs-size); }

/* ── Training & enablement ────────────────────────────────────────────── */
.pd-train-card { border: 1px solid var(--color-border); border-radius: 12px; padding: 16px; margin-bottom: 18px; background: var(--color-surface); }
.pd-train-head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.pd-train-grid { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; margin-bottom: 14px; }
.pd-train-field { display: flex; flex-direction: column; gap: 4px; font-size: var(--text-xs-size); color: var(--color-text-muted); min-width: 160px; }
.pd-train-field .control { width: 100%; }
.pd-train-totals { display: flex; gap: 18px; margin-left: auto; }
.pd-train-totals > div { display: flex; flex-direction: column; }
.pd-train-add { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 12px; }
.pd-train-picker { min-width: 220px; }
.pd-train-check { display: inline-flex; align-items: center; gap: 4px; font-size: var(--text-sm-size); color: var(--color-text-muted); }

/* ── Order release gate ───────────────────────────────────────────────── */
.pd-release { border: 1px solid var(--color-border); border-radius: 12px; background: var(--color-surface-sunken); padding: 12px 14px; margin: 14px 0 4px; }
.pd-release-head { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.pd-release-title { font-weight: 600; }
.pd-inline-select { width: auto; height: 30px; padding: 0 8px; }
.pd-release-gate { display: inline-flex; align-items: center; gap: 6px; font-size: var(--text-sm-size); color: var(--color-text-muted); }
.pd-release-signals { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; margin-top: 10px; }
.pd-sig { display: inline-flex; align-items: center; gap: 6px; font-size: var(--text-sm-size); }
.pd-sig.ok { color: var(--color-success-text); font-weight: 600; }
.pd-sig.off { color: var(--color-text-light); }
.pd-sig-override { display: inline-flex; align-items: center; gap: 3px; font-size: var(--text-xs-size); color: var(--color-text-muted); margin-left: 4px; font-weight: 400; }
.pd-release-lpo { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 10px; }
.pd-release-lpo-l { font-size: var(--text-sm-size); color: var(--color-text-muted); }

/* ── Bill of Materials ────────────────────────────────────────────────── */
.pd-hidden-file { display: none; }
.pd-bom-totals { display: flex; flex-wrap: wrap; gap: 10px; margin: 6px 0 14px; }
.pd-bom-kpi { display: flex; flex-direction: column; gap: 2px; min-width: 120px; padding: 10px 14px; border: 1px solid var(--color-border); border-radius: 10px; background: var(--color-surface); }
.pd-bom-n { font-size: var(--text-lg-size); font-weight: 700; font-variant-numeric: tabular-nums; }
.pd-bom-l { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-bom-table th, .pd-bom-table td { white-space: nowrap; }
.pd-bom-add { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; align-items: center; }

/* ── Controls & alerts ────────────────────────────────────────────────── */
.pd-alerts { border: 1px solid var(--color-border); border-radius: 12px; background: var(--color-surface); padding: 14px 16px; margin-bottom: 22px; }
.pd-alerts-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.pd-alerts-counts { display: flex; gap: 6px; }
.pd-alert-chip { font-size: 11px; font-weight: 700; border-radius: 999px; padding: 2px 9px; text-transform: uppercase; letter-spacing: .03em; }
.pd-alert-chip.pd-alert-critical { color: var(--color-danger-text); background: var(--color-danger-subtle); }
.pd-alert-chip.pd-alert-warning { color: var(--color-warning-text); background: var(--color-warning-subtle); }
.pd-alert-chip.pd-alert-info { color: var(--color-brand-on-surface); background: var(--color-brand-subtle); }
.pd-alert-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.pd-alert { display: grid; grid-template-columns: auto 1fr auto; align-items: start; gap: 10px; padding: 10px 12px; border-radius: 9px; border: 1px solid var(--color-border); border-left-width: 3px; background: var(--color-surface); }
.pd-alert-critical { border-left-color: var(--color-danger-text); background: color-mix(in oklab, var(--color-danger-subtle) 40%, transparent); }
.pd-alert-warning { border-left-color: var(--color-warning-text); background: color-mix(in oklab, var(--color-warning-subtle) 35%, transparent); }
.pd-alert-info { border-left-color: var(--color-brand-base); }
.pd-alert-dot { width: 8px; height: 8px; border-radius: 999px; margin-top: 6px; }
.pd-alert-critical .pd-alert-dot { background: var(--color-danger-text); }
.pd-alert-warning .pd-alert-dot { background: var(--color-warning-text); }
.pd-alert-info .pd-alert-dot { background: var(--color-brand-base); }
.pd-alert-title { font-weight: 600; display: flex; align-items: center; gap: 8px; }
.pd-alert-n { font-size: 11px; font-weight: 700; background: var(--color-surface-sunken); border-radius: 999px; padding: 0 7px; }
.pd-alert-detail { font-size: var(--text-xs-size); color: var(--color-text-light); margin-top: 2px; }
.pd-alert-cat { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: var(--color-text-light); align-self: center; }

/* ── Approval tracker ─────────────────────────────────────────────────── */
.pd-appr-kpis { display: flex; flex-wrap: wrap; gap: 10px; margin: 4px 0 14px; }
.pd-appr-kpi { display: flex; flex-direction: column; gap: 2px; min-width: 96px; padding: 10px 14px; border: 1px solid var(--color-border); border-radius: 10px; background: var(--color-surface); }
.pd-appr-kpi.pd-appr-pending { border-color: color-mix(in oklab, var(--color-warning-text) 40%, transparent); background: var(--color-warning-subtle); }
.pd-appr-kpi.pd-appr-warn { border-color: color-mix(in oklab, var(--color-danger-text) 35%, transparent); }
.pd-appr-n { font-size: var(--text-lg-size); font-weight: 700; font-variant-numeric: tabular-nums; }
.pd-appr-l { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-appr-table td { vertical-align: top; }
.pd-appr-row-pending { background: color-mix(in oklab, var(--color-warning-subtle) 45%, transparent); }
.pd-appr-title { font-weight: 600; }
.pd-appr-detail { font-size: var(--text-xs-size); color: var(--color-text-light); margin-top: 2px; }
.pd-appr-ref { font-family: ui-monospace, monospace; font-size: 11px; margin-right: 6px; color: var(--color-text-muted); }
.pd-appr-flag { display: inline-block; margin-left: 6px; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; font-weight: 700; color: var(--color-danger-text); background: var(--color-danger-subtle); border-radius: 4px; padding: 0 5px; }
.pd-appr-sub { display: block; font-size: var(--text-xs-size); color: var(--color-text-light); margin-top: 2px; }
.pd-kind-chip { display: inline-block; font-size: 11px; font-weight: 600; border-radius: 5px; padding: 1px 8px; white-space: nowrap; }
.pd-kind-travel { color: var(--color-brand-on-surface); background: var(--color-brand-subtle); }
.pd-kind-procurement { color: var(--color-text); background: var(--color-surface-sunken); }
.pd-appr-btn { height: 28px; padding: 0 10px; font-size: var(--text-xs-size); border: 1px solid var(--color-border); border-radius: 7px; background: var(--color-surface); cursor: pointer; margin-right: 5px; }
.pd-appr-btn:hover { background: var(--color-surface-sunken); }
.pd-mini-good { color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 35%, transparent); }
.pd-mini-bad { color: var(--color-danger-text); border-color: color-mix(in oklab, var(--color-danger-text) 35%, transparent); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); text-align: left; }
.link-btn:hover { @apply underline; }
.link-btn:disabled { color: var(--color-text-light); cursor: not-allowed; text-decoration: none; opacity: .6; }
.link-danger { color: var(--color-danger-text); }

/* ── Milestone requirements & proof ───────────────────────────────────── */
.pd-req-picker { display: flex; flex-direction: column; gap: 12px; margin-top: 8px; max-height: 300px; overflow-y: auto; border: 1px solid var(--color-border); border-radius: 10px; padding: 12px 14px; }
.pd-req-group-title { font-size: var(--text-xs-size); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-light); margin-bottom: 6px; }
.pd-req-opt { display: flex; align-items: center; gap: 8px; font-size: var(--text-sm-size); color: var(--color-text); padding: 4px 2px; cursor: pointer; }
.pd-req-opt input { flex: 0 0 auto; }
.pd-req-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.pd-req-chip { display: inline-flex; align-items: center; gap: 6px; font-size: var(--text-xs-size); border-radius: 999px; padding: 2px 9px; border: 1px solid var(--color-border-strong); }
.pd-req-chip.done { background: #dcfce7; border-color: #86efac; color: #166534; }
.pd-req-chip.open { background: var(--color-surface-sunken); color: var(--color-text-light); }
.pd-req-kind { font-size: 9px; text-transform: uppercase; letter-spacing: .04em; opacity: .7; }
.pd-req-tick { font-weight: 700; }
.pd-proofs { list-style: none; margin: 0 0 4px; padding: 0; display: flex; flex-direction: column; gap: 2px; }
.pd-proofs li { display: flex; align-items: center; gap: 6px; font-size: var(--text-xs-size); }
.pd-proof-add { display: inline-flex; align-items: center; cursor: pointer; font-size: var(--text-xs-size); color: var(--color-brand-on-surface); }
.pd-proof-add:hover { text-decoration: underline; }
.pd-ready { display: inline-block; margin-left: 6px; font-size: 10px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: #166534; background: #dcfce7; border-radius: 5px; padding: 1px 6px; }

/* ── Charter (Details tab) ────────────────────────────────────────────── */
.pd-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 18px; }
@media (max-width: 48rem) { .pd-grid2 { grid-template-columns: 1fr; } }
.pd-readonly { font-size: var(--text-sm-size); color: var(--color-text); padding: 9px 11px; border: 1px dashed var(--color-border-strong); border-radius: 8px; background: var(--color-surface-sunken); }
.pd-docs { margin-top: 12px; display: flex; flex-direction: column; gap: 8px; }
.pd-doc-list { display: flex; flex-wrap: wrap; gap: 8px; }
.pd-doc { display: inline-flex; align-items: center; gap: 6px; font-size: var(--text-sm-size); background: var(--color-surface-sunken); border-radius: 8px; padding: 4px 10px; }
.pd-doc-add { display: inline-flex; align-items: center; cursor: pointer; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); align-self: flex-start; }
.pd-doc-add:hover { text-decoration: underline; }
.pd-charter-save { display: flex; justify-content: flex-end; }
.pd-team-heading { margin: 22px 0 12px; }

/* ── Project inbox ────────────────────────────────────────────────────── */
.pd-emails { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
.pd-email { border-bottom: 1px solid var(--color-border); }
.pd-email-row { display: flex; align-items: center; gap: 12px; padding: 10px 4px; cursor: pointer; }
.pd-email-row:hover { background: var(--color-surface-sunken); }
.pd-email-from { flex: 0 0 180px; font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pd-email-subject { flex: 1; min-width: 0; font-size: var(--text-sm-size); color: var(--color-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pd-email-when { flex: 0 0 auto; font-size: var(--text-xs-size); color: var(--color-text-light); white-space: nowrap; }
.pd-email-preview { padding: 0 4px 10px 192px; font-size: var(--text-xs-size); color: var(--color-text-light); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pd-email-body { width: 100%; height: 360px; border: 1px solid var(--color-border); border-radius: 8px; background: #fff; margin: 4px 0 12px; }
@media (max-width: 640px) { .pd-email-from { flex-basis: 110px; } .pd-email-preview { padding-left: 4px; } }

/* ── Supplier material orders ─────────────────────────────────────────── */
.pd-order { padding: 0; overflow: hidden; }
.pd-order-head { display: flex; align-items: center; gap: 12px; padding: 12px 16px; cursor: pointer; }
.pd-order-head:hover { background: var(--color-surface-sunken); }
.pd-order-ref { font-size: var(--text-xs-size); font-weight: 700; color: var(--color-text-light); font-variant-numeric: tabular-nums; }
.pd-order-name { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pd-order-due { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-order-caret { color: var(--color-text-light); }
.pd-order-body { padding: 4px 16px 16px; border-top: 1px solid var(--color-border); }
.pd-order-lpo { font-size: var(--text-sm-size); color: var(--color-text-light); margin: 10px 0; }
.pd-order-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
@media (max-width: 860px) { .pd-order-cols { grid-template-columns: 1fr; } }
.pd-order-sec h4 { margin: 0 0 8px; font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); }
.pd-order-add { display: flex; flex-direction: column; gap: 6px; margin-top: 8px; }
.pd-order-timeline, .pd-order-updates, .pd-order-contacts, .pd-order-docs { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.pd-order-timeline li { display: flex; align-items: baseline; gap: 8px; font-size: var(--text-sm-size); }
.pd-ot-date { font-size: var(--text-xs-size); color: var(--color-text-light); white-space: nowrap; min-width: 78px; }
.pd-ot-body { flex: 1; }
.pd-order-updates li { border: 1px solid var(--color-border); border-radius: 8px; padding: 8px 10px; font-size: var(--text-sm-size); }
.pd-ou-label { font-weight: 600; color: var(--color-text-light); }
.pd-ou-meta { display: flex; align-items: center; gap: 8px; font-size: var(--text-xs-size); color: var(--color-text-light); margin-top: 4px; }
.pd-order-contacts li { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: var(--text-sm-size); }
.pd-oc-x { font-size: var(--text-xs-size); color: var(--color-text-light); background: var(--color-surface-sunken); border-radius: 6px; padding: 1px 7px; }
.pd-order-docs li { display: flex; align-items: center; gap: 6px; font-size: var(--text-sm-size); }

/* ── Enriched task creator ────────────────────────────────────────────── */
.pd-task-kind { font-size: var(--text-xs-size); color: var(--color-text-light); background: var(--color-surface-sunken); border-radius: 8px; padding: 6px 10px; margin: 0 0 4px; }
.pd-task-kind strong { color: var(--color-text); }
.pd-assign-pick { display: flex; flex-direction: column; gap: 4px; border: 1px solid var(--color-border); border-radius: 10px; padding: 10px 12px; margin-top: 6px; max-height: 200px; overflow-y: auto; }
.pd-dep-pick { max-height: 160px; }
.pd-assign-opt { display: flex; align-items: center; gap: 8px; font-size: var(--text-sm-size); color: var(--color-text); }
.pd-resp { margin-top: 10px; border: 1px solid var(--color-border); border-radius: 10px; padding: 8px 12px; }
.pd-resp-title { font-size: var(--text-xs-size); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-light); margin-bottom: 6px; }
.pd-resp-row { display: flex; align-items: center; gap: 10px; padding: 4px 0; }
.pd-resp-name { flex: 1; min-width: 0; font-size: var(--text-sm-size); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pd-resp-toggle { display: inline-flex; border: 1px solid var(--color-border-strong); border-radius: 8px; overflow: hidden; }
.pd-resp-toggle button { border: none; background: var(--color-surface); color: var(--color-text-light); font: inherit; font-size: var(--text-xs-size); padding: 4px 10px; cursor: pointer; }
.pd-resp-toggle button.on { background: var(--color-brand-base); color: var(--color-on-brand); font-weight: 600; }
.pd-resp-toggle button + button { border-left: 1px solid var(--color-border-strong); }

/* ── Correspondence ───────────────────────────────────────────────────── */
.pd-ms-schedule { margin-left: auto; }
.pd-meetings { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.pd-meeting { border: 1px solid var(--color-border); border-radius: 12px; padding: 12px 14px; background: var(--color-surface); }
.pd-meeting.cancelled { opacity: .6; }
.pd-meeting-row { display: flex; gap: 16px; align-items: flex-start; }
.pd-tag-ok { background: #dcfce7; color: #166534; }
.pd-transcript { margin: 12px 0 0; padding: 12px 14px; background: var(--color-surface-sunken); border-radius: 8px; font-size: var(--text-xs-size); color: var(--color-text); white-space: pre-wrap; word-break: break-word; max-height: 340px; overflow-y: auto; font-family: var(--font-family-mono, monospace); line-height: 1.5; }
.pd-meeting-when { flex: 0 0 auto; min-width: 150px; display: flex; flex-direction: column; gap: 2px; }
.pd-meeting-date { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); }
.pd-meeting-dur { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-meeting-body { flex: 1; min-width: 0; }
.pd-meeting-subject { font-size: var(--text-sm-size); font-weight: 500; color: var(--color-text); display: flex; align-items: center; gap: 8px; }
.pd-meeting-att { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
.pd-att-chip { font-size: var(--text-xs-size); background: var(--color-surface-sunken); border-radius: 999px; padding: 1px 9px; color: var(--color-text-light); }
.pd-meeting-actions { flex: 0 0 auto; display: flex; align-items: center; gap: 12px; }
.pd-join { height: 32px; padding: 0 14px; text-decoration: none; }
.pd-att-picker { display: flex; flex-direction: column; gap: 4px; max-height: 220px; overflow-y: auto; border: 1px solid var(--color-border); border-radius: 10px; padding: 10px 12px; margin-top: 6px; }
.pd-att-opt { display: flex; align-items: center; gap: 8px; font-size: var(--text-sm-size); color: var(--color-text); }
.pd-att-opt small { color: var(--color-text-light); }
.pd-att-extra { margin-top: 8px; }
.pd-att-selected { margin-top: 10px; }

.pd-sheet-backdrop { @apply fixed inset-0 flex justify-end z-50; background: rgb(0 0 0 / 0.28); }
.pd-sheet {
  @apply flex flex-col overflow-y-auto; width: min(26rem, 100%); gap: var(--space-3);
  padding: var(--space-5); background: var(--color-surface-raised); box-shadow: var(--shadow-lg);
  animation: sheet-in 180ms ease;
}
.pd-sheet-wide { width: min(34rem, 100%); }
@keyframes sheet-in { from { transform: translateX(12px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .pd-sheet { animation: none; } }
.pd-sheet-head { @apply flex items-center justify-between; }
.pd-sheet-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.pd-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

.btn-primary, .btn-ghost, .btn-secondary {
  @apply inline-flex cursor-pointer items-center justify-center;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
  transition: background .15s ease, box-shadow .15s ease, border-color .15s ease;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-primary:hover:not(:disabled) { background: var(--color-brand-hover, #0064bb); }
.btn-ghost   { background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-secondary { background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-secondary:hover:not(:disabled) { background: var(--color-surface-sunken); border-color: var(--color-brand-base); color: var(--color-brand-on-surface, var(--color-brand-base)); }
.btn-block   { width: 100%; }
.btn-primary:disabled, .btn-ghost:disabled, .btn-secondary:disabled { @apply cursor-not-allowed opacity-50; }

/* ── Register (issues · dependencies · risks) ─────────────────────────── */
.pd-reg-block { border: 1px solid var(--color-border); border-radius: 14px; background: var(--color-surface); padding: 2px 18px 18px; box-shadow: 0 1px 2px rgba(16,24,40,.04); margin-bottom: 18px; }
.pd-reg-head { display: flex; align-items: center; gap: 8px; padding: 14px 0 12px; border-bottom: 1px solid var(--color-border); margin-bottom: 14px; }
.pd-reg-head h3 { margin: 0; font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); }
.pd-gen-btn { margin-left: auto; height: 30px; padding: 0 12px; font-size: var(--text-xs-size); font-weight: 600; border: 1px solid color-mix(in oklab, var(--color-brand-base) 40%, transparent); border-radius: 8px; background: var(--color-brand-subtle); color: var(--color-brand-on-surface); cursor: pointer; white-space: nowrap; }
.pd-gen-btn:hover { background: color-mix(in oklab, var(--color-brand-base) 18%, transparent); }
.pd-src-chip { margin-left: 8px; font-size: 10px; font-weight: 700; text-transform: capitalize; letter-spacing: .02em; color: var(--color-brand-on-surface); background: var(--color-brand-subtle); border-radius: 5px; padding: 1px 6px; white-space: nowrap; }
.pd-reg-count { font-size: var(--text-xs-size); background: var(--color-surface-sunken); color: var(--color-text-light); border-radius: 999px; padding: 1px 8px; }
.pd-reg-esc { display: block; font-size: var(--text-xs-size); color: var(--color-warning-text); margin-top: 2px; }
.pd-grow { flex: 1 1 200px; min-width: 150px; }
.pd-ccy { width: 72px; flex: 0 0 auto; text-transform: uppercase; text-align: center; }
.pd-amt { width: 130px; flex: 0 0 auto; }
.pd-mini-label { display: inline-flex; align-items: center; gap: 6px; font-size: var(--text-xs-size); color: var(--color-text-light); white-space: nowrap; }
.pd-mini-label .pd-input { min-width: 92px; }
.pd-or { font-size: var(--text-xs-size); color: var(--color-text-light); padding: 0 2px; }
.pd-dm-title { font-weight: 500; }
.pd-dm-done .pd-dm-title { text-decoration: line-through; color: var(--color-text-light); }
.pd-dm-flag { font-size: 10px; font-weight: 600; border-radius: 5px; padding: 1px 7px; margin-left: 8px; }
.pd-dm-flag.overdue { background: #fee2e2; color: #991b1b; }
.pd-dm-flag.risk { background: #fef9c3; color: #854d0e; }
.pd-reg-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
.pd-reg-table th { text-align: left; font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-light); padding: 6px 10px; border-bottom: 1px solid var(--color-border); }
.pd-reg-table td { font-size: var(--text-sm-size); color: var(--color-text); padding: 8px 10px; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
.pd-reg-table .pd-num { text-align: right; }
.pd-tag { font-size: var(--text-xs-size); background: var(--color-surface-sunken); border-radius: 6px; padding: 1px 7px; color: var(--color-text-light); }
.pd-sev { font-size: var(--text-xs-size); font-weight: 600; border-radius: 6px; padding: 1px 8px; }
.pd-sev-low { background: #e0f2fe; color: #075985; }
.pd-sev-medium { background: #fef9c3; color: #854d0e; }
.pd-sev-high { background: #ffedd5; color: #9a3412; }
.pd-sev-critical { background: #fee2e2; color: #991b1b; }
.pd-heat-lo { background: #dcfce7; color: #166534; }
.pd-heat-mid { background: #fef9c3; color: #854d0e; }
.pd-heat-hi { background: #fee2e2; color: #991b1b; }
.pd-risk-closed td { opacity: .55; }
.pd-risk-caret, .pd-risk-title-cell { cursor: pointer; }
.pd-risk-caret { color: var(--color-text-light); width: 20px; }
.pd-risk-detail-row td { background: var(--color-surface-sunken); }
.pd-risk-detail { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; padding: 12px 6px; }
@media (max-width: 720px) { .pd-risk-detail { grid-template-columns: 1fr; } }
.pd-risk-assoc { display: flex; flex-direction: column; gap: 12px; }
.pd-risk-ev-head { font-size: var(--text-xs-size); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-light); margin-bottom: 8px; }

/* ── Budget ───────────────────────────────────────────────────────────── */
.pd-fin-summary { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 22px; }
.pd-fin-card { flex: 1 1 220px; max-width: 320px; border: 1px solid var(--color-border); border-radius: 12px; padding: 14px 16px; background: var(--color-surface); }
.pd-fin-over { border-color: #fca5a5; background: #fef2f2; }
.pd-fin-ccy { font-weight: 700; font-size: var(--text-sm-size); color: var(--color-text); margin-bottom: 8px; }
.pd-fin-row { display: flex; justify-content: space-between; font-size: var(--text-sm-size); color: var(--color-text-light); padding: 2px 0; }
.pd-fin-row strong { color: var(--color-text); }
.pd-fin-remain { border-top: 1px solid var(--color-border); margin-top: 4px; padding-top: 6px; }
.pd-fin-bar { height: 7px; border-radius: 999px; background: var(--color-surface-sunken); overflow: hidden; margin-top: 10px; }
.pd-fin-fill { display: block; height: 100%; background: var(--color-brand-base); border-radius: 999px; }
.pd-fin-fill-over { background: #dc2626; }
.pd-fin-util { font-size: var(--text-xs-size); color: var(--color-text-light); margin-top: 5px; }
.pd-fin-flag { color: #dc2626; font-weight: 600; }
.pd-budget-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.pd-chip-tag { font-size: 10px; background: rgba(0,0,0,.08); border-radius: 5px; padding: 0 5px; margin-left: 5px; }
@media (max-width: 860px) { .pd-budget-cols { grid-template-columns: 1fr; } }

/* ── Expense tracking (analytics) ─────────────────────────────────────── */
.pd-cat { max-width: 150px; }
.pd-link { max-width: 170px; }
.pd-cat-chip { display: inline-block; font-size: 11px; font-weight: 600; text-transform: capitalize; color: var(--color-brand-base); background: var(----color-primary-soft, rgba(37,99,235,.1)); border-radius: 5px; padding: 1px 8px; }
.pd-link-chip { display: inline-block; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; font-size: 12px; color: var(--color-text-light); }
.pd-track { margin: 20px 0 8px; padding: 18px; border: 1px solid var(--color-border); border-radius: 12px; background: var(--color-surface-sunken); }
.pd-track-head { display: flex; align-items: baseline; gap: 10px; margin-bottom: 14px; }
.pd-track-head h3 { margin: 0; font-size: var(--text-md-size); }
.pd-track-ccy { font-size: var(--text-xs-size); font-weight: 700; letter-spacing: .05em; color: var(--color-text-light); background: rgba(0,0,0,.05); border-radius: 5px; padding: 1px 7px; }
.pd-fc { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
.pd-fc-cell { display: flex; flex-direction: column; gap: 2px; padding: 12px 14px; border: 1px solid var(--color-border); border-radius: 10px; background: var(--color-surface, #fff); }
.pd-fc-cell strong { font-size: var(--text-lg-size); }
.pd-fc-l { font-size: var(--text-xs-size); color: var(--color-text-light); text-transform: uppercase; letter-spacing: .04em; }
.pd-fc-sub { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-fc-good { color: #16a34a; font-weight: 600; }
.pd-fc-bad { color: #dc2626; font-weight: 600; }
.pd-fc-over .pd-fc-cell { border-color: rgba(220,38,38,.35); }
.pd-track-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.pd-track-block h4 { margin: 0 0 4px; font-size: var(--text-sm-size); color: var(--color-text-light); }
.pd-cat-table { margin-top: 6px; }
.pd-var-bad { color: #dc2626; font-weight: 600; }
.pd-var-good { color: #16a34a; }
.pd-burn { display: flex; flex-direction: column; gap: 7px; margin-top: 8px; }
.pd-burn-row { display: grid; grid-template-columns: 62px 1fr auto; align-items: center; gap: 10px; }
.pd-burn-m { font-size: var(--text-xs-size); color: var(--color-text-light); font-variant-numeric: tabular-nums; }
.pd-burn-bar { height: 8px; background: rgba(0,0,0,.06); border-radius: 999px; overflow: hidden; }
.pd-burn-fill { display: block; height: 100%; background: var(--color-brand-base); border-radius: 999px; }
.pd-burn-v { font-size: var(--text-xs-size); font-variant-numeric: tabular-nums; white-space: nowrap; }
.pd-burn-note { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 4px 0 0; }
.pd-link-stats { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 12px; font-size: var(--text-xs-size); color: var(--color-text); }
@media (max-width: 860px) { .pd-fc, .pd-track-grid { grid-template-columns: 1fr; } }

/* ── Weekly report ────────────────────────────────────────────────────── */
.pd-rag { display: inline-flex; align-items: center; gap: 7px; font-weight: 700; font-size: var(--text-xs-size); letter-spacing: .03em; border-radius: 999px; padding: 4px 12px; }
.pd-rag-dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
.pd-rag-green { background: #dcfce7; color: #166534; }
.pd-rag-amber { background: #fef9c3; color: #854d0e; }
.pd-rag-red   { background: #fee2e2; color: #991b1b; }

/* Status banner */
.pd-wk-toolbar { display: flex; justify-content: flex-end; margin-bottom: 10px; }
.pd-wk-banner {
  display: flex; align-items: center; gap: 28px;
  border: 1px solid var(--color-border); border-radius: 16px;
  padding: 22px 26px; margin-bottom: 18px;
  background: var(--color-surface);
  border-left: 6px solid var(--color-border-strong);
  box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
}
.pd-wk-banner.rag-green { border-left-color: #16a34a; background: linear-gradient(90deg, rgba(22,163,74,.05), transparent 40%); }
.pd-wk-banner.rag-amber { border-left-color: #d97706; background: linear-gradient(90deg, rgba(217,119,6,.06), transparent 40%); }
.pd-wk-banner.rag-red   { border-left-color: #dc2626; background: linear-gradient(90deg, rgba(220,38,38,.06), transparent 40%); }
.pd-wk-ring-wrap { flex: 0 0 auto; }
.pd-wk-ring { width: 108px; height: 108px; transform: rotate(-90deg); }
.pd-wk-ring-track { fill: none; stroke: var(--color-surface-sunken); stroke-width: 11; }
.pd-wk-ring-fill { fill: none; stroke-width: 11; stroke-linecap: round; transition: stroke-dashoffset .5s ease; }
.rag-green .pd-wk-ring-fill { stroke: #16a34a; }
.rag-amber .pd-wk-ring-fill { stroke: #d97706; }
.rag-red   .pd-wk-ring-fill { stroke: #dc2626; }
.pd-wk-ring-pct { transform: rotate(90deg); transform-origin: 60px 60px; text-anchor: middle; font-size: 26px; font-weight: 700; fill: var(--color-text); }
.pd-wk-ring-lbl { transform: rotate(90deg); transform-origin: 60px 60px; text-anchor: middle; font-size: 11px; fill: var(--color-text-light); letter-spacing: .04em; }
.pd-wk-banner-body { flex: 1; min-width: 0; }
.pd-wk-banner-top { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
.pd-wk-week { font-size: var(--text-sm-size); color: var(--color-text-light); }
.pd-wk-badge-pub { font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #166534; background: #dcfce7; border-radius: 5px; padding: 2px 7px; }
.pd-wk-badge-draft { font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-light); background: var(--color-surface-sunken); border-radius: 5px; padding: 2px 7px; }
.pd-wk-banner-headline { font-size: 1.25rem; font-weight: 700; color: var(--color-text); }
.pd-wk-delta { font-size: var(--text-sm-size); font-weight: 600; margin-top: 6px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.pd-wk-delta.up { color: #166534; }
.pd-wk-delta.down { color: #b91c1c; }
.pd-wk-delta.flat { color: var(--color-text-light); font-weight: 500; }
.pd-wk-ragmoved { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-light); background: var(--color-surface-sunken); border-radius: 6px; padding: 1px 8px; }

/* Signal chips */
.pd-wk-signals { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 22px; }
.pd-wk-sig { flex: 1 1 120px; display: flex; align-items: baseline; gap: 8px; border: 1px solid var(--color-border); border-radius: 12px; padding: 12px 16px; background: var(--color-surface); }
.pd-wk-sig b { font-size: 1.5rem; font-weight: 700; color: var(--color-text); line-height: 1; }
.pd-wk-sig span { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pd-wk-sig.bad  { border-color: #fca5a5; background: #fef2f2; }
.pd-wk-sig.bad b { color: #dc2626; }
.pd-wk-sig.warn { border-color: #fcd34d; background: #fffbeb; }
.pd-wk-sig.warn b { color: #b45309; }

/* Cards */
.pd-wk-card { border: 1px solid var(--color-border); border-radius: 14px; background: var(--color-surface); padding: 4px 18px 16px; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
.pd-wk-card-head { display: flex; align-items: center; gap: 10px; padding: 14px 0 12px; border-bottom: 1px solid var(--color-border); margin-bottom: 12px; }
.pd-wk-card-head h3 { margin: 0; font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); }
.pd-wk-card-head.accent-brand { border-bottom-color: var(--color-brand-base); }
.pd-wk-card-head.accent-warn { border-bottom-color: #f59e0b; }
.pd-wk-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.pd-wk-okrs, .pd-wk-risks { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.pd-wk-okrs li, .pd-wk-risks li { display: flex; align-items: center; gap: 10px; font-size: var(--text-sm-size); color: var(--color-text); padding: 7px 6px; border-radius: 8px; }
.pd-wk-okrs li:hover, .pd-wk-risks li:hover { background: var(--color-surface-sunken); }
.pd-wk-okrs li.done { color: var(--color-text-light); }
.pd-wk-okrs li.done .pd-wk-okr-title { text-decoration: line-through; }
.pd-wk-okr-title, .pd-wk-risk-title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pd-wk-okr-tick { font-weight: 700; color: var(--color-brand-base); width: 14px; text-align: center; }
.pd-wk-okrs li.done .pd-wk-okr-tick { color: #16a34a; }
.pd-wk-empty { font-size: var(--text-sm-size); color: var(--color-text-light); padding: 14px 6px; line-height: 1.5; }
.pd-wk-empty small { font-size: var(--text-xs-size); }

/* Publish */
.pd-wk-publish-card { margin-top: 20px; }
.pd-wk-publish-hint { font-size: var(--text-xs-size); color: var(--color-text-light); margin-left: auto; }
.pd-wk-note { width: 100%; resize: vertical; font-family: inherit; }
.pd-wk-publish-actions { display: flex; justify-content: flex-end; margin-top: 12px; }

/* History */
.pd-wk-history { margin-top: 20px; }
.pd-rag-chip { font-size: 10px; font-weight: 700; letter-spacing: .03em; border-radius: 6px; padding: 2px 8px; }
.pd-wk-htable td { vertical-align: middle; }
.pd-wk-htable tbody tr:hover { background: var(--color-surface-sunken); }
.pd-wk-hbar { display: inline-block; width: 54px; height: 6px; border-radius: 999px; background: var(--color-surface-sunken); overflow: hidden; vertical-align: middle; margin-right: 8px; }
.pd-wk-hbar-fill { display: block; height: 100%; background: var(--color-brand-base); border-radius: 999px; }
.pd-wk-hsummary { max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--color-text-light); }

/* OKR toggle in the task editor */
.pd-logi { display: flex; flex-direction: column; gap: 10px; padding: 14px; margin: 4px 0 8px; border: 1px solid var(--color-border); border-radius: 12px; background: var(--color-surface-sunken); }
.pd-logi-head { display: flex; align-items: center; gap: 8px; }
.pd-logi-title { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); }
.pd-req { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-danger-text); background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 999px; padding: 1px 8px; text-transform: uppercase; letter-spacing: .04em; }
.pd-req-mark { color: var(--color-danger-text); font-style: normal; }
.pd-logi-needs { max-height: none; }
.pd-logi-warn { font-size: var(--text-xs-size); color: var(--color-danger-text); margin: 0; }
.pd-logi-filter { width: auto; min-width: 12rem; }
.pd-logi-badge { display: inline-flex; align-items: center; gap: 3px; font-size: var(--text-xs-size); padding: 1px 7px; margin-left: 6px; border-radius: 999px; background: var(--color-brand-subtle); color: var(--color-brand-on-surface); white-space: nowrap; }
.pd-logi-badge.warn { background: var(--color-surface-sunken); color: var(--color-warning-text); }
.pd-proc-approve { width: auto; min-width: 7rem; padding: 4px 8px; font-size: var(--text-xs-size); }
.pd-prereq-note { font-size: var(--text-xs-size); color: var(--color-warning-text); background: var(--color-surface-sunken); border-radius: 8px; padding: 6px 10px; margin: 0 0 8px; }
.pd-prereq-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
.pd-prereq { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; padding: 8px 10px; border: 1px solid var(--color-border); border-radius: 10px; border-left: 3px solid var(--color-border-strong); }
.pd-prereq.st-done { border-left-color: var(--color-success-text); opacity: .7; }
.pd-prereq.st-in_progress { border-left-color: var(--color-brand-base); }
.pd-prereq.st-blocked { border-left-color: var(--color-danger-text); }
.pd-prereq-main { min-width: 0; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.pd-prereq-label { font-size: var(--text-sm-size); font-weight: 500; color: var(--color-text); }
.pd-prereq-tag { font-size: var(--text-xs-size); padding: 1px 7px; border-radius: 999px; background: var(--color-surface-sunken); color: var(--color-text-muted); }
.pd-prereq-tag.warn { color: var(--color-danger-text); }
.pd-prereq-tag.linked { color: var(--color-brand-on-surface); background: var(--color-brand-subtle); font-weight: 600; }
.pd-prereq-actions { display: flex; align-items: center; gap: 6px; flex: 0 0 auto; }
.pd-prereq-detail { flex-basis: 100%; font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 2px 0 0; }
.pd-prereq-status { width: auto; min-width: 8rem; flex: 0 0 auto; }
.pd-okr-toggle { display: flex; gap: 10px; align-items: flex-start; margin: 4px 0 8px; cursor: pointer; }
.pd-okr-toggle input { margin-top: 3px; }
.pd-okr-toggle span { font-size: var(--text-sm-size); color: var(--color-text); display: flex; flex-direction: column; }
.pd-okr-toggle small { font-size: var(--text-xs-size); color: var(--color-text-light); font-weight: 400; }
@media (max-width: 860px) {
  .pd-wk-cols { grid-template-columns: 1fr; }
  .pd-wk-banner { flex-direction: column; text-align: center; }
  .pd-wk-banner-top { justify-content: center; }
}
</style>
