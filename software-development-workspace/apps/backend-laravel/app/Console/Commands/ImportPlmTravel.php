<?php

namespace App\Console\Commands;

use App\Models\Account\Account;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\PlmImportMap;
use App\Models\Travel\TravelApproval;
use App\Models\Travel\TravelDestination;
use App\Models\Travel\TravelRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring the travel requests across from the old PLM.
 *
 * A hundred and two requests, and — the reason for importing them — the
 * four-stage approval trail underneath: who decided, when, and what they said.
 * The point is not to have the records; it is to be able to look at how the
 * process actually ran before changing it.
 *
 * ── Decisions are recorded, not replayed ──
 *
 * Every approval arrives already decided, with the date and comment the
 * approver gave. Nothing is pushed through the live workflow: that would email
 * a head of department about a trip taken in 2024, and would stamp today's
 * date on a decision made eighteen months ago — destroying the one thing these
 * records are being imported for.
 *
 * ── A stage nobody ever used ──
 *
 * All 102 requests sit Pending at the second stage, while Finance approved 18
 * and the CFO 6. The stage was in the chain and was never once actioned, so
 * requests went round it. That is imported exactly as it stands: a pending
 * approval that was skipped in practice is the finding, and smoothing it into
 * "approved" or dropping the row would erase it.
 */
class ImportPlmTravel extends Command
{
    protected $signature = 'plm:import-travel
        {--org=konvergenz : Organisation slug to import into}
        {--apply : Actually write. Without it, nothing changes}';

    protected $description = 'Import travel requests and their approval trail from the old PLM';

    /**
     * The old chain, as this system names it.
     *
     * Four stages, same order, different words. The old system named them
     * after the office holder — HOD, CTO, Finance, CFO — and this one after
     * the role in the decision, which survives somebody changing job title.
     */
    private const STAGES = [
        'hod'     => ['stage' => 'department_head', 'sequence' => 1],
        'cto'     => ['stage' => 'function_head',   'sequence' => 2],
        'finance' => ['stage' => 'finance',         'sequence' => 3],
        'cfo'     => ['stage' => 'executive',       'sequence' => 4],
    ];

    private const TRAVEL_MODES = [
        'air' => 'air', 'private car' => 'road', 'public transport' => 'road',
    ];

    private bool $apply = false;
    private int $orgId;
    private array $unmapped = [];
    private array $cache = [];

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
            $this->summarise('Destinations', $this->importDestinations());
            $this->summarise('Requests', $this->importRequests());
            $this->reportProcess();
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

    // ── Where people went ─────────────────────────────────────────────────────

    /**
     * The company's real destination list.
     *
     * Twenty-five places against the five seeded here as examples. These are
     * reference data rather than records — the towns people actually travel to
     * and the tier each is paid at — so the missing ones are created rather
     * than left for every request to fail to match. Whether somewhere is
     * international comes from its tier, which is the only place the old
     * system recorded it.
     */
    private function importDestinations(): array
    {
        $rows = $this->legacy()->table('mdl_travel_r_destionations as d')
            ->leftJoin('mdl_travel_r_d_tiers as t', 't.id', '=', 'd.tier')
            ->orderBy('d.id')
            ->get(['d.id', 'd.name', 't.name as tier']);

        $made = 0;
        $matched = 0;

        foreach ($rows as $row) {
            $name = $this->text($row->name);

            if (! $name) {
                continue;
            }

            $international = str_contains(mb_strtolower((string) $row->tier), 'international');

            $existing = TravelDestination::where('organization_id', $this->orgId)
                ->whereRaw('lower(trim(name)) = ?', [mb_strtolower(trim($name))])
                ->first();

            if ($existing) {
                $matched++;
            } else {
                $existing = TravelDestination::create([
                    'organization_id'  => $this->orgId,
                    'name'             => $name,
                    'country'          => $international ? null : 'KE',
                    'is_international' => $international,
                    'is_active'        => true,
                ]);

                $made++;
            }

            PlmImportMap::updateOrCreate(
                ['organization_id' => $this->orgId, 'source_table' => 'mdl_travel_r_destionations',
                 'source_id' => $row->id],
                ['target_type' => TravelDestination::class, 'target_id' => $existing->id,
                 'source_snapshot' => ['name' => $row->name, 'tier' => $row->tier]],
            );
        }

        return ['read' => $rows->count(), 'created' => $made, 'already here' => $matched];
    }

    // ── The requests ──────────────────────────────────────────────────────────

    private function importRequests(): array
    {
        $rows = $this->legacy()->table('mdl_travel_r')->orderBy('id')->get();

        $made = 0;
        $updated = 0;
        $noTraveller = 0;
        $noCost = 0;

        foreach ($rows as $row) {
            $employee = $this->employee($row->employee);

            if (! $employee) {
                /*
                 * The traveller works for one of the companies not imported.
                 * A travel request with no traveller is not a record of
                 * anything, and employee_id is required — so it is named
                 * rather than attached to somebody plausible.
                 */
                $this->unmapped['held back']["Request {$row->id} — its traveller was not imported"] = 1;
                $noTraveller++;

                continue;
            }

            $destination = PlmImportMap::resolve($this->orgId, 'mdl_travel_r_destionations', $row->destination);
            $departure   = $this->date($row->from);
            $return      = $this->date($row->to);

            if (! $departure || ! $return) {
                $this->unmapped['held back']["Request {$row->id} — no travel dates"] = 1;

                continue;
            }

            $cost = is_numeric($row->travel_cost) ? (float) $row->travel_cost : null;

            if ($cost === null) {
                $noCost++;
            }

            $existing = PlmImportMap::resolve($this->orgId, 'mdl_travel_r', $row->id);
            $request  = ($existing ? TravelRequest::find($existing->target_id) : null) ?? new TravelRequest();

            $request->forceFill(array_filter([
                'organization_id'       => $this->orgId,
                'reference'             => $request->reference ?: 'TR-PLM-' . str_pad((string) $row->id, 5, '0', STR_PAD_LEFT),
                'employee_id'           => $employee,
                'account_id'            => $this->account($row->client),
                'travel_destination_id' => $destination?->target_id,
                'travel_mode'           => $this->mode($row->travel_mode),
                'travel_type'           => $this->travelType($destination?->target_id),
                'departure_date'        => $departure,
                'return_date'           => $return,
                'days'                  => $this->days($departure, $return),
                'purpose'               => $this->text($row->travel_reason),
                'business_impact'       => $this->text($row->misc),
                'currency'              => 'KES',
                /*
                 * Thirty-five requests carry no cost at all. Zero would read as
                 * a trip that cost nothing, so the claim is left at zero only
                 * because the column demands a number — and the count is
                 * reported, because "a third of requests had no figure" is
                 * itself a fact about the process.
                 */
                'total_claimed'         => $cost ?? 0,
                'status'                => $this->status($row),
                'submitted_at'          => $this->date($row->created_at),
                'completed_at'          => $this->completedAt($row),
                'created_at'            => $this->date($row->created_at) ?? now(),
                'accommodation_needed'  => false,
                'flexible_days'         => false,
            ], fn ($v) => $v !== null));

            $request->save();

            if ($existing) {
                $updated++;
            } else {
                $made++;

                PlmImportMap::create([
                    'organization_id' => $this->orgId, 'source_table' => 'mdl_travel_r',
                    'source_id' => $row->id, 'target_type' => TravelRequest::class,
                    'target_id' => $request->id,
                    'source_snapshot' => ['employee' => $row->employee, 'status' => $row->status,
                                          'cost' => $row->travel_cost],
                ]);
            }

            $this->recordApprovals($request, $row);
        }

        return ['read' => $rows->count(), 'created' => $made, 'updated' => $updated,
                'traveller not here' => $noTraveller, 'no cost recorded' => $noCost];
    }

    /**
     * The four decisions, exactly as they were made.
     *
     * Written straight into the approval rows rather than pushed through the
     * service that decides them: that service stamps the current time, moves
     * the request on, and notifies the next approver. All three would be wrong
     * for a decision made eighteen months ago, and the timestamps are the whole
     * point of importing this.
     */
    private function recordApprovals(TravelRequest $request, object $row): void
    {
        $submitted = $this->date($row->created_at);

        foreach (self::STAGES as $prefix => $stage) {
            $status = mb_strtolower(trim((string) $row->{"{$prefix}_status"}));

            $decidedAt = $status === 'pending'
                ? null
                : $this->date($row->{"{$prefix}_approval_date"});

            TravelApproval::updateOrCreate(
                ['travel_request_id' => $request->id, 'stage' => $stage['stage']],
                [
                    'sequence'               => $stage['sequence'],
                    'status'                 => $this->approvalStatus($status),
                    'comment'                => $this->text($row->{"{$prefix}_comment"}),
                    'decided_by_employee_id' => $this->employee($row->{$prefix}),
                    'decided_at'             => $decidedAt,
                ],
            );

            if ($decidedAt && $submitted
                && substr($decidedAt, 0, 10) === substr($submitted, 0, 10)) {
                $this->sameDayDecisions++;
            }

            $this->stageTally[$stage['stage']][$this->approvalStatus($status)] =
                ($this->stageTally[$stage['stage']][$this->approvalStatus($status)] ?? 0) + 1;
        }
    }

    private array $stageTally = [];

    private function approvalStatus(string $legacy): string
    {
        return match ($legacy) {
            'approved' => 'approved',
            'declined', 'rejected' => 'rejected',
            default    => 'pending',
        };
    }

    // ── Reading the request's own state ───────────────────────────────────────

    /**
     * Where the request got to.
     *
     * Derived from the decisions rather than trusted from the old status
     * column, which says "Processing" for seventy-one requests including ones
     * the CFO signed off. A rejection anywhere ends it; every stage approved
     * means approved; anything else is still somewhere in the chain.
     */
    private function status(object $row): string
    {
        $decisions = array_map(
            fn (string $prefix) => $this->approvalStatus(mb_strtolower(trim((string) $row->{"{$prefix}_status"}))),
            array_keys(self::STAGES),
        );

        if (in_array('rejected', $decisions, true)) {
            return 'rejected';
        }

        return in_array('pending', $decisions, true) ? 'in_approval' : 'approved';
    }

    private function completedAt(object $row): ?string
    {
        return $this->status($row) === 'in_approval'
            ? null
            : $this->date($row->cfo_approval_date ?? $row->finance_approval_date);
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

    private function account(mixed $legacyId): ?int
    {
        $map = PlmImportMap::resolve($this->orgId, 'mdl_accounts', $legacyId);

        return $map ? Account::whereKey($map->target_id)->value('id') : null;
    }

    private function mode(mixed $legacyId): ?string
    {
        $name = mb_strtolower((string) $this->legacyName('mdl_travel_r_travel_mode', $legacyId));

        if (! $name) {
            return null;
        }

        if (! isset(self::TRAVEL_MODES[$name])) {
            $this->unmapped['travel mode'][$name] = ($this->unmapped['travel mode'][$name] ?? 0) + 1;

            return null;
        }

        return self::TRAVEL_MODES[$name];
    }

    /** Local or international, from the destination rather than guessed. */
    private function travelType(?int $destinationId): string
    {
        if (! $destinationId) {
            return 'local';
        }

        return TravelDestination::whereKey($destinationId)->value('is_international')
            ? 'international' : 'local';
    }

    private function days(?string $from, ?string $to): int
    {
        if (! $from || ! $to) {
            return 1;
        }

        // Inclusive of both ends: a trip out and back on one day is a day.
        return max(1, (int) \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) + 1);
    }

    // ── What the trail says about the process ─────────────────────────────────

    /**
     * The point of the exercise, printed where somebody will read it.
     *
     * A stage that never once decided anything is the sort of thing that is
     * obvious in a tally and invisible in a hundred and two individual records.
     */
    private function reportProcess(): void
    {
        if (! $this->stageTally) {
            return;
        }

        $this->newLine();
        $this->line('  <options=bold>How the chain actually ran</>');

        foreach (self::STAGES as $stage) {
            $counts = $this->stageTally[$stage['stage']] ?? [];
            $parts = [];

            foreach (['approved', 'rejected', 'pending'] as $state) {
                if (! empty($counts[$state])) {
                    $parts[] = "{$counts[$state]} {$state}";
                }
            }

            $line = '    ' . str_pad($stage['stage'], 18) . implode(', ', $parts);

            $this->line(empty($counts['approved']) && empty($counts['rejected'])
                ? "<fg=yellow>{$line}  ← never decided anything</>"
                : $line);
        }

        /*
         * A limit on what any analysis of this can say, stated once here
         * rather than discovered by somebody computing a negative turnaround.
         */
        if ($this->sameDayDecisions > 0) {
            $this->newLine();
            $this->line("    <fg=yellow>Approval dates in the old system carry no time of day, so the "
                . "{$this->sameDayDecisions} decisions</>");
            $this->line('    <fg=yellow>made on the day of the request read as midnight — earlier than the '
                . 'request itself.</>');
            $this->line('    <fg=yellow>Turnaround can be measured in days, never in hours.</>');
        }
    }

    private int $sameDayDecisions = 0;

    // ── Shared ────────────────────────────────────────────────────────────────

    private function legacyName(string $table, mixed $id): ?string
    {
        if (! $id || ! is_numeric($id)) {
            return null;
        }

        $key = "{$table}|{$id}";

        return $this->cache[$key] ??= ($this->legacy()->table($table)
            ->where('id', (int) $id)->value('name') ?: null);
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return ($value === '' || mb_strtoupper($value) === 'NULL') ? null : $value;
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
            $this->warn($what === 'held back' ? 'Held back:' : "No {$what} here by that name — left unset:");

            arsort($values);

            foreach (array_slice($values, 0, 15, true) as $value => $count) {
                $this->line($what === 'held back' ? "  · {$value}" : "  · {$value} — {$count}");
            }
        }
    }
}
