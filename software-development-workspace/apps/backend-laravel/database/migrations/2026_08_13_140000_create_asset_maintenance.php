<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Servicing, and what it cost.
 *
 * The Maintenance tab has been a promise since the designs arrived: a list of
 * work with a type, a reason, a cost and a status that runs awaiting
 * procurement → awaiting HR → in progress → completed. `assets.next_maintenance`
 * is a single date and can say a service is due; it cannot say what happened at
 * the last one, who did it, or what the organisation has spent keeping a
 * machine alive.
 *
 * That last question is the one that decides whether to repair or replace, and
 * it is unanswerable without history. A laptop repaired three times in a year
 * is a laptop to retire, and nothing in the register could tell you that.
 *
 * Linked to the issue that raised it where there was one, so a fault report and
 * the repair it caused are the same story rather than two records that happen
 * to name the same asset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_maintenance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            /*
             * Preventive is planned; corrective is something broke.
             *
             * Worth separating because the ratio is the health measure: an
             * estate that is mostly corrective is an estate being run to
             * failure, and no cost total on its own shows that.
             */
            $table->string('type', 24);          // preventive | corrective
            $table->string('reason', 255);
            $table->text('details')->nullable();

            /*
             * awaiting_procurement — a part has to be bought
             * awaiting_hr        — somebody has to authorise the spend
             * in_progress        — being worked on
             * completed / cancelled
             *
             * The two waiting states are separate deliberately. Both look like
             * "not started" on a board, and they need entirely different people
             * to unblock them.
             */
            $table->string('status', 24)->default('awaiting_hr');

            $table->date('scheduled_for')->nullable();
            $table->date('performed_on')->nullable();
            $table->decimal('cost', 14, 2)->nullable();

            // Who did the work: an outside firm, or somebody here.
            $table->foreignId('vendor_partner_id')->nullable()
                ->constrained('vendor_partners')->nullOnDelete();
            $table->foreignId('technician_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            // The fault that caused it, where there was one.
            $table->foreignId('asset_issue_id')->nullable()
                ->constrained('asset_issues')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['asset_id', 'performed_on']);
            // The maintenance calendar reads this.
            $table->index('scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance');
    }
};
