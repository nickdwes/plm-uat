<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of the project register — dependencies and risks — alongside the
 * issues shipped earlier.
 *
 * The old PLM hung dependencies off tasks and had a risk table it never
 * surfaced. Here both are first-class on the project: a dependency can still
 * point at a task (and take that task's status), and a risk carries the
 * likelihood/impact/mitigation an audit actually asks for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();

            $table->string('title', 300);
            $table->text('description')->nullable();
            $table->string('kind', 16)->default('external'); // task | external | vendor | other
            $table->foreignId('depends_on_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('status', 16)->default('pending'); // pending | in_progress | resolved
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('due_on')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'status']);
        });

        Schema::create('project_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('title', 300);
            $table->text('description')->nullable();
            $table->string('likelihood', 8)->default('medium'); // low | medium | high
            $table->string('impact', 8)->default('medium');      // low | medium | high
            $table->string('status', 12)->default('open');       // open | mitigating | closed
            $table->text('mitigation')->nullable();
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_risks');
        Schema::dropIfExists('project_dependencies');
    }
};
