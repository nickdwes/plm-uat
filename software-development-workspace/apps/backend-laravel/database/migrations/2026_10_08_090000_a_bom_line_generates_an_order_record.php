<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order-lifecycle record — one per approved BOM line, the spine that ties
 * a priced line to the module that actually fulfils it.
 *
 * It carries the commercial snapshot copied from the BOM line (so the figures
 * never drift if the BOM is later reopened) and a polymorphic pointer to the
 * real execution record it spawned — a procurement request, a material order,
 * a vendor engagement — so the order shows where fulfilment stands without
 * duplicating that module's data. Distinct from project_material_orders, which
 * is one of the things an order can point at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('bom_item_id')->nullable()->constrained('project_bom_items')->nullOnDelete();
            $table->string('reference')->nullable();

            $table->string('category', 32);
            $table->string('description', 300);
            $table->string('part_number', 120)->nullable();
            $table->foreignId('vendor_partner_id')->nullable()->constrained('vendor_partners')->nullOnDelete();

            // Commercial snapshot from the BOM line.
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('selling_price', 15, 2)->default(0);
            $table->decimal('order_value', 15, 2)->default(0);
            $table->decimal('margin', 15, 2)->default(0);
            $table->char('currency', 3)->default('KES');

            $table->date('expected_delivery_date')->nullable();
            $table->foreignId('phase_id')->nullable()->constrained('project_phases')->nullOnDelete();

            $table->string('status', 20)->default('approved');   // see ProjectOrder::STATUSES

            // Polymorphic pointer to the execution record in its owning module.
            $table->nullableMorphs('fulfillable');

            // Lifecycle stamps.
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Customer acceptance.
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acceptance_note', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index('bom_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_orders');
    }
};
