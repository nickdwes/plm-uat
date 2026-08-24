import { createRouter, createWebHashHistory, type RouteRecordRaw } from "vue-router";
import { isAuthenticated } from "../stores/auth";

import LoginView           from "../views/LoginView.vue";
import ForgotPasswordView  from "../views/ForgotPasswordView.vue";
import ResetPasswordView   from "../views/ResetPasswordView.vue";
import DashboardView       from "../views/DashboardView.vue";
import HRDashboardView     from "../views/HRDashboardView.vue";
import EmployeesView       from "../views/EmployeesView.vue";
import EmployeeDetailView  from "../views/EmployeeDetailView.vue";
import PeopleReportView from "../views/PeopleReportView.vue";
import EmployeeImportView from "../views/EmployeeImportView.vue";
import EmployeeDependentsView from "../views/EmployeeDependentsView.vue";
import MyInfoView          from "../views/MyInfoView.vue";
import OnboardingView      from "../views/OnboardingView.vue";
import LeaveView           from "../views/LeaveView.vue";
import LeaveAdminView      from "../views/LeaveAdminView.vue";
import PayrollView         from "../views/PayrollView.vue";
import PayrollConfigView   from "../views/PayrollConfigView.vue";
import SendPayslipsView    from "../views/Payroll/SendPayslipsView.vue";
import SendP9View          from "../views/Payroll/SendP9View.vue";
import SupportView         from "../views/SupportView.vue";
import OrgChartView        from "../views/OrgChartView.vue";
import PortalView          from "../views/PortalView.vue";
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
import TalentRequisitionsView          from "../views/Recruitment/TalentRequisitionsView.vue";
import TalentRequisitionDetailView     from "../views/Recruitment/TalentRequisitionDetailView.vue";
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
import OnboardingQueueView            from "../views/HR/OnboardingQueueView.vue";
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
import TasksView                       from "../views/Task/TasksView.vue";
import AttendanceView                  from "../views/Attendance/AttendanceView.vue";
import AttendanceReportView            from "../views/Attendance/AttendanceReportView.vue";
import TaskDetailView                  from "../views/Task/TaskDetailView.vue";
import OpportunityDetailView         from "../views/Pipeline/OpportunityDetailView.vue";

// Vendor Module
import VendorsView                   from "../views/Vendor/VendorsView.vue";
import VendorProfileView             from "../views/Vendor/VendorProfileView.vue";

// Notifications
import NotificationsView from "../views/NotificationsView.vue";

// Integrations — where the register reads other systems from
import ConnectionsView from "../views/Integration/ConnectionsView.vue";
import LicenceStatisticsView from "../views/Integration/LicenceStatisticsView.vue";

const routes: RouteRecordRaw[] = [
  { path: "/",           redirect: "/hr-dashboard" },
  { path: "/login",      name: "login",        component: LoginView,       meta: { public: true } },
  { path: "/sso/callback", name: "sso-callback", component: SsoCallbackView, meta: { public: true } },

  /*
   * Password recovery. Public, because the whole point is that the person
   * cannot sign in — the guard below would otherwise bounce them to the login
   * screen they are stuck on.
   *
   * The token is a path segment and the address a query parameter because that
   * is the shape the backend builds into the email; changing either here
   * silently breaks every link already sent.
   */
  /*
   * The customer portal. Public by construction — access is the token in the
   * link, and these pages deliberately render outside the staff shell.
   */
  { path: "/portal/ticket",    name: "portal-ticket",    component: PortalView, meta: { public: true } },
  { path: "/portal/tickets",   name: "portal-tickets",   component: PortalView, meta: { public: true } },
  { path: "/portal/catalogue", name: "portal-catalogue", component: PortalView, meta: { public: true } },
  { path: "/portal/survey",    name: "portal-survey",    component: PortalView, meta: { public: true } },
  { path: "/forgot-password", name: "forgot-password", component: ForgotPasswordView, meta: { public: true } },
  { path: "/reset-password/:token", name: "reset-password", component: ResetPasswordView, meta: { public: true } },
  { path: "/dashboard",       name: "dashboard",        component: DashboardView },
  { path: "/hr-dashboard",    name: "hr-dashboard",     component: HRDashboardView },
  { path: "/notifications",   name: "notifications",    component: NotificationsView },
  { path: "/integrations/connections", name: "integration-connections", component: ConnectionsView },
  { path: "/integrations/licences",    name: "licence-statistics",     component: LicenceStatisticsView },
  { path: "/employees",       name: "employees",        component: EmployeesView },
  { path: "/employees/onboard",name: "onboard",          component: OnboardingView },
  { path: "/employees/:id/edit",name: "employee-edit",   component: OnboardingView, props: true },
  /* Reports read the same population the directory does; kept on its own
     route because a report is a different thing from a record. */
  { path: "/reports/people", name: "people-report", component: PeopleReportView },
  { path: "/employees/import", name: "employee-import", component: EmployeeImportView },
  { path: "/employees/:id/dependents", name: "employee-dependents", component: EmployeeDependentsView, props: true },
  /*
   * Vehicles moved onto the profile itself as its own tab — it used to be a
   * separate page with none of that person's photo, status or other tabs in
   * view, which made editing one feel like leaving the record entirely. Kept
   * as a redirect rather than dropped outright: the path may be bookmarked.
   */
  { path: "/employees/:id/vehicles",   name: "employee-vehicles",
    redirect: to => ({ name: "employee-detail", params: to.params }) },
  { path: "/employees/:id",   name: "employee-detail",  component: EmployeeDetailView, props: true },
  { path: "/my-info",         name: "my-info",          component: MyInfoView },
  { path: "/leave",           name: "leave",            component: LeaveView },
  /*
   * Approvals folded into the leave page's second tab. Kept as a redirect
   * rather than removed: the path is in people's bookmarks and in older
   * notification emails, and a dead link there reads as the feature being
   * gone rather than moved.
   */
  { path: "/leave/approvals", name: "leave-approvals", redirect: { name: "leave" } },
  { path: "/leave/admin",     name: "leave-admin",      component: LeaveAdminView },
  { path: "/payroll",         name: "payroll",          component: PayrollView },
  { path: "/payroll/:id/send", name: "payroll-send",    component: SendPayslipsView, props: true },
  { path: "/payroll/p9/:year/send", name: "payroll-p9-send", component: SendP9View, props: true },
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
  { path: "/support",         name: "support",       component: SupportView },
  { path: "/org-chart",       name: "org-chart",     component: OrgChartView },
  { path: "/assets",          name: "assets",        component: AssetsView },
  { path: "/assets/:id",      name: "asset-detail",  component: AssetDetailView, props: true },
  { path: "/performance",          name: "performance",      component: PerformanceView },
  { path: "/goals",                name: "goals",            component: GoalsView },
  { path: "/okr/cycles",           name: "okr-cycles",       component: OkrCyclesView },
  // ── Recruitment ──────────────────────────────────────────────────────────
  /*
   * Talent Requisition is now where the journey starts — a vacancy is what an
   * approved requisition becomes. The old recruitment landing stays reachable
   * for the parts that have not moved yet.
   */
  { path: "/requisitions",      name: "requisitions",      component: TalentRequisitionsView },
  { path: "/requisitions/:id",  name: "requisition",       component: TalentRequisitionDetailView, props: true },
  { path: "/requisitions-inbox", name: "requisition-inbox", component: TalentRequisitionsView },

  { path: "/recruitment",                   name: "recruitment",      component: RecruitmentView },
  /*
   * Job openings are their own page, not a card under Recruitment.
   *
   * A vacancy carries the hiring manager, the pipeline, the application form
   * and the screening rules — it is where the hiring is actually run, and
   * reaching it through a landing grid made it look like a sub-feature of
   * something else.
   */
  { path: "/vacancies",         name: "vacancies",      component: VacanciesView },
  { path: "/vacancies/new",     name: "vacancy-new",    component: VacancyWizardView },
  { path: "/vacancies/:id",     name: "vacancy-detail", component: VacancyDetailView, props: true },
  { path: "/recruitment/candidates",        name: "candidates",       component: CandidatesView },
  { path: "/recruitment/candidates/:id",    name: "candidate-detail", component: CandidateDetailView, props: true },
  { path: "/recruitment/templates",         name: "rec-templates",    component: TemplatesView },
  { path: "/recruitment/workflows",         name: "rec-workflows",    component: WorkflowEditorView },
  { path: "/recruitment/interviews/:id/scorecard", name: "interview-scorecard", component: InterviewScorecardView, props: true },
  // ── Roster ───────────────────────────────────────────────────────────────
  { path: "/roster",  name: "roster",  component: RosterView },
  // ── Onboarding ────────────────────────────────────────────────────────────
  /*
   * One queue between an accepted offer and a first day.
   *
   * There were two lists reading the same `/preboarding` records and a third
   * nav entry pointing at the wizard, which meant three answers to "where do I
   * onboard somebody". The older list is gone; its path redirects so existing
   * links and bookmarks still land somewhere sensible.
   */
  { path: "/onboarding",                     name: "onboarding-queue",   component: OnboardingQueueView },
  { path: "/preboarding",                    redirect: { name: "onboarding-queue" } },
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

  // ── Tasks ─────────────────────────────────────────────────────────────────
  // Not under /projects: three quarters of the legacy's 9,055 tasks had no
  // project at all, so this is its own module rather than a project sub-screen.
  // The detail is a child, so it renders as a panel over the list rather than
  // a page away from it — you answer one task and carry on down the column.
  {
    path: "/tasks", name: "tasks", component: TasksView,
    children: [
      { path: ":id", name: "task", component: TaskDetailView, props: true },
    ],
  },

  // ── Attendance ────────────────────────────────────────────────────────────
  // Where people work, and whether the day happened.
  { path: "/attendance", name: "attendance", component: AttendanceView },
  { path: "/attendance/report", name: "attendance-report", component: AttendanceReportView },

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
