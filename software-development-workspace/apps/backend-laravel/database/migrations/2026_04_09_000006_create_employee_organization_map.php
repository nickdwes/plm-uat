<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Links shared employees to secondary organizations with their context within each
        Schema::create('employee_organization_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Role/designation within THIS organization (may differ from primary org)
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            // Reporting manager within THIS organization context
            $table->foreignId('reports_to')->nullable()->constrained('employees')->nullOnDelete();

            // What the employee can access within this org
            $table->string('access_scope')->default('operational');  // operational|read_only|admin

            // Employment context
            $table->string('role')->nullable();       // a free-text operational role label
            $table->date('effective_from');
            $table->date('effective_to')->nullable(); // null = current/ongoing

            $table->boolean('is_active')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'organization_id', 'effective_from'], 'emp_org_map_unique');
        });

        // Reporting line overrides — employee's manager per organization context
        // (The employees.reports_to column covers the primary org;
        //  this table covers secondary org reporting lines set via the map)
        // Already covered by employee_organization_map.reports_to above.
        // This table handles the full org chart within each organization.
        Schema::create('reporting_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('manager_id')->constrained('employees')->cascadeOnDelete();
            $table->string('relationship_type')->default('direct'); // direct|dotted_line
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['organization_id', 'employee_id', 'relationship_type', 'effective_from'],
                'reporting_line_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_lines');
        Schema::dropIfExists('employee_organization_map');
    }
};
