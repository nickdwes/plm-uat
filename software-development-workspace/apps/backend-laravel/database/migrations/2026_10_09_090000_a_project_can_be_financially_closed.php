<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financial closure — the capstone of the order lifecycle. A project is closed
 * only once every order it generated has been accepted (or cancelled), its
 * billing milestones are settled, and it is not over budget. The stamp is kept
 * apart from the delivery status: a project can be delivered operationally long
 * before Finance signs it off, and closing is Finance's act, not the PM's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('financially_closed_at')->nullable()->after('delivered_on');
            $table->foreignId('financially_closed_by')->nullable()->after('financially_closed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financially_closed_by');
            $table->dropColumn('financially_closed_at');
        });
    }
};
