<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project's Bill of Materials — the priced line items a won deal will deliver,
 * and the source the order-lifecycle engine generates order records from.
 *
 * Until now the only cost artifact was the opportunity's header-level estimate
 * (budget/revenue/cost totals, no lines). Nothing said "4 × switch SW-48 at
 * 1,200, sold at 1,800, category hardware". The BOM holds exactly those lines,
 * behind a header whose status gates generation: lines are edited while the BOM
 * is `draft`, and approving it stamps who signed off and lets the orders be
 * raised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 16)->default('draft');   // draft | approved | superseded
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        Schema::create('project_bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_bom_id')->constrained('project_boms')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedInteger('line_no')->default(1);

            $table->string('category', 32);   // see ProjectBomItem::CATEGORIES
            $table->string('description', 300);
            $table->string('part_number', 120)->nullable();
            $table->foreignId('vendor_partner_id')->nullable()->constrained('vendor_partners')->nullOnDelete();
            $table->string('supplier_name', 200)->nullable();

            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit', 40)->nullable();
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('selling_price', 15, 2)->default(0);
            $table->char('currency', 3)->default('KES');

            $table->date('expected_delivery_date')->nullable();
            $table->foreignId('phase_id')->nullable()->constrained('project_phases')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->index('project_bom_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_bom_items');
        Schema::dropIfExists('project_boms');
    }
};
