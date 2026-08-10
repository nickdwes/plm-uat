<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The API tokens table, published from Sanctum rather than borrowed from it.
 *
 * Sanctum 4 only *publishes* this migration — it does not load it from the
 * package at runtime the way Sanctum 3 did. Nobody published it here, so the
 * table existed in production (created back when the package still loaded its
 * own migrations) and could not be created by this repository at all. A
 * database built from these migrations came up with no `personal_access_tokens`
 * table, which means every bearer request fails on the first query: not a
 * degraded system, a system that cannot be logged into.
 *
 * The filename is deliberately the one Sanctum ships and production already
 * recorded in its ledger, `2019_12_14_000001_create_personal_access_tokens_table`.
 * Publishing it under a fresh timestamp would have made production try to
 * create a table it already has.
 *
 * Kept byte-identical to the vendor copy so upgrading Sanctum stays a matter of
 * re-publishing rather than reconciling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
