<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per organisation, the same shape as payroll_document_settings —
 * a threshold nobody has set yet means "no Finance stage", not "block
 * everything until someone visits a settings screen".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_budget_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->decimal('approval_threshold_amount', 12, 2)->nullable();
            $table->char('approval_threshold_currency', 3)->default('KES');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_budget_settings');
    }
};
