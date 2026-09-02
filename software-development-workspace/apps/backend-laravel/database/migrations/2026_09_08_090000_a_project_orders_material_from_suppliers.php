<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier material ordering & delivery — the old PLM's ordering_and_delivery
 * set, modernised.
 *
 * A project raises material orders against a supplier; each order carries an
 * LPO, a tracker of checkpoints, delivery updates from the supplier, and the
 * logistics contacts handling it. The legacy split those across four tables and
 * a dead 'Pending' status column; this keeps the four-table shape (it is the
 * right one) with a real status lifecycle and the supplier wired to
 * vendor_partners rather than free text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_material_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40)->nullable();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('pending'); // pending|ordered|in_transit|delivered|cancelled
            $table->date('due_date')->nullable();

            // The supplier — a known partner where possible, a name otherwise.
            $table->foreignId('vendor_partner_id')->nullable()->constrained('vendor_partners')->nullOnDelete();
            $table->string('supplier_name', 200)->nullable();
            $table->string('lpo_reference', 120)->nullable(); // Local Purchase Order no.

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'status']);
        });

        // Tracker — the checkpoints an order passes through.
        Schema::create('project_material_order_trackers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_order_id')->constrained('project_material_orders')->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('details')->nullable();
            $table->date('happened_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('material_order_id');
        });

        // Delivery updates — the supplier's latest word, and the plan status.
        Schema::create('project_material_order_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_order_id')->constrained('project_material_orders')->cascadeOnDelete();
            $table->text('latest_supplier_update')->nullable();
            $table->text('delivery_plan_status')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('material_order_id');
        });

        // Logistics contacts — who is moving it.
        Schema::create('project_material_order_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_order_id')->constrained('project_material_orders')->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('email', 200)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('address', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('material_order_id');
        });

        // Order documents (the LPO, delivery notes) reuse the encrypted store.
        Schema::table('project_attachments', function (Blueprint $table) {
            $table->foreignId('material_order_id')->nullable()->after('milestone_id')
                  ->constrained('project_material_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_order_id');
        });
        Schema::dropIfExists('project_material_order_contacts');
        Schema::dropIfExists('project_material_order_updates');
        Schema::dropIfExists('project_material_order_trackers');
        Schema::dropIfExists('project_material_orders');
    }
};
