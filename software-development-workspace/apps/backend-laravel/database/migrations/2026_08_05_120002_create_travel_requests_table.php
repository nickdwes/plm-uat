<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel requests.
 *
 * ONE TENANT OWNS THE REQUEST END TO END.
 *
 * `organization_id` is set when the request is raised and never changes. It
 * decides whose budget pays, whose rate card the costing uses, whose approvers
 * act on it, and who can see it. An employee who belongs to several
 * organisations raises the request in one of them, and that choice is the
 * commitment — not their primary organisation, which is the intuitive but
 * wrong answer for anyone seconded.
 *
 * Every association below therefore has to resolve inside the same
 * organisation, and none of that is given for free by the tenancy scope:
 *
 *   employee_id      must be a member of this organisation
 *   department_id    their department in THIS organisation, taken from
 *                    employee_organization_map, not their primary department
 *   account_id       this organisation's customer
 *   destination      this organisation's destination list
 *   approvers        members of this organisation
 *
 * Foreign keys enforce that the referenced rows exist; they cannot enforce
 * that they belong to the same tenant, because tenancy is a column rather than
 * a schema boundary. The service and request validation carry that, and the
 * tests fix it in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('reference')->nullable();          // human-facing, e.g. TR-2026-0001

            // The traveller, and the department they sit in within THIS
            // organisation. Denormalised deliberately: it decides the first
            // approver, and it must not change under a request already in
            // flight because someone moved department.
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('functional_unit_id')->nullable()->constrained('org_functional_units')->nullOnDelete();

            // Who the trip is for. 94 of 102 legacy requests named a customer.
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->foreignId('travel_destination_id')->nullable()->constrained('travel_destinations')->nullOnDelete();
            $table->string('travel_mode')->nullable();        // road | air | rail | sea
            $table->date('departure_date');
            $table->date('return_date');
            $table->unsignedSmallInteger('days')->default(1);

            $table->text('purpose')->nullable();
            $table->text('business_impact')->nullable();

            // Totals, in the organisation's currency for this request.
            $table->char('currency', 3)->default('KES');
            $table->decimal('total_claimed', 14, 2)->default(0);
            $table->decimal('total_approved', 14, 2)->nullable();
            $table->decimal('advance_required', 14, 2)->nullable();

            $table->string('status')->default('draft');
            // draft | submitted | in_approval | approved | rejected | cancelled | disbursed

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'employee_id', 'status']);
            $table->index(['organization_id', 'departure_date']);
        });

        /*
         * The costed lines of a request.
         *
         * `rate_applied` is the rate resolved at submission and is never
         * recalculated. Without it, correcting the rate card silently rewrites
         * the value of every historical request that used it — a change nobody
         * sees until someone audits a payment.
         */
        Schema::create('travel_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_id')->constrained('travel_requests')->cascadeOnDelete();
            $table->foreignId('travel_expense_category_id')->constrained('travel_expense_categories')->cascadeOnDelete();

            $table->decimal('quantity', 8, 2)->default(1);    // days, or 1 for per-trip
            $table->decimal('rate_applied', 14, 2);           // snapshot
            $table->string('unit')->default('per_day');
            $table->decimal('amount_claimed', 14, 2);
            // Finance can approve less than was asked. The legacy schema had
            // no way to express that.
            $table->decimal('amount_approved', 14, 2)->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('travel_request_id');
        });

        /*
         * The approval chain, as rows.
         *
         * Created at submission from the organisation's active stages, filtered
         * by the request total against each stage's threshold. Snapshotted the
         * same way the rate is: editing the policy mid-flight must not add an
         * approver to an already-approved request, nor drop one before payment.
         */
        Schema::create('travel_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_id')->constrained('travel_requests')->cascadeOnDelete();

            $table->string('stage');                          // department_head | function_head | finance | executive
            $table->unsignedSmallInteger('sequence');

            // Who is expected to act, resolved at submission. Nullable because
            // a stage can be role-based (finance, executive) with no single
            // named person until someone acts on it.
            $table->foreignId('expected_approver_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('decided_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('status')->default('pending');     // pending | approved | rejected | skipped
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->unique(['travel_request_id', 'stage']);
            $table->index(['travel_request_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_approvals');
        Schema::dropIfExists('travel_request_items');
        Schema::dropIfExists('travel_requests');
    }
};
