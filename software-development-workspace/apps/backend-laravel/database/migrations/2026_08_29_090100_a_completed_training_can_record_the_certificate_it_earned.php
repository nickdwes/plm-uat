<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The score a learner achieved was already tracked; which certificate that
 * score earned, and when it lapses, was not — so nobody could tell from
 * this record alone whether someone's AWS or Cisco certification was still
 * current. The certificate file itself still goes through the existing
 * evidence-upload path (training_evidence_files); these two columns are
 * just the structured facts a file attachment can't answer on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_training_records', function (Blueprint $table) {
            $table->string('certificate_number')->nullable()->after('score');
            $table->date('certificate_expiry_date')->nullable()->after('certificate_number');
        });
    }

    public function down(): void
    {
        Schema::table('employee_training_records', function (Blueprint $table) {
            $table->dropColumn(['certificate_number', 'certificate_expiry_date']);
        });
    }
};
