<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding a supplier stops being one person clicking Approve.
 *
 * Today `approve`, `reject` and `suspend` are all guarded by the same
 * permission as editing a name — anyone who can type a vendor in can bless
 * it — there is no chain, no separation of duties, and `approve` works from
 * `draft`, so the review state can be skipped entirely. Meanwhile the bank
 * details added in October can be changed by that same person with nobody
 * else looking, which is the single highest-value fraud in a purchase-to-pay
 * system: redirect the account number on a supplier everyone already trusts.
 *
 * This adds:
 *  - `vendors.approve`, back-filled to whoever holds `vendors.manage` today
 *    so nothing regresses on deploy;
 *  - a configurable approval chain in the house shape
 *    (`procurement_approval_stages` / `procurement_approvals`), seeded for
 *    every organisation so no tenant finds an empty chain;
 *  - `submitted_by`, so the chain can refuse to let the submitter sign their
 *    own work;
 *  - `pending_change`, where an edit to banking or legal identity waits for a
 *    second pair of eyes instead of taking effect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_partners', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('approved_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');

            /*
             * An edit to bank details or legal identity, waiting for somebody
             * other than its author to confirm it. Held as JSON rather than a
             * shadow table because it is at most one pending change at a time
             * and its shape follows the columns it proposes.
             */
            $table->json('pending_change')->nullable()->after('rejection_reason');
            $table->foreignId('pending_change_by')->nullable()->after('pending_change')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('pending_change_at')->nullable()->after('pending_change_by');
        });

        Schema::create('vendor_approval_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 40);
            $table->unsignedSmallInteger('sequence');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'stage']);
        });

        Schema::create('vendor_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_partner_id')->constrained('vendor_partners')->cascadeOnDelete();
            $table->string('stage', 40);
            $table->unsignedSmallInteger('sequence');
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comment', 1000)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_partner_id', 'status']);
        });

        // Seeded for every organisation, like the procurement chain, so no
        // tenant discovers on its first submission that nobody can sign.
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            \App\Models\Vendor\VendorApprovalStage::materialiseFor((int) $orgId);
        }

        /*
         * Whoever may manage vendors today may approve them today. Splitting
         * the permission without this would quietly lock every existing
         * administrator out of the button they used yesterday; narrowing it
         * afterwards is a decision for the role editor, not for a migration.
         */
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode($role->permissions ?? '[]', true) ?: [];

            $manages = in_array('vendors.manage', $permissions, true)
                || in_array('vendors.*', $permissions, true)
                || in_array('*', $permissions, true);

            if (! $manages || in_array('vendors.approve', $permissions, true)) {
                continue;
            }

            // A wildcard already covers it — adding the concrete string would
            // only make the role read as though somebody granted it by hand.
            if (in_array('vendors.*', $permissions, true) || in_array('*', $permissions, true)) {
                continue;
            }

            $permissions[] = 'vendors.approve';
            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values($permissions)),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_approvals');
        Schema::dropIfExists('vendor_approval_stages');

        Schema::table('vendor_partners', function (Blueprint $table) {
            $table->dropColumn([
                'submitted_by', 'submitted_at',
                'pending_change', 'pending_change_by', 'pending_change_at',
            ]);
        });
    }
};
