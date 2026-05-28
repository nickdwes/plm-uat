<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Adds salary + allowance columns to employees
 * - Expands payroll_runs with Kenya-specific statutory columns
 * - Adds payroll_run_allowances for per-employee ad-hoc earnings
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Employee salary fields ────────────────────────────────────────────
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('basic_salary', 14, 2)->default(0)->after('is_shared');
            $table->decimal('house_allowance', 14, 2)->default(0)->after('basic_salary');
            $table->decimal('transport_allowance', 14, 2)->default(0)->after('house_allowance');
            $table->decimal('medical_allowance', 14, 2)->default(0)->after('transport_allowance');
            $table->decimal('other_allowances', 14, 2)->default(0)->after('medical_allowance');
            // KRA-mandatory insurance relief (claimed monthly)
            $table->decimal('insurance_relief', 14, 2)->default(0)->after('other_allowances');
        });

        // ── Expanded payroll_runs: Kenya statutory breakdown ──────────────────
        Schema::table('payroll_runs', function (Blueprint $table) {
            // Earnings breakdown
            $table->decimal('house_allowance', 14, 2)->default(0)->after('basic_salary');
            $table->decimal('transport_allowance', 14, 2)->default(0)->after('house_allowance');
            $table->decimal('other_earnings', 14, 2)->default(0)->after('transport_allowance');

            // Kenya statutory deductions
            $table->decimal('paye', 14, 2)->default(0)->after('gross_pay');
            $table->decimal('shif', 14, 2)->default(0)->after('paye');
            $table->decimal('nssf_tier1', 14, 2)->default(0)->after('shif');
            $table->decimal('nssf_tier2', 14, 2)->default(0)->after('nssf_tier1');
            $table->decimal('housing_levy', 14, 2)->default(0)->after('nssf_tier2');
            $table->decimal('nita', 14, 2)->default(0)->after('housing_levy');

            // Reliefs applied
            $table->decimal('personal_relief', 14, 2)->default(0)->after('nita');
            $table->decimal('insurance_relief', 14, 2)->default(0)->after('personal_relief');

            // Payable PAYE after reliefs
            $table->decimal('paye_payable', 14, 2)->default(0)->after('insurance_relief');
        });

        // ── Per-run ad-hoc allowances / deductions ────────────────────────────
        Schema::create('payroll_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->string('type');           // earning | deduction
            $table->string('label');          // e.g. "Overtime", "Salary Advance Recovery"
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_items');

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn([
                'house_allowance', 'transport_allowance', 'other_earnings',
                'paye', 'shif', 'nssf_tier1', 'nssf_tier2', 'housing_levy', 'nita',
                'personal_relief', 'insurance_relief', 'paye_payable',
            ]);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'basic_salary', 'house_allowance', 'transport_allowance',
                'medical_allowance', 'other_allowances', 'insurance_relief',
            ]);
        });
    }
};
