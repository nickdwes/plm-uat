<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Travel\TravelAllowanceRate;
use App\Models\Travel\TravelExpenseCategory;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The rate-card travel estimate — priced from the allowance card + per-mode transport. */
class ProjectTravelEstimateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'SD-WAN']);

        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization',
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE, Permission::PROJECTS_ASSIGN],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    private function rateCard(): void
    {
        foreach (['Per diem' => 4500, 'Accommodation' => 12000, 'Incidentals' => 5000] as $name => $amount) {
            $cat = TravelExpenseCategory::create(['organization_id' => $this->org->id, 'name' => $name]);
            TravelAllowanceRate::create([
                'organization_id' => $this->org->id, 'travel_expense_category_id' => $cat->id,
                'amount' => $amount, 'currency' => 'KES', 'unit' => 'per_day',
                'effective_from' => now()->subYear()->toDateString(),
            ]);
        }
    }

    public function test_estimate_prices_a_cycle_from_the_rate_card(): void
    {
        $this->rateCard();
        // A 3-day (2-night) trip needing accommodation, by air.
        $this->postJson($this->url('/tasks'), [
            'title' => 'Cutover', 'started_on' => '2026-09-01', 'deadline' => '2026-09-03',
            'logistics' => ['needs_travel' => true, 'needs_accommodation' => true, 'travel_location' => 'Mombasa', 'travel_mode' => 'air'],
        ])->assertCreated();

        $res = $this->getJson($this->url('/travel-estimate'))->assertOk();

        $this->assertSame('rate_card', $res->json('rates.per_diem.source'));
        $c = $res->json('cycles.0');
        $this->assertSame(3, $c['days']);
        $this->assertSame(2, $c['nights']);
        // per diem 4500×3×1 + accom 12000×2×1 + incidentals 5000×1 + air 25000×1
        $this->assertSame(13500.0 + 24000.0 + 5000.0 + 25000.0, (float) $c['total']);
    }

    public function test_it_falls_back_to_defaults_without_a_rate_card(): void
    {
        $this->postJson($this->url('/tasks'), [
            'title' => 'Visit', 'logistics' => ['needs_travel' => true, 'travel_location' => 'Kisumu', 'travel_mode' => 'road'],
        ])->assertCreated();

        $res = $this->getJson($this->url('/travel-estimate'))->assertOk();
        $this->assertSame('default', $res->json('rates.per_diem.source'));
        $this->assertGreaterThan(0, (float) $res->json('total'));
    }

    public function test_apply_writes_the_estimate_onto_uncosted_requests_only(): void
    {
        $this->rateCard();
        $this->postJson($this->url('/tasks'), [
            'title' => 'Cutover', 'started_on' => '2026-09-01', 'deadline' => '2026-09-02',
            'logistics' => ['needs_travel' => true, 'travel_location' => 'Mombasa', 'travel_mode' => 'air'],
        ])->assertCreated();
        $this->postJson($this->url('/travel-requests/generate'))->assertOk();

        // Hand-set one cost first — apply must not overwrite it.
        $reqId = $this->getJson($this->url('/travel-requests'))->json('data.0.id');
        $this->putJson($this->url("/travel-requests/{$reqId}"), ['estimated_cost' => 999])->assertOk();

        $res = $this->postJson($this->url('/travel-estimate/apply'))->assertOk();
        $this->assertSame(0, $res->json('applied'));   // the one request was already costed

        $this->assertSame('999.00', $this->getJson($this->url('/travel-requests'))->json('data.0.estimated_cost'));
    }
}
