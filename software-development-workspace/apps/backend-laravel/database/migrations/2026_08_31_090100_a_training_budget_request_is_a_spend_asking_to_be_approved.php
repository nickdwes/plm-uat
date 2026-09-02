<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two shapes of the same request, not two features: "one course, several
 * people" and "one person, several courses" both reduce to a list of
 * (employee, training, cost, achieve-by) lines with a total at the bottom.
 * `mode` records which shape was chosen so the form can be re-opened the
 * way it was built, but the approval logic below never has to branch on it —
 * it only ever looks at the total and the line items.
 *
 * Approval is two fixed stages, not a configurable chain: the requester's
 * own manager first (resolved once at submission, same as
 * training_assignments.manager_id — a later reporting-line change must not
 * rewrite who is being asked to approve *this* spend), then Finance only if
 * the total clears the organisation's threshold
 * (training_budget_settings.approval_threshold_amount). No manager on file
 * skips straight to the threshold check rather than blocking forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_budget_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('mode', 20); // by_training | by_employee
            $table->foreignId('training_item_id')->nullable()->constrained('training_items')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->text('payment_instructions');
            $table->decimal('total_amount', 12, 2);
            $table->char('currency', 3)->default('KES');

            $table->string('status', 20)->default('pending_manager');
            // pending_manager | pending_finance | approved | rejected

            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manager_decided_at')->nullable();
            $table->text('manager_notes')->nullable();

            $table->foreignId('finance_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finance_decided_at')->nullable();
            $table->text('finance_notes')->nullable();

            $table->string('rejected_stage', 20)->nullable(); // manager | finance
            $table->text('rejection_reason')->nullable();

            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('training_budget_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_budget_request_id')->constrained('training_budget_requests')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('training_item_id')->constrained('training_items')->cascadeOnDelete();
            $table->decimal('cost', 12, 2);
            $table->date('achieve_by_date')->nullable();
            $table->timestamps();

            $table->unique(
                ['training_budget_request_id', 'employee_id', 'training_item_id'],
                'uniq_budget_request_line'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_budget_request_items');
        Schema::dropIfExists('training_budget_requests');
    }
};
