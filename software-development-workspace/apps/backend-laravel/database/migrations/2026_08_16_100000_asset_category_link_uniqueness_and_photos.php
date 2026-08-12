<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three things the dynamic register needs before it fills up.
 *
 * ── The category becomes a link ──
 *
 * `assets.category` is free text. The whole field engine works by matching that
 * string against a configured category's name and class — which holds until
 * somebody renames a category, or types "Laptop" where the list says "laptop",
 * and every custom field silently stops appearing.
 *
 * The string stays, kept in step on write. Reports, exports and the list
 * filters all read it, and forcing every one of them through a join to display
 * a word is a poor trade. The link is the truth; the string is a copy that the
 * service maintains.
 *
 * ── Uniqueness ──
 *
 * An IMEI, a VIN, a MAC address and a hostname are each supposed to identify
 * exactly one thing. Two assets sharing one means somebody has registered the
 * same laptop twice, and every count from then on is wrong.
 *
 * Enforced in the validator rather than by a database constraint, deliberately.
 * A unique index over a JSON key has to name that key, and the keys here are
 * configuration — so the alternative is the application issuing DDL at runtime
 * off the back of a form somebody filled in. That trade is not worth it at this
 * size, and the check belongs where the error message can be useful.
 *
 * ── Photos ──
 *
 * The condition an asset was in when it was handed over is the thing argued
 * about when it comes back. A photograph settles it, and there was nowhere to
 * put one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('asset_category_id')->nullable()->after('category')
                ->constrained('asset_categories')->nullOnDelete();
        });

        /*
         * Point existing rows at their category where the name and class match.
         * Anything typed freehand — including everything filed under "other" —
         * stays unlinked rather than being guessed at.
         */
        DB::statement("
            UPDATE assets a
               SET asset_category_id = c.id
              FROM asset_categories c
             WHERE c.organization_id = a.organization_id
               AND c.asset_class     = a.asset_class
               AND lower(c.name)     = lower(a.category)
        ");

        Schema::table('asset_category_fields', function (Blueprint $table) {
            /*
             * No two assets may share this value.
             *
             * Scoped to the organisation and the category: two tenants may
             * legitimately hold the same asset tag, and a serial number that
             * clashes across a laptop and a monitor is a coincidence rather
             * than a duplicate.
             */
            $table->boolean('is_unique')->default(false)->after('is_required');
        });

        Schema::create('asset_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            // The vault path. Encrypted like every other document class, on the
            // asset_documents disk.
            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();

            /*
             * What the photograph shows.
             *
             * "Scratch on the lid, present at handover" is the caption that
             * settles an argument eighteen months later.
             */
            $table->string('caption', 255)->nullable();

            // The one shown beside the asset everywhere else.
            $table->boolean('is_primary')->default(false);

            /*
             * Taken at handover, as opposed to added later.
             *
             * The condition at the moment custody changed is a different claim
             * from the condition today, and only one of them settles a dispute.
             */
            $table->boolean('at_handover')->default(false);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_photos');

        Schema::table('asset_category_fields', fn (Blueprint $t) => $t->dropColumn('is_unique'));

        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_category_id');
        });
    }
};
