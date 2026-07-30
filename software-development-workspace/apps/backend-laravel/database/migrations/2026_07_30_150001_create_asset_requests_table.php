<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requests for equipment, ported from Plmv2.
 *
 * The source keyed requester and approver to users; both point at employees
 * here, matching leave_requests.approved_by and keeping every people column in
 * this system on one graph.
 *
 * request_number is unique per organization, not globally — see the note on
 * asset_tag in the assets migration.
 *
 * Approval is single-step, as in the source. If this needs a chain later, the
 * shape to copy is the one the legacy system used for travel (see
 * docs/plm-borrowed-patterns.md), not a second status column bolted on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('request_number');

            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->string('category');
            $table->string('item')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->text('reason');
            $table->string('urgency')->default('medium');   // low | medium | high
            $table->string('status')->default('pending');   // pending | approved | rejected | fulfilled

            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note')->nullable();

            // Set once the request is satisfied by a specific asset.
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'request_number']);
            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_requests');
    }
};
