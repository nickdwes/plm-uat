<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Restore the default asset categories.
 *
 * The 2026_08_14 migration seeded a full set (physical, software, subscription)
 * for every organisation, and 2026_08_19 added the cloud class. They were later
 * lost — every org was down to at most a single hand-made category — and asset
 * categories carry no soft-delete, so they cannot come back from the table.
 *
 * This re-seeds the same defaults, inserting only what is missing (matched
 * case-insensitively on class + name), so anything an org already has — a
 * renamed or hand-made category — is left untouched. Idempotent: safe to run
 * again, and it fixes any environment where the same loss happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        // [class => [ [name, description, custody_model], ... ]]
        $defaults = [
            'physical' => [
                ['laptop', 'Portable computers issued to staff', 'assignee'],
                ['desktop', 'Fixed workstations', 'assignee'],
                ['monitor', 'Screens and displays', 'assignee'],
                ['phone', 'Mobile handsets', 'assignee'],
                ['tablet', 'Tablets and e-readers', 'assignee'],
                ['peripheral', 'Keyboards, mice, docks, headsets', 'assignee'],
                ['network equipment', 'Switches, routers, firewalls, access points', 'custodian'],
                ['server', 'Physical servers and storage', 'custodian'],
                ['power equipment', 'UPS units, inverters, generators', 'custodian'],
                ['furniture', 'Desks, chairs, cabinets', 'assignee'],
                ['other', 'Anything not covered above — name it when registering', 'assignee'],
            ],
            'software' => [
                ['licence', 'Perpetual or term software licences', 'none'],
                ['design tool', 'Figma, Adobe, CAD', 'none'],
                ['developer tool', 'IDEs, repositories, build services', 'none'],
                ['security', 'Antivirus, endpoint protection, scanning', 'none'],
                ['other', 'Anything not covered above — name it when registering', 'none'],
            ],
            'subscription' => [
                ['ssl certificate', 'Certificates securing a domain or service', 'none'],
                ['domain', 'Domain registrations', 'none'],
                ['saas', 'Hosted services billed per period', 'none'],
                ['cloud service', 'Cloud subscriptions and credits', 'none'],
                ['support contract', 'Vendor support and maintenance agreements', 'none'],
                ['other', 'Anything not covered above — name it when registering', 'none'],
            ],
            'cloud' => [
                ['virtual machine', 'Compute instances — Azure VMs, EC2, droplets', 'custodian'],
                ['managed database', 'Hosted database services', 'custodian'],
                ['storage account', 'Object and file storage', 'custodian'],
                ['key vault', 'Secret, key and certificate stores', 'custodian'],
                ['app service', 'Hosted web applications and APIs', 'custodian'],
                ['container service', 'Kubernetes clusters, container registries and instances', 'custodian'],
                ['virtual network', 'Networks, subnets, gateways, firewalls and bastions', 'custodian'],
                ['backup vault', 'Backup and recovery services', 'custodian'],
                ['ai service', 'Cognitive, OpenAI and machine learning services', 'custodian'],
                ['monitoring', 'Alerts, action groups and log workspaces', 'custodian'],
                ['other', 'Anything not covered above — name it when registering', 'custodian'],
            ],
        ];

        $now = now();

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            foreach ($defaults as $class => $items) {
                $order = 0;
                foreach ($items as [$name, $description, $custody]) {
                    $sortOrder = $name === 'other' ? 99 : $order++;

                    $exists = DB::table('asset_categories')
                        ->where('organization_id', $orgId)
                        ->where('asset_class', $class)
                        ->whereRaw('lower(name) = ?', [$name])
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('asset_categories')->insert([
                        'organization_id' => $orgId,
                        'asset_class'     => $class,
                        'name'            => $name,
                        'description'     => $description,
                        'custody_model'   => $custody,
                        'is_active'       => true,
                        'sort_order'      => $sortOrder,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // A restore is not reversed — dropping them would repeat the loss.
    }
};
