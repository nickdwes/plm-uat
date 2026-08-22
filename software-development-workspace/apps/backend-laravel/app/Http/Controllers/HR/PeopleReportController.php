<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\HR\PeopleReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The people report — pick the columns, see them, take them away.
 *
 * The preview and the spreadsheet are the same query and the same catalogue,
 * so what somebody checks on screen is what lands in the file they forward.
 */
class PeopleReportController extends Controller
{
    /** Rows shown on screen before exporting. Enough to check the shape. */
    private const PREVIEW_ROWS = 25;

    /**
     * GET /api/v1/reports/people/columns
     *
     * What this viewer may put in a report, grouped as the picker shows them.
     */
    public function columns(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $orgId = app('current.organization_id');
        $user  = $request->user();

        $available = PeopleReport::availableTo($user, $orgId);

        return response()->json([
            'data' => [
                'columns' => collect($available)->map(fn ($meta, $key) => [
                    'key'       => $key,
                    'label'     => $meta['label'],
                    'group'     => $meta['group'],
                    'sensitive' => $meta['sensitive'],
                ])->values(),
                'defaults' => array_values(array_filter(
                    PeopleReport::DEFAULTS,
                    fn ($c) => isset($available[$c]),
                )),
                /*
                 * Said plainly rather than left to be inferred from a short
                 * list. Somebody who cannot see pay should know the report is
                 * narrower than the one their colleague ran, not wonder why
                 * their spreadsheet is missing a column.
                 */
                'sees_sensitive' => PeopleReport::maySeeSensitive($user, $orgId),
            ],
        ]);
    }

    /**
     * GET /api/v1/reports/people/preview
     *
     * The first rows, so the columns can be checked before a file is made.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $orgId   = app('current.organization_id');
        $columns = PeopleReport::resolve($this->requested($request), $request->user(), $orgId);
        $query   = PeopleReport::query($orgId, $this->filters($request));

        $total = (clone $query)->count();
        $page  = $query->limit(self::PREVIEW_ROWS)->get();

        Employee::attachPlacements($page, $orgId);

        return response()->json([
            'data' => [
                'columns'  => $columns,
                'headings' => PeopleReport::headings($columns),
                'rows'     => $page->map(fn (Employee $e) => PeopleReport::row($e, $columns)),
                'total'    => $total,
                'showing'  => $page->count(),
            ],
        ]);
    }

    /**
     * POST /api/v1/reports/people/export
     *
     * Every employee the filters match — not the page on screen. A report
     * headed "all employees" that stops at row twenty-five is the kind of file
     * somebody plans around.
     *
     * ── Why this is a POST, and why the file is a zip ──
     *
     * The password travels in the body because a query string is written to
     * the access log, the browser history and any proxy in between, and a
     * password recorded in three places is not one.
     *
     * PhpSpreadsheet cannot write an .xlsx that Excel asks a password to open
     * — that is ECMA-376 agile encryption, which it only reads. What it can do
     * is set sheet and workbook protection, and that is not encryption at all:
     * the rows sit in cleartext inside the zip and the flag is stripped by
     * renaming the file. Shipping that for a sheet holding salaries and
     * national ID numbers would be worse than shipping nothing, because it
     * would look protected.
     *
     * So the workbook is wrapped in a zip encrypted with AES-256, which is
     * real. The cost is that the recipient needs 7-Zip, WinRAR, Keka or any
     * modern archiver — Windows Explorer and macOS Archive Utility both open
     * zips but neither handles AES, and they will report the file as damaged
     * rather than asking for a password.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Employee::class);

        $orgId   = app('current.organization_id');
        $columns = PeopleReport::resolve($this->requested($request), $request->user(), $orgId);
        $filters = $this->filters($request);

        $password = (string) $request->input('password', '');

        /*
         * A report carrying pay or statutory identifiers cannot leave
         * unprotected. Somebody exporting a name-and-department list should
         * not have to invent a password for it; somebody exporting salaries
         * should not be able to skip one.
         */
        $catalogue = PeopleReport::catalogue();
        $sensitive = array_values(array_filter($columns, fn ($c) => $catalogue[$c]['sensitive'] ?? false));

        if ($sensitive && mb_strlen($password) < 8) {
            abort(422, 'This report includes ' . $catalogue[$sensitive[0]]['label']
                . ', so it needs a password of at least 8 characters before it can be downloaded.');
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Employees');

        $headings = PeopleReport::headings($columns)->all();
        $sheet->fromArray($headings, null, 'A1');

        $lastColumn = $sheet->getCell([count($headings), 1])->getColumn();
        $header = $sheet->getStyle("A1:{$lastColumn}1");
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EFF3F8');
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        // So the headings stay visible on a sheet of three hundred people.
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}1");

        $numeric = PeopleReport::numericColumns($columns);
        $row = 2;

        /*
         * Chunked. Three hundred and forty people fit in memory; the point is
         * that the report keeps working at three thousand four hundred, and a
         * report that dies on a growing company is a report nobody trusts.
         */
        PeopleReport::query($orgId, $filters)->chunk(500, function ($chunk) use (
            $sheet, $columns, $numeric, &$row, $orgId
        ) {
            Employee::attachPlacements($chunk, $orgId);

            foreach ($chunk as $employee) {
                $values = PeopleReport::row($employee, $columns);

                foreach ($values as $i => $value) {
                    $cell = [$i + 1, $row];

                    /*
                     * Numbers as numbers. A salary written as text cannot be
                     * summed, and the first thing anybody does with a payroll
                     * column is total it.
                     */
                    if (in_array($columns[$i], $numeric, true)) {
                        $sheet->setCellValueExplicit($cell, (float) $value,
                            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00');
                    } else {
                        // Explicitly text: an employee number like 0012 must
                        // not lose its leading zeros, and a phone number
                        // beginning + must not become a formula.
                        $sheet->setCellValueExplicit($cell, (string) ($value ?? ''),
                            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    }
                }

                $row++;
            }
        });

        foreach (range(1, count($headings)) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        $stamp = now()->format('Ymd-His');

        if ($password === '') {
            return response()->streamDownload(function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
            }, "employees-{$stamp}.xlsx", [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return $this->encrypted($spreadsheet, $password, $stamp);
    }

    /**
     * The workbook inside an AES-256 zip.
     *
     * Written to a temporary file because ZipArchive needs a path on disk, and
     * both files are removed before the response finishes streaming — a
     * plaintext copy of the payroll left in /tmp would undo the point of the
     * password.
     */
    private function encrypted(Spreadsheet $spreadsheet, string $password, string $stamp): StreamedResponse
    {
        $dir  = sys_get_temp_dir();
        $book = tempnam($dir, 'people-') . '.xlsx';
        $zip  = tempnam($dir, 'people-') . '.zip';

        (new Xlsx($spreadsheet))->save($book);

        $archive = new \ZipArchive();

        if ($archive->open($zip, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) !== true) {
            @unlink($book);
            abort(500, 'Could not build the protected archive.');
        }

        $archive->setPassword($password);
        $archive->addFile($book, "employees-{$stamp}.xlsx");
        $archive->setEncryptionName("employees-{$stamp}.xlsx", \ZipArchive::EM_AES_256);
        $archive->close();

        // The workbook itself is no longer needed the moment it is inside.
        @unlink($book);

        return response()->streamDownload(function () use ($zip) {
            readfile($zip);
            @unlink($zip);
        }, "employees-{$stamp}.zip", [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * Whether a password would be required for these columns, so the screen
     * can say so before somebody presses the button.
     */
    public function requirement(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $orgId     = app('current.organization_id');
        $columns   = PeopleReport::resolve($this->requested($request), $request->user(), $orgId);
        $catalogue = PeopleReport::catalogue();

        $sensitive = array_values(array_filter($columns, fn ($c) => $catalogue[$c]['sensitive'] ?? false));

        return response()->json([
            'data' => [
                'password_required' => (bool) $sensitive,
                'because' => array_map(fn ($c) => $catalogue[$c]['label'], $sensitive),
            ],
        ]);
    }

    /** @return array<int, string> */
    private function requested(Request $request): array
    {
        $raw = $request->input('columns', []);

        return is_array($raw) ? $raw : array_filter(explode(',', (string) $raw));
    }

    private function filters(Request $request): array
    {
        return $request->only(['search', 'department_id', 'employment_type', 'status', 'on_leave']);
    }
}
