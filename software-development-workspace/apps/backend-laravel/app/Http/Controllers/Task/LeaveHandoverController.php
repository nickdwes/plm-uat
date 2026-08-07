<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\Task\Task;
use App\Models\Task\TaskHandover;
use App\Services\Task\LeaveHandoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The handover checklist for one leave request.
 *
 * Gated on the leave request rather than on tasks: the person going away is the
 * one who has to answer for their own work, and their approver is the one who
 * has to be satisfied with the answers. Requiring a task permission on top
 * would lock most people out of their own handover.
 */
class LeaveHandoverController extends Controller
{
    public function __construct(private LeaveHandoverService $handover)
    {
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorise($request, $leaveRequest);

        $split = $this->handover->prepare($leaveRequest);

        return response()->json([
            // Coverable work asks "who".
            'coverable' => $split['coverable']->map(fn (Task $t) => $this->row($t)),

            /*
             * The rest asks "what do we do about it" — but only where it
             * actually falls due while they are away. The legacy could never
             * surface these because it treated every task as equally handable.
             */
            'at_risk'   => $split['at_risk']->map(fn (Task $t) => $this->row($t)),
            'personal'  => $split['personal']->map(fn (Task $t) => $this->row($t)),

            'summary' => [
                'coverable' => $split['coverable']->count(),
                'covered'   => $split['coverable']->filter(fn (Task $t) => $t->activeHandover)->count(),
                'at_risk'   => $split['at_risk']->count(),
                'personal'  => $split['personal']->count(),
            ],
        ]);
    }

    /** Name somebody to hold a task for the duration. */
    public function store(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorise($request, $leaveRequest);

        $data = $request->validate([
            'task_id' => ['required', 'integer',
                Rule::exists('tasks', 'id')->where('organization_id', $leaveRequest->organization_id)],
            'to_employee_id' => ['required', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $leaveRequest->organization_id)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $task = Task::findOrFail($data['task_id']);

        $this->handover->assign(
            $leaveRequest, $task, $data['to_employee_id'],
            $data['note'] ?? null, $request->user()->id,
        );

        return $this->show($request, $leaveRequest);
    }

    /** Take one back — before the leave, or early. */
    public function destroy(Request $request, LeaveRequest $leaveRequest, TaskHandover $handover): JsonResponse
    {
        $this->authorise($request, $leaveRequest);
        abort_unless($handover->leave_request_id === $leaveRequest->id, 404);

        $this->handover->release($handover);

        return $this->show($request, $leaveRequest);
    }

    /**
     * Their own handover, or one they are approving.
     *
     * Anybody who can see the whole company's tasks can see it too, because
     * somebody has to be able to pick up the pieces when a person goes away
     * without arranging cover.
     */
    private function authorise(Request $request, LeaveRequest $leaveRequest): void
    {
        $me = $request->user()->employeeId();

        abort_unless(
            $leaveRequest->employee_id === $me
                || $leaveRequest->approved_by === $me
                || $request->user()->hasPermission(\App\Support\Authorization\Permission::TASKS_VIEW),
            403,
            'This is not your handover.',
        );
    }

    private function row(Task $t): array
    {
        $cover = $t->activeHandover;

        return [
            'id'        => $t->id,
            'reference' => $t->reference,
            'title'     => $t->title,
            'category'  => $t->category,
            'status'    => $t->status,
            'priority'  => $t->priority,
            'deadline'  => $t->deadline?->toDateString(),
            'is_overdue' => $t->is_overdue,
            'project'   => $t->project ? ['id' => $t->project->id, 'name' => $t->project->name] : null,

            'is_transferable' => $t->is_transferable,
            // Said out loud. A rule people cannot see reads as obstruction, and
            // they route around it by handing the task over informally — which
            // is the thing this exists to stop.
            'block_reason'    => $t->handover_block_reason,

            'covered_by' => $cover?->coverer
                ? ['id' => $cover->coverer->id, 'name' => $cover->coverer->full_name]
                : null,
            'handover_id'   => $cover?->id,
            'handover_note' => $cover?->note,
        ];
    }
}
