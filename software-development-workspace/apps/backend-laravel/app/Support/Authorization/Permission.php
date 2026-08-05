<?php

namespace App\Support\Authorization;

/**
 * Canonical permission catalogue.
 *
 * Permissions are `resource.action` strings stored on `roles.permissions`.
 * A role may hold a wildcard (`employees.*`) which grants every action on
 * that resource. `*` alone grants everything.
 *
 * Every permission the application checks MUST appear in ALL. Free-form
 * strings are how authorization silently fails open: a typo in a check
 * reads as "no such permission", which — depending on how the caller
 * treats it — can grant rather than deny. `assertKnown()` turns that into
 * a loud failure in non-production environments.
 *
 * Naming: resource is plural and matches the domain concept
 * (`employees`, not `employee`); action is a verb. `*_own` and `*_team`
 * suffixes mark permissions whose scope is narrowed further by ABAC —
 * holding `leave.view_own` grants nothing on its own until the ownership
 * check in AuthorizesResources passes.
 */
final class Permission
{
    // ── Employees ─────────────────────────────────────────────────────────
    public const EMPLOYEES_VIEW      = 'employees.view';
    public const EMPLOYEES_VIEW_OWN  = 'employees.view_own';
    public const EMPLOYEES_VIEW_TEAM = 'employees.view_team';
    public const EMPLOYEES_CREATE    = 'employees.create';
    public const EMPLOYEES_UPDATE    = 'employees.update';
    public const EMPLOYEES_DELETE    = 'employees.delete';
    public const EMPLOYEES_EXIT      = 'employees.exit';

    // ── Leave ─────────────────────────────────────────────────────────────
    public const LEAVE_VIEW       = 'leave.view';
    public const LEAVE_VIEW_OWN   = 'leave.view_own';
    public const LEAVE_VIEW_TEAM  = 'leave.view_team';
    public const LEAVE_SUBMIT     = 'leave.submit';
    public const LEAVE_APPROVE    = 'leave.approve';
    public const LEAVE_REJECT     = 'leave.reject';
    public const LEAVE_CANCEL_OWN = 'leave.cancel_own';
    public const LEAVE_MANAGE     = 'leave.manage';

    // ── Payroll ───────────────────────────────────────────────────────────
    public const PAYROLL_VIEW    = 'payroll.view';
    public const PAYROLL_CREATE  = 'payroll.create';
    public const PAYROLL_PROCESS = 'payroll.process';
    public const PAYROLL_APPROVE = 'payroll.approve';
    /** Earning and deduction codes — operational payroll setup. */
    public const PAYROLL_MANAGE_CONFIG = 'payroll.manage_config';
    /**
     * PAYE bands and statutory rates. Separate from manage_config because these
     * are set by legislation, determine every employee's net pay, and are
     * rewritten a whole tax year at a time.
     */
    public const PAYROLL_MANAGE_STATUTORY = 'payroll.manage_statutory';
    public const PAYSLIP_VIEW_OWN = 'payslip.view_own';

    // ── Organization & departments ────────────────────────────────────────
    public const ORGANIZATIONS_VIEW   = 'organizations.view';
    public const ORGANIZATIONS_MANAGE = 'organizations.manage';
    public const DEPARTMENTS_VIEW     = 'departments.view';
    public const DEPARTMENTS_MANAGE   = 'departments.manage';

    // ── Recruitment ───────────────────────────────────────────────────────
    public const RECRUITMENT_VIEW    = 'recruitment.view';
    public const RECRUITMENT_MANAGE  = 'recruitment.manage';
    public const VACANCIES_MANAGE    = 'vacancies.manage';
    public const CANDIDATES_VIEW     = 'candidates.view';
    public const APPLICATIONS_MANAGE = 'applications.manage';

    // ── Training ──────────────────────────────────────────────────────────
    public const TRAINING_VIEW     = 'training.view';
    public const TRAINING_VIEW_OWN = 'training.view_own';
    public const TRAINING_MANAGE   = 'training.manage';
    public const TRAINING_APPROVE  = 'training.approve';

    // ── Onboarding / preboarding ──────────────────────────────────────────
    public const ONBOARDING_VIEW   = 'onboarding.view';
    public const ONBOARDING_MANAGE = 'onboarding.manage';

    // ── Reporting & audit ─────────────────────────────────────────────────
    public const REPORTS_ORG  = 'reports.org';
    public const REPORTS_TEAM = 'reports.team';
    public const AUDIT_VIEW   = 'audit.view';
    public const SETTINGS_MANAGE = 'settings.manage';

    // ── Authorization administration ──────────────────────────────────────
    //
    // Deliberately three permissions, not one. Reading who holds what is a
    // routine audit question; defining a role is a change to the rules; and
    // granting one is a change to who is bound by them. Collapsing them means
    // anyone who may review access may also widen it.
    public const ROLES_VIEW   = 'roles.view';
    public const ROLES_MANAGE = 'roles.manage';
    public const ROLES_ASSIGN = 'roles.assign';

    // ── Performance reviews ───────────────────────────────────────────────
    public const PERFORMANCE_VIEW      = 'performance.view';
    public const PERFORMANCE_VIEW_OWN  = 'performance.view_own';
    public const PERFORMANCE_VIEW_TEAM = 'performance.view_team';
    public const PERFORMANCE_MANAGE    = 'performance.manage';

    // ── Goals ─────────────────────────────────────────────────────────────
    public const GOALS_VIEW     = 'goals.view';
    public const GOALS_VIEW_OWN = 'goals.view_own';
    public const GOALS_CREATE   = 'goals.create';
    public const GOALS_UPDATE   = 'goals.update';
    public const GOALS_DELETE   = 'goals.delete';

    // ── OKR ───────────────────────────────────────────────────────────────
    public const OKR_VIEW   = 'okr.view';
    public const OKR_MANAGE = 'okr.manage';
    /** Recording progress against a key result, distinct from redefining it. */
    public const OKR_SCORE  = 'okr.score';

    // ── Projects ──────────────────────────────────────────────────────────
    public const PROJECTS_VIEW      = 'projects.view';
    public const PROJECTS_VIEW_OWN  = 'projects.view_own';
    public const PROJECTS_CREATE    = 'projects.create';
    public const PROJECTS_UPDATE    = 'projects.update';
    public const PROJECTS_DELETE    = 'projects.delete';
    public const PROJECTS_ASSIGN    = 'projects.assign';

    // ── Tasks ─────────────────────────────────────────────────────────────
    public const TASKS_VIEW     = 'tasks.view';
    public const TASKS_VIEW_OWN = 'tasks.view_own';
    public const TASKS_CREATE   = 'tasks.create';
    public const TASKS_UPDATE   = 'tasks.update';
    public const TASKS_DELETE   = 'tasks.delete';
    public const TASKS_ASSIGN   = 'tasks.assign';
    public const TASKS_COMMENT  = 'tasks.comment';

    // ── Assets ────────────────────────────────────────────────────────────
    public const ASSETS_VIEW          = 'assets.view';
    public const ASSETS_VIEW_OWN      = 'assets.view_own';
    public const ASSETS_CREATE        = 'assets.create';
    public const ASSETS_UPDATE        = 'assets.update';
    public const ASSETS_DELETE        = 'assets.delete';
    public const ASSETS_ASSIGN        = 'assets.assign';
    public const ASSETS_REQUEST       = 'assets.request';
    public const ASSETS_APPROVE       = 'assets.approve';
    public const ASSETS_REPORT_ISSUE  = 'assets.report_issue';
    public const ASSETS_RESOLVE_ISSUE = 'assets.resolve_issue';

    // ── Accounts (customer master) ────────────────────────────────────────
    public const ACCOUNTS_VIEW       = 'accounts.view';
    /** An account manager seeing only the customers assigned to them. */
    public const ACCOUNTS_VIEW_OWN   = 'accounts.view_own';
    public const ACCOUNTS_CREATE     = 'accounts.create';
    public const ACCOUNTS_UPDATE     = 'accounts.update';
    public const ACCOUNTS_DELETE     = 'accounts.delete';
    /** Reassigning the account manager is a commercial decision, not an edit. */
    public const ACCOUNTS_ASSIGN     = 'accounts.assign';
    /** Types, sectors and sub-sectors — the axes customers are reported on. */
    public const ACCOUNTS_MANAGE_REF = 'accounts.manage_reference_data';

    // ── Travel ────────────────────────────────────────────────────────────
    public const TRAVEL_VIEW      = 'travel.view';
    public const TRAVEL_VIEW_OWN  = 'travel.view_own';
    public const TRAVEL_VIEW_TEAM = 'travel.view_team';
    public const TRAVEL_SUBMIT    = 'travel.submit';
    public const TRAVEL_APPROVE   = 'travel.approve';
    public const TRAVEL_REJECT    = 'travel.reject';
    public const TRAVEL_MANAGE    = 'travel.manage';
    /** Reserved for the finance stage added in the travel second pass. */
    public const TRAVEL_RECONCILE = 'travel.reconcile';

    /**
     * Every permission the application recognises, grouped by resource.
     *
     * @return array<string, string[]>
     */
    public static function grouped(): array
    {
        return [
            'accounts'      => [
                self::ACCOUNTS_VIEW, self::ACCOUNTS_VIEW_OWN, self::ACCOUNTS_CREATE,
                self::ACCOUNTS_UPDATE, self::ACCOUNTS_DELETE, self::ACCOUNTS_ASSIGN,
                self::ACCOUNTS_MANAGE_REF,
            ],
            'employees'     => [
                self::EMPLOYEES_VIEW, self::EMPLOYEES_VIEW_OWN, self::EMPLOYEES_VIEW_TEAM,
                self::EMPLOYEES_CREATE, self::EMPLOYEES_UPDATE, self::EMPLOYEES_DELETE,
                self::EMPLOYEES_EXIT,
            ],
            'leave'         => [
                self::LEAVE_VIEW, self::LEAVE_VIEW_OWN, self::LEAVE_VIEW_TEAM,
                self::LEAVE_SUBMIT, self::LEAVE_APPROVE, self::LEAVE_REJECT,
                self::LEAVE_CANCEL_OWN, self::LEAVE_MANAGE,
            ],
            'payroll'       => [
                self::PAYROLL_VIEW, self::PAYROLL_CREATE, self::PAYROLL_PROCESS,
                self::PAYROLL_APPROVE,
                self::PAYROLL_MANAGE_CONFIG, self::PAYROLL_MANAGE_STATUTORY,
            ],
            'payslip'       => [self::PAYSLIP_VIEW_OWN],
            'organizations' => [self::ORGANIZATIONS_VIEW, self::ORGANIZATIONS_MANAGE],
            'departments'   => [self::DEPARTMENTS_VIEW, self::DEPARTMENTS_MANAGE],
            'recruitment'   => [self::RECRUITMENT_VIEW, self::RECRUITMENT_MANAGE],
            'vacancies'     => [self::VACANCIES_MANAGE],
            'candidates'    => [self::CANDIDATES_VIEW],
            'applications'  => [self::APPLICATIONS_MANAGE],
            'training'      => [
                self::TRAINING_VIEW, self::TRAINING_VIEW_OWN,
                self::TRAINING_MANAGE, self::TRAINING_APPROVE,
            ],
            'onboarding'    => [self::ONBOARDING_VIEW, self::ONBOARDING_MANAGE],
            'reports'       => [self::REPORTS_ORG, self::REPORTS_TEAM],
            'audit'         => [self::AUDIT_VIEW],
            'settings'      => [self::SETTINGS_MANAGE],
            'roles'         => [self::ROLES_VIEW, self::ROLES_MANAGE, self::ROLES_ASSIGN],
            'performance'   => [
                self::PERFORMANCE_VIEW, self::PERFORMANCE_VIEW_OWN,
                self::PERFORMANCE_VIEW_TEAM, self::PERFORMANCE_MANAGE,
            ],
            'goals'         => [
                self::GOALS_VIEW, self::GOALS_VIEW_OWN, self::GOALS_CREATE,
                self::GOALS_UPDATE, self::GOALS_DELETE,
            ],
            'okr'           => [self::OKR_VIEW, self::OKR_MANAGE, self::OKR_SCORE],
            'projects'      => [
                self::PROJECTS_VIEW, self::PROJECTS_VIEW_OWN, self::PROJECTS_CREATE,
                self::PROJECTS_UPDATE, self::PROJECTS_DELETE, self::PROJECTS_ASSIGN,
            ],
            'tasks'         => [
                self::TASKS_VIEW, self::TASKS_VIEW_OWN, self::TASKS_CREATE,
                self::TASKS_UPDATE, self::TASKS_DELETE, self::TASKS_ASSIGN,
                self::TASKS_COMMENT,
            ],
            'assets'        => [
                self::ASSETS_VIEW, self::ASSETS_VIEW_OWN, self::ASSETS_CREATE,
                self::ASSETS_UPDATE, self::ASSETS_DELETE, self::ASSETS_ASSIGN,
                self::ASSETS_REQUEST, self::ASSETS_APPROVE,
                self::ASSETS_REPORT_ISSUE, self::ASSETS_RESOLVE_ISSUE,
            ],
            'travel'        => [
                self::TRAVEL_VIEW, self::TRAVEL_VIEW_OWN, self::TRAVEL_VIEW_TEAM,
                self::TRAVEL_SUBMIT, self::TRAVEL_APPROVE, self::TRAVEL_REJECT,
                self::TRAVEL_MANAGE, self::TRAVEL_RECONCILE,
            ],
        ];
    }

    /** @return string[] */
    public static function all(): array
    {
        return array_merge(...array_values(self::grouped()));
    }

    public static function isKnown(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /**
     * Fail loudly on an unrecognised permission outside production, so a
     * typo surfaces in development rather than quietly denying (or, in a
     * caller that inverts the result, quietly granting).
     */
    public static function assertKnown(string $permission): void
    {
        if (! self::isKnown($permission) && ! app()->isProduction()) {
            throw new \InvalidArgumentException(
                "Unknown permission [{$permission}]. Add it to " . self::class . " before checking it."
            );
        }
    }

    /**
     * Does a granted permission string satisfy the one being asked for?
     *
     * Matching is exact, resource-wildcard (`leave.*`), or global (`*`).
     * A wildcard never spans the dot: `leave.*` does not grant
     * `leave_types.manage`.
     */
    public static function satisfies(string $granted, string $wanted): bool
    {
        if ($granted === '*' || $granted === $wanted) {
            return true;
        }

        if (! str_ends_with($granted, '.*')) {
            return false;
        }

        return str_starts_with($wanted, substr($granted, 0, -1));
    }

    /**
     * @param string[] $granted
     */
    public static function anySatisfies(array $granted, string $wanted): bool
    {
        foreach ($granted as $g) {
            if (self::satisfies($g, $wanted)) {
                return true;
            }
        }

        return false;
    }
}
