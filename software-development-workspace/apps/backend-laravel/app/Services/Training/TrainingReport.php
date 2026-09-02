<?php

namespace App\Services\Training;

use App\Models\Training\CompetencyItem;
use App\Models\Training\EmployeeTrainingRecord;
use App\Models\Training\TrainingBudgetRequest;
use App\Models\Training\TrainingItem;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Everything the Training module knows, in one place — catalog, enrolment,
 * budget requests and certification expiry all draw from the same handful
 * of tables this session built up piece by piece. Static, the same shape as
 * App\Services\HR\PeopleReport, so the on-screen summary and the exported
 * workbook are guaranteed to agree: both call these methods, neither
 * recomputes its own version of "how many are overdue".
 *
 * Nothing here is sensitive the way a salary or a national ID is, so unlike
 * PeopleReport there is no password path — a plain .xlsx is enough.
 */
class TrainingReport
{
    private const DAYS_UNTIL_EXPIRY_WARNING = 90;

    public static function summary(int $orgId): array
    {
        return [
            'overview'                 => self::overview($orgId),
            'catalog_by_type'          => self::countBy(TrainingItem::where('organization_id', $orgId), 'type'),
            'catalog_by_delivery'      => self::countBy(TrainingItem::where('organization_id', $orgId), 'delivery'),
            'catalog_by_provider_type' => self::countBy(TrainingItem::where('organization_id', $orgId), 'training_provider_type'),
            'top_vendors'              => self::topVendors($orgId),
            'enrollment_by_status'     => self::countBy(EmployeeTrainingRecord::where('organization_id', $orgId), 'status'),
            'budget'                   => self::budgetSummary($orgId),
            'budget_requests_by_status'=> self::budgetRequestsByStatus($orgId),
            'certifications'           => self::certificationsSummary($orgId),
            'competencies_count'       => CompetencyItem::where('organization_id', $orgId)->count(),
        ];
    }

    private static function overview(int $orgId): array
    {
        $activeLearners = EmployeeTrainingRecord::where('organization_id', $orgId)
            ->whereIn('status', ['enrolled', 'in_progress'])
            ->distinct('employee_id')->count('employee_id');

        $completionsThisMonth = EmployeeTrainingRecord::where('organization_id', $orgId)
            ->where('status', 'completed')
            ->whereMonth('completed_at', now()->month)
            ->whereYear('completed_at', now()->year)
            ->count();

        $overdue = EmployeeTrainingRecord::where('organization_id', $orgId)
            ->whereNotIn('status', ['completed', 'withdrawn'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', today())
            ->count();

        $totalEnrolled  = EmployeeTrainingRecord::where('organization_id', $orgId)
            ->whereIn('status', ['enrolled', 'in_progress', 'completed'])->count();
        $totalCompleted = EmployeeTrainingRecord::where('organization_id', $orgId)
            ->where('status', 'completed')->count();

        return [
            'active_learners'         => $activeLearners,
            'completions_this_month'  => $completionsThisMonth,
            'overdue'                 => $overdue,
            'completion_rate_percent' => $totalEnrolled > 0 ? round(($totalCompleted / $totalEnrolled) * 100, 1) : 0,
            'catalog_total'           => TrainingItem::where('organization_id', $orgId)->count(),
            'catalog_active'          => TrainingItem::where('organization_id', $orgId)->where('is_active', true)->count(),
        ];
    }

    private static function countBy($query, string $column): array
    {
        return $query->selectRaw("COALESCE({$column}, 'not_set') as bucket, COUNT(*) as count")
            ->groupBy('bucket')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['label' => $row->bucket, 'count' => (int) $row->count])
            ->all();
    }

    private static function topVendors(int $orgId): array
    {
        return TrainingItem::where('training_items.organization_id', $orgId)
            ->whereNotNull('vendor_partner_id')
            ->join('vendor_partners', 'vendor_partners.id', '=', 'training_items.vendor_partner_id')
            ->selectRaw('vendor_partners.name as vendor, COUNT(*) as count')
            ->groupBy('vendor_partners.name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['vendor' => $row->vendor, 'count' => (int) $row->count])
            ->all();
    }

    private static function budgetSummary(int $orgId): array
    {
        return TrainingItem::where('organization_id', $orgId)
            ->where('is_active', true)
            ->selectRaw(
                "cost_currency,
                 COALESCE(SUM(budget_amount) FILTER (WHERE is_budgeted), 0) as total_budgeted_amount,
                 COALESCE(SUM(estimated_cost), 0) as total_estimated_cost"
            )
            ->groupBy('cost_currency')
            ->get()
            ->map(fn ($row) => [
                'currency'               => $row->cost_currency,
                'total_budgeted_amount'  => (float) $row->total_budgeted_amount,
                'total_estimated_cost'   => (float) $row->total_estimated_cost,
            ])
            ->all();
    }

    private static function budgetRequestsByStatus(int $orgId): array
    {
        return TrainingBudgetRequest::where('organization_id', $orgId)
            ->selectRaw('status, currency, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total')
            ->groupBy('status', 'currency')
            ->get()
            ->map(fn ($row) => [
                'status'   => $row->status,
                'currency' => $row->currency,
                'count'    => (int) $row->count,
                'total'    => (float) $row->total,
            ])
            ->all();
    }

    private static function certificationsSummary(int $orgId): array
    {
        $issuingItems = TrainingItem::where('organization_id', $orgId)->where('certificate_issued', true)->count();

        $warningDate = today()->addDays(self::DAYS_UNTIL_EXPIRY_WARNING);

        $expired = EmployeeTrainingRecord::where('organization_id', $orgId)
            ->whereNotNull('certificate_expiry_date')
            ->where('certificate_expiry_date', '<', today())
            ->count();

        $expiringSoon = EmployeeTrainingRecord::where('organization_id', $orgId)
            ->whereNotNull('certificate_expiry_date')
            ->whereBetween('certificate_expiry_date', [today(), $warningDate])
            ->count();

        $held = EmployeeTrainingRecord::where('organization_id', $orgId)
            ->whereNotNull('certificate_number')
            ->count();

        return [
            'certificate_issuing_items' => $issuingItems,
            'certificates_held'         => $held,
            'expiring_within_90_days'   => $expiringSoon,
            'expired'                   => $expired,
        ];
    }

    // ── Export ───────────────────────────────────────────────────────────────

    public static function buildSpreadsheet(int $orgId): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        self::overviewSheet($spreadsheet, $orgId);
        self::catalogSheet($spreadsheet, $orgId);
        self::enrollmentsSheet($spreadsheet, $orgId);
        self::budgetRequestsSheet($spreadsheet, $orgId);
        self::certificationsSheet($spreadsheet, $orgId);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private static function addSheet(Spreadsheet $spreadsheet, string $title, array $headings): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $sheet->fromArray($headings, null, 'A1');

        $lastColumn = $sheet->getCell([count($headings), 1])->getColumn();
        $header = $sheet->getStyle("A1:{$lastColumn}1");
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EFF3F8');
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->freezePane('A2');

        return $sheet;
    }

    private static function autosize(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $columnCount): void
    {
        foreach (range(1, $columnCount) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
    }

    private static function writeRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, array $values): void
    {
        foreach (array_values($values) as $i => $value) {
            $sheet->setCellValueExplicit([$i + 1, $row], (string) ($value ?? ''), DataType::TYPE_STRING);
        }
    }

    private static function overviewSheet(Spreadsheet $spreadsheet, int $orgId): void
    {
        $sheet = self::addSheet($spreadsheet, 'Overview', ['Metric', 'Value']);
        $overview = self::overview($orgId);
        $certs = self::certificationsSummary($orgId);

        $rows = [
            ['Active Learners', $overview['active_learners']],
            ['Completions This Month', $overview['completions_this_month']],
            ['Overdue Trainings', $overview['overdue']],
            ['Completion Rate', $overview['completion_rate_percent'] . '%'],
            ['Catalog Items (Total)', $overview['catalog_total']],
            ['Catalog Items (Active)', $overview['catalog_active']],
            ['Certificate-Issuing Items', $certs['certificate_issuing_items']],
            ['Certificates Held', $certs['certificates_held']],
            ['Certificates Expiring Within 90 Days', $certs['expiring_within_90_days']],
            ['Certificates Expired', $certs['expired']],
            ['Competencies Tracked', CompetencyItem::where('organization_id', $orgId)->count()],
        ];

        $row = 2;
        foreach ($rows as $r) {
            self::writeRow($sheet, $row, $r);
            $row++;
        }

        foreach (self::budgetSummary($orgId) as $b) {
            self::writeRow($sheet, $row, ["Total Budgeted ({$b['currency']})", number_format($b['total_budgeted_amount'], 2)]);
            $row++;
            self::writeRow($sheet, $row, ["Total Estimated Cost ({$b['currency']})", number_format($b['total_estimated_cost'], 2)]);
            $row++;
        }

        foreach (self::budgetRequestsByStatus($orgId) as $b) {
            self::writeRow($sheet, $row, ["Budget Requests: {$b['status']} ({$b['currency']})", "{$b['count']} request(s), " . number_format($b['total'], 2)]);
            $row++;
        }

        self::autosize($sheet, 2);
    }

    private static function catalogSheet(Spreadsheet $spreadsheet, int $orgId): void
    {
        $headings = [
            'Code', 'Title', 'Type', 'Delivery', 'Level', 'Provider Type', 'Provider',
            'Technical', 'Certificate Issued', 'Certification Name', 'Validity (yrs)',
            'Estimated Cost', 'Currency', 'Budgeted', 'Budget Amount', 'Enrolled', 'Active',
        ];
        $sheet = self::addSheet($spreadsheet, 'Catalog', $headings);

        $row = 2;
        TrainingItem::where('organization_id', $orgId)
            ->withCount('trainingRecords')
            ->with(['certifyingBody', 'vendorPartner'])
            ->orderBy('title')
            ->chunk(200, function ($items) use ($sheet, &$row) {
                foreach ($items as $item) {
                    $provider = $item->training_provider_type === 'vendor_oem'
                        ? ($item->vendorPartner->name ?? '—')
                        : ($item->certifyingBody->name ?? '—');

                    self::writeRow($sheet, $row, [
                        $item->training_code, $item->title, $item->type, $item->delivery, $item->level ?? '—',
                        $item->training_provider_type ?? '—', $provider,
                        $item->is_technical ? 'Yes' : 'No',
                        $item->certificate_issued ? 'Yes' : 'No',
                        $item->certification_name ?? '',
                        $item->validity_years ?? '',
                        number_format((float) $item->estimated_cost, 2), $item->cost_currency,
                        $item->is_budgeted ? 'Yes' : 'No',
                        $item->is_budgeted ? number_format((float) $item->budget_amount, 2) : '',
                        $item->training_records_count ?? 0,
                        $item->is_active ? 'Yes' : 'No',
                    ]);
                    $row++;
                }
            });

        self::autosize($sheet, count($headings));
    }

    private static function enrollmentsSheet(Spreadsheet $spreadsheet, int $orgId): void
    {
        $headings = [
            'Employee', 'Training', 'Assignment Type', 'Status', 'Progress %',
            'Started', 'Completed', 'Due Date', 'Score', 'Certificate #', 'Certificate Expiry',
        ];
        $sheet = self::addSheet($spreadsheet, 'Enrollments', $headings);

        $row = 2;
        EmployeeTrainingRecord::where('organization_id', $orgId)
            ->with(['employee', 'trainingItem'])
            ->orderByDesc('created_at')
            ->chunk(200, function ($records) use ($sheet, &$row) {
                foreach ($records as $record) {
                    self::writeRow($sheet, $row, [
                        $record->employee->full_name ?? '—',
                        $record->trainingItem->title ?? '—',
                        $record->assignment_type, $record->status, $record->progress_percent,
                        optional($record->started_at)->toDateString(),
                        optional($record->completed_at)->toDateString(),
                        optional($record->due_date)->toDateString(),
                        $record->score,
                        $record->certificate_number,
                        optional($record->certificate_expiry_date)->toDateString(),
                    ]);
                    $row++;
                }
            });

        self::autosize($sheet, count($headings));
    }

    private static function budgetRequestsSheet(Spreadsheet $spreadsheet, int $orgId): void
    {
        $headings = [
            'Mode', 'Training / Employee', 'Total Amount', 'Currency', 'Status',
            'Manager', 'Manager Decided', 'Finance Decided By', 'Finance Decided',
            'Rejected Stage', 'Rejection Reason', 'Submitted By', 'Submitted',
        ];
        $sheet = self::addSheet($spreadsheet, 'Budget Requests', $headings);

        $row = 2;
        TrainingBudgetRequest::where('organization_id', $orgId)
            ->with(['trainingItem', 'employee', 'manager', 'financeDecidedBy', 'submittedBy'])
            ->orderByDesc('created_at')
            ->chunk(200, function ($requests) use ($sheet, &$row) {
                foreach ($requests as $req) {
                    $subject = $req->mode === 'by_training'
                        ? ($req->trainingItem->title ?? '—')
                        : ($req->employee->full_name ?? '—');

                    self::writeRow($sheet, $row, [
                        $req->mode, $subject, number_format((float) $req->total_amount, 2), $req->currency, $req->status,
                        $req->manager->name ?? '—', optional($req->manager_decided_at)->toDateTimeString(),
                        $req->financeDecidedBy->name ?? '—', optional($req->finance_decided_at)->toDateTimeString(),
                        $req->rejected_stage ?? '', $req->rejection_reason ?? '',
                        $req->submittedBy->name ?? '—', $req->created_at->toDateTimeString(),
                    ]);
                    $row++;
                }
            });

        self::autosize($sheet, count($headings));
    }

    private static function certificationsSheet(Spreadsheet $spreadsheet, int $orgId): void
    {
        $headings = ['Employee', 'Training', 'Certificate #', 'Score', 'Expiry Date', 'Days Until Expiry'];
        $sheet = self::addSheet($spreadsheet, 'Certifications', $headings);

        $row = 2;
        EmployeeTrainingRecord::where('organization_id', $orgId)
            ->whereNotNull('certificate_number')
            ->with(['employee', 'trainingItem'])
            ->orderBy('certificate_expiry_date')
            ->chunk(200, function ($records) use ($sheet, &$row) {
                foreach ($records as $record) {
                    $daysLeft = $record->certificate_expiry_date
                        ? today()->diffInDays($record->certificate_expiry_date, false)
                        : null;

                    self::writeRow($sheet, $row, [
                        $record->employee->full_name ?? '—',
                        $record->trainingItem->title ?? '—',
                        $record->certificate_number, $record->score,
                        optional($record->certificate_expiry_date)->toDateString(),
                        $daysLeft,
                    ]);
                    $row++;
                }
            });

        self::autosize($sheet, count($headings));
    }
}
