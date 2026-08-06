<?php

namespace Tests\Feature\Pipeline;

use App\Models\Pipeline\OpportunityDocument;
use App\Support\Storage\MirrorPolicy;
use Tests\TestCase;

/**
 * What may be copied to SharePoint.
 *
 * SharePoint is a workspace, so mirrored files are plaintext there — governed
 * by SharePoint's permissions rather than ours. That makes the list of what
 * leaves the single most consequential thing in the feature, and the cheapest
 * to get wrong quietly.
 *
 * Reads as a decision rather than a test of behaviour, because that is what it
 * is: the list is the feature.
 */
class MirrorPolicyTest extends TestCase
{
    private function doc(string $stage, ?string $slot = null, string $type = 'internal'): OpportunityDocument
    {
        return OpportunityDocument::make([
            'stage' => $stage, 'slot' => $slot, 'document_type' => $type,
        ]);
    }

    public function test_working_material_is_mirrored(): void
    {
        // The files people actually want open in a browser tab.
        foreach (['site_survey', 'specification', 'proposal', 'kickoff'] as $stage) {
            $this->assertTrue(MirrorPolicy::allows($this->doc($stage)), $stage);
        }
    }

    public function test_bids_and_negotiations_never_leave(): void
    {
        $this->assertFalse(MirrorPolicy::allows($this->doc('bid')));
        $this->assertFalse(MirrorPolicy::allows($this->doc('negotiation')));
        $this->assertFalse(MirrorPolicy::allows($this->doc('contracting')));
    }

    public function test_anything_financial_never_leaves_whatever_stage_it_is_in(): void
    {
        // A price filed under a mirrored stage is still a price.
        $this->assertFalse(MirrorPolicy::allows($this->doc('specification', null, 'financial')));
    }

    public function test_a_named_sensitive_slot_never_leaves_even_in_a_mirrored_stage(): void
    {
        // Somebody filing the signed contract against the kickoff pack is a
        // reasonable thing to do, and must not publish it.
        $this->assertFalse(MirrorPolicy::allows($this->doc('kickoff', 'signed_contract')));
        $this->assertFalse(MirrorPolicy::allows($this->doc('proposal', 'discount_workings')));
    }

    public function test_an_unknown_stage_is_not_mirrored(): void
    {
        /*
         * Deny by default. A document class added later stays on the platform
         * until somebody decides otherwise — the opposite of how these lists
         * usually rot.
         */
        $this->assertFalse(MirrorPolicy::allows($this->doc('some_new_stage')));
        $this->assertFalse(MirrorPolicy::allows($this->doc('general')));
    }

    public function test_a_refusal_explains_itself(): void
    {
        // "Not in SharePoint" sends somebody hunting for a bug.
        $this->assertNull(MirrorPolicy::reason($this->doc('specification')));
        $this->assertStringContainsString('Financial', MirrorPolicy::reason($this->doc('specification', null, 'financial')) ?? '');
        $this->assertStringContainsString('Tender', MirrorPolicy::reason($this->doc('kickoff', 'signed_contract')) ?? '');
    }
}
