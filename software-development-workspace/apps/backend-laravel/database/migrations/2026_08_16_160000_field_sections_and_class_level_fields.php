<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sections, and fields that belong to a whole class.
 *
 * ── Sections ──
 *
 * A vehicle's ten fields render as one flat block, and a software asset in the
 * brief has sixty across thirteen headings — Basic, Licensing, Installation,
 * Hosting, Authentication, Security. Without grouping that is a wall, and a
 * wall is not filled in.
 *
 * ── Fields that are not about one category ──
 *
 * The brief's software sections apply to *all* software: a licence key, a
 * seat count, an authentication method and a business criticality are asked of
 * Microsoft 365, of an ERP and of an antivirus console alike. Only the last
 * section differs by category — tenant id for M365, database engine for SQL,
 * endpoint count for antivirus.
 *
 * Configuring the common ones against every category would mean the same sixty
 * fields copied seventeen times, and seventeen places to change when one is
 * wrong. So a field may now hang off a class instead of a category, and an
 * asset gets both: everything its class asks, then everything its category
 * adds.
 *
 * `asset_category_id` becomes nullable to allow it, which is the whole change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_category_fields', function (Blueprint $table) {
            /*
             * The heading this field appears under. Null means the unnamed
             * first group, which is what every existing field is.
             */
            $table->string('section', 80)->nullable()->after('help');

            // Ordering of the sections themselves, distinct from the ordering
            // of fields within one.
            $table->smallInteger('section_order')->default(0)->after('section');

            /*
             * Which class this applies to when it is not tied to a category.
             * Always set — for category fields it mirrors the category's class,
             * so a single query can ask for both without a join.
             */
            $table->string('asset_class', 24)->nullable()->after('asset_category_id');
        });

        // Backfill the class from each field's category.
        DB::statement("
            UPDATE asset_category_fields f
               SET asset_class = c.asset_class
              FROM asset_categories c
             WHERE c.id = f.asset_category_id
        ");

        /*
         * Category becomes optional so a field can belong to the class alone.
         *
         * The unique index has to go with it: two class-level fields would both
         * have a null category, and Postgres does not treat nulls as equal, so
         * the constraint would silently stop protecting anything.
         */
        Schema::table('asset_category_fields', function (Blueprint $table) {
            $table->dropUnique(['asset_category_id', 'key']);
            $table->foreignId('asset_category_id')->nullable()->change();
        });

        // Unique within a category where there is one, and within a class
        // where there is not. Two partial indexes, because one cannot say both.
        DB::statement("
            CREATE UNIQUE INDEX asset_category_fields_category_key_unique
                ON asset_category_fields (asset_category_id, key)
             WHERE asset_category_id IS NOT NULL
        ");
        DB::statement("
            CREATE UNIQUE INDEX asset_category_fields_class_key_unique
                ON asset_category_fields (organization_id, asset_class, key)
             WHERE asset_category_id IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS asset_category_fields_category_key_unique');
        DB::statement('DROP INDEX IF EXISTS asset_category_fields_class_key_unique');

        DB::table('asset_category_fields')->whereNull('asset_category_id')->delete();

        Schema::table('asset_category_fields', function (Blueprint $table) {
            $table->foreignId('asset_category_id')->nullable(false)->change();
            $table->unique(['asset_category_id', 'key']);
            $table->dropColumn(['section', 'section_order', 'asset_class']);
        });
    }
};
