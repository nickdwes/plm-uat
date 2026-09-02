<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teams meetings scheduled against a project, for the correspondence tab.
 *
 * Mirrors support_ticket_meetings deliberately — the same shape, made through
 * the same Graph client — because a scheduled call is a scheduled call whether
 * it hangs off a ticket or a project, and the recording/transcript pipeline
 * that lands later should have one shape to populate, not two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('subject', 300);
            $table->timestamp('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->json('attendees'); // [{name, address}]

            $table->string('graph_event_id')->nullable();
            $table->string('join_url', 500)->nullable();
            $table->string('recording_url', 500)->nullable();
            $table->longText('transcript')->nullable();

            $table->string('status', 16)->default('scheduled'); // scheduled | cancelled
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_meetings');
    }
};
