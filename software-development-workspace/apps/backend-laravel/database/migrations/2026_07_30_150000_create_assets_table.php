<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset register, ported from Plmv2.
 *
 * Three deliberate departures from the source schema:
 *
 *   organization_id   Plmv2 has no tenancy at all. Every table here is
 *                     org-scoped, matching the rest of this system.
 *
 *   assigned_to       points at employees, not users. Who holds a laptop is a
 *                     people fact, not a login fact — and it must remain
 *                     answerable for someone who has no account.
 *
 *   department_id     was a free-text string. This system has a departments
 *                     table, and an unconstrained string cannot be reported on.
 *
 * asset_tag is unique per organization rather than globally. A global unique
 * would let one tenant's tag collide with another's, which is a cross-tenant
 * failure surfacing as a validation error on unrelated data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // ── Identity ──────────────────────────────────────────────────
            $table->string('name');
            $table->string('asset_tag');
            $table->string('category');          // laptop | desktop | monitor | phone | tablet | software | peripheral | other
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();

            // ── Lifecycle ─────────────────────────────────────────────────
            $table->string('status')->default('available');   // available | assigned | maintenance | retired
            $table->string('lifecycle_stage')->nullable();    // procured | in_stock | assigned | reassigned | under_repair | retired
            $table->string('condition')->nullable();          // excellent | good | fair | poor
            $table->date('procured_date')->nullable();
            $table->date('in_stock_date')->nullable();
            $table->date('next_maintenance')->nullable();

            // ── Assignment ────────────────────────────────────────────────
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('assigned_date')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('location')->nullable();

            // Handover overlaps roster_handover_reports, which covers shift
            // handover rather than custody of equipment. Kept separate for now;
            // reconciling them is a decision, not a port.
            $table->boolean('handover_required')->default(false);
            $table->timestamp('handover_signed_at')->nullable();

            // ── Procurement ───────────────────────────────────────────────
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_value', 15, 2)->nullable();
            $table->string('vendor')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('warranty_period')->nullable();
            $table->date('warranty_expiry_date')->nullable();

            // ── Hardware specification ────────────────────────────────────
            $table->string('processor')->nullable();
            $table->string('ram')->nullable();
            $table->string('storage')->nullable();
            $table->string('operating_system')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'asset_tag']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'category']);
            $table->index('assigned_to');
            $table->index('warranty_expiry_date');
            $table->index('next_maintenance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
