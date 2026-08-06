<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The lead as the board and the qualification screens define it.
 *
 * Two changes of substance.
 *
 * The statuses become the four the board has columns for. `cold` and `lost`
 * both collapse into `unqualified`: they were two words for the same outcome —
 * we are not taking this further — and a board cannot have a column for a
 * distinction nobody could state. `converted` stays but is not a column; a
 * converted lead has left the board and lives on as its opportunity.
 *
 * Qualification is stored as five percentages rather than a single figure.
 * A lead scoring 89 tells you nothing about what to do; a lead at 94 on
 * timeline and 60 on solution match tells you to go and fix the solution.
 * The overall score is their mean, computed rather than stored, so it cannot
 * drift from the parts it is made of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // The line under the title on a board card. Distinct from the
            // problem statement, which is prose nobody can read at a glance.
            $table->string('summary')->nullable()->after('name');

            // ── Qualification: the five dimensions ────────────────────────
            //
            // Nullable, not zero. An unanswered dimension and one honestly
            // assessed at nothing are different, and defaulting to zero would
            // make every new lead look hopeless.
            $table->unsignedTinyInteger('fit_company')->nullable();
            $table->unsignedTinyInteger('fit_budget')->nullable();
            $table->unsignedTinyInteger('fit_solution')->nullable();
            $table->unsignedTinyInteger('fit_timeline')->nullable();
            $table->unsignedTinyInteger('fit_stakeholder')->nullable();

            // ── Qualification summary ─────────────────────────────────────
            $table->string('budget_availability', 24)->nullable();     // available | limited | none
            $table->string('stakeholder_engagement', 24)->nullable();  // high | medium | low
            $table->string('timeline_estimate')->nullable();           // "3–4 Months"
            $table->string('technical_readiness', 24)->nullable();      // high | medium | low
            $table->string('decision_authority_access', 24)->nullable();// available | limited | none

            $table->text('qualification_notes')->nullable();
        });

        /*
         * Who decides, on their side.
         *
         * Kept apart from account contacts on purpose: a decision maker is
         * named against this deal, and the same person may matter here and be
         * irrelevant to the next one. The link back to a contact is optional
         * so somebody named here can be promoted to the customer's contact
         * list without being retyped.
         */
        Schema::create('lead_stakeholders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('account_contact_id')->nullable()->constrained('account_contacts')->nullOnDelete();

            $table->string('name');
            $table->string('role')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // Whether they can say yes, as distinct from whether they matter.
            $table->string('influence', 16)->nullable();   // decision_maker | influencer | blocker | user
            $table->timestamps();

            $table->index(['lead_id']);
        });

        // The lead's own timeline. Separate from the customer's: what happened
        // on this deal is not the same question as when we last spoke to them.
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();

            $table->string('kind', 16)->default('manual');   // manual | system
            $table->string('type', 32)->default('note');
            $table->text('body')->nullable();
            $table->timestamp('occurred_at');

            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lead_id', 'occurred_at']);
        });

        // Paperwork on the lead. Private, like every other document class.
        Schema::create('lead_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();

            $table->string('document_type', 32)->default('internal');  // client | internal | financial | technical
            $table->string('label');
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lead_id']);
        });

        // Two words for one outcome, before the board gives it a column.
        DB::table('leads')->whereIn('status', ['cold', 'lost'])->update(['status' => 'unqualified']);
    }

    public function down(): void
    {
        DB::table('leads')->where('status', 'unqualified')->update(['status' => 'lost']);

        Schema::dropIfExists('lead_attachments');
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('lead_stakeholders');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'summary', 'fit_company', 'fit_budget', 'fit_solution', 'fit_timeline',
                'fit_stakeholder', 'budget_availability', 'stakeholder_engagement',
                'timeline_estimate', 'technical_readiness', 'decision_authority_access',
                'qualification_notes',
            ]);
        });
    }
};
