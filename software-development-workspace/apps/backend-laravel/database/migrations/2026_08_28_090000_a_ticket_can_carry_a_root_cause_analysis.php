<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One RCA per ticket, structured rather than a single text box.
 *
 * A free-text "notes" field would satisfy nobody who reads an RCA
 * downstream — audit, risk, security, and management each expect specific
 * questions answered in specific places. Root cause, contributing factors,
 * control failure, corrective actions and preventive actions are kept as
 * their own fields for that reason: a report generator (and a reviewer) can
 * rely on them existing, rather than parsing prose to find them.
 *
 * Narrower narrative groups (incident description, business impact, risk
 * assessment) are stored as JSON objects rather than a column each — those
 * are read and written as a group, never queried by sub-field, so a column
 * per bullet would only be schema noise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_rcas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('support_ticket_id')->unique()->constrained()->cascadeOnDelete();

            // 1. Document information
            $table->string('title', 300)->nullable();
            $table->date('incident_date')->nullable();
            $table->date('prepared_date')->nullable();
            $table->string('system_affected', 200)->nullable();
            $table->string('business_unit', 200)->nullable();
            $table->string('severity', 50)->nullable();
            $table->string('incident_owner', 160)->nullable();
            $table->string('technical_owner', 160)->nullable();
            $table->json('reviewers')->nullable(); // string[]

            // 2. Executive summary
            $table->text('executive_summary')->nullable();

            // 3. Incident description (symptoms, systems/users affected, errors, detection, observations)
            $table->json('incident_description')->nullable();

            // 4. Business impact (users/transactions/revenue/SLA/operational/customer/compliance/data-loss)
            $table->json('business_impact')->nullable();

            // 5. Incident timeline — [{time, event}]
            $table->json('timeline')->nullable();

            // 6. Technical investigation
            $table->text('technical_investigation')->nullable();

            // 7. Root cause — the section every downstream reader needs answered plainly
            $table->text('immediate_cause')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('contributing_factors')->nullable();
            $table->text('control_failure')->nullable(); // why existing controls didn't prevent/catch it

            // 8. Five whys — [{question, answer}] and the conclusion
            $table->json('five_whys')->nullable();
            $table->text('final_root_cause')->nullable();

            // 9. Classification
            $table->string('root_cause_category', 40)->nullable();

            // 10. Resolution
            $table->text('resolution')->nullable();

            // 11 & 12. Actions — [{action, owner, priority, due_date, status}]. Section 16's
            // consolidated tracker is these two lists rendered together, not stored again.
            $table->json('corrective_actions')->nullable();
            $table->json('preventive_actions')->nullable();

            // 13. Lessons learned
            $table->text('lessons_learned')->nullable();

            // 14. Evidence — references (log extracts, tickets, links), one per line
            $table->text('evidence')->nullable();

            // 15. Risk assessment (similar systems, probability, impact, controls, gaps, treatment)
            $table->json('risk_assessment')->nullable();

            $table->string('status', 16)->default('draft'); // draft | final
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_rcas');
    }
};
