<?php

namespace Tests\Feature\Performance;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Review cycles as rows with dates.
 *
 * `review_cycle` was free text, so "H1 2026" and "H1-2026" were two cycles and
 * neither said which days it covered. That was survivable while a review was a
 * rating and some comments; it stopped being survivable when attendance
 * started featuring in one.
 */
class PerformanceCycleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        app()->instance('current.organization_id', $this->org->id);

        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'HR', 'scope' => 'organization',
            'permissions' => [Permission::PERFORMANCE_VIEW, Permission::PERFORMANCE_MANAGE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/performance-cycles{$path}?org={$this->org->slug}";
    }

    public function test_a_cycle_records_the_days_it_covers(): void
    {
        $this->postJson($this->url(), [
            'name' => 'H1 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30',
        ])->assertCreated();

        $cycle = PerformanceCycle::firstOrFail();

        $this->assertSame('2026-01-01', $cycle->starts_on->toDateString());
        $this->assertSame(181, $cycle->length_in_days);
    }

    /** Two "H1 2026" would be the free-text problem again with extra steps. */
    public function test_a_name_is_unique_within_the_organisation(): void
    {
        $body = ['name' => 'H1 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30'];

        $this->postJson($this->url(), $body)->assertCreated();
        $this->postJson($this->url(), $body)->assertStatus(422);
    }

    /** A cycle ending before it starts covers no days, and every figure in it would be empty. */
    public function test_it_cannot_end_before_it_starts(): void
    {
        $this->postJson($this->url(), [
            'name' => 'Backwards', 'starts_on' => '2026-06-30', 'ends_on' => '2026-01-01',
        ])->assertStatus(422);
    }

    /**
     * Moving the dates would change the window the attendance on existing
     * reviews was measured over, without changing the figures printed on them.
     */
    public function test_the_dates_cannot_move_once_reviews_exist(): void
    {
        $cycle = PerformanceCycle::create([
            'organization_id' => $this->org->id, 'name' => 'H1 2026',
            'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'is_open' => true,
        ]);

        $employee = Employee::create([
            'employee_number' => 'E1', 'primary_organization_id' => $this->org->id,
            'first_name' => 'A', 'last_name' => 'B', 'employment_type' => 'permanent',
            'date_hired' => '2024-01-01', 'status' => 'active',
        ]);

        PerformanceReview::create([
            'employee_id' => $employee->id, 'reviewer_id' => $employee->id,
            'review_cycle' => 'H1 2026', 'cycle_id' => $cycle->id,
            'review_date' => '2026-07-01', 'rating' => 4,
        ]);

        $this->putJson($this->url("/{$cycle->id}"), ['ends_on' => '2026-12-31'])
            ->assertStatus(422);

        // Renaming and closing stay allowed — neither changes what was measured.
        $this->putJson($this->url("/{$cycle->id}"), ['name' => 'First half 2026', 'is_open' => false])
            ->assertOk();

        $this->assertFalse($cycle->fresh()->is_open);
    }

    /**
     * Reviews written before cycles existed belong to a window nobody
     * recorded. Inventing one would put a made-up date range behind a real
     * appraisal.
     */
    public function test_an_older_review_keeps_its_text_and_has_no_cycle(): void
    {
        $employee = Employee::create([
            'employee_number' => 'E2', 'primary_organization_id' => $this->org->id,
            'first_name' => 'A', 'last_name' => 'B', 'employment_type' => 'permanent',
            'date_hired' => '2024-01-01', 'status' => 'active',
        ]);

        $review = PerformanceReview::create([
            'employee_id' => $employee->id, 'reviewer_id' => $employee->id,
            'review_cycle' => 'Whatever somebody typed', 'review_date' => '2025-07-01', 'rating' => 3,
        ]);

        $this->assertNull($review->cycle_id);
        $this->assertSame('Whatever somebody typed', $review->review_cycle);
    }
}
