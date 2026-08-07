<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance and where people are working.
 *
 * Nothing in the old PLM covers this, so there is no legacy to read — which
 * means the shape has to be argued from the requirement rather than the data.
 *
 * The spine is one row per employee per working day. A future day is a plan and
 * a past day is a record, and they are the same row: the requirement asks both
 * for advance work-location reporting and for attendance history, and holding
 * those in two tables guarantees they eventually disagree about the same
 * Thursday. Planned and actual sit side by side so the gap between them —
 * "said office, worked from home" — is a fact the system holds rather than
 * something nobody can reconstruct.
 *
 * What already existed was `work_location_checkins`: zero rows, and a punch
 * model — a check-in, a check-out, a location. It cannot answer "was Irene late
 * on the third" or "where is she working on Thursday", because a punch has no
 * opinion about what the day was supposed to be. It becomes the child of the
 * day rather than the record of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What the working day is supposed to look like.
         *
         * Without this "On Time" and "Late" are arbitrary — the designs put
         * both on the front page as counted figures, and a count needs a rule.
         * One row per organisation, because that is the level at which somebody
         * can actually decide it.
         */
        Schema::create('attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();

            $table->time('work_starts_at')->default('08:00');
            $table->time('work_ends_at')->default('17:00');

            /*
             * Minutes after the start before it counts as late.
             *
             * Explicit rather than zero, because a rule with no grace makes
             * 08:01 a disciplinary fact and the number stops being read.
             */
            $table->unsignedSmallInteger('grace_minutes')->default(10);

            $table->decimal('standard_hours', 4, 2)->default(8);
            // Anything past the standard day. A threshold rather than a plain
            // subtraction, so five minutes over does not read as overtime.
            $table->unsignedSmallInteger('overtime_after_minutes')->default(30);

            // ISO weekdays that count as working days: 1 Monday … 7 Sunday.
            $table->json('working_days')->nullable();

            /*
             * Whether somebody may report a location for a day that has not
             * happened yet. The requirement allows advance reporting "subject
             * to company policy", so the policy is a setting rather than a
             * decision baked into the code.
             */
            $table->unsignedSmallInteger('plan_ahead_days')->default(30);

            $table->timestamps();
        });

        /*
         * One employee, one day.
         *
         * The unique key is the whole design. It is what makes a day something
         * you can plan and then fill in, rather than a pile of punches somebody
         * has to add up.
         */
        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date');

            // office | home | customer_site
            $table->string('planned_location', 24)->nullable();
            $table->string('actual_location', 24)->nullable();

            /*
             * Which customer, when it is a customer site.
             *
             * A link rather than the free-text `customer_name` the punch table
             * carried — "customer site engagement statistics" is a reporting
             * requirement, and you cannot count a name people spell three ways.
             */
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('location_note')->nullable();

            /*
             * Derived and stored, unusually.
             *
             * Everywhere else in this system a derivable value is computed on
             * read. Here it is written, because it depends on the policy as it
             * stood on the day — changing the office start time next year must
             * not retroactively make last year's punctual staff late.
             */
            $table->string('status', 24)->nullable();   // on_time | late | early | absent | on_leave | holiday | weekend

            $table->timestamp('first_clock_in_at')->nullable();
            $table->timestamp('last_clock_out_at')->nullable();
            $table->unsignedSmallInteger('worked_minutes')->nullable();
            $table->unsignedSmallInteger('overtime_minutes')->nullable();

            /*
             * The enablement day — one a week for training, certification,
             * research and internal improvement. Flagged here rather than in
             * its own table because it is a property of a day, and a day that
             * already exists is the thing being designated.
             */
            $table->boolean('is_enablement_day')->default(false);
            $table->string('enablement_status', 16)->nullable();  // requested | approved | declined
            $table->foreignId('enablement_approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('enablement_actioned_at')->nullable();
            $table->text('enablement_note')->nullable();

            $table->text('notes')->nullable();

            // Null where the day was filled in by the system rather than typed.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'employee_id', 'work_date']);
            // The two questions the dashboards ask: this person's month, and
            // everybody's Thursday.
            $table->index(['organization_id', 'work_date']);
            $table->index(['employee_id', 'work_date']);
        });

        /*
         * The clock pairs inside a day.
         *
         * Several per day, because a real day has a site visit in the middle of
         * it. The day's own first-in and last-out are rolled up from these
         * rather than typed, so the two can never disagree.
         */
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('attendance_day_id')->constrained('attendance_days')->cascadeOnDelete();

            $table->timestamp('clocked_in_at');
            $table->timestamp('clocked_out_at')->nullable();
            $table->string('location_type', 24)->nullable();

            /*
             * Entered after the fact rather than clocked live.
             *
             * The designs have an "Add Hours" tab beside "Clock In", so this is
             * expected and normal — but a figure somebody typed and one the
             * clock recorded are different evidence, and reporting that cannot
             * tell them apart is reporting nobody should rely on.
             */
            $table->boolean('is_manual')->default(false);
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['attendance_day_id']);
        });

        /*
         * Every change to a day, kept.
         *
         * The requirement asks for audit logs on schedule, attendance and
         * location changes by name. Attendance is the record people have the
         * strongest reason to revise after the fact, which is exactly why the
         * revisions have to be visible.
         */
        Schema::create('attendance_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('attendance_day_id')->constrained('attendance_days')->cascadeOnDelete();

            $table->string('field', 64);
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();

            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->index(['attendance_day_id', 'changed_at']);
        });

        /*
         * The old punch table goes.
         *
         * Zero rows, no controller reading anything off it that survives, and
         * keeping it would leave two answers to "where was this person" with
         * nothing deciding which is right.
         */
        Schema::dropIfExists('work_location_checkins');
    }

    public function down(): void
    {
        Schema::create('work_location_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('location_type');
            $table->string('customer_name')->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('attendance_audits');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('attendance_policies');
    }
};
