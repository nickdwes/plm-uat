<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A file attached at some point in the pipeline.
 *
 * One table for every stage, because attaching a file is one act however many
 * places it happens. The legacy modelled it twenty times across two tables of
 * integer pointers and Plmv2 seventeen times as bare path strings; each
 * repetition is another place to forget that these belong on a private disk.
 */
class OpportunityDocument extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'opportunity_documents';

    protected $fillable = [
        'organization_id', 'opportunity_id', 'stage', 'slot', 'document_type',
        'label', 'original_name', 'storage_path', 'mime_type', 'size_bytes',
        'bid_id', 'estimate_id', 'uploaded_by',
    ];

    protected $casts = ['size_bytes' => 'integer'];

    /** Where in the process it was attached. */
    public const STAGES = [
        'site_survey', 'estimate', 'specification', 'bid', 'negotiation',
        'contracting', 'kickoff', 'proposal', 'general',
    ];

    /**
     * The named slots the legacy had a column for.
     *
     * Kept as a list rather than free text so the stage screens can ask for a
     * specific document — "the completed tender checklist" — and know whether
     * they have it.
     */
    public const SLOTS = [
        'tender_document', 'tender_checklist', 'completed_tender_checklist',
        'tender_financial_workings', 'tender_response', 'tender_decision_notes',
        'tender_opening_results', 'discount_workings', 'award_letter',
        'lost_bid_rca', 'final_quotation', 'supplier_quotation',
        'signed_contract', 'scope_of_work', 'sla', 'bill_of_materials',
        'solution_estimate',
    ];

    public const DOCUMENT_TYPES = ['client', 'internal', 'financial', 'technical'];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(OpportunityBid::class, 'bid_id');
    }

    public function scopeForStage(Builder $q, string $stage): Builder
    {
        return $q->where('stage', $stage);
    }
}
