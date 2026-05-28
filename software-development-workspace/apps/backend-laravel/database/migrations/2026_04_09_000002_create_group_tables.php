<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string|boolean|integer|json
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('shared_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');             // leave|payroll|compliance|appraisal
            $table->json('rules');              // policy ruleset
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Which orgs have adopted a particular shared policy (orgs can override)
        Schema::create('organization_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('shared_policy_id')->constrained('shared_policies')->cascadeOnDelete();
            $table->json('overrides')->nullable();   // org-specific rule overrides
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'shared_policy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_policies');
        Schema::dropIfExists('shared_policies');
        Schema::dropIfExists('group_settings');
    }
};
