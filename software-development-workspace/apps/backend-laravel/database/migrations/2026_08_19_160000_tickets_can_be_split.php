<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One message, two problems.
 *
 * "The laptop is slow, and while I have you, we need a mailbox for the new
 * starter." That is two pieces of work with two owners and two clocks, and the
 * old module had one answer: work them both on one ticket and close it when the
 * slower one finished — which is why its resolution times were what they were.
 *
 * ── Not a parent and child ──
 *
 * A merge says forty reports are one fault. A split says one report was two
 * requests. They are different relationships and share no meaning, so the split
 * gets its own column rather than being squeezed into parent_ticket_id — where
 * it would show up in every major-incident count and broadcast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('split_from_ticket_id')->nullable()->after('parent_ticket_id')
                ->constrained('support_tickets')->nullOnDelete();

            $table->index(['organization_id', 'split_from_ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign(['split_from_ticket_id']);
            $table->dropColumn('split_from_ticket_id');
        });
    }
};
