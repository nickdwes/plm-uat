<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PaymentBatch;
use App\Models\PayrollPeriod;
use App\Services\Payroll\PaymentFileBuilder;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The payroll as a file a bank will accept.
 *
 * Guarded on the period being approved. A draft can still be recomputed, and a
 * payment file is the one artefact here that moves money the moment somebody
 * else uploads it.
 */
class PaymentFileController extends Controller
{
    public function __construct(
        private readonly PaymentFileBuilder $builder,
        private readonly PayrollService $service,
    ) {}

    /**
     * Who would be paid and who would not, before anything is generated.
     *
     * The excluded list is the point. A payment file's whole risk is that it
     * looks complete — the bank pays what it is given and reports success, and
     * the person left out finds out on payday.
     */
    public function preview(int $payrollPeriod): JsonResponse
    {
        $this->authorize('view', PayrollPeriod::class);

        $period  = $this->service->getPeriod($payrollPeriod);
        $preview = $this->builder->preview($period);

        return response()->json([
            'period'    => ['id' => $period->id, 'name' => $period->name, 'status' => $period->status],
            'payable'   => $preview['count'],
            'total'     => $preview['total'],
            'rows'      => $preview['rows'],
            'excluded'  => $preview['excluded'],
            /*
             * The gap, stated rather than left to be worked out. The payroll
             * total and the batch total differ by exactly the excluded people,
             * and somebody signing this off needs to see that they agree.
             */
            'excluded_total' => round(array_sum(array_column($preview['excluded'], 'amount')), 2),
            'formats'   => PaymentFileBuilder::FORMATS,
            'ready'     => in_array($period->status, [
                PayrollPeriod::STATUS_APPROVED, PayrollPeriod::STATUS_PAID,
            ], true),
            'batches'   => PaymentBatch::where('payroll_period_id', $period->id)
                ->orderByDesc('id')->get([
                    'id', 'reference', 'format', 'record_count', 'total_amount',
                    'excluded_count', 'created_at',
                ]),
        ]);
    }

    /**
     * Generate it, record it, and send it.
     *
     * Recorded before it is sent: "has this month gone to the bank?" has the
     * same wrong answer available as the payslip question — nobody knows, so
     * do it again — and a payroll file uploaded twice pays everybody twice.
     */
    public function download(Request $request, int $payrollPeriod): StreamedResponse|JsonResponse
    {
        $this->authorize('update', PayrollPeriod::class);

        $data = $request->validate([
            'format'     => ['nullable', 'string', 'in:standard,simple'],
            'value_date' => ['nullable', 'date'],
        ]);

        $period = $this->service->getPeriod($payrollPeriod);

        abort_unless(
            in_array($period->status, [PayrollPeriod::STATUS_APPROVED, PayrollPeriod::STATUS_PAID], true),
            422,
            "'{$period->name}' has not been approved. A draft can still be recomputed, and a payment "
            . 'file moves money the moment somebody uploads it.',
        );

        $preview = $this->builder->preview($period);

        if ($preview['count'] === 0) {
            return response()->json([
                'message' => 'Nobody in this period can be paid by transfer — every employee is either '
                    . 'missing bank details or has nothing to pay. Nothing was generated.',
                'excluded' => $preview['excluded'],
            ], 422);
        }

        $format    = $data['format'] ?? 'standard';
        $valueDate = $data['value_date'] ?? $period->payment_date?->toDateString();
        $reference = PaymentBatch::nextReference($period->organization_id, $period);

        $content = $this->builder->build($preview, $format, $reference, $valueDate);

        PaymentBatch::create([
            'organization_id'   => $period->organization_id,
            'payroll_period_id' => $period->id,
            'reference'         => $reference,
            'format'            => $format,
            'value_date'        => $valueDate,
            'record_count'      => $preview['count'],
            'total_amount'      => $preview['total'],
            'excluded_count'    => count($preview['excluded']),
            'excluded'          => $preview['excluded'],
            'generated_by'      => $request->user()?->id,
        ]);

        $filename = $reference . '.csv';

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, [
            'Content-Type'  => 'text/csv',
            'Cache-Control' => 'no-store',
        ]);
    }
}
