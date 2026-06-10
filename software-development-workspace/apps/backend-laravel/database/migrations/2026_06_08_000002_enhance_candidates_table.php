<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('middle_name')->nullable()->after('first_name');
            $table->boolean('is_archived')->default(false)->after('notes');
            $table->timestamp('archived_at')->nullable()->after('is_archived');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn(['middle_name', 'is_archived', 'archived_at']);
        });
    }
};
