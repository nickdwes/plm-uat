<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Getting rid of things, and paying to keep others.
 *
 * ── Disposal ──
 *
 * The Disposals tab could have been a filtered list — every column it shows is
 * already on the asset. The record earns its place on the two things a list
 * cannot hold: who authorised it, and whether it was got rid of lawfully.
 *
 * A laptop battery, a monitor and a UPS are electronic waste. In Kenya that is
 * NEMA's business under the Sustainable Waste Management Act, and "we gave them
 * to a man with a van" is not a defence. The register has to be able to say
 * which items needed a licensed handler and produce the certificate.
 *
 * ── Renewals ──
 *
 * `expires_on` says when something dies. It cannot say what the last renewal
 * cost, who it was bought from, or whether the price is climbing — and those
 * are the questions asked when the invoice arrives. Renewals are therefore
 * history, one row per term.
 *
 * Reminders are logged rather than computed on the fly, because the sweep runs
 * daily and must not send the same warning twice. The unique index is the
 * guard: the sweep attempts an insert and treats a duplicate as "already told
 * them", which is safe under concurrency in a way that check-then-write is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ── How something left ──
         */
        Schema::create('asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            // sold, donated, recycled, scrapped, returned_to_vendor, written_off, lost
            $table->string('method', 32);
            $table->date('disposed_on');

            /*
             * Whether this had to go to a licensed handler.
             *
             * Set deliberately rather than inferred from the category, because
             * the category says "Laptop" and the thing that makes it e-waste is
             * the battery inside it. Recorded even when false: "we considered
             * it and it did not apply" and "nobody thought about it" are
             * different states, and only the first survives an audit.
             */
            $table->boolean('requires_safe_disposal')->default(false);
            // NEMA licence number, WEEE reference, or whatever the handler gave us.
            $table->string('handler_name', 255)->nullable();
            $table->string('handler_licence_ref', 120)->nullable();
            $table->date('certificate_received_on')->nullable();

            // What we got for it, if anything. Nullable rather than zero —
            // scrapped and sold-for-nothing are not the same event.
            $table->decimal('proceeds', 14, 2)->nullable();

            /*
             * Who said it could go. Disposal is irreversible and it removes an
             * asset somebody paid for from the register, so it is the one
             * lifecycle event that names an approver.
             */
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One disposal per asset. Something cannot be scrapped twice.
            $table->unique('asset_id');
            $table->index(['organization_id', 'disposed_on']);
        });

        /*
         * ── What each renewal cost, and who from ──
         */
        Schema::create('asset_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            $table->date('renewed_on');
            // The date this term runs to — becomes the asset's `expires_on`.
            $table->date('expires_on');

            $table->decimal('cost', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();

            /*
             * Who it was bought from this time.
             *
             * Free text alongside the linked vendor, because renewals are often
             * bought through a reseller who is not a vendor partner in this
             * system, and losing that is losing the answer to "who did we buy
             * it from last year".
             */
            $table->foreignId('vendor_partner_id')->nullable()
                ->constrained('vendor_partners')->nullOnDelete();
            $table->string('vendor_name', 255)->nullable();

            $table->integer('seats')->nullable();
            $table->string('invoice_number', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'renewed_on']);
        });

        /*
         * ── Who hears about it ──
         *
         * Per asset, because the person who cares that the Adobe licence is up
         * is not the person who cares about the SSL certificate. A pivot rather
         * than a JSON column so a leaver's row goes with them instead of
         * leaving an id nobody can resolve.
         */
        Schema::create('asset_renewal_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['asset_id', 'employee_id']);
        });

        /*
         * ── What has already been sent ──
         *
         * The sweep runs daily and the cadence tightens as the date approaches,
         * so without a record it would re-send the six-month warning every
         * morning for three months.
         *
         * `stage_key` is the milestone — `m6`, `m3`, `m1`, `w:2026-08-10`,
         * `d:2026-08-25` — and the unique index makes a resend impossible
         * rather than unlikely.
         */
        Schema::create('asset_renewal_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('stage_key', 32);
            $table->date('sent_on');
            $table->smallInteger('days_out');
            $table->timestamps();

            $table->unique(['asset_id', 'stage_key']);
        });

        Schema::table('assets', function (Blueprint $table) {
            /*
             * What the next renewal is expected to cost.
             *
             * Distinct from `renewal_cost`, which is what the current term
             * actually cost. Budgeting needs the guess; reconciliation needs
             * the fact, and one column cannot be both.
             */
            $table->decimal('estimated_renewal_cost', 14, 2)->nullable()->after('renewal_cost');
            // Off by default. A reminder nobody asked for is a reminder people
            // learn to filter.
            $table->boolean('renewal_reminders_on')->default(false)->after('estimated_renewal_cost');
        });

        /*
         * ── Somebody to forward a request to ──
         *
         * The designs' asset request chain ends at "Forward to Procurement" and
         * there was no procurement role, so the stage had nobody standing at it
         * — the same silent stall as an approval queue with no approver.
         *
         * Its own role rather than an addition to finance. Finance asks whether
         * we can afford it; procurement asks who we buy it from and on what
         * terms. Collapsing them would leave the chain intact on paper and
         * defeated in practice.
         */
        if (! DB::table('roles')->where('name', 'procurement_officer')->exists()) {
            DB::table('roles')->insert([
                'name'         => 'procurement_officer',
                'display_name' => 'Procurement Officer',
                'description'  => 'Sources and buys what the business asks for, and holds the vendor relationship.',
                'scope'        => 'organization',
                'is_system'    => true,
                'permissions'  => json_encode([
                    'assets.view', 'assets.create', 'assets.update', 'assets.approve',
                    'vendors.view', 'vendors.manage',
                ]),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'procurement_officer')->delete();

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['estimated_renewal_cost', 'renewal_reminders_on']);
        });

        Schema::dropIfExists('asset_renewal_reminders');
        Schema::dropIfExists('asset_renewal_watchers');
        Schema::dropIfExists('asset_renewals');
        Schema::dropIfExists('asset_disposals');
    }
};
