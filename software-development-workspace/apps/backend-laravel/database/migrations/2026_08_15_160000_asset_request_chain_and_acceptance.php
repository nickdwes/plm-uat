<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A request that goes somewhere, and equipment somebody signs for.
 *
 * ── The chain ──
 *
 * `asset_requests` had four states: pending, approved, rejected, fulfilled.
 * One decision by one person. The designs show a chain — HR review, then
 * finance, then approval, with "forward to procurement" when there is nothing
 * on the shelf to give.
 *
 * Those stages are not decoration. Each is a different person answering a
 * different question: HR asks whether this person should have one, finance
 * asks whether we can afford it, procurement asks who we buy it from. Folding
 * them into one "approved" loses who agreed to what, which is the first thing
 * asked when something turns out to have been a mistake.
 *
 * ── Acceptance ──
 *
 * `handover_required` and `handover_signed_at` have existed as columns with no
 * flow attached. Assigning a laptop set `assigned_to` immediately, so the
 * register said somebody held equipment they had not yet been given — and if
 * it went missing in between, it went missing from their record.
 *
 * An assignment that needs a handover now waits at `pending_acceptance` until
 * the person says they have it. Custody starts when they accept, not when
 * somebody types their name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_requests', function (Blueprint $table) {
            /*
             * Who acted at each stage, rather than one `approved_by`.
             *
             * The single column could only remember the last person to touch
             * it, so a request approved by HR and then declined by finance
             * looked as though HR had declined it.
             */
            /*
             * Employees, matching `approved_by` on this table and every other
             * "who did this" column in the module. Mixing users and employees
             * inside one table is how a join silently returns the wrong person.
             */
            $table->foreignId('hr_reviewed_by')->nullable()->after('status')
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('hr_reviewed_at')->nullable()->after('hr_reviewed_by');

            $table->foreignId('finance_reviewed_by')->nullable()->after('hr_reviewed_at')
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('finance_reviewed_at')->nullable()->after('finance_reviewed_by');

            $table->foreignId('procurement_by')->nullable()->after('finance_reviewed_at')
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('procurement_at')->nullable()->after('procurement_by');

            /*
             * Why it was declined, kept apart from `decision_note`.
             *
             * A refusal without a reason is the thing people complain about,
             * and the designs put it in its own box on the decline dialog. It
             * is also what the requester is shown, so it should not be mixed
             * with internal remarks.
             */
            $table->text('rejection_reason')->nullable()->after('decision_note');

            // Why procurement was involved — usually "nothing suitable in
            // stock", which the designs surface as its own banner.
            $table->string('procurement_note', 500)->nullable()->after('rejection_reason');
        });

        Schema::table('assets', function (Blueprint $table) {
            /*
             * Handed over, and signed for.
             *
             * `handover_signed_at` recorded that somebody signed. This records
             * who, which is the part that matters when the signature is the
             * evidence.
             */
            $table->foreignId('accepted_by')->nullable()->after('handover_signed_at')
                ->constrained('employees')->nullOnDelete();

            /*
             * What they said when they took it.
             *
             * The designs' acceptance form is an acknowledgement of condition;
             * "screen has a scratch already" belongs on the record from the
             * start rather than being discovered at the exit interview.
             */
            $table->string('acceptance_note', 500)->nullable()->after('accepted_by');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn('acceptance_note');
        });

        Schema::table('asset_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hr_reviewed_by');
            $table->dropConstrainedForeignId('finance_reviewed_by');
            $table->dropConstrainedForeignId('procurement_by');
            $table->dropColumn([
                'hr_reviewed_at', 'finance_reviewed_at', 'procurement_at',
                'rejection_reason', 'procurement_note',
            ]);
        });
    }
};
