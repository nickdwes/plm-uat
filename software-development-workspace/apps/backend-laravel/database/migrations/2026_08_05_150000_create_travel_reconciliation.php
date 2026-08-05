<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closing a trip: evidence that the money was spent as approved.
 *
 * WHAT EVIDENCE MEANS DIFFERS BY CATEGORY, AND SO DOES WHO PROVIDES IT.
 *
 * A boolean `requires_receipt` could not express the real cases:
 *
 *   Uber fares        a receipt, from the traveller who took the ride
 *   Airline tickets   a receipt, from finance who bought them — the traveller
 *                     never sees an invoice and asking them for one guarantees
 *                     the trip never closes
 *   Per diem          no receipt at all, but the traveller confirms they
 *                     received it, so an unspent advance surfaces
 *
 * So a category carries two settings: what evidence is (nothing, an
 * acknowledgement, or a document) and whose job it is to supply it. Getting the
 * second wrong is what makes reconciliation stall — everyone waits for someone
 * else, and the request sits open until somebody closes it out of impatience.
 *
 * The legacy system's reconciliation table held zero rows across 102 requests.
 * This is not that design rebuilt: that one demanded receipts for everything
 * from the traveller, which is why nobody used it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_expense_categories', function (Blueprint $table) {
            // none | acknowledgement | receipt
            $table->string('evidence_type')->default('none')->after('requires_receipt');
            // requestor | finance | either
            $table->string('evidence_provider')->default('requestor')->after('evidence_type');
        });

        // Carry the old boolean across rather than silently resetting whatever
        // an organisation had already configured.
        DB::table('travel_expense_categories')
            ->where('requires_receipt', true)
            ->update(['evidence_type' => 'receipt']);

        Schema::create('travel_expense_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_item_id')
                  ->constrained('travel_request_items')->cascadeOnDelete();

            $table->string('kind');                       // receipt | acknowledgement

            /*
             * Why this receipt is what it claims to be.
             *
             * Required rather than optional: a receipt with no explanation
             * cannot be checked without knowing what it was for, and finance
             * ends up chasing the traveller anyway.
             */
            $table->text('explanation');

            // Absent for an acknowledgement, which is a statement rather than
            // a document.
            $table->string('storage_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            /*
             * What was actually spent, which is the point of reconciling. Left
             * null when the evidence is an acknowledgement that the approved
             * amount was received.
             */
            $table->decimal('amount', 14, 2)->nullable();

            $table->foreignId('submitted_by_employee_id')->nullable()
                  ->constrained('employees')->nullOnDelete();
            $table->timestamp('submitted_at');

            $table->timestamps();
            $table->softDeletes();

            $table->index('travel_request_item_id');
        });

        Schema::table('travel_requests', function (Blueprint $table) {
            $table->timestamp('reconciled_at')->nullable()->after('completed_at');
            $table->foreignId('reconciled_by_employee_id')->nullable()->after('reconciled_at')
                  ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('travel_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reconciled_by_employee_id');
            $table->dropColumn('reconciled_at');
        });

        Schema::dropIfExists('travel_expense_evidence');

        Schema::table('travel_expense_categories', function (Blueprint $table) {
            $table->dropColumn(['evidence_type', 'evidence_provider']);
        });
    }
};
