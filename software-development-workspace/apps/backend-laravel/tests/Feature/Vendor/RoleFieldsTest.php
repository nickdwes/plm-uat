<?php

namespace Tests\Feature\Vendor;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Models\Vendor\StakeholderRoleField;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The form asks what the role it is asking about actually needs to know.
 *
 * A consultant has a professional body rather than a company registration,
 * and a day rate rather than a renewal cost. None of that had anywhere to go
 * before: the registration form was company-shaped for everybody, so it all
 * ended up in the notes box where nothing can be searched or made mandatory.
 */
class RoleFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    private function actor(array $permissions = [Permission::VENDORS_VIEW, Permission::VENDORS_MANAGE]): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function url(string $path): string
    {
        return "/api/v1{$path}?org={$this->org->slug}";
    }

    private function fieldsFor(array $roles, string $partyClass = 'organization'): array
    {
        // Comma separated, so "no roles at all" is expressible — an empty
        // array simply vanishes from a query string.
        $query = http_build_query([
            'roles'       => implode(',', $roles),
            'party_class' => $partyClass,
        ]);

        return $this->getJson($this->url('/vendors/role-fields') . '&' . $query)
            ->assertOk()->json('data');
    }

    // ── What gets asked ───────────────────────────────────────────────────

    public function test_a_consultant_is_asked_about_their_practice(): void
    {
        $this->actor();

        $keys = array_column($this->fieldsFor(['consultant'], 'individual'), 'key');

        $this->assertContains('specialisation', $keys);
        $this->assertContains('professional_body', $keys);
        $this->assertContains('day_rate', $keys);
        // And, being a person, an ID rather than a company registration.
        $this->assertContains('national_id_number', $keys);
    }

    public function test_a_distributor_is_asked_about_territory_not_a_day_rate(): void
    {
        $this->actor();

        $keys = array_column($this->fieldsFor(['distributor']), 'key');

        $this->assertContains('territories', $keys);
        $this->assertContains('oems_represented', $keys);
        $this->assertNotContains('day_rate', $keys);
        $this->assertNotContains('specialisation', $keys);
    }

    public function test_an_organisation_is_never_asked_for_a_national_id(): void
    {
        $this->actor();

        $keys = array_column($this->fieldsFor(['consultant'], 'organization'), 'key');

        // The party-class narrowing: a firm has a registration number, which
        // the static form already asks for.
        $this->assertNotContains('national_id_number', $keys);
        $this->assertContains('specialisation', $keys);
    }

    public function test_two_roles_are_asked_both_sets_once(): void
    {
        $this->actor();

        $fields = $this->fieldsFor(['distributor', 'contractor_company']);
        $keys   = array_column($fields, 'key');

        $this->assertContains('territories', $keys);
        $this->assertContains('crew_size', $keys);
        // `trade` is asked by contractor_company only; a shared key would
        // appear once — it is one firm.
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    // ── Answers ───────────────────────────────────────────────────────────

    public function test_answers_are_stored_against_the_party(): void
    {
        $this->actor();

        $vendor = $this->postJson($this->url('/vendors'), [
            'name'         => 'Jane Wanjiru',
            'party_class'  => 'individual',
            'roles'        => ['consultant'],
            'role_details' => [
                'national_id_number' => '12345678',
                'specialisation'     => 'Network security architecture',
                'professional_body'  => 'ICTAK',
                'day_rate'           => 45000,
            ],
        ])->assertStatus(201)->json('data');

        $this->assertSame('ICTAK', $vendor['role_details']['professional_body']);
        $this->assertSame('Network security architecture', $vendor['role_details']['specialisation']);
    }

    public function test_a_required_answer_blocks_the_submission_not_the_draft(): void
    {
        $this->actor();

        /*
         * A draft is unfinished by definition — refusing to save one loses
         * whatever the person had typed. `specialisation` is mandatory for a
         * consultant, so it turns up where the missing documents do: as a
         * blocker on the record, stopping the submission.
         */
        $vendor = $this->postJson($this->url('/vendors'), [
            'name' => 'Jane Wanjiru', 'party_class' => 'individual',
            'roles' => ['consultant'],
            'role_details' => ['national_id_number' => '12345678'],
        ])->assertStatus(201)->json('data');

        $blockers = $this->getJson($this->url("/vendors/{$vendor['id']}"))
            ->assertOk()->json('data.activation_blockers');

        $this->assertNotEmpty(array_filter(
            $blockers, fn ($b) => str_contains($b, 'Area of specialisation'),
        ));

        // Answered, it stops blocking.
        $this->putJson($this->url("/vendors/{$vendor['id']}"), [
            'role_details' => ['specialisation' => 'Network security'],
        ])->assertOk();

        $blockers = $this->getJson($this->url("/vendors/{$vendor['id']}"))->json('data.activation_blockers');
        $this->assertEmpty(array_filter($blockers, fn ($b) => str_contains($b, 'Area of specialisation')));
    }

    public function test_a_select_field_refuses_a_value_outside_its_options(): void
    {
        $this->actor();

        $this->postJson($this->url('/vendors'), [
            'name' => 'Jane Wanjiru', 'party_class' => 'individual',
            'roles' => ['consultant'],
            'role_details' => [
                'national_id_number' => '1', 'specialisation' => 'Security',
                'availability' => 'Whenever',
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('role_details.availability');
    }

    public function test_an_answer_to_a_question_nobody_asks_is_not_stored(): void
    {
        $this->actor();

        $vendor = $this->postJson($this->url('/vendors'), [
            'name' => 'Acme Ltd', 'roles' => ['supplier'],
            'role_details' => ['territories' => 'Everywhere', 'delivery_lead_time_days' => 5],
        ])->assertStatus(201)->json('data');

        // `territories` belongs to a distributor; this party is not one.
        $this->assertArrayNotHasKey('territories', $vendor['role_details']);
        $this->assertSame(5, $vendor['role_details']['delivery_lead_time_days']);
    }

    public function test_editing_one_field_does_not_fail_the_others(): void
    {
        $this->actor();

        $vendor = $this->postJson($this->url('/vendors'), [
            'name' => 'Jane Wanjiru', 'party_class' => 'individual',
            'roles' => ['consultant'],
            'role_details' => ['national_id_number' => '12345678', 'specialisation' => 'Security'],
        ])->assertStatus(201)->json('data');

        // Carrying only the changed field: the required ones are already
        // answered and sitting on the record.
        $updated = $this->putJson($this->url("/vendors/{$vendor['id']}"), [
            'role_details' => ['day_rate' => 50000],
        ])->assertOk()->json('data');

        $this->assertSame('Security', $updated['role_details']['specialisation']);
        $this->assertSame(50000, $updated['role_details']['day_rate']);
    }

    public function test_ending_a_role_stops_the_asking_without_erasing_the_answer(): void
    {
        $this->actor();

        $vendor = $this->postJson($this->url('/vendors'), [
            'name' => 'Acme Distribution', 'roles' => ['distributor'],
            'role_details' => ['territories' => 'Kenya, Uganda'],
        ])->assertStatus(201)->json('data');

        $roleId = collect($vendor['roles'])->firstWhere('role', 'distributor')['id'];
        $this->patchJson($this->url("/vendors/{$vendor['id']}/roles/{$roleId}"), [])->assertOk();

        // The form stops asking…
        $this->assertNotContains('territories', array_column($this->fieldsFor([]), 'key'));

        // …and the record keeps what that relationship recorded while it was
        // in force. This is where it parts company with the asset engine.
        $updated = $this->putJson($this->url("/vendors/{$vendor['id']}"), [
            'role_details' => [], 'notes' => 'Wound down',
        ])->assertOk()->json('data');

        $this->assertSame('Kenya, Uganda', $updated['role_details']['territories']);
    }

    // ── Configuration ─────────────────────────────────────────────────────

    public function test_a_tenant_that_has_never_configured_still_gets_the_baseline(): void
    {
        $this->actor();

        // No rows of its own — the organisation was created after the
        // migration ran, which is how every tenant added from here on begins.
        $this->assertSame(0, StakeholderRoleField::where('organization_id', $this->org->id)->count());

        $this->assertNotEmpty($this->fieldsFor(['consultant'], 'individual'));
    }

    public function test_reading_the_questions_needs_only_view(): void
    {
        $this->actor([Permission::VENDORS_VIEW]);

        $this->getJson($this->url('/vendors/role-fields'))->assertOk();
    }
}
