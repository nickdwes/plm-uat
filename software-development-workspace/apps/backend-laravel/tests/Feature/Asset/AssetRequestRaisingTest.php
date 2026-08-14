<?php

namespace Tests\Feature\Asset;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetRequest;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Asking for something you do not have.
 *
 * The chain could advance, decline, forward and fulfil a request since the
 * approval work went in — and nothing could raise one. Every stage after the
 * first existed for a record with no way of being created, so the tab read
 * "No requests have been raised" and always would have.
 */
class AssetRequestRaisingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    private function employee(string $first = 'Grace', ?Organization $org = null): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => ($org ?? $this->org)->id,
            'first_name' => $first, 'last_name' => 'Njoroge',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function signIn(array $permissions = ['assets.view', 'assets.create'], bool $linked = true): ?Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        if (! $linked) {
            return null;
        }

        $employee = $this->employee();
        $employee->update(['user_id' => $user->id]);

        return $employee->fresh();
    }

    private function url(): string
    {
        return "/api/v1/assets/requests?org={$this->org->slug}";
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'item'     => 'Laptop, 16GB',
            'category' => 'laptop',
            'quantity' => 1,
            'urgency'  => 'normal',
            'reason'   => 'Starting on the new project and the current one will not build.',
        ], $overrides);
    }

    public function test_a_request_can_be_raised(): void
    {
        $me = $this->signIn();

        $this->postJson($this->url(), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', AssetRequest::STATUS_PENDING);

        $raised = AssetRequest::first();
        $this->assertSame($me->id, $raised->employee_id);
        $this->assertSame('Laptop, 16GB', $raised->item);
    }

    /** It has to appear in the list it was invisible from. */
    public function test_a_raised_request_shows_on_the_requests_tab(): void
    {
        $this->signIn();
        $this->postJson($this->url(), $this->payload())->assertCreated();

        $this->getJson("/api/v1/assets/requests?org={$this->org->slug}")
            ->assertOk()
            ->assertJsonPath('data.data.0.item', 'Laptop, 16GB')
            ->assertJsonPath('data.data.0.status', AssetRequest::STATUS_PENDING);
    }

    /** It starts where the chain starts, not at approved. */
    public function test_a_new_request_enters_at_the_first_stage(): void
    {
        $this->signIn();
        $this->postJson($this->url(), $this->payload())->assertCreated();

        $this->assertSame(AssetRequest::STATUS_PENDING, AssetRequest::first()->status);
        $this->assertNull(AssetRequest::first()->approved_by);
    }

    /**
     * Two people asking at the same moment must not be handed one reference —
     * the unique index would reject the second with a 500 that explains nothing.
     */
    public function test_references_are_sequential_within_an_organisation(): void
    {
        $this->signIn();

        $this->postJson($this->url(), $this->payload())->assertCreated();
        $this->postJson($this->url(), $this->payload(['item' => 'Monitor']))->assertCreated();
        $this->postJson($this->url(), $this->payload(['item' => 'Dock']))->assertCreated();

        $year = now()->format('Y');

        $this->assertSame(
            ["AR-{$year}-0001", "AR-{$year}-0002", "AR-{$year}-0003"],
            AssetRequest::orderBy('id')->pluck('request_number')->all(),
        );
    }

    /** HR raising one on somebody's behalf is ordinary. */
    public function test_a_request_can_be_raised_for_somebody_else(): void
    {
        $this->signIn();
        $colleague = $this->employee('Peter');

        $this->postJson($this->url(), $this->payload(['employee_id' => $colleague->id]))
            ->assertCreated();

        $this->assertSame($colleague->id, AssetRequest::first()->employee_id);
    }

    /** `exists` knows nothing about which organisation an employee is in. */
    public function test_an_employee_from_another_organisation_is_refused(): void
    {
        $this->signIn();
        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $stranger = $this->employee('Someone', $other);

        $this->postJson($this->url(), $this->payload(['employee_id' => $stranger->id]))
            ->assertStatus(422);
    }

    /**
     * An account with no employee record cannot be the requester, and saying so
     * beats writing a null and producing a request for nobody.
     */
    public function test_an_unlinked_account_must_say_who_it_is_for(): void
    {
        $this->signIn(linked: false);

        $this->postJson($this->url(), $this->payload())
            ->assertStatus(422);
    }

    /** The column is not null, and a request filed under nothing cannot be matched. */
    public function test_a_category_is_required(): void
    {
        $this->signIn();

        $this->postJson($this->url(), $this->payload(['category' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_a_reason_is_required(): void
    {
        $this->signIn();

        $this->postJson($this->url(), $this->payload(['reason' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_raising_needs_permission(): void
    {
        $this->signIn(['assets.view']);

        $this->postJson($this->url(), $this->payload())->assertForbidden();
    }
}
