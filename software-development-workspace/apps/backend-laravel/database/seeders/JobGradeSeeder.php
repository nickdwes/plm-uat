<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The grade ladder an organisation pays and promotes against.
 *
 * Grades are not a travel concept — payroll bands, designations and travel
 * allowances all hang off them — but travel is where their absence first
 * bites: without grades, every rate applies to everyone, and a trainee is
 * entitled to the same hotel as the CFO.
 *
 *   TRAVEL_SEED_ORG=konvergenz php artisan db:seed --class=JobGradeSeeder
 *
 * Idempotent by name, so re-running does not duplicate and does not overwrite
 * salary bands somebody has since set.
 */
class JobGradeSeeder extends Seeder
{
    /** name, code, level — level orders the ladder and drives rate specificity. */
    private const GRADES = [
        ['Trainee',                  'G1', 1],
        ['Engineer',                 'G2', 2],
        ['Senior Engineer / Manager','G3', 3],
        ['Unit Head',                'G4', 4],
        ['C-Suite',                  'G5', 5],
    ];

    public function run(): void
    {
        $slug = env('TRAVEL_SEED_ORG', 'konvergenz');
        $org  = Organization::where('slug', $slug)->first();

        if (! $org) {
            $this->command->error("No organisation with slug '{$slug}'.");
            return;
        }

        $this->command->info("Job grades for {$org->name}…");

        foreach (self::GRADES as [$name, $code, $level]) {
            $existing = DB::table('job_grades')
                ->where('organization_id', $org->id)
                ->where('name', $name)
                ->first();

            if ($existing) {
                // Level may have been reordered deliberately; leave salary
                // bands and anything else alone.
                $this->command->line("  {$name} — already present");
                continue;
            }

            DB::table('job_grades')->insert([
                'organization_id' => $org->id,
                'name'            => $name,
                'code'            => $code,
                'level'           => $level,
                'currency'        => 'KES',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $this->command->line("  {$name} (level {$level})");
        }

        $this->command->warn(
            '  Allowance rates still apply to every grade until grade-specific '
            . 'ones are added. A rate with no grade is the fallback, not a gap.'
        );
    }
}
