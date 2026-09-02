/**
 * Which permission opens which part of the application.
 *
 * One map, consumed twice: the sidebar hides items whose permission the caller
 * lacks, and the router refuses the same routes on deep links. Writing it once
 * is what keeps the two from ever disagreeing — and the strings are the same
 * ones the API checks, so hiding a nav item, refusing its route and 403ing its
 * endpoint are a single rule.
 *
 * An entry lists ALTERNATIVES: holding any one of them opens the door. That is
 * how `view` and `view_own` coexist — a salesperson with `pipeline.view_own`
 * sees the Leads item and the list narrows itself server-side, exactly as it
 * already does.
 *
 * Routes with no entry are open to anyone signed in (My Info, Notifications —
 * the self-service surface).
 */
export const ROUTE_PERMISSIONS: Record<string, string[]> = {
  // ── People ────────────────────────────────────────────────────────────
  "hr-dashboard":       ["employees.view"],
  "employees":          ["employees.view", "employees.view_team"],
  "people-report":      ["employees.view"],
  "org":                ["organizations.view", "departments.view"],
  "org-chart":          ["employees.view", "employees.view_team"],
  "leave":              [], // everyone: the view itself narrows to own leave
  "attendance":         [], // same — self-service first, wider by permission
  "payroll":            ["payroll.view"],
  "performance":        ["performance.view", "performance.view_team", "performance.view_own"],
  "goals":              ["goals.view", "goals.view_own"],
  "okr-cycles":         ["okr.view"],
  "benefit-plans":      ["benefits.view"],
  "public-holidays":    ["leave.manage", "organizations.manage"],
  "roster":             ["roster.view"],
  "onboarding-queue":   ["onboarding.view", "onboarding.manage"],
  "training":           ["training.view", "training.view_own"],
  "training-approvals": ["training.approve"],
  "requisitions":       ["requisitions.view", "requisitions.create"],
  "vacancies":          ["vacancies.manage", "recruitment.view"],

  // ── Operations ────────────────────────────────────────────────────────
  "leads":              ["pipeline.view", "pipeline.view_team", "pipeline.view_own"],
  "opportunities":      ["pipeline.view", "pipeline.view_own"],
  "research":           ["pipeline.view", "pipeline.view_own"],
  "accounts":           ["accounts.view", "accounts.view_own"],
  "projects":           ["projects.view", "projects.view_own"],
  "projects-dashboard": ["projects.view"],
  "projects-pm-definitions": ["projects.view"],
  "tasks":              ["tasks.view", "tasks.view_own"],
  "support":            ["support.view", "support.view_own"],
  "assets":             ["assets.view", "assets.view_own"],
  "procurement":        ["procurement.approve_department", "procurement.approve_finance",
                         "procurement.approve_operations", "procurement.approve_executive",
                         "procurement.manage_settings"],
  "travel-requests":    ["travel.view", "travel.view_own", "travel.submit"],
  "deliveries":         ["travel.view", "travel.manage"],

  // ── Vendor management ─────────────────────────────────────────────────
  "vendors":            ["vendors.view"],
  "vendor-compliance":  ["vendors.view"],

  // ── Administration ────────────────────────────────────────────────────
  "role-assignments":   ["roles.view"],
  "user-directory":     ["roles.view"],
  "audit-log":          ["audit.view"],
  "licence-statistics": ["integrations.view"],
  "integrations-connections": ["integrations.view", "integrations.manage"],
};

/** May the caller open this route? Unlisted routes are open to anyone signed in. */
export function routeAllowed(
  routeName: string | null | undefined,
  can: (p: string) => boolean,
): boolean {
  if (!routeName) return true;

  const required = ROUTE_PERMISSIONS[routeName];
  if (!required || required.length === 0) return true;

  return required.some(can);
}
