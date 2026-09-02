<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectEmail;
use App\Services\Project\ProjectMailService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A project's inbound email — pulled from its shared mailbox into the
 * correspondence tab.
 */
class ProjectEmailController extends Controller
{
    public function __construct(private readonly ProjectMailService $mail)
    {
    }

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'data'          => $project->emails()->get()->map(fn ($e) => $this->payload($e)),
            'project_email' => $project->project_email,
        ]);
    }

    public function sync(Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        if (! $project->project_email) {
            return response()->json(['message' => 'Set the project mailbox first, then fetch its email.'], 422);
        }

        try {
            $result = $this->mail->sync($project);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data'    => $project->emails()->get()->map(fn ($e) => $this->payload($e)),
            'fetched' => $result['fetched'],
            'new'     => $result['new'],
        ]);
    }

    private function payload(ProjectEmail $e): array
    {
        return [
            'id'              => $e->id,
            'from_name'       => $e->from_name,
            'from_email'      => $e->from_email,
            'subject'         => $e->subject,
            'body_preview'    => $e->body_preview,
            'body_html'       => $e->body_html,
            'to'              => $e->to_recipients ?? [],
            'cc'              => $e->cc_recipients ?? [],
            'has_attachments' => $e->has_attachments,
            'received_at'     => $e->received_at?->toIso8601String(),
        ];
    }
}
