<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A reference an applicant can quote that says nothing about anybody else.
 *
 * The public portal returned `APP-` padded with the application's primary key,
 * so an applicant who submitted `APP-000042` learned that the system had taken
 * forty-two applications — across every organisation on it, since the portal
 * is shared. Two applications a week apart give the rate. It is not a
 * catastrophe; it is a number a competitor can read off a form nobody has to
 * log in to.
 *
 * A random reference costs nothing and tells them only about themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('reference', 24)->nullable()->after('id');
        });

        // Existing applications keep working references rather than being
        // left null for support to puzzle over.
        DB::table('applications')->whereNull('reference')->orderBy('id')
            ->each(function ($row) {
                DB::table('applications')->where('id', $row->id)->update([
                    'reference' => 'APP-' . strtoupper(Str::random(10)),
                ]);
            });

        Schema::table('applications', function (Blueprint $table) {
            $table->unique('reference');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });
    }
};
