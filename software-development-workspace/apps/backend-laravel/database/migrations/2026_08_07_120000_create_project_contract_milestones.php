<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer agreed to pay for, and against which piece of work.
 *
 * The legacy's `mdl_projects_contract_milestones` — 82 rows, every one attached
 * to a project and a plan phase. Since a plan phase is a task here, they land on
 * tasks unchanged.
 *
 * Three things the legacy data settles:
 *
 *   Currency is real. 67 of the 82 were KES and 15 USD, so a single implicit
 *   currency would silently misstate nearly a fifth of them. This is the first
 *   place in the system to carry one, and it is worth knowing that contract
 *   values, estimates and bids elsewhere still assume one — noted rather than
 *   fixed here, because changing them is its own piece of work.
 *
 *   Its status was 'Pending' on all 82 rows, like the opportunity status and
 *   the research status before it. A column with one value answers nothing, so
 *   this one gets a set that means something.
 *
 *   `payment_details` and `payment_attachment` were never filled in on any row.
 *   The intent was clearly to evidence payment and it never happened. Rather
 *   than carry two empty columns, the dates that actually answer "have we been
 *   paid" are here instead, and evidence attaches through the project's own
 *   documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_contract_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            /*
             * The work it is paid against. Nullable so a milestone can be
             * recorded from the contract before anybody has planned the work,
             * which is the order these usually arrive in — but every one of the
             * legacy's 82 had one, so a milestone left unattached is a gap
             * worth surfacing rather than a normal state.
             */
            $table->foreignId('project_task_id')->nullable()->constrained('project_tasks')->nullOnDelete();

            $table->string('reference', 32)->nullable();
            $table->string('name');
            $table->text('description')->nullable();

            // Three-letter ISO. Defaulted to the organisation's own currency by
            // the application rather than here, so a tenant billing in USD does
            // not have to correct every row.
            $table->char('currency', 3);
            $table->decimal('value', 15, 2);

            $table->string('status', 24)->default('pending');

            // What actually answers "have we been paid", which the legacy's
            // free-text payment_details never did on a single row.
            $table->date('due_on')->nullable();
            $table->timestamp('invoiced_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('invoice_reference')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['project_id', 'status']);
            // Drives "what is due and unpaid" without a scan.
            $table->index(['organization_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_contract_milestones');
    }
};
