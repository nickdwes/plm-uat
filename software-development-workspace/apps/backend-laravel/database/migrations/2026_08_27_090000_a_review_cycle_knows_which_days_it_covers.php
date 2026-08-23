<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A review cycle that knows which days it covers.
 *
 * `performance_reviews.review_cycle` is a free-text string — "H1 2026",
 * "H1-2026", "First half 2026" are three cycles as far as anything here can
 * tell, and none of them says which days it is about. That was survivable
 * while a review was only a rating and some comments. It stops being
 * survivable the moment attendance features in one: a figure covering an
 * unstated window is not comparable with anybody else's.
 *
 * So cycles become rows with dates, per organisation, and a review points at
 * one. Every review in H1 2026 then covers exactly the same days, which is
 * the property that makes two people's attendance figures mean the same
 * thing.
 *
 * ── The old column stays ──
 *
 * `review_cycle` is not dropped. Reviews already written carry a name and no
 * cycle to point at, and deleting the only record of what cycle they belonged
 * to in order to tidy the schema would lose the thing itself. New reviews use
 * `cycle_id`; the text remains as what was typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 60);
            $table->date('starts_on');
            $table->date('ends_on');

            /*
             * Whether reviews may still be written against it. A closed cycle
             * keeps its dates — the attendance figures on last year's reviews
             * have to stay reproducible — it simply stops being offered.
             */
            $table->boolean('is_open')->default(true);

            $table->timestamps();

            // One "H1 2026" per organisation. Two would be the free-text
            // problem again with extra steps.
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'starts_on']);
        });

        Schema::table('performance_reviews', function (Blueprint $table) {
            /*
             * Nullable, and null on every existing row. A review written
             * before cycles existed belongs to a window nobody recorded, and
             * inventing one for it would put a made-up date range behind a
             * real appraisal.
             */
            $table->foreignId('cycle_id')->nullable()->after('review_cycle')
                ->constrained('performance_cycles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cycle_id');
        });

        Schema::dropIfExists('performance_cycles');
    }
};
