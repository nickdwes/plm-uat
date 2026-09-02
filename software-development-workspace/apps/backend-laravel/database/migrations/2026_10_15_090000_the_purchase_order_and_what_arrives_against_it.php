<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The purchase order — the first document in this chain the supplier ever sees
 * — and the goods receipt notes recording what actually turned up against it.
 *
 * Deliveries arrive in pieces, so a PO has many receipts and its status is
 * derived from what they add up to rather than set by hand. Each receipt
 * stamps whether it was on time at the moment it is written: a PO's expected
 * date can be renegotiated later, and a vendor's punctuality should not
 * silently improve when it is.
 *
 * `material_order_id` bridges to the older supplier-order register so a
 * procurement-sourced buy shows its real PO reference there, without the
 * Orders tab needing to know any of this exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('procurement_request_id')->constrained('procurement_requests')->cascadeOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained('procurement_quotations')->nullOnDelete();
            $table->foreignId('vendor_partner_id')->nullable()->constrained('vendor_partners')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('reference')->nullable();

            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->char('currency', 3)->default('KES');

            $table->decimal('quantity_ordered', 12, 2)->default(1);
            $table->string('payment_terms', 255)->nullable();
            $table->string('delivery_terms', 255)->nullable();
            $table->date('expected_delivery_date')->nullable();

            // draft|issued|vendor_accepted|vendor_declined|in_delivery|partially_received|received|closed|cancelled
            $table->string('status', 24)->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->text('acceptance_note')->nullable();

            $table->foreignId('material_order_id')->nullable()->constrained('project_material_orders')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('procurement_request_id');
        });

        Schema::create('goods_receipt_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('reference')->nullable();

            $table->decimal('quantity_ordered', 12, 2)->default(0);
            $table->decimal('quantity_received', 12, 2)->default(0);
            $table->decimal('variance', 12, 2)->default(0);

            $table->string('quality_status', 16)->default('accepted');   // accepted|partial|rejected
            $table->text('quality_notes')->nullable();
            // Stamped at receipt, never recomputed: a later change to the PO's
            // expected date must not rewrite a vendor's delivery record.
            $table->boolean('on_time')->nullable();

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_notes');
        Schema::dropIfExists('purchase_orders');
    }
};
