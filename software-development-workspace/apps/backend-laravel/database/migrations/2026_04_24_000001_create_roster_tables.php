<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Roster General Configuration (one row per organisation) ───────────
        Schema::create('roster_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            // Which detail appears next to the employee name on the roster view.
            $table->string('primary_detail_shown', 30)->default('total_shift_hours'); // total_shift_hours | allocated_hours
            $table->boolean('swap_shifts_enabled')->default(false);
            $table->boolean('manager_approval_required')->default(true);
            $table->timestamps();

            $table->unique('organization_id');
        });

        // ── Shift Groups (optional groupings for shift types, e.g. "Clinical") ─
        Schema::create('roster_shift_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        // ── Shift Types (reusable templates: Morning, Evening, Night, etc.) ───
        Schema::create('roster_shift_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('shift_group_id')
                ->nullable()
                ->constrained('roster_shift_groups')
                ->nullOnDelete();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->time('break_start')->nullable();
            $table->unsignedSmallInteger('break_duration_minutes')->default(0);
            // Gross shift duration (end − start, crossing midnight handled).
            $table->unsignedSmallInteger('duration_minutes');
            $table->boolean('is_overnight')->default(false); // spans midnight
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── Roster Schedules (a named plan covering a date range) ─────────────
        Schema::create('roster_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('draft'); // draft | published
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // ── Roster Assignments (employee ↔ shift type ↔ calendar date) ────────
        Schema::create('roster_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')
                ->constrained('roster_schedules')
                ->cascadeOnDelete();
            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();
            $table->foreignId('shift_type_id')
                ->nullable()
                ->constrained('roster_shift_types')
                ->nullOnDelete();
            $table->date('assignment_date');
            $table->string('type', 20)->default('shift'); // shift | day_off | leave
            $table->text('notes')->nullable();
            $table->timestamps();

            // One slot per employee per day per schedule.
            $table->unique(
                ['schedule_id', 'employee_id', 'assignment_date'],
                'roster_unique_assignment'
            );
        });

        // ── Shift Swap Requests ───────────────────────────────────────────────
        Schema::create('roster_shift_swap_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('requester_assignment_id')
                ->constrained('roster_assignments')
                ->cascadeOnDelete();
            $table->foreignId('requested_assignment_id')
                ->nullable()
                ->constrained('roster_assignments')
                ->nullOnDelete();
            $table->foreignId('requester_id')
                ->constrained('employees')
                ->cascadeOnDelete();
            $table->foreignId('requested_employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();
            // pending | accepted | rejected | approved | cancelled
            $table->string('status', 20)->default('pending');
            $table->text('requester_notes')->nullable();
            $table->text('responder_notes')->nullable();
            $table->text('manager_notes')->nullable();
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_shift_swap_requests');
        Schema::dropIfExists('roster_assignments');
        Schema::dropIfExists('roster_schedules');
        Schema::dropIfExists('roster_shift_types');
        Schema::dropIfExists('roster_shift_groups');
        Schema::dropIfExists('roster_configurations');
    }
};
