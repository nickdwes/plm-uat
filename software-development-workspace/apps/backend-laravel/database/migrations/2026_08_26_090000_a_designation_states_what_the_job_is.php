<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A job title that says what the job is.
 *
 * `designations` has held a title and a description and nothing else, which
 * makes it a label rather than a role. The two things anybody actually asks of
 * a role — what somebody needs to be able to do, and what they must hold to do
 * it — had nowhere to live, so they were re-typed into every vacancy that
 * recruited for the same job.
 *
 * ── The same shape the vacancy already uses ──
 *
 * `vacancies.required_skills` is a json array of plain strings, read by
 * CandidateSuitability, which lower-cases and trims before comparing. These
 * match it deliberately: a vacancy raised against a designation should be able
 * to inherit the skills rather than restate them, and two different shapes for
 * the same idea is how the scoring silently stops matching.
 *
 * Certifications are kept apart from skills rather than folded in. A skill is
 * a claim somebody can make about themselves and a panel can assess; a
 * certification is a document with an issuer and an expiry that either exists
 * or does not. Scoring the second like the first would let a panel's opinion
 * stand in for a licence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('designations', function (Blueprint $table) {
            /*
             * The long form, kept separate from `description`. That column is
             * capped at 500 characters and is used as the one-line summary on
             * the org screens; a job description is pages, and widening the
             * summary would push a wall of text through every listing that
             * currently shows it.
             */
            $table->text('job_description')->nullable()->after('description');

            $table->json('desired_skills')->nullable()->after('job_description');
            $table->json('certifications')->nullable()->after('desired_skills');
        });
    }

    public function down(): void
    {
        Schema::table('designations', function (Blueprint $table) {
            $table->dropColumn(['job_description', 'desired_skills', 'certifications']);
        });
    }
};
