<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The procurement request grows from a flat register row into the aggregate
 * root of a finance-controlled procure-to-pay lifecycle.
 *
 * Three groups of columns, all nullable or defaulted so every existing writer
 * (the BOM order generator, the task-prerequisite fulfiller, the project
 * register) keeps working unchanged:
 *
 *  - who is asking and why: department, justification, priority;
 *  - the budget gate's snapshot at check time — what was available, what was
 *    already committed, and the verdict — so an audit can see the numbers the
 *    decision was made on, not the numbers as they are now;
 *  - the lifecycle: a stage that walks draft → … → closed, with the stamps
 *    each stage sets. The legacy `status` and `approval_status` columns stay,
 *    kept in sync as derived summaries, so nothing that reads them changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('project_id')
                ->constrained('departments')->nullOnDelete();
            $table->text('business_justification')->nullable()->after('notes');
            $table->string('priority', 12)->default('normal')->after('business_justification');

            // Budget-gate snapshot.
            $table->timestamp('budget_checked_at')->nullable()->after('priority');
            $table->decimal('budget_available_amount', 15, 2)->nullable()->after('budget_checked_at');
            $table->decimal('budget_committed_amount', 15, 2)->nullable()->after('budget_available_amount');
            $table->string('budget_result', 16)->nullable()->after('budget_committed_amount');   // ok|exceeded|no_budget|overridden

            // Lifecycle.
            $table->string('lifecycle_stage', 20)->default('draft')->after('budget_result');
            $table->timestamp('submitted_at')->nullable()->after('lifecycle_stage');
            $table->foreignId('awarded_vendor_id')->nullable()->after('submitted_at')
                ->constrained('vendor_partners')->nullOnDelete();
            $table->decimal('awarded_amount', 15, 2)->nullable()->after('awarded_vendor_id');
            $table->timestamp('closed_at')->nullable()->after('awarded_amount');
            $table->foreignId('closed_by')->nullable()->after('closed_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['organization_id', 'lifecycle_stage']);
        });
    }

    public function down(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'lifecycle_stage']);
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('awarded_vendor_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn([
                'business_justification', 'priority',
                'budget_checked_at', 'budget_available_amount', 'budget_committed_amount', 'budget_result',
                'lifecycle_stage', 'submitted_at', 'awarded_amount', 'closed_at',
            ]);
        });
    }
};
