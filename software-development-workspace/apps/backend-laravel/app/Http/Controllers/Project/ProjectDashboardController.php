<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Services\Project\ProjectDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectDashboardController extends Controller
{
    public function __construct(private readonly ProjectDashboard $dashboard) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $request->validate([
            'status' => ['nullable', 'string', Rule::in(Project::STATUSES)],
        ]);

        return response()->json([
            'data' => $this->dashboard->build(
                app('current.organization_id'),
                $request->input('status'), // null = whole active portfolio
            ),
        ]);
    }
}
