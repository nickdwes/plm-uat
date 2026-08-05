<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people who work an account, beyond the one who owns it.
 *
 * `accounts.account_manager_id` names a single owner, which is who the
 * relationship belongs to and whose book it counts towards. It is not who
 * works it: a customer is usually served by an engineer, a support contact and
 * a manager, and asking "who deals with this client" has no answer today
 * unless that person happens to be the owner.
 *
 * Ported from Plmv2's `account_user`, repointed to employees rather than
 * users — consistent with the rest of this system, and it keeps the answer
 * meaningful for somebody who has never logged in.
 *
 * The owner is not duplicated here. One place names them, so the two cannot
 * disagree about who is accountable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // What they do on this account — engineer, support, commercial.
            // Free text rather than a lookup: it varies by customer and is
            // read by people, not grouped on.
            $table->string('role')->nullable();

            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'employee_id']);
            $table->index(['organization_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_team');
    }
};
