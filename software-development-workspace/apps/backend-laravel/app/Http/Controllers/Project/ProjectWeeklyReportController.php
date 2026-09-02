<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Services\Project\WeeklyReportPdf;
use App\Services\Project\WeeklyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProjectWeeklyReportController extends Controller
{
    public function __construct(private WeeklyReportService $reports)
    {
    }

    /** The live current-week report plus last week's published snapshot. */
    public function current(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $this->reports->current($project)]);
    }

    /** Every published week, newest first. */
    public function history(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $this->reports->history($project)]);
    }

    /** The week's status report as a printable PDF. */
    public function pdf(Project $project, WeeklyReportPdf $pdf): Response
    {
        $this->authorize('view', $project);

        $project->loadMissing('account');
        $document = $pdf->render(
            $project,
            $this->reports->current($project),
            $this->reports->history($project),
        );

        return response($document['content'], 200, [
            'Content-Type'           => 'application/pdf',
            'Content-Disposition'    => 'attachment; filename="' . $document['filename'] . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Publish (or re-publish) this week's report. */
    public function publish(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'summary' => ['nullable', 'string', 'max:5000'],
        ]);

        $snapshot = $this->reports->publish($project, $data['summary'] ?? null, $request->user()->id);

        return response()->json(['data' => $this->reports->payload($snapshot->fresh('creator:id,name'))], 201);
    }

    public function destroy(Project $project, int $snapshot): JsonResponse
    {
        $this->authorize('update', $project);

        $row = $project->weeklySnapshots()->whereKey($snapshot)->firstOrFail();
        $row->delete();

        return response()->json(['message' => 'Weekly report removed.']);
    }
}
