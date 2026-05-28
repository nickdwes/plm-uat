<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── OKR Cycles ────────────────────────────────────────────────────────
        Schema::create('okr_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');                         // e.g. "Jan–Dec 2026"
            $table->smallInteger('year');
            $table->string('period', 20)->default('annual'); // annual | q1 | q2 | q3 | q4
            $table->string('status', 20)->default('draft');  // draft | active | closed
            $table->timestamps();
        });

        // ── OKR Objectives ────────────────────────────────────────────────────
        Schema::create('okr_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('okr_cycles')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('weight', 5, 2)->default(1.00); // org-level weighting
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── OKR Key Results ───────────────────────────────────────────────────
        Schema::create('okr_key_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('objective_id')->constrained('okr_objectives')->cascadeOnDelete();
            $table->string('title');
            $table->string('measure')->nullable();          // unit/description of the metric
            $table->decimal('target_value', 15, 2)->default(100.00);
            $table->decimal('achievement', 15, 2)->nullable();
            $table->decimal('score_percent', 5, 2)->default(0.00); // computed & stored
            $table->decimal('weight', 5, 2)->default(1.00);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── KR Department Scores (the matrix cells) ───────────────────────────
        Schema::create('okr_kr_dept_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('key_result_id')->constrained('okr_key_results')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->decimal('score', 5, 2)->default(0.00);  // 0–100%
            $table->decimal('weight', 5, 2)->default(1.00);
            $table->timestamps();

            $table->unique(['key_result_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_kr_dept_scores');
        Schema::dropIfExists('okr_key_results');
        Schema::dropIfExists('okr_objectives');
        Schema::dropIfExists('okr_cycles');
    }
};
