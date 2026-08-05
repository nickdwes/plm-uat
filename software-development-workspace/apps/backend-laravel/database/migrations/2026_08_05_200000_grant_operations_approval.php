<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives the COO stage somebody who can act on it.
 *
 * `travel.approve_operations` was added with the delivery chain and granted to
 * nobody, so a consignment above the threshold reached the stage and stopped —
 * no eligible approver, no error, and a request sitting in a queue that does
 * not exist. The same silent stall as a department with no lead.
 *
 * Its own role rather than an addition to finance_executive. Signing off a
 * large consignment is an operations judgement, not a financial one, and the
 * whole point of the separate stage is that the two are held by different
 * people. Putting both permissions on one role would leave the chain intact on
 * paper and defeated in practice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('roles')->where('name', 'operations_executive')->exists()) {
            return;
        }

        DB::table('roles')->insert([
            'name'         => 'operations_executive',
            'display_name' => 'Operations Executive (COO)',
            'description'  => 'Approves large consignments. Full visibility of deliveries and travel.',
            'scope'        => 'organization',
            'is_system'    => true,
            'permissions'  => json_encode([
                // Full visibility, because judging whether a consignment
                // should move means seeing what else is moving.
                'travel.view',
                'travel.approve_operations',
                'travel.submit', 'travel.view_own',
            ]),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'operations_executive')->where('is_system', true)->delete();
    }
};
