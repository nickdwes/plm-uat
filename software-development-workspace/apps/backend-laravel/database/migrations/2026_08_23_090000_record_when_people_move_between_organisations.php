<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Why somebody is where they are.
 *
 * There is no audit trail on an employee anywhere in this application — no
 * events, no observers, no history table. `updated_at` and a soft delete are
 * the whole record. That is survivable for a corrected phone number and not
 * for a move between companies, where "why is this person in Tiberbu now, and
 * who decided" is asked months later by somebody reconciling a payroll.
 *
 * The preflight is stored alongside the movement rather than thrown away. What
 * was outstanding at the moment of the move — the tickets still open, the
 * laptop still signed out, the loan still recovering — is the context that
 * makes the answer useful, and it cannot be reconstructed afterwards because
 * by then somebody has cleared it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            /*
             * Both ends are recorded even for a secondment, where the home
             * organisation does not change. "Placed into Tiberbu from
             * Konvergenz" is the sentence somebody needs; "placed into
             * Tiberbu" makes them go and look up where the person came from.
             */
            $table->foreignId('from_organization_id')->constrained('organizations');
            $table->foreignId('to_organization_id')->constrained('organizations');

            // secondment: home membership continues. transfer: it ends.
            // return: a secondment ended and they went back.
            $table->string('kind', 16);

            $table->date('effective_on');
            $table->string('reason', 500)->nullable();

            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * What was still holding them, and what the move could not carry.
             * Kept because both are arguments somebody will have later — a
             * leave balance that did not come across because the destination
             * has no leave type by that code is a conversation, not a bug.
             */
            $table->json('preflight')->nullable();
            $table->json('carried')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'effective_on']);
        });

        Schema::table('employees', function (Blueprint $table) {
            /*
             * A second reporting line is not a second employer.
             *
             * `is_shared` is drawn on the org chart as "Shared / seconded" and
             * is supposed to mean somebody works across organisations. The PLM
             * import set it from the old system's `also_answers_to` column, so
             * 49 people carry it because they answer to two managers inside
             * one company — a real and useful fact wearing the wrong label,
             * with no placement anywhere to back it up.
             */
            $table->boolean('has_dotted_line')->default(false)->after('is_shared');
        });

        // Move those 49 onto the field that means what they are.
        DB::table('employees')
            ->where('is_shared', true)
            ->whereNotIn('id', function ($q) {
                $q->select('employee_id')->from('employee_organization_map')->where('is_active', true);
            })
            ->update(['has_dotted_line' => true, 'is_shared' => false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_movements');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('has_dotted_line');
        });
    }
};
