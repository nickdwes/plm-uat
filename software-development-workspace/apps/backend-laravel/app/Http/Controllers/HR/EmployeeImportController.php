<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\HR\EmployeeImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bringing people in from a spreadsheet, in two steps.
 *
 * Inspect, then run. The file is sent again with the mapping rather than
 * parked on the server between the two: a half-finished import sitting in
 * storage is a copy of a personnel file with no owner and no expiry, and the
 * saving is a few hundred kilobytes.
 */
class EmployeeImportController extends Controller
{
    /** Rows shown back so a mapping can be checked against real values. */
    private const SAMPLE_ROWS = 5;

    /** Enough for a company; beyond it this belongs in a queue, not a request. */
    private const MAX_ROWS = 5000;

    /**
     * POST /api/v1/employees/import/inspect
     *
     * The sheet's headings, a few rows, and a guess at which column is which.
     */
    public function inspect(Request $request): JsonResponse
    {
        $this->guard($request);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'],
        ]);

        [$headings, $rows] = $this->read($request->file('file'));

        $orgId = app('current.organization_id');

        return response()->json([
            'data' => [
                'headings' => $headings,
                'sample'   => array_slice($rows, 0, self::SAMPLE_ROWS),
                'rows'     => count($rows),
                'suggested' => EmployeeImport::suggest($headings, $request->user(), $orgId),
                'fields'   => collect(EmployeeImport::availableTo($request->user(), $orgId))
                    ->map(fn ($meta, $key) => [
                        'key'      => $key,
                        'label'    => $meta['label'],
                        'group'    => $meta['group'],
                        'required' => (bool) ($meta['required'] ?? false),
                        'type'     => $meta['type'] ?? 'text',
                        'values'   => $meta['values'] ?? null,
                    ])->values(),
            ],
        ]);
    }

    /**
     * POST /api/v1/employees/import/run
     *
     * Rehearses unless `apply` is true, the same way the PLM importers do.
     * Nobody should discover what an import did by looking at the result.
     */
    public function run(Request $request): JsonResponse
    {
        $this->guard($request);

        $request->validate([
            'file'      => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'],
            'mapping'   => ['required'],
            'apply'     => ['sometimes'],
        ]);

        $mapping = $request->input('mapping');

        if (is_string($mapping)) {
            $mapping = json_decode($mapping, true) ?: [];
        }

        abort_if(! is_array($mapping) || ! $mapping, 422, 'Nothing was mapped, so there is nothing to import.');

        [$headings, $rows] = $this->read($request->file('file'));

        $report = (new EmployeeImport())->run(
            rows: $rows,
            headings: $headings,
            mapping: $mapping,
            organizationId: app('current.organization_id'),
            user: $request->user(),
            apply: $request->boolean('apply'),
        );

        return response()->json(['data' => $report]);
    }

    /**
     * Creating and updating, both. An import does both by design, so holding
     * only one of the two permissions is not enough to run one.
     */
    private function guard(Request $request): void
    {
        $this->authorize('viewAny', Employee::class);

        abort_unless(
            EmployeeImport::mayImport($request->user(), app('current.organization_id')),
            403,
            'Importing people needs permission to both create and update employee records.',
        );
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    private function read(UploadedFile $file): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);

        $sheet = $reader->load($file->getRealPath())->getActiveSheet();
        $table = $sheet->toArray(null, true, false, false);

        abort_if(! $table, 422, 'That file has no rows in it.');

        $headings = array_map(
            fn ($h) => trim((string) ($h ?? '')),
            array_shift($table) ?? [],
        );

        abort_if(
            ! array_filter($headings),
            422,
            'The first row must be the column headings — it is what the mapping refers to.',
        );

        // Trailing blank rows are what a spreadsheet leaves behind when
        // somebody deletes the contents of a row rather than the row.
        $table = array_values(array_filter(
            $table,
            fn ($row) => array_filter($row, fn ($c) => $c !== null && trim((string) $c) !== ''),
        ));

        abort_if(
            count($table) > self::MAX_ROWS,
            422,
            'That sheet has ' . count($table) . ' rows. Anything over ' . self::MAX_ROWS
            . ' should be split, or it will time out halfway through and leave you guessing what landed.',
        );

        return [$headings, $table];
    }
}
