<?php

namespace App\Services\Travel;

use App\Models\Travel\TravelExpenseEvidence;
use App\Models\Travel\TravelRequest;
use App\Models\Travel\TravelRequestItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Closing a trip once it has happened.
 *
 * Reconciliation only works if it asks the right person for the right thing.
 * The legacy system demanded a receipt for everything from the traveller, and
 * its reconciliation table held zero rows across 102 requests — people simply
 * did not do it, because half the documents were never theirs to produce.
 *
 * So each category states what closing it requires and who supplies it:
 *
 *   nothing            settled on approval
 *   an acknowledgement the traveller confirms an allowance reached them
 *   a receipt          a document, from whoever actually holds it
 *
 * A request closes when every line that needs something has it. Nothing here
 * decides whether the spend was reasonable — that is the approver's job, and
 * it already happened.
 */
class TravelReconciliationService
{
    /**
     * Attach a receipt to one line.
     *
     * `actual_cost` is written from the evidence rather than the estimate,
     * because the difference between the two is the entire reason to
     * reconcile. Leaving it as the estimate would make every trip look
     * perfectly forecast.
     */
    public function attachReceipt(
        TravelRequestItem $item,
        UploadedFile $file,
        string $explanation,
        ?float $amount,
        ?int $employeeId,
    ): TravelExpenseEvidence {
        if (! $this->isReconcilable($item->request)) {
            throw new RuntimeException('Only an approved request can be reconciled.');
        }

        return DB::transaction(function () use ($item, $file, $explanation, $amount, $employeeId) {
            $extension = $file->getClientOriginalExtension() ?: 'bin';
            $path = "{$item->request->organization_id}/{$item->travel_request_id}/"
                . Str::uuid() . ".{$extension}";

            Storage::disk('travel_evidence')->putFileAs(dirname($path), $file, basename($path));

            $evidence = $item->evidence()->create([
                'kind'                     => 'receipt',
                'explanation'              => $explanation,
                'storage_path'             => $path,
                'original_name'            => $file->getClientOriginalName(),
                'mime_type'                => $file->getMimeType() ?? $file->getClientMimeType(),
                'size_bytes'               => $file->getSize(),
                'amount'                   => $amount,
                'submitted_by_employee_id' => $employeeId,
                'submitted_at'             => now(),
            ]);

            if ($amount !== null) {
                $item->update(['actual_cost' => $amount]);
            }

            $item->request->recordActivity(
                'reconciled',
                'Receipt added for ' . ($item->category?->name ?? 'an expense'),
                $employeeId,
            );

            return $evidence;
        });
    }

    /**
     * Record that an allowance was received.
     *
     * The only check a per diem admits: there is no document to produce, but
     * an allowance nobody confirms receiving is an allowance nobody can show
     * was paid.
     */
    public function acknowledge(
        TravelRequestItem $item,
        string $explanation,
        ?int $employeeId,
    ): TravelExpenseEvidence {
        if (! $this->isReconcilable($item->request)) {
            throw new RuntimeException('Only an approved request can be reconciled.');
        }

        return DB::transaction(function () use ($item, $explanation, $employeeId) {
            $evidence = $item->evidence()->create([
                'kind'                     => 'acknowledgement',
                'explanation'              => $explanation,
                // The approved amount is what was acknowledged as received.
                'amount'                   => $item->amount_approved ?? $item->amount_claimed,
                'submitted_by_employee_id' => $employeeId,
                'submitted_at'             => now(),
            ]);

            $item->update(['actual_cost' => $evidence->amount]);

            $item->request->recordActivity(
                'reconciled',
                'Receipt of ' . ($item->category?->name ?? 'an allowance') . ' acknowledged',
                $employeeId,
            );

            return $evidence;
        });
    }

    /**
     * What is still outstanding, and who owes it.
     *
     * Named per line rather than reported as a count, because "3 items
     * outstanding" tells nobody whether they are the one holding it up.
     *
     * @return array<int, array{item_id: int, category: string, needs: string, from: string}>
     */
    public function outstanding(TravelRequest $request): array
    {
        return $request->items
            ->filter(fn (TravelRequestItem $item) => $item->awaitsEvidence())
            ->map(fn (TravelRequestItem $item) => [
                'item_id'  => $item->id,
                'category' => $item->category?->name ?? 'Uncategorised',
                'needs'    => $item->category?->evidence_type ?? 'none',
                'from'     => $item->category?->evidence_provider ?? 'requestor',
            ])
            ->values()
            ->all();
    }

    /**
     * Close the trip.
     *
     * Refused while anything is outstanding: a closed request is the record
     * that the money was accounted for, and closing one that is not makes the
     * status worthless everywhere it appears.
     */
    public function close(TravelRequest $request, ?int $employeeId): TravelRequest
    {
        if (! $this->isReconcilable($request)) {
            throw new RuntimeException('Only an approved request can be closed.');
        }

        $outstanding = $this->outstanding($request);

        if ($outstanding !== []) {
            throw new RuntimeException(
                'Still waiting on evidence for: '
                . implode(', ', array_column($outstanding, 'category')) . '.'
            );
        }

        /*
         * A delivery closes against its paperwork, not against fuel receipts.
         *
         * The note proves it arrived and the manifest proves what arrived —
         * and a delivery where four of five items turned up is exactly the
         * case a single "delivered" flag would close silently.
         */
        $gaps = $request->load('manifest')->deliveryGaps();

        if ($gaps !== []) {
            throw new RuntimeException('This delivery still needs ' . implode(', ', $gaps) . '.');
        }

        $request->update([
            'status'                    => 'closed',
            'reconciled_at'             => now(),
            'reconciled_by_employee_id' => $employeeId,
        ]);

        $request->recordActivity('closed', 'Reconciled and closed', $employeeId);

        return $request->fresh(['items.evidence', 'activity.actor']);
    }

    /** A trip can only be reconciled once it has been approved and taken. */
    private function isReconcilable(TravelRequest $request): bool
    {
        return in_array($request->status, ['approved', 'disbursed'], true);
    }
}
