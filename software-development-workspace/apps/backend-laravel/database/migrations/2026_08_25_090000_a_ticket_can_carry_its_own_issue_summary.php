<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the agent decided the issue actually was, in their own words.
 *
 * `body` is the customer's raw message — often literal Word/Outlook HTML, not
 * something anyone should read as prose, and not something anyone should have
 * to summarise from scratch again when this ticket becomes an RCA line item.
 * This is that summary, written once and carried with the ticket rather than
 * re-derived from the thread every time it is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->text('issue_summary')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('issue_summary');
        });
    }
};
