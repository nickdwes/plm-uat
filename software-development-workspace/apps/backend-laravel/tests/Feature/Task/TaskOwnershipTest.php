<?php

namespace Tests\Feature\Task;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Not every task is ours to do.
 *
 * 104 of the legacy's 9,055 tasks were owed by somebody else — Safaricom 26,
 * Versa 8, KBC 7, Data Core 7 and about thirty others — and 97 of those were
 * tracked all the way to Completed. It was a real, closed-out commitment, not a
 * note in a comment.
 *
 * What it got wrong was holding the owner as a string: one row reads
 * "Versa-Fatih", an organisation and a person in one field, and 974 rows left
 * it blank. So the party links to the customer or vendor already on file, and
 * only the person stays free text — which is right, because 95 of those 104
 * named somebody who does not work here.
 */
class TaskOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function coordinator(): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization',
            'permissions' => [
                Permission::TASKS_VIEW, Permission::TASKS_CREATE,
                Permission::TASKS_UPDATE, Permission::TASKS_DELETE,
            ],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
        $employee->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function account(string $name = 'Safaricom'): Account
    {
        return Account::create(['organization_id' => $this->org->id, 'name' => $name]);
    }

    private function vendor(string $name = 'Versa'): VendorPartner
    {
        return VendorPartner::create(['organization_id' => $this->org->id, 'name' => $name]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/tasks{$path}?org={$this->org->slug}";
    }

    private function task(array $attrs = []): Task
    {
        return Task::create($attrs + [
            'organization_id' => $this->org->id,
            'title'           => 'Open the firewall for the new subnet',
        ]);
    }

    // ── The default ───────────────────────────────────────────────────────

    /**
     * 7,977 legacy rows said Konvergenz and 974 more meant it by leaving the
     * field blank. A default rather than a nullable column, because "nobody
     * said" and "ours" are the same thing and a null would invite a third
     * state that means neither.
     */
    public function test_a_task_is_ours_unless_somebody_says_otherwise(): void
    {
        $this->coordinator();

        $this->postJson($this->url(), ['title' => 'Patch the core switches'])
            ->assertCreated()
            ->assertJsonPath('data.owner.party', 'internal')
            ->assertJsonPath('data.owner.is_ours', true)
            ->assertJsonPath('data.owner.name', null);
    }

    // ── Owed by a customer ────────────────────────────────────────────────

    public function test_a_task_can_be_owed_by_a_customer(): void
    {
        $this->coordinator();
        $account = $this->account();

        $this->postJson($this->url(), [
            'title' => 'Open the firewall for the new subnet',
            'owner_party' => 'customer',
            'owner_account_id' => $account->id,
            // Free text, because the client's engineer is not on our books.
            'owner_contact_name' => 'Milton Ope',
        ])->assertCreated()
            ->assertJsonPath('data.owner.is_ours', false)
            ->assertJsonPath('data.owner.name', 'Safaricom')
            ->assertJsonPath('data.owner.contact_name', 'Milton Ope');
    }

    /**
     * Naming the organisation is the whole point — a free-text owner cannot be
     * counted, and "everything still waiting on Safaricom" is the question
     * this exists to answer.
     */
    public function test_a_customer_owned_task_must_say_which_customer(): void
    {
        $this->coordinator();

        $this->postJson($this->url(), [
            'title' => 'Open the firewall', 'owner_party' => 'customer',
        ])->assertJsonValidationErrorFor('owner_account_id');
    }

    public function test_a_vendor_owned_task_must_say_which_vendor(): void
    {
        $this->coordinator();

        $this->postJson($this->url(), [
            'title' => 'Ship the replacement SFPs', 'owner_party' => 'vendor',
        ])->assertJsonValidationErrorFor('owner_vendor_partner_id');
    }

    public function test_a_task_can_be_owed_by_a_vendor(): void
    {
        $this->coordinator();
        $vendor = $this->vendor();

        $this->postJson($this->url(), [
            'title' => 'Ship the replacement SFPs',
            'owner_party' => 'vendor', 'owner_vendor_partner_id' => $vendor->id,
        ])->assertCreated()
            ->assertJsonPath('data.owner.party', 'vendor')
            ->assertJsonPath('data.owner.name', 'Versa');
    }

    /** Where the person is already a customer contact, use the record. */
    public function test_a_contact_on_file_is_used_in_place_of_the_typed_name(): void
    {
        $this->coordinator();
        $account = $this->account();

        $contact = AccountContact::create([
            'organization_id' => $this->org->id, 'account_id' => $account->id,
            'first_name' => 'Cyril', 'last_name' => 'Omurwa',
        ]);

        $this->postJson($this->url(), [
            'title' => 'Confirm the change window',
            'owner_party' => 'customer', 'owner_account_id' => $account->id,
            'owner_contact_id' => $contact->id,
        ])->assertCreated()->assertJsonPath('data.owner.contact_name', 'Cyril Omurwa');
    }

    // ── Switching hands ───────────────────────────────────────────────────

    /**
     * Otherwise a later filter finds the task under an organisation that no
     * longer owes anything — the quiet sort of wrongness the legacy's blank
     * owner column produced 974 times.
     */
    public function test_taking_a_task_back_clears_who_it_was_owed_by(): void
    {
        $this->coordinator();
        $account = $this->account();

        $task = $this->task([
            'owner_party' => 'customer', 'owner_account_id' => $account->id,
            'owner_contact_name' => 'Milton Ope',
        ]);

        $this->putJson($this->url('/' . $task->id), [
            'title' => $task->title, 'owner_party' => 'internal',
        ])->assertOk()->assertJsonPath('data.owner.is_ours', true);

        $this->assertNull($task->fresh()->owner_account_id);
        $this->assertNull($task->fresh()->owner_contact_name);
    }

    /** A customer-owned task has no vendor, and the reverse. */
    public function test_moving_a_task_from_a_customer_to_a_vendor_drops_the_customer(): void
    {
        $this->coordinator();
        $task = $this->task([
            'owner_party' => 'customer', 'owner_account_id' => $this->account()->id,
        ]);

        $this->putJson($this->url('/' . $task->id), [
            'title' => $task->title,
            'owner_party' => 'vendor', 'owner_vendor_partner_id' => $this->vendor()->id,
        ])->assertOk()->assertJsonPath('data.owner.name', 'Versa');

        $this->assertNull($task->fresh()->owner_account_id);
    }

    // ── Counting it apart ─────────────────────────────────────────────────

    /**
     * Someone chasing three things a client owes is not overloaded, and a
     * total that mixes the two says they are.
     */
    public function test_what_others_owe_is_counted_apart_from_our_workload(): void
    {
        $this->coordinator();
        $account = $this->account();

        $this->task(['title' => 'Ours']);
        $this->task(['title' => 'Theirs', 'owner_party' => 'customer', 'owner_account_id' => $account->id]);
        $this->task([
            'title' => 'Theirs, done', 'owner_party' => 'customer', 'owner_account_id' => $account->id,
            'status' => Task::DONE, 'completed_at' => now(),
        ]);

        $this->getJson($this->url('/summary'))
            ->assertOk()
            ->assertJsonPath('total', 3)
            // Only what is still outstanding — a closed commitment is not
            // something anybody has to chase.
            ->assertJsonPath('awaiting_others', 1);
    }

    public function test_the_list_can_be_narrowed_to_what_others_owe(): void
    {
        $this->coordinator();
        $account = $this->account();

        $this->task(['title' => 'Ours']);
        $this->task(['title' => 'Theirs', 'owner_party' => 'customer', 'owner_account_id' => $account->id]);

        $this->getJson($this->url() . '&state=awaiting_others')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Theirs');
    }

    /** Asked per organisation: everything still waiting on this customer. */
    public function test_the_list_can_be_narrowed_to_one_customer(): void
    {
        $this->coordinator();

        $this->task([
            'title' => 'Safaricom owes this',
            'owner_party' => 'customer', 'owner_account_id' => $this->account('Safaricom')->id,
        ]);
        $this->task([
            'title' => 'Versa owes this',
            'owner_party' => 'vendor', 'owner_vendor_partner_id' => $this->vendor('Versa')->id,
        ]);

        $this->getJson($this->url() . '&owner_party=customer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Safaricom owes this');
    }

    /**
     * An externally owned task still sits in somebody's list here — somebody
     * has to chase it. Ownership says who does the work, not who tracks it.
     */
    public function test_a_task_owed_by_a_customer_is_still_assigned_to_somebody_here(): void
    {
        $me      = $this->coordinator();
        $account = $this->account();

        $this->postJson($this->url(), [
            'title' => 'Open the firewall for the new subnet',
            'owner_party' => 'customer', 'owner_account_id' => $account->id,
            'assigned_to' => $me->id,
        ])->assertCreated()
            ->assertJsonPath('data.owner.is_ours', false)
            ->assertJsonPath('data.assignee.id', $me->id);
    }
}
