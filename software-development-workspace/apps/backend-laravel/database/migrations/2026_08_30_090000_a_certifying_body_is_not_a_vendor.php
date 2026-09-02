<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ISACA does not sell a product and does not belong in vendor_partners — it
 * certifies people against a body of knowledge it owns, the same shape as
 * (ISC)², PMI, CompTIA and AXELOS. That is a different relationship from
 * "Microsoft makes Azure", so it gets its own lookup rather than being
 * shoehorned into the vendor table as a vendor with no products.
 *
 * Global, not organisation-scoped: ISACA is the same body for every tenant
 * in this system: seeding it four times over would just be four copies to
 * keep in sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_certifying_bodies', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $seed = ['ISACA', '(ISC)²', 'PMI', 'CompTIA', 'AXELOS'];
        $now = now();
        DB::table('training_certifying_bodies')->insert(array_map(
            fn (string $name) => [
                'uuid'       => (string) Str::uuid(),
                'name'       => $name,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $seed,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('training_certifying_bodies');
    }
};
