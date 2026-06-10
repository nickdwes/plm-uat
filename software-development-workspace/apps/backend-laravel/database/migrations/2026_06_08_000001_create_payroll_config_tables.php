<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── PAYE Tax Bands ────────────────────────────────────────────────────
        Schema::create('payroll_config_tax_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedSmallInteger('tax_year');
            $table->unsignedSmallInteger('band_order'); // 1 = lowest, ascending
            $table->decimal('lower_limit', 14, 2)->default(0);
            $table->decimal('upper_limit', 14, 2)->nullable(); // null = no ceiling (top band)
            $table->decimal('rate', 5, 2);                     // percentage, e.g. 10.00
            $table->date('effective_date');
            $table->date('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'tax_year', 'band_order'], 'pc_taxband_unique');
        });

        // ── Statutory Rate Parameters ─────────────────────────────────────────
        // Keyed store for all configurable statutory rates.
        // Supported keys (see PayrollConfigStatutoryRate::PARAM_KEYS):
        //   personal_relief, insurance_relief_rate, insurance_relief_max,
        //   shif_rate, shif_min, shif_max,
        //   nssf_tier1_ceiling, nssf_tier2_ceiling, nssf_employee_rate, nssf_employer_rate,
        //   housing_levy_employee_rate, housing_levy_employer_rate, nita_amount
        Schema::create('payroll_config_statutory_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('param_key', 60);
            $table->decimal('value', 14, 4);
            $table->string('description', 200)->nullable();
            $table->date('effective_date');
            $table->date('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'param_key', 'effective_date'], 'pc_stat_rate_unique');
        });

        // ── Earning Codes Master ──────────────────────────────────────────────
        Schema::create('payroll_earning_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 120);
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_pensionable')->default(true);
            $table->boolean('is_shif_applicable')->default(true);
            $table->boolean('is_nssf_applicable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->date('effective_date')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'code'], 'pc_earning_code_unique');
        });

        // ── Deduction / Check-off Codes Master ────────────────────────────────
        Schema::create('payroll_deduction_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 120);
            // statutory | checkoff | loan
            $table->string('deduction_type', 30)->default('checkoff');
            // Lower priority number = deducted first
            $table->unsignedSmallInteger('priority')->default(99);
            // fixed | percentage
            $table->string('calculation_type', 20)->default('fixed');
            $table->decimal('max_amount', 14, 2)->nullable();
            $table->boolean('recover_balance')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code'], 'pc_deduction_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_deduction_codes');
        Schema::dropIfExists('payroll_earning_codes');
        Schema::dropIfExists('payroll_config_statutory_rates');
        Schema::dropIfExists('payroll_config_tax_bands');
    }
};
