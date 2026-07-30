<?php

namespace App\Support\Authorization;

/**
 * The reach of a role assignment.
 *
 * A permission says *what* a principal may do. A scope says *which records*
 * it reaches. Keeping them separate is what stops the permission catalogue
 * growing combinatorially — one `leave.view` assigned at five scopes rather
 * than five permissions.
 *
 * Scopes are totally ordered and each contains the ones below it. A grant at
 * `organization` therefore satisfies a requirement for `self`, because
 * everything of the principal's own is inside their organization.
 */
final class Scope
{
    /** Only the principal's own records. */
    public const SELF = 'self';

    /** The principal's direct and indirect reports. */
    public const TEAM = 'team';

    /** Everyone in the principal's department. */
    public const DEPARTMENT = 'department';

    /** The whole tenant. */
    public const ORGANIZATION = 'organization';

    /** Every tenant. */
    public const GROUP = 'group';

    /**
     * Narrowest to widest. Position is the comparison; do not reorder without
     * understanding that `covers()` depends on it.
     *
     * @var string[]
     */
    private const ORDER = [
        self::SELF,
        self::TEAM,
        self::DEPARTMENT,
        self::ORGANIZATION,
        self::GROUP,
    ];

    /** @return string[] */
    public static function all(): array
    {
        return self::ORDER;
    }

    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::ORDER, true);
    }

    public static function rank(string $scope): int
    {
        $rank = array_search($scope, self::ORDER, true);

        if ($rank === false) {
            // An unrecognised scope must not accidentally outrank a real one.
            // Treat it as the narrowest possible reach rather than guessing.
            return -1;
        }

        return $rank;
    }

    /**
     * Does a held scope reach at least as far as one required?
     *
     * covers('organization', 'self') === true  — org contains own records
     * covers('self', 'organization') === false — own records are not the org
     */
    public static function covers(string $held, string $required): bool
    {
        $heldRank = self::rank($held);

        if ($heldRank < 0) {
            return false;
        }

        return $heldRank >= self::rank($required);
    }

    /**
     * The widest of several scopes, or null if none are valid.
     *
     * Used when a principal holds one permission through several assignments:
     * the effective reach is the widest of them.
     *
     * @param string[] $scopes
     */
    public static function widest(array $scopes): ?string
    {
        $widest = null;

        foreach ($scopes as $scope) {
            if (! self::isValid($scope)) {
                continue;
            }

            if ($widest === null || self::rank($scope) > self::rank($widest)) {
                $widest = $scope;
            }
        }

        return $widest;
    }
}
