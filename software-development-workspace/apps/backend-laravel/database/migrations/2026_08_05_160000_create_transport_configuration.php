<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transport configuration.
 *
 * Transport entitlement is mileage- and cost-driven, not grade-driven: a 40 km
 * errand and a 600 km round trip are not the same claim, and no grade table
 * tells them apart. What is configured here is the arithmetic — a rate per
 * kilometre, what a vehicle consumes, what a bus costs on a route — and the
 * journey supplies the rest.
 *
 * Fuel price is deliberately absent. The requirement is explicit that it is
 * "the current fuel price at the point of request", and a pump price held
 * centrally is stale within a week: every request would then be costed against
 * a number nobody remembered to update.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Rate per kilometre for self-drive reimbursement.
         *
         * Effective-dated for the same reason allowance rates are: a trip
         * approved in March must still reconcile against March's rate when
         * somebody checks it in November.
         */
        Schema::create('transport_mileage_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->decimal('rate_per_km', 10, 2);
            $table->char('currency', 3)->default('KES');

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'effective_from']);
        });

        /*
         * Vehicle classes, and what each consumes.
         *
         * Consumption is held per vehicle type rather than per vehicle: a
         * fleet's actual figures vary by driver and load more than by chassis,
         * and an organisation that cannot maintain per-vehicle data ends up
         * with one stale row per car instead of one good row per class.
         */
        Schema::create('transport_vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('name');                          // Saloon, 4x4, Pickup, Van, Lorry
            $table->decimal('km_per_litre', 6, 2)->nullable();
            // Guides the vehicle a delivery needs, which the requirement asks
            // to be driven by what is being moved.
            $table->decimal('load_capacity_kg', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        /*
         * Standard round-trip bus fares.
         *
         * Per route rather than per kilometre: a bus fare is a published price,
         * not a function of distance, and computing it would produce a figure
         * no ticket office recognises.
         */
        Schema::create('transport_bus_fares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('travel_destination_id')->constrained('travel_destinations')->cascadeOnDelete();

            $table->decimal('round_trip_amount', 12, 2);
            $table->char('currency', 3)->default('KES');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['organization_id', 'travel_destination_id']);
        });

        /*
         * One journey, by one mode.
         *
         * A leg rather than a field on the request, because a real trip is
         * several: a flight to Kampala and taxis once there. One row per mode
         * expresses that; a single transport_mode column forces a choice that
         * does not exist.
         */
        Schema::create('travel_transport_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_id')->constrained('travel_requests')->cascadeOnDelete();

            $table->string('mode');          // self_drive | hired_vehicle | bus | taxi | flight | visa
            $table->string('purpose')->nullable();
            $table->string('travel_type')->default('local');   // local | international | delivery

            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->foreignId('travel_destination_id')->nullable()
                  ->constrained('travel_destinations')->nullOnDelete();

            // ── Mileage and fuel ──────────────────────────────────────────
            $table->decimal('estimated_km', 10, 2)->nullable();
            $table->decimal('actual_km', 10, 2)->nullable();
            // Snapshotted, like every other rate in this system: reconciling
            // against today's rate would re-judge a decision already made.
            $table->decimal('rate_per_km_applied', 10, 2)->nullable();
            // Entered by the requester, because a pump price held centrally is
            // wrong within a week.
            $table->decimal('fuel_price_per_litre', 10, 2)->nullable();
            $table->foreignId('transport_vehicle_type_id')->nullable()
                  ->constrained('transport_vehicle_types')->nullOnDelete();
            $table->unsignedSmallInteger('days')->nullable();

            // ── Delivery ──────────────────────────────────────────────────
            $table->boolean('is_delivery')->default(false);
            $table->string('item_description')->nullable();
            $table->decimal('item_weight_kg', 10, 2)->nullable();
            $table->string('item_dimensions')->nullable();

            // ── Money ─────────────────────────────────────────────────────
            $table->decimal('estimated_cost', 14, 2)->nullable();
            $table->decimal('actual_cost', 14, 2)->nullable();
            $table->char('currency', 3)->default('KES');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['travel_request_id', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_transport_legs');
        Schema::dropIfExists('transport_bus_fares');
        Schema::dropIfExists('transport_vehicle_types');
        Schema::dropIfExists('transport_mileage_rates');
    }
};
