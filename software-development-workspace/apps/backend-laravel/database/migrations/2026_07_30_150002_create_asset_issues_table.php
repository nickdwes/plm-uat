<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faults raised against an asset, ported from Plmv2.
 *
 * Two people columns with different meanings, both on employees:
 *   reported_by   who raised it
 *   assigned_to   who is fixing it (IT, facilities)
 *
 * The source named the first `user_id`, which read as "the owner" and made the
 * two indistinguishable at a glance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('ticket_number');

            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('reported_by')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('issue_type');                    // hardware | software | access | other
            $table->string('priority')->default('medium');   // low | medium | high | critical
            $table->text('description');
            $table->string('status')->default('reported');   // reported | in_progress | resolved
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'ticket_number']);
            $table->index(['organization_id', 'status']);
            $table->index(['asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_issues');
    }
};
