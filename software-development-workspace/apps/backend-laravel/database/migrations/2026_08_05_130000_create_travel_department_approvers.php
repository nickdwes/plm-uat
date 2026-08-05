<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named approvers for a department, able to act on the head's behalf.
 *
 * `departments.lead_employee_id` names one person, which is where approvals
 * stall: the head is travelling, on leave, or the post is vacant, and every
 * request behind them waits. In practice the work gets done by someone
 * phoning the head and the record says otherwise.
 *
 * A delegate decides in their own name. `travel_approvals` already records
 * `expected_approver_id` — who it was routed to — separately from
 * `decided_by_employee_id`, who actually acted, so "approved by X on behalf of
 * Y" is answerable afterwards without any extra column. That distinction is
 * the point: delegation that hides who signed is worse than no delegation.
 *
 * Scoped to a department rather than granted as a permission because the
 * question is not "may this person approve travel" but "may this person
 * approve travel for THIS team". A permission answers the first and cannot
 * express the second.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_department_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Exactly one of these is set: an approver covers a department or a
            // whole function, matching the two structural stages.
            $table->foreignId('department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->foreignId('functional_unit_id')->nullable()->constrained('org_functional_units')->cascadeOnDelete();

            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            /*
             * Whether this person may decide in the head's absence, as opposed
             * to being listed for visibility only. Kept explicit so adding
             * someone to the list is not the same act as giving them the
             * authority to sign.
             */
            $table->boolean('can_act_on_behalf')->default(true);

            // A cover arrangement for a period of leave should expire on its
            // own rather than relying on somebody remembering to remove it.
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['department_id', 'employee_id'], 'tda_department_employee_unique');
            $table->unique(['functional_unit_id', 'employee_id'], 'tda_function_employee_unique');
            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_department_approvers');
    }
};
