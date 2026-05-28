<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('date');
            $table->smallInteger('year')->index();
            $table->string('country_code', 2)->default('KE');
            // Whether this holiday was imported from an external source or added manually
            $table->enum('source', ['manual', 'imported'])->default('manual');
            // Optional local name (e.g. Swahili)
            $table->string('local_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'date', 'name'], 'unique_org_holiday');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
    }
};
