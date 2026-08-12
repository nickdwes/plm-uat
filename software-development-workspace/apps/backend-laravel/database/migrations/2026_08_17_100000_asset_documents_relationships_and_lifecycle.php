<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paperwork, dependencies, and knowing when software stops being supported.
 *
 * ── End of support as a date ──
 *
 * Lifecycle status is configured as a dropdown somebody sets by hand, which
 * rots the day after it is set: nothing moves "supported" to "end of life" when
 * the date passes. The date is the fact and the status is derived from it,
 * which is the same rule this register applies to warranties, renewals and
 * overdue work.
 *
 * ── Documents ──
 *
 * A licence agreement, a purchase invoice, a vendor contract, an architecture
 * diagram. The photo work already put an encrypted container and a disk in
 * place; this is the same thing with a type on it, because "show me the signed
 * agreement" is a different request from "show me everything attached".
 *
 * ── Relationships ──
 *
 * What runs on what, what authenticates against what, what a service depends
 * on. Typed rather than free association: "related to" tells nobody whether
 * taking the server down breaks the application, and that is the entire
 * question a dependency map exists to answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            /*
             * When the vendor stops supporting this version.
             *
             * A date, not a status. Unsupported software is a security finding
             * and it becomes one on a specific day whether or not anybody
             * remembered to change a dropdown.
             */
            $table->date('end_of_support_on')->nullable()->after('expires_on');
        });

        Schema::create('asset_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            /*
             * What kind of paper this is. Typed because the question asked is
             * almost always for one kind — the signed agreement, the invoice —
             * and a flat list of attachments makes somebody open six files to
             * find it.
             */
            $table->string('category', 40);

            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('note', 255)->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'category']);
        });

        Schema::create('asset_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // The dependant, and the thing it depends on.
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('related_asset_id')->constrained('assets')->cascadeOnDelete();

            /*
             * runs_on, hosted_by, authenticates_against, backed_up_by,
             * secured_by, depends_on, connects_to, replaces.
             *
             * Directional, and the direction matters: "the CRM runs on SRV-01"
             * and "SRV-01 runs on the CRM" are not the same claim, and only one
             * of them tells you what breaks when the server is rebooted.
             */
            $table->string('relationship', 40);
            $table->string('note', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The same pair, the same way round, twice, is a duplicate.
            $table->unique(['asset_id', 'related_asset_id', 'relationship'], 'asset_rel_unique');
            $table->index('related_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_relationships');
        Schema::dropIfExists('asset_documents');
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn('end_of_support_on'));
    }
};
