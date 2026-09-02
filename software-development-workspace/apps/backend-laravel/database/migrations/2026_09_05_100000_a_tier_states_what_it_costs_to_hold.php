<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A partnership tier stops being a label with two numbers beside it.
 *
 * `min_certifications` and `min_headcount` are counts of nothing in
 * particular: they say "three certifications" without saying WHICH, so
 * nothing can check them and `is_current` is a flag somebody sets by hand.
 * Meanwhile the real question — are we still entitled to the Gold pricing on
 * this OEM's firewalls — depends on named training, named assessments, named
 * certifications, and on how many seats the commercial commitment covers.
 *
 * So a tier now says:
 *
 *  - what it applies to: the partnership generally, or one product;
 *  - which training, assessments and certifications it requires, and how many
 *    people must hold each;
 *  - if it carries a revenue commitment, how many seats that commitment buys
 *    and how many are actually activated.
 *
 * Which together are something compliance can be measured against, rather
 * than a number in a text box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_partnership_tiers', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();

            // 'general' = the partnership as a whole; 'product' = this tier is
            // held on one product line, which is how OEM programmes actually
            // work — Gold on firewalls and Silver on switches at once.
            $table->string('scope', 20)->default('general')->after('tier_order');
            $table->foreignId('vendor_product_id')->nullable()->after('scope')
                ->constrained('vendor_products')->nullOnDelete();

            /*
             * Seats ride with the money.
             *
             * A revenue commitment in these programmes is not a bare figure —
             * it is "this much, covering this many activated seats". Without
             * the seat count the target cannot be checked against anything
             * real, which is why the old revenue_target was decoration.
             */
            $table->unsignedInteger('seats_required')->nullable()->after('revenue_currency');
            $table->unsignedInteger('seats_activated')->nullable()->after('seats_required');

            // Effective dating, so tier history has real dates rather than one
            // boolean that only ever describes today. `is_current` is derived
            // from these by the service from here on.
            $table->date('valid_from')->nullable()->after('is_current');
            $table->date('valid_until')->nullable()->after('valid_from');
        });

        Schema::create('vendor_tier_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_partnership_tier_id')
                ->constrained('vendor_partnership_tiers')->cascadeOnDelete();

            // What kind of thing this is, for reading. What it points at is
            // below, and that is what gets counted.
            $table->string('requirement_type', 20)->default('training');

            $table->foreignId('training_item_id')->nullable()
                ->constrained('training_items')->cascadeOnDelete();
            $table->foreignId('vendor_professional_certification_id')->nullable()
                ->constrained('vendor_professional_certifications')->cascadeOnDelete();

            // How many of our people must hold it. One by default: a tier
            // requiring "a certification" means at least somebody has it.
            $table->unsignedSmallInteger('required_headcount')->default(1);
            $table->string('notes', 300)->nullable();
            $table->timestamps();

            $table->index(['vendor_partnership_tier_id', 'requirement_type'], 'tier_requirement_lookup');
        });

        // Existing tiers belong to their vendor's organisation; the column is
        // new, so nothing has one yet.
        DB::statement('
            UPDATE vendor_partnership_tiers t
            SET organization_id = v.organization_id
            FROM vendor_partners v
            WHERE v.id = t.vendor_partner_id AND t.organization_id IS NULL
        ');

        // A tier already marked current has been in force since somebody said
        // so; the best date available is when the row was written.
        DB::statement("
            UPDATE vendor_partnership_tiers
            SET valid_from = COALESCE(created_at::date, CURRENT_DATE)
            WHERE is_current = true AND valid_from IS NULL
        ");

        /*
         * An assessment is not a course and not a certification.
         *
         * Partner programmes distinguish them — a sales-accreditation
         * assessment is a different obligation from a week-long course — and
         * the type list had no word for it, so they were all filed as
         * "course" and became indistinguishable in exactly the report that
         * needed to tell them apart.
         */
        DB::statement('ALTER TABLE training_items DROP CONSTRAINT IF EXISTS training_items_type_check');
        DB::statement("
            ALTER TABLE training_items ADD CONSTRAINT training_items_type_check
            CHECK (type IN (
                'course', 'workshop', 'certification', 'assessment', 'exam_voucher',
                'subscription', 'on_job', 'mentoring', 'conference'
            ))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_tier_requirements');

        Schema::table('vendor_partnership_tiers', function (Blueprint $table) {
            $table->dropColumn([
                'organization_id', 'scope', 'vendor_product_id',
                'seats_required', 'seats_activated', 'valid_from', 'valid_until',
            ]);
        });

        DB::statement('ALTER TABLE training_items DROP CONSTRAINT IF EXISTS training_items_type_check');
        DB::statement("
            ALTER TABLE training_items ADD CONSTRAINT training_items_type_check
            CHECK (type IN (
                'course', 'workshop', 'certification', 'exam_voucher',
                'subscription', 'on_job', 'mentoring', 'conference'
            ))
        ");
    }
};
