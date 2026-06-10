<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link vendor professional certifications to training catalog items.
 * When set, the training_item_id enables this cert to be selected
 * as a compliance requirement in the Vendor Compliance module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_professional_certifications', function (Blueprint $table) {
            $table->unsignedBigInteger('training_item_id')->nullable()->after('vendor_partner_id');
            $table->foreign('training_item_id')
                  ->references('id')
                  ->on('training_items')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_professional_certifications', function (Blueprint $table) {
            $table->dropForeign(['training_item_id']);
            $table->dropColumn('training_item_id');
        });
    }
};
