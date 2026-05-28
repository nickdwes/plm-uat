<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\HR\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages Step 5 of the employee onboarding wizard:
 *   - HR induction checklist
 *   - Document collection checklist
 *   - Asset assignments
 *
 * All routes are nested under /api/v1/employees/{employee}/onboarding
 */
class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingService $service) {}

    // ── GET /employees/{employee}/onboarding ──────────────────────────────────

    /**
     * Return all Step 5 data for an employee in a single response.
     */
    public function show(int $employee): JsonResponse
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('view', $record);

        return response()->json([
            'inductions'        => $this->service->getInductions($employee),
            'document_checklist'=> $this->service->getDocumentChecklist($employee),
            'assets'            => $this->service->getAssets($employee),
        ]);
    }

    // ── PUT /employees/{employee}/onboarding ──────────────────────────────────

    /**
     * Sync all Step 5 data at once (called on "Complete Onboarding" submit).
     *
     * Body: {
     *   inductions:         [{ label, done, notes }]
     *   document_checklist: [{ label, done, notes }]
     *   assets:             [{ name, serial, assigned_date }]
     * }
     */
    public function sync(Request $request, int $employee): JsonResponse
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('update', $record);

        $request->validate([
            'inductions'                  => ['nullable', 'array'],
            'inductions.*.label'          => ['required_with:inductions', 'string', 'max:255'],
            'inductions.*.done'           => ['nullable', 'boolean'],
            'inductions.*.notes'          => ['nullable', 'string', 'max:500'],

            'document_checklist'          => ['nullable', 'array'],
            'document_checklist.*.label'  => ['required_with:document_checklist', 'string', 'max:255'],
            'document_checklist.*.done'   => ['nullable', 'boolean'],
            'document_checklist.*.notes'  => ['nullable', 'string', 'max:500'],

            'assets'                      => ['nullable', 'array'],
            'assets.*.name'               => ['required_with:assets', 'string', 'max:255'],
            'assets.*.serial'             => ['nullable', 'string', 'max:100'],
            'assets.*.assigned_date'      => ['nullable', 'date'],
        ]);

        $inductions = $request->input('inductions', []);
        if (!empty($inductions)) {
            $this->service->syncInductions($employee, $inductions);
        }

        $docChecklist = $request->input('document_checklist', []);
        if (!empty($docChecklist)) {
            $this->service->syncDocumentChecklist($employee, $docChecklist);
        }

        $assets = $request->input('assets', []);
        if (!empty($assets)) {
            $this->service->syncAssets($employee, $assets);
        }

        return response()->json([
            'inductions'        => $this->service->getInductions($employee),
            'document_checklist'=> $this->service->getDocumentChecklist($employee),
            'assets'            => $this->service->getAssets($employee),
        ]);
    }
}
