<?php

namespace Tests\Feature\Travel;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Travel\TravelRequest;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bookings and itinerary.
 *
 * A booking is what was bought, as distinct from what was approved — keeping
 * them apart is what makes "approved 28,000, booked 27,800" answerable, and
 * the property worth fixing is that recording a booking never touches the
 * estimate the approval was given against.
 */
class TravelBookingsAndItineraryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Employee $traveller;
    private TravelRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->traveller = $this->employee();

        $this->request = TravelRequest::create([
            'organization_id' => $this->org->id,
            'employee_id'     => $this->traveller->id,
            'departure_date'  => '2026-09-01',
            'return_date'     => '2026-09-03',
            'days'            => 3,
            'total_claimed'   => 28000,
        ]);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function actingAs_(array $permissions, ?Employee $employee = null): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = $employee ?? $this->employee();
        $employee->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function url(string $path): string
    {
        return "/api/v1/travel-requests/{$this->request->id}{$path}?org={$this->org->slug}";
    }

    // ── Bookings ──────────────────────────────────────────────────────────

    public function test_a_booking_can_be_recorded_after_approval(): void
    {
        // Tickets are bought once the trip is agreed, when the request is no
        // longer editable.
        $this->request->update(['status' => 'approved']);
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);

        $this->postJson($this->url('/bookings'), [
            'booking_type' => 'flight', 'vendor' => 'Kenya Airways',
            'total_cost' => 27800, 'booking_date' => '2026-08-20',
        ])->assertOk();

        $this->assertSame(1, $this->request->bookings()->count());
    }

    public function test_recording_a_booking_leaves_the_approved_estimate_alone(): void
    {
        // Collapsing the two would overwrite the figure the approval was given
        // against, and "approved 28,000, booked 27,800" would stop being
        // answerable.
        $this->request->update(['status' => 'approved']);
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);

        $this->postJson($this->url('/bookings'), [
            'booking_type' => 'flight', 'total_cost' => 27800,
        ])->assertOk();

        $this->assertEquals(28000, (float) $this->request->fresh()->total_claimed);
    }

    public function test_an_unknown_booking_type_is_refused(): void
    {
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);

        $this->postJson($this->url('/bookings'), ['booking_type' => 'submarine'])
            ->assertStatus(422)->assertJsonValidationErrors('booking_type');
    }

    public function test_a_booking_records_an_activity_entry(): void
    {
        // A request showing "approved" cannot tell you a ticket was bought.
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);

        $this->postJson($this->url('/bookings'), [
            'booking_type' => 'hotel', 'vendor' => 'Pride Inn',
        ])->assertOk();

        $this->assertTrue(
            $this->request->activity()->where('event', 'booked')->exists(),
            'Booking should leave a trail.'
        );
    }

    public function test_a_booking_on_another_request_is_not_reachable(): void
    {
        $other = TravelRequest::create([
            'organization_id' => $this->org->id, 'employee_id' => $this->traveller->id,
            'departure_date' => '2026-10-01', 'return_date' => '2026-10-02',
        ]);
        $foreign = $other->bookings()->create(['booking_type' => 'flight']);

        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);

        $this->deleteJson($this->url("/bookings/{$foreign->id}"))->assertNotFound();
    }

    public function test_somebody_with_no_claim_on_the_request_cannot_book_against_it(): void
    {
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN]);

        $this->postJson($this->url('/bookings'), ['booking_type' => 'flight'])->assertForbidden();
    }

    // ── Itinerary ─────────────────────────────────────────────────────────

    public function test_an_itinerary_entry_can_be_added_to_a_draft(): void
    {
        $this->actingAs_([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN], $this->traveller);

        $this->postJson($this->url('/itinerary'), [
            'starts_on' => '2026-09-01', 'activity' => 'Flight Nairobi → Mombasa',
        ])->assertOk();

        $this->assertSame(1, $this->request->itinerary()->count());
    }

    public function test_an_itinerary_entry_cannot_end_before_it_starts(): void
    {
        $this->actingAs_([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN], $this->traveller);

        $this->postJson($this->url('/itinerary'), [
            'starts_on' => '2026-09-03', 'ends_on' => '2026-09-01', 'activity' => 'Backwards',
        ])->assertStatus(422)->assertJsonValidationErrors('ends_on');
    }

    public function test_the_itinerary_is_locked_once_the_request_is_submitted(): void
    {
        // It is part of what an approver read before deciding.
        $this->request->update(['status' => 'in_approval']);
        $this->actingAs_([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN], $this->traveller);

        $this->postJson($this->url('/itinerary'), [
            'starts_on' => '2026-09-01', 'activity' => 'Added later',
        ])->assertForbidden();
    }
}
