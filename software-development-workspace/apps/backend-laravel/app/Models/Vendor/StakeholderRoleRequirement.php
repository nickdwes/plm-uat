<?php

namespace App\Models\Vendor;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * What a role has to produce before it can be activated.
 *
 * Per-role rather than per-vendor because the paperwork follows what somebody
 * DOES for us: a distributor needs the manufacturer's authorisation letter, a
 * consultant needs an NDA, and asking either for the other's is how a form
 * loses the room. A party holding two roles owes the union of both lists.
 *
 * `mandatory` blocks activation. `optional` is a prompt — worth asking for,
 * never worth stopping a purchase over.
 */
class StakeholderRoleRequirement extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'role', 'document_key', 'label',
        'requirement', 'help', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public const REQUIREMENTS = ['mandatory', 'optional'];

    public function isMandatory(): bool
    {
        return $this->requirement === 'mandatory';
    }

    /**
     * The baseline every organisation starts from.
     *
     * Held here rather than only in the seeding migration so an organisation
     * created LATER is not born with an empty rulebook — a tenant added next
     * year would otherwise have no compliance requirements at all, and
     * nothing on screen would say why. `forOrganization()` falls back to
     * these until somebody customises, at which point their rows take over
     * completely.
     *
     * Kenyan-registry flavoured because that is where this runs. Every entry
     * is editable, so an organisation that disagrees changes it rather than
     * working around it.
     *
     * @var array<string, array<int, array{0:string,1:string,2:string,3:?string}>>
     */
    private const COMPANY_BASELINE = [
        ['certificate_of_incorporation', 'Certificate of Incorporation', 'mandatory', 'Proof the company legally exists.'],
        ['tax_compliance',               'Tax Compliance Certificate',   'mandatory', 'Renewed annually — expiry is tracked.'],
        ['kra_pin',                      'KRA PIN Certificate',          'mandatory', null],
        ['bank_confirmation',            'Bank Account Confirmation',    'mandatory', 'Confirms the account payments are sent to.'],
    ];

    public static function defaults(): array
    {
        return [
            'supplier'              => self::COMPANY_BASELINE,
            'service_provider'      => self::COMPANY_BASELINE,
            'oem'                   => self::COMPANY_BASELINE,
            'distributor'           => array_merge(self::COMPANY_BASELINE, [
                ['oem_authorization', 'OEM Authorisation Letter', 'mandatory', 'The manufacturer confirming this distributor may resell.'],
            ]),
            'channel_partner'       => array_merge(self::COMPANY_BASELINE, [
                ['partner_agreement', 'Signed Partner Agreement', 'mandatory', null],
            ]),
            'contractor_company'    => array_merge(self::COMPANY_BASELINE, [
                ['insurance_liability', 'Public Liability Insurance', 'mandatory', 'Must be in force for the whole engagement.'],
                ['insurance_wiba',      'WIBA / Workmen’s Cover',     'mandatory', null],
            ]),
            'subcontractor'         => array_merge(self::COMPANY_BASELINE, [
                ['insurance_liability', 'Public Liability Insurance', 'mandatory', null],
            ]),
            // A person, not a company — asking an individual for a certificate
            // of incorporation is how a form loses the room.
            'contractor_individual' => [
                ['national_id',       'National ID or Passport',   'mandatory', null],
                ['kra_pin',           'KRA PIN Certificate',       'mandatory', null],
                ['bank_confirmation', 'Bank Account Confirmation', 'mandatory', null],
                ['cv',                'Curriculum Vitae',          'optional',  null],
            ],
            'consultant'            => [
                ['national_id',       'National ID or Passport',         'mandatory', null],
                ['kra_pin',           'KRA PIN Certificate',             'mandatory', null],
                ['nda',               'Signed Non-Disclosure Agreement', 'mandatory', 'Consultants see more than they buy.'],
                ['bank_confirmation', 'Bank Account Confirmation',       'mandatory', null],
                ['cv',                'Curriculum Vitae',                'optional',  null],
            ],
        ];
    }

    /**
     * Write the baseline into this organisation's own rows.
     *
     * Called when a tenant is created, and again the first time somebody
     * edits a rule — because editing one means owning the list, and a rulebook
     * you can only add to is not one you can correct.
     */
    public static function materialiseFor(int $organizationId): void
    {
        if (static::where('organization_id', $organizationId)->exists()) {
            return;
        }

        foreach (static::defaults() as $role => $requirements) {
            foreach ($requirements as [$key, $label, $requirement, $help]) {
                static::create([
                    'organization_id' => $organizationId,
                    'role'            => $role,
                    'document_key'    => $key,
                    'label'           => $label,
                    'requirement'     => $requirement,
                    'help'            => $help,
                    'is_active'       => true,
                ]);
            }
        }
    }

    /**
     * This organisation's rulebook, or the baseline if it has never edited one.
     *
     * All-or-nothing on purpose: once a tenant has a single row of its own, it
     * owns the list entirely. Merging its edits over the defaults would make
     * "delete this requirement" impossible to express — the default would
     * simply come back.
     *
     * @return Collection<int, self>
     */
    public static function forOrganization(int $organizationId): Collection
    {
        $own = static::query()
            ->where('organization_id', $organizationId)
            ->get();

        if ($own->isNotEmpty()) {
            return $own->where('is_active', true)->values();
        }

        return collect(static::defaults())
            ->flatMap(fn (array $requirements, string $role) => collect($requirements)
                ->map(fn (array $r) => new static([
                    'organization_id' => $organizationId,
                    'role'            => $role,
                    'document_key'    => $r[0],
                    'label'           => $r[1],
                    'requirement'     => $r[2],
                    'help'            => $r[3],
                    'is_active'       => true,
                ])))
            ->values();
    }
}
