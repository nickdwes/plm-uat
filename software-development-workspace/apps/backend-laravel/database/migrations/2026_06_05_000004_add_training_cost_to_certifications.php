<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_professional_certifications', function (Blueprint $table) {
            $table->decimal('training_cost_usd', 10, 2)->nullable()->after('exam_cost_usd');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_professional_certifications', function (Blueprint $table) {
            $table->dropColumn('training_cost_usd');
        });
    }
};
