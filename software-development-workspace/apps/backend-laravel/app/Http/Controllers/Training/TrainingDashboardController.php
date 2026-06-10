<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\Training\EmployeeTrainingRecord;
use App\Models\Training\TrainingItem;
use Illuminate\Http\JsonResponse;

class TrainingDashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', TrainingItem::class);

        $activeLearners = EmployeeTrainingRecord::whereIn('status', ['enrolled', 'in_progress'])
            ->distinct('employee_id')
            ->count('employee_id');

        $completionsThisMonth = EmployeeTrainingRecord::where('status', 'completed')
            ->whereMonth('completed_at', now()->month)
            ->whereYear('completed_at', now()->year)
            ->count();

        $overdue = EmployeeTrainingRecord::whereNotIn('status', ['completed', 'withdrawn'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', today())
            ->count();

        $priorityActive = EmployeeTrainingRecord::where('is_priority', true)
            ->whereIn('status', ['enrolled', 'in_progress'])
            ->count();

        $totalEnrolled  = EmployeeTrainingRecord::whereIn('status', ['enrolled', 'in_progress', 'completed'])->count();
        $totalCompleted = EmployeeTrainingRecord::where('status', 'completed')->count();
        $completionRate = $totalEnrolled > 0 ? round(($totalCompleted / $totalEnrolled) * 100, 1) : 0;

        $catalogTotal  = TrainingItem::count();
        $catalogActive = TrainingItem::where('is_active', true)->count();

        return response()->json([
            'active_learners'          => $activeLearners,
            'completions_this_month'   => $completionsThisMonth,
            'overdue'                  => $overdue,
            'priority_active'          => $priorityActive,
            'completion_rate_percent'  => $completionRate,
            'catalog_total'            => $catalogTotal,
            'catalog_active'           => $catalogActive,
        ]);
    }
}
