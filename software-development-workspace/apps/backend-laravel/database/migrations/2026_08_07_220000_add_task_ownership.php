<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not every task is ours to do.
 *
 * The legacy carried `task_owner`, defaulted to Konvergenz and left that way on
 * 7,977 of its 9,055 tasks — but 104 name somebody else, across about thirty
 * organisations. Customers: Safaricom 26, KBC 7, Equity 5, KCBU 4, KCB 3, DTB
 * 3. OEMs and suppliers: Versa 8, Data Core Ltd 7, VTS 3. Those are tasks the
 * client or the vendor owes, tracked here because they hold up our work, and 97
 * of the 104 were followed all the way to Completed. This is not a note in a
 * comment; it was a tracked, closed-out commitment.
 *
 * It also carried `responsible_person` — named on 95 of those 104 — free text
 * like "Cyril", "Milton Ope", "Fatih/Bahdir". Free text because those people
 * work for the customer, not for us, so they have no employee record. That part
 * is right and is kept.
 *
 * What was wrong was leaving both as strings. One row reads "Versa-Fatih",
 * which is an organisation and a person jammed into one field, and 974 rows
 * left the owner blank — you cannot count what you cannot spell consistently.
 * So the party is a link to the customer or the vendor we already have on file,
 * and only the person stays free text.
 *
 * Why this matters beyond tidiness: without it, "waiting on the client" can
 * only be written as a free-text dependency, which cannot be counted, chased or
 * reported. And it is the same distinction as the OEM professional-services
 * split already carried on projects — an OEM-owned task is OEM effort, not
 * ours, and mixing the two makes our own capacity look worse than it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            /*
             * Who owes the work: internal | customer | vendor.
             *
             * Defaulted to internal, which is what 7,977 of the legacy's rows
             * meant and 974 more meant by leaving it blank. A default rather
             * than nullable, because "nobody said" and "ours" are the same
             * thing here and a null would invite a third state that means
             * neither.
             */
            $table->string('owner_party', 16)->default('internal')->after('assigned_to');

            $table->foreignId('owner_account_id')->nullable()->after('owner_party')
                ->constrained('accounts')->nullOnDelete();
            $table->foreignId('owner_vendor_partner_id')->nullable()->after('owner_account_id')
                ->constrained('vendor_partners')->nullOnDelete();

            /*
             * Who there.
             *
             * Linked where the person is already a customer contact, and free
             * text otherwise — which is most of the time. Vendors have no
             * contact table at all, and a client's project engineer is rarely
             * on file before the task naming them exists.
             */
            $table->foreignId('owner_contact_id')->nullable()->after('owner_vendor_partner_id')
                ->constrained('account_contacts')->nullOnDelete();
            $table->string('owner_contact_name')->nullable()->after('owner_contact_id');

            // "Everything still waiting on the client" is the question this
            // exists to answer, and it is asked per organisation.
            $table->index(['organization_id', 'owner_party']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'owner_party']);
            $table->dropConstrainedForeignId('owner_account_id');
            $table->dropConstrainedForeignId('owner_vendor_partner_id');
            $table->dropConstrainedForeignId('owner_contact_id');
            $table->dropColumn(['owner_party', 'owner_contact_name']);
        });
    }
};
