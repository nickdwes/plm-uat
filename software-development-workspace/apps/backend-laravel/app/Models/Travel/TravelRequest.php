<?php

namespace App\Models\Travel;

use App\Models\Account\Account;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * A travel request.
 *
 * One tenant owns it end to end. `organization_id` is fixed when the request
 * is raised and decides whose budget pays, whose rate card costs it, whose
 * approvers act, and who can see it. An employee belonging to several
 * organisations raises it in one of them, and that choice is the commitment.
 *
 * The tenancy global scope stops another organisation reading this row. It
 * does not stop this row pointing at another organisation's customer,
 * destination or approver — tenancy here is a column, not a schema boundary —
 * so those are checked when the request is built, and the immutability of
 * `organization_id` is enforced below.
 */
class TravelRequest extends Model
{
    use BelongsToOrganization, SoftDeletes;

    public const TRAVEL_TYPES = ['local', 'international', 'delivery'];

    public const STATUSES = [
        'draft', 'submitted', 'in_approval', 'approved', 'rejected',
        'cancelled', 'disbursed', 'closed',
    ];

    protected $fillable = [
        'organization_id', 'reference', 'employee_id', 'department_id', 'functional_unit_id',
        'account_id', 'project_id', 'source_project_travel_request_id',
        'linked_task_ref', 'delivery_reference', 'delivery_note_attachment_id',
        'travel_destination_id', 'travel_mode', 'travel_type',
        'priority', 'accommodation_needed', 'flexible_days',
        'advance_justification', 'departure_date', 'return_date',
        'days', 'purpose', 'business_impact', 'currency', 'total_claimed', 'total_approved',
        'advance_required', 'status', 'submitted_at', 'completed_at',
        'reconciled_at', 'reconciled_by_employee_id', 'created_by',
    ];

    protected $casts = [
        'departure_date'   => 'date',
        'return_date'      => 'date',
        'days'             => 'integer',
        'total_claimed'    => 'decimal:2',
        'total_approved'   => 'decimal:2',
        'advance_required' => 'decimal:2',
        'accommodation_needed' => 'boolean',
        'flexible_days'        => 'boolean',
        'submitted_at'     => 'datetime',
        'completed_at'     => 'datetime',
        'reconciled_at'    => 'datetime',
    ];

    protected $attributes = [
        'status'        => 'draft',
        'currency'      => 'KES',
        'days'          => 1,
        'total_claimed' => 0,
    ];

    protected static function booted(): void
    {
        /*
         * The owning organisation cannot be changed.
         *
         * Moving a request between tenants would carry its approvals, its
         * costing and its customer link across a boundary they were each
         * validated against separately — and the result would look entirely
         * ordinary in the interface. There is no legitimate reason to do it:
         * a request raised against the wrong organisation is cancelled and
         * raised again, which leaves a trail.
         */
        static::updating(function (self $request) {
            if ($request->isDirty('organization_id')) {
                throw new RuntimeException(
                    'A travel request cannot be moved between organisations. '
                    . 'Cancel it and raise a new one in the correct organisation.'
                );
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(TravelDestination::class, 'travel_destination_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project\Project::class, 'project_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TravelRequestItem::class);
    }

    public function itinerary(): HasMany
    {
        return $this->hasMany(TravelItineraryItem::class)->orderBy('starts_on')->orderBy('position');
    }

    /**
     * The journeys this trip is made of.
     *
     * One per mode, because a real trip is several — a flight to Kampala and
     * taxis once there. A single transport_mode column forces a choice that
     * does not exist.
     */
    public function transportLegs(): HasMany
    {
        return $this->hasMany(TravelTransportLeg::class);
    }

    /** What is in the vehicle, for a delivery. */
    public function manifest(): HasMany
    {
        return $this->hasMany(\App\Models\Delivery\DeliveryManifestItem::class);
    }

    /** Who is delivering it. */
    public function couriers(): HasMany
    {
        return $this->hasMany(\App\Models\Delivery\DeliveryCourier::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TravelAttachment::class);
    }

    public function isDelivery(): bool
    {
        return $this->travel_type === 'delivery';
    }

    /**
     * Whether this delivery has everything it needs to close.
     *
     * A signed note, and every manifest line accounted for. "Has an
     * attachment" is not the same statement — a quotation would satisfy that,
     * and a delivery where four of five items arrived would close silently.
     *
     * @return string[] what is still missing
     */
    public function deliveryGaps(): array
    {
        if (! $this->isDelivery()) {
            return [];
        }

        $gaps = [];

        if ($this->delivery_note_attachment_id === null) {
            $gaps[] = 'a signed delivery note';
        }

        if ($this->manifest->isEmpty()) {
            $gaps[] = 'a manifest of what was delivered';
        }

        $unreceived = $this->manifest->reject->isFullyReceived();

        if ($unreceived->isNotEmpty()) {
            $gaps[] = $unreceived->count() . ' manifest line(s) not signed for';
        }

        return $gaps;
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(TravelBooking::class)->orderBy('booking_date');
    }

    public function activity(): HasMany
    {
        return $this->hasMany(TravelActivity::class)->orderByDesc('occurred_at');
    }

    /** Record an event on the trail. */
    public function recordActivity(string $event, ?string $description = null, ?int $actorEmployeeId = null): void
    {
        $this->activity()->create([
            'event'             => $event,
            'description'       => $description,
            'actor_employee_id' => $actorEmployeeId,
            'occurred_at'       => now(),
        ]);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(TravelApproval::class)->orderBy('sequence');
    }

    // ── Workflow ──────────────────────────────────────────────────────────

    /**
     * The stage currently waiting on someone, or null when none is.
     *
     * Stages are decided in sequence: the earliest pending one is the only
     * one anybody may act on, so a later approver cannot sign ahead of an
     * earlier one and leave the chain looking complete.
     */
    public function currentApproval(): ?TravelApproval
    {
        return $this->approvals->firstWhere('status', 'pending');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
