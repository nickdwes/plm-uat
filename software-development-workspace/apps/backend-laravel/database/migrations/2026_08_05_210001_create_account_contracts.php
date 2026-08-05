<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What we have actually agreed to do for a customer, and until when.
 *
 * Ported from Plmv2's `contracts`. Named `account_contracts` rather than
 * `contracts` because this system is an HRMS first: an unqualified `contracts`
 * will be read as an employment contract by the next person to need one.
 *
 * Plmv2's `project_id` is dropped — projects do not exist here yet, and a
 * foreign key to nothing is worse than no column. It comes back with Phase E.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->string('name');
            $table->string('contract_number')->nullable();
            $table->string('type')->nullable();          // support, licence, SLA, framework
            $table->decimal('contract_value', 15, 2)->nullable();

            // Both required: a contract with no end never shows up as expiring,
            // which is the one question this table exists to answer.
            $table->date('start_date');
            $table->date('end_date');

            $table->string('status')->default('active');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Drives "what expires in the next 90 days" without a scan.
            $table->index(['organization_id', 'end_date']);
            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_contracts');
    }
};
