<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Extend employees with new personal / health fields ─────────────
        Schema::table('employees', function (Blueprint $table) {
            // Contact / identity
            $table->string('linkedin_profile')->nullable()->after('phone');
            $table->text('residential_address')->nullable()->after('address');

            // Health info (sensitive)
            $table->string('blood_group', 10)->nullable()->after('country');
            $table->text('allergies')->nullable()->after('blood_group');
            $table->text('known_conditions')->nullable()->after('allergies');
            $table->text('disabilities')->nullable()->after('known_conditions');
        });

        // ── 2. Emergency contacts ─────────────────────────────────────────────
        Schema::create('employee_emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('name');
            $table->string('relationship');            // e.g. Spouse, Parent, Sibling
            $table->string('phone');
            $table->string('email')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        // ── 3. Dependents ─────────────────────────────────────────────────────
        Schema::create('employee_dependents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            $table->string('relationship');            // child, spouse, parent, sibling, other
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();      // male|female|other
            $table->string('national_id')->nullable();
            $table->string('passport_number')->nullable();
            $table->string('school_institution')->nullable();
            $table->string('disability')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ── 4. Employee vehicles ──────────────────────────────────────────────
        Schema::create('employee_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('registration_number');
            $table->string('make')->nullable();        // e.g. Toyota
            $table->string('model')->nullable();       // e.g. Corolla
            $table->string('color')->nullable();
            $table->smallInteger('year')->nullable();
            $table->string('parking_slot')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_vehicles');
        Schema::dropIfExists('employee_dependents');
        Schema::dropIfExists('employee_emergency_contacts');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'linkedin_profile', 'residential_address',
                'blood_group', 'allergies', 'known_conditions', 'disabilities',
            ]);
        });
    }
};
