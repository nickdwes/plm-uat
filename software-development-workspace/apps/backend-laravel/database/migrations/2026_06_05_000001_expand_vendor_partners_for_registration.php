<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor Module — expand vendor_partners table with full registration fields.
 *
 * Adds:
 *  - vendor_number          : auto-generated human-readable ID (VND-0001)
 *  - registration_number    : company registration / business number
 *  - tax_id                 : KRA PIN / TIN
 *  - vendor_type            : supplier | service_provider | contractor | consultant | distributor | partner
 *  - category               : free-text industry category
 *  - description            : what services/goods they provide
 *  - primary_contact_name   : main contact person name
 *  - primary_contact_designation : their job title
 *  - physical_address       : street address
 *  - city                   : city
 *  - country                : country (default Kenya)
 *  - onboarding_status      : draft | pending_review | approved | rejected | suspended
 *  - approved_at            : timestamp when approved
 *  - approved_by            : FK → users
 *  - rejection_reason       : reason if rejected/suspended
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_partners', function (Blueprint $table) {
            $table->string('vendor_number', 20)->nullable()->unique()->after('uuid');
            $table->string('registration_number', 100)->nullable()->after('vendor_number');
            $table->string('tax_id', 50)->nullable()->after('registration_number');
            $table->string('vendor_type', 50)->default('supplier')->after('tax_id');
            $table->string('category', 100)->nullable()->after('vendor_type');
            $table->text('description')->nullable()->after('category');
            $table->string('primary_contact_name', 255)->nullable()->after('name');
            $table->string('primary_contact_designation', 255)->nullable()->after('primary_contact_name');
            $table->text('physical_address')->nullable()->after('contact_phone');
            $table->string('city', 100)->nullable()->after('physical_address');
            $table->string('country', 100)->default('Kenya')->after('city');
            $table->string('onboarding_status', 30)->default('draft')->after('is_active');
            $table->timestamp('approved_at')->nullable()->after('onboarding_status');
            $table->foreignId('approved_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete()
                  ->after('approved_at');
            $table->text('rejection_reason')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_partners', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn([
                'vendor_number', 'registration_number', 'tax_id',
                'vendor_type', 'category', 'description',
                'primary_contact_name', 'primary_contact_designation',
                'physical_address', 'city', 'country',
                'onboarding_status', 'approved_at', 'approved_by', 'rejection_reason',
            ]);
        });
    }
};
