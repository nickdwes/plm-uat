<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the legacy's research rows actually carried.
 *
 * Three booleans naming the scope of the work — concept, architecture,
 * integration — set on 18, 14 and 13 of its 20 rows. They are not decoration:
 * they say whether the question is "can this be done at all", "how would it be
 * built", or "will it talk to what they already run", and those are answered by
 * different people.
 *
 * `script` is the legacy's `research_script`, on 9 of 20 — the plan for the
 * work, as distinct from `findings`, which is what came back.
 *
 * Not carried over: its `status`, which held 'New' on all twenty rows. A column
 * with one value answers nothing, and the status here is ours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('researches', function (Blueprint $table) {
            // Nullable rather than defaulting false: unanswered and answered-no
            // are different, and the legacy distinguished them.
            $table->boolean('covers_concept')->nullable()->after('description');
            $table->boolean('covers_architecture')->nullable()->after('covers_concept');
            $table->boolean('covers_integration')->nullable()->after('covers_architecture');

            $table->text('script')->nullable()->after('covers_integration');
        });
    }

    public function down(): void
    {
        Schema::table('researches', function (Blueprint $table) {
            $table->dropColumn(['covers_concept', 'covers_architecture', 'covers_integration', 'script']);
        });
    }
};
