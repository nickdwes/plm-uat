<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The skills a role needs, on the thing people apply to.
 *
 * They are recorded today on the requisition — the internal request to hire —
 * and nowhere on the vacancy, which is what an applicant actually sees and
 * answers. So the one document that could ask "do you have these?" had no idea
 * what "these" were, and the application form collected years of experience, a
 * cover letter and a LinkedIn URL while the skills the job was raised for sat
 * one table away, unread.
 *
 * Copied rather than joined. A requisition is a request made at a moment in
 * time and can be edited or withdrawn afterwards; a vacancy that shifted its
 * requirements under applicants who had already answered would be judging them
 * against a bar they never saw. And a vacancy raised without a requisition —
 * which the schema allows — could otherwise never state a requirement at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancies', function (Blueprint $table) {
            $table->json('required_skills')->nullable()->after('description');
        });

        // Vacancies that came from a requisition inherit what it asked for.
        DB::statement(<<<'SQL'
            UPDATE vacancies v
               SET required_skills = r.skills
              FROM talent_requisitions r
             WHERE r.id = v.talent_requisition_id
               AND r.skills IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('vacancies', function (Blueprint $table) {
            $table->dropColumn('required_skills');
        });
    }
};
