<?php

namespace App\Http\Controllers\Benefits;

use App\Support\Authorization\Permission;
use App\Http\Controllers\Controller;
use App\Models\Benefits\BenefitApproval;
use App\Models\Benefits\BenefitDefinition;
use App\Models\Benefits\BenefitDependent;
use App\Models\Benefits\BenefitPlan;
use App\Models\Benefits\EmployeeBenefitPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BenefitController extends Controller
{
    private const TYPES = [
        'bonus', 'fuel_allowance', 'taxi_allowance', 'airtime_allowance',
        'lunch_allowance', 'per_diem', 'membership_allowance', 'welfare',
        'medical_insurance', 'work_from_home', 'compensatory_days_off',
    ];

    private const DEF_RULES = [
        'name'                  => 'required|string|max:120',
        'benefit_type'          => 'required|string',
        'eligibility_rules'     => 'nullable|array',
        'amount'                => 'nullable|numeric|min:0',
        'amount_limit'          => 'nullable|numeric|min:0',
        'payable_period'        => 'nullable|string|in:monthly,quarterly,annually,per_trip,per_event',
        'paid_with_salary'      => 'boolean',
        'requires_approval'     => 'boolean',
        'requires_reason'       => 'boolean',
        'max_days'              => 'nullable|integer|min:0',
        'registration_required' => 'boolean',
        'mode'                  => 'nullable|string|in:company_issued,reimbursement,issued_amount',
        'location_rates'        => 'nullable|array',
        'allowed_memberships'   => 'nullable|string',
        'membership_rules'      => 'nullable|string',
        'coverage_limit'        => 'nullable|numeric|min:0',
        'dependents_required'   => 'boolean',
        'notes'                 => 'nullable|string',
        'is_active'             => 'boolean',
    ];

    /*
     * Every method guards inline, per the house pattern for policy-less
     * modules (Goals, OKR, Procurement). Three tiers: `view` reads the
     * catalogue and the assignments; `manage` administers them; `approve`
     * decides the approvals that put an employee onto a plan. This module
     * previously had no checks at all.
     */

    // ── Benefit Definitions ───────────────────────────────────────────────────

    public function indexDefinitions(): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_VIEW), 403);
        $orgId = app('current.organization_id');
        $defs  = BenefitDefinition::where('organization_id', $orgId)
            ->orderBy('benefit_type')->orderBy('name')->get();
        return response()->json(['data' => $defs]);
    }

    public function storeDefinition(Request $request): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_MANAGE), 403);
        $rules        = self::DEF_RULES;
        $rules['benefit_type'] = 'required|string|in:' . implode(',', self::TYPES);
        $data         = $request->validate($rules);
        $data['organization_id'] = app('current.organization_id');
        $def = BenefitDefinition::create($data);
        return response()->json($def, 201);
    }

    public function updateDefinition(Request $request, BenefitDefinition $benefit): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_MANAGE), 403);
        $rules = array_map(fn ($r) => str_replace('required|', 'sometimes|', $r), self::DEF_RULES);
        $rules['benefit_type'] = 'sometimes|string|in:' . implode(',', self::TYPES);
        $data = $request->validate($rules);
        $benefit->update($data);
        return response()->json($benefit);
    }

    public function destroyDefinition(BenefitDefinition $benefit): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_MANAGE), 403);
        $benefit->delete();
        return response()->json(null, 204);
    }

    // ── Benefit Plans ─────────────────────────────────────────────────────────

    public function indexPlans(): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_VIEW), 403);
        $orgId = app('current.organization_id');
        $plans = BenefitPlan::where('organization_id', $orgId)
            ->withCount('assignments')
            ->with(['items.benefit'])
            ->orderBy('name')->get();
        return response()->json(['data' => $plans]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_MANAGE), 403);
        $data = $request->validate([
            'name'              => 'required|string|max:120',
            'description'       => 'nullable|string',
            'eligibility_rules' => 'nullable|array',
            'is_active'         => 'boolean',
            'items'             => 'array',
            'items.*.benefit_definition_id' => 'required|integer|exists:benefit_definitions,id',
            'items.*.amount_override'       => 'nullable|numeric|min:0',
            'items.*.limit_override'        => 'nullable|numeric|min:0',
            'items.*.max_days_override'     => 'nullable|integer|min:0',
            'items.*.notes'                 => 'nullable|string',
        ]);

        $items = $data['items'] ?? [];
        unset($data['items']);
        $data['organization_id'] = app('current.organization_id');

        $plan = BenefitPlan::create($data);
        foreach ($items as $item) {
            $plan->items()->create($item);
        }
        return response()->json($plan->load('items.benefit'), 201);
    }

    public function updatePlan(Request $request, BenefitPlan $plan): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_MANAGE), 403);
        $data = $request->validate([
            'name'              => 'sometimes|string|max:120',
            'description'       => 'nullable|string',
            'eligibility_rules' => 'nullable|array',
            'is_active'         => 'boolean',
            'items'             => 'nullable|array',
            'items.*.benefit_definition_id' => 'required|integer|exists:benefit_definitions,id',
            'items.*.amount_override'       => 'nullable|numeric|min:0',
            'items.*.limit_override'        => 'nullable|numeric|min:0',
            'items.*.max_days_override'     => 'nullable|integer|min:0',
            'items.*.notes'                 => 'nullable|string',
        ]);

        $items = $data['items'] ?? null;
        unset($data['items']);

        $plan->update($data);

        if ($items !== null) {
            $plan->items()->delete();
            foreach ($items as $item) {
                $plan->items()->create($item);
            }
        }
        return response()->json($plan->load('items.benefit'));
    }

    public function destroyPlan(BenefitPlan $plan): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_MANAGE), 403);
        $plan->delete();
        return response()->json(null, 204);
    }

    // ── Employee Assignments ──────────────────────────────────────────────────

    public function indexAssignments(): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_VIEW), 403);
        $orgId       = app('current.organization_id');
        $assignments = EmployeeBenefitPlan::whereHas('employee', fn ($q) => $q->where('primary_organization_id', $orgId))
            ->with(['employee', 'plan', 'assignedBy'])
            ->orderByDesc('effective_date')->get();
        return response()->json(['data' => $assignments]);
    }

    public function storeAssignment(Request $request): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_MANAGE), 403);
        $data = $request->validate([
            'employee_id'    => 'required|integer|exists:employees,id',
            'plan_id'        => 'required|integer|exists:benefit_plans,id',
            'effective_date' => 'required|date',
            'end_date'       => 'nullable|date|after:effective_date',
            'notes'          => 'nullable|string',
        ]);
        $data['assigned_by'] = $request->user()->employee?->id;
        $data['is_active']   = true;

        $assignment = EmployeeBenefitPlan::create($data);
        return response()->json($assignment->load(['employee', 'plan']), 201);
    }

    public function destroyAssignment(EmployeeBenefitPlan $assignment): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_MANAGE), 403);
        $assignment->delete();
        return response()->json(null, 204);
    }

    // ── Dependents (Medical Insurance) ────────────────────────────────────────

    public function indexDependents(): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_VIEW), 403);
        $orgId = app('current.organization_id');
        $deps  = BenefitDependent::whereHas('employee', fn ($q) => $q->where('primary_organization_id', $orgId))
            ->with('employee')
            ->orderBy('full_name')->get();
        return response()->json(['data' => $deps]);
    }

    public function storeDependent(Request $request): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_MANAGE), 403);
        $data = $request->validate([
            'employee_id'   => 'required|integer|exists:employees,id',
            'full_name'     => 'required|string|max:120',
            'relationship'  => 'required|string|in:spouse,child,parent,other',
            'date_of_birth' => 'nullable|date',
            'id_number'     => 'nullable|string|max:30',
        ]);
        $dep = BenefitDependent::create($data);
        return response()->json($dep->load('employee'), 201);
    }

    public function destroyDependent(BenefitDependent $dependent): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_MANAGE), 403);
        $dependent->delete();
        return response()->json(null, 204);
    }

    // ── Approvals (WFH / Compensatory Days) ──────────────────────────────────

    public function indexApprovals(): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_VIEW), 403);
        $orgId     = app('current.organization_id');
        $approvals = BenefitApproval::whereHas('employee', fn ($q) => $q->where('primary_organization_id', $orgId))
            ->with(['employee', 'benefit', 'supervisor'])
            ->orderByDesc('requested_date')->get();
        return response()->json(['data' => $approvals]);
    }

    public function storeApproval(Request $request): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_MANAGE), 403);
        $data = $request->validate([
            'employee_id'           => 'required|integer|exists:employees,id',
            'benefit_definition_id' => 'required|integer|exists:benefit_definitions,id',
            'requested_date'        => 'required|date',
            'end_date'              => 'nullable|date|after_or_equal:requested_date',
            'reason'                => 'nullable|string',
            'days_requested'        => 'required|integer|min:1',
            'supervisor_id'         => 'nullable|integer|exists:employees,id',
        ]);
        $data['status'] = 'pending';

        $approval = BenefitApproval::create($data);
        return response()->json($approval->load(['employee', 'benefit', 'supervisor']), 201);
    }

    public function actionApproval(Request $request, BenefitApproval $approval): JsonResponse
    {
        abort_unless(request()->user()->hasPermission(Permission::BENEFITS_APPROVE), 403);
        $data = $request->validate([
            'status'       => 'required|in:approved,rejected',
            'action_notes' => 'nullable|string',
        ]);

        $approval->update([
            'status'       => $data['status'],
            'action_date'  => now()->toDateString(),
            'action_notes' => $data['action_notes'] ?? null,
        ]);
        return response()->json($approval);
    }
}
