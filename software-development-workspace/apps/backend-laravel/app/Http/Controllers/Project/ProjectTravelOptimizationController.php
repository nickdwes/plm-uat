<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Services\Project\TravelCycleBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Travel cycle optimisation tips — a recommendation pass over the consolidated
 * cycles. The builder already merges trips whose dates overlap; this looks one
 * step further out for savings a scheduler would spot by eye: separate trips to
 * the same place close together that could become one, and single-task trips
 * whose travel cost is carried by one piece of work.
 */
class ProjectTravelOptimizationController extends Controller
{
    /** Two trips to the same site within this many days are worth combining. */
    private const MERGE_WINDOW_DAYS = 14;

    public function index(Project $project, TravelCycleBuilder $builder): JsonResponse
    {
        $this->authorize('view', $project);

        $cycles = collect($builder->build($project));
        $tips = collect()
            ->concat($this->mergeable($cycles))
            ->concat($this->underfilled($cycles))
            ->values();

        return response()->json([
            'data'    => $tips,
            'summary' => [
                'cycles'          => $cycles->count(),
                'tips'            => $tips->count(),
                'potential_saving_days' => (int) $tips->sum(fn ($t) => $t['saving_days'] ?? 0),
            ],
        ]);
    }

    /**
     * Separate trips to the same location whose windows sit within the merge
     * window — combining them into one visit saves the extra mobilisations.
     */
    private function mergeable($cycles): array
    {
        $tips = [];

        foreach ($cycles->groupBy('location') as $location => $group) {
            if ($group->count() < 2) {
                continue;
            }

            $dated = $group->filter(fn ($c) => $c['start'])->sortBy('start')->values();
            if ($dated->count() < 2) {
                // Same place, undated — still worth flagging as combinable.
                $tips[] = $this->tip('merge', "Multiple trips to {$location}",
                    $group->count() . " separate trips to {$location} could be planned as one visit.",
                    $group->pluck('number')->all(), (int) $group->sum('travel_days') - (int) $group->max('travel_days'));
                continue;
            }

            // Cluster consecutive trips whose gap is within the window.
            $cluster = [$dated[0]];
            $flush = function (array $c) use (&$tips, $location) {
                if (count($c) < 2) {
                    return;
                }
                $collection = collect($c);
                $tips[] = $this->tip('merge', "Trips to {$location} close together",
                    count($c) . " trips to {$location} fall within " . self::MERGE_WINDOW_DAYS . " days of each other — one visit would cover them.",
                    $collection->pluck('number')->all(), (int) $collection->sum('travel_days') - (int) $collection->max('travel_days'));
            };

            for ($i = 1; $i < $dated->count(); $i++) {
                $prev = $cluster[count($cluster) - 1];
                $gap = Carbon::parse($prev['end'] ?? $prev['start'])->diffInDays(Carbon::parse($dated[$i]['start']), false);
                if ($gap <= self::MERGE_WINDOW_DAYS) {
                    $cluster[] = $dated[$i];
                } else {
                    $flush($cluster);
                    $cluster = [$dated[$i]];
                }
            }
            $flush($cluster);
        }

        return $tips;
    }

    /** Single-task trips — the travel cost rests on one piece of work. */
    private function underfilled($cycles): array
    {
        return $cycles
            ->filter(fn ($c) => count($c['tasks']) === 1 && (int) $c['travel_days'] > 0)
            ->map(fn ($c) => $this->tip('batch', "One task carries the trip to {$c['location']}",
                "Cycle #{$c['number']} to {$c['location']} covers a single task over {$c['travel_days']} travel day" . ($c['travel_days'] === 1 ? '' : 's') . ' — batching more work into this visit spreads the travel cost.',
                [$c['number']], 0))
            ->values()->all();
    }

    private function tip(string $type, string $title, string $detail, array $cycles, int $saving): array
    {
        return [
            'type'        => $type,
            'title'       => $title,
            'detail'      => $detail,
            'cycles'      => $cycles,
            'saving_days' => max(0, $saving),
        ];
    }
}
