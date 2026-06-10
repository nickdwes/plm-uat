<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_items', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(false)->after('is_featured');
            $table->boolean('is_budgeted')->default(false)->after('requires_approval');
            $table->decimal('budget_amount', 12, 2)->nullable()->after('is_budgeted');
        });
    }

    public function down(): void
    {
        Schema::table('training_items', function (Blueprint $table) {
            $table->dropColumn(['requires_approval', 'is_budgeted', 'budget_amount']);
        });
    }
};
