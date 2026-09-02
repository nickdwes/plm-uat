<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One place that answers "who did what, and when".
 *
 * The system had module-specific event tables (ticket events, attendance
 * audits, asset events, requisition events) but no single trail — and nothing
 * at all for the events an auditor asks about first: logins, failed logins,
 * cross-tenant access attempts, role grants, and reads of sensitive files.
 * This is that trail, written append-only and never updated in place.
 *
 * organization_id is nullable on purpose: a failed login or a cross-tenant
 * denial does not belong to the org the attacker was reaching for, and must
 * still be visible to a group administrator reviewing security events.
 *
 * The actor's name is snapshotted alongside user_id so a renamed or deleted
 * user does not turn last year's trail into a column of orphaned ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 160)->nullable();

            // created | updated | deleted | login | login_failed | logout |
            // access_denied | downloaded | exported | role_granted | role_revoked | ...
            $table->string('action', 40);

            // What was acted on, polymorphic and nullable (auth/security events
            // have no subject record).
            $table->string('auditable_type', 160)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            $table->string('description', 500)->nullable();
            $table->json('changes')->nullable();      // { field: { old, new } }

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 400)->nullable();
            $table->string('route', 200)->nullable();  // "POST api/v1/..."

            // Written once; no updated_at, because an audit row is never edited.
            $table->timestamp('created_at')->nullable();

            $table->index('organization_id');
            $table->index('user_id');
            $table->index('action');
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
