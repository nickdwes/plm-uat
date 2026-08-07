import { createRouter, createWebHashHistory, type RouteRecordRaw } from "vue-router";
import { isAuthenticated } from "../stores/auth";

import LoginView           from "../views/LoginView.vue";
import DashboardView       from "../views/DashboardView.vue";
import HRDashboardView     from "../views/HRDashboardView.vue";
import EmployeesView       from "../views/EmployeesView.vue";
import EmployeeDetailView  from "../views/EmployeeDetailView.vue";
import EmployeeDependentsView from "../views/EmployeeDependentsView.vue";
import EmployeeVehiclesView   from "../views/EmployeeVehiclesView.vue";
import MyInfoView          from "../views/MyInfoView.vue";
import OnboardingView      from "../views/OnboardingView.vue";
import LeaveView           from "../views/LeaveView.vue";
import LeaveApprovalsView  from "../views/LeaveApprovalsView.vue";
import LeaveAdminView      from "../views/LeaveAdminView.vue";
import PayrollView         from "../views/PayrollView.vue";
import PayrollConfigView   from "../views/PayrollConfigView.vue";
import OrgView             from "../views/OrgView.vue";
import OrgProfileView      from "../views/OrgProfileView.vue";
import PerformanceView     from "../views/PerformanceView.vue";
import GoalsView           from "../views/GoalsView.vue";
import BenefitPlansView    from "../views/BenefitPlansView.vue";
import PublicHolidaysView  from "../views/PublicHolidaysView.vue";
import OkrCyclesView       from "../views/OkrCyclesView.vue";
import RoleAssignmentsView from "../views/RoleAssignmentsView.vue";

// Assets
import TravelSettingsView from "../views/Travel/TravelSettingsView.vue";
import TravelRequestsView from "../views/Travel/TravelRequestsView.vue";
import TravelRequestWizard from "../views/Travel/TravelRequestWizard.vue";
import TravelRequestDetailView from "../views/Travel/TravelRequestDetailView.vue";
import DeliveriesView from "../views/Travel/DeliveriesView.vue";
import DeliveryDetailView from "../views/Travel/DeliveryDetailView.vue";
import DeliveryWizard from "../views/Travel/DeliveryWizard.vue";
import AssetsView          from "../views/Asset/AssetsView.vue";
import AssetDetailView     from "../views/Asset/AssetDetailView.vue";

// Recruitment
import RecruitmentView      from "../views/Recruitment/RecruitmentView.vue";
import VacanciesView        from "../views/Recruitment/VacanciesView.vue";
import VacancyDetailView    from "../views/Recruitment/VacancyDetailView.vue";
import VacancyWizardView    from "../views/Recruitment/VacancyWizardView.vue";
import CandidatesView       from "../views/Recruitment/CandidatesView.vue";
import CandidateDetailView  from "../views/Recruitment/CandidateDetailView.vue";
import TemplatesView        from "../views/Recruitment/TemplatesView.vue";
import WorkflowEditorView   from "../views/Recruitment/WorkflowEditorView.vue";
import InterviewScorecardView from "../views/Recruitment/InterviewScorecardView.vue";

// Roster
import RosterView             from "../views/RosterView.vue";

// Preboarding
import PreboardingListView   from "../views/PreboardingListView.vue";
import PreboardingDetailView from "../views/PreboardingDetailView.vue";
import PreboardingFormView   from "../views/PreboardingFormView.vue";
import InterviewScoringFormView from "../views/Recruitment/InterviewScoringFormView.vue";

// SSO
import SsoCallbackView from "../views/SsoCallbackView.vue";

// Training
import TrainingDashboardView  from "../views/Training/TrainingDashboardView.vue";
import TrainingCatalogView    from "../views/Training/TrainingCatalogView.vue";
import TrainingItemDetailView from "../views/Training/TrainingItemDetailView.vue";
import MyTrainingView         from "../views/Training/MyTrainingView.vue";
import CompetencyFrameworkView    from "../views/Training/CompetencyFrameworkView.vue";
import RoleProfileEditorView      from "../views/Training/RoleProfileEditorView.vue";
import TrainingRecordDetailView   from "../views/Training/TrainingRecordDetailView.vue";
import EnrollmentManagerView      from "../views/Training/EnrollmentManagerView.vue";
import ManagerTrainingApprovalsView from "../views/Training/ManagerTrainingApprovalsView.vue";
import VendorComplianceView          from "../views/Training/VendorComplianceView.vue";

// Accounts (customer master)
import AccountsView                  from "../views/Account/AccountsView.vue";
import AccountDetailView             from "../views/Account/AccountDetailView.vue";

// Pipeline (leads and opportunities)
import LeadsView                     from "../views/Pipeline/LeadsView.vue";
import LeadDetailView                from "../views/Pipeline/LeadDetailView.vue";
import OpportunitiesView             from "../views/Pipeline/OpportunitiesView.vue";
import ResearchView                   from "../views/Pipeline/ResearchView.vue";
import ResearchDetailView             from "../views/Pipeline/ResearchDetailView.vue";

// Projects
import ProjectsView                   from "../views/Project/ProjectsView.vue";
import ProjectDetailView              from "../views/Project/ProjectDetailView.vue";
import OpportunityDetailView         from "../views/Pipeline/OpportunityDetailView.vue";

// Vendor Module
import VendorsView                   from "../views/Vendor/VendorsView.vue";
import VendorProfileView             from "../views/Vendor/VendorProfileView.vue";

// Notifications
import NotificationsView from "../views/NotificationsView.vue";

const routes: RouteRecordRaw[] = [
  { path: "/",           redirect: "/hr-dashboard" },
  { path: "/login",      name: "login",        component: LoginView,       meta: { public: true } },
  { path: "/sso/callback", name: "sso-callback", component: SsoCallbackView, meta: { public: true } },
  { path: "/dashboard",       name: "dashboard",        component: DashboardView },
  { path: "/hr-dashboard",    name: "hr-dashboard",     component: HRDashboardView },
  { path: "/notifications",   name: "notifications",    component: NotificationsView },
  { path: "/employees",       name: "employees",        component: EmployeesView },
  { path: "/employees/onboard",name: "onboard",          component: OnboardingView },
  { path: "/employees/:id/edit",name: "employee-edit",   component: OnboardingView, props: true },
  { path: "/employees/:id/dependents", name: "employee-dependents", component: EmployeeDependentsView, props: true },
  { path: "/employees/:id/vehicles",   name: "employee-vehicles",   component: EmployeeVehiclesView,   props: true },
  { path: "/employees/:id",   name: "employee-detail",  component: EmployeeDetailView, props: true },
  { path: "/my-info",         name: "my-info",          component: MyInfoView },
  { path: "/leave",           name: "leave",            component: LeaveView },
  { path: "/leave/approvals", name: "leave-approvals",  component: LeaveApprovalsView },
  { path: "/leave/admin",     name: "leave-admin",      component: LeaveAdminView },
  { path: "/payroll",         name: "payroll",          component: PayrollView },
  { path: "/org",             name: "org",              component: OrgView },
  { path: "/org/profile/:slug", name: "org-profile",    component: OrgProfileView, props: true },
  { path: "/org/benefit-plans",    name: "benefit-plans",    component: BenefitPlansView },
  { path: "/org/public-holidays",  name: "public-holidays",  component: PublicHolidaysView },
  { path: "/org/payroll-config",   name: "payroll-config",   component: PayrollConfigView },
  { path: "/org/role-assignments", name: "role-assignments", component: RoleAssignmentsView },
  // ── Operations ───────────────────────────────────────────────────────────
  { path: "/travel",          name: "travel-requests",   component: TravelRequestsView },
  { path: "/travel/new",      name: "travel-request-new", component: TravelRequestWizard },
  { path: "/travel/:id",      name: "travel-request",     component: TravelRequestDetailView, props: true },
  { path: "/deliveries",      name: "deliveries",        component: DeliveriesView },
  { path: "/deliveries/new",  name: "delivery-new",      component: DeliveryWizard },
  { path: "/deliveries/:id",  name: "delivery",          component: DeliveryDetailView, props: true },
  { path: "/travel/settings", name: "travel-settings",   component: TravelSettingsView },
  { path: "/assets",          name: "assets",        component: AssetsView },
  { path: "/assets/:id",      name: "asset-detail",  component: AssetDetailView, props: true },
  { path: "/performance",          name: "performance",      component: PerformanceView },
  { path: "/goals",                name: "goals",            component: GoalsView },
  { path: "/okr/cycles",           name: "okr-cycles",       component: OkrCyclesView },
  // ── Recruitment ──────────────────────────────────────────────────────────
  { path: "/recruitment",                   name: "recruitment",      component: RecruitmentView },
  { path: "/recruitment/vacancies",         name: "vacancies",        component: VacanciesView },
  { path: "/recruitment/vacancies/new",     name: "vacancy-new",      component: VacancyWizardView },
  { path: "/recruitment/vacancies/:id",     name: "vacancy-detail",   component: VacancyDetailView, props: true },
  { path: "/recruitment/candidates",        name: "candidates",       component: CandidatesView },
  { path: "/recruitment/candidates/:id",    name: "candidate-detail", component: CandidateDetailView, props: true },
  { path: "/recruitment/templates",         name: "rec-templates",    component: TemplatesView },
  { path: "/recruitment/workflows",         name: "rec-workflows",    component: WorkflowEditorView },
  { path: "/recruitment/interviews/:id/scorecard", name: "interview-scorecard", component: InterviewScorecardView, props: true },
  // ── Roster ───────────────────────────────────────────────────────────────
  { path: "/roster",  name: "roster",  component: RosterView },
  // ── Pre-Onboarding ────────────────────────────────────────────────────────
  { path: "/preboarding",                    name: "preboarding",        component: PreboardingListView },
  { path: "/preboarding/:id",                name: "preboarding-detail", component: PreboardingDetailView, props: true },
  { path: "/pre-onboarding/form/:token",     name: "preboarding-form",   component: PreboardingFormView,   props: true, meta: { public: true } },
  { path: "/score/:token",                   name: "interview-scoring",  component: InterviewScoringFormView, props: true, meta: { public: true } },
  // ── Training & Competency ──────────────────────────────────────────────────
  { path: "/training",                name: "training",                component: TrainingDashboardView },
  { path: "/training/catalog",        name: "training-catalog",       component: TrainingCatalogView },
  { path: "/training/catalog/:id",    name: "training-item-detail",   component: TrainingItemDetailView, props: true },
  { path: "/training/my",             name: "my-training",            component: MyTrainingView },
  { path: "/training/competencies",       name: "competencies",           component: CompetencyFrameworkView },
  { path: "/training/role-profiles",      name: "role-profiles",          component: RoleProfileEditorView },
  { path: "/training/records/:id",         name: "training-record-detail",  component: TrainingRecordDetailView, props: true },
  { path: "/training/enroll",             name: "training-enroll",         component: EnrollmentManagerView },
  { path: "/training/approvals",          name: "training-approvals",       component: ManagerTrainingApprovalsView },
  { path: "/training/vendor-compliance",  name: "vendor-compliance",        component: VendorComplianceView },
  // ── Accounts (customer master) ────────────────────────────────────────────
  { path: "/accounts",      name: "accounts",       component: AccountsView },
  { path: "/accounts/:id",  name: "account",        component: AccountDetailView, props: true },

  // ── Pipeline ──────────────────────────────────────────────────────────────
  // Reached from the customer record's Leads and Opportunities tabs.
  { path: "/leads",             name: "leads",       component: LeadsView },
  { path: "/leads/:id",         name: "lead",        component: LeadDetailView,        props: true },
  { path: "/opportunities",     name: "opportunities", component: OpportunitiesView },
  { path: "/opportunities/:id", name: "opportunity", component: OpportunityDetailView, props: true },
  { path: "/research",          name: "research",        component: ResearchView },
  { path: "/research/:id",      name: "research-detail", component: ResearchDetailView, props: true },
  { path: "/projects",          name: "projects",        component: ProjectsView },
  { path: "/projects/:id",      name: "project",         component: ProjectDetailView, props: true },

  // ── Vendor Module ─────────────────────────────────────────────────────────
  { path: "/vendors",       name: "vendors",        component: VendorsView },
  { path: "/vendors/:id",   name: "vendor-profile", component: VendorProfileView, props: true },
];

const router = createRouter({
  history: createWebHashHistory(),
  routes,
});

router.beforeEach((to) => {
  if (to.meta.public) return true;
  if (!isAuthenticated.value) return { name: "login" };
  return true;
});

export default router;
