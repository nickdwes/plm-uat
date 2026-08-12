<?php

namespace Tests\Feature\Asset;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetCategory;
use App\Models\Organization;
use App\Services\Asset\AssetService;
use App\Services\Asset\CustomFieldValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Fields defined per category, instead of one form for everything.
 *
 * The register has columns for a laptop and nothing for a UPS, which wants a
 * capacity in VA and a battery replacement date. Both used to end up in the
 * notes field, where nothing can be reported on or expire.
 *
 * The rules are data, so they are built at request time. These tests are mostly
 * about the answers being checked against the questions actually asked — a
 * custom-field column that accepts anything is a second notes field with extra
 * steps.
 */
class CategoryFieldTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private AssetCategory $ups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->ups = AssetCategory::create([
            'organization_id' => $this->org->id,
            'asset_class' => 'physical', 'name' => 'ups',
        ]);

        $this->ups->fields()->createMany([
            [
                'organization_id' => $this->org->id, 'key' => 'capacity_va',
                'label' => 'Capacity (VA)', 'type' => 'number', 'is_required' => true,
            ],
            [
                'organization_id' => $this->org->id, 'key' => 'battery_replaced_on',
                'label' => 'Battery replaced on', 'type' => 'date',
            ],
            [
                'organization_id' => $this->org->id, 'key' => 'form_factor',
                'label' => 'Form factor', 'type' => 'select',
                'options' => ['tower', 'rack'],
            ],
        ]);
    }

    private function check(array $answers): array
    {
        return app(CustomFieldValidator::class)->check('ups', 'physical', $answers);
    }

    public function test_answers_are_kept_when_they_match_the_definitions(): void
    {
        $kept = $this->check([
            'capacity_va' => 3000,
            'battery_replaced_on' => '2026-01-15',
            'form_factor' => 'rack',
        ]);

        $this->assertSame(3000, $kept['capacity_va']);
        $this->assertSame('rack', $kept['form_factor']);
    }

    public function test_a_required_field_is_required(): void
    {
        $this->expectException(ValidationException::class);
        $this->check(['form_factor' => 'tower']);
    }

    public function test_a_list_field_refuses_a_value_not_on_the_list(): void
    {
        $this->expectException(ValidationException::class);
        $this->check(['capacity_va' => 3000, 'form_factor' => 'wall-mounted']);
    }

    public function test_a_number_field_refuses_prose(): void
    {
        $this->expectException(ValidationException::class);
        $this->check(['capacity_va' => 'about three thousand']);
    }

    /**
     * Anything not asked for is dropped rather than stored.
     *
     * Keeping arbitrary keys would let the column quietly become the notes
     * field it was built to replace.
     */
    public function test_keys_the_category_does_not_define_are_discarded(): void
    {
        $kept = $this->check(['capacity_va' => 3000, 'colour' => 'black']);

        $this->assertArrayHasKey('capacity_va', $kept);
        $this->assertArrayNotHasKey('colour', $kept);
    }

    /** A category nobody configured asks nothing, so it keeps nothing. */
    public function test_an_unconfigured_category_captures_nothing(): void
    {
        $this->assertSame(
            [],
            app(CustomFieldValidator::class)->check('something nobody set up', 'physical', ['x' => 1]),
        );
    }

    /**
     * An edit that touches one field must not wipe the others.
     *
     * A form rendering only the current category's questions would otherwise
     * silently drop every answer it did not display.
     */
    public function test_editing_merges_rather_than_replaces(): void
    {
        $service = app(AssetService::class);

        $asset = $service->create($this->org->id, [
            'name' => 'APC Smart-UPS', 'category' => 'ups', 'asset_tag' => 'AST-UPS-1',
            'custom_fields' => ['capacity_va' => 3000, 'form_factor' => 'rack'],
        ]);

        $service->update($asset, ['custom_fields' => ['battery_replaced_on' => '2026-02-01']]);

        $fresh = $asset->fresh()->custom_fields;

        $this->assertSame('2026-02-01', $fresh['battery_replaced_on']);
        // Still there.
        $this->assertSame(3000, $fresh['capacity_va']);
        $this->assertSame('rack', $fresh['form_factor']);
    }

    /** The key is generated once and is not the label. */
    public function test_keys_are_derived_from_the_label(): void
    {
        $this->assertSame(
            'battery_replaced_on',
            \App\Models\Asset\AssetCategoryField::keyFor('Battery replaced on'),
        );
    }
}
