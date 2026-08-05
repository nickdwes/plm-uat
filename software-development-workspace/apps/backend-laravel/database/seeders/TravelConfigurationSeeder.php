<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Travel\TravelAllowanceRate;
use App\Models\Travel\TravelApprovalStage;
use App\Models\Travel\TravelDestination;
use App\Models\Travel\TravelExpenseCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A starting travel configuration for one organisation.
 *
 * Without this an organisation cannot use the module at all: no rate means
 * nothing costs, and no active approval stage means submission is refused. It
 * exists so a tenant can be switched on in one command rather than by clicking
 * through four settings tabs before anything works.
 *
 * The figures are a starting point, not a policy. They are deliberately round
 * numbers that finance will want to change, rather than plausible ones that
 * might be left in place by accident.
 *
 *   TRAVEL_SEED_ORG=konvergenz php artisan db:seed --class=TravelConfigurationSeeder
 *
 * An environment variable rather than a command option: artisan validates its
 * own options and rejects unknown ones, so a --org flag never reaches here.
 *
 * Idempotent: re-running updates the same rows rather than duplicating them,
 * so it is safe against an organisation that has already been part-configured.
 */
class TravelConfigurationSeeder extends Seeder
{
    /**
     * Evidence policy per category.
     *
     * Who supplies what is the part that matters. A traveller has the taxi
     * receipt because they took the ride; finance has the airline invoice
     * because they bought the ticket and the traveller never sees one. A per
     * diem has no document at all, only confirmation that it arrived.
     */
    private const CATEGORIES = [
        // name, evidence_type, evidence_provider, rate, unit
        ['Accommodation', 'receipt',         'requestor', 12000, 'per_day'],
        ['Per diem',      'acknowledgement', 'requestor',  4500, 'per_day'],
        ['Incidentals',   'receipt',         'either',     5000, 'per_trip'],
    ];

    /*
     * Transport and flights are deliberately absent.
     *
     * Both are answered by a transport leg — distance, fuel, mode, ticket —
     * rather than by a grade allowance. Seeding them here would recreate the
     * double-count the transport calculator exists to remove.
     *
     * What is left is what should be grade-priced: a unit head does get a
     * better hotel; they do not get a longer road.
     */

    private const DESTINATIONS = [
        ['Nairobi',  'KE', false],
        ['Mombasa',  'KE', false],
        ['Kisumu',   'KE', false],
        ['Nakuru',   'KE', false],
        ['Kampala',  'UG', true],
    ];

    /**
     * The chain, as described: head of department, chief manager of the
     * function, finance assessment, then the CFO before disbursement.
     *
     * The middle and last stages carry thresholds so a short domestic trip does
     * not collect four signatures — sending trivial amounts through the full
     * chain is what makes approval processes get bypassed informally.
     *
     * The first stage deliberately has no threshold. A configuration where
     * every stage has one can resolve to nothing for a small request, which
     * would let it approve itself.
     */
    private const STAGES = [
        ['department_head', 1, null],
        ['function_head',   2, 100000],
        ['finance',         3, null],
        ['executive',       4, 250000],
    ];

    public function run(): void
    {
        $slug = env('TRAVEL_SEED_ORG', 'konvergenz');
        $org  = Organization::where('slug', $slug)->first();

        if (! $org) {
            $this->command->error("No organisation with slug '{$slug}'.");
            return;
        }

        $this->command->info("Configuring travel for {$org->name}…");

        foreach (self::DESTINATIONS as [$name, $country, $international]) {
            TravelDestination::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $org->id, 'name' => $name],
                ['country' => $country, 'is_international' => $international, 'is_active' => true],
            );
        }
        $this->command->line('  destinations: ' . count(self::DESTINATIONS));

        foreach (self::CATEGORIES as [$name, $evidenceType, $provider, $amount, $unit]) {
            $category = TravelExpenseCategory::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $org->id, 'name' => $name],
                [
                    'evidence_type'     => $evidenceType,
                    'evidence_provider' => $provider,
                    'requires_receipt'  => $evidenceType === 'receipt',
                    'is_active'         => true,
                ],
            );

            // A default rate applying to every grade and destination. Nulls
            // mean "applies to all", so finance can add specific overrides
            // above it without restating this one.
            TravelAllowanceRate::withoutGlobalScopes()->updateOrCreate(
                [
                    'organization_id'            => $org->id,
                    'travel_expense_category_id' => $category->id,
                    'job_grade_id'               => null,
                    'travel_destination_id'      => null,
                    'effective_to'               => null,
                ],
                [
                    'amount'         => $amount,
                    'currency'       => 'KES',
                    'unit'           => $unit,
                    'effective_from' => now()->startOfYear()->toDateString(),
                ],
            );
        }
        $this->command->line('  categories and default rates: ' . count(self::CATEGORIES));

        foreach (self::STAGES as [$stage, $sequence, $threshold]) {
            TravelApprovalStage::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $org->id, 'stage' => $stage],
                [
                    'sequence'              => $sequence,
                    'required_above_amount' => $threshold,
                    'currency'              => 'KES',
                    'is_active'             => true,
                ],
            );
        }
        $this->command->line('  approval stages: ' . count(self::STAGES));

        $this->warnAboutRouting($org);
    }

    /**
     * Configuration alone does not make the chain work.
     *
     * The structural stages resolve their approver from the organisation
     * chart, and a stage with nobody to route to leaves requests sitting in a
     * queue that does not exist — with no error anywhere.
     */
    private function warnAboutRouting(Organization $org): void
    {
        $leaderless = DB::table('departments')
            ->where('organization_id', $org->id)
            ->whereNull('lead_employee_id')
            ->count();

        $unlinked = DB::table('departments')
            ->where('organization_id', $org->id)
            ->whereNull('functional_unit_id')
            ->count();

        if ($leaderless > 0) {
            $this->command->warn("  {$leaderless} department(s) have no lead — requests from them stall at stage 1.");
        }

        if ($unlinked > 0) {
            $this->command->warn("  {$unlinked} department(s) have no functional unit — stage 2 cannot resolve for them.");
        }
    }
}
