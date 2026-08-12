<?php

namespace Tests\Feature\Asset;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetRequest;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Services\Asset\AssetRequestChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * From asking for a laptop to signing for one.
 *
 * The chain matters because each stage is a different person answering a
 * different question — HR whether this person should have one, finance whether
 * we can afford it, procurement who we buy it from. The old model had a single
 * `approved_by`, which could only remember the last person to touch it: a
 * request approved by HR and declined by finance read as though HR had
 * declined it.
 *
 * And acceptance matters because assigning used to set the holder immediately,
 * so the register claimed somebody held equipment still sitting on a shelf. If
 * it went missing in between, it went missing from their record.
 */
class RequestChainTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private AssetRequestChain $chain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->chain = app(AssetRequestChain::class);
    }

    /**
     * A user with an employee record behind it.
     *
     * The stage columns name employees, so a bare user leaves them null — which
     * is correct behaviour and useless for testing that the right person is
     * recorded.
     */
    private function actor(string $first = 'Approver'): User
    {
        $user = User::factory()->create();
        $this->employee($first)->update(['user_id' => $user->id]);

        return $user->fresh();
    }

    private function employee(string $first = 'Amina'): Employee
    {
        return Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function request(): AssetRequest
    {
        return AssetRequest::create([
            'organization_id' => $this->org->id,
            'request_number' => 'REQ-' . fake()->unique()->numberBetween(1000, 999999),
            'employee_id' => $this->employee()->id,
            'category' => 'laptop', 'item' => 'Laptop', 'quantity' => 1,
            'reason' => 'Current machine is failing',
        ]);
    }

    private function asset(): Asset
    {
        return Asset::create([
            'organization_id' => $this->org->id,
            'name' => 'ThinkPad X1', 'category' => 'laptop',
            'asset_tag' => 'AST-' . fake()->unique()->numberBetween(1000, 999999),
        ]);
    }

    // ── The chain ─────────────────────────────────────────────────────────

    public function test_a_request_walks_the_stages_in_order(): void
    {
        $r = $this->request();
        $hr = $this->actor('HR');

        $this->assertSame(AssetRequest::STATUS_PENDING, $r->status);

        $r = $this->chain->advance($r, $hr);
        $this->assertSame(AssetRequest::STATUS_HR_REVIEW, $r->status);

        $r = $this->chain->advance($r, $hr);
        $this->assertSame(AssetRequest::STATUS_AWAITING_FINANCE, $r->status);

        $r = $this->chain->advance($r, $this->actor());
        $this->assertSame(AssetRequest::STATUS_APPROVED, $r->status);
    }

    /**
     * The whole point of the chain: who agreed to what survives.
     *
     * One `approved_by` could only remember the last person to touch it.
     */
    public function test_each_stage_remembers_who_acted(): void
    {
        $hr      = $this->actor('HR');
        $finance = $this->actor('Finance');

        $r = $this->request();
        $r = $this->chain->advance($r, $hr);        // out of pending: HR acted
        $r = $this->chain->advance($r, $hr);        // out of hr_review
        $r = $this->chain->advance($r, $finance);   // out of awaiting_finance

        $this->assertSame($hr->employeeId(), $r->hr_reviewed_by);
        $this->assertSame($finance->employeeId(), $r->finance_reviewed_by);
        $this->assertNotNull($r->approved_at);
    }

    public function test_declining_requires_a_reason_and_records_it(): void
    {
        $r = $this->chain->decline($this->request(), $this->actor(), 'Budget frozen this quarter.');

        $this->assertSame(AssetRequest::STATUS_REJECTED, $r->status);
        $this->assertSame('Budget frozen this quarter.', $r->rejection_reason);
    }

    public function test_a_blank_reason_is_refused(): void
    {
        $this->expectException(HttpException::class);
        $this->chain->decline($this->request(), $this->actor(), '   ');
    }

    /**
     * Approving something already declined is not a correction — it is two
     * people disagreeing in the record. The fix is a new request.
     */
    public function test_a_settled_request_cannot_be_advanced(): void
    {
        $r = $this->chain->decline($this->request(), $this->actor(), 'No.');

        $this->expectException(HttpException::class);
        $this->chain->advance($r, $this->actor());
    }

    /** Nothing on the shelf is a different situation from awaiting a decision. */
    public function test_forwarding_to_procurement_says_why(): void
    {
        $r = $this->chain->forwardToProcurement($this->request(), $this->actor());

        $this->assertSame(AssetRequest::STATUS_PROCUREMENT, $r->status);
        $this->assertNotNull($r->procurement_note);

        // Once bought, it rejoins at approved — whether to allow it was
        // settled before procurement was involved.
        $r = $this->chain->advance($r, $this->actor());
        $this->assertSame(AssetRequest::STATUS_APPROVED, $r->status);
    }

    // ── Fulfilment and acceptance ─────────────────────────────────────────

    /**
     * Custody starts when somebody says they have it.
     *
     * Not when an administrator types their name — the gap between those two
     * is where a laptop goes missing from somebody's record.
     */
    public function test_fulfilling_waits_for_acceptance_before_custody_starts(): void
    {
        $r     = $this->request();
        $asset = $this->asset();
        $who   = $this->employee('Brian');

        $r = $this->chain->fulfil($r, $asset, $this->actor(), $who->id);

        $asset->refresh();
        $this->assertSame(AssetRequest::STATUS_FULFILLED, $r->status);
        $this->assertSame(Asset::STATUS_PENDING_ACCEPTANCE, $asset->status);
        // Allocated, but not yet signed for.
        $this->assertSame($who->id, $asset->assigned_to);
        $this->assertNull($asset->handover_signed_at);
    }

    public function test_accepting_starts_custody_and_records_the_condition(): void
    {
        $asset = $this->asset();
        $who   = $this->employee('Brian');

        $this->chain->fulfil($this->request(), $asset, $this->actor(), $who->id);

        $accepted = $this->chain->accept($asset->fresh(), $who->id, 'Screen already has a scratch.');

        $this->assertSame(Asset::STATUS_ASSIGNED, $accepted->status);
        $this->assertNotNull($accepted->handover_signed_at);
        $this->assertSame($who->id, $accepted->accepted_by);
        $this->assertSame('Screen already has a scratch.', $accepted->acceptance_note);
    }

    /** Somebody else's signature is not a signature. */
    public function test_only_the_person_it_was_allocated_to_can_accept(): void
    {
        $asset = $this->asset();
        $who   = $this->employee('Brian');
        $other = $this->employee('Cara');

        $this->chain->fulfil($this->request(), $asset, $this->actor(), $who->id);

        $this->expectException(HttpException::class);
        $this->chain->accept($asset->fresh(), $other->id);
    }

    public function test_an_asset_already_out_cannot_be_given_to_somebody_else(): void
    {
        $asset = $this->asset();
        $asset->update(['status' => Asset::STATUS_ASSIGNED]);

        $this->expectException(HttpException::class);
        $this->chain->fulfil($this->request(), $asset, $this->actor(), $this->employee()->id);
    }
}
