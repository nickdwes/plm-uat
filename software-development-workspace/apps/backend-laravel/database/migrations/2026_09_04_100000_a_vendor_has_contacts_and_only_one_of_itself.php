<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two long-standing gaps in the vendor master, closed together.
 *
 * **Contacts.** Vendors have had one denormalised name on the row and nothing
 * else — `2026_08_07_220000` records the consequence: a vendor-owned task
 * cannot name a real contact because "vendors have no contact table at all",
 * so it falls back to free text. Customers have had a proper, categorised,
 * soft-deletable contact table since August; this is that table, for the
 * other half of the register.
 *
 * **Duplicates.** `accounts` carries `unique(organization_id, name)` with a
 * comment explaining that duplicates are the main way a master rots — half
 * the history on each record. Vendors never got it, and it matters more here:
 * a duplicate vendor splits purchase orders, invoices and performance history
 * across two rows and quietly breaks the scorecard. Folded on lower(name) so
 * "ACME Ltd" and "Acme Ltd" cannot coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_partner_id')->constrained('vendor_partners')->cascadeOnDelete();
            $table->string('category', 30)->default('other');
            $table->string('title', 32)->nullable();
            $table->string('first_name', 128);
            $table->string('last_name', 128)->nullable();
            $table->string('designation', 128)->nullable();
            $table->string('department', 128)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('alternate_phone', 32)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_partner_id', 'is_active']);
            $table->index(['organization_id', 'email']);
        });

        // Carry the one contact each vendor already had into a real row, so
        // the new table starts populated rather than looking empty on day one.
        foreach (
            DB::table('vendor_partners')
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNotNull('primary_contact_name')->orWhereNotNull('contact_email'))
                ->get(['id', 'organization_id', 'primary_contact_name', 'primary_contact_designation', 'contact_email', 'contact_phone'])
            as $vendor
        ) {
            $name = trim((string) $vendor->primary_contact_name);

            // No name but an address still deserves a row — the mailbox is
            // who people write to, and "Accounts" beats an empty tab.
            $first = $name !== '' ? \Illuminate\Support\Str::before($name, ' ') : 'Contact';
            $last  = $name !== '' && str_contains($name, ' ') ? \Illuminate\Support\Str::after($name, ' ') : null;

            DB::table('vendor_contacts')->insert([
                'organization_id'   => $vendor->organization_id,
                'vendor_partner_id' => $vendor->id,
                'category'          => 'primary',
                'first_name'        => $first,
                'last_name'         => $last,
                'designation'       => $vendor->primary_contact_designation,
                'email'             => $vendor->contact_email,
                'phone'             => $vendor->contact_phone,
                'is_primary'        => true,
                'is_active'         => true,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }

        /*
         * Fold existing collisions before the constraint lands, or the
         * migration fails on any tenant that already has two "Acme Ltd".
         * Renamed, never deleted — the rows carry history the operator has to
         * decide about, and silently merging that here would be worse than a
         * name they can see and fix.
         */
        $collisions = DB::table('vendor_partners')
            ->whereNull('deleted_at')
            ->selectRaw('organization_id, lower(name) AS folded, count(*) AS n')
            ->groupBy('organization_id', 'folded')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($collisions as $collision) {
            $rows = DB::table('vendor_partners')
                ->whereNull('deleted_at')
                ->where('organization_id', $collision->organization_id)
                ->whereRaw('lower(name) = ?', [$collision->folded])
                ->orderBy('id')
                ->get(['id', 'name']);

            // The oldest keeps the name; the rest are marked for a human.
            foreach ($rows->skip(1) as $i => $row) {
                DB::table('vendor_partners')->where('id', $row->id)->update([
                    'name'       => $row->name . ' (duplicate ' . ($i + 2) . ')',
                    'updated_at' => now(),
                ]);
            }
        }

        // Partial: a soft-deleted vendor must not block the name's reuse.
        DB::statement(
            'CREATE UNIQUE INDEX vendor_partners_org_name_unique
             ON vendor_partners (organization_id, lower(name))
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS vendor_partners_org_name_unique');
        Schema::dropIfExists('vendor_contacts');
    }
};
