<?php

namespace App\Services\Training;

use App\Models\Employee;
use App\Models\Training\TrainingBudgetRequest;
use App\Models\Training\TrainingBudgetSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TrainingBudgetRequestService
{
    private const WITH = ['items.employee', 'items.trainingItem', 'trainingItem', 'employee', 'manager', 'financeDecidedBy', 'submittedBy'];

    public function list(int $organizationId, array $filters = []): Collection
    {
        $query = TrainingBudgetRequest::where('organization_id', $organizationId)
            ->with(self::WITH)
            ->orderByDesc('created_at');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['mode'])) {
            $query->where('mode', $filters['mode']);
        }
        if (!empty($filters['training_item_id'])) {
            // Covers both shapes: the item named directly (by_training mode)
            // and the item appearing as one line among several (by_employee
            // mode) — a request naming this course either way belongs on
            // its budget history.
            $itemId = $filters['training_item_id'];
            $query->where(function ($q) use ($itemId) {
                $q->where('training_item_id', $itemId)
                  ->orWhereHas('items', fn ($q2) => $q2->where('training_item_id', $itemId));
            });
        }

        return $query->get();
    }

    /** Requests sitting at whichever stage this actor can currently act on. */
    public function myPending(int $organizationId, User $user): Collection
    {
        $canActOnFinance = $user->hasPermission(\App\Support\Authorization\Permission::TRAINING_BUDGET_APPROVE_FINANCE);

        return TrainingBudgetRequest::where('organization_id', $organizationId)
            ->with(self::WITH)
            ->where(function ($q) use ($user, $canActOnFinance) {
                $q->where(['status' => 'pending_manager', 'manager_id' => $user->id]);
                if ($canActOnFinance) {
                    $q->orWhere('status', 'pending_finance');
                }
            })
            ->orderByDesc('created_at')
            ->get();
    }

    public function create(int $organizationId, int $submittedByUserId, array $data, array $items): TrainingBudgetRequest
    {
        $totalAmount = collect($items)->sum('cost');
        $manager = $this->resolveManagerUser($submittedByUserId);
        $threshold = TrainingBudgetSetting::forOrganization($organizationId)->approval_threshold_amount;

        return DB::transaction(function () use ($organizationId, $submittedByUserId, $data, $items, $totalAmount, $manager, $threshold) {
            $request = TrainingBudgetRequest::create([
                'organization_id'      => $organizationId,
                'mode'                 => $data['mode'],
                'training_item_id'     => $data['training_item_id'] ?? null,
                'employee_id'          => $data['employee_id'] ?? null,
                'payment_instructions' => $data['payment_instructions'],
                'total_amount'         => $totalAmount,
                'currency'             => $data['currency'],
                'manager_id'           => $manager?->id,
                'submitted_by'         => $submittedByUserId,
                'status'               => $this->initialStatus($manager, (float) $totalAmount, $threshold ? (float) $threshold : null),
            ]);

            foreach ($items as $item) {
                $request->items()->create($item);
            }

            return $request->load(self::WITH);
        });
    }

    public function approveAsManager(TrainingBudgetRequest $request, ?string $notes): TrainingBudgetRequest
    {
        abort_unless($request->status === 'pending_manager', 422, 'This request is not awaiting manager approval.');

        $threshold = TrainingBudgetSetting::forOrganization($request->organization_id)->approval_threshold_amount;
        $nextStatus = $this->needsFinance((float) $request->total_amount, $threshold ? (float) $threshold : null)
            ? 'pending_finance' : 'approved';

        $request->update([
            'status'             => $nextStatus,
            'manager_decided_at' => now(),
            'manager_notes'      => $notes,
        ]);

        return $request->fresh(self::WITH);
    }

    public function approveAsFinance(TrainingBudgetRequest $request, User $actor, ?string $notes): TrainingBudgetRequest
    {
        abort_unless($request->status === 'pending_finance', 422, 'This request is not awaiting Finance approval.');

        $request->update([
            'status'             => 'approved',
            'finance_decided_by' => $actor->id,
            'finance_decided_at' => now(),
            'finance_notes'      => $notes,
        ]);

        return $request->fresh(self::WITH);
    }

    public function reject(TrainingBudgetRequest $request, string $stage, string $reason): TrainingBudgetRequest
    {
        abort_unless(
            in_array($request->status, ['pending_manager', 'pending_finance'], true),
            422,
            'This request has already been decided.'
        );

        $request->update([
            'status'           => 'rejected',
            'rejected_stage'   => $stage,
            'rejection_reason' => $reason,
        ]);

        return $request->fresh(self::WITH);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function resolveManagerUser(int $submittedByUserId): ?User
    {
        $employee = Employee::where('user_id', $submittedByUserId)->first();

        return $employee?->manager?->user;
    }

    /**
     * No manager on file skips straight to the threshold check rather than
     * blocking forever — the same grace TrainingAssignment gives a managerless
     * employee.
     */
    private function initialStatus(?User $manager, float $totalAmount, ?float $threshold): string
    {
        if ($manager) {
            return 'pending_manager';
        }

        return $this->needsFinance($totalAmount, $threshold) ? 'pending_finance' : 'approved';
    }

    private function needsFinance(float $totalAmount, ?float $threshold): bool
    {
        return $threshold !== null && $totalAmount > $threshold;
    }
}
