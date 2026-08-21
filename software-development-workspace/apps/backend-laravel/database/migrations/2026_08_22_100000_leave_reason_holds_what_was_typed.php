<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A leave reason as long as the form already lets somebody write.
 *
 * The request rules accept 500 characters and the column held 255, so anybody
 * typing a careful explanation of why they need the fortnight passed
 * validation and then hit a database error — with their reason still in the
 * box and nothing on screen explaining why it would not save.
 *
 * Widened rather than the rule tightened, because the rule is the one that
 * matches how people actually use the field. Four years of imported history
 * agrees: the longest reason anybody wrote runs to 612 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->text('reason')->nullable()->change();
            $table->text('rejection_reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('reason', 255)->nullable()->change();
            $table->string('rejection_reason', 255)->nullable()->change();
        });
    }
};
