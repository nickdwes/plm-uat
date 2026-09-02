<?php

namespace App\Models\Vendor;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One thing worth capturing about a party because of what it does for us.
 *
 * A consultant has a professional body and a day rate; a distributor has the
 * territories it may sell into; an individual contractor has a national ID
 * where a company has a registration number. None of those belongs as a
 * column on every vendor, and all of them used to end up in the notes field.
 *
 * Same engine as `asset_category_fields`, keyed on role instead of category.
 */
class StakeholderRoleField extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'role', 'party_class', 'key', 'label',
        'type', 'options', 'help', 'section', 'is_required', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'options'     => 'array',
        'is_required' => 'boolean',
        'is_active'   => 'boolean',
        'sort_order'  => 'integer',
    ];

    /**
     * Deliberately the same small list the asset engine uses.
     *
     * Every type here has an obvious control and an obvious way to validate.
     * A second, longer vocabulary for the same idea would mean two renderers
     * to keep in step.
     */
    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'boolean'];

    /** Rules built at request time, because the definitions are data. */
    public function rules(): array
    {
        $rules = [$this->is_required ? 'required' : 'nullable'];

        $rules[] = match ($this->type) {
            'number'   => 'numeric',
            'date'     => 'date',
            'boolean'  => 'boolean',
            default    => 'string',
        };

        if ($this->type === 'select' && $this->options) {
            $rules[] = 'in:' . implode(',', $this->options);
        }

        if (in_array($this->type, ['text', 'select'], true)) {
            $rules[] = 'max:255';
        }

        if ($this->type === 'textarea') {
            $rules[] = 'max:5000';
        }

        return $rules;
    }

    /**
     * What each role is worth asking about.
     *
     * Held on the model rather than only in the seeding migration, for the
     * reason learnt twice already in this epic: an organisation created later
     * would otherwise get an empty form and nothing would say why.
     *
     * Every row is editable, so a tenant that disagrees changes it rather
     * than working around it.
     *
     * @return array<int, array{role:?string,party_class:?string,key:string,label:string,type:string,options:?array,help:?string,section:string,is_required:bool,sort_order:int}>
     */
    public static function defaults(): array
    {
        $f = fn (
            ?string $role, string $key, string $label, string $type = 'text',
            array $extra = [],
        ) => array_merge([
            'role'        => $role,
            'party_class' => null,
            'key'         => $key,
            'label'       => $label,
            'type'        => $type,
            'options'     => null,
            'help'        => null,
            'section'     => 'Role details',
            'is_required' => false,
            'sort_order'  => 0,
        ], $extra);

        return [
            // ── A person, whatever they do for us ─────────────────────────
            $f(null, 'national_id_number', 'National ID / Passport number', 'text', [
                'party_class' => 'individual',
                'is_required' => true,
                'section'     => 'Identity',
                'help'        => 'A person has no company registration number.',
                'sort_order'  => 1,
            ]),
            $f(null, 'date_of_birth', 'Date of birth', 'date', [
                'party_class' => 'individual',
                'section'     => 'Identity',
                'sort_order'  => 2,
            ]),

            // ── Consultant ────────────────────────────────────────────────
            $f('consultant', 'specialisation', 'Area of specialisation', 'text', [
                'is_required' => true, 'sort_order' => 1,
                'help' => 'e.g. Network security architecture, ERP implementation.',
            ]),
            $f('consultant', 'professional_body', 'Professional body', 'text', [
                'sort_order' => 2, 'help' => 'e.g. ICTAK, IEK, ICPAK — the register they are on.',
            ]),
            $f('consultant', 'membership_number', 'Membership number', 'text', ['sort_order' => 3]),
            $f('consultant', 'years_experience', 'Years of experience', 'number', ['sort_order' => 4]),
            $f('consultant', 'day_rate', 'Standard day rate', 'number', [
                'sort_order' => 5, 'help' => 'What they normally charge, before negotiation.',
            ]),
            $f('consultant', 'rate_currency', 'Rate currency', 'select', [
                'options' => ['KES', 'USD', 'EUR', 'GBP'], 'sort_order' => 6,
            ]),
            $f('consultant', 'availability', 'Availability', 'select', [
                'options' => ['Full time', 'Part time', 'Ad hoc', 'Retained'], 'sort_order' => 7,
            ]),

            // ── Individual contractor ─────────────────────────────────────
            $f('contractor_individual', 'trade', 'Trade or skill', 'text', [
                'is_required' => true, 'sort_order' => 1,
                'help' => 'e.g. Fibre splicing, electrical installation, rigging.',
            ]),
            $f('contractor_individual', 'day_rate', 'Standard day rate', 'number', ['sort_order' => 2]),
            $f('contractor_individual', 'rate_currency', 'Rate currency', 'select', [
                'options' => ['KES', 'USD'], 'sort_order' => 3,
            ]),
            $f('contractor_individual', 'has_own_tools', 'Brings their own tools', 'boolean', ['sort_order' => 4]),

            // ── Contracting companies ─────────────────────────────────────
            $f('contractor_company', 'trade', 'Trade or discipline', 'text', [
                'is_required' => true, 'sort_order' => 1,
            ]),
            $f('contractor_company', 'crew_size', 'Typical crew size', 'number', ['sort_order' => 2]),
            $f('contractor_company', 'safety_officer_name', 'Site safety officer', 'text', [
                'sort_order' => 3, 'help' => 'Who is accountable for safety on our sites.',
            ]),
            $f('subcontractor', 'trade', 'Trade or discipline', 'text', ['is_required' => true, 'sort_order' => 1]),
            $f('subcontractor', 'works_under', 'Normally engaged through', 'text', [
                'sort_order' => 2, 'help' => 'The main contractor this firm usually comes with.',
            ]),

            // ── Distributor / channel ─────────────────────────────────────
            $f('distributor', 'territories', 'Territories covered', 'text', [
                'is_required' => true, 'sort_order' => 1,
                'help' => 'Where this distributor is authorised to sell. Comma separated.',
            ]),
            $f('distributor', 'oems_represented', 'OEMs represented', 'textarea', [
                'sort_order' => 2, 'help' => 'Which manufacturers they distribute for.',
            ]),
            $f('distributor', 'lead_time_days', 'Typical lead time (days)', 'number', ['sort_order' => 3]),
            $f('channel_partner', 'partner_tier_with_oem', 'Tier held with the OEM', 'text', [
                'sort_order' => 1, 'help' => 'e.g. Gold, Premier — as the manufacturer grades them.',
            ]),
            $f('channel_partner', 'deal_registration_supported', 'Supports deal registration', 'boolean', ['sort_order' => 2]),

            // ── OEM ───────────────────────────────────────────────────────
            $f('oem', 'support_portal_url', 'Support portal', 'text', ['sort_order' => 1]),
            $f('oem', 'support_sla', 'Support response commitment', 'text', [
                'sort_order' => 2, 'help' => 'What they promise when we raise a case.',
            ]),

            // ── Ordinary suppliers ────────────────────────────────────────
            $f('supplier', 'delivery_lead_time_days', 'Typical delivery lead time (days)', 'number', ['sort_order' => 1]),
            $f('supplier', 'minimum_order_value', 'Minimum order value', 'number', ['sort_order' => 2]),
            $f('service_provider', 'service_window', 'Service window', 'text', [
                'sort_order' => 1, 'help' => 'When they will attend — e.g. 8×5, 24×7.',
            ]),
        ];
    }

    public static function materialiseFor(int $organizationId): void
    {
        if (static::where('organization_id', $organizationId)->exists()) {
            return;
        }

        foreach (static::defaults() as $definition) {
            static::create([...$definition, 'organization_id' => $organizationId, 'is_active' => true]);
        }
    }

    /**
     * This organisation's questions, or the baseline if it has never edited any.
     *
     * All-or-nothing, like the document rulebook: once a tenant owns the list
     * it owns all of it, so removing a question is expressible.
     *
     * @return Collection<int, self>
     */
    public static function forOrganization(int $organizationId): Collection
    {
        $own = static::query()->where('organization_id', $organizationId)->get();

        if ($own->isNotEmpty()) {
            return $own->where('is_active', true)->values();
        }

        return collect(static::defaults())
            ->map(fn (array $d) => new static([...$d, 'organization_id' => $organizationId, 'is_active' => true]));
    }

    /**
     * The questions that apply to a party: those for the roles it holds, plus
     * any asked of every role, narrowed to its party class.
     *
     * A party holding two roles is asked both sets, deduplicated by key — it
     * is one firm, and a day rate is a day rate whichever hat it is wearing.
     *
     * @param  string[]  $roles  roles in force
     * @return Collection<int, self>
     */
    public static function applyingTo(int $organizationId, array $roles, string $partyClass): Collection
    {
        return static::forOrganization($organizationId)
            ->filter(fn (self $field) => ($field->role === null || in_array($field->role, $roles, true))
                && ($field->party_class === null || $field->party_class === $partyClass))
            ->unique('key')
            ->sortBy([['section', 'asc'], ['sort_order', 'asc'], ['label', 'asc']])
            ->values();
    }
}
