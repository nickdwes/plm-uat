<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * More physical asset categories — vehicles, racks, UPS units, storage boxes
 * and rooms/offices — each with its own ITIL CI-attribute fields, plus a
 * couple more on furniture.
 *
 * Purely additive and idempotent: a category or field is created only when it
 * is not already there (matched case-insensitively). Nothing is updated or
 * deleted, so anything already present — including a category somebody made by
 * hand — is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // [name => [description, custody_model, sort_order, [ [label,type,options|null,help|null], ... ]]]
        $categories = [
            'vehicle' => ['Company cars, vans and motorbikes', 'assignee', 20, [
                ['Registration number', 'text', null, null],
                ['Chassis / VIN', 'text', null, null],
                ['Engine number', 'text', null, null],
                ['Year of manufacture', 'number', null, null],
                ['Fuel type', 'select', ['Petrol', 'Diesel', 'Electric', 'Hybrid'], null],
                ['Transmission', 'select', ['Manual', 'Automatic'], null],
                ['Odometer (km)', 'number', null, null],
                ['Colour', 'text', null, null],
                ['Assigned driver', 'text', null, null],
                ['Insurance provider', 'text', null, null],
                ['Insurance expiry', 'date', null, null],
                ['Road licence expiry', 'date', null, null],
                ['Next inspection due', 'date', null, null],
            ]],
            'rack' => ['Server racks and cabinets', 'custodian', 21, [
                ['Rack size (U)', 'number', null, 'Height in rack units'],
                ['Row / grid position', 'text', null, null],
                ['Power feeds', 'number', null, null],
                ['PDU count', 'number', null, null],
                ['Cooling', 'select', ['In-row', 'CRAC', 'Ambient'], null],
                ['Weight limit (kg)', 'number', null, null],
            ]],
            'ups' => ['Uninterruptible power supplies', 'custodian', 22, [
                ['Capacity (VA)', 'number', null, null],
                ['Capacity (Watts)', 'number', null, null],
                ['Topology', 'select', ['Online', 'Line-interactive', 'Standby'], null],
                ['Battery type', 'text', null, null],
                ['Battery install date', 'date', null, null],
                ['Battery replacement due', 'date', null, null],
                ['Runtime (minutes)', 'number', null, 'Runtime at rated load'],
                ['Input voltage', 'text', null, null],
                ['Output voltage', 'text', null, null],
            ]],
            'storage box' => ['Boxes, crates and containers for stored items', 'custodian', 23, [
                ['Box number / label', 'text', null, null],
                ['Contents summary', 'textarea', null, null],
                ['Shelf / bin location', 'text', null, null],
                ['Capacity', 'text', null, null],
                ['Access restricted', 'boolean', null, null],
            ]],
            'room' => ['Rooms, offices and physical spaces', 'custodian', 24, [
                ['Room number', 'text', null, null],
                ['Floor', 'text', null, null],
                ['Building', 'text', null, null],
                ['Room type', 'select', ['Office', 'Meeting room', 'Server room', 'Store', 'Reception', 'Workshop', 'Other'], null],
                ['Seating capacity', 'number', null, null],
                ['Area (sqm)', 'number', null, null],
                ['Access control', 'select', ['Keycard', 'Key', 'Biometric', 'Open'], null],
            ]],
        ];

        // Extra fields for the existing furniture category, added additively.
        $extraFurniture = [
            ['Dimensions', 'text', null, 'W × D × H'],
            ['Quantity', 'number', null, null],
        ];

        $now = now();

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            foreach ($categories as $name => [$description, $custody, $sort, $fields]) {
                $categoryId = $this->ensureCategory($orgId, $name, $description, $custody, $sort, $now);

                $order = 0;
                foreach ($fields as [$label, $type, $options, $help]) {
                    $this->insertField($orgId, $categoryId, 'physical', ucfirst($name) . ' details', 2, $order++, $label, $type, $options, $help, $now);
                }
            }

            // Furniture already exists — just add the extra fields to it.
            $furniture = DB::table('asset_categories')
                ->where('organization_id', $orgId)->where('asset_class', 'physical')
                ->whereRaw('lower(name) = ?', ['furniture'])->value('id');

            if ($furniture) {
                foreach ($extraFurniture as $i => [$label, $type, $options, $help]) {
                    $this->insertField($orgId, $furniture, 'physical', 'Furniture details', 2, 10 + $i, $label, $type, $options, $help, $now);
                }
            }
        }
    }

    private function ensureCategory(int $orgId, string $name, string $description, string $custody, int $sort, $now): int
    {
        $existing = DB::table('asset_categories')
            ->where('organization_id', $orgId)->where('asset_class', 'physical')
            ->whereRaw('lower(name) = ?', [strtolower($name)])->value('id');

        if ($existing) {
            return $existing;
        }

        return DB::table('asset_categories')->insertGetId([
            'organization_id' => $orgId,
            'asset_class'     => 'physical',
            'name'            => $name,
            'description'     => $description,
            'custody_model'   => $custody,
            'is_active'       => true,
            'sort_order'      => $sort,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    private function insertField(int $orgId, int $categoryId, string $class, string $section, int $sectionOrder, int $sortOrder, string $label, string $type, ?array $options, ?string $help, $now): void
    {
        $key = Str::of($label)->slug('_')->limit(60, '')->toString();

        $exists = DB::table('asset_category_fields')
            ->where('organization_id', $orgId)
            ->where('asset_category_id', $categoryId)
            ->whereRaw('lower(key) = ?', [$key])->exists();

        if ($exists) {
            return;
        }

        DB::table('asset_category_fields')->insert([
            'organization_id'   => $orgId,
            'asset_category_id' => $categoryId,
            'asset_class'       => $class,
            'key'               => $key,
            'label'             => $label,
            'type'              => $type,
            'options'           => $options ? json_encode($options) : null,
            'help'              => $help,
            'is_required'       => false,
            'is_unique'         => false,
            'section'           => $section,
            'section_order'     => $sectionOrder,
            'sort_order'        => $sortOrder,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
    }

    public function down(): void
    {
        // Additive only — not reversed, so hand-entered assets keep their type.
    }
};
