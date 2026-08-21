<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeMovement;
use App\Models\Organization;
use App\Services\HR\EmployeeMovementService;
use App\Services\HR\TransferPreflight;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Moving somebody between the organisations in the group.
 */
class EmployeeMovementController extends Controller
{
    public function __construct(
        private readonly EmployeeMovementService $movements,
        private readonly TransferPreflight $preflight,
    ) {}

    /**
     * What is still holding them where they are.
     *
     * Asked before the move, not after. Twenty-five places in this application
     * validate an assignee against their primary organisation, so a person's
     * open work becomes un-resaveable the moment they leave — the order has to
     * be reassign, then move.
     */
    public function preflight(Request $request, int $employee): JsonResponse
    {
        $record = Employee::findOrFail($employee);

        $this->authorize('transfer', $record);

        return response()->json([
            'data' => $this->preflight->run($record, $record->primary_organization_id),
            'employee' => [
                'id'   => $record->id,
                'name' => trim($record->first_name . ' ' . $record->last_name),
                'organization' => Organization::find($record->primary_organization_id)?->only(['id', 'name']),
            ],
        ]);
    }

    public function index(int $employee): JsonResponse
    {
        $record = Employee::findOrFail($employee);

        $this->authorize('view', $record);

        return response()->json([
            'data' => EmployeeMovement::where('employee_id', $record->id)
                ->with(['from:id,name', 'to:id,name', 'movedBy:id,name'])
                ->orderByDesc('effective_on')->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request, int $employee): JsonResponse
    {
        $record = Employee::findOrFail($employee);

        $this->authorize('transfer', $record);

        $data = $request->validate([
            'kind'            => ['required', Rule::in([EmployeeMovement::SECONDMENT, EmployeeMovement::TRANSFER])],
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'effective_on'    => ['required', 'date'],
            'effective_to'    => ['nullable', 'date', 'after:effective_on'],
            'reason'          => ['nullable', 'string', 'max:500'],
            'role'            => ['nullable', 'string', 'max:120'],
            'engagement_type' => ['nullable', 'string', 'max:32'],
            'allocation_pct'  => ['nullable', 'integer', 'min:1', 'max:100'],
            'is_billable'     => ['boolean'],
            'department_id'   => ['nullable', 'integer', 'exists:departments,id'],
            'designation_id'  => ['nullable', 'integer', 'exists:designations,id'],
            'reports_to'      => ['nullable', 'integer', 'exists:employees,id'],
            'access_scope'    => ['nullable', 'string', 'max:32'],
            'force'           => ['boolean'],
        ]);

        $destination = Organization::findOrFail($data['organization_id']);

        /*
         * The permission is needed in the destination as well.
         *
         * This is the one action that crosses a tenancy boundary. Checking only
         * the organisation the request was made in would let anybody who can
         * move their own staff post them into a company that has never agreed
         * to employ them.
         */
        $user = $request->user();

        abort_unless(
            $user->hasPermission(Permission::EMPLOYEES_TRANSFER, $destination->id),
            403,
            'You do not have permission to move people into ' . $destination->name . '.',
        );

        try {
            $movement = $data['kind'] === EmployeeMovement::TRANSFER
                ? $this->movements->transfer($record, $destination, $data, $user)
                : $this->movements->second($record, $destination, $data, $user);
        } catch (RuntimeException $e) {
            // 409 rather than 422: the request is well formed, the world is not
            // ready for it. A validation error would send somebody looking for
            // the field they got wrong.
            return response()->json([
                'message'   => $e->getMessage(),
                'preflight' => $this->preflight->run($record, $record->primary_organization_id),
            ], 409);
        }

        return response()->json([
            'data'    => $movement->load(['from:id,name', 'to:id,name']),
            'message' => $this->describe($movement, $record),
        ], 201);
    }

    /** What just happened, in a sentence somebody can read back. */
    private function describe(EmployeeMovement $movement, Employee $employee): string
    {
        $name = trim($employee->first_name . ' ' . $employee->last_name);
        $to   = $movement->to?->name;

        return $movement->kind === EmployeeMovement::TRANSFER
            ? "{$name} now works for {$to}."
            : "{$name} is placed with {$to}, and still works for {$movement->from?->name}.";
    }
}
