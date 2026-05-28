import { createRouter, createWebHashHistory, type RouteRecordRaw } from "vue-router";
import { isAuthenticated } from "../stores/auth";

import LoginView           from "../views/LoginView.vue";
import DashboardView       from "../views/DashboardView.vue";
import HRDashboardView     from "../views/HRDashboardView.vue";
import EmployeesView       from "../views/EmployeesView.vue";
import EmployeeDetailView  from "../views/EmployeeDetailView.vue";
import EmployeeDependentsView from "../views/EmployeeDependentsView.vue";
import EmployeeVehiclesView   from "../views/EmployeeVehiclesView.vue";
import OnboardingView      from "../views/OnboardingView.vue";
import LeaveView           from "../views/LeaveView.vue";
import LeaveApprovalsView  from "../views/LeaveApprovalsView.vue";
import LeaveAdminView      from "../views/LeaveAdminView.vue";
import PayrollView         from "../views/PayrollView.vue";
import OrgView             from "../views/OrgView.vue";
import OrgProfileView      from "../views/OrgProfileView.vue";
import PerformanceView     from "../views/PerformanceView.vue";
import GoalsView           from "../views/GoalsView.vue";
import BenefitPlansView   from "../views/BenefitPlansView.vue";

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

const routes: RouteRecordRaw[] = [
  { path: "/", redirect: "/hr-dashboard" },
  { path: "/login",           name: "login",           component: LoginView,          meta: { public: true } },
  { path: "/dashboard",       name: "dashboard",        component: DashboardView },
  { path: "/hr-dashboard",    name: "hr-dashboard",     component: HRDashboardView },
  { path: "/employees",       name: "employees",        component: EmployeesView },
  { path: "/employees/onboard",name: "onboard",          component: OnboardingView },
  { path: "/employees/:id/edit",name: "employee-edit",   component: OnboardingView, props: true },
  { path: "/employees/:id/dependents", name: "employee-dependents", component: EmployeeDependentsView, props: true },
  { path: "/employees/:id/vehicles",   name: "employee-vehicles",   component: EmployeeVehiclesView,   props: true },
  { path: "/employees/:id",   name: "employee-detail",  component: EmployeeDetailView, props: true },
  { path: "/leave",           name: "leave",            component: LeaveView },
  { path: "/leave/approvals", name: "leave-approvals",  component: LeaveApprovalsView },
  { path: "/leave/admin",     name: "leave-admin",      component: LeaveAdminView },
  { path: "/payroll",         name: "payroll",          component: PayrollView },
  { path: "/org",             name: "org",              component: OrgView },
  { path: "/org/profile/:slug", name: "org-profile",    component: OrgProfileView, props: true },
  { path: "/org/benefit-plans",name: "benefit-plans",   component: BenefitPlansView },
  { path: "/performance",     name: "performance",      component: PerformanceView },
  { path: "/goals",           name: "goals",            component: GoalsView },
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
  { path: "/preboarding",      name: "preboarding",        component: PreboardingListView },
  { path: "/preboarding/:id",  name: "preboarding-detail", component: PreboardingDetailView, props: true },
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
