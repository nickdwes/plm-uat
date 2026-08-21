<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Leave\LeaveRequestAttachment;
use App\Models\LeaveRequest;
use App\Support\Storage\DocumentVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The paperwork behind a leave request.
 *
 * Sick notes and death certificates, so they go through DocumentVault onto the
 * encrypted disk and come back only through a route that has authorised the
 * caller. Nothing here ever hands out a storage path or a public URL — the
 * bytes are only ever streamed after the policy has run.
 */
class LeaveAttachmentController extends Controller
{
    /** Five megabytes. A scan of a doctor's note, not a video. */
    private const MAX_SIZE_BYTES = 5 * 1024 * 1024;

    public function index(int $leaveRequest): JsonResponse
    {
        $leave = $this->authorised($leaveRequest, 'view');

        return response()->json([
            'data' => $leave->attachments()->with('uploader:id,name')->get()
                ->map(fn ($a) => $this->format($a))->all(),
        ]);
    }

    public function store(Request $request, int $leaveRequest): JsonResponse
    {
        $leave = $this->authorised($leaveRequest, 'view');

        /*
         * Only while the decision is still open. A file added to a settled
         * request looks like evidence that was considered and was not, and
         * there is no way for a reader to tell the difference afterwards.
         */
        abort_unless(
            $leave->isPending(),
            422,
            'This request has already been decided, so nothing further can be attached to it.',
        );

        $request->validate([
            'file' => [
                'required', 'file',
                'max:' . (self::MAX_SIZE_BYTES / 1024), // Laravel counts kilobytes
                'mimes:pdf,jpg,jpeg,png,webp,doc,docx',
            ],
        ]);

        $file = $request->file('file');

        $attachment = LeaveRequestAttachment::create([
            'leave_request_id' => $leave->id,
            'uploaded_by'      => $request->user()->id,
            'original_name'    => $file->getClientOriginalName(),
            'storage_path'     => DocumentVault::store(
                $file, LeaveRequestAttachment::DISK, LeaveRequestAttachment::DIRECTORY,
            ),
            'mime_type'        => $file->getClientMimeType(),
            'size_bytes'       => $file->getSize(),
        ]);

        return response()->json(['data' => $this->format($attachment->load('uploader:id,name'))], 201);
    }

    public function download(int $leaveRequest, int $attachment): StreamedResponse
    {
        $leave = $this->authorised($leaveRequest, 'view');

        $file = $leave->attachments()->findOrFail($attachment);

        // A row whose bytes have gone should 404, not 500 halfway through a
        // stream the browser has already started saving.
        abort_unless($file->fileExists(), 404, 'That file is no longer in storage.');

        return DocumentVault::download(
            LeaveRequestAttachment::DISK, $file->storage_path, $file->original_name, $file->mime_type,
        );
    }

    public function destroy(Request $request, int $leaveRequest, int $attachment): JsonResponse
    {
        $leave = $this->authorised($leaveRequest, 'view');

        $file = $leave->attachments()->findOrFail($attachment);

        /*
         * Whoever attached it, while the request is still open. An approver
         * cannot quietly remove the note they were shown, and nobody can edit
         * the record of a decision after it has been made.
         */
        abort_unless(
            $file->uploaded_by === $request->user()->id && $leave->isPending(),
            403,
            'Only the person who attached a file can remove it, and only while the request is still open.',
        );

        $file->purge();

        return response()->json(['message' => 'Attachment removed.']);
    }

    /** The request, if this user may see it at all. */
    private function authorised(int $id, string $ability): LeaveRequest
    {
        $leave = LeaveRequest::withoutGlobalScope('organization')
            ->where('organization_id', app('current.organization_id'))
            ->findOrFail($id);

        $this->authorize($ability, $leave);

        return $leave;
    }

    private function format(LeaveRequestAttachment $a): array
    {
        return [
            'id'            => $a->id,
            'original_name' => $a->original_name,
            'mime_type'     => $a->mime_type,
            'size_bytes'    => $a->size_bytes,
            'uploaded_by'   => $a->uploader?->name,
            'created_at'    => $a->created_at?->toISOString(),
        ];
    }
}
