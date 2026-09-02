<?php

namespace App\Http\Controllers\HR;

use App\Support\Storage\DocumentVault;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\HR\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Handles secure upload, listing, download, and deletion of employee documents.
 * Files are stored on the private 'employee_documents' disk and are
 * NEVER served directly — they stream through this authenticated endpoint only.
 *
 * Routes nested under /api/v1/employees/{employee}/documents
 */
class DocumentController extends Controller
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

    public function __construct(private readonly OnboardingService $service) {}

    // ── GET /employees/{employee}/documents ───────────────────────────────────

    public function index(Request $request, int $employee): JsonResponse
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('view', $record);

        /*
         * Confidential documents are a class above "private to the employee
         * file": listed only for holders of documents.view_confidential —
         * including against the employee they are about, which is the whole
         * point of the flag (disciplinary notes, investigations).
         */
        $seesConfidential = $this->seesConfidential($request);

        return response()->json([
            'data' => $this->service->getDocuments($employee)
                ->reject(fn (EmployeeDocument $d) => $d->is_confidential && ! $seesConfidential)
                ->values()
                ->map(fn (EmployeeDocument $d) => $this->formatDocument($d)),
            // So the client can offer the flag on upload only when it means something.
            'can_flag_confidential' => $seesConfidential,
        ]);
    }

    // ── POST /employees/{employee}/documents ──────────────────────────────────

    public function store(Request $request, int $employee): JsonResponse
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('update', $record);

        $request->validate([
            'file'     => [
                'required',
                'file',
                'max:' . (self::MAX_SIZE_BYTES / 1024), // Laravel uses KB
                'mimes:pdf,jpg,jpeg,png,webp,doc,docx',
            ],
            'category'        => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'is_confidential' => ['sometimes', 'boolean'],
        ]);

        // Flagging a document confidential makes it invisible to yourself
        // unless you hold the permission — refuse rather than let somebody
        // upload a file they can never see again.
        $confidential = $request->boolean('is_confidential');
        abort_if($confidential && ! $this->seesConfidential($request), 403,
            'Only holders of documents.view_confidential may flag a document confidential.');

        $orgId = app('current.organization_id');

        $doc = $this->service->uploadDocument(
            employeeId:   $employee,
            orgId:        $orgId,
            uploadedBy:   $request->user()->id,
            category:     $request->input('category'),
            file:         $request->file('file'),
            confidential: $confidential,
        );

        return response()->json(['data' => $this->formatDocument($doc)], 201);
    }

    // ── GET /employees/{employee}/documents/{document}/download ───────────────

    public function download(Request $request, int $employee, int $document): Response
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('view', $record);

        $doc = EmployeeDocument::where('employee_id', $employee)->findOrFail($document);

        // Listing hides confidential documents; the download must refuse them
        // too, or a remembered id becomes a way round the classification.
        abort_if($doc->is_confidential && ! $this->seesConfidential($request), 403);

        abort_unless($doc->fileExists(), 404, 'File not found on storage.');

        // A read of somebody's private document is exactly the access an
        // auditor asks about — recorded before the bytes leave.
        \App\Services\Audit\AuditLogger::record(
            action: 'downloaded',
            auditable: $record,
            description: "Downloaded document \"{$doc->original_name}\" of " . $record->auditName(),
            organizationId: $record->primary_organization_id,
        );

        // Through the vault, which decrypts. Streaming the raw bytes would
        // hand back ciphertext now that this class is sealed.
        return DocumentVault::download(
            'employee_documents', $doc->storage_path, $doc->original_name, $doc->mime_type,
        );
    }

    // ── DELETE /employees/{employee}/documents/{document} ─────────────────────

    public function destroy(Request $request, int $employee, int $document): JsonResponse
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('update', $record);

        $doc = EmployeeDocument::where('employee_id', $employee)->findOrFail($document);

        // What you cannot see, you cannot delete — a guessed id must not
        // become a way to destroy an investigation file.
        abort_if($doc->is_confidential && ! $this->seesConfidential($request), 403);

        $this->service->deleteDocument($doc);

        return response()->json(['message' => 'Document deleted.']);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function seesConfidential(Request $request): bool
    {
        return $request->user()?->hasPermission(
            \App\Support\Authorization\Permission::DOCUMENTS_VIEW_CONFIDENTIAL,
        ) ?? false;
    }

    private function formatDocument(EmployeeDocument $d): array
    {
        return [
            'id'              => $d->id,
            'category'        => $d->category,
            'is_confidential' => $d->is_confidential,
            'original_name'   => $d->original_name,
            'mime_type'       => $d->mime_type,
            'size_bytes'      => $d->size_bytes,
            'uploaded_by'     => $d->uploaded_by,
            'created_at'      => $d->created_at?->toISOString(),
        ];
    }
}
