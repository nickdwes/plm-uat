<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real money on a project: what it is budgeted, what it actually spends, and
 * who supplies it.
 *
 * The old PLM stored budget and expense lines per currency as truncated
 * integer strings and never separated planned money from billing. Here budget
 * lines (planned) and expenses (actual spend) are distinct, per currency, on
 * proper decimals — so Budget-vs-Expenditure is an honest comparison and an
 * over-run is visible rather than hidden. Vendors reuse the vendor_partners
 * register where the supplier is a known partner, and fall back to a name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('label', 200);
            $table->char('currency', 3)->default('KES');
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('project_id');
        });

        Schema::create('project_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('label', 200);
            $table->char('currency', 3)->default('KES');
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('spent_on')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('project_id');
        });

        Schema::create('project_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_partner_id')->nullable()->constrained('vendor_partners')->nullOnDelete();
            $table->string('name', 200);
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_vendors');
        Schema::dropIfExists('project_expenses');
        Schema::dropIfExists('project_budget_lines');
    }
};
