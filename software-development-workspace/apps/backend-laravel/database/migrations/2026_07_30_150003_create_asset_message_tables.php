<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threaded conversation on an issue or a request.
 *
 * Two tables rather than one polymorphic thread, matching the source. A
 * polymorphic messages table would be tidier, but it is also the shape the
 * notifications table got wrong — a key type that could not address its
 * targets — and there is no third consumer yet to justify the abstraction.
 * Worth revisiting when Travel needs the same thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_issue_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('asset_issue_id')->constrained('asset_issues')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('employees')->cascadeOnDelete();
            $table->text('message');
            $table->timestamps();

            $table->index(['asset_issue_id', 'created_at']);
        });

        Schema::create('asset_request_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('asset_request_id')->constrained('asset_requests')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('employees')->cascadeOnDelete();
            $table->text('message');
            $table->timestamps();

            $table->index(['asset_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_request_messages');
        Schema::dropIfExists('asset_issue_messages');
    }
};
