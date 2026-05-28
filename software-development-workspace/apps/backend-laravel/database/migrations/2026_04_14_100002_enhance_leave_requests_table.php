<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add half-day and scattered-date support to leave_requests.
 *
 * is_half_day:       true when the employee applies for exactly half a day.
 * half_day_period:   'morning' or 'afternoon' (only meaningful when is_half_day = true).
 * scattered_dates:   JSON array of date strings (YYYY-MM-DD) for non-consecutive leave.
 *                    When set, from_date/to_date hold the earliest/latest date of the set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->boolean('is_half_day')->default(false)->after('days_requested');
            $table->string('half_day_period')->nullable()->after('is_half_day');
            $table->json('scattered_dates')->nullable()->after('half_day_period');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['is_half_day', 'half_day_period', 'scattered_dates']);
        });
    }
};
