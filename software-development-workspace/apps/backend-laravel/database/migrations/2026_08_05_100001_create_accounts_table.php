<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer master.
 *
 * Travel requests name the customer a trip is for — 94 of 102 legacy requests
 * did — so this exists before Travel rather than alongside it.
 *
 * Departures from the legacy `mdl_accounts`:
 *
 *   organization_id    Customers are per organisation. The legacy system was
 *                      single-tenant and had no equivalent.
 *
 *   account_manager_id points at employees, not users. Who owns a customer
 *                      relationship is a people fact, and must stay answerable
 *                      for someone who has never logged in.
 *
 *   country            a two-letter ISO code rather than a foreign key into a
 *                      countries table nobody maintains.
 *
 *   vat                boolean. The legacy stored it as varchar(255), which
 *                      across 70 rows is a yes/no wearing a costume.
 *
 * Dropped: `slug` (nothing routes by it), `image`/`image_thumb` (no customer
 * logo requirement has been stated — easier to add than to migrate away from),
 * `attachments` as an integer column (attachments are a relation, not a
 * count), and `comments` as free text alongside `details`, which is two
 * fields for one purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('name');
            $table->string('status')->default('prospect');   // prospect | active | inactive

            $table->foreignId('account_manager_id')->nullable()
                  ->constrained('employees')->nullOnDelete();
            $table->foreignId('account_type_id')->nullable()
                  ->constrained('account_types')->nullOnDelete();
            $table->foreignId('account_sector_id')->nullable()
                  ->constrained('account_sectors')->nullOnDelete();
            $table->foreignId('account_subsector_id')->nullable()
                  ->constrained('account_subsectors')->nullOnDelete();

            // ── Profile ───────────────────────────────────────────────────
            $table->char('country', 2)->nullable();          // ISO 3166-1 alpha-2
            $table->string('physical_location')->nullable();
            $table->unsignedInteger('number_of_branches')->nullable();
            $table->unsignedInteger('number_of_employees')->nullable();
            $table->string('fund_source')->nullable();

            // ── Tax ───────────────────────────────────────────────────────
            $table->boolean('vat_registered')->default(false);
            $table->string('tax_pin')->nullable();           // KRA PIN in Kenya

            $table->text('details')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // A customer named once per organisation. Duplicates here are the
            // main way a customer master rots — two records, half the history
            // on each.
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'account_manager_id']);
        });

        Schema::create('account_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->string('title')->nullable();             // Mr, Ms, Dr, Eng
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('designation')->nullable();
            $table->string('department')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('alternate_phone')->nullable();

            // Who to call first. Enforced as at most one per account by the
            // service rather than the schema, since a partial unique index
            // would still allow none.
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'is_active']);
            $table->index(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_contacts');
        Schema::dropIfExists('accounts');
    }
};
