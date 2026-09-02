<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The supplier's invoice, and the three-way match that decides whether it gets
 * paid.
 *
 * The match asks three questions of every invoice: is there an order behind it,
 * did the goods actually arrive, and is the price the one that was agreed. Each
 * answer is stored rather than recomputed on read, because an invoice approved
 * for payment must keep the reasons it was approved even after the order,
 * receipts or tolerance later change.
 *
 * Payment sits on the invoice rather than in its own table: one invoice, one
 * settlement is the shape of every purchase here, and a payments table would be
 * a join to carry a single date and reference. Partial settlement is still
 * expressible through `paid_amount`, and if instalments ever become real the
 * table can arrive then without rewriting this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('vendor_partner_id')->nullable()->constrained('vendor_partners')->nullOnDelete();
            $table->string('reference')->nullable();          // ours (SINV-)
            $table->string('invoice_number', 120)->nullable(); // theirs

            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();

            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->char('currency', 3)->default('KES');

            // The three-way match, each leg answered and kept.
            $table->boolean('matched_po')->default(false);
            $table->boolean('matched_grn')->default(false);
            $table->boolean('matched_price')->default(false);
            $table->string('match_status', 16)->default('pending');   // pending|matched|exception
            $table->text('exception_reason')->nullable();
            $table->foreignId('exception_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('override_justification')->nullable();

            // pending|matched|exception|approved_for_payment|paid|cancelled
            $table->string('status', 24)->default('pending');
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 120)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
    }
};
