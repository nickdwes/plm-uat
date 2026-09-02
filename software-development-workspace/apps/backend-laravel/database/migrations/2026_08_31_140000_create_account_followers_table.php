<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is watching a customer.
 *
 * Per user, not per organisation: following is a personal bookmark — "tell me
 * about this one" — and two people watching the same account is the normal
 * case, not a conflict.
 *
 * No organisation column. The account carries one, and a row here is only ever
 * reachable through an account the reader is already allowed to see; a second
 * copy would be a second thing to keep true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Following twice is the same as following once.
            $table->unique(['account_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_followers');
    }
};
