<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a customer can ask for, with the questions worth asking up front.
 *
 * The old module received everything as prose, so "we need a mailbox" arrived
 * without a name, a start date or a manager to approve it — and the first reply
 * was always the same three questions. A form asks them once.
 */
class Service extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_services';

    protected $fillable = [
        'organization_id', 'name', 'slug', 'summary', 'description',
        'ticket_type_id', 'service_category_id', 'default_impact_id', 'default_urgency_id',
        'fields', 'icon', 'position', 'is_active', 'needs_approval',
    ];

    protected $casts = [
        'fields'         => 'array',
        'is_active'      => 'boolean',
        'needs_approval' => 'boolean',
        'position'       => 'integer',
    ];

    protected $attributes = ['is_active' => true, 'needs_approval' => false];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'ticket_type_id');
    }

    /**
     * Validation rules built from the form definition.
     *
     * Derived rather than stored twice. A form whose rules live somewhere else
     * is a form that accepts a blank required field the day somebody edits one
     * of the two copies.
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->fields ?? [] as $field) {
            $key = 'answers.' . ($field['key'] ?? '');

            $rule = [$field['required'] ?? false ? 'required' : 'nullable'];

            $rule[] = match ($field['type'] ?? 'text') {
                'date'     => 'date',
                'number'   => 'numeric',
                'email'    => 'email',
                'textarea' => 'string',
                'select'   => 'string',
                default    => 'string',
            };

            if (($field['type'] ?? '') !== 'textarea') {
                $rule[] = 'max:500';
            }

            $rules[$key] = $rule;
        }

        return $rules;
    }
}
