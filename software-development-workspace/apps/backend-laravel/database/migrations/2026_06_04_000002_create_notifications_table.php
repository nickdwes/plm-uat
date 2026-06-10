<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            
            // Notification type: training_assigned, training_approved, training_rejected, training_completed, etc.
            $table->string('type');
            
            // Human-readable title and message
            $table->string('title');
            $table->text('message')->nullable();
            
            // Additional context data (JSON)
            $table->json('data')->nullable();
            
            // Polymorphic relation to notify able model (e.g., TrainingAssignment, TrainingRecord)
            $table->string('notifiable_type')->nullable();
            $table->uuid('notifiable_id')->nullable();
            
            // Read status
            $table->dateTime('read_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for fast queries
            $table->index(['organization_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
