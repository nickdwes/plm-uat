<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The free-text "vendor" column added a week ago was a guess at this shape
 * before the real spec existed — a fixed list of vendor names with no link
 * to Vendor Management, and no way to say "not a vendor at all, this is a
 * PMI certification." Replaced here with two real relationships instead:
 * a training item points at either a vendor_partners row (OEM-based) or a
 * training_certifying_bodies row (professional body), never both, and
 * training_provider_type records which. The catalog is still empty in
 * every environment this has shipped to, so the old column is dropped
 * outright rather than carried forward unused.
 *
 * training_item_products is a plain many-to-many: one course can cover
 * several of a vendor's products (an "Azure Security Fundamentals" course
 * might span Azure AD and Defender), and the unique pair is what "a product
 * cannot be selected more than once" actually means at the data layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_items', function (Blueprint $table) {
            $table->dropColumn('vendor');
        });

        Schema::table('training_items', function (Blueprint $table) {
            $table->string('training_provider_type', 20)->nullable()->after('provider_url');
            $table->foreignId('certifying_body_id')->nullable()
                ->after('training_provider_type')
                ->constrained('training_certifying_bodies')->nullOnDelete();
            $table->foreignId('vendor_partner_id')->nullable()
                ->after('certifying_body_id')
                ->constrained('vendor_partners')->nullOnDelete();
        });

        Schema::create('training_item_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_item_id')->constrained('training_items')->cascadeOnDelete();
            $table->foreignId('vendor_product_id')->constrained('vendor_products')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['training_item_id', 'vendor_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_item_products');

        Schema::table('training_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_partner_id');
            $table->dropConstrainedForeignId('certifying_body_id');
            $table->dropColumn('training_provider_type');
        });

        Schema::table('training_items', function (Blueprint $table) {
            $table->string('vendor')->nullable()->after('provider_name');
        });
    }
};
