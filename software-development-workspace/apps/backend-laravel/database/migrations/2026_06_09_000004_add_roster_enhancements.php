<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add color to shift types
        Schema::table('roster_shift_types', function (Blueprint $table) {
            $table->string('color', 7)->default('#3B82F6')->after('is_active');
        });

        // Handover reports
        Schema::create('roster_handover_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('roster_schedules')->nullOnDelete();
            $table->foreignId('shift_type_id')->nullable()->constrained('roster_shift_types')->nullOnDelete();
            $table->foreignId('outgoing_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('incoming_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('report_date');
            $table->time('shift_start')->nullable();
            $table->time('shift_end')->nullable();
            $table->text('tasks_completed')->nullable();
            $table->text('ongoing_issues')->nullable();
            $table->text('tasks_pending')->nullable();
            $table->text('equipment_notes')->nullable();
            $table->text('general_notes')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_handover_reports');
        Schema::table('roster_shift_types', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
