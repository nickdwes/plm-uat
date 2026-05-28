<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add counting_method, working_days, allow_half_day, allow_scattered to leave_types.
 *
 * counting_method:
 *   business_days — only count days that fall on configured working_days AND are not public holidays.
 *   calendar_days — count every calendar day (weekends and public holidays included).
 *
 * working_days: JSON array of ISO weekday numbers (1=Mon … 7=Sun).
 *   NULL means the default Mon–Fri [1,2,3,4,5].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->string('counting_method')->default('business_days')->after('is_active');
            $table->json('working_days')->nullable()->after('counting_method');
            $table->boolean('allow_half_day')->default(false)->after('working_days');
            $table->boolean('allow_scattered')->default(false)->after('allow_half_day');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['counting_method', 'working_days', 'allow_half_day', 'allow_scattered']);
        });
    }
};
