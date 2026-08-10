<?php

namespace App\Http\Controllers\HR;

use App\Support\Storage\DocumentVault;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\HR\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    public function index(int $employee): JsonResponse
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('view', $record);

        return response()->json([
            'data' => $this->service->getDocuments($employee)->map(fn (EmployeeDocument $d) => $this->formatDocument($d)),
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
            'category' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
        ]);

        $orgId = app('current.organization_id');

        $doc = $this->service->uploadDocument(
            employeeId: $employee,
            orgId:      $orgId,
            uploadedBy: $request->user()->id,
            category:   $request->input('category'),
            file:       $request->file('file'),
        );

        return response()->json(['data' => $this->formatDocument($doc)], 201);
    }

    // ── GET /employees/{employee}/documents/{document}/download ───────────────

    public function download(int $employee, int $document): Response
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('view', $record);

        $doc = EmployeeDocument::where('employee_id', $employee)->findOrFail($document);

        abort_unless($doc->fileExists(), 404, 'File not found on storage.');

        // Through the vault, which decrypts. Streaming the raw bytes would
        // hand back ciphertext now that this class is sealed.
        return DocumentVault::download(
            'employee_documents', $doc->storage_path, $doc->original_name, $doc->mime_type,
        );
    }

    // ── DELETE /employees/{employee}/documents/{document} ─────────────────────

    public function destroy(int $employee, int $document): JsonResponse
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('update', $record);

        $doc = EmployeeDocument::where('employee_id', $employee)->findOrFail($document);

        $this->service->deleteDocument($doc);

        return response()->json(['message' => 'Document deleted.']);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function formatDocument(EmployeeDocument $d): array
    {
        return [
            'id'            => $d->id,
            'category'      => $d->category,
            'original_name' => $d->original_name,
            'mime_type'     => $d->mime_type,
            'size_bytes'    => $d->size_bytes,
            'uploaded_by'   => $d->uploaded_by,
            'created_at'    => $d->created_at?->toISOString(),
        ];
    }
}
