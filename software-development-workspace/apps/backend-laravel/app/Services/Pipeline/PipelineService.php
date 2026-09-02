<?php

namespace App\Services\Pipeline;

use App\Models\Account\Account;
use App\Models\Pipeline\Lead;
use App\Models\Pipeline\Opportunity;
use App\Support\Pipeline\LeadQualification;
use App\Models\Pipeline\OpportunityPhase;
use App\Services\Account\AccountActivityService;
use App\Support\Account\ReferenceSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PipelineService
{
    public function __construct(private readonly AccountActivityService $activities) {}

    public function createLead(array $data, int $organizationId, ?int $actorId): Lead
    {
        return DB::transaction(function () use ($data, $organizationId, $actorId) {
            $lead = Lead::create($data + [
                'reference'       => $data['reference'] ?? ReferenceSequence::next('LD', 'leads', $organizationId),
                'organization_id' => $organizationId,
                'created_by'      => $actorId,
            ]);

            // A lead raised against a customer belongs on that customer's
            // timeline. Recorded as a system entry: opening a lead is not the
            // same as having spoken to them.
            if ($lead->account) {
                $this->activities->record($lead->account, 'lead', "Lead raised: {$lead->name}", $actorId);
            }

            return $lead;
        });
    }

    /**
     * Turn a qualified lead into a deal.
     *
     * The legacy did this by writing "Attached to an opportunity" into the
     * lead's status, which is why its status column could not answer how many
     * leads were still open. Here the opportunity holds the link and the lead
     * is marked converted — one fact, recorded once.
     *
     * A customer is required. An opportunity is a deal with somebody, and the
     * legacy had every one of its 130 attached to an account; letting one
     * exist without would put revenue against nobody.
     */
    public function convertLead(Lead $lead, array $data, ?int $actorId): Opportunity
    {
        return DB::transaction(function () use ($lead, $data, $actorId) {
            if ($lead->opportunity()->exists()) {
                throw ValidationException::withMessages([
                    'lead' => 'This lead has already become an opportunity.',
                ]);
            }

            $accountId = $data['account_id'] ?? $lead->account_id;

            if ($accountId === null) {
                throw ValidationException::withMessages([
                    'account_id' => 'Name the customer before converting this lead — a deal has to be with somebody.',
                ]);
            }

            /*
             * The assessment travels with the lead.
             *
             * The deal keeps its own copy from this moment on — the picture at
             * bidding is not the one that justified opening the lead, and it
             * has to be free to diverge. But it starts from what was already
             * established, rather than making somebody answer five questions a
             * second time about the same customer on the same day.
             */
            $assessment = collect(array_keys(LeadQualification::DIMENSIONS))
                ->mapWithKeys(fn (string $key) => [$key => $data[$key] ?? $lead->{$key}])
                ->all();

            $opportunity = $this->createOpportunity(array_merge($data, $assessment, [
                'account_id'  => $accountId,
                'lead_id'     => $lead->id,
                // Inherited so the deal starts with what the lead established
                // rather than a blank form somebody has to retype.
                'name'        => $data['name'] ?? $lead->name,
                'description' => $data['description'] ?? $lead->proposed_solution,
                'owner_id'    => $data['owner_id'] ?? $lead->owner_id,
            ]), $lead->organization_id, $actorId);

            $lead->update(['status' => 'converted', 'account_id' => $accountId]);

            return $opportunity;
        });
    }

    public function createOpportunity(array $data, int $organizationId, ?int $actorId): Opportunity
    {
        return DB::transaction(function () use ($data, $organizationId, $actorId) {
            $opportunity = Opportunity::create($data + [
                'reference' => $data['reference'] ?? ReferenceSequence::next('OP', 'opportunities', $organizationId),
                // The first phase, so a new deal is somewhere rather than
                // nowhere — a null phase means neither open nor closed.
                'opportunity_phase_id' => $data['opportunity_phase_id'] ?? $this->firstPhase($organizationId)?->id,
                'organization_id'      => $organizationId,
                'created_by'           => $actorId,
            ]);

            $this->activities->record(
                Account::findOrFail($opportunity->account_id),
                'opportunity',
                "Opportunity opened: {$opportunity->name}",
                $actorId,
            );

            return $opportunity;
        });
    }

    /**
     * Move a deal to another phase, closing it if the phase says so.
     *
     * `closed_at` is stamped from the phase rather than set by the caller, so
     * "when was this won" cannot disagree with "is it won".
     */
    public function moveToPhase(Opportunity $opportunity, OpportunityPhase $phase, ?int $actorId): Opportunity
    {
        return DB::transaction(function () use ($opportunity, $phase, $actorId) {
            $opportunity->update([
                'opportunity_phase_id' => $phase->id,
                'closed_at'            => $phase->outcome ? ($opportunity->closed_at ?? now()) : null,
            ]);

            if ($phase->outcome) {
                $this->activities->record(
                    $opportunity->account,
                    'opportunity',
                    "Opportunity {$phase->outcome}: {$opportunity->name}",
                    $actorId,
                );
            }

            return $opportunity->fresh(['phase', 'owner', 'assignees.employee']);
        });
    }

    private function firstPhase(int $organizationId): ?OpportunityPhase
    {
        return OpportunityPhase::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereNull('outcome')
            ->orderBy('position')
            ->first();
    }
}
