<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the lead's Company tab shows about a customer beyond who they are.
 *
 * Organisation size is not among them. The screens show a band — "250–500
 * Employees" — and we already hold the headcount, so the band is derived. A
 * second column would let a customer be 300 employees and "1000+" at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Country is already an ISO code; the city was buried inside
            // physical_location, which is a street address and cannot be
            // grouped on.
            $table->string('city')->nullable()->after('country');

            // What they already run. It is the opening question of most deals
            // here — whether we are replacing something or adding to it.
            $table->string('existing_products')->nullable()->after('fund_source');

            // What they typically spend, as a band rather than a figure: it is
            // an expectation, and a precise number would be read as a quote.
            $table->string('budget_range', 64)->nullable()->after('existing_products');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['city', 'existing_products', 'budget_range']);
        });
    }
};
