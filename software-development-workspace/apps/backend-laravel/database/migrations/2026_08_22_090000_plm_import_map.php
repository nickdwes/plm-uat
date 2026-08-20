<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What came from the old system, and what it became.
 *
 * The legacy ids are kept here rather than on the records themselves. An
 * `plm_account_id` column on `accounts` would outlive the migration by years,
 * be null for everything created since, and quietly invite code to depend on
 * it — and the domain schema should not carry a scar from how the data
 * arrived.
 *
 * It earns its place three times over: the import can run twice without
 * duplicating anything, the ticket import can resolve `mdl_tickets.account`
 * to a real account, and when a figure looks wrong in eighteen months
 * somebody can ask which legacy row it came from and get an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plm_import_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('source_table', 64);
            $table->unsignedBigInteger('source_id');

            $table->string('target_type', 120);
            $table->unsignedBigInteger('target_id');

            /*
             * What the row looked like when it came across. Not for reading
             * back into the app — for answering "was it already wrong in the
             * old system, or did we break it?", which is the first question
             * asked about every migrated figure.
             */
            $table->json('source_snapshot')->nullable();
            $table->string('note', 255)->nullable();

            $table->timestamps();

            // One legacy row becomes one record. This is what makes a second
            // run an update rather than a duplicate.
            $table->unique(['organization_id', 'source_table', 'source_id']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plm_import_map');
    }
};
