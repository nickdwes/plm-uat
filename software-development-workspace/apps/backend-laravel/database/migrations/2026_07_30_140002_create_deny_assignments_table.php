<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit denial, evaluated last and always winning.
 *
 * Roles are additive: hold two and you get the union, which makes "everything
 * except X" impossible to express by adding roles. A deny assignment is the
 * subtractive half — a person under investigation excluded from payroll data,
 * a contractor barred from a resource their role would otherwise reach.
 *
 * Deny should stay rare. A system accumulating them has the wrong roles, and
 * the count is worth watching for that reason.
 *
 * `permission` accepts wildcards on the same rules as a grant, so denying
 * `payroll.*` needs one row rather than five.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deny_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Wildcards permitted, matched by Permission::satisfies().
            $table->string('permission');

            // Null organization = the denial applies in every tenant.
            $table->foreignId('organization_id')->nullable()
                ->constrained('organizations')->cascadeOnDelete();

            // A denial with no expiry is permanent until removed. Temporary
            // ones should carry a date so they lapse rather than being
            // forgotten in place.
            $table->timestamp('expires_at')->nullable();

            // Denials restrict a person's access; the reason is not optional.
            $table->string('reason');
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'organization_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deny_assignments');
    }
};
