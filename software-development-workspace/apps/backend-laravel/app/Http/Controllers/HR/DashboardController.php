<?php

namespace App\Http\Controllers\HR;

use App\Support\Authorization\Permission;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Department;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        // An org-wide HR aggregate: headcounts, leave, attendance. The same
        // permission that reads the employee register reads its summary.
        abort_unless(request()->user()->hasPermission(Permission::EMPLOYEES_VIEW), 403);

        $orgId = app('current.organization_id');
        $today = now()->toDateString();

        // ── Core headcount ────────────────────────────────────────────────────
        $totalActive = Employee::where('primary_organization_id', $orgId)
            ->employed()->count();

        $totalExited = Employee::where('primary_organization_id', $orgId)
            ->where('status', 'exited')->count();

        $newHiresThisMonth = Employee::where('primary_organization_id', $orgId)
            ->whereYear('date_hired', now()->year)
            ->whereMonth('date_hired', now()->month)
            ->count();

        $lastMonth = now()->subMonthNoOverflow();
        $newHiresLastMonth = Employee::where('primary_organization_id', $orgId)
            ->whereYear('date_hired', $lastMonth->year)
            ->whereMonth('date_hired', $lastMonth->month)
            ->count();

        // ── Leave statistics ──────────────────────────────────────────────────
        $pendingLeave = LeaveRequest::where('organization_id', $orgId)
            ->where('status', 'pending')->count();

        $approvedLeave = LeaveRequest::where('organization_id', $orgId)
            ->where('status', 'approved')->count();

        $rejectedLeave = LeaveRequest::where('organization_id', $orgId)
            ->where('status', 'rejected')->count();

        $onLeaveToday = LeaveRequest::where('organization_id', $orgId)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $today)
            ->whereDate('to_date', '>=', $today)
            ->count();

        // Leave ratio: employees currently on leave vs active headcount
        $leaveRatio = $totalActive > 0
            ? round(($onLeaveToday / $totalActive) * 100, 1)
            : 0;

        // ── Department headcount ──────────────────────────────────────────────
        $headcountByDept = Department::where('organization_id', $orgId)
            ->withCount(['employees as headcount' => fn($q) => $q->employed()])
            ->orderByDesc('headcount')
            ->get(['id', 'name'])
            ->map(fn($d) => ['department' => $d->name, 'headcount' => $d->headcount]);

        // ── Employment type breakdown ─────────────────────────────────────────
        $employmentTypes = Employee::where('primary_organization_id', $orgId)
            ->employed()
            ->select('employment_type', DB::raw('count(*) as count'))
            ->groupBy('employment_type')
            ->get()
            ->map(fn($r) => [
                'type'  => $r->employment_type ?? 'unspecified',
                'count' => $r->count,
            ]);

        // ── Gender breakdown ──────────────────────────────────────────────────
        $genderBreakdown = Employee::where('primary_organization_id', $orgId)
            ->employed()
            ->select('gender', DB::raw('count(*) as count'))
            ->groupBy('gender')
            ->get()
            ->map(fn($r) => [
                'gender' => $r->gender ?? 'unspecified',
                'count'  => $r->count,
            ]);

        // ── Monthly hiring trend (last 6 months) ─────────────────────────────
        $monthlyHires = Employee::where('primary_organization_id', $orgId)
            ->whereBetween('date_hired', [now()->subMonths(5)->startOfMonth(), now()->endOfMonth()])
            ->select(
                DB::raw("TO_CHAR(date_hired, 'YYYY-MM') as month"),
                DB::raw('count(*) as count')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn($r) => ['month' => $r->month, 'count' => $r->count]);

        return response()->json([
            'totalActive'          => $totalActive,
            'totalExited'          => $totalExited,
            'newHiresThisMonth'    => $newHiresThisMonth,
            'newHiresLastMonth'    => $newHiresLastMonth,
            'pendingLeave'         => $pendingLeave,
            'approvedLeave'        => $approvedLeave,
            'rejectedLeave'        => $rejectedLeave,
            'onLeaveToday'         => $onLeaveToday,
            'leaveRatio'           => $leaveRatio,
            'headcountByDept'      => $headcountByDept,
            'employmentTypes'      => $employmentTypes,
            'genderBreakdown'      => $genderBreakdown,
            'monthlyHires'         => $monthlyHires,
        ]);
    }
}
