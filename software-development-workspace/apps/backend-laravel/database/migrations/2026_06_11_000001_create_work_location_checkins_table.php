<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_location_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('location_type'); // office | home | customer_site
            $table->string('customer_name')->nullable();
            $table->string('notes', 500)->nullable();
            $table->dateTime('checked_in_at');
            $table->dateTime('checked_out_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'checked_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_location_checkins');
    }
};
