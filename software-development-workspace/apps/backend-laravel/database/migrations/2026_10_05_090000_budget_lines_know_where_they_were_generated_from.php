<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Budget generation builds planned lines from what is already costed — travel
 * requests and procurement items each carry an estimated cost. To regenerate
 * without duplicating, and to leave hand-entered lines untouched, each line
 * remembers where it came from: 'manual' (default, never overwritten) or the
 * register + record id it was generated from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_budget_lines', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('category');
            $table->unsignedBigInteger('source_ref')->nullable()->after('source');
            $table->index(['project_id', 'source', 'source_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('project_budget_lines', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'source', 'source_ref']);
            $table->dropColumn(['source', 'source_ref']);
        });
    }
};
