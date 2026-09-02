<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The one door onto the audit trail.
 *
 * Every writer — the model observer, the auth-event listeners, the explicit
 * calls for downloads and role changes — comes through here, so the shape of a
 * row is decided in a single place and the actor/ip/route are filled in the
 * same way whoever is writing.
 */
class AuditLogger
{
    /**
     * How many detailed (created/updated/deleted) rows were written during the
     * current request. The request-action middleware reads this to avoid
     * logging a coarse duplicate of an operation the model observer already
     * captured in detail. Request-scoped: a static is fine under PHP-FPM.
     */
    public static int $detailedWritesThisRequest = 0;

    /**
     * @param  array<string, array{old: mixed, new: mixed}>|null $changes
     */
    public static function record(
        string $action,
        ?Model $auditable = null,
        ?string $description = null,
        ?array $changes = null,
        ?int $organizationId = null,
        ?int $userId = null,
        ?string $actorName = null,
    ): void {
        if (in_array($action, ['created', 'updated', 'deleted'], true)) {
            self::$detailedWritesThisRequest++;
        }

        $request = request();
        $user    = Auth::user();

        AuditLog::create([
            'organization_id' => $organizationId ?? self::currentOrg(),
            'user_id'         => $userId ?? $user?->id,
            'actor_name'      => $actorName ?? $user?->name,
            'action'          => $action,
            'auditable_type'  => $auditable?->getMorphClass(),
            'auditable_id'    => $auditable?->getKey(),
            'description'     => $description,
            'changes'         => $changes,
            'ip_address'      => $request?->ip(),
            'user_agent'      => substr((string) $request?->userAgent(), 0, 400) ?: null,
            'route'           => $request ? trim($request->method() . ' ' . $request->path()) : null,
            'created_at'      => now(),
        ]);
    }

    private static function currentOrg(): ?int
    {
        return app()->bound('current.organization_id')
            ? app('current.organization_id')
            : null;
    }
}
