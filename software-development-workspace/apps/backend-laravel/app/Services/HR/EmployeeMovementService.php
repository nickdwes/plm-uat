<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\EmployeeMovement;
use App\Models\EmployeeOrganizationMap;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moving somebody from one organisation to another.
 *
 * One mechanism, two outcomes. A secondment and a transfer both write a
 * placement in the destination; they differ only in whether the home
 * membership ends and the primary organisation follows. Building them
 * separately would mean two answers to every question underneath — what
 * happens to the reporting line, the roles, the leave balance — and the two
 * answers would drift.
 *
 * ── Nothing is deleted ──
 *
 * A placement is closed, never removed. Role grants are revoked with a reason,
 * never re-pointed at the new organisation: `user_roles` carries `revoked_at`,
 * `revoked_by` and `grant_reason` precisely so "who had access to Konvergenz
 * last March" survives somebody leaving it. Repointing the row would answer
 * that question wrongly and leave no trace that it ever said otherwise.
 *
 * ── The destination decides nothing about the past ──
 *
 * Payroll runs, leave taken, tickets worked and attendance stay with the
 * organisation where the work happened. The new organisation sees no history,
 * and that is correct: repointing them would make last year's payroll for one
 * company silently become another's.
 */
class EmployeeMovementService
{
    public function __construct(private readonly TransferPreflight $preflight) {}

    /**
     * A placement in another organisation, with the home one continuing.
     */
    public function second(
        Employee $employee,
        Organization $destination,
        array $data,
        ?User $actor = null,
    ): EmployeeMovement {
        $this->refuseSameOrganisation($employee, $destination);

        return DB::transaction(function () use ($employee, $destination, $data, $actor) {
            $this->openPlacement($employee, $destination, $data);

            $employee->forceFill(['is_shared' => true])->save();

            return $this->record($employee, $employee->primary_organization_id, $destination->id,
                EmployeeMovement::SECONDMENT, $data, $actor, carried: []);
        });
    }

    /**
     * A permanent move: the home membership ends and the primary follows.
     *
     * @throws RuntimeException when something in the old organisation still
     *                          depends on them and `force` was not given.
     */
    public function transfer(
        Employee $employee,
        Organization $destination,
        array $data,
        ?User $actor = null,
    ): EmployeeMovement {
        $this->refuseSameOrganisation($employee, $destination);

        $from      = $employee->primary_organization_id;
        $on        = $data['effective_on'];
        $preflight = $this->preflight->run($employee, $from);

        /*
         * Refusing is the whole point of the check. A transfer that goes ahead
         * over an unrecovered loan or a live approval route does damage nobody
         * sees for a month — and by then the person to ask has moved.
         */
        if (! $preflight['clear'] && ! ($data['force'] ?? false)) {
            throw new RuntimeException(
                'Some things in ' . (Organization::find($from)?->name ?? 'their current organisation')
                . ' still depend on this person. Deal with those first, or say explicitly that '
                . 'you are moving them anyway.'
            );
        }

        return DB::transaction(function () use ($employee, $destination, $data, $actor, $from, $on, $preflight) {
            $this->closeHomePlacement($employee, $from, $on);
            $this->openPlacement($employee, $destination, $data);

            $carried = $this->rehomeEmployee($employee, $destination, $on);
            $carried['leave'] = $this->carryLeaveBalances($employee, $from, $destination->id);
            $carried['roles'] = $this->revokeRoles($employee, $from, $actor, $data['reason'] ?? null);

            /*
             * Being listed in an organisation is not being able to see it.
             * Access comes from `user_roles` and nothing here grants any, which
             * is deliberate — permissions should never be acquired as a side
             * effect of an HR action. Said out loud because otherwise the first
             * person to try signing in reads it as a bug.
             */
            $carried['access_note'] = 'No roles were granted in ' . $destination->name
                . '. Until somebody grants one, this person cannot see their new organisation.';

            return $this->record($employee, $from, $destination->id,
                EmployeeMovement::TRANSFER, $data, $actor, $carried, $preflight);
        });
    }

    // ── The pieces ────────────────────────────────────────────────────────────

    private function openPlacement(Employee $employee, Organization $destination, array $data): void
    {
        EmployeeOrganizationMap::updateOrCreate(
            [
                'employee_id'     => $employee->id,
                'organization_id' => $destination->id,
                'effective_from'  => $data['effective_on'],
            ],
            [
                'designation_id' => $data['designation_id'] ?? null,
                'department_id'  => $data['department_id'] ?? null,
                'reports_to'     => $data['reports_to'] ?? null,
                'access_scope'   => $data['access_scope'] ?? 'operational',
                'role'           => $data['role'] ?? null,
                'engagement_type' => $data['engagement_type'] ?? null,
                'allocation_pct' => $data['allocation_pct'] ?? 100,
                'is_billable'    => $data['is_billable'] ?? false,
                'effective_to'   => $data['effective_to'] ?? null,
                'is_active'      => true,
                'notes'          => $data['reason'] ?? null,
            ],
        );
    }

    /**
     * End their membership of the organisation they are leaving.
     *
     * Nobody has a placement row for their own primary organisation — the map
     * was only ever written for secondments — so one is created and closed in
     * the same breath. Without it there is no record that they ever worked
     * there, which is the question the table exists to answer.
     */
    private function closeHomePlacement(Employee $employee, int $fromOrganizationId, string $on): void
    {
        $existing = EmployeeOrganizationMap::where('employee_id', $employee->id)
            ->where('organization_id', $fromOrganizationId)
            ->where('is_active', true)
            ->get();

        if ($existing->isEmpty()) {
            EmployeeOrganizationMap::create([
                'employee_id'     => $employee->id,
                'organization_id' => $fromOrganizationId,
                'designation_id'  => $employee->designation_id,
                'department_id'   => $employee->department_id,
                'reports_to'      => $employee->reports_to,
                'access_scope'    => 'operational',
                'allocation_pct'  => 100,
                'effective_from'  => $employee->date_hired?->toDateString() ?? $on,
                'effective_to'    => $on,
                'is_active'       => false,
                'notes'           => 'Backfilled when they transferred out.',
            ]);

            return;
        }

        $existing->each(fn (EmployeeOrganizationMap $row) => $row->close($on));
    }

    /**
     * Point the employee at their new organisation's own structure.
     *
     * Departments, designations and job grades are all per-organisation and
     * unique by code within one. Carrying the old ids across would leave
     * somebody in Tiberbu filed under a Konvergenz department — no foreign key
     * would object, and the screens would quietly show another company's
     * structure.
     *
     * Where the destination has no counterpart the field is left empty and
     * named in the result. A guessed department is worse than a blank one.
     */
    private function rehomeEmployee(Employee $employee, Organization $destination, string $on): array
    {
        $notes = [];

        $department = $this->counterpart('departments', $employee->department_id, $destination->id, 'code');
        $grade      = $this->counterpart('job_grades', $employee->job_grade_id, $destination->id, 'code');
        $title      = $this->counterpart('designations', $employee->designation_id, $destination->id, 'title');

        foreach ([
            'department' => [$employee->department_id, $department],
            'job grade'  => [$employee->job_grade_id, $grade],
            'designation' => [$employee->designation_id, $title],
        ] as $label => [$had, $found]) {
            if ($had && ! $found) {
                $notes[] = "Their {$label} has no counterpart in {$destination->name} and was left unset.";
            }
        }

        /*
         * The reporting line does not survive. Their manager works for the
         * organisation they have left, and a stale one is not merely untidy:
         * manager-based authorisation walks this column, so it would leave
         * somebody at the old company with continuing authority over them.
         */
        if ($employee->reports_to) {
            $notes[] = 'Their reporting line was cleared — their manager works for '
                . (Organization::find($employee->primary_organization_id)?->name ?? 'the old organisation')
                . '. Set a manager in ' . $destination->name . '.';
        }

        $this->moveReportingLine($employee, $destination->id, $on);

        $employee->forceFill([
            'primary_organization_id' => $destination->id,
            'department_id'  => $department,
            'job_grade_id'   => $grade,
            'designation_id' => $title,
            'reports_to'     => null,
        ])->save();

        return ['notes' => $notes];
    }

    /** The equivalent row in another organisation, matched on a stable field. */
    private function counterpart(string $table, ?int $id, int $organizationId, string $on): ?int
    {
        if (! $id) {
            return null;
        }

        $value = DB::table($table)->where('id', $id)->value($on);

        if (! $value) {
            return null;
        }

        return DB::table($table)
            ->where('organization_id', $organizationId)
            ->whereRaw("lower(trim({$on})) = ?", [mb_strtolower(trim((string) $value))])
            ->value('id');
    }

    private function moveReportingLine(Employee $employee, int $destinationId, string $on): void
    {
        DB::table('reporting_lines')
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->update(['is_active' => false, 'effective_to' => $on, 'updated_at' => now()]);
    }

    /**
     * Carry what is left of this year's leave.
     *
     * Balances have no organisation of their own — they take it from the leave
     * type, and leave types are per-organisation. So the balance is carried by
     * matching the type's **code**: the annual leave they have used nine days
     * of becomes nine days used against the destination's annual leave.
     *
     * Not doing this would hand somebody a fresh entitlement for moving desk,
     * and would teach everybody to time a transfer for January.
     *
     * A type the destination does not have is left behind and named. Inventing
     * an entitlement is not this code's business.
     */
    private function carryLeaveBalances(Employee $employee, int $fromOrganizationId, int $toOrganizationId): array
    {
        $year    = (int) now()->format('Y');
        $carried = [];
        $left    = [];

        $balances = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->with('leaveType')
            ->get();

        foreach ($balances as $balance) {
            $type = $balance->leaveType;

            if (! $type || $type->organization_id !== $fromOrganizationId) {
                continue;
            }

            $destination = LeaveType::withoutGlobalScopes()
                ->where('organization_id', $toOrganizationId)
                ->whereRaw('lower(trim(code)) = ?', [mb_strtolower(trim((string) $type->code))])
                ->first();

            if (! $destination) {
                $left[] = $type->name;

                continue;
            }

            LeaveBalance::updateOrCreate(
                ['employee_id' => $employee->id, 'leave_type_id' => $destination->id, 'year' => $year],
                [
                    'entitled_days' => $destination->days_per_year,
                    // What they have already taken this year follows them.
                    'used_days'     => $balance->used_days,
                    'pending_days'  => $balance->pending_days,
                    'carried_days'  => $balance->carried_days,
                ],
            );

            $carried[] = $type->name . ': ' . $balance->used_days . ' days used carried across';
        }

        return ['carried' => $carried, 'left_behind' => $left];
    }

    /**
     * Withdraw their access to the organisation they have left.
     *
     * Revoked, not deleted and not re-pointed. The columns exist so the
     * question "who could see this organisation in March" has an answer, and
     * an update in place answers it wrongly with nothing to show it changed.
     */
    private function revokeRoles(Employee $employee, int $fromOrganizationId, ?User $actor, ?string $reason): array
    {
        if (! $employee->user_id) {
            return ['revoked' => 0];
        }

        $revoked = DB::table('user_roles')
            ->where('user_id', $employee->user_id)
            ->where('organization_id', $fromOrganizationId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at'   => now(),
                'revoked_by'   => $actor?->id,
                'grant_reason' => trim('Transferred out. ' . ($reason ?? '')),
                'updated_at'   => now(),
            ]);

        return ['revoked' => $revoked];
    }

    // ── Shared ────────────────────────────────────────────────────────────────

    private function refuseSameOrganisation(Employee $employee, Organization $destination): void
    {
        if ($employee->primary_organization_id === $destination->id) {
            throw new RuntimeException('That is already their organisation.');
        }
    }

    private function record(
        Employee $employee,
        int $from,
        int $to,
        string $kind,
        array $data,
        ?User $actor,
        array $carried = [],
        ?array $preflight = null,
    ): EmployeeMovement {
        return EmployeeMovement::create([
            'employee_id'          => $employee->id,
            'from_organization_id' => $from,
            'to_organization_id'   => $to,
            'kind'                 => $kind,
            'effective_on'         => $data['effective_on'],
            'reason'               => $data['reason'] ?? null,
            'moved_by'             => $actor?->id,
            'preflight'            => $preflight,
            'carried'              => $carried,
        ]);
    }
}
