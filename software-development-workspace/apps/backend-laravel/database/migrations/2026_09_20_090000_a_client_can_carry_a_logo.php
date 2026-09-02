<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client can carry a logo.
 *
 * The accounts table dropped the legacy image columns when no logo requirement
 * had been stated. There is one now — a small mark beside the name on the list
 * and the record. Stored on the public disk like the organisation's own logo,
 * so `logo_path` mirrors that column exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'logo_path')) {
                $table->string('logo_path')->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts', 'logo_path')) {
                $table->dropColumn('logo_path');
            }
        });
    }
};
