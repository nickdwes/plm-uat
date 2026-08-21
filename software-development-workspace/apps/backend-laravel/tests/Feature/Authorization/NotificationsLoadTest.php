<?php

namespace Tests\Feature\Authorization;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The notifications bell, which had never worked.
 *
 * Six call sites here and seven more in training reached for
 * `app('organizationId')`. Nothing has ever bound that name — the key is
 * `current.organization_id` — so every one threw a BindingResolutionException
 * and returned a 500 for the whole life of the feature. The bell is fetched on
 * every page load, so it failed constantly and silently, and read as a server
 * problem rather than a typo.
 */
class NotificationsLoadTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(?Organization $org): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Staff',
            'scope' => $org ? 'organization' : 'group',
            'permissions' => ['employees.view_own'],
        ]);

        $user->roles()->attach($role->id, [
            'organization_id' => $org?->id,
            'scope' => $org ? 'organization' : 'group',
        ]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_the_notifications_bell_answers(): void
    {
        $org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->signIn($org);

        $this->getJson('/api/v1/notifications', ['X-Organization' => 'alpha'])->assertOk();
    }

    public function test_the_unread_count_answers(): void
    {
        $org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->signIn($org);

        $this->getJson('/api/v1/notifications/count', ['X-Organization' => 'alpha'])
            ->assertOk();
    }

    /**
     * And for somebody who oversees the whole group.
     *
     * They have no single organisation in context, so the key resolves to null
     * — which is a valid answer meaning "no filter", and used to be the case
     * that threw hardest.
     */
    public function test_it_answers_for_somebody_working_across_the_group(): void
    {
        Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->signIn(null);

        $this->getJson('/api/v1/notifications')->assertOk();
    }
}
