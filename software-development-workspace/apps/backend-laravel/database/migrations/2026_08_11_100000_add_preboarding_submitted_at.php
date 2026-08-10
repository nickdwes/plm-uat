<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the pre-hire finished filling everything in.
 *
 * Sections could be submitted one at a time and the record itself never moved
 * past `in_progress`, so there was no moment to record and nobody to tell. The
 * onboarding queue needs it for two different questions: which submissions are
 * waiting on HR, and how long people are being left waiting after they have
 * done their part.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preboarding_records', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('status');

            // The queue's two questions: what is outstanding, and what has
            // gone past its start date without being finished.
            $table->index(['organization_id', 'status']);
            $table->index(['expected_start_date']);
        });
    }

    public function down(): void
    {
        Schema::table('preboarding_records', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropIndex(['expected_start_date']);
            $table->dropColumn('submitted_at');
        });
    }
};
