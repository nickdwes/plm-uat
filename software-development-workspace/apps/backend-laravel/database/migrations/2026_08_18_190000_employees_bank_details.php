<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where somebody's pay actually goes.
 *
 * The payroll could compute a net pay and could not say where to send it, so
 * paying people meant reading figures off a screen and typing them into a bank
 * portal — which is where a transposed digit sends a month's salary to a
 * stranger.
 *
 * Held on the employee rather than the run: an account number is a fact about a
 * person that outlives any one month, and copying it onto every run would mean
 * correcting it in a hundred places.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // bank | cash | cheque | mobile_money
            $table->string('payment_method', 20)->default('bank')->after('basic_salary');

            $table->string('bank_name', 120)->nullable()->after('payment_method');

            /*
             * The bank's own code and the branch's. Banks reject a bulk file on
             * either being wrong far more often than on a bad account number,
             * because the account is at least checked against a name.
             */
            $table->string('bank_code', 20)->nullable()->after('bank_name');
            $table->string('branch_code', 20)->nullable()->after('bank_code');

            $table->string('bank_account_number', 40)->nullable()->after('branch_code');

            /*
             * The name on the account, which is not always the name on the
             * employment record — a married name, an initial, a middle name the
             * bank has and payroll does not. Stored separately because a
             * mismatch is what the bank bounces on.
             */
            $table->string('bank_account_name', 160)->nullable()->after('bank_account_number');

            // For mobile money, which is a normal way to be paid here.
            $table->string('mobile_money_number', 30)->nullable()->after('bank_account_name');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method', 'bank_name', 'bank_code', 'branch_code',
                'bank_account_number', 'bank_account_name', 'mobile_money_number',
            ]);
        });
    }
};
