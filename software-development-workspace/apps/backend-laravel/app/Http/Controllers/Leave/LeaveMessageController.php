<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Leave\LeaveRequestMessage;
use App\Models\LeaveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The conversation on a leave request.
 *
 * "Can you move this to the following week" is the commonest thing an approver
 * needs to say and there has never been anywhere to say it, so it has been
 * said by email and the request has carried no trace of it.
 *
 * Not the rejection reason. That is a verdict — written once, by the person
 * refusing, unchangeable afterwards. This is a discussion, and conflating the
 * two would let a thread be mistaken for the record of a decision.
 */
class LeaveMessageController extends Controller
{
    public function index(Request $request, int $leaveRequest): JsonResponse
    {
        $leave = $this->authorised($leaveRequest);

        $messages = $leave->messages()
            ->visibleTo($this->isApplicant($request, $leave))
            ->with('author:id,name')
            ->get();

        return response()->json([
            'data' => $messages->map(fn ($m) => [
                'id'                  => $m->id,
                'body'                => $m->body,
                'author'              => $m->author?->name,
                'author_id'           => $m->author_id,
                'visible_to_employee' => $m->visible_to_employee,
                'created_at'          => $m->created_at?->toISOString(),
            ])->all(),
        ]);
    }

    public function store(Request $request, int $leaveRequest): JsonResponse
    {
        $leave = $this->authorised($leaveRequest);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            /*
             * Only the approving side may write a note the applicant cannot
             * read. Letting the applicant mark their own message private would
             * produce a message with no audience at all.
             */
            'visible_to_employee' => ['sometimes', 'boolean'],
        ]);

        $isApplicant = $this->isApplicant($request, $leave);

        $message = LeaveRequestMessage::create([
            'leave_request_id'    => $leave->id,
            'author_id'           => $request->user()->id,
            'body'                => $data['body'],
            'visible_to_employee' => $isApplicant
                ? true
                : ($data['visible_to_employee'] ?? true),
        ]);

        $message->load('author:id,name');

        return response()->json([
            'data' => [
                'id'                  => $message->id,
                'body'                => $message->body,
                'author'              => $message->author?->name,
                'author_id'           => $message->author_id,
                'visible_to_employee' => $message->visible_to_employee,
                'created_at'          => $message->created_at?->toISOString(),
            ],
        ], 201);
    }

    /** Whether this user is the person who asked for the leave. */
    private function isApplicant(Request $request, LeaveRequest $leave): bool
    {
        return $request->user()->employee?->id === $leave->employee_id;
    }

    private function authorised(int $id): LeaveRequest
    {
        $leave = LeaveRequest::withoutGlobalScope('organization')
            ->where('organization_id', app('current.organization_id'))
            ->findOrFail($id);

        $this->authorize('view', $leave);

        return $leave;
    }
}
