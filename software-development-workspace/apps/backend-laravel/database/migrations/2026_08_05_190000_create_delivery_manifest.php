<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deliveries: what is being moved, who is moving it, and the note that proves
 * it arrived.
 *
 * A delivery is not a trip with cargo. It is coordinated by finance from the
 * procurement side, it carries a manifest of what is in the vehicle, and it
 * closes against a signed delivery note rather than a fuel receipt. Those are
 * not fields on a travel request; they are the substance of a different
 * document that happens to need the same approval and costing machinery.
 *
 * Reusing travel_requests underneath keeps one approval chain, one
 * reconciliation and one tenancy boundary. What differs is what is attached to
 * it and how it is presented — which is why this is a manifest rather than a
 * second requests table.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What is in the vehicle.
         *
         * Per line rather than a free-text description, because a manifest is
         * checked off on arrival: "3 rack servers, 2 switches" is countable
         * against what was received, and a paragraph is not.
         */
        Schema::create('delivery_manifest_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_id')->constrained('travel_requests')->cascadeOnDelete();

            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit')->default('pcs');          // pcs | boxes | pallets | kg
            $table->decimal('weight_kg', 10, 2)->nullable();
            $table->string('dimensions')->nullable();

            // Serial, asset tag, invoice or LPO number — whatever identifies
            // this consignment against the paperwork it came with.
            $table->string('reference')->nullable();
            $table->decimal('declared_value', 14, 2)->nullable();

            /*
             * Signed for on arrival, per line.
             *
             * A delivery where four of five items arrived is the case that
             * matters, and a single "delivered" flag on the request cannot
             * express it.
             */
            $table->decimal('quantity_received', 10, 2)->nullable();
            $table->string('condition_on_arrival')->nullable();   // good | damaged | partial
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('travel_request_id');
        });

        /*
         * Who is delivering.
         *
         * More than one person, because a heavy consignment travels with a
         * driver and a hand, and both need to be named for a note either of
         * them might sign.
         */
        Schema::create('delivery_couriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_id')->constrained('travel_requests')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // The person accountable for the consignment, as distinct from
            // whoever came along to help carry it.
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['travel_request_id', 'employee_id']);
        });

        Schema::table('travel_attachments', function (Blueprint $table) {
            /*
             * What kind of document this is.
             *
             * A delivery closes against a signed note, so the system has to be
             * able to tell one attachment from another — otherwise "has an
             * attachment" stands in for "was delivered", and a quotation would
             * satisfy it.
             */
            $table->string('document_type')->default('other')->after('travel_request_id');
            // delivery_note | quotation | invoice | ticket | visa | receipt | other
        });

        Schema::table('travel_requests', function (Blueprint $table) {
            // The paperwork this consignment travels against, so the manifest
            // can be checked against the order it came from.
            $table->string('delivery_reference')->nullable()->after('linked_task_ref');
            $table->foreignId('delivery_note_attachment_id')->nullable()->after('delivery_reference')
                  ->constrained('travel_attachments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('travel_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_note_attachment_id');
            $table->dropColumn('delivery_reference');
        });

        Schema::table('travel_attachments', function (Blueprint $table) {
            $table->dropColumn('document_type');
        });

        Schema::dropIfExists('delivery_couriers');
        Schema::dropIfExists('delivery_manifest_items');
    }
};
