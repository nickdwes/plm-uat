<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\Training\TrainingItem;
use App\Services\Training\TrainingReport;
use Illuminate\Http\JsonResponse;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrainingReportController extends Controller
{
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', TrainingItem::class);

        return response()->json(['data' => TrainingReport::summary(app('current.organization_id'))]);
    }

    public function export(): StreamedResponse
    {
        $this->authorize('viewAny', TrainingItem::class);

        $spreadsheet = TrainingReport::buildSpreadsheet(app('current.organization_id'));
        $stamp = now()->format('Ymd-His');

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, "training-report-{$stamp}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
