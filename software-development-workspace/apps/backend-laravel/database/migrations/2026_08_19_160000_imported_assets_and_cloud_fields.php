<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assets that came from somewhere else, and what cloud resources are asked.
 *
 * ── Knowing what we imported ──
 *
 * An import needs a key that survives a rename, or the second run creates a
 * second copy of everything. Azure issues one: the ARM resource id, which is
 * globally unique and stable across renames and moves. It is kept alongside the
 * source that produced it, because "imported" is not a property of an asset —
 * "imported from this Azure subscription" is.
 *
 * Assets typed in by hand keep both columns null and are never touched by a
 * sync. That matters: somebody who corrects a name in the register should not
 * have it overwritten the next night, and somebody who registers a laptop
 * should not find it deleted because Azure has never heard of it.
 *
 * ── What a cloud resource is asked ──
 *
 * Class-level fields, so every cloud category inherits them rather than the
 * same five being configured against eleven categories and then disagreeing.
 * Resource group, subscription and SKU come from ARM; environment and owner
 * come from tags where the tags exist, which on this tenant they mostly do not
 * — and the import says so rather than quietly leaving them blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            /*
             * The identifier the source system issues. For Azure this is the
             * full ARM resource id — unique across every subscription, and
             * unchanged by renaming the resource.
             */
            $table->string('external_ref', 400)->nullable()->after('asset_tag');
            $table->string('external_source', 32)->nullable()->after('external_ref');
            $table->timestamp('external_synced_at')->nullable()->after('external_source');
        });

        /*
         * Unique only where there is one, so the thousands of hand-registered
         * assets with null refs do not collide — Postgres treats nulls as
         * distinct, but a partial index says the intent out loud and stays
         * correct if that ever changes.
         */
        DB::statement('
            CREATE UNIQUE INDEX assets_external_ref_unique
                ON assets (organization_id, external_source, external_ref)
             WHERE external_ref IS NOT NULL
        ');

        /*
         * What every cloud resource is asked, whatever kind it is.
         *
         * Hung off the class rather than each category for the reason the
         * software fields were: the same five fields configured against eleven
         * categories is eleven places to change when one is wrong.
         */
        $fields = [
            ['resource_group', 'Resource group', 'text', 'Where it lives in the subscription', 1],
            ['subscription',   'Subscription',   'text', 'Which subscription pays for it', 2],
            ['sku',            'Size or tier',   'text', 'VM size, storage tier, service plan', 3],
            ['environment',    'Environment',    'text', 'Production, staging, development — from the Environment tag', 4],
            ['owner',          'Owner (tag)',    'text', 'From the Owner tag in Azure, where one is set', 5],
        ];

        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            foreach ($fields as [$key, $label, $type, $help, $order]) {
                $exists = DB::table('asset_category_fields')
                    ->where('organization_id', $organizationId)
                    ->whereNull('asset_category_id')
                    ->where('asset_class', 'cloud')
                    ->where('key', $key)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('asset_category_fields')->insert([
                    'organization_id'   => $organizationId,
                    'asset_category_id' => null,
                    'asset_class'       => 'cloud',
                    'key'               => $key,
                    'label'             => $label,
                    'type'              => $type,
                    'help'              => $help,
                    'section'           => 'Where it runs',
                    'section_order'     => 1,
                    'sort_order'        => $order,
                    'is_required'       => false,
                    'is_unique'         => false,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('asset_category_fields')->where('asset_class', 'cloud')->whereNull('asset_category_id')->delete();

        DB::statement('DROP INDEX IF EXISTS assets_external_ref_unique');

        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn([
            'external_ref', 'external_source', 'external_synced_at',
        ]));
    }
};
