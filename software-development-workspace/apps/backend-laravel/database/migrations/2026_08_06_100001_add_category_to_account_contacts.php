<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of contact this person is.
 *
 * The screens group contacts as Primary, Secondary, HR, Technical and so on.
 * `is_primary` could only say one thing, and it said it about one person —
 * everybody else was an undifferentiated list, so "who do we call about an
 * invoice" had no answer.
 *
 * is_primary is kept rather than dropped. It is the invariant the service
 * already enforces (exactly one primary), and the useful guarantee is that
 * there is always somebody to call; category derives it rather than replacing
 * it, so the two can never disagree about who that is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_contacts', function (Blueprint $table) {
            $table->string('category', 32)->default('other')->after('account_id');
            $table->index(['account_id', 'category']);
        });

        // Whoever was already flagged primary keeps that standing.
        DB::table('account_contacts')->where('is_primary', true)->update(['category' => 'primary']);
        DB::table('account_contacts')->where('is_primary', false)->update(['category' => 'secondary']);
    }

    public function down(): void
    {
        Schema::table('account_contacts', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'category']);
            $table->dropColumn('category');
        });
    }
};
