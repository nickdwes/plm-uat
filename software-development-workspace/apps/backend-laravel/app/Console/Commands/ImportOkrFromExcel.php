<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\OkrCycle;
use App\Models\OkrKeyResult;
use App\Models\OkrKrDeptScore;
use App\Models\OkrObjective;
use App\Models\Organization;
use App\Services\OKR\OkrScoringService;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportOkrFromExcel extends Command
{
    protected $signature = 'okr:import
                            {file? : Path to the Excel file (defaults to /home/ubuntu/logos/KNS OKRs Setting 2026 - Jan26.xlsx)}
                            {--org= : Organisation slug or ID}
                            {--cycle= : OKR cycle name (default: Jan-Dec 2026)}
                            {--year= : Cycle year (default: 2026)}
                            {--dry-run : Preview without writing to DB}';

    protected $description = 'Import OKR objectives, key results and department scores from an Excel file';

    public function handle(OkrScoringService $scoring): int
    {
        $file = $this->argument('file')
            ?? '/home/ubuntu/logos/KNS OKRs Setting 2026 - Jan26.xlsx';

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        // ── Resolve organisation ──────────────────────────────────────────────
        $orgInput = $this->option('org');
        $org      = $orgInput
            ? Organization::where('slug', $orgInput)->first()
            : Organization::first();

        if (! $org) {
            $this->error('Organisation not found. Use --org=slug.');
            return 1;
        }

        $this->info("Organisation: {$org->name} (id={$org->id})");
        $isDry = $this->option('dry-run');

        // ── Load spreadsheet ──────────────────────────────────────────────────
        $this->info("Loading: {$file}");
        $spreadsheet = IOFactory::load($file);
        $sheet       = $spreadsheet->getActiveSheet();

        $rows = $sheet->toArray(null, true, true, true); // column letters as keys

        if (empty($rows)) {
            $this->error('Spreadsheet is empty.');
            return 1;
        }

        // ── Detect header row ─────────────────────────────────────────────────
        // Look for the row containing "OBJECTIVES" or "KNS KEY RESULTS"
        $headerRow  = null;
        $headerIdx  = null;
        foreach ($rows as $rowNum => $row) {
            $vals = array_map('strtolower', array_map(fn($v) => trim((string)($v ?? '')), $row));
            if (in_array('objectives', $vals) || in_array('kns key results', $vals)) {
                $headerRow = $row;
                $headerIdx = $rowNum;
                break;
            }
        }

        if (! $headerRow) {
            $this->error('Could not find header row (looking for "OBJECTIVES" or "KNS KEY RESULTS").');
            return 1;
        }

        $this->info("Header row found at row #{$headerIdx}");

        // ── Map column letters to semantic names ──────────────────────────────
        $colObjective   = null;
        $colObjWeight   = null;
        $colKeyResult   = null;
        $colKrWeight    = null;
        $colMeasure     = null;
        $colAchievement = null;
        $colScore       = null;
        $deptCols       = []; // ['col_letter' => 'dept_name']

        foreach ($headerRow as $col => $val) {
            $v = strtolower(trim((string)($val ?? '')));
            if (str_contains($v, 'objective') && ! str_contains($v, 'weighting') && ! $colObjective) {
                $colObjective = $col;
            } elseif ((str_contains($v, 'objective') && str_contains($v, 'weight')) || $v === 'organizational objective weighting') {
                $colObjWeight = $col;
            } elseif (str_contains($v, 'key result') || str_contains($v, 'kns key result')) {
                $colKeyResult = $col;
            } elseif ($v === 'kr weight' || $v === 'kr weighting') {
                $colKrWeight = $col;
            } elseif ($v === 'measure') {
                $colMeasure = $col;
            } elseif (str_contains($v, 'achievement')) {
                $colAchievement = $col;
            } elseif (str_contains($v, 'score') && str_contains($v, '%')) {
                $colScore = $col;
            } elseif (! empty($v) && ! in_array($v, ['', 'average', 'weighted', 'dept', 'departmental'])) {
                // Remaining non-empty, non-formula columns = department columns
                if (! in_array($col, array_filter([$colObjective, $colObjWeight, $colKeyResult,
                    $colKrWeight, $colMeasure, $colAchievement, $colScore]))) {
                    $deptCols[$col] = trim((string)($val ?? ''));
                }
            }
        }

        $this->info("Columns detected:");
        $this->line("  Objective: {$colObjective}, ObjWeight: {$colObjWeight}");
        $this->line("  KeyResult: {$colKeyResult}, KrWeight: {$colKrWeight}");
        $this->line("  Measure: {$colMeasure}, Achievement: {$colAchievement}");
        $this->line("  Dept cols: " . implode(', ', array_map(fn($c, $n) => "{$c}={$n}", array_keys($deptCols), $deptCols)));

        if (! $colKeyResult) {
            $this->error('Could not detect Key Result column.');
            return 1;
        }

        // ── Resolve departments from DB ───────────────────────────────────────
        $allDepts  = Department::where('organization_id', $org->id)->get()->keyBy(fn($d) => strtolower(trim($d->name)));
        $deptIdMap = []; // col_letter → dept_id

        foreach ($deptCols as $col => $name) {
            $key  = strtolower(trim($name));
            $dept = $allDepts[$key] ?? null;
            if ($dept) {
                $deptIdMap[$col] = $dept->id;
                $this->line("  Matched dept '{$name}' → id={$dept->id}");
            } else {
                $this->warn("  Dept '{$name}' not found in DB — will be skipped.");
            }
        }

        // ── Create or find cycle ──────────────────────────────────────────────
        $cycleName = $this->option('cycle') ?? 'Jan-Dec 2026';
        $cycleYear = (int)($this->option('year') ?? 2026);

        if (! $isDry) {
            $cycle = OkrCycle::firstOrCreate(
                ['organization_id' => $org->id, 'name' => $cycleName],
                ['year' => $cycleYear, 'period' => 'annual', 'status' => 'active']
            );
            $this->info("Cycle: '{$cycle->name}' (id={$cycle->id})");
        } else {
            $this->info("[DRY RUN] Would create cycle '{$cycleName}' for {$cycleYear}");
        }

        // ── Parse data rows ───────────────────────────────────────────────────
        $currentObjective = null;
        $currentObjModel  = null;
        $objSort          = 0;
        $krSort           = 0;
        $imported         = ['objectives' => 0, 'key_results' => 0, 'dept_scores' => 0];

        foreach ($rows as $rowNum => $row) {
            if ($rowNum <= $headerIdx) continue;

            $objTitle = $colObjective ? trim((string)($row[$colObjective] ?? '')) : '';
            $krTitle  = $colKeyResult ? trim((string)($row[$colKeyResult] ?? '')) : '';

            // Skip empty rows
            if (empty($objTitle) && empty($krTitle)) continue;

            // ── New objective ─────────────────────────────────────────────────
            if (! empty($objTitle) && $objTitle !== $currentObjective) {
                $currentObjective = $objTitle;
                $objWeight        = $colObjWeight ? (float)($row[$colObjWeight] ?? 1) : 1;
                if ($objWeight === 0.0) $objWeight = 1;
                $krSort = 0;

                $this->line("  OBJECTIVE [{$objSort}]: {$objTitle} (weight={$objWeight})");

                if (! $isDry) {
                    $currentObjModel = OkrObjective::firstOrCreate(
                        ['cycle_id' => $cycle->id, 'title' => $objTitle],
                        [
                            'organization_id' => $org->id,
                            'weight'          => $objWeight,
                            'sort_order'      => $objSort,
                        ]
                    );
                }
                $objSort++;
                $imported['objectives']++;
            }

            // ── Key Result ────────────────────────────────────────────────────
            if (! empty($krTitle) && $currentObjModel !== null || ($isDry && ! empty($krTitle))) {
                $krWeight    = $colKrWeight    ? (float)($row[$colKrWeight]    ?? 1) : 1;
                $measure     = $colMeasure     ? trim((string)($row[$colMeasure]     ?? '')) : null;
                $achievement = $colAchievement ? ($row[$colAchievement] !== null && $row[$colAchievement] !== ''
                    ? (float)$row[$colAchievement] : null) : null;

                $this->line("    KR [{$krSort}]: {$krTitle} (w={$krWeight}, measure={$measure}, ach={$achievement})");

                if (! $isDry) {
                    $kr = OkrKeyResult::firstOrCreate(
                        ['objective_id' => $currentObjModel->id, 'title' => $krTitle],
                        [
                            'measure'      => $measure ?: null,
                            'target_value' => 100,
                            'achievement'  => $achievement,
                            'weight'       => $krWeight ?: 1,
                            'sort_order'   => $krSort,
                            'score_percent' => 0,
                        ]
                    );

                    // Dept scores
                    foreach ($deptIdMap as $col => $deptId) {
                        $rawScore = $row[$col] ?? null;
                        if ($rawScore === null || $rawScore === '') continue;
                        $score = (float)$rawScore;
                        // Normalise: if value > 1 and <= 100, it's already a percentage
                        // If value <= 1, treat as decimal fraction × 100
                        if ($score > 0 && $score <= 1) $score *= 100;

                        OkrKrDeptScore::updateOrCreate(
                            ['key_result_id' => $kr->id, 'department_id' => $deptId],
                            ['score' => round($score, 2), 'weight' => 1]
                        );
                        $imported['dept_scores']++;
                    }

                    $scoring->recomputeAndPersist($kr->fresh()->load('deptScores'));
                }

                $krSort++;
                $imported['key_results']++;
            }
        }

        $this->newLine();
        $this->info("Import " . ($isDry ? '(DRY RUN) ' : '') . "complete:");
        $this->table(['Entity', 'Count'], [
            ['Objectives', $imported['objectives']],
            ['Key Results', $imported['key_results']],
            ['Dept Scores', $imported['dept_scores']],
        ]);

        return 0;
    }
}
