<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project names its customer team and stakeholders.
 *
 * The other half of governance is the client's own structure — who sponsors it
 * on their side, who owns it, who to engage for finance or operations, and the
 * wider stakeholder map. Customer roles point at the account's contacts (the
 * customer's people already live there); stakeholders are a register that can
 * link a contact or stand alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_customer_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('role_key', 60);
            $table->foreignId('account_contact_id')->nullable()->constrained('account_contacts')->nullOnDelete();
            $table->text('responsibility')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'role_key']);
        });

        Schema::create('project_stakeholders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('account_contact_id')->nullable()->constrained('account_contacts')->nullOnDelete();

            $table->string('name');
            $table->string('title')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('persona')->nullable();       // decision maker, influencer, end user, gatekeeper…
            $table->string('influence', 20)->nullable();  // high / medium / low
            $table->string('interest', 20)->nullable();   // high / medium / low
            $table->text('responsibility')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_stakeholders');
        Schema::dropIfExists('project_customer_roles');
    }
};
