<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a project may start ordering differs by deal: a framework or LPO-backed
 * award can order immediately, while a bespoke service contract must be signed
 * first. So the gate is per project, not one universal rule.
 *
 * `order_release_gate` names which signal unlocks ordering — on_award,
 * on_contract, on_lpo, or manual. `contract_ready_override` lets a PM confirm
 * (or deny) contract readiness when the auto-detect from the linked contract is
 * loose (null = trust auto-detect). The customer LPO — the customer's written
 * authorisation to proceed — had no home before; it lives here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('order_release_gate', 16)->default('manual')->after('financially_closed_by');
            $table->boolean('contract_ready_override')->nullable()->after('order_release_gate');

            $table->string('customer_lpo_number', 120)->nullable()->after('contract_ready_override');
            $table->date('customer_lpo_date')->nullable()->after('customer_lpo_number');
            $table->decimal('customer_lpo_value', 15, 2)->nullable()->after('customer_lpo_date');
            $table->char('customer_lpo_currency', 3)->nullable()->after('customer_lpo_value');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'order_release_gate', 'contract_ready_override',
                'customer_lpo_number', 'customer_lpo_date', 'customer_lpo_value', 'customer_lpo_currency',
            ]);
        });
    }
};
