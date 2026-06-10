<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Expand vendor_partners ────────────────────────────────────────
        Schema::table('vendor_partners', function (Blueprint $table) {
            $table->string('partnership_level', 50)->nullable()
                ->after('category');
            $table->string('logo_url', 500)->nullable()
                ->after('website_url');
            $table->unsignedTinyInteger('fiscal_year_start_month')->nullable()
                ->comment('Month the vendor fiscal year starts: 1=Jan, 12=Dec')
                ->after('partnership_level');
            $table->date('renewal_anniversary_date')->nullable()
                ->after('fiscal_year_start_month');
            $table->decimal('renewal_cost', 12, 2)->nullable()
                ->after('renewal_anniversary_date');
            $table->string('renewal_cost_currency', 10)->default('USD')
                ->after('renewal_cost');
            $table->unsignedSmallInteger('renewal_reminder_days')->default(30)
                ->comment('Days before renewal_anniversary_date to trigger reminder')
                ->after('renewal_cost_currency');
        });

        // ── 2. vendor_partnership_tiers ──────────────────────────────────────
        Schema::create('vendor_partnership_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('vendor_partner_id')
                ->constrained('vendor_partners')->cascadeOnDelete();
            $table->string('tier_name', 100);
            $table->unsignedTinyInteger('tier_order')->default(0);
            $table->decimal('revenue_target', 15, 2)->nullable();
            $table->string('revenue_currency', 10)->default('USD');
            $table->unsignedSmallInteger('min_certifications')->nullable();
            $table->unsignedSmallInteger('min_headcount')->nullable();
            $table->text('requirements')->nullable();
            $table->text('benefits')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });

        // ── 3. vendor_portal_users ───────────────────────────────────────────
        Schema::create('vendor_portal_users', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('vendor_partner_id')
                ->constrained('vendor_partners')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('display_name', 255);
            $table->string('email', 255);
            $table->string('role', 50)->default('user');
            $table->string('portal_url', 500)->nullable();
            $table->string('access_level', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── 4. vendor_customers ──────────────────────────────────────────────
        Schema::create('vendor_customers', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('vendor_partner_id')
                ->constrained('vendor_partners')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('customer_type', 50)->default('enterprise');
            $table->string('industry', 100)->nullable();
            $table->string('country', 100)->default('Kenya');
            $table->string('contact_name', 255)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── 5. vendor_customer_projects ──────────────────────────────────────
        Schema::create('vendor_customer_projects', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('vendor_partner_id')
                ->constrained('vendor_partners')->cascadeOnDelete();
            $table->foreignId('vendor_customer_id')->nullable()
                ->constrained('vendor_customers')->nullOnDelete();
            $table->string('name', 255);
            $table->string('project_code', 100)->nullable();
            $table->string('status', 50)->default('active');
            $table->string('technology_space', 100)->nullable();
            $table->string('technology_subcategory', 100)->nullable();
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('contract_value', 15, 2)->nullable();
            $table->string('currency', 10)->default('KES');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_customer_projects');
        Schema::dropIfExists('vendor_customers');
        Schema::dropIfExists('vendor_portal_users');
        Schema::dropIfExists('vendor_partnership_tiers');

        Schema::table('vendor_partners', function (Blueprint $table) {
            $table->dropColumn([
                'partnership_level', 'logo_url', 'fiscal_year_start_month',
                'renewal_anniversary_date', 'renewal_cost', 'renewal_cost_currency',
                'renewal_reminder_days',
            ]);
        });
    }
};
