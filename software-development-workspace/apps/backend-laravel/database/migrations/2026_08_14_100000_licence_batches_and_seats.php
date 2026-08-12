<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A licence is a batch with a term, and seats that belong to people.
 *
 * `seats_used` was an integer somebody typed. It could say eighteen of
 * twenty-five were in use and could never say which eighteen — so the question
 * that actually gets asked when a renewal comes up, or when somebody leaves, or
 * when a vendor audits, had no answer: *who is affected*.
 *
 * Worse, a hand-maintained count drifts. Somebody leaves, nobody decrements it,
 * and the register quietly reports full utilisation on a licence with six spare
 * seats nobody is using. That is money.
 *
 * So the count goes and allocations arrive. Seats used is derived from rows
 * that name a person, which cannot disagree with itself.
 *
 * ── The term ──
 *
 * A batch runs from a date to a date. `expires_on` already held the end;
 * `starts_on` gives the other half, so "this contract ran April to April" is a
 * fact rather than an inference from when somebody happened to key it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // The other end of the term. Nullable because hardware has no term
            // and older records will not have one.
            $table->date('starts_on')->nullable()->after('warranty_expiry_date');

            /*
             * What this licence actually lets somebody do.
             *
             * Separate from `notes`, which is operational remarks — where the
             * key is kept, who to ring. This is the capability: "Full Creative
             * Cloud, all apps, 100GB storage, commercial use". It is what the
             * person deciding whether to buy another seat needs to read.
             */
            $table->text('description')->nullable()->after('name');
        });

        /*
         * ── Seats ──
         *
         * One row per seat handed to somebody. Released rather than deleted:
         * a seat that was held for eight months and given back is part of the
         * story a renewal conversation needs, and deleting it would make a
         * licence look under-used for a year it was fully consumed.
         */
        Schema::create('asset_seat_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->date('allocated_on');
            $table->date('released_on')->nullable();

            // What the seat is for, where that is not obvious from the person.
            $table->string('note', 255)->nullable();
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'released_on']);
            $table->index('employee_id');
        });

        /*
         * The typed count goes.
         *
         * Dropped rather than kept as a fallback: two sources for one number is
         * how they come to disagree, and the register is empty, so nothing is
         * lost. A licence whose holders are unknown should read as unknown, not
         * as a number somebody guessed once.
         */
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('seats_used');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->integer('seats_used')->nullable()->after('seats_total');
        });

        Schema::dropIfExists('asset_seat_allocations');

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['starts_on', 'description']);
        });
    }
};
