<?php

namespace Tests\Feature\Asset;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetCategory;
use App\Models\Asset\AssetEvent;
use App\Models\Employee;
use App\Models\Organization;
use App\Services\Asset\AssetLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Somebody answerable for a thing nobody carries.
 *
 * The register had one idea of custody: a laptop is given to a person, signed
 * for, and handed back when they leave. A core switch, a firewall and a
 * production virtual machine are none of those things, and recording them as
 * assigned puts racked equipment on a leaver's return checklist.
 */
class AssetCustodianTest extends TestCase
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

    private function employee(string $first = 'Grace'): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => 'Njoroge',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function category(string $name, string $class, string $model): AssetCategory
    {
        return AssetCategory::create([
            'organization_id' => $this->org->id,
            'asset_class'     => $class,
            'name'            => $name,
            'custody_model'   => $model,
        ]);
    }

    private function asset(AssetCategory $category, array $attrs = []): Asset
    {
        return Asset::create(array_merge([
            'organization_id'   => $this->org->id,
            'asset_class'       => $category->asset_class,
            'asset_category_id' => $category->id,
            'category'          => $category->name,
            'name'              => 'Thing',
            'asset_tag'         => 'AST-' . fake()->unique()->numberBetween(1, 99999),
        ], $attrs));
    }

    // ── Which model applies ───────────────────────────────────────────────────

    /**
     * The category decides, not the class.
     *
     * "Cloud gets a custodian, physical gets an assignee" is wrong in both
     * directions: network equipment and servers are physical and nobody carries
     * them either.
     */
    public function test_network_equipment_is_looked_after_not_carried(): void
    {
        $switch = $this->asset($this->category('network equipment', Asset::CLASS_PHYSICAL, 'custodian'));
        $laptop = $this->asset($this->category('laptop', Asset::CLASS_PHYSICAL, 'assignee'));

        $this->assertTrue($switch->usesCustodian());
        $this->assertFalse($laptop->usesCustodian());
    }

    public function test_cloud_resources_are_looked_after(): void
    {
        $vm = $this->asset($this->category('virtual machine', Asset::CLASS_CLOUD, 'custodian'));

        $this->assertTrue($vm->usesCustodian());
    }

    /**
     * Anything filed under cloud is infrastructure even when it is not linked
     * to a configured category — imported resources arrive with a free-text
     * category and no link until somebody files them.
     */
    public function test_an_unlinked_cloud_asset_still_uses_a_custodian(): void
    {
        $vm = Asset::create([
            'organization_id' => $this->org->id,
            'asset_class'     => Asset::CLASS_CLOUD,
            'category'        => 'virtual machine',
            'name'            => 'orphan-vm',
            'asset_tag'       => 'AST-CLOUD-1',
        ]);

        $this->assertTrue($vm->usesCustodian());
    }

    // ── Recording it ──────────────────────────────────────────────────────────

    public function test_setting_a_custodian_records_who_and_when(): void
    {
        $switch = $this->asset($this->category('network equipment', Asset::CLASS_PHYSICAL, 'custodian'));
        $grace  = $this->employee();

        $event = $this->lifecycle->setCustodian($switch, $grace->id);

        $switch->refresh();
        $this->assertSame($grace->id, $switch->custodian_id);
        $this->assertSame(now()->toDateString(), $switch->custodian_since->toDateString());
        $this->assertSame(AssetEvent::CUSTODIAN_SET, $event->event);
    }

    /**
     * In service, not available. `available` on a running switch says it could
     * be handed to whoever asks next.
     */
    public function test_a_custodian_puts_the_asset_in_service(): void
    {
        $vm = $this->asset($this->category('virtual machine', Asset::CLASS_CLOUD, 'custodian'));
        $this->assertSame(Asset::STATUS_AVAILABLE, $vm->status);

        $this->lifecycle->setCustodian($vm, $this->employee()->id);

        $this->assertSame(Asset::STATUS_IN_SERVICE, $vm->fresh()->status);
    }

    /** Handing over responsibility is a different event from taking it on. */
    public function test_a_change_of_custodian_is_recorded_as_a_change(): void
    {
        $switch = $this->asset($this->category('network equipment', Asset::CLASS_PHYSICAL, 'custodian'));
        $grace  = $this->employee('Grace');
        $peter  = $this->employee('Peter');

        $this->lifecycle->setCustodian($switch, $grace->id);
        $event = $this->lifecycle->setCustodian($switch->fresh(), $peter->id);

        $this->assertSame(AssetEvent::CUSTODIAN_CHANGED, $event->event);
        $this->assertSame($peter->id, $event->employee_id);
        $this->assertSame($peter->id, $switch->fresh()->custodian_id);

        // Who let go of it is named, because an event row carrying only the
        // incoming person reads as though nobody had it before.
        $this->assertStringContainsString('Grace Njoroge', $event->note);
    }

    /**
     * The whole point of keeping the two apart: a custodian has nothing to hand
     * back, so a switch must never appear beside a laptop on a leaver's list.
     */
    public function test_a_custodian_is_not_a_holder(): void
    {
        $switch = $this->asset($this->category('network equipment', Asset::CLASS_PHYSICAL, 'custodian'));
        $grace  = $this->employee();

        $this->lifecycle->setCustodian($switch, $grace->id);

        $switch->refresh();
        $this->assertSame($grace->id, $switch->custodian_id);
        $this->assertNull($switch->assigned_to);
        $this->assertFalse($switch->isAssigned());
    }

    /** A laptop with a custodian and no assignee is one nobody can be asked to return. */
    public function test_a_carried_asset_refuses_a_custodian(): void
    {
        $laptop = $this->asset($this->category('laptop', Asset::CLASS_PHYSICAL, 'assignee'));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->lifecycle->setCustodian($laptop, $this->employee()->id);
    }

    // ── Losing one ────────────────────────────────────────────────────────────

    /**
     * Infrastructure with nobody answerable for it is still running.
     *
     * Marking it available would offer a production database to whoever asked
     * next; taking it out of service because somebody left would be worse.
     */
    public function test_clearing_a_custodian_leaves_the_asset_in_service(): void
    {
        $vm = $this->asset($this->category('virtual machine', Asset::CLASS_CLOUD, 'custodian'));
        $this->lifecycle->setCustodian($vm, $this->employee()->id);

        $event = $this->lifecycle->clearCustodian($vm->fresh());

        $vm->refresh();
        $this->assertNull($vm->custodian_id);
        $this->assertNull($vm->custodian_since);
        $this->assertSame(Asset::STATUS_IN_SERVICE, $vm->status);
        $this->assertSame(AssetEvent::CUSTODIAN_CLEARED, $event->event);
    }

    public function test_clearing_nothing_is_refused(): void
    {
        $vm = $this->asset($this->category('virtual machine', Asset::CLASS_CLOUD, 'custodian'));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->lifecycle->clearCustodian($vm);
    }

    /** The history survives the person leaving the role. */
    public function test_the_timeline_keeps_every_custodian(): void
    {
        $switch = $this->asset($this->category('network equipment', Asset::CLASS_PHYSICAL, 'custodian'));
        $grace  = $this->employee('Grace');
        $peter  = $this->employee('Peter');

        $this->lifecycle->setCustodian($switch, $grace->id);
        $this->lifecycle->setCustodian($switch->fresh(), $peter->id);
        $this->lifecycle->clearCustodian($switch->fresh());

        $this->assertSame(
            [AssetEvent::CUSTODIAN_SET, AssetEvent::CUSTODIAN_CHANGED, AssetEvent::CUSTODIAN_CLEARED],
            $switch->events()->pluck('event')->all(),
        );
    }
}
