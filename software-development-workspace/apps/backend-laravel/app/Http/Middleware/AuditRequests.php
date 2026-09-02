<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The coarse half of "every action": one row for every state-changing request
 * that succeeded, so an action with no single model behind it — a payroll run,
 * a report export, a bulk send, re-running routing — still leaves a trace.
 *
 * It defers to the model observer: if a request already produced detailed
 * created/updated/deleted rows, no coarse duplicate is written. What is left is
 * exactly the actions the field-level trail cannot see.
 *
 * Only method + path is recorded, never the request body — that body is the PII
 * the masker works to keep out of the log, and it does not belong here either.
 */
class AuditRequests
{
    /** Never audit these (auth has its own events; audit reads would recurse). */
    private const SKIP_PREFIXES = ['api/login', 'api/logout', 'api/v1/audit-logs'];

    public function handle(Request $request, Closure $next): Response
    {
        AuditLogger::$detailedWritesThisRequest = 0;

        /** @var Response $response */
        $response = $next($request);

        if ($this->shouldRecord($request, $response)) {
            AuditLogger::record(
                action: 'action',
                description: $this->describe($request),
            );
        }

        return $response;
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        // Only state-changing verbs.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        // Only successful ones — a rejected/403/422 action changed nothing.
        if ($response->getStatusCode() >= 300) {
            return false;
        }

        // Nobody to attribute it to (public/unauth POST) — skip; auth events
        // cover the login surface.
        if (! Auth::check()) {
            return false;
        }

        // A model observer already logged this operation in detail.
        if (AuditLogger::$detailedWritesThisRequest > 0) {
            return false;
        }

        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($request->path(), $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function describe(Request $request): string
    {
        $verb = match ($request->method()) {
            'POST'   => 'Performed',
            'PUT', 'PATCH' => 'Updated via',
            'DELETE' => 'Deleted via',
            default  => 'Called',
        };

        return "{$verb} {$request->method()} " . $request->path();
    }
}
