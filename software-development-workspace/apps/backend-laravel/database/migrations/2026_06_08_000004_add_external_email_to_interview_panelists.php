<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_panelists', function (Blueprint $table) {
            // Allow panelists who are not in the employee directory (external / guests)
            $table->string('external_email', 255)->nullable()->after('employee_id');
            $table->string('external_name',  150)->nullable()->after('external_email');
            // employee_id is no longer strictly required when external_email is provided
            $table->unsignedBigInteger('employee_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('interview_panelists', function (Blueprint $table) {
            $table->dropColumn(['external_email', 'external_name']);
            $table->unsignedBigInteger('employee_id')->nullable(false)->change();
        });
    }
};
