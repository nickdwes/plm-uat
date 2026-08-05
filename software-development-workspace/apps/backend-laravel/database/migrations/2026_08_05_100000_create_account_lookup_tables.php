<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference data for the customer master.
 *
 * The legacy system held type, sector and sub-sector as integer columns
 * pointing at their own tables, and country as an integer pointing at a
 * countries table. All four are kept as tables rather than flattened to
 * strings, because they are the axes customers get reported on — "how much of
 * our pipeline is public sector" is a group-by, and a free-text field cannot
 * answer it once two people have typed "Public Sector" and "public sector".
 *
 * Country is the exception: it becomes a two-letter ISO code on the account
 * rather than a lookup table. The legacy countries table existed to hold
 * names, which ISO already standardises, and one fewer table to seed is one
 * fewer thing to get wrong per organisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Unique per organisation, not globally: two tenants naming a type
            // "Enterprise" is normal, and a global unique would surface as a
            // validation error on data the second tenant cannot even see.
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('account_sectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('account_subsectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('account_sector_id')->constrained('account_sectors')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Scoped to the parent sector: "Retail" under Banking and "Retail"
            // under Commerce are different things.
            $table->unique(['account_sector_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_subsectors');
        Schema::dropIfExists('account_sectors');
        Schema::dropIfExists('account_types');
    }
};
