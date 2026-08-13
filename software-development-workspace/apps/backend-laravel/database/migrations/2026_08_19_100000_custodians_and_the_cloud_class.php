<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Things nobody carries, and the person answerable for them anyway.
 *
 * ── Two different relationships ──
 *
 * The register has had one idea of custody: somebody is given a laptop, signs
 * for it, and gives it back when they leave. Handover, acceptance, an exit
 * interview.
 *
 * A core switch in a rack is not that. Neither is a virtual machine, a key
 * vault or a firewall. Nobody takes them home, nobody signs for them, and
 * nothing is returned at an exit interview — but somebody is still answerable
 * for them: who to ring when it fails, who approves a change, who answers at
 * audit. Recording that as `assigned_to` reads as though the network manager
 * took a switch home, and the moment it reads wrong is the moment people stop
 * correcting it.
 *
 * So: a custodian, kept in its own column and moved by its own action.
 *
 * ── Why the category decides, not the class ──
 *
 * The obvious rule is "cloud things get a custodian, physical things get an
 * assignee", and it is wrong in both directions. Network equipment, servers and
 * UPS units are physical and nobody carries them. So the choice belongs to the
 * category, which is already configuration — a new kind of thing can say which
 * model it follows without a migration, exactly as it already says which fields
 * it asks for.
 *
 * ── A status for things in service ──
 *
 * `available` on a production virtual machine is a lie with consequences: it
 * says the thing is on a shelf and could be handed to somebody. Things that are
 * running and looked after sit at `in_service` instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_categories', function (Blueprint $table) {
            /*
             * assignee  — carried by a person, signed for, returned
             * custodian — looked after by a person, never handed over
             * none      — consumed as seats, so custody is the allocation
             */
            $table->string('custody_model', 16)->default('assignee')->after('description');
        });

        Schema::table('assets', function (Blueprint $table) {
            /*
             * Separate from `assigned_to` rather than reusing it.
             *
             * They answer different questions and have different consequences:
             * an assignee has the thing and must give it back, a custodian is
             * accountable for something that never moves. One column would make
             * every "what is this person holding" report wrong at exit.
             */
            $table->foreignId('custodian_id')->nullable()->after('assigned_date')
                ->constrained('employees')->nullOnDelete();

            $table->date('custodian_since')->nullable()->after('custodian_id');
        });

        /*
         * The things nobody carries.
         *
         * Set by name because that is what these categories are seeded as; a
         * category somebody has since renamed keeps the default and can be
         * corrected on the categories screen.
         */
        DB::table('asset_categories')
            ->where('asset_class', 'physical')
            ->whereIn(DB::raw('lower(name)'), ['network equipment', 'server', 'power equipment'])
            ->update(['custody_model' => 'custodian']);

        // Seats are the custody model for anything bought in quantity.
        DB::table('asset_categories')
            ->whereIn('asset_class', ['software', 'subscription'])
            ->update(['custody_model' => 'none']);

        /*
         * Cloud resources as their own class.
         *
         * A virtual machine is not a laptop with the hardware fields blank and
         * not a subscription with no seats. It is metered infrastructure that
         * exists until somebody deletes it, and it is asked different questions:
         * what it runs on, what depends on it, who is answerable for it.
         */
        $cloudCategories = [
            ['virtual machine',    'Compute instances — Azure VMs, EC2, droplets'],
            ['managed database',   'Hosted database services'],
            ['storage account',    'Object and file storage'],
            ['key vault',          'Secret, key and certificate stores'],
            ['app service',        'Hosted web applications and APIs'],
            ['container service',  'Kubernetes clusters, container registries and instances'],
            ['virtual network',    'Networks, subnets, gateways, firewalls and bastions'],
            ['backup vault',       'Backup and recovery services'],
            ['ai service',         'Cognitive, OpenAI and machine learning services'],
            ['monitoring',         'Alerts, action groups and log workspaces'],
            ['other',              'Anything not covered above — name it when registering'],
        ];

        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            $order = 0;

            foreach ($cloudCategories as [$name, $description]) {
                $exists = DB::table('asset_categories')
                    ->where('organization_id', $organizationId)
                    ->where('asset_class', 'cloud')
                    ->whereRaw('lower(name) = ?', [$name])
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('asset_categories')->insert([
                    'organization_id' => $organizationId,
                    'asset_class'     => 'cloud',
                    'name'            => $name,
                    'description'     => $description,
                    'custody_model'   => 'custodian',
                    'is_active'       => true,
                    'sort_order'      => $order++,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('asset_categories')->where('asset_class', 'cloud')->delete();

        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custodian_id');
            $table->dropColumn('custodian_since');
        });

        Schema::table('asset_categories', fn (Blueprint $t) => $t->dropColumn('custody_model'));
    }
};
