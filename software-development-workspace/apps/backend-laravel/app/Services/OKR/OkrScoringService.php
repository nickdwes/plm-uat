<?php

namespace App\Services\OKR;

use App\Models\OkrCycle;
use App\Models\OkrKeyResult;
use App\Models\OkrObjective;

class OkrScoringService
{
    /**
     * Compute the score % for a Key Result.
     *
     * Logic:
     *  - If department scores exist: weighted average of dept scores.
     *  - Otherwise: (achievement / target_value) × 100, capped at 100%.
     */
    public function computeKrScore(OkrKeyResult $kr): float
    {
        $deptScores = $kr->deptScores;

        if ($deptScores->isNotEmpty()) {
            $totalWeight    = $deptScores->sum(fn($ds) => (float) $ds->weight);
            $weightedSum    = $deptScores->sum(fn($ds) => (float) $ds->score * (float) $ds->weight);
            return $totalWeight > 0 ? min(100, round($weightedSum / $totalWeight, 2)) : 0.0;
        }

        if ($kr->target_value > 0 && $kr->achievement !== null) {
            return min(100, round(((float) $kr->achievement / (float) $kr->target_value) * 100, 2));
        }

        return (float) ($kr->score_percent ?? 0);
    }

    /**
     * Recompute a KR's score_percent and persist it.
     */
    public function recomputeAndPersist(OkrKeyResult $kr): float
    {
        $kr->loadMissing('deptScores');
        $score = $this->computeKrScore($kr);
        $kr->update(['score_percent' => $score]);
        return $score;
    }

    /**
     * Compute objective score as weighted average of its KR scores.
     *
     * Objective Score = sum(KR score_percent × KR weight) / sum(KR weights)
     */
    public function computeObjectiveScore(OkrObjective $objective): float
    {
        $krs = $objective->keyResults()->with('deptScores')->get();
        if ($krs->isEmpty()) return 0.0;

        $totalWeight = $krs->sum(fn($kr) => (float) $kr->weight);
        if ($totalWeight === 0.0) return 0.0;

        $weightedSum = $krs->sum(fn($kr) => $this->computeKrScore($kr) * (float) $kr->weight);
        return round($weightedSum / $totalWeight, 2);
    }

    /**
     * Build the full scorecard for a cycle:
     * Returns an array of objectives, each with:
     *   - its own score (weighted KR avg)
     *   - its key results, each with dept_scores keyed by department_id
     */
    public function computeCycleScorecard(OkrCycle $cycle): array
    {
        $objectives = $cycle->objectives()
            ->with(['keyResults.deptScores.department'])
            ->get();

        $result = [];
        foreach ($objectives as $obj) {
            $krs = [];
            foreach ($obj->keyResults as $kr) {
                $score     = $this->computeKrScore($kr);
                $deptMap   = [];
                foreach ($kr->deptScores as $ds) {
                    $deptMap[$ds->department_id] = [
                        'department_id'   => $ds->department_id,
                        'department_name' => $ds->department->name ?? '—',
                        'score'           => (float) $ds->score,
                        'weight'          => (float) $ds->weight,
                    ];
                }
                $krs[] = [
                    'id'            => $kr->id,
                    'title'         => $kr->title,
                    'measure'       => $kr->measure,
                    'target_value'  => (float) $kr->target_value,
                    'achievement'   => $kr->achievement !== null ? (float) $kr->achievement : null,
                    'score_percent' => $score,
                    'weight'        => (float) $kr->weight,
                    'sort_order'    => $kr->sort_order,
                    'dept_scores'   => $deptMap,
                ];
            }

            // Compute obj score from KR data already fetched
            $totalWeight = collect($krs)->sum('weight');
            $objScore    = $totalWeight > 0
                ? round(collect($krs)->sum(fn($k) => $k['score_percent'] * $k['weight']) / $totalWeight, 2)
                : 0.0;

            $result[] = [
                'id'          => $obj->id,
                'title'       => $obj->title,
                'description' => $obj->description,
                'weight'      => (float) $obj->weight,
                'sort_order'  => $obj->sort_order,
                'score'       => $objScore,
                'key_results' => $krs,
            ];
        }

        return $result;
    }
}
