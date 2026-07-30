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

    /**
     * Every permission the application recognises, grouped by resource.
     *
     * @return array<string, string[]>
     */
    public static function grouped(): array
    {
        return [
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
