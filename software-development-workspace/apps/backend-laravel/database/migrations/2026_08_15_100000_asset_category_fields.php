<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields defined per category, instead of one form for everything.
 *
 * The register has columns for a laptop — processor, memory, storage, operating
 * system — and nothing for a UPS, which wants capacity in VA and a battery
 * replacement date, or a vehicle, which wants a registration and a logbook
 * number. Adding either meant a migration, so in practice they went in the
 * notes field, where nothing can be reported on or expire.
 *
 * A category now carries its own field definitions and an asset carries the
 * answers. That trades a little query convenience for the ability to describe
 * what the organisation actually owns without a developer.
 *
 * ── Why JSON for the values ──
 *
 * The alternative is a row per value in a side table, which is tidier in theory
 * and means every screen that shows an asset does an extra join and a pivot to
 * display six fields. Postgres indexes JSONB properly and these are read
 * whole — an asset is never asked for one custom field across the estate.
 *
 * ── Why keys are immutable ──
 *
 * The stored values are keyed by `key`, so renaming one would orphan every
 * answer already given. The label can change freely; the key is generated once
 * and never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_category_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_category_id')->constrained('asset_categories')->cascadeOnDelete();

            /*
             * The stable name the answers are stored under. Derived from the
             * label when the field is created and never changed afterwards —
             * editing it would orphan every value already captured.
             */
            $table->string('key', 60);
            $table->string('label', 120);

            // text | textarea | number | date | select | boolean
            $table->string('type', 24)->default('text');

            // For select. Ignored otherwise.
            $table->json('options')->nullable();

            /*
             * A sentence for whoever is filling it in. The difference between
             * "capacity" meaning VA and meaning watts is obvious to the person
             * who added the field and to nobody else.
             */
            $table->string('help', 255)->nullable();

            $table->boolean('is_required')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            // One key per category; two fields called the same thing would
            // overwrite each other's answers.
            $table->unique(['asset_category_id', 'key']);
            $table->index(['organization_id', 'asset_category_id']);
        });

        Schema::table('assets', function (Blueprint $table) {
            /*
             * The answers, keyed by field key.
             *
             * Defaults to an empty object rather than null so every read gets
             * something iterable and no caller has to remember the difference
             * between "no answers" and "never asked".
             */
            $table->jsonb('custom_fields')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn('custom_fields'));
        Schema::dropIfExists('asset_category_fields');
    }
};
