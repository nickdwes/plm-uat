<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * vendor_type already answers "what is our relationship to this vendor"
 * (supplier, distributor, partner...), which is orthogonal to "does this
 * vendor make products a training course can cover" — a distributor and an
 * OEM are not mutually exclusive, and neither classification implies the
 * other. The training catalog's Vendor (OEM Based) picker needs to filter
 * on that second question directly rather than guessing at it from
 * vendor_type or the free-text category field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_partners', function (Blueprint $table) {
            $table->boolean('is_oem_vendor')->default(false)->after('vendor_type');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_partners', function (Blueprint $table) {
            $table->dropColumn('is_oem_vendor');
        });
    }
};
