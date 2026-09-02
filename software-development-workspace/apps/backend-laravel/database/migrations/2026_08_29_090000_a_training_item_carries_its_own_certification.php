<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The catalog form only ever asked what a training item costs and how long
 * it runs. It has no way to say a course issues a vendor certification, what
 * exam sits behind it, or when that certification lapses — so every AWS,
 * Cisco or (ISC)2 course in the catalog looks identical to an internal
 * lunch-and-learn. This adds that shape without inventing a second entity:
 * classification and certification detail live on the training item itself,
 * the way the rest of the catalog form already does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_items', function (Blueprint $table) {
            $table->string('training_code')->nullable()->unique()->after('uuid');
            $table->string('training_version', 50)->nullable()->after('title');
            $table->string('vendor')->nullable()->after('provider_name');

            // Difficulty label only — no XP or badge behind it yet.
            $table->string('level', 20)->nullable()->after('delivery');
            $table->boolean('is_technical')->default(false)->after('level');
            $table->boolean('requires_proctored_exam')->default(false)->after('is_technical');

            // Certification detail is only meaningful when this is true;
            // the frontend hides the fields below it otherwise.
            $table->boolean('certificate_issued')->default(false)->after('requires_proctored_exam');
            $table->string('certification_name')->nullable()->after('certificate_issued');
            $table->string('exam_code', 50)->nullable()->after('certification_name');
            $table->string('exam_duration', 50)->nullable()->after('exam_code');
            $table->unsignedTinyInteger('passing_score')->nullable()->after('exam_duration');
            $table->string('exam_provider')->nullable()->after('passing_score');
            $table->unsignedTinyInteger('validity_years')->nullable()->after('exam_provider');
            $table->boolean('renewal_required')->default(false)->after('validity_years');
            $table->unsignedSmallInteger('cpe_credits')->nullable()->after('renewal_required');
        });

        // Backfill existing rows so "training_code" reads as a real catalog
        // number rather than blank for everything created before this
        // migration — numbered per organisation, in creation order.
        $rows = DB::table('training_items')->orderBy('organization_id')->orderBy('id')->get(['id', 'organization_id']);
        $seq = [];
        foreach ($rows as $row) {
            $seq[$row->organization_id] = ($seq[$row->organization_id] ?? 0) + 1;
            DB::table('training_items')->where('id', $row->id)->update([
                'training_code' => 'TRN-' . str_pad((string) $seq[$row->organization_id], 4, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('training_items', function (Blueprint $table) {
            $table->dropColumn([
                'training_code', 'training_version', 'vendor',
                'level', 'is_technical', 'requires_proctored_exam',
                'certificate_issued', 'certification_name', 'exam_code',
                'exam_duration', 'passing_score', 'exam_provider',
                'validity_years', 'renewal_required', 'cpe_credits',
            ]);
        });
    }
};
