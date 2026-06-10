<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Vendor Partner Compliance
 *
 * Creates two tables:
 *  - vendor_partners          : vendor/partner companies tracked per org
 *  - vendor_required_certifications : pivot — what training items (certifications)
 *                               each vendor requires a minimum number of holders for
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Vendor partners ───────────────────────────────────────────────────
        Schema::create('vendor_partners', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')
                  ->constrained('organizations')
                  ->cascadeOnDelete();
            $table->string('name');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('website_url')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id');
        });

        // ── Required certifications (pivot) ───────────────────────────────────
        Schema::create('vendor_required_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_partner_id')
                  ->constrained('vendor_partners')
                  ->cascadeOnDelete();
            $table->foreignId('training_item_id')
                  ->constrained('training_items')
                  ->cascadeOnDelete();
            $table->unsignedSmallInteger('required_headcount')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['vendor_partner_id', 'training_item_id'], 'vrc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_required_certifications');
        Schema::dropIfExists('vendor_partners');
    }
};
