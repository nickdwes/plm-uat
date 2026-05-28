<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('recruitment_workflows')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('order_index');
            $table->enum('stage_type', ['application', 'screening', 'interview', 'decision', 'offer', 'hired', 'rejected'])->default('interview');
            $table->json('coordinator_ids')->nullable();
            $table->json('required_actions')->nullable();
            $table->json('entry_criteria')->nullable();
            $table->json('exit_criteria')->nullable();
            $table->timestamps();

            $table->unique(['workflow_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_stages');
        Schema::dropIfExists('recruitment_workflows');
    }
};
