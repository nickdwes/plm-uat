<?php

namespace App\Support\Storage;

use App\Models\Pipeline\OpportunityDocument;

/**
 * Which documents may be copied to SharePoint, and which never leave.
 *
 * SharePoint is wanted as a workspace — somewhere people open, preview, search
 * and co-author these files. That decides the design, and it costs something
 * that has to be said out loud: **a mirrored file is plaintext there.** Our
 * encryption protects the copy in blob storage; it cannot protect a copy living
 * in a document library governed by SharePoint's permissions instead of ours.
 *
 * So the answer is not "mirror everything" or "mirror nothing" but a list, and
 * this class is that list. It is deliberately a deny-by-default allow-list: a
 * document class added later is not mirrored until somebody decides it should
 * be, which is the opposite of how these things usually rot.
 *
 * Nothing mirrors yet. This exists now because the classification is the part
 * that is expensive to get wrong and cheap to write down — once a batch job is
 * running, changing what it has already published means deleting things out of
 * somebody's SharePoint.
 */
final class MirrorPolicy
{
    /**
     * Pipeline stages whose documents are working material.
     *
     * These are the ones people genuinely want open in a browser tab: the
     * scope they are building to, the specification they are quoting against,
     * the proposal they are drafting together.
     */
    private const MIRRORED_STAGES = [
        'site_survey',
        'specification',
        'proposal',
        'kickoff',
    ];

    /**
     * Slots that never leave, whatever stage they sit in.
     *
     * A bid bond names what we have lodged; financial workings name our
     * margin; a signed contract carries terms and signatures. None of these is
     * co-authored, so nothing is lost by keeping them here — and a competitor
     * or a departing employee with SharePoint access is the exact threat the
     * encryption exists for.
     */
    private const NEVER_MIRROR_SLOTS = [
        'tender_financial_workings',
        'tender_response',
        'tender_decision_notes',
        'discount_workings',
        'signed_contract',
        'award_letter',
        'final_quotation',
        'supplier_quotation',
        'lost_bid_rca',
    ];

    /**
     * Document types that never leave regardless of stage.
     *
     * `financial` covers anything priced. Customer PII is not a type here
     * because it does not belong to this table at all — candidate documents and
     * employee records are on their own disks and are not mirrorable by this
     * policy in any form.
     */
    private const NEVER_MIRROR_TYPES = ['financial'];

    public static function allows(OpportunityDocument $document): bool
    {
        if (in_array($document->document_type, self::NEVER_MIRROR_TYPES, true)) {
            return false;
        }

        if ($document->slot !== null && in_array($document->slot, self::NEVER_MIRROR_SLOTS, true)) {
            return false;
        }

        return in_array($document->stage, self::MIRRORED_STAGES, true);
    }

    /**
     * Why a document is not mirrored, for a screen that has to explain it.
     *
     * "Not in SharePoint" invites somebody to go looking for a bug; "bids stay
     * on the platform" is an answer.
     */
    public static function reason(OpportunityDocument $document): ?string
    {
        if (self::allows($document)) {
            return null;
        }

        return match (true) {
            in_array($document->document_type, self::NEVER_MIRROR_TYPES, true)
                => 'Financial documents stay on the platform.',
            $document->slot !== null && in_array($document->slot, self::NEVER_MIRROR_SLOTS, true)
                => 'Tender and contract paperwork stays on the platform.',
            default
                => 'Only working material — surveys, specifications, proposals and kickoff packs — is copied to SharePoint.',
        };
    }

    /** The stages that mirror, for documentation and for the eventual job. */
    public static function mirroredStages(): array
    {
        return self::MIRRORED_STAGES;
    }
}
