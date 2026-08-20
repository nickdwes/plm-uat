<?php

namespace Tests\Feature\Support;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Support\Approval;
use App\Models\Support\ApprovalStage;
use App\Models\Support\Change;
use App\Models\Support\Problem;
use App\Models\User;
use App\Services\Support\ApprovalService;
use App\Services\Support\SupportDefaults;
use App\Services\Support\TicketService;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Causes, deliberate changes, and somebody actually signing things off.
 *
 * The old module had "Problem" as a ticket type and approval as a word on a
 * status — nothing asked anybody, nothing recorded an answer, and nothing
 * stopped the work starting anyway.
 */
class ChangeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        app(SupportDefaults::class)->ensureFor($this->org->id);
    }

    private function approvals(): ApprovalService { return app(ApprovalService::class); }

    private function change(array $attrs = []): Change
    {
        return Change::create(array_merge([
            'organization_id' => $this->org->id,
            'reference'   => Change::nextReference($this->org->id),
            'title'       => 'Replace the core switch',
            'change_type' => Change::NORMAL,
            'risk'        => 'high',
            'rollback_plan' => 'Refit the old switch.',
        ], $attrs));
    }

    private function stage(string $name, int $sequence, ?User $approver = null, ?string $risk = null): ApprovalStage
    {
        return ApprovalStage::create([
            'organization_id' => $this->org->id,
            'stage' => $name, 'sequence' => $sequence,
            'applies_to' => ApprovalStage::FOR_CHANGE,
            'risk' => $risk, 'approver_id' => $approver?->id,
        ]);
    }

    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Desk', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    // ── Problems ──────────────────────────────────────────────────────────────

    /**
     * A known error tells the desk there is something to tell the customer.
     * Without a workaround it says that and is not true.
     */
    public function test_a_known_error_needs_a_workaround(): void
    {
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        $this->postJson('/api/v1/support/problems', [
            'title' => 'Switch drops packets under load', 'status' => 'known_error',
        ], ['X-Organization' => 'alpha'])->assertStatus(422);

        $this->postJson('/api/v1/support/problems', [
            'title' => 'Switch drops packets under load', 'status' => 'known_error',
            'workaround' => 'Move the affected VLAN to the spare uplink.',
        ], ['X-Organization' => 'alpha'])->assertCreated();
    }

    /** Forty tickets, one cause — which is the thing the old module could not say. */
    public function test_a_problem_accounts_for_several_tickets(): void
    {
        $problem = Problem::create([
            'organization_id' => $this->org->id,
            'reference' => Problem::nextReference($this->org->id),
            'title' => 'Failing core switch',
        ]);

        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        foreach (range(1, 3) as $i) {
            $ticket = app(TicketService::class)->open($this->org->id, ['subject' => "Outage {$i}"]);

            $this->postJson("/api/v1/support/problems/{$problem->id}/tickets",
                ['ticket_reference' => $ticket->reference],
                ['X-Organization' => 'alpha'])->assertOk();
        }

        $this->assertSame(3, $problem->fresh()->links()->count());
    }

    // ── Changes ───────────────────────────────────────────────────────────────

    /** The question nobody asks at 2am has to be answered before the window opens. */
    public function test_a_rollback_plan_is_required_on_anything_but_a_standard_change(): void
    {
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        $this->postJson('/api/v1/support/changes', [
            'title' => 'Firmware upgrade', 'change_type' => 'normal', 'risk' => 'high',
        ], ['X-Organization' => 'alpha'])->assertStatus(422);

        // A standard change is routine by definition.
        $this->postJson('/api/v1/support/changes', [
            'title' => 'Monthly patching', 'change_type' => 'standard', 'risk' => 'low',
        ], ['X-Organization' => 'alpha'])->assertCreated();
    }

    /** Asking for sign-off on something pre-approved teaches people to stop reading. */
    public function test_a_standard_change_is_approved_without_asking_anybody(): void
    {
        $change = $this->change(['change_type' => Change::STANDARD]);

        $this->assertSame(0, $this->approvals()->requestForChange($change));
        $this->assertSame(Change::APPROVED, $change->fresh()->status);
        $this->assertSame(0, $change->approvals()->count());
    }

    /**
     * A change nobody was asked about would sit waiting for a decision nobody
     * has been asked to make.
     */
    public function test_a_change_with_no_matching_stage_is_refused_rather_than_left_waiting(): void
    {
        $change = $this->change();

        $this->expectException(\RuntimeException::class);
        $this->approvals()->requestForChange($change);
    }

    // ── Approvals ─────────────────────────────────────────────────────────────

    public function test_stages_are_asked_in_order(): void
    {
        $first  = User::factory()->create();
        $second = User::factory()->create();

        $this->stage('Technical review', 1, $first);
        $this->stage('CAB', 2, $second);

        $change = $this->change();
        $this->assertSame(2, $this->approvals()->requestForChange($change));
        $this->assertSame(Change::AWAITING, $change->fresh()->status);

        // The second approver cannot decide before the first has answered.
        $late = $change->approvals()->where('sequence', 2)->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $this->approvals()->decide($late, Approval::APPROVED, null, $second);
    }

    public function test_a_change_is_approved_once_every_stage_has_answered(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->stage('Technical review', 1, $a);
        $this->stage('CAB', 2, $b);

        $change = $this->change();
        $this->approvals()->requestForChange($change);

        $this->approvals()->decide($change->approvals()->where('sequence', 1)->firstOrFail(),
            Approval::APPROVED, 'Looks sound.', $a);
        $this->assertSame(Change::AWAITING, $change->fresh()->status);

        $this->approvals()->decide($change->approvals()->where('sequence', 2)->firstOrFail(),
            Approval::APPROVED, null, $b);
        $this->assertSame(Change::APPROVED, $change->fresh()->status);
    }

    /**
     * One rejection ends it. Asking the remaining stages wastes their time on
     * something already refused, and the requests would sit in their queue.
     */
    public function test_a_rejection_stops_the_rest_of_the_chain(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->stage('Technical review', 1, $a);
        $this->stage('CAB', 2, $b);

        $change = $this->change();
        $this->approvals()->requestForChange($change);

        $this->approvals()->decide($change->approvals()->where('sequence', 1)->firstOrFail(),
            Approval::REJECTED, 'No rollback for the uplink.', $a);

        $this->assertSame(Change::REJECTED, $change->fresh()->status);
        $this->assertSame(Approval::SKIPPED,
            $change->approvals()->where('sequence', 2)->firstOrFail()->status);
    }

    /** "Rejected" alone sends the requester back to guess what was wrong. */
    public function test_a_rejection_needs_a_reason(): void
    {
        $a = User::factory()->create();
        $this->stage('CAB', 1, $a);

        $change = $this->change();
        $this->approvals()->requestForChange($change);

        $this->expectException(\RuntimeException::class);
        $this->approvals()->decide($change->approvals()->first(), Approval::REJECTED, null, $a);
    }

    /** A stage with only a risk on it matches on risk and nothing else. */
    public function test_a_risk_specific_stage_only_applies_to_that_risk(): void
    {
        $a = User::factory()->create();
        $this->stage('Everything', 1, $a);
        $this->stage('High risk only', 2, $a, risk: 'high');

        $high = $this->change(['risk' => 'high']);
        $low  = $this->change(['risk' => 'low', 'reference' => Change::nextReference($this->org->id)]);

        $this->assertSame(2, $this->approvals()->requestForChange($high));
        $this->assertSame(1, $this->approvals()->requestForChange($low));
    }

    /** Raising a change and waving it through are opposite jobs. */
    public function test_approving_needs_its_own_permission(): void
    {
        $approver = User::factory()->create();
        $this->stage('CAB', 1, $approver);

        $change = $this->change();
        $this->approvals()->requestForChange($change);
        $approval = $change->approvals()->first();

        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        $this->postJson("/api/v1/support/approvals/{$approval->id}",
            ['decision' => 'approved'], ['X-Organization' => 'alpha'])->assertForbidden();

        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_APPROVE]);

        $this->postJson("/api/v1/support/approvals/{$approval->id}",
            ['decision' => 'approved'], ['X-Organization' => 'alpha'])->assertOk();
    }

    /** Only the stage that is actually next appears in somebody's queue. */
    public function test_an_approver_is_not_shown_a_stage_that_is_not_theirs_yet(): void
    {
        $first  = User::factory()->create();
        $second = User::factory()->create();
        $this->stage('Technical review', 1, $first);
        $this->stage('CAB', 2, $second);

        $change = $this->change();
        $this->approvals()->requestForChange($change);

        $this->assertCount(1, $this->approvals()->pendingFor($first, $this->org->id));
        $this->assertCount(0, $this->approvals()->pendingFor($second, $this->org->id));
    }

    // ── Outcomes ──────────────────────────────────────────────────────────────

    /** A failed change with no note is the one somebody repeats next quarter. */
    public function test_a_failed_change_must_say_what_went_wrong(): void
    {
        $change = $this->change();
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        $this->postJson("/api/v1/support/changes/{$change->id}/outcome",
            ['status' => 'failed'], ['X-Organization' => 'alpha'])->assertStatus(422);

        $this->postJson("/api/v1/support/changes/{$change->id}/outcome",
            ['status' => 'failed', 'outcome' => 'Uplink would not come back up on the old firmware.'],
            ['X-Organization' => 'alpha'])->assertOk();
    }

    public function test_the_calendar_only_shows_what_is_coming(): void
    {
        $this->change(['status' => Change::SCHEDULED, 'window_start' => now()->addWeek()]);
        $this->change([
            'reference' => Change::nextReference($this->org->id),
            'status' => Change::IMPLEMENTED, 'window_start' => now()->subMonth(),
        ]);

        $this->signIn([Permission::SUPPORT_VIEW]);

        $this->getJson('/api/v1/support/changes', ['X-Organization' => 'alpha'])
            ->assertOk()
            ->assertJsonCount(1, 'calendar');
    }
}
