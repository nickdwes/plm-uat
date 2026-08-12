<?php

namespace Tests\Feature\Asset;

use App\Models\Asset\Asset;
use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A licence is a batch, and its seats belong to people.
 *
 * `seats_used` was an integer somebody typed. It could say eighteen of
 * twenty-five were in use and never which eighteen — which is the only form of
 * the question anybody asks, at a renewal, at an exit interview, or in an audit.
 *
 * And a typed count drifts. Somebody leaves, nobody decrements it, and the
 * register reports a full licence while six seats sit unused. These tests exist
 * so the count cannot disagree with the people.
 */
class LicenceSeatTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    private function employee(string $first): Employee
    {
        return Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function licence(int $seats = 3): Asset
    {
        return Asset::create([
            'organization_id' => $this->org->id,
            'asset_class' => Asset::CLASS_SUBSCRIPTION,
            'name' => 'Adobe Creative Cloud', 'category' => 'licence',
            'asset_tag' => 'SUB-' . fake()->unique()->numberBetween(1000, 999999),
            'seats_total' => $seats,
            'starts_on' => now()->toDateString(),
            'expires_on' => now()->addYear()->toDateString(),
        ]);
    }

    public function test_seats_used_is_counted_from_people_not_typed(): void
    {
        $licence = $this->licence(3);

        $this->assertSame(0, $licence->seatsUsed());
        $this->assertSame(3, $licence->seatsSpare());

        $licence->allocateSeat($this->employee('Amina')->id);
        $licence->allocateSeat($this->employee('Brian')->id);

        $this->assertSame(2, $licence->fresh()->seatsUsed());
        $this->assertSame(1, $licence->fresh()->seatsSpare());
    }

    /** The compliance finding the register exists to prevent. */
    public function test_a_batch_cannot_be_over_allocated(): void
    {
        $licence = $this->licence(1);
        $licence->allocateSeat($this->employee('Amina')->id);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $licence->fresh()->allocateSeat($this->employee('Brian')->id);
    }

    public function test_the_same_person_cannot_hold_two_seats(): void
    {
        $licence = $this->licence(5);
        $amina = $this->employee('Amina');

        $licence->allocateSeat($amina->id);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $licence->fresh()->allocateSeat($amina->id);
    }

    /**
     * Released, not deleted.
     *
     * The seat comes back to the batch, and the history that it was held stays
     * — a licence fully consumed for a year should not read as under-used
     * because the holders have since left.
     */
    public function test_releasing_a_seat_returns_it_to_the_batch_and_keeps_the_record(): void
    {
        $licence = $this->licence(2);
        $amina   = $this->employee('Amina');

        $seat = $licence->allocateSeat($amina->id);
        $this->assertSame(1, $licence->fresh()->seatsUsed());

        $seat->update(['released_on' => now()->toDateString()]);

        $this->assertSame(0, $licence->fresh()->seatsUsed());
        $this->assertSame(2, $licence->fresh()->seatsSpare());
        // Still on file.
        $this->assertSame(1, $licence->fresh()->seats()->count());
    }

    /** Released seats free the batch, so the person can be given one again. */
    public function test_a_released_seat_can_be_reallocated_to_the_same_person(): void
    {
        $licence = $this->licence(1);
        $amina   = $this->employee('Amina');

        $seat = $licence->allocateSeat($amina->id);
        $seat->update(['released_on' => now()->toDateString()]);

        $licence->fresh()->allocateSeat($amina->id);

        $this->assertSame(1, $licence->fresh()->seatsUsed());
        $this->assertSame(2, $licence->fresh()->seats()->count());
    }

    /** Hardware has no seats, and asking is a category error. */
    public function test_a_laptop_has_no_seats_to_allocate(): void
    {
        $laptop = Asset::create([
            'organization_id' => $this->org->id,
            'name' => 'ThinkPad', 'category' => 'laptop',
            'asset_tag' => 'AST-' . fake()->unique()->numberBetween(1000, 999999),
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $laptop->allocateSeat($this->employee('Amina')->id);
    }

    /** A batch with no stated size is not a batch you can overfill. */
    public function test_a_licence_with_no_seat_count_allocates_freely(): void
    {
        $licence = $this->licence(3);
        $licence->update(['seats_total' => null]);

        $licence->fresh()->allocateSeat($this->employee('Amina')->id);
        $licence->fresh()->allocateSeat($this->employee('Brian')->id);

        $this->assertSame(2, $licence->fresh()->seatsUsed());
        // Nothing to be spare out of.
        $this->assertNull($licence->fresh()->seatsSpare());
    }
}
