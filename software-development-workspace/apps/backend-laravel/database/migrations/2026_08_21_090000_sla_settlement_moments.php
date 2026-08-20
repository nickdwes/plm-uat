<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When each clock reached an outcome.
 *
 * Compliance reporting needs to put every ticket in exactly one month, and none
 * of the existing columns can do it. `first_response_at` and `resolved_at` say
 * when somebody acted, but a ticket that blew its target and was never touched
 * has neither — so the worst tickets on the desk fell out of the figures
 * entirely, and the longer one was ignored the longer it stayed out. And
 * `resolved_at` is cleared when a ticket is reopened, which is correct for the
 * ticket and wrong for the report: last month's resolution figure changed
 * whenever somebody reopened something.
 *
 * These two are stamped once, at the moment the outcome became known — an
 * answer, a fix, or the target passing with neither — and never revised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_ticket_slas', function (Blueprint $table) {
            $table->timestamp('response_settled_at')->nullable()->after('response_breached_at');
            $table->timestamp('resolution_settled_at')->nullable()->after('resolution_breached_at');

            $table->index('response_settled_at');
            $table->index('resolution_settled_at');
        });

        /*
         * Existing clocks keep their history. A breach already recorded is the
         * moment it settled; otherwise it settled when the ticket was answered
         * or resolved, and a clock still running settles to null — which is the
         * correct answer for a ticket whose outcome nobody knows yet.
         */
        DB::statement(<<<'SQL'
            UPDATE support_ticket_slas s
               SET response_settled_at   = COALESCE(s.response_breached_at, t.first_response_at),
                   resolution_settled_at = COALESCE(s.resolution_breached_at, t.resolved_at)
              FROM support_tickets t
             WHERE t.id = s.support_ticket_id
        SQL);
    }

    public function down(): void
    {
        Schema::table('support_ticket_slas', function (Blueprint $table) {
            $table->dropColumn(['response_settled_at', 'resolution_settled_at']);
        });
    }
};
