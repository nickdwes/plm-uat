<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Whose leave a person may look at.
 *
 * The same screen serves an HR officer and a team leader, and the difference
 * between them is not cosmetic — it is which rows the query returns. Deciding
 * that in one place means the listing, the counts along the top and the
 * per-request checks cannot disagree, which is the failure that lets somebody
 * see a total that includes rows they are then refused.
 */
class LeaveVisibility
{
    public const ALL  = 'all';
    public const TEAM = 'team';
    public const OWN  = 'own';

    /**
     * The widest scope this user is entitled to in this organisation.
     *
     * Ordered widest-first: somebody who is both an HR officer and a manager
     * gets the HR view, because the narrower one would hide rows they are
     * explicitly permitted to see.
     */
    public static function scopeFor(User $user, ?int $organizationId): string
    {
        if ($user->isGroupAdmin()
            || $user->isOrgHR($organizationId)
            || $user->hasPermission(Permission::LEAVE_MANAGE, $organizationId)
            || $user->hasPermission(Permission::LEAVE_VIEW, $organizationId)) {
            return self::ALL;
        }

        /*
         * A manager with nobody reporting to them is not a team view — it is
         * an empty table with a heading that promises otherwise. The permission
         * says they may see a team; having one is a separate question.
         */
        if ($user->hasPermission(Permission::LEAVE_VIEW_TEAM, $organizationId)
            || $user->hasPermission(Permission::LEAVE_APPROVE, $organizationId)) {
            return self::teamOf($user, $organizationId)->isNotEmpty() ? self::TEAM : self::OWN;
        }

        return self::OWN;
    }

    /**
     * The employees whose leave a team-scoped viewer may see.
     *
     * Direct reports only. A deeper walk would put a director in the middle of
     * every request in their line of the org chart, which is not what the
     * approval routing does and would quietly widen the screen past what the
     * permission was granted for.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function teamOf(User $user, ?int $organizationId): \Illuminate\Support\Collection
    {
        $me = $user->employee?->id;

        if (! $me) {
            return collect();
        }

        return Employee::query()
            ->where('reports_to', $me)
            ->when($organizationId, fn ($q, $org) => $q->forOrganization($org))
            ->pluck('id');
    }

    /**
     * The employee ids a scope resolves to, or null for "no restriction".
     *
     * Null rather than an empty collection on purpose — an empty list means
     * nobody, and returning one where "everybody" was meant is how an HR
     * screen renders blank.
     *
     * @return \Illuminate\Support\Collection<int, int>|null
     */
    public static function employeeIds(string $scope, User $user, ?int $organizationId): ?\Illuminate\Support\Collection
    {
        return match ($scope) {
            self::ALL  => null,
            // Their own row belongs in the team view too; a manager taking
            // leave is part of who is away.
            self::TEAM => self::teamOf($user, $organizationId)
                ->push($user->employee?->id)
                ->filter()
                ->unique()
                ->values(),
            default    => collect([$user->employee?->id])->filter()->values(),
        };
    }
}
