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
    /*
     * Attendance. Sensitive personal data, so the scopes are the point: you
     * see your own, a manager sees their team's, HR sees everybody's.
     */
    public const ATTENDANCE_VIEW_OWN  = 'attendance.view_own';
    public const ATTENDANCE_VIEW_TEAM = 'attendance.view_team';
    public const ATTENDANCE_VIEW      = 'attendance.view';
    public const ATTENDANCE_RECORD    = 'attendance.record';
    // Editing somebody else's day, and setting the policy everyone is measured
    // against. Deliberately apart from recording your own.
    public const ATTENDANCE_MANAGE    = 'attendance.manage';

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

    // ── Pipeline (leads and opportunities) ────────────────────────────────
    public const PIPELINE_VIEW     = 'pipeline.view';
    /** Seeing only the leads and deals you own or are assigned to. */
    public const PIPELINE_VIEW_OWN = 'pipeline.view_own';
    public const PIPELINE_CREATE   = 'pipeline.create';
    public const PIPELINE_UPDATE   = 'pipeline.update';
    public const PIPELINE_DELETE   = 'pipeline.delete';
    /**
     * Moving a deal into Win or Lost.
     *
     * Separate from editing it: closing a deal is what the number reported to
     * the board is built from, and anyone able to correct a typo in a deal
     * name should not thereby be able to book it as won.
     */
    public const PIPELINE_CLOSE    = 'pipeline.close';
    /**
     * Seeing the money.
     *
     * Deal values, estimates, margins, bid bonds and bidding costs are visible
     * only with this. Everything else about a deal — who owns it, what stage it
     * is in, what has been agreed — stays readable, because a presales engineer
     * needs the deal and does not need the margin.
     *
     * Held apart from `pipeline.view` rather than folded into it: the commonest
     * request is "let the delivery team see their deals", and granting that
     * should not hand over what the company bids and what it makes.
     */
    public const PIPELINE_VIEW_COMMERCIALS = 'pipeline.view_commercials';

    // ── Travel ────────────────────────────────────────────────────────────
    public const TRAVEL_VIEW      = 'travel.view';
    public const TRAVEL_VIEW_OWN  = 'travel.view_own';
    public const TRAVEL_VIEW_TEAM = 'travel.view_team';
    public const TRAVEL_SUBMIT    = 'travel.submit';
    public const TRAVEL_APPROVE   = 'travel.approve';
    public const TRAVEL_REJECT    = 'travel.reject';
    public const TRAVEL_MANAGE    = 'travel.manage';
    /**
     * The finance stages are separate authorities and must not collapse into
     * one. With a single `travel.approve`, anyone able to approve a trip could
     * also release the money — precisely the separation a four-stage chain
     * exists to enforce.
     */
    public const TRAVEL_ASSESS_FINANCE    = 'travel.assess_finance';
    public const TRAVEL_APPROVE_EXECUTIVE = 'travel.approve_executive';
    /**
     * The COO stage on a delivery.
     *
     * Separate from the CFO's: signing off a large consignment of equipment is
     * an operations judgement, not a financial one, and the two are held by
     * different people.
     */
    public const TRAVEL_APPROVE_OPERATIONS = 'travel.approve_operations';
    /** Destinations, expense categories and the allowance rate card. */
    public const TRAVEL_MANAGE_RATES      = 'travel.manage_rates';
    /** Reserved. No reconciliation workflow is built: the legacy table held
     *  zero rows across 102 requests. */
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
            'pipeline'      => [
                self::PIPELINE_VIEW, self::PIPELINE_VIEW_OWN, self::PIPELINE_CREATE,
                self::PIPELINE_UPDATE, self::PIPELINE_DELETE, self::PIPELINE_CLOSE,
                self::PIPELINE_VIEW_COMMERCIALS,
            ],
            'employees'     => [
                self::EMPLOYEES_VIEW, self::EMPLOYEES_VIEW_OWN, self::EMPLOYEES_VIEW_TEAM,
                self::EMPLOYEES_CREATE, self::EMPLOYEES_UPDATE, self::EMPLOYEES_DELETE,
                self::EMPLOYEES_EXIT,
            ],
            'attendance'    => [
                self::ATTENDANCE_VIEW, self::ATTENDANCE_VIEW_OWN, self::ATTENDANCE_VIEW_TEAM,
                self::ATTENDANCE_RECORD, self::ATTENDANCE_MANAGE,
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
                self::TRAVEL_ASSESS_FINANCE, self::TRAVEL_APPROVE_EXECUTIVE,
                self::TRAVEL_APPROVE_OPERATIONS,
                self::TRAVEL_MANAGE_RATES, self::TRAVEL_MANAGE, self::TRAVEL_RECONCILE,
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
