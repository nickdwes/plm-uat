<?php

namespace Tests\Feature\Pipeline;

use App\Models\Account\Account;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Pipeline\Opportunity;
use App\Models\Pipeline\Research;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Research — work done to decide whether a deal is possible.
 *
 * Shaped from the legacy's twenty rows. Two facts from them drove the design:
 * every one was linked to a customer and none to a lead, opportunity or
 * project, so the link to a deal is new and all four stay optional; and the
 * three scope flags were set on most rows, so they are content rather than
 * decoration.
 */
class ResearchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);

        $this->account = Account::create([
            'organization_id' => $this->org->id, 'name' => 'Kenya Commercial Bank',
        ]);
    }

    private function actor(array $permissions): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = $this->employee();
        $employee->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/research{$path}?org={$this->org->slug}";
    }

    private function deal(): Opportunity
    {
        return Opportunity::create([
            'organization_id' => $this->org->id,
            'account_id'      => $this->account->id,
            'name'            => 'Integrate AI lead scoring module',
        ]);
    }

    // ── Raising research ──────────────────────────────────────────────────

    public function test_research_is_given_a_reference(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $res = $this->postJson($this->url(), ['name' => 'Employee Portal Revamp'])->assertCreated();

        $this->assertMatchesRegularExpression('/^RS-\d+$/', $res->json('data.reference'));
        $this->assertSame('new', $res->json('data.status'));
    }

    public function test_research_can_start_before_there_is_anything_to_attach_it_to(): void
    {
        /*
         * The legacy linked none of its twenty rows to a lead, opportunity or
         * project. Research genuinely starts before there is a deal, which is
         * most of the point of doing it.
         */
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $res = $this->postJson($this->url(), ['name' => 'Is this even possible'])->assertCreated();

        $this->assertNull($res->json('data.account'));
        $this->assertNull($res->json('data.opportunity'));
    }

    public function test_research_raised_against_a_deal_inherits_the_customer(): void
    {
        // Asking twice would invite the two disagreeing, and the answer is
        // already known.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $res = $this->postJson($this->url(), [
            'name' => 'Integration feasibility', 'opportunity_id' => $this->deal()->id,
        ])->assertCreated();

        $this->assertSame($this->account->id, $res->json('data.account.id'));
    }

    public function test_the_scope_flags_are_carried_from_the_legacy(): void
    {
        // Concept, architecture and integration are answered by different
        // people, so the scope decides who picks the work up.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $res = $this->postJson($this->url(), [
            'name' => 'Scoped', 'covers_concept' => true,
            'covers_architecture' => true, 'covers_integration' => false,
        ])->assertCreated();

        $this->assertTrue($res->json('data.scope.concept'));
        $this->assertTrue($res->json('data.scope.architecture'));
        $this->assertFalse($res->json('data.scope.integration'));
    }

    public function test_an_unanswered_scope_flag_stays_unanswered(): void
    {
        // Nullable rather than defaulting false: the legacy distinguished
        // "not asked" from "asked and no", and so does this.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $res = $this->postJson($this->url(), ['name' => 'Unscoped'])->assertCreated();

        $this->assertNull($res->json('data.scope.concept'));
    }

    public function test_the_plan_and_the_findings_are_separate(): void
    {
        // The legacy's `research_script` is what we intend to do; findings are
        // what came back. Collapsing them loses the first as soon as there is
        // an answer.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $res = $this->postJson($this->url(), [
            'name' => 'Load testing', 'script' => 'Run 10k concurrent sessions',
            'findings' => 'Fell over at 6k',
        ])->assertCreated();

        $this->assertSame('Run 10k concurrent sessions', $res->json('data.script'));
        $this->assertSame('Fell over at 6k', $res->json('data.findings'));
    }

    public function test_research_cannot_be_attached_to_another_tenants_deal(): void
    {
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $theirs = Opportunity::withoutGlobalScopes()->create([
            'organization_id' => $beta->id,
            'account_id' => Account::withoutGlobalScopes()->create([
                'organization_id' => $beta->id, 'name' => 'Theirs',
            ])->id,
            'name' => 'Theirs',
        ]);

        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $this->postJson($this->url(), ['name' => 'Poached', 'opportunity_id' => $theirs->id])
            ->assertStatus(422)->assertJsonValidationErrors('opportunity_id');
    }

    // ── Filtering ─────────────────────────────────────────────────────────

    public function test_a_deals_research_is_reachable_from_the_deal(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);
        $deal = $this->deal();

        $this->postJson($this->url(), ['name' => 'For this deal', 'opportunity_id' => $deal->id])->assertCreated();
        $this->postJson($this->url(), ['name' => 'For nothing'])->assertCreated();

        $this->getJson($this->url() . "&opportunity_id={$deal->id}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    // ── Team and visibility ───────────────────────────────────────────────

    public function test_somebody_can_be_added_to_the_research_team(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE, Permission::PIPELINE_UPDATE]);

        $id = $this->postJson($this->url(), ['name' => 'Team research'])->json('data.id');

        $res = $this->postJson($this->url("/{$id}/team"), [
            'employee_id' => $this->employee()->id, 'role' => 'Integration',
        ])->assertOk();

        $this->assertSame('Integration', $res->json('data.team.0.role'));
    }

    public function test_a_researcher_can_open_what_they_were_asked_to_research(): void
    {
        // Being assigned work you cannot read would be a strange arrangement.
        $me = $this->actor([Permission::PIPELINE_VIEW_OWN]);

        $research = Research::create([
            'organization_id' => $this->org->id, 'name' => 'Assigned to me',
            'research_lead_id' => $this->employee()->id,
        ]);
        $research->team()->create(['organization_id' => $this->org->id, 'employee_id' => $me->id]);

        $this->getJson($this->url("/{$research->id}"))->assertOk();
    }

    public function test_research_that_is_neither_yours_nor_your_team_is_not_visible(): void
    {
        $this->actor([Permission::PIPELINE_VIEW_OWN]);

        $research = Research::create([
            'organization_id' => $this->org->id, 'name' => 'Somebody else\'s',
            'research_lead_id' => $this->employee()->id,
        ]);

        $this->getJson($this->url("/{$research->id}"))->assertForbidden();
    }

    public function test_raising_research_needs_more_than_reading_it(): void
    {
        $this->actor([Permission::PIPELINE_VIEW]);

        $this->postJson($this->url(), ['name' => 'Not allowed'])->assertForbidden();
    }
}
