<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recreate the per-category dynamic fields, to ITIL CI-attribute standards.
 *
 * When the asset categories were lost, their fields went with them
 * (asset_category_fields was left empty). This restores a sensible default set
 * modelled on ITIL Configuration Item attributes:
 *
 *   • class-level fields (asset_category_id NULL) — the identification,
 *     financial, lifecycle and ownership attributes every CI of a class shares;
 *   • category-level fields — the technical attributes specific to the type
 *     (a laptop's CPU, a certificate's CA, a VM's region).
 *
 * Purely additive and idempotent: a field is inserted only when the same key
 * does not already exist for that category (or class). Nothing is ever updated
 * or deleted — a field somebody has since added or edited is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Class-level fields — one shared section per class ────────────────
        // [class => [section, [ [label, type, options|null, help|null], ... ]]]
        $classLevel = [
            'physical' => ['Lifecycle & finance', [
                ['Status', 'select', ['In stock', 'In use', 'In repair', 'Retired', 'Disposed'], 'Where this asset is in its lifecycle'],
                ['Condition', 'select', ['New', 'Good', 'Fair', 'Poor'], null],
                ['Location', 'text', null, 'Site, floor or room it lives in'],
                ['Purchase date', 'date', null, null],
                ['Purchase cost', 'number', null, null],
                ['Warranty expiry', 'date', null, null],
                ['Warranty provider', 'text', null, null],
            ]],
            'software' => ['Licensing', [
                ['Publisher', 'text', null, null],
                ['Licence type', 'select', ['Perpetual', 'Subscription', 'Volume', 'OEM', 'Open source'], null],
                ['Licence key', 'text', null, null],
                ['Seats', 'number', null, 'Number of seats or installs covered'],
                ['Version', 'text', null, 'Version or edition'],
                ['Purchase date', 'date', null, null],
                ['Support expiry', 'date', null, null],
                ['Cost', 'number', null, null],
            ]],
            'subscription' => ['Renewal', [
                ['Provider', 'text', null, null],
                ['Account reference', 'text', null, 'Account, customer or order number'],
                ['Start date', 'date', null, null],
                ['Renewal date', 'date', null, null],
                ['Billing cycle', 'select', ['Monthly', 'Quarterly', 'Annual', 'Biennial'], null],
                ['Auto renew', 'boolean', null, null],
                ['Cost per period', 'number', null, null],
            ]],
            'cloud' => ['Cloud', [
                ['Cloud provider', 'select', ['Azure', 'AWS', 'Google Cloud', 'Other'], null],
                ['Account subscription', 'text', null, 'Subscription, project or account id'],
                ['Region', 'text', null, null],
                ['Resource ID', 'text', null, 'The provider resource id / ARN'],
                ['Environment', 'select', ['Production', 'Staging', 'Development', 'Test'], null],
                ['Monthly cost', 'number', null, null],
                ['Answerable owner', 'text', null, 'Who is accountable for this resource'],
            ]],
        ];

        // ── Category-level fields, keyed by category name (case-insensitive) ─
        // [name => [ [label, type, options|null, help|null], ... ]]
        $networked = [
            ['Hostname', 'text', null, null],
            ['CPU', 'text', null, null],
            ['RAM (GB)', 'number', null, null],
            ['Storage', 'text', null, null],
            ['Operating system', 'text', null, null],
            ['MAC address', 'text', null, null],
            ['IP address', 'text', null, null],
        ];

        $categoryLevel = [
            'laptop'  => $networked,
            'desktop' => $networked,
            'server'  => array_merge($networked, [
                ['Management IP', 'text', null, 'Out-of-band / iLO / iDRAC address'],
                ['Rack location', 'text', null, null],
            ]),
            'monitor' => [
                ['Screen size (inches)', 'number', null, null],
                ['Resolution', 'text', null, null],
                ['Panel type', 'select', ['IPS', 'VA', 'TN', 'OLED'], null],
            ],
            'phone' => [
                ['IMEI', 'text', null, null],
                ['OS version', 'text', null, null],
                ['Phone number', 'text', null, null],
                ['Storage', 'text', null, null],
            ],
            'tablet' => [
                ['IMEI', 'text', null, null],
                ['OS version', 'text', null, null],
                ['Storage', 'text', null, null],
            ],
            'peripheral' => [
                ['Connection type', 'select', ['USB', 'Bluetooth', 'Wireless', 'Wired'], null],
            ],
            'network equipment' => [
                ['Management IP', 'text', null, null],
                ['Firmware version', 'text', null, null],
                ['Port count', 'number', null, null],
                ['Rack location', 'text', null, null],
                ['MAC address', 'text', null, null],
            ],
            'power equipment' => [
                ['Capacity (VA)', 'number', null, null],
                ['Battery install date', 'date', null, null],
            ],
            'furniture' => [
                ['Material', 'text', null, null],
                ['Colour', 'text', null, null],
            ],
            'ssl certificate' => [
                ['Common name', 'text', null, 'The domain the certificate secures'],
                ['Certificate authority', 'text', null, null],
                ['Issued on', 'date', null, null],
                ['Expiry date', 'date', null, null],
                ['Key algorithm', 'text', null, null],
            ],
            'domain' => [
                ['Registrar', 'text', null, null],
                ['Expiry date', 'date', null, null],
                ['Nameservers', 'textarea', null, null],
            ],
            'saas' => [
                ['Tenant ID', 'text', null, null],
                ['Plan', 'text', null, null],
                ['Admin URL', 'text', null, null],
            ],
            'support contract' => [
                ['Coverage level', 'text', null, null],
                ['Support portal', 'text', null, null],
                ['Contract number', 'text', null, null],
            ],
            'virtual machine' => [
                ['vCPUs', 'number', null, null],
                ['RAM (GB)', 'number', null, null],
                ['Operating system', 'text', null, null],
                ['Public IP', 'text', null, null],
                ['Private IP', 'text', null, null],
            ],
            'managed database' => [
                ['Engine', 'text', null, 'e.g. PostgreSQL, SQL Server, MySQL'],
                ['Engine version', 'text', null, null],
                ['Tier / size', 'text', null, null],
            ],
            'storage account' => [
                ['Storage tier', 'text', null, null],
                ['Capacity (GB)', 'number', null, null],
            ],
        ];

        $now = now();

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            // Class-level.
            foreach ($classLevel as $class => [$section, $fields]) {
                $order = 0;
                foreach ($fields as [$label, $type, $options, $help]) {
                    $this->insertMissing($orgId, null, $class, $section, 1, $order++, $label, $type, $options, $help, $now);
                }
            }

            // Category-level — matched to each org's categories by name.
            $categories = DB::table('asset_categories')->where('organization_id', $orgId)->get(['id', 'asset_class', 'name']);

            foreach ($categories as $cat) {
                $defs = $categoryLevel[strtolower($cat->name)] ?? null;
                if (! $defs) {
                    continue;
                }
                $order = 0;
                foreach ($defs as [$label, $type, $options, $help]) {
                    $this->insertMissing($orgId, $cat->id, $cat->asset_class, ucfirst($cat->name) . ' details', 2, $order++, $label, $type, $options, $help, $now);
                }
            }
        }
    }

    private function insertMissing(int $orgId, ?int $categoryId, string $class, string $section, int $sectionOrder, int $sortOrder, string $label, string $type, ?array $options, ?string $help, $now): void
    {
        $key = Str::of($label)->slug('_')->limit(60, '')->toString();

        $q = DB::table('asset_category_fields')->where('organization_id', $orgId)->whereRaw('lower(key) = ?', [$key]);
        $categoryId === null
            ? $q->whereNull('asset_category_id')->where('asset_class', $class)
            : $q->where('asset_category_id', $categoryId);

        if ($q->exists()) {
            return; // never overwrite what is already there
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
        // A restore is not reversed — dropping these would repeat the loss.
    }
};
