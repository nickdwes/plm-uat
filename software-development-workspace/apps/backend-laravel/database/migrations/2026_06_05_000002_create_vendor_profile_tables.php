<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor Module — Products, Incentives/NFRs, and Professional Certifications
 *
 * vendor_products                    : products & solutions offered by vendor
 * vendor_incentives                  : rebates, NFRs, SPIFFs, MDF, deal-reg, co-op
 * vendor_professional_certifications : training & certification programs offered by vendor
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Products & Solutions ──────────────────────────────────────────────
        Schema::create('vendor_products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_partner_id')
                  ->constrained('vendor_partners')
                  ->cascadeOnDelete();
            $table->string('name');
            $table->string('sku', 100)->nullable();
            // product | solution | service | platform
            $table->string('product_type', 30)->default('product');
            $table->string('technology_space', 100);       // e.g. cybersecurity
            $table->string('technology_subcategory', 100)->nullable(); // e.g. edr
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('vendor_partner_id');
        });

        // ── Incentives & NFRs ─────────────────────────────────────────────────
        Schema::create('vendor_incentives', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_partner_id')
                  ->constrained('vendor_partners')
                  ->cascadeOnDelete();
            $table->string('name');
            // rebate | nfr | spiff | mdf | deal_registration | co_op | other
            $table->string('incentive_type', 30)->default('other');
            $table->text('description')->nullable();
            $table->decimal('value', 10, 4)->nullable();
            // percentage | fixed | per_unit | variable
            $table->string('value_type', 20)->default('percentage');
            $table->string('currency', 10)->nullable();
            $table->text('eligibility_criteria')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('vendor_partner_id');
        });

        // ── Professional Certifications & Training ────────────────────────────
        Schema::create('vendor_professional_certifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_partner_id')
                  ->constrained('vendor_partners')
                  ->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50)->nullable();        // e.g. CCNA, AWS-SAA
            // associate | professional | expert | specialist | practitioner | foundation
            $table->string('level', 30)->default('associate');
            $table->string('technology_space', 100);
            $table->string('technology_subcategory', 100)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('validity_months')->nullable();
            $table->decimal('exam_cost_usd', 10, 2)->nullable();
            $table->text('prerequisites')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('vendor_partner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_professional_certifications');
        Schema::dropIfExists('vendor_incentives');
        Schema::dropIfExists('vendor_products');
    }
};
