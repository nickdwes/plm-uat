<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_compliance_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Stable machine-readable key (e.g. "cr12", "kra_pin") — unique per org.
            $table->string('document_key', 100);

            // Grouping segment label (e.g. "Core Registration", "Tax Compliance").
            $table->string('segment', 120);

            // Human-readable document label.
            $table->string('document_label', 200);

            // False = has an expiry date; True = no expiry (e.g. PIN cert).
            $table->boolean('is_perpetual')->default(false);

            // Uploaded file details (nullable until uploaded).
            $table->string('file_url')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_disk_path')->nullable();

            // Key dates.
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('anniversary_date')->nullable();

            // Internal notes.
            $table->text('notes')->nullable();

            // Assignee — person responsible for keeping this document current.
            $table->string('owner_name', 150)->nullable();
            $table->string('owner_email', 150)->nullable();

            // Manual status override (e.g. "Under Review", "Pending Renewal").
            $table->string('status_override', 60)->nullable();

            // Editable renewal instructions shown in the info popup.
            $table->text('renewal_guide')->nullable();

            // Audit trail — JSON array of {at, by, action, note} entries.
            $table->json('audit_trail')->nullable();

            // Reminder configuration.
            $table->unsignedSmallInteger('reminder_days_before')->default(30);
            // Comma-separated email addresses.
            $table->text('reminder_recipients')->nullable();
            $table->boolean('reminder_active')->default(false);
            // JSON array of days-before-expiry thresholds (e.g. [180,90,30,7,1]).
            $table->json('reminder_thresholds')->nullable();
            $table->timestamp('last_reminder_sent_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'document_key'], 'unique_org_compliance_doc');
            $table->index(['organization_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_compliance_documents');
    }
};
