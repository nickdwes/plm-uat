<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceCommitment;
use App\Models\Employee;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;

/**
 * Telling somebody when work does not land.
 *
 * "Effective communication if not completed" is not a report run at month end.
 * It is a sentence written on the day by the person who knows why, reaching
 * somebody who was waiting on it — and a note filed in a record nobody opens is
 * the same as no note at all.
 *
 * Deliberately narrow. Nothing is sent when work is finished, and nothing when
 * it is planned. Only the miss travels, because a channel that carries
 * everything carries nothing.
 */
class CommitmentReporter
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /**
     * Move an unfinished commitment to another day.
     *
     * A link to its replacement rather than a flag, so "this has slipped four
     * times" is answerable. A boolean would only ever say it moved once.
     */
    public function carry(AttendanceCommitment $commitment, string $toDate, ?int $userId = null): AttendanceCommitment
    {
        $day = app(AttendanceService::class)->dayFor(
            $commitment->organization_id,
            $commitment->day->employee_id,
            CarbonImmutable::parse($toDate)->toDateString(),
            $userId,
        );

        $next = $day->commitments()->create([
            'organization_id' => $commitment->organization_id,
            'task_id' => $commitment->task_id,
            'title'   => $commitment->title,
            'kind'    => $commitment->kind,
            'position' => (int) $day->commitments()->max('position') + 1,
            // The reason it slipped travels with it, so tomorrow's list shows
            // why this is back rather than looking like a fresh commitment.
            'note'    => $commitment->reason,
            'created_by' => $userId,
        ]);

        $commitment->update(['carried_to_id' => $next->id]);

        return $next;
    }

    /**
     * Tell whoever is waiting.
     *
     * The line manager, because they are accountable for the day's work — and
     * whoever owns the task if it is somebody else, because they are the one
     * actually held up by it.
     */
    public function announce(AttendanceCommitment $commitment): void
    {
        $employee = $commitment->day->employee;

        if ($employee === null) {
            return;
        }

        $recipients = array_filter([
            $employee->reports_to,
            // Only when it is not the person who reported it — telling somebody
            // what they just typed is the sort of notification people mute.
            $commitment->task?->assigned_to !== $employee->id
                ? $commitment->task?->assigned_to
                : null,
        ]);

        foreach (array_unique($recipients) as $employeeId) {
            $userId = Employee::whereKey($employeeId)->value('user_id');

            if ($userId === null) {
                continue;
            }

            $this->notifications->create(
                (string) $commitment->organization_id,
                $userId,
                'attendance.commitment_missed',
                $employee->full_name . ' did not finish ' . $commitment->title,
                $commitment->reason,
                [
                    'commitment_id' => $commitment->id,
                    'task_id'  => $commitment->task_id,
                    'work_date' => $commitment->day->work_date->toDateString(),
                    'carried'  => $commitment->carried_to_id !== null,
                ],
                AttendanceCommitment::class,
                (string) $commitment->id,
            );
        }
    }
}
