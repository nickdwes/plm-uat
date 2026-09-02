<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a supplier is actually paid.
 *
 * The vendor record grew out of partner accreditation and knows what a supplier
 * sells and certifies, but never where the money goes or when it falls due —
 * so every remittance detail lived in somebody's email. Payment terms also give
 * an invoice its due date without anyone typing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_partners', function (Blueprint $table) {
            $table->string('bank_name', 160)->nullable()->after('notes');
            $table->string('bank_branch', 160)->nullable()->after('bank_name');
            $table->string('account_name', 160)->nullable()->after('bank_branch');
            $table->string('account_number', 64)->nullable()->after('account_name');
            $table->string('swift_code', 32)->nullable()->after('account_number');
            $table->unsignedInteger('payment_terms_days')->nullable()->after('swift_code');
            $table->decimal('credit_limit', 15, 2)->nullable()->after('payment_terms_days');
            $table->char('credit_limit_currency', 3)->nullable()->after('credit_limit');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_partners', function (Blueprint $table) {
            $table->dropColumn([
                'bank_name', 'bank_branch', 'account_name', 'account_number',
                'swift_code', 'payment_terms_days', 'credit_limit', 'credit_limit_currency',
            ]);
        });
    }
};
