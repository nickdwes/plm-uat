<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of a travel request: itinerary, attachments, bookings and history.
 *
 * All four hang off the request and inherit its organisation, so nothing here
 * carries its own tenancy column — a booking cannot belong to a different
 * tenant from the trip it is for, and giving it an independent
 * `organization_id` would make that contradiction expressible.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What the traveller will actually be doing, day by day.
         *
         * Separate from the costed lines because they answer different
         * questions: an approver reads the itinerary to judge whether the trip
         * is necessary, and the expenses to judge whether it is affordable.
         */
        Schema::create('travel_itinerary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_id')->constrained('travel_requests')->cascadeOnDelete();

            $table->date('starts_on');
            $table->date('ends_on')->nullable();          // multi-day activities
            $table->time('at_time')->nullable();          // null reads as "full day"
            $table->string('activity');
            $table->string('category')->nullable();       // flight | onsite | meeting | return
            $table->decimal('estimated_cost', 14, 2)->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['travel_request_id', 'starts_on']);
        });

        /*
         * Supporting documents.
         *
         * On the private disk with the rest of the document estate, never the
         * public one — a travel request can carry an invitation letter, a visa
         * or an invoice, none of which belong on a URL that needs no
         * authentication.
         */
        Schema::create('travel_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_id')->constrained('travel_requests')->cascadeOnDelete();

            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('travel_request_id');
        });

        /*
         * Flights, hotels and transport actually booked.
         *
         * Distinct from the estimate: the estimate is what was approved, the
         * booking is what was bought. Keeping them apart is what makes
         * "approved 28,000, booked 27,800" answerable, and collapsing them
         * would overwrite the figure the approval was given against.
         */
        Schema::create('travel_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_id')->constrained('travel_requests')->cascadeOnDelete();

            $table->string('booking_type');                // flight | hotel | transport | other
            $table->string('vendor')->nullable();
            $table->string('status')->default('pending');  // pending | confirmed | cancelled
            $table->string('reference')->nullable();       // airline PNR, hotel confirmation

            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->date('booking_date')->nullable();

            $table->decimal('total_cost', 14, 2)->nullable();
            $table->char('currency', 3)->default('KES');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['travel_request_id', 'booking_type']);
        });

        /*
         * What happened to this request, and when.
         *
         * Written by the application rather than derived, because the
         * interesting events — submitted, approved, returned for changes — are
         * not all recoverable from the current state. A request showing
         * "approved" cannot tell you it was rejected once and resubmitted.
         */
        Schema::create('travel_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_id')->constrained('travel_requests')->cascadeOnDelete();

            $table->string('event');                       // created | submitted | approved | rejected | booked | cancelled
            $table->string('description')->nullable();

            // The person, not the login: an activity trail that stops making
            // sense once someone's account is disabled is not a trail.
            $table->foreignId('actor_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['travel_request_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_activity');
        Schema::dropIfExists('travel_bookings');
        Schema::dropIfExists('travel_attachments');
        Schema::dropIfExists('travel_itinerary_items');
    }
};
