<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An asset register that can hold something other than a laptop.
 *
 * `assets` was built for hardware — it has `processor`, `ram`, `storage` and
 * `operating_system` as columns. A Figma licence appears as an asset in the
 * designs already, and an SSL certificate has none of those things and one that
 * hardware does not: a date it stops working, after which somebody's site goes
 * down.
 *
 * Done now because the table is empty. Every one of these changes is cheap
 * today and a data migration once a thousand assets are in.
 *
 * ── What is deliberately not here ──
 *
 * No depreciation. Purchase value is recorded and nothing is computed from it:
 * methods, schedules and journals need a chart of accounts this system does not
 * have, and nothing in the designs asks for them.
 *
 * No cloud, identity, property or intellectual-property classes. They are in
 * the brief and they are real, but an Azure resource and a patent have almost
 * nothing in common with each other, and modelling them before anybody uses
 * them produces abstraction that cannot be tested against anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            /*
             * What kind of thing this is.
             *
             * The discriminator rather than a set of nullable columns per type:
             * `category` already says "Laptop" or "Monitor", which is a label
             * within a class, not the class itself. A subscription is not a
             * laptop with the hardware fields left empty — it is answerable to
             * different questions, and reports that mix them are wrong twice.
             */
            $table->string('asset_class', 24)->default('physical')->after('organization_id');

            /*
             * Sub-assets. A docking station belongs to a laptop; a licence seat
             * belongs to a subscription.
             *
             * Nulled rather than cascaded on delete: losing the parent must not
             * silently take the children with it, because the children are the
             * things somebody is still holding.
             */
            $table->foreignId('parent_asset_id')->nullable()->after('asset_class')
                ->constrained('assets')->nullOnDelete();

            /*
             * ── Renewal and expiry ──
             *
             * One idea, not three. A warranty, a subscription term, an SSL
             * certificate and a domain are the same shape: this stops on a date
             * and somebody must act before then. `warranty_expiry_date` stays
             * where it is for hardware; this is the general one, and it is what
             * the renewals report and the reminders read.
             */
            $table->date('expires_on')->nullable()->after('warranty_expiry_date');
            $table->boolean('auto_renews')->default(false)->after('expires_on');
            // Days before `expires_on` that somebody should hear about it.
            // Null means the organisation default rather than "never".
            $table->smallInteger('renewal_notice_days')->nullable()->after('auto_renews');
            $table->decimal('renewal_cost', 14, 2)->nullable()->after('renewal_notice_days');

            /*
             * ── Seats ──
             *
             * A subscription is bought in quantity and consumed by people, and
             * the number nobody tracks is the one that costs money: seats paid
             * for and not used. Held on the asset rather than derived from
             * assignments, because the invoice is the truth about what was
             * bought — assignments only say what was handed out.
             */
            $table->integer('seats_total')->nullable()->after('renewal_cost');
            $table->integer('seats_used')->nullable()->after('seats_total');

            // Who to talk to when it breaks or comes up for renewal. On the
            // asset because it differs per subscription even with one vendor.
            $table->string('vendor_contact', 255)->nullable()->after('vendor');
            $table->string('support_contact', 255)->nullable()->after('vendor_contact');
            $table->string('sla', 120)->nullable()->after('support_contact');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->index(['organization_id', 'asset_class']);
            // The renewals report and the expiry sweep both run off this.
            $table->index('expires_on');
        });

        /*
         * ── The lifecycle timeline ──
         *
         * The designs show Procured → In Stock → Assigned → Reassigned → Under
         * Repair → Retired, each with a date and a person. `lifecycle_stage` is
         * a single string: it can say where an asset is now and can never say
         * how it got there, so the timeline in the design could not be drawn
         * from it and "who had this in March" had no answer.
         *
         * Events rather than a status column with an audit log, because the
         * timeline is the feature, not a side effect of one.
         */
        Schema::create('asset_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            // procured, in_stock, assigned, reassigned, returned, under_repair,
            // repaired, lost, retired, disposed.
            $table->string('event', 32);

            $table->date('occurred_on');

            // Who held it, and where, at this point. Kept on the event rather
            // than read back through the asset: the asset says where it is now,
            // and the timeline has to say where it was.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('location', 255)->nullable();
            $table->foreignId('vendor_partner_id')->nullable()
                ->constrained('vendor_partners')->nullOnDelete();

            $table->text('note')->nullable();

            // Who recorded it, which is not always who it happened to.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'occurred_on']);
        });

        /*
         * Existing assets get their current stage as a first event, so the
         * timeline is not empty for anything already on the register. There are
         * none today; this is here so the migration is honest if that changes
         * before it runs anywhere else.
         */
        DB::statement("
            INSERT INTO asset_events (organization_id, asset_id, event, occurred_on, employee_id, created_at, updated_at)
            SELECT organization_id, id,
                   CASE WHEN status = 'retired' THEN 'retired'
                        WHEN assigned_to IS NOT NULL THEN 'assigned'
                        ELSE 'in_stock' END,
                   COALESCE(assigned_date, in_stock_date, procured_date, CURRENT_DATE),
                   assigned_to, NOW(), NOW()
            FROM assets
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_events');

        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'asset_class']);
            $table->dropIndex(['expires_on']);
            $table->dropConstrainedForeignId('parent_asset_id');
            $table->dropColumn([
                'asset_class', 'expires_on', 'auto_renews', 'renewal_notice_days',
                'renewal_cost', 'seats_total', 'seats_used',
                'vendor_contact', 'support_contact', 'sla',
            ]);
        });
    }
};
