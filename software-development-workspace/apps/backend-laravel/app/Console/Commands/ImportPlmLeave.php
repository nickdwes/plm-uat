<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\PlmImportMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring the leave history across from the old PLM.
 *
 * Twelve hundred requests over four years, and the day-by-day record
 * underneath them. Leave is the one module where history is not optional: an
 * entitlement is an annual figure, so a balance calculated without last
 * year's approved leave is wrong from the day it is switched on.
 *
 * ── Days are counted, not calculated ──
 *
 * The old system kept a row per day taken, marked full day, morning or
 * afternoon. That is the record of what somebody actually used, and it already
 * accounts for the weekends, the public holidays and the scattered
 * single-day absences that a start-to-end subtraction gets wrong. Where those
 * rows exist they are the answer; a request with none falls back to counting
 * the range, and those are reported.
 *
 * ── Approvals are recorded, not replayed ──
 *
 * Same rule as the travel requests. Nothing goes through the approval service:
 * it would stamp today's date on a decision made in 2023 and email a manager
 * about leave somebody has long since taken and come back from.
 */
class ImportPlmLeave extends Command
{
    protected $signature = 'plm:import-leave
        {--org=konvergenz : Organisation slug to import into}
        {--apply : Actually write. Without it, nothing changes}';

    protected $description = 'Import leave types and leave requests from the old PLM';

    private const STATUSES = [
        'approved' => LeaveRequest::STATUS_APPROVED,
        'declined' => LeaveRequest::STATUS_REJECTED,
        'rejected' => LeaveRequest::STATUS_REJECTED,
        'pending'  => LeaveRequest::STATUS_PENDING,
        'cancelled' => LeaveRequest::STATUS_CANCELLED,
    ];

    private bool $apply = false;
    private int $orgId;
    private array $unmapped = [];
    private array $cache = [];
    private int $countedFromRange = 0;

    public function handle(): int
    {
        $this->apply = (bool) $this->option('apply');

        $org = Organization::where('slug', $this->option('org'))->first();

        if (! $org) {
            $this->error("No organisation with slug '{$this->option('org')}'.");

            return self::FAILURE;
        }

        $this->orgId = $org->id;
        app()->instance('current.organization_id', $org->id);

        try {
            $this->legacy()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Cannot reach the old PLM database: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line($this->apply
            ? "Importing into <options=bold>{$org->name}</>."
            : "Rehearsing against <options=bold>{$org->name}</>. Nothing will be written — pass --apply to commit.");
        $this->newLine();

        $run = function () {
            $this->summarise('Leave types', $this->importTypes());
            $this->summarise('Requests', $this->importRequests());
        };

        if ($this->apply) {
            DB::transaction($run);
        } else {
            DB::beginTransaction();

            try {
                $run();
            } finally {
                DB::rollBack();
            }
        }

        $this->reportUnmapped();

        return self::SUCCESS;
    }

    // ── The policy ────────────────────────────────────────────────────────────

    /**
     * The eight kinds of leave the company actually grants.
     *
     * Five are already here; study, pre-adoptive and unpaid leave are not, and
     * a request cannot exist without its type. These are policy rather than
     * records — the entitlement in days, whether it accrues, whether weekends
     * count against it — so the missing ones are created with what the old
     * system held.
     */
    private function importTypes(): array
    {
        $rows = $this->legacy()->table('mdl_leave_types')->orderBy('position')->get();

        $made = 0;
        $matched = 0;

        foreach ($rows as $row) {
            $name = $this->text($row->name);

            if (! $name) {
                continue;
            }

            /*
             * Matched on a squashed name. One of ours is stored as
             * "Maternity  Leave" with two spaces, and an exact comparison
             * would create a second maternity leave beside it.
             */
            $existing = LeaveType::where('organization_id', $this->orgId)
                ->get()
                ->first(fn (LeaveType $t) => $this->squash($t->name) === $this->squash($name));

            if ($existing) {
                $matched++;
            } else {
                $existing = LeaveType::create([
                    'organization_id'   => $this->orgId,
                    'name'              => $name,
                    'code'              => $this->uniqueCode($name),
                    'days_per_year'     => is_numeric($row->days) ? (int) $row->days : 0,
                    // Unpaid leave is the one kind that is not paid, and the
                    // old system says so only in its name.
                    'is_paid'           => ! str_contains(mb_strtolower($name), 'unpaid'),
                    'carry_forward'     => (bool) $row->is_earned,
                    'requires_approval' => true,
                    'is_active'         => true,
                    // Weekends count against some kinds and not others, which
                    // is the difference between 14 days of paternity leave and
                    // nearly three working weeks of it.
                    'counting_method'   => $row->includes_weekend ? 'calendar_days' : 'business_days',
                    'allow_half_day'    => true,
                    'allow_scattered'   => true,
                ]);

                $made++;
            }

            PlmImportMap::updateOrCreate(
                ['organization_id' => $this->orgId, 'source_table' => 'mdl_leave_types', 'source_id' => $row->id],
                ['target_type' => LeaveType::class, 'target_id' => $existing->id,
                 'source_snapshot' => ['name' => $row->name, 'days' => $row->days]],
            );
        }

        return ['read' => $rows->count(), 'created' => $made, 'already here' => $matched];
    }

    // ── The requests ──────────────────────────────────────────────────────────

    private function importRequests(): array
    {
        $rows = $this->legacy()->table('mdl_hr_leave')->orderBy('id')->get();

        $made = 0;
        $updated = 0;
        $noEmployee = 0;
        $noType = 0;
        $scattered = 0;
        $undated = 0;
        $this->recovered = 0;

        foreach ($rows as $row) {
            $employee = $this->employee($row->employee);

            if (! $employee) {
                $noEmployee++;

                continue;
            }

            $type = PlmImportMap::resolve($this->orgId, 'mdl_leave_types', $row->leave_type);

            if (! $type) {
                /*
                 * One request names no kind of leave at all. It cannot be
                 * filed under a guess — annual and unpaid are the same number
                 * of days and a very different thing — so it is named.
                 */
                $this->unmapped['held back']["Request {$row->id} names no leave type"] = 1;
                $noType++;

                continue;
            }

            $days = $this->legacy()->table('mdl_leave_days')
                ->where('leave', $row->id)->orderBy('day')->get(['day', 'period']);

            $isScattered = mb_strtolower(trim((string) $row->days_type)) === 'scattered dates';

            if ($isScattered) {
                $scattered++;
            }

            /*
             * A hundred and sixty-seven requests carry 1 January 1970 — the
             * epoch, which is what the old system stored when a date was never
             * set. They are not requests from 1970; they are requests whose
             * dates live only in the day rows underneath, and taking the
             * first and last of those recovers all but seven of them. Without
             * this they would sit in a 1970 bucket, quietly wrong in every
             * leave figure that groups by year.
             */
            [$from, $to] = $this->period($row, $days);

            if (! $from || ! $to) {
                $this->unmapped['held back'][
                    "Request {$row->id} has no usable dates, in its own row or its days"
                ] = 1;
                $undated++;

                continue;
            }

            $existing = PlmImportMap::resolve($this->orgId, 'mdl_hr_leave', $row->id);
            $request  = ($existing ? LeaveRequest::find($existing->target_id) : null) ?? new LeaveRequest();

            $halves = $days->filter(fn ($d) => in_array(mb_strtolower((string) $d->period), ['am', 'pm'], true));

            $request->forceFill(array_filter([
                'organization_id' => $this->orgId,
                'employee_id'     => $employee,
                'leave_type_id'   => $type->target_id,
                'from_date'       => $from,
                'to_date'         => $to,
                'days_requested'  => $this->days($days, $row),
                'reason'          => $this->plain($row->leave_reason),
                'status'          => $this->status($row->status),
                'rejection_reason' => $this->plain($row->decline_reason),
                'approved_by'     => $this->approverFor($row->approved_by_email),
                'actioned_at'     => $this->date($row->approved_at),
                'is_half_day'     => $halves->isNotEmpty() && $days->count() === 1,
                'half_day_period' => $days->count() === 1 && $halves->isNotEmpty()
                    ? mb_strtolower((string) $halves->first()->period) : null,
                /*
                 * The actual dates, for the two hundred and fourteen requests
                 * that are a handful of separate days rather than a stretch.
                 * Without them a Monday and the following Friday read as a
                 * five-day absence.
                 */
                'scattered_dates' => $isScattered && $days->isNotEmpty()
                    ? $days->map(fn ($d) => substr((string) $d->day, 0, 10))->values()->all()
                    : null,
                'created_at'      => $this->date($row->created_at) ?? $this->date($row->start_date),
            ], fn ($v) => $v !== null));

            $request->save();

            if ($existing) {
                $updated++;
            } else {
                $made++;

                PlmImportMap::create([
                    'organization_id' => $this->orgId, 'source_table' => 'mdl_hr_leave',
                    'source_id' => $row->id, 'target_type' => LeaveRequest::class,
                    'target_id' => $request->id,
                    'source_snapshot' => ['employee' => $row->employee, 'status' => $row->status,
                                          'type' => $row->leave_type, 'year' => $row->year],
                ]);
            }
        }

        if ($this->countedFromRange > 0) {
            $this->unmapped['held back'][
                "{$this->countedFromRange} requests have no day-by-day record, so their length was "
                . 'counted from the dates — weekends and holidays included'
            ] = 1;
        }

        if ($this->recovered > 0) {
            $this->unmapped['held back'][
                "{$this->recovered} requests had no dates of their own and were dated from the days taken"
            ] = 1;
        }

        return ['read' => $rows->count(), 'created' => $made, 'updated' => $updated,
                'scattered' => $scattered, 'dated from their days' => $this->recovered,
                'nobody to attach to' => $noEmployee, 'no leave type' => $noType,
                'no usable dates' => $undated];
    }

    /**
     * When the leave was, taken from the request or from the days themselves.
     *
     * The old system wrote the epoch where a date was never set, so a request
     * stamped 1 January 1970 has no date of its own — but its day rows do, and
     * the first and last of them are the leave. Anything before the company
     * existed is treated as absent rather than as a date.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function period(object $row, $days): array
    {
        $from = $this->realDate($row->start_date);
        $to   = $this->realDate($row->end_date);

        if ($from && $to) {
            return [$from, $to];
        }

        $known = $days->map(fn ($d) => $this->realDate($d->day))->filter()->sort()->values();

        if ($known->isEmpty()) {
            return [$from, $to];
        }

        $this->recovered++;

        return [$from ?? $known->first(), $to ?? $known->last()];
    }

    private int $recovered = 0;

    /** A date, unless it is the epoch the old system wrote for "unset". */
    private function realDate(mixed $value): ?string
    {
        $date = $this->date($value);

        return ($date === null || $date < '2000-01-01') ? null : substr($date, 0, 10);
    }

    /**
     * How much leave this actually used.
     *
     * Counted from the rows the old system wrote, one per day, because those
     * already account for the weekends, public holidays and single scattered
     * days that subtracting one date from another gets wrong. A morning or an
     * afternoon is half a day.
     */
    private function days($days, object $row): float
    {
        if ($days->isNotEmpty()) {
            return (float) $days->sum(fn ($d) =>
                in_array(mb_strtolower((string) $d->period), ['am', 'pm'], true) ? 0.5 : 1);
        }

        $this->countedFromRange++;

        $from = $this->date($row->start_date);
        $to   = $this->date($row->end_date);

        if (! $from || ! $to) {
            return 1;
        }

        return (float) max(1, \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) + 1);
    }

    private function status(?string $legacy): string
    {
        $name = mb_strtolower(trim((string) $legacy));

        if (! isset(self::STATUSES[$name])) {
            $this->unmapped['leave status'][$legacy ?: '(blank)'] =
                ($this->unmapped['leave status'][$legacy ?: '(blank)'] ?? 0) + 1;

            return LeaveRequest::STATUS_PENDING;
        }

        return self::STATUSES[$name];
    }

    // ── Resolving ─────────────────────────────────────────────────────────────

    private function employee(mixed $legacyId): ?int
    {
        if (! $legacyId || ! is_numeric($legacyId)) {
            return null;
        }

        $key = "emp|{$legacyId}";

        if (! array_key_exists($key, $this->cache)) {
            $map = PlmImportMap::resolve($this->orgId, 'mdl_hr_employees', $legacyId);

            $this->cache[$key] = $map ? Employee::whereKey($map->target_id)->value('id') : null;
        }

        return $this->cache[$key];
    }

    /**
     * Who approved it, matched on the address the old system stored.
     *
     * It recorded the approver's email rather than their id, which turns out
     * to be the more durable of the two — the id pointed into a user table
     * that only partly came across, and this column wants an employee anyway.
     *
     * An approver at one of the other companies resolves to nothing and the
     * approval keeps its date without a name, rather than the request being
     * dropped: that somebody signed it off is the fact worth keeping.
     */
    private function approverFor(?string $email): ?int
    {
        $email = $this->text($email);

        if (! $email) {
            return null;
        }

        $key = 'appr|' . mb_strtolower($email);

        if (! array_key_exists($key, $this->cache)) {
            $id = Employee::whereRaw('lower(work_email) = ?', [mb_strtolower($email)])->value('id');

            if (! $id) {
                $this->unmapped['approver'][$email] = ($this->unmapped['approver'][$email] ?? 0) + 1;
            }

            $this->cache[$key] = $id;
        }

        return $this->cache[$key];
    }

    /**
     * A short code, and one nobody already holds.
     *
     * Initials are the obvious choice and they collide: "Sick Leave" and
     * "Study Leave" are both SL, as are "Paternity" and "Pre-adoptive". Where
     * that happens the first word gives up another letter — SL becomes STL —
     * rather than a number being tacked on, because these appear on a leave
     * calendar and STL still reads as study leave where SL2 reads as nothing.
     */
    private function uniqueCode(string $name): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));

        if (! $words) {
            return 'LV';
        }

        $tail = implode('', array_map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice($words, 1)));

        for ($take = 1; $take <= mb_strlen($words[0]); $take++) {
            $code = mb_strtoupper(mb_substr($words[0], 0, $take)) . $tail;

            $taken = LeaveType::where('organization_id', $this->orgId)
                ->whereRaw('upper(code) = ?', [$code])->exists();

            if (! $taken) {
                return $code;
            }
        }

        return mb_strtoupper(mb_substr(preg_replace('/\s+/', '', $name) ?? 'LV', 0, 8));
    }

    private function squash(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($value)) ?? '');
    }

    // ── Shared ────────────────────────────────────────────────────────────────

    private function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return ($value === '' || mb_strtoupper($value) === 'NULL') ? null : $value;
    }

    /**
     * A reason as a sentence, not as markup.
     *
     * The old editor wrote HTML into this field like every other, but a leave
     * reason carries no structure worth keeping — nobody writes a bulleted
     * list explaining why they need Thursday off. Converted here rather than
     * rendered as rich text later, which would be a v-html site earning
     * nothing.
     */
    private function plain(mixed $value): ?string
    {
        $raw = $this->text($value);

        if ($raw === null) {
            return null;
        }

        // Block ends become spaces, or two paragraphs weld into one word.
        $spaced = preg_replace('#</(p|div|li|br|h[1-6])\s*>|<br\s*/?>#i', ' ', $raw) ?? $raw;

        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->text(preg_replace('/\s+/u', ' ', $text));
    }

    private function date(mixed $value): ?string
    {
        $value = $this->text($value);

        return ($value === null || str_starts_with($value, '0000')) ? null : $value;
    }

    private function legacy(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection('plm');
    }

    private function summarise(string $label, array $counts): void
    {
        $parts = [];

        foreach ($counts as $key => $value) {
            $parts[] = "{$value} {$key}";
        }

        $this->line("  <options=bold>{$label}</>: " . implode(', ', $parts));
    }

    private function reportUnmapped(): void
    {
        if (! $this->unmapped) {
            $this->newLine();
            $this->info('Everything mapped. Nothing was left unset.');

            return;
        }

        foreach ($this->unmapped as $what => $values) {
            $this->newLine();
            $this->warn($what === 'held back' ? 'Worth knowing:' : "No {$what} here by that name:");

            arsort($values);

            foreach (array_slice($values, 0, 15, true) as $value => $count) {
                $this->line($what === 'held back' ? "  · {$value}" : "  · {$value} — {$count}");
            }
        }
    }
}
