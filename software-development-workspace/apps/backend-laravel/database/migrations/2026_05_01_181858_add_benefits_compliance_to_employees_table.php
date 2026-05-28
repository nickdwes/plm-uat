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
        Schema::table('employees', function (Blueprint $table) {
            // Benefits
            $table->string('medical_plan', 255)->nullable()->after('nhif_number');
            $table->string('pension_provider', 255)->nullable()->after('medical_plan');
            $table->boolean('benefit_medical')->default(false)->after('pension_provider');
            $table->boolean('benefit_life_insurance')->default(false)->after('benefit_medical');
            $table->boolean('benefit_pension')->default(false)->after('benefit_life_insurance');
            $table->boolean('benefit_wiba')->default(false)->after('benefit_pension');
            $table->boolean('benefit_group_life')->default(false)->after('benefit_wiba');
            $table->date('benefits_effective_date')->nullable()->after('benefit_group_life');

            // Compliance
            $table->boolean('nssf_registered')->default(false)->after('benefits_effective_date');
            $table->boolean('nhif_registered')->default(false)->after('nssf_registered');
            $table->boolean('kra_registered')->default(false)->after('nhif_registered');
            $table->boolean('has_work_permit')->default(false)->after('kra_registered');
            $table->string('work_permit_number', 50)->nullable()->after('has_work_permit');
            $table->date('work_permit_expiry')->nullable()->after('work_permit_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'medical_plan', 'pension_provider',
                'benefit_medical', 'benefit_life_insurance', 'benefit_pension',
                'benefit_wiba', 'benefit_group_life', 'benefits_effective_date',
                'nssf_registered', 'nhif_registered', 'kra_registered',
                'has_work_permit', 'work_permit_number', 'work_permit_expiry',
            ]);
        });
    }
};
