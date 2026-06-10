<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preboarding_records', function (Blueprint $table) {
            // Employee classification
            $table->string('employee_type', 50)->nullable()->after('position'); // permanent, contract, intern, probation

            // Secure one-time link
            $table->timestamp('token_expires_at')->nullable()->after('token');
            $table->timestamp('token_used_at')->nullable()->after('token_expires_at');
            $table->unsignedSmallInteger('link_expiry_days')->default(3)->after('token_used_at');
            $table->unsignedSmallInteger('link_resend_count')->default(0)->after('link_expiry_days');

            // Medical cover eligibility (drives dependents section)
            $table->boolean('medical_cover_eligible')->default(false)->after('link_resend_count');

            // Configurable form: which sections are enabled
            // e.g. {"enabled_sections":["self_intro","personal","contact","emergency","dependents","qualifications","attachments"]}
            $table->jsonb('form_config')->nullable()->after('medical_cover_eligible');
        });
    }

    public function down(): void
    {
        Schema::table('preboarding_records', function (Blueprint $table) {
            $table->dropColumn([
                'employee_type',
                'token_expires_at',
                'token_used_at',
                'link_expiry_days',
                'link_resend_count',
                'medical_cover_eligible',
                'form_config',
            ]);
        });
    }
};
