<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a payslip is locked, and with what.
 *
 * Its own table rather than another `payroll_config_statutory_rates` row: those
 * are rates in force on a date, versioned so a past period can be recomputed
 * under the rules it was run beneath. A password policy is nothing like that —
 * it is a current setting, it holds text rather than a decimal, and dating it
 * would imply old payslips reopen under old rules, which they do not.
 *
 * ── There is no "off" ──
 *
 * A payslip carries somebody's salary, their tax number and their bank details.
 * It is emailed to a personal address and forwarded from there. So the question
 * this table answers is what the password *is*, never whether there is one, and
 * the service refuses to emit a PDF it cannot lock rather than falling back to
 * an open one.
 *
 * ── Why a fallback exists ──
 *
 * The password is derived from something the employee already knows — their
 * national ID, on the designs. Records are incomplete in practice, and an
 * employee with no ID on file would otherwise never receive a payslip at all.
 * A second source is tried, and if neither resolves the run stops and names the
 * person, which is a problem somebody can fix in a minute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_document_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * national_id | employee_number | kra_pin | date_of_birth
             *
             * Whatever it is, it must be something the recipient can produce
             * without being told it — a password sent in the same email as the
             * attachment protects nobody.
             */
            $table->string('password_source', 32)->default('national_id');
            $table->string('password_fallback_source', 32)->nullable()->default('employee_number');

            /*
             * Bolted on either end of the derived value. A leading "KNS-" makes
             * a bare ID number look deliberate, and some organisations want the
             * year so the same document reissued next year differs.
             */
            $table->string('password_prefix', 16)->nullable();
            $table->string('password_suffix', 16)->nullable();

            // Upper, lower, or as recorded. People type what they were told.
            $table->string('password_case', 16)->default('upper');

            /*
             * The owner password governs editing rather than opening, and is
             * never shown to anybody. Generated per organisation so a payslip
             * cannot be silently altered and passed on.
             */
            $table->text('owner_password')->nullable();

            /*
             * What the recipient may do once it is open. Printing and copying
             * are separable: a payslip goes to a landlord or a bank, so
             * printing is usually wanted and extraction usually is not.
             */
            $table->boolean('allow_print')->default(true);
            $table->boolean('allow_copy')->default(false);

            $table->timestamps();
        });

        /*
         * Every organisation starts protected. Nothing has to be switched on
         * for the first payslip to be safe, which is the only default that
         * survives somebody being in a hurry.
         */
        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            DB::table('payroll_document_settings')->insert([
                'organization_id'          => $organizationId,
                'password_source'          => 'national_id',
                'password_fallback_source' => 'employee_number',
                'password_case'            => 'upper',
                'owner_password'           => encrypt(bin2hex(random_bytes(16))),
                'allow_print'              => true,
                'allow_copy'               => false,
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_document_settings');
    }
};
