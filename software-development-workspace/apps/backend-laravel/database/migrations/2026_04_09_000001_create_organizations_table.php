<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();           // konvergenz, visibility, tiberbu, teedu
            $table->string('legal_name')->nullable();
            $table->string('industry')->nullable();
            $table->string('country', 3)->default('KE');
            $table->string('currency', 3)->default('KES');
            $table->string('logo_path')->nullable();
            $table->string('primary_email')->nullable();
            $table->string('primary_phone')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();       // org-specific overrides to group settings
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
