<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The registration form stops being company-shaped for everybody.
 *
 * It asks every party for a company name, a registration number and a fiscal
 * year start, whoever they are. A consultant is a person: they have a
 * professional body rather than a company registry entry, a specialisation
 * rather than an industry, and a day rate rather than a renewal cost — and
 * none of that had anywhere to go, so it ended up in the notes box where
 * nothing can be searched, reported on, or made mandatory.
 *
 * The same problem assets solved: definitions are data, answers are JSON on
 * the record, and the form is built from whichever definitions apply. There
 * the key is the asset's category; here it is the roles the party plays, so a
 * distributor that also contracts is asked both sets once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stakeholder_role_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Null = asked of every role. Nothing uses it yet; it is how a
            // tenant adds "which of our offices owns this relationship"
            // without repeating the field nine times.
            $table->string('role', 40)->nullable();

            // Null = asked of organisations and individuals alike.
            $table->string('party_class', 20)->nullable();

            $table->string('key', 60);
            $table->string('label', 160);
            $table->string('type', 20)->default('text');
            $table->json('options')->nullable();
            $table->string('help', 300)->nullable();
            $table->string('section', 80)->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'role', 'key'], 'role_field_unique');
            $table->index(['organization_id', 'role']);
        });

        Schema::table('vendor_partners', function (Blueprint $table) {
            /*
             * The answers. JSON on the record rather than a table of rows for
             * the same reason assets keep theirs there: they are read whole,
             * with the record, every single time, and never queried across
             * parties without also naming the party.
             */
            $table->jsonb('role_details')->default('{}')->after('party_class');
        });

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            \App\Models\Vendor\StakeholderRoleField::materialiseFor((int) $orgId);
        }
    }

    public function down(): void
    {
        Schema::table('vendor_partners', fn (Blueprint $table) => $table->dropColumn('role_details'));
        Schema::dropIfExists('stakeholder_role_fields');
    }
};
