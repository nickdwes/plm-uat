<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('type', [
                'course', 'workshop', 'certification', 'exam_voucher',
                'subscription', 'on_job', 'mentoring', 'conference',
            ])->default('course');
            $table->enum('delivery', [
                'in_person', 'virtual', 'e_learning', 'blended', 'self_study',
            ])->default('e_learning');
            $table->unsignedSmallInteger('duration_hours')->default(0);
            $table->unsignedSmallInteger('duration_minutes')->default(0);
            $table->boolean('is_long_course')->default(false); // duration_hours > 8
            $table->string('provider_name')->nullable();
            $table->string('provider_url')->nullable();
            $table->char('cost_currency', 3)->default('KES');
            $table->decimal('estimated_cost', 12, 2)->default(0);
            $table->json('tags')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('max_enrollments')->nullable(); // null = unlimited
            $table->string('thumbnail_url')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_items');
    }
};
