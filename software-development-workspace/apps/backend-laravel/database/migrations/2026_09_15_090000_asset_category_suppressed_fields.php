<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a category switch off the standard fields that don't apply to it.
 *
 * A room is a physical asset but has no brand, model, serial or warranty — the
 * hardware and finance fields every other physical asset shows are just noise
 * on it. `suppressed_fields` lists the standard/class-level field keys a
 * category hides, so a place-like asset shows only what it actually has.
 *
 * Additive: the column is nullable, and the room defaults are set only where
 * nothing has been configured, so a hand-tuned list is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('asset_categories', 'suppressed_fields')) {
            Schema::table('asset_categories', function (Blueprint $table) {
                $table->json('suppressed_fields')->nullable()->after('custody_model');
            });
        }

        // A room keeps its own details (and lifecycle Status) and drops the
        // hardware and finance fields that mean nothing for a space.
        $roomSuppresses = [
            'vendor', 'brand', 'model', 'serial_number',
            'condition', 'location', 'purchase_date', 'purchase_cost',
            'warranty_expiry', 'warranty_provider',
        ];

        DB::table('asset_categories')
            ->where('asset_class', 'physical')
            ->whereRaw('lower(name) = ?', ['room'])
            ->whereNull('suppressed_fields')
            ->update(['suppressed_fields' => json_encode($roomSuppresses)]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('asset_categories', 'suppressed_fields')) {
            Schema::table('asset_categories', function (Blueprint $table) {
                $table->dropColumn('suppressed_fields');
            });
        }
    }
};
