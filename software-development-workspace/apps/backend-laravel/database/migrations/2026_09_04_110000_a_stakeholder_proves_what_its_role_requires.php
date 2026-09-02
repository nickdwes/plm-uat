<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor compliance stops meaning "how many of our people hold a certificate".
 *
 * The existing `vendor-compliance` screen answers a training question — do we
 * have enough certified engineers for this vendor's products. Useful, and not
 * the question a buyer asks before raising a purchase order: is this supplier
 * tax compliant, insured, and authorised to sell what it says it sells.
 *
 * Three tables:
 *
 *  - `vendor_documents` — the paperwork itself, through DocumentVault so it is
 *    encrypted at rest like every other document class here.
 *  - `stakeholder_role_requirements` — what each ROLE has to produce.
 *    Per-role because a distributor's OEM authorisation letter is meaningless
 *    for a consultant, and an NDA is the only thing a consultant needs.
 *  - `vendor_document_reminders` — a sent-log, so the expiry sweep can be
 *    idempotent. Same shape as `asset_renewal_reminders`, whose unique
 *    (record, stage) is what stops a rung firing twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_partner_id')->constrained('vendor_partners')->cascadeOnDelete();

            // Matches a requirement's key when it satisfies one; free-form
            // otherwise, because a vendor may send paperwork nobody asked for
            // and refusing to file it would be worse than filing it loosely.
            $table->string('document_key', 60);
            $table->string('label', 160);

            $table->string('original_name', 255);
            $table->string('storage_path', 500);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            // A certificate of incorporation does not expire; a tax compliance
            // certificate does. Without this the sweep would nag about the
            // former forever.
            $table->boolean('is_perpetual')->default(false);

            $table->string('notes', 500)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_partner_id', 'document_key']);
            $table->index(['organization_id', 'expiry_date']);
        });

        Schema::create('stakeholder_role_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('role', 40);
            $table->string('document_key', 60);
            $table->string('label', 160);
            // `mandatory` blocks activation; `optional` is a prompt, not a gate.
            $table->string('requirement', 20)->default('mandatory');
            $table->string('help', 300)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'role', 'document_key'], 'role_requirement_unique');
        });

        Schema::create('vendor_document_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_document_id')->constrained('vendor_documents')->cascadeOnDelete();
            $table->string('stage_key', 32);
            $table->date('sent_on');
            $table->smallInteger('days_out');
            $table->timestamps();

            // One rung fires once. The sweep may run twice in a day, or slip
            // one entirely, without anybody being nagged twice.
            $table->unique(['vendor_document_id', 'stage_key']);
        });

        $this->seedRequirements();
    }

    /**
     * Give every existing organisation its rulebook.
     *
     * From `StakeholderRoleRequirement::defaults()` rather than a copy here:
     * one list, so a later change to the baseline cannot disagree with what
     * this migration wrote. New organisations get theirs on creation, and
     * `forOrganization()` falls back to the same defaults for anything that
     * slipped between the two.
     */
    private function seedRequirements(): void
    {
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            \App\Models\Vendor\StakeholderRoleRequirement::materialiseFor((int) $orgId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_document_reminders');
        Schema::dropIfExists('stakeholder_role_requirements');
        Schema::dropIfExists('vendor_documents');
    }
};
