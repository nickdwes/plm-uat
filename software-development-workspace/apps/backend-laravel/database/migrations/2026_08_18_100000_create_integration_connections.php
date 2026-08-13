<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the register gets told about somebody else's estate.
 *
 * ── Why this is a table and not more env vars ──
 *
 * The first sync read one tenant out of `config/services.php`, which was right
 * for proving it worked and wrong the moment there is a second. Konvergenz runs
 * four organisations; a tenant belongs to one of them, and the estate it
 * describes should land in that organisation's register rather than whichever
 * one the deployment happened to be configured for.
 *
 * Credentials in config also mean a new tenant is a deploy. Somebody in HR
 * adding the Tiberbu tenant should not need an engineer and a container
 * restart.
 *
 * ── Secrets ──
 *
 * `client_secret` is encrypted at rest with the application key and is never
 * returned by the API — the only things read back are whether one is set and
 * when it expires. A secret that can be fetched through the same screen that
 * sets it is a secret one XSS away from being copied.
 *
 * Azure Key Vault is the better home for these and the tenant already has one.
 * That is a later move; the important part now is that it is not plaintext in
 * a column somebody can select.
 *
 * ── Subscriptions ──
 *
 * A tenant has one directory and any number of Azure subscriptions, so they are
 * their own rows rather than a column. Their status diverges: a principal often
 * holds Reader on one subscription and nothing on another, and a single
 * "last synced" on the tenant would report that as working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->id();

            /*
             * Whose register this feeds. A tenant describes one organisation's
             * estate, and importing it into another would silently mix two
             * companies' equipment in one list.
             */
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);

            /*
             * Named rather than assumed. Everything here is Microsoft today,
             * and a column called `provider` costs nothing now against a
             * migration later when the first Google Workspace tenant appears.
             */
            $table->string('provider', 32)->default('microsoft');

            $table->string('tenant_id', 64);
            $table->string('client_id', 64);
            $table->text('client_secret');

            /*
             * When the secret stops working.
             *
             * Optional, and worth asking for: a client secret expires quietly
             * and the sync then stops with nothing to announce it. The register
             * goes stale, which is the exact failure this module exists to
             * prevent, and nobody finds out until somebody queries a number.
             */
            $table->date('secret_expires_on')->nullable();

            // What this connection is allowed to bring in.
            $table->boolean('sync_licences')->default(true);
            $table->boolean('sync_azure')->default(false);

            $table->boolean('is_active')->default(true);

            /*
             * The result of the last credential test, kept so the list can show
             * what is broken without re-testing every connection on every page
             * load — which would be a burst of token requests against Microsoft
             * every time somebody opens the screen.
             */
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 16)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->json('granted_roles')->nullable();

            $table->timestamp('last_synced_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // One tenant per organisation. The same directory registered twice
            // would import every licence twice and double every count.
            $table->unique(['organization_id', 'tenant_id']);
        });

        Schema::create('integration_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_connection_id')
                ->constrained('integration_connections')->cascadeOnDelete();

            $table->uuid('subscription_id');
            $table->string('display_name', 160)->nullable();
            $table->string('state', 32)->nullable();

            /*
             * Discovered subscriptions are listed but not synced until somebody
             * says so. A principal granted Reader at management-group scope can
             * see subscriptions belonging to other parts of the business, and
             * hoovering those into this register is how one company's asset
             * list quietly acquires another's servers.
             */
            $table->boolean('is_enabled')->default(false);

            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('resource_count')->nullable();
            $table->string('last_sync_message', 500)->nullable();

            $table->timestamps();

            $table->unique(['integration_connection_id', 'subscription_id'], 'integration_sub_unique');
        });

        /*
         * Managing a connection means handling credentials that can read a
         * whole directory, so it is its own permission rather than folding into
         * assets.update — somebody who maintains the asset register is not
         * automatically somebody who should hold tenant credentials.
         */
        foreach (['group_admin', 'org_hr'] as $role) {
            $row = DB::table('roles')->where('name', $role)->first();

            if (! $row) {
                continue;
            }

            $permissions = json_decode($row->permissions ?? '[]', true) ?: [];

            foreach (['integrations.view', 'integrations.manage', 'integrations.sync'] as $permission) {
                if (! in_array($permission, $permissions, true)) {
                    $permissions[] = $permission;
                }
            }

            DB::table('roles')->where('id', $row->id)->update([
                'permissions' => json_encode($permissions),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['group_admin', 'org_hr'] as $role) {
            $row = DB::table('roles')->where('name', $role)->first();

            if (! $row) {
                continue;
            }

            $permissions = array_values(array_diff(
                json_decode($row->permissions ?? '[]', true) ?: [],
                ['integrations.view', 'integrations.manage', 'integrations.sync'],
            ));

            DB::table('roles')->where('id', $row->id)->update([
                'permissions' => json_encode($permissions),
                'updated_at'  => now(),
            ]);
        }

        Schema::dropIfExists('integration_subscriptions');
        Schema::dropIfExists('integration_connections');
    }
};
