<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asset categories, configured rather than compiled in.
 *
 * The category list lived in the frontend as a hardcoded array. Adding "UPS" or
 * "network switch" meant a developer, a build and a deploy — for a dropdown.
 * Meanwhile `assets.category` is free text, so anything typed elsewhere in the
 * system already went straight in, and the register slowly accumulated
 * "Laptop", "laptop" and "Lap top" as three different things to report on.
 *
 * Per organisation, because the four tenants own different kinds of things: a
 * training company and a healthcare integrator do not want the same list.
 *
 * Tied to a class, because a category only means something within one. "SSL
 * certificate" is not a kind of laptop, and offering it when somebody registers
 * a monitor is how the register gets filled in wrongly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // physical | software | subscription
            $table->string('asset_class', 24);
            $table->string('name', 120);

            /*
             * What belongs in this category, for whoever is choosing.
             *
             * The difference between "peripheral" and "other" is obvious to the
             * person who wrote the list and to nobody else.
             */
            $table->string('description', 255)->nullable();

            /*
             * Retired rather than deleted.
             *
             * A category with assets filed under it cannot simply vanish —
             * every one of those rows would be left pointing at a name that no
             * longer means anything. Switching it off removes it from the
             * picker and leaves history readable.
             */
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);

            $table->timestamps();

            // Same name twice in one class is a data-entry accident, not a
            // choice. Allowed across classes: "other" belongs in each.
            $table->unique(['organization_id', 'asset_class', 'name']);
            $table->index(['organization_id', 'asset_class', 'is_active']);
        });

        /*
         * Seeded with the lists that were hardcoded, for every organisation
         * that exists.
         *
         * Without this the picker is empty on the day this ships and every
         * tenant has to type its own list before anybody can register
         * anything — a migration that makes the product worse until somebody
         * does homework.
         */
        $defaults = [
            'physical' => [
                ['laptop', 'Portable computers issued to staff'],
                ['desktop', 'Fixed workstations'],
                ['monitor', 'Screens and displays'],
                ['phone', 'Mobile handsets'],
                ['tablet', 'Tablets and e-readers'],
                ['peripheral', 'Keyboards, mice, docks, headsets'],
                ['network equipment', 'Switches, routers, firewalls, access points'],
                ['server', 'Physical servers and storage'],
                ['power equipment', 'UPS units, inverters, generators'],
                ['furniture', 'Desks, chairs, cabinets'],
                ['other', 'Anything not covered above — name it when registering'],
            ],
            'software' => [
                ['licence', 'Perpetual or term software licences'],
                ['design tool', 'Figma, Adobe, CAD'],
                ['developer tool', 'IDEs, repositories, build services'],
                ['security', 'Antivirus, endpoint protection, scanning'],
                ['other', 'Anything not covered above — name it when registering'],
            ],
            'subscription' => [
                ['ssl certificate', 'Certificates securing a domain or service'],
                ['domain', 'Domain registrations'],
                ['saas', 'Hosted services billed per period'],
                ['cloud service', 'Cloud subscriptions and credits'],
                ['support contract', 'Vendor support and maintenance agreements'],
                ['other', 'Anything not covered above — name it when registering'],
            ],
        ];

        $rows = [];
        $now  = now();

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            foreach ($defaults as $class => $items) {
                foreach ($items as $i => [$name, $description]) {
                    $rows[] = [
                        'organization_id' => $orgId,
                        'asset_class'     => $class,
                        'name'            => $name,
                        'description'     => $description,
                        'is_active'       => true,
                        // "other" last, wherever it appears.
                        'sort_order'      => $name === 'other' ? 99 : $i,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('asset_categories')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_categories');
    }
};
