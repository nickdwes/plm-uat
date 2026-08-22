<?php

namespace Tests\Feature\HR;

use App\Models\Designation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A job title that says what the job is.
 *
 * The role has held a title and nothing else, so what somebody needs to be
 * able to do and what they must hold to do it were re-typed into every
 * vacancy recruiting for the same job.
 */
class DesignationDetailTest extends TestCase
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
            'permissions' => [Permission::DEPARTMENTS_VIEW, Permission::DEPARTMENTS_MANAGE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/org/designations{$path}?org={$this->org->slug}";
    }

    public function test_a_role_records_its_description_skills_and_certifications(): void
    {
        $this->postJson($this->url(), [
            'title'           => 'Network Engineer',
            'description'     => 'Runs the network.',
            'job_description' => "Owns the routed and switched estate.\nOn call one week in four.",
            'desired_skills'  => ['BGP', 'MPLS', 'Python'],
            'certifications'  => ['CCNP Enterprise'],
        ])->assertCreated();

        $role = Designation::firstOrFail();

        $this->assertSame(['BGP', 'MPLS', 'Python'], $role->desired_skills);
        $this->assertSame(['CCNP Enterprise'], $role->certifications);
        $this->assertStringContainsString('On call', $role->job_description);
    }

    /**
     * The order somebody typed them in is the order of importance they meant,
     * so the list is tidied but never sorted.
     */
    public function test_blanks_and_duplicate_spellings_collapse_but_the_order_holds(): void
    {
        $this->postJson($this->url(), [
            'title'          => 'Network Engineer',
            'desired_skills' => ['BGP', ' bgp ', '', 'MPLS', 'BGP'],
        ])->assertCreated();

        $this->assertSame(['BGP', 'MPLS'], Designation::firstOrFail()->desired_skills);
    }

    public function test_the_skills_survive_a_round_trip_through_the_api(): void
    {
        $role = Designation::create([
            'organization_id' => $this->org->id,
            'title'           => 'Solutions Architect',
            'desired_skills'  => ['Azure', 'Terraform'],
        ]);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.0.desired_skills', ['Azure', 'Terraform']);

        $this->putJson($this->url("/{$role->id}"), [
            'certifications' => ['AZ-305'],
        ])->assertOk();

        $this->assertSame(['AZ-305'], $role->fresh()->certifications);
    }

    /**
     * `description` is the one-line summary the org screens print. The long
     * form has its own column so a full job description does not push a wall
     * of text through every listing.
     */
    public function test_the_summary_stays_short_while_the_long_form_does_not(): void
    {
        $this->postJson($this->url(), [
            'title'       => 'Network Engineer',
            'description' => str_repeat('a', 501),
        ])->assertStatus(422);

        $this->postJson($this->url(), [
            'title'           => 'Network Engineer',
            'job_description' => str_repeat('a', 5000),
        ])->assertCreated();
    }

    public function test_a_role_with_nothing_recorded_says_nothing_rather_than_guessing(): void
    {
        $this->postJson($this->url(), ['title' => 'Network Engineer'])->assertCreated();

        $role = Designation::firstOrFail();

        $this->assertNull($role->desired_skills);
        $this->assertNull($role->certifications);
        $this->assertNull($role->job_description);
    }
}
