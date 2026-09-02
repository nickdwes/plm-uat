<?php

namespace App\Services\Pipeline;

use App\Models\Pipeline\Opportunity;
use App\Models\Pipeline\OpportunityPhase;
use App\Support\Pipeline\LeadQualification;
use App\Support\Pipeline\OpportunityScore;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules that govern moving a deal through the pipeline.
 *
 * The legacy had none of these: its fourteen phases were a dropdown, and a
 * deal could sit in Bidding with no bid, or Contracting with no contract, and
 * frequently did — seventeen of its 130 rows were in Project Kick-Off while
 * every one of them still said status 'Pending'.
 *
 * What is enforced here is only what would otherwise be silently wrong. The
 * gates are deliberately few: a process that refuses every step out of order
 * gets worked around, and the workaround is worse than the disorder.
 */
class OpportunityStageService
{
    /**
     * What each stage requires before it can be marked complete.
     *
     * Keyed on the phase name as seeded. A stage with no entry here can be
     * completed whenever somebody judges it done, which is most of them.
     */
    private const GATES = [
        'Opportunity Commit' => 'commit',
        'Bidding'            => 'bidding',
        'Contracting'        => 'contracting',
        'Project Kick-Off'   => 'kickoff',
    ];

    public function __construct(private readonly PipelineService $pipeline) {}

    /**
     * Mark a stage finished.
     *
     * Idempotent: completing an already-complete stage keeps the original
     * timestamp, because when a thing was first done is the useful fact and
     * a second click should not rewrite it.
     */
    public function complete(Opportunity $opportunity, OpportunityPhase $phase, ?int $actorId): Opportunity
    {
        $this->assertGate($opportunity, $phase);

        DB::transaction(function () use ($opportunity, $phase, $actorId) {
            $opportunity->stageProgress()->firstOrCreate(
                ['opportunity_phase_id' => $phase->id],
                [
                    'organization_id' => $opportunity->organization_id,
                    'completed_at'    => now(),
                    'completed_by'    => $actorId,
                ],
            );

            /*
             * Completing a stage moves the deal to the next one it has not
             * finished, unless it is already further along. Somebody ticking
             * off a stage they went back to fix should not drag the deal
             * backwards with it.
             */
            $next = $this->nextIncomplete($opportunity->fresh('stageProgress'));

            if ($next && ($opportunity->phase?->position ?? 0) < $next->position) {
                $opportunity->update(['opportunity_phase_id' => $next->id]);
            }
        });

        return $opportunity->fresh(['phase', 'stageProgress.phase']);
    }

    public function reopen(Opportunity $opportunity, OpportunityPhase $phase): Opportunity
    {
        $opportunity->stageProgress()->where('opportunity_phase_id', $phase->id)->delete();

        return $opportunity->fresh(['phase', 'stageProgress.phase']);
    }

    /**
     * Confirm the budget, which is what releases the deal to Commit.
     *
     * Held as its own act rather than a field on an edit form: the screen
     * offers Confirm and Revoke, and both need to be recorded with who and
     * when — a budget that was confirmed and then withdrawn is a different
     * story from one never confirmed.
     */
    public function confirmBudget(Opportunity $opportunity, float $amount, ?int $actorId): Opportunity
    {
        $opportunity->update([
            'confirmed_budget'    => $amount,
            'budget_confirmed_at' => now(),
            'budget_confirmed_by' => $actorId,
        ]);

        return $opportunity->fresh();
    }

    public function revokeBudget(Opportunity $opportunity): Opportunity
    {
        // The amount is kept. Revoking says the confirmation is withdrawn, not
        // that nobody ever quoted a figure.
        $opportunity->update(['budget_confirmed_at' => null, 'budget_confirmed_by' => null]);

        return $opportunity->fresh();
    }

    /**
     * Mark the deal qualified.
     *
     * The design's wording is "once all criteria are met", and the criteria
     * are the fit assessment. Refusing when it has not been done at all is
     * worth it — a deal qualified against nothing is the claim this stage
     * exists to prevent.
     *
     * The refusal names the questions still open. It used to say only "assess
     * it first", which is true and useless: the whole live book failed this
     * gate at once and the message gave nobody a next move. A control that
     * blocks work should say what unblocks it.
     */
    public function qualify(Opportunity $opportunity): Opportunity
    {
        if (LeadQualification::score($opportunity) === null) {
            $missing = LeadQualification::unanswered($opportunity);

            throw ValidationException::withMessages([
                'qualification' => 'Answer the qualification questions before marking this qualified — '
                    .'still open: '.implode(', ', $missing).'.',
            ]);
        }

        $opportunity->update(['qualified_at' => now()]);

        return $opportunity->fresh();
    }

    /** The first stage in order that has not been completed. */
    private function nextIncomplete(Opportunity $opportunity): ?OpportunityPhase
    {
        $done = $opportunity->stageProgress->pluck('opportunity_phase_id')->all();

        return OpportunityPhase::where('organization_id', $opportunity->organization_id)
            ->where('is_active', true)
            ->whereNull('outcome')
            ->whereNotIn('id', $done)
            ->orderBy('position')
            ->first();
    }

    private function assertGate(Opportunity $opportunity, OpportunityPhase $phase): void
    {
        $gate = self::GATES[$phase->name] ?? null;

        match ($gate) {
            // Sixty-five is the threshold the business set. Enforced here
            // because it is the first time the score has decided anything.
            'commit' => OpportunityScore::meetsCommitThreshold($opportunity) ?: throw ValidationException::withMessages([
                'stage' => sprintf(
                    'This deal scores %d. It needs %d before it can be committed.',
                    OpportunityScore::for($opportunity),
                    OpportunityScore::COMMIT_THRESHOLD,
                ),
            ]),

            'bidding' => $opportunity->bids()->exists() ?: throw ValidationException::withMessages([
                'stage' => 'Record the bid before closing this stage.',
            ]),

            'contracting' => $opportunity->contracts()->exists() ?: throw ValidationException::withMessages([
                'stage' => 'Add the contract before closing this stage.',
            ]),

            'kickoff' => $opportunity->projects()->exists() ?: throw ValidationException::withMessages([
                'stage' => 'Create the project before closing this stage.',
            ]),

            default => true,
        };
    }
}

