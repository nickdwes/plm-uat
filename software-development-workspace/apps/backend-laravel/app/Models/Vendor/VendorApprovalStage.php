<?php

namespace App\Models\Vendor;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * The chain a supplier is put through, as this organisation configures it.
 *
 * Sequential, and deliberately so: the house stance, written down in
 * Support's ApprovalService, is that asking everybody at once gets a faster
 * answer and a worse one.
 */
class VendorApprovalStage extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'stage', 'sequence', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sequence' => 'integer'];

    /**
     * The default chain.
     *
     * Held here rather than only in the seeding migration, for the same
     * reason the document requirements are: an organisation created LATER
     * would otherwise have no chain at all, and its first submission would
     * fail with "no approval chain is configured" — a sentence that blames
     * the operator for something nobody could have set up.
     */
    public const STAGES = [
        'registration_review',
        'compliance_review',
        'finance_review',
        'final_approval',
    ];

    /** Write the default chain into this organisation's own rows. */
    public static function materialiseFor(int $organizationId): void
    {
        if (static::where('organization_id', $organizationId)->exists()) {
            return;
        }

        foreach (array_values(self::STAGES) as $index => $stage) {
            static::create([
                'organization_id' => $organizationId,
                'stage'           => $stage,
                'sequence'        => $index + 1,
                'is_active'       => true,
            ]);
        }
    }

    /**
     * The chain as it applies, defaults included.
     *
     * All-or-nothing like the requirements rulebook: once a tenant has a row
     * of its own it owns the chain, so removing a stage is expressible.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function forOrganization(int $organizationId): \Illuminate\Support\Collection
    {
        $own = static::query()
            ->where('organization_id', $organizationId)
            ->orderBy('sequence')
            ->get();

        if ($own->isNotEmpty()) {
            return $own->where('is_active', true)->values();
        }

        return collect(array_values(self::STAGES))
            ->map(fn (string $stage, int $index) => new static([
                'organization_id' => $organizationId,
                'stage'           => $stage,
                'sequence'        => $index + 1,
                'is_active'       => true,
            ]));
    }
}
