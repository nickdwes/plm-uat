<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectOrder;
use App\Models\Project\ProjectTrainingFulfillment;
use App\Models\Role;
use App\Models\Training\TrainingItem;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Deep training — a training order activated into a course + trainee register. */
class ProjectTrainingTest extends TestCase
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
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    private function trainingFulfillment(): ProjectTrainingFulfillment
    {
        $this->postJson($this->url('/bom/items'), ['category' => 'training', 'description' => 'Firewall admin course', 'quantity' => 3, 'unit_cost' => 2000, 'selling_price' => 3000])->assertCreated();
        $this->postJson($this->url('/bom/approve'))->assertOk();
        $order = ProjectOrder::where('project_id', $this->project->id)->where('category', 'training')->firstOrFail();
        $this->postJson($this->url("/orders/{$order->id}/raise"))->assertOk();   // activates training

        return ProjectTrainingFulfillment::where('project_id', $this->project->id)->firstOrFail();
    }

    public function test_raising_a_training_order_creates_a_fulfillment(): void
    {
        $f = $this->trainingFulfillment();
        $this->assertSame('Firewall admin course', $f->title);
        $this->assertSame(2000.0, (float) $f->cost_per_person);   // from the BOM unit_cost

        $res = $this->getJson($this->url('/training'))->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertNotNull($res->json('data.0.order_reference'));
    }

    public function test_pick_a_course_and_register_trainees_with_travel(): void
    {
        $f = $this->trainingFulfillment();
        $course = TrainingItem::create(['organization_id' => $this->org->id, 'title' => 'CCNA', 'training_code' => 'CCNA-1', 'delivery' => 'in_person', 'estimated_cost' => 1800]);

        $this->putJson($this->url("/training/{$f->id}"), ['training_item_id' => $course->id, 'mode' => 'classroom', 'status' => 'booked'])->assertOk();

        // Internal trainee (employee) + external customer trainee with travel.
        $emp = Employee::create(['employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999), 'primary_organization_id' => $this->org->id, 'first_name' => 'Jo', 'last_name' => 'Eng', 'employment_type' => 'permanent', 'date_hired' => '2024-01-01']);
        $this->postJson($this->url("/training/{$f->id}/trainees"), ['party' => 'internal', 'employee_id' => $emp->id, 'name' => 'Jo Eng'])->assertOk();
        $res = $this->postJson($this->url("/training/{$f->id}/trainees"), [
            'party' => 'customer', 'name' => 'Client Person', 'email' => 'cp@client.co.ke',
            'needs_travel' => true, 'travel_location' => 'Nairobi', 'travel_cost' => 15000,
        ])->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', $f->id);
        $this->assertSame(2, $row['trainee_count']);
        $this->assertSame('CCNA', $row['course']['title']);
        $this->assertSame('booked', $row['status']);
        $this->assertSame(15000.0, (float) $row['travel_total']);
        // cost_per_person (2000) × 2 + travel 15000 = 19000
        $this->assertSame(19000.0, (float) $row['total_cost']);
    }

    public function test_the_catalog_is_offered_for_the_course_picker(): void
    {
        $this->trainingFulfillment();
        TrainingItem::create(['organization_id' => $this->org->id, 'title' => 'AZ-900', 'training_code' => 'AZ900', 'delivery' => 'virtual', 'is_active' => true]);

        $res = $this->getJson($this->url('/training'))->assertOk();
        $this->assertNotEmpty($res->json('catalog'));
        $this->assertContains('AZ-900', collect($res->json('catalog'))->pluck('title')->all());
    }
}
