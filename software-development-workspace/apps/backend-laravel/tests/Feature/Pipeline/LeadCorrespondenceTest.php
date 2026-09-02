<?php

namespace Tests\Feature\Pipeline;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Employee;
use App\Models\Integration\IntegrationConnection;
use App\Models\Organization;
use App\Models\Pipeline\Lead;
use App\Models\Pipeline\LeadRequest;
use App\Models\Pipeline\LeadStakeholder;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A lead's correspondence: the calls, the mail, and what pursuing it costs.
 *
 * Graph is faked throughout. What is proved is our side of it — which addresses
 * a lead's mail is searched by, that a repeated pull only adds what is new, and
 * that spending money on a deal nobody has won yet takes a second person.
 */
class LeadCorrespondenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Account $account;
    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);

        $conn = IntegrationConnection::create([
            'organization_id' => $this->org->id, 'name' => 'MS', 'provider' => 'microsoft',
            'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'is_active' => true,
        ]);
        $this->org->update(['calendar_connection_id' => $conn->id]);

        config(['mail.from.address' => 'sales@alpha.example']);
        putenv('HR_CALENDAR_MAILBOX=sales@alpha.example');

        $this->account = Account::create([
            'organization_id' => $this->org->id, 'name' => 'Kengen',
        ]);

        $this->lead = Lead::create([
            'organization_id' => $this->org->id, 'name' => 'FTTX servers',
            'account_id' => $this->account->id, 'status' => 'new', 'priority' => 'medium',
        ]);
    }

    // ── Actors ────────────────────────────────────────────────────────────

    private function actor(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'T', 'last_name' => 'P',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01', 'user_id' => $user->id,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function worker(): User
    {
        return $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
    }

    private function approver(): User
    {
        return $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE, Permission::PIPELINE_CLOSE]);
    }

    private function url(string $path): string
    {
        return "/api/v1/leads/{$this->lead->id}{$path}?org={$this->org->slug}";
    }

    // ── Meetings ──────────────────────────────────────────────────────────

    private function fakeCalendar(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok'], 200),
            '*/events*' => Http::response([
                'id'            => 'evt-1',
                'onlineMeeting' => ['joinUrl' => 'https://teams.example/meet/1'],
            ], 201),
            '*' => Http::response([], 200),
        ]);
    }

    public function test_a_meeting_can_be_booked_against_a_lead(): void
    {
        $this->worker();
        $this->fakeCalendar();

        $res = $this->postJson($this->url('/meetings'), [
            'subject'          => 'Scoping call',
            'scheduled_at'     => '2026-09-10T09:00:00Z',
            'duration_minutes' => 45,
            'agenda'           => 'Walk the requirement.',
            'attendees'        => [['name' => 'Josphat', 'address' => 'j@kengen.example']],
        ])->assertStatus(201);

        $this->assertSame('Scoping call', $res->json('data.subject'));
        $this->assertSame('https://teams.example/meet/1', $res->json('data.join_url'));
        $this->assertSame('scheduled', $res->json('data.status'));
    }

    public function test_the_people_worth_inviting_come_with_the_list(): void
    {
        // Otherwise the form is an empty box and somebody goes hunting for
        // addresses they already recorded on the customer.
        $this->worker();

        AccountContact::create([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id,
            'first_name' => 'Grace', 'last_name' => 'Muli', 'email' => 'grace@kengen.example', 'category' => 'primary',
        ]);
        LeadStakeholder::create([
            'organization_id' => $this->org->id, 'lead_id' => $this->lead->id,
            'name' => 'Josphat Biwott', 'email' => 'josphat@kengen.example',
        ]);

        $res = $this->getJson($this->url('/meetings'))->assertOk();

        $this->assertSame(
            ['josphat@kengen.example', 'grace@kengen.example'],
            array_column($res->json('suggested_attendees'), 'address'),
        );
    }

    public function test_our_own_people_are_offered_too(): void
    {
        // A lead with nobody named on the customer's side used to produce an
        // empty list and a button that could never enable — a meeting
        // scheduler you cannot schedule a meeting with. There is always
        // somebody on our side of a lead.
        $this->worker();

        $owner = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'Mary', 'last_name' => 'Wanjiru',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'work_email' => 'mary@alpha.example',
        ]);
        $this->lead->update(['owner_id' => $owner->id]);

        $res = $this->getJson($this->url('/meetings'))->assertOk();
        $people = $res->json('suggested_attendees');

        $this->assertSame(['mary@alpha.example'], array_column($people, 'address'));
        $this->assertSame('ours', $people[0]['side']);
    }

    public function test_anybody_with_an_address_can_be_invited(): void
    {
        // The person you most want at a first call is often somebody nobody
        // has filed against the lead yet.
        $this->worker();
        $this->fakeCalendar();

        $res = $this->postJson($this->url('/meetings'), [
            'subject' => 'Scoping call', 'scheduled_at' => '2026-09-10T09:00:00Z',
            'duration_minutes' => 45,
            'attendees' => [['name' => 'Nobody On File', 'address' => 'stranger@elsewhere.example']],
        ])->assertStatus(201);

        $this->assertSame('stranger@elsewhere.example', $res->json('data.attendees.0.address'));
    }

    public function test_a_meeting_needs_somebody_to_attend_it(): void
    {
        $this->worker();

        $this->postJson($this->url('/meetings'), [
            'subject' => 'Scoping call', 'scheduled_at' => '2026-09-10T09:00:00Z',
            'duration_minutes' => 45, 'attendees' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('attendees');
    }

    public function test_booking_a_meeting_needs_more_than_being_able_to_read_the_lead(): void
    {
        $this->actor([Permission::PIPELINE_VIEW]);

        $this->postJson($this->url('/meetings'), [
            'subject' => 'Scoping call', 'scheduled_at' => '2026-09-10T09:00:00Z',
            'duration_minutes' => 45, 'attendees' => [['address' => 'j@kengen.example']],
        ])->assertForbidden();
    }

    // ── Emails ────────────────────────────────────────────────────────────

    /** @var list<array<string, mixed>> what the fake mailbox currently holds */
    private array $inbox = [];

    /**
     * One stub, reading a mutable inbox.
     *
     * Calling Http::fake() a second time does not replace the first stub — the
     * earlier one still matches first — so a test that pulls twice has to
     * change the inbox rather than re-fake it.
     */
    private function fakeMail(array $messages): void
    {
        $this->inbox = $messages;

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok'], 200),
            '*/messages*' => fn () => Http::response(['value' => $this->inbox], 200),
        ]);
    }

    private function message(string $id, string $subject, string $received = '2026-08-20T09:00:00Z'): array
    {
        return [
            'id' => $id, 'internetMessageId' => "<{$id}@kengen.example>", 'subject' => $subject,
            'bodyPreview' => 'Morning,', 'body' => ['content' => '<p>Morning,</p>'],
            'from' => ['emailAddress' => ['name' => 'Josphat', 'address' => 'josphat@kengen.example']],
            'toRecipients' => [['emailAddress' => ['name' => 'Sales', 'address' => 'sales@alpha.example']]],
            'ccRecipients' => [], 'receivedDateTime' => $received, 'hasAttachments' => false,
        ];
    }

    public function test_a_lead_with_nobody_named_on_it_has_nothing_to_search_for(): void
    {
        // A lead has no mailbox of its own, so with no people there is no query
        // to make — and saying that is more use than an empty list.
        $this->worker();

        $res = $this->postJson($this->url('/emails/sync'))->assertOk();

        $this->assertSame('no_correspondents', $res->json('result.status'));
        $this->assertStringContainsString('Nobody is named on this lead yet', $res->json('message'));
    }

    public function test_mail_is_found_by_the_people_on_the_lead(): void
    {
        $this->worker();
        LeadStakeholder::create([
            'organization_id' => $this->org->id, 'lead_id' => $this->lead->id,
            'name' => 'Josphat', 'email' => 'josphat@kengen.example',
        ]);
        $this->fakeMail([$this->message('m-1', 'Re: FTTX servers')]);

        $res = $this->postJson($this->url('/emails/sync'))->assertOk();

        $this->assertSame(1, $res->json('result.new'));
        $this->assertSame('Re: FTTX servers', $res->json('data.0.subject'));

        // Why this message is on this lead at all.
        $this->assertSame('josphat@kengen.example', $res->json('data.0.matched_address'));

        Http::assertSent(fn ($request) => str_contains(
            urldecode($request->url()),
            'participants:josphat@kengen.example',
        ));
    }

    public function test_pulling_twice_adds_only_what_is_new(): void
    {
        $this->worker();
        LeadStakeholder::create([
            'organization_id' => $this->org->id, 'lead_id' => $this->lead->id,
            'name' => 'Josphat', 'email' => 'josphat@kengen.example',
        ]);

        $this->fakeMail([$this->message('m-1', 'First')]);
        $this->postJson($this->url('/emails/sync'))->assertOk();

        // The same message plus one more, as a second pull would really see.
        $this->inbox[] = $this->message('m-2', 'Second');
        $res = $this->postJson($this->url('/emails/sync'))->assertOk();

        $this->assertSame(1, $res->json('result.new'));
        $this->assertCount(2, $res->json('data'));
    }

    public function test_the_addresses_being_searched_are_shown_with_the_mail(): void
    {
        // An empty list means one of two very different things: nobody wrote,
        // or nobody has been named.
        $this->worker();
        AccountContact::create([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id,
            'first_name' => 'Grace', 'last_name' => 'Muli', 'email' => 'grace@kengen.example', 'category' => 'primary',
        ]);

        $res = $this->getJson($this->url('/emails'))->assertOk();

        $this->assertSame(['grace@kengen.example'], $res->json('correspondents'));
    }

    // ── Requests ──────────────────────────────────────────────────────────

    public function test_a_resource_can_be_asked_for_against_a_lead(): void
    {
        $this->worker();

        $res = $this->postJson($this->url('/requests'), [
            'kind' => 'site_survey', 'purpose' => 'Survey the Nakuru branch',
            'location' => 'Nakuru', 'needed_on' => '2026-09-12',
            'people' => 3, 'estimated_cost' => 45000,
        ])->assertStatus(201);

        $this->assertSame('site_survey', $res->json('data.kind'));
        $this->assertSame('Site survey', $res->json('data.kind_label'));
        $this->assertSame('pending', $res->json('data.status'));
        $this->assertSame('LR-10000', $res->json('data.reference'));
        $this->assertSame('KES', $res->json('data.currency'));
    }

    public function test_the_four_things_a_pursuit_costs_are_all_askable(): void
    {
        $this->worker();

        foreach (['transport', 'travel', 'lunch', 'site_survey'] as $kind) {
            $this->postJson($this->url('/requests'), ['kind' => $kind, 'purpose' => 'x'])
                ->assertStatus(201);
        }

        $this->assertCount(4, $this->getJson($this->url('/requests'))->assertOk()->json('data'));
    }

    public function test_something_we_do_not_provide_is_refused(): void
    {
        $this->worker();

        $this->postJson($this->url('/requests'), ['kind' => 'helicopter', 'purpose' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('kind');
    }

    public function test_working_a_lead_does_not_let_you_approve_your_own_spend_on_it(): void
    {
        // The whole point of an approval. Pre-sales money goes on a deal that
        // may never close, so a second person says yes.
        $this->worker();
        $id = $this->postJson($this->url('/requests'), ['kind' => 'lunch', 'purpose' => 'Room for six'])
            ->assertStatus(201)->json('data.id');

        $this->postJson($this->url("/requests/{$id}/decide"), ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_an_approver_can_say_yes(): void
    {
        $this->worker();
        $id = $this->postJson($this->url('/requests'), ['kind' => 'transport', 'purpose' => 'Car to Nakuru'])
            ->assertStatus(201)->json('data.id');

        $this->approver();
        $res = $this->postJson($this->url("/requests/{$id}/decide"), [
            'status' => 'approved', 'decision_comment' => 'Use the pool car.',
        ])->assertOk();

        $this->assertSame('approved', $res->json('data.status'));
        $this->assertNotNull($res->json('data.decided_at'));
        $this->assertNotNull($res->json('data.decided_by'));
    }

    public function test_declining_says_why(): void
    {
        // A refusal with no reason gets asked again next week.
        $this->worker();
        $id = $this->postJson($this->url('/requests'), ['kind' => 'travel', 'purpose' => 'Flight to Kisumu'])
            ->assertStatus(201)->json('data.id');

        $this->approver();
        $this->postJson($this->url("/requests/{$id}/decide"), ['status' => 'declined'])
            ->assertStatus(422)->assertJsonValidationErrors('decision_comment');
    }

    public function test_a_request_is_answered_once(): void
    {
        $this->worker();
        $id = $this->postJson($this->url('/requests'), ['kind' => 'lunch', 'purpose' => 'x'])
            ->assertStatus(201)->json('data.id');

        $this->approver();
        $this->postJson($this->url("/requests/{$id}/decide"), ['status' => 'approved'])->assertOk();
        $this->postJson($this->url("/requests/{$id}/decide"), ['status' => 'declined', 'decision_comment' => 'no'])
            ->assertStatus(422);
    }

    public function test_only_an_approved_request_can_be_marked_as_provided(): void
    {
        $this->worker();
        $id = $this->postJson($this->url('/requests'), ['kind' => 'transport', 'purpose' => 'x'])
            ->assertStatus(201)->json('data.id');

        $this->postJson($this->url("/requests/{$id}/fulfil"))->assertStatus(422);

        $this->approver();
        $this->postJson($this->url("/requests/{$id}/decide"), ['status' => 'approved'])->assertOk();

        $this->worker();
        $this->assertSame(
            'fulfilled',
            $this->postJson($this->url("/requests/{$id}/fulfil"))->assertOk()->json('data.status'),
        );
    }

    public function test_an_unanswered_request_can_be_withdrawn(): void
    {
        $this->worker();
        $id = $this->postJson($this->url('/requests'), ['kind' => 'lunch', 'purpose' => 'x'])
            ->assertStatus(201)->json('data.id');

        $this->deleteJson($this->url("/requests/{$id}"))->assertOk();

        $this->assertDatabaseCount('lead_requests', 0);
    }

    public function test_an_answered_request_stays_on_the_record(): void
    {
        // Deleting it would erase what was refused, and by whom.
        $this->worker();
        $id = $this->postJson($this->url('/requests'), ['kind' => 'lunch', 'purpose' => 'x'])
            ->assertStatus(201)->json('data.id');

        $this->approver();
        $this->postJson($this->url("/requests/{$id}/decide"), ['status' => 'declined', 'decision_comment' => 'Too early.'])
            ->assertOk();

        $this->deleteJson($this->url("/requests/{$id}"))->assertStatus(422);
        $this->assertDatabaseCount('lead_requests', 1);
    }

    public function test_correspondence_belongs_to_its_own_lead(): void
    {
        $this->worker();
        $other = Lead::create([
            'organization_id' => $this->org->id, 'name' => 'Another', 'status' => 'new', 'priority' => 'medium',
        ]);
        $foreign = LeadRequest::create([
            'organization_id' => $this->org->id, 'lead_id' => $other->id,
            'kind' => 'lunch', 'purpose' => 'x', 'status' => 'pending',
        ]);

        $this->deleteJson($this->url("/requests/{$foreign->id}"))->assertNotFound();
    }
}
