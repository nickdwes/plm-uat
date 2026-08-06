<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What has happened with this customer, in order.
 *
 * The legacy generated two default tasks per account — Meetings and Phone
 * Calls — which is the same idea reached from the task module. Here it is a
 * feed on the account, because the question is "when did we last speak to
 * them and about what", and that is not a task list.
 *
 * Two kinds of entry share the table. Somebody writing a note or logging a
 * call is `manual`; something the system observed — a travel request raised
 * against this customer, a contract signed — is `system`. Keeping them apart
 * matters because only manual contact should move `last_contact_at`: a
 * contract expiring is not us having spoken to anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->string('kind', 16)->default('manual');       // manual | system
            $table->string('type', 32)->default('note');         // note, call, meeting, email, …

            $table->text('body')->nullable();

            /*
             * When it happened, as distinct from when it was typed in. A call
             * logged the following morning belongs on the day of the call, or
             * "when did we last speak to them" is answered by somebody's
             * admin habits.
             */
            $table->timestamp('occurred_at');

            // The employee it is attributed to — who made the call. Nullable
            // for system entries, which nobody made.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['account_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_activities');
    }
};
