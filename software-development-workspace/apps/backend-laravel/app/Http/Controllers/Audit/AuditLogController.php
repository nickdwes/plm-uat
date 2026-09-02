<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading the audit trail.
 *
 * Scoped so a reviewer sees their own organisation's events plus the
 * org-less security events (failed logins, cross-tenant attempts) that concern
 * whoever they are reaching for. A group administrator sees everything.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permission::AUDIT_VIEW), 403);

        $query = AuditLog::query()->with('user:id,name')->orderByDesc('id');

        // Tenant scope: group admins see all; everyone else sees their org's
        // rows plus the org-less security events.
        if (! $request->user()->isGroupAdmin()) {
            $orgId = app()->bound('current.organization_id') ? app('current.organization_id') : null;
            $query->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        if ($request->filled('auditable_type')) {
            // Accept a short name ("Employee") or the full class.
            $type = $request->string('auditable_type');
            $query->where('auditable_type', 'ilike', "%{$type}");
        }
        if ($request->filled('q')) {
            $like = '%' . $request->string('q') . '%';
            $query->where(fn ($w) => $w->where('description', 'ilike', $like)
                ->orWhere('actor_name', 'ilike', $like)
                ->orWhere('ip_address', 'ilike', $like));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        $logs = $query->paginate($request->integer('per_page', 40));

        $logs->getCollection()->transform(function (AuditLog $log) use ($request) {
            // What was written is masked for everyone (PiiMasker); what is
            // READ additionally answers to the reader's own permissions, so
            // the trail is not a side-channel around field classification.
            $view = \App\Support\Audit\AuditRedactor::forReader($request->user(), $log);

            return [
                'id'              => $log->id,
                'action'          => $log->action,
                'actor'           => $log->user?->name ?? $log->actor_name ?? 'System',
                'description'     => $log->description,
                'subject'         => $log->auditable_type ? class_basename($log->auditable_type) . ' #' . $log->auditable_id : null,
                'changes'         => $view['changes'],
                'values_redacted' => $view['values_redacted'],
                'ip_address'      => $log->ip_address,
                'route'           => $log->route,
                'at'              => $log->created_at?->toDateTimeString(),
            ];
        });

        return response()->json($logs);
    }

    /** The distinct action types present, for the filter dropdown. */
    public function actions(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permission::AUDIT_VIEW), 403);

        return response()->json([
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
