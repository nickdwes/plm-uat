<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A vendor's classification stops being one column.
 *
 * `vendor_type` is single-valued, and reality is not: `2026_08_30_090100`
 * had to bolt on `is_oem_vendor` as a boolean precisely because a distributor
 * and an OEM are not mutually exclusive. Rather than a third axis, roles move
 * to their own effective-dated rows — a partner that was a subcontractor last
 * year and a distributor since March is history the register can now answer.
 *
 * Rows are END-DATED, never deleted: the relationship's past is the point.
 * The legacy `vendor_type` / `is_oem_vendor` columns stay readable for the
 * consumers that still filter on them; they are back-filled here and their
 * writes are deprecated. Removal is a later hygiene pass.
 *
 * `party_class` arrives in the same breath: an independent contractor or a
 * consultant is a PERSON, and pretending they are a one-person company is
 * how registration-number fields end up holding national IDs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stakeholder_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_partner_id')->constrained('vendor_partners')->cascadeOnDelete();
            $table->string('role', 40);
            $table->date('valid_from');
            // Null = in force. An ended role keeps its row.
            $table->date('valid_until')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Same role may recur across time, never overlap at the same start.
            $table->unique(['vendor_partner_id', 'role', 'valid_from'], 'stakeholder_role_window');
            $table->index(['organization_id', 'role']);
            $table->index('valid_until');
        });

        Schema::table('vendor_partners', function (Blueprint $table) {
            $table->string('party_class', 20)->default('organization')->after('name');
        });

        // Back-fill: every vendor's current classification becomes role rows,
        // effective from the day the record was created (the best date we have).
        $legacyToRole = [
            'supplier'         => 'supplier',
            'service_provider' => 'service_provider',
            'contractor'       => 'contractor_company',
            'consultant'       => 'consultant',
            'distributor'      => 'distributor',
            'partner'          => 'channel_partner',
        ];

        foreach (DB::table('vendor_partners')->get(['id', 'organization_id', 'vendor_type', 'is_oem_vendor', 'created_at']) as $vendor) {
            $from = substr((string) ($vendor->created_at ?? now()), 0, 10);

            $roles = [$legacyToRole[$vendor->vendor_type] ?? 'supplier'];
            if ($vendor->is_oem_vendor) {
                $roles[] = 'oem';
            }

            foreach (array_unique($roles) as $role) {
                DB::table('stakeholder_roles')->insertOrIgnore([
                    'organization_id'   => $vendor->organization_id,
                    'vendor_partner_id' => $vendor->id,
                    'role'              => $role,
                    'valid_from'        => $from,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('vendor_partners', function (Blueprint $table) {
            $table->dropColumn('party_class');
        });
        Schema::dropIfExists('stakeholder_roles');
    }
};
