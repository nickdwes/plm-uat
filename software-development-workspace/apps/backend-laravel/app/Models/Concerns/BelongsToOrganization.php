<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Apply this trait to any model that must be scoped to an organization.
 *
 * It adds a global scope that automatically filters all queries by the
 * organization resolved from the current request context (set by
 * App\Http\Middleware\ResolveOrganizationContext).
 *
 * Exception: Group Admin users bypass the scope (see middleware).
 * Exception: Explicit ->withoutOrgScope() calls can bypass it for group-level queries.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder) {
            $orgId = app()->bound('current.organization_id') ? app('current.organization_id') : null;

            if ($orgId !== null) {
                $builder->where(
                    $builder->getModel()->getTable() . '.organization_id',
                    $orgId
                );
            }
        });
    }

    /**
     * Remove the organization global scope for cross-org (group-level) queries.
     */
    public static function forGroup(): Builder
    {
        return static::withoutGlobalScope('organization');
    }

    /**
     * Explicitly scope a query to a specific organization
     * (useful in group-level admin context).
     */
    public static function forOrganization(int $organizationId): Builder
    {
        return static::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId);
    }
}
