<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employee_organization_map', function (Blueprint $table) {
            $table->unsignedTinyInteger('allocation_pct')->default(100)->after('role');
            $table->boolean('is_billable')->default(false)->after('allocation_pct');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_organization_map', function (Blueprint $table) {
            $table->dropColumn(['allocation_pct', 'is_billable']);
        });
    }
};
