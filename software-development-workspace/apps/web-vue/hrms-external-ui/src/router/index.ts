import { createRouter, createWebHashHistory, type RouteRecordRaw } from "vue-router";
import { isConfigured, isCompanyHR, isManager } from "../stores/auth";

import DashboardView from "../views/DashboardView.vue";
import LoginView from "../views/LoginView.vue";
import PeopleDashboardView from "../views/PeopleDashboardView.vue";
import PeopleView from "../views/PeopleView.vue";
import PeopleDetailView from "../views/PeopleDetailView.vue";
import OrgView from "../views/OrgView.vue";
import LeaveView from "../views/LeaveView.vue";
import PayrollView from "../views/PayrollView.vue";
import AdminView from "../views/AdminView.vue";

const routes: RouteRecordRaw[] = [
  {
    path: "/",
    redirect: "/dashboard",
  },
  {
    path: "/login",
    name: "login",
    component: LoginView,
    meta: { public: true },
  },
  {
    path: "/dashboard",
    name: "dashboard",
    component: DashboardView,
  },
  {
    path: "/people/dashboard",
    name: "people-dashboard",
    component: PeopleDashboardView,
    meta: { requiresManager: true },
  },
  {
    path: "/people",
    name: "people",
    component: PeopleView,
    meta: { requiresManager: true },
  },
  {
    path: "/people/:id",
    name: "people-detail",
    component: PeopleDetailView,
    props: true,
    meta: { requiresManager: true },
  },
  {
    path: "/org",
    name: "org",
    component: OrgView,
    meta: { requiresCompanyHR: true },
  },
  {
    path: "/leave",
    name: "leave",
    component: LeaveView,
  },
  {
    path: "/payroll",
    name: "payroll",
    component: PayrollView,
    meta: { requiresCompanyHR: true },
  },
  {
    path: "/admin",
    name: "admin",
    component: AdminView,
    meta: { requiresCompanyHR: true },
  },
];

const router = createRouter({
  history: createWebHashHistory(),
  routes,
});

router.beforeEach((to) => {
  if (to.meta.public) return true;

  if (!isConfigured.value) {
    return { name: "login" };
  }

  if (to.meta.requiresCompanyHR && !isCompanyHR.value) {
    return { name: "dashboard" };
  }

  if (to.meta.requiresManager && !isManager.value) {
    return { name: "leave" };
  }

  return true;
});

export default router;
