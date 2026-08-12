<?php

namespace Tests\Feature\Asset;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetEvent;
use App\Models\Employee;
use App\Models\Organization;
use App\Services\Asset\AssetLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where an asset has been, not just where it is.
 *
 * The register could say a laptop was assigned. It could not say who had it in
 * March, how long it sat waiting for a repair, or how many people it passed
 * through before being scrapped — and the designs draw a timeline of exactly
 * that.
 *
 * These tests are mostly about the register and the history agreeing. They were
 * two separate columns set in a dozen places, and two facts maintained by hand
 * are two facts that eventually disagree.
 */
class AssetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private AssetLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->lifecycle = app(AssetLifecycle::class);
    }

    private function employee(string $first = 'James'): Employee
    {
        return Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => 'Kinyanjui',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function asset(array $attrs = []): Asset
    {
        return Asset::create($attrs + [
            'organization_id' => $this->org->id,
            'name' => 'MacBook Pro',
            'category' => 'Laptop',
            'asset_tag' => 'AST-' . fake()->unique()->numberBetween(1000, 999999),
        ]);
    }

    // ── The timeline ──────────────────────────────────────────────────────

    public function test_the_timeline_reads_forwards(): void
    {
        $asset = $this->asset();
        $james = $this->employee();

        $this->lifecycle->record($asset, AssetEvent::PROCURED, ['occurred_on' => '2023-02-28']);
        $this->lifecycle->record($asset, AssetEvent::IN_STOCK, ['occurred_on' => '2024-02-29']);
        $this->lifecycle->assign($asset->fresh(), $james->id, ['occurred_on' => '2024-03-12']);

        $this->assertSame(
            ['procured', 'in_stock', 'assigned'],
            $asset->fresh()->events->pluck('event')->all(),
        );
    }

    /**
     * Handing something from one person to another is its own event.
     *
     * Two identical "assigned" markers would hide the moment worth looking at —
     * a handover where nobody signed and the condition was never checked.
     */
    public function test_passing_it_on_is_a_reassignment(): void
    {
        $asset = $this->asset();
        $james = $this->employee('James');
        $luke  = $this->employee('Luke');

        $this->lifecycle->assign($asset, $james->id);
        $this->lifecycle->assign($asset->fresh(), $luke->id);

        $this->assertSame(
            ['assigned', 'reassigned'],
            $asset->fresh()->events->pluck('event')->all(),
        );
        $this->assertSame($luke->id, $asset->fresh()->assigned_to);
    }

    /** Assigning to the same person twice is not a handover. */
    public function test_reassigning_to_the_same_person_is_not_a_handover(): void
    {
        $asset = $this->asset();
        $james = $this->employee();

        $this->lifecycle->assign($asset, $james->id);
        $this->lifecycle->assign($asset->fresh(), $james->id);

        $this->assertSame(
            ['assigned', 'assigned'],
            $asset->fresh()->events->pluck('event')->all(),
        );
    }

    // ── The register follows the history ──────────────────────────────────

    public function test_recording_a_repair_takes_it_off_the_available_list(): void
    {
        $asset = $this->asset();

        $this->lifecycle->record($asset, AssetEvent::UNDER_REPAIR);

        $this->assertSame(Asset::STATUS_MAINTENANCE, $asset->fresh()->status);
    }

    /**
     * A retired asset that still shows an owner is how somebody gets chased at
     * their exit interview for a machine scrapped last year.
     */
    public function test_retiring_an_asset_ends_custody(): void
    {
        $asset = $this->asset();
        $james = $this->employee();

        $this->lifecycle->assign($asset, $james->id);
        $this->assertSame($james->id, $asset->fresh()->assigned_to);

        $this->lifecycle->record($asset->fresh(), AssetEvent::RETIRED);

        $fresh = $asset->fresh();
        $this->assertNull($fresh->assigned_to);
        $this->assertSame(Asset::STATUS_RETIRED, $fresh->status);
        // The history still knows who had it.
        $this->assertSame($james->id, $fresh->events->firstWhere('event', 'assigned')->employee_id);
    }

    public function test_returning_it_frees_it_without_losing_who_had_it(): void
    {
        $asset = $this->asset();
        $james = $this->employee();

        $this->lifecycle->assign($asset, $james->id);
        $this->lifecycle->release($asset->fresh());

        $fresh = $asset->fresh();
        $this->assertNull($fresh->assigned_to);
        $this->assertSame(Asset::STATUS_AVAILABLE, $fresh->status);
        $this->assertSame($james->id, $fresh->events->last()->employee_id);
    }

    /** A mended laptop does not put itself back on somebody's desk. */
    public function test_a_repair_completing_does_not_guess_who_gets_it(): void
    {
        $asset = $this->asset();

        $this->lifecycle->record($asset, AssetEvent::UNDER_REPAIR);
        $this->lifecycle->record($asset->fresh(), AssetEvent::REPAIRED);

        $this->assertNull($asset->fresh()->assigned_to);
    }

    public function test_an_unknown_event_is_refused(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->lifecycle->record($this->asset(), 'incinerated');
    }

    // ── Subscriptions and licences ────────────────────────────────────────

    /** Seats are a question about a subscription, not about a laptop. */
    public function test_spare_seats_are_only_asked_of_seated_classes(): void
    {
        $licence = $this->asset([
            'asset_class' => Asset::CLASS_SUBSCRIPTION,
            'name' => 'Figma', 'seats_total' => 25,
        ]);

        // Seats in use are people now, not a number — so eighteen of them.
        foreach (range(1, 18) as $i) {
            $licence->allocateSeat($this->employee("Person{$i}")->id);
        }

        $laptop = $this->asset();

        $this->assertSame(7, $licence->fresh()->seatsSpare());
        // Not nought — a laptop has no seats to spare, which is a different
        // statement from a fully-subscribed licence.
        $this->assertNull($laptop->seatsSpare());
    }

    public function test_expiry_is_counted_from_today_and_goes_negative(): void
    {
        $soon = $this->asset(['expires_on' => now()->addDays(9)->toDateString()]);
        $gone = $this->asset(['expires_on' => now()->subDays(4)->toDateString()]);

        $this->assertSame(9, $soon->daysToExpiry());
        $this->assertSame(-4, $gone->daysToExpiry());
        $this->assertNull($this->asset()->daysToExpiry());
    }

    /**
     * How much warning something needs is a property of the thing. A domain
     * wants a fortnight; an enterprise agreement wants a quarter.
     */
    public function test_renewal_notice_is_per_asset_with_a_sane_default(): void
    {
        // Default 30 days: due in 20, so it should surface.
        $this->asset(['name' => 'Default notice', 'expires_on' => now()->addDays(20)->toDateString()]);

        // Wants 90 days' warning, due in 60 — surfaces, though a 30-day
        // default would have missed it.
        $this->asset([
            'name' => 'Enterprise agreement',
            'expires_on' => now()->addDays(60)->toDateString(),
            'renewal_notice_days' => 90,
        ]);

        // Due in 200 days, wants 14. Not yet.
        $this->asset([
            'name' => 'Domain',
            'expires_on' => now()->addDays(200)->toDateString(),
            'renewal_notice_days' => 14,
        ]);

        $names = Asset::expiringSoon()->pluck('name')->sort()->values()->all();

        $this->assertSame(['Default notice', 'Enterprise agreement'], $names);
    }

    /** Nothing retired is coming up for renewal. */
    public function test_retired_assets_are_not_chased_for_renewal(): void
    {
        $this->asset([
            'expires_on' => now()->addDays(5)->toDateString(),
            'status' => Asset::STATUS_RETIRED,
        ]);

        $this->assertSame(0, Asset::expiringSoon()->count());
    }
}
