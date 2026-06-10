<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_scores', function (Blueprint $table) {
            // Per-criterion comment support
            $table->json('criterion_comments')->nullable()->after('interests_goals');

            // Technical exercise section
            $table->boolean('technical_exercise_conducted')->default(false)->after('criterion_comments');
            $table->decimal('technical_exercise_score', 5, 2)->nullable()->after('technical_exercise_conducted');
            $table->text('technical_exercise_notes')->nullable()->after('technical_exercise_score');
        });
    }

    public function down(): void
    {
        Schema::table('interview_scores', function (Blueprint $table) {
            $table->dropColumn([
                'criterion_comments',
                'technical_exercise_conducted',
                'technical_exercise_score',
                'technical_exercise_notes',
            ]);
        });
    }
};
