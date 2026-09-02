<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Competitive sourcing: the request for quotation, who was invited, what they
 * quoted, and which quote won.
 *
 * The three-quote rule lives here rather than in code that could be forgotten.
 * A purchase that genuinely has one possible supplier is not an exception to
 * be waved through in conversation — it is a waiver recorded on the RFQ, with
 * the reason and the person who accepted it, so "we only got one quote"
 * remains answerable a year later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('procurement_request_id')->constrained('procurement_requests')->cascadeOnDelete();
            $table->string('reference')->nullable();
            $table->string('status', 16)->default('draft');   // draft|issued|closed|cancelled
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();

            // The compliance record: met by quote count, or waived on the record.
            $table->boolean('compliance_met')->default(false);
            $table->text('waiver_reason')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('procurement_request_id');
        });

        Schema::create('rfq_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignId('vendor_partner_id')->constrained('vendor_partners')->cascadeOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('status', 16)->default('invited');   // invited|declined|quoted
            $table->timestamps();

            $table->unique(['rfq_id', 'vendor_partner_id']);
        });

        Schema::create('procurement_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignId('vendor_partner_id')->constrained('vendor_partners')->cascadeOnDelete();

            $table->decimal('amount', 15, 2);
            $table->char('currency', 3)->default('KES');
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->string('warranty_terms', 255)->nullable();
            $table->string('payment_terms', 255)->nullable();
            $table->string('delivery_terms', 255)->nullable();
            $table->date('valid_until')->nullable();

            // Evaluation. Price and lead time are scored from the quotes
            // themselves; the rest are judgements a buyer records.
            $table->decimal('price_score', 5, 2)->nullable();
            $table->decimal('delivery_score', 5, 2)->nullable();
            $table->decimal('technical_score', 5, 2)->nullable();
            $table->decimal('vendor_score', 5, 2)->nullable();
            $table->decimal('total_score', 5, 2)->nullable();

            $table->boolean('is_awarded')->default(false);
            $table->text('award_justification')->nullable();   // required when the top score is not chosen
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('rfq_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_quotations');
        Schema::dropIfExists('rfq_vendors');
        Schema::dropIfExists('rfqs');
    }
};
