<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrects notifications.notifiable_id from uuid to bigint.
 *
 * The column was created as uuid, but every domain primary key in this schema
 * is bigint — employees, leave_requests, applications, and everything ported
 * from Plmv2. A uuid column cannot address any of them, so the polymorphic
 * link was unusable from the day it was written. The symptom is that it has
 * never been populated.
 *
 * The notification's own `id` stays uuid. Only the target reference changes.
 *
 * Safe to run: the column holds no data. This migration refuses to guess if
 * that ever stops being true.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('notifications', 'notifiable_id')) {
            return;
        }

        // A populated uuid column cannot be cast to bigint. If rows exist,
        // stop rather than destroy them — they would need mapping by hand.
        $populated = DB::table('notifications')->whereNotNull('notifiable_id')->count();

        if ($populated > 0) {
            throw new RuntimeException(
                "notifications.notifiable_id holds {$populated} value(s). "
                . 'Cast them to bigint manually before running this migration.'
            );
        }

        Schema::table('notifications', function ($table) {
            $table->dropColumn('notifiable_id');
        });

        Schema::table('notifications', function ($table) {
            $table->unsignedBigInteger('notifiable_id')->nullable()->after('notifiable_type');
            $table->index(['notifiable_type', 'notifiable_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('notifications', 'notifiable_id')) {
            return;
        }

        Schema::table('notifications', function ($table) {
            $table->dropIndex(['notifiable_type', 'notifiable_id']);
            $table->dropColumn('notifiable_id');
        });

        Schema::table('notifications', function ($table) {
            $table->uuid('notifiable_id')->nullable()->after('notifiable_type');
        });
    }
};
