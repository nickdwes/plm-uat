<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The approval tracker answers "what is waiting on a decision, and who signed
 * off on the rest" — but neither the travel-request nor the procurement register
 * recorded who decided or when. A status alone can't be audited. Both gain a
 * decider and a decided-at, set when the status leaves "pending" and cleared if
 * it is ever sent back.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['project_travel_requests', 'procurement_requests'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('decided_by')->nullable()->after('approval_status')
                    ->constrained('users')->nullOnDelete();
                $t->timestamp('decided_at')->nullable()->after('decided_by');
            });
        }
    }

    public function down(): void
    {
        foreach (['project_travel_requests', 'procurement_requests'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('decided_by');
                $t->dropColumn('decided_at');
            });
        }
    }
};
