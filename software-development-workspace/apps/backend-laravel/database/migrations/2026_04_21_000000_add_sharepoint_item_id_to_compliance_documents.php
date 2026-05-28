<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_compliance_documents', function (Blueprint $table) {
            $table->string('sharepoint_item_id')->nullable()->after('file_disk_path');
        });
    }

    public function down(): void
    {
        Schema::table('org_compliance_documents', function (Blueprint $table) {
            $table->dropColumn('sharepoint_item_id');
        });
    }
};
