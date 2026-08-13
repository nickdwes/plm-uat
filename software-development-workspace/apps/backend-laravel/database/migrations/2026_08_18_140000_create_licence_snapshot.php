<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the directory says, kept so it can be counted.
 *
 * ── Why this is not employees ──
 *
 * The tenant holds 151 licensed accounts. The employee register holds one. The
 * temptation is to import the directory into `employees` and call the register
 * populated, and it would be wrong: an employee record carries a start date, a
 * contract, a line manager and a joiner process that somebody owns. Filling in
 * a name and an address and leaving the rest blank produces 151 half-records
 * that look complete enough to stop anybody entering the real thing.
 *
 * So the directory lands here instead, as its own snapshot, and answers the
 * questions that need no employee at all: how many seats are paid for, how many
 * are held, and which of the accounts holding them cannot sign in.
 *
 * `employee_id` is nullable and stays null for now. When employees are migrated
 * the match writes into it, and nothing else about these tables changes.
 *
 * ── Why a snapshot and not a passthrough ──
 *
 * Reading Graph on every page load would make a dashboard depend on Microsoft
 * being up and on a token that expires, and would put a directory read behind
 * every refresh. It also could not answer "what did we have last month", which
 * is the question asked after somebody queries an invoice.
 *
 * ── Disappearing rows ──
 *
 * Nothing is deleted when it stops appearing. `last_seen_at` records when the
 * tenant last reported it and `is_present` says whether the most recent sync
 * still found it. A licence that vanishes is a fact worth keeping — usually it
 * means somebody removed a seat, which is exactly what a cost review wants to
 * see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_licence_skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_connection_id')
                ->constrained('integration_connections')->cascadeOnDelete();

            $table->uuid('sku_id');
            $table->string('part_number', 120);
            $table->string('name', 160);

            /*
             * paid, free or trial — stored rather than recomputed on read.
             *
             * The classification is a heuristic that will be corrected as SKUs
             * are added, and a stored value keeps an old snapshot consistent
             * with what was believed at the time rather than silently
             * rewriting history when the rules change.
             */
            $table->string('classification', 16);

            $table->unsignedInteger('seats_total')->default(0);

            /*
             * Microsoft's own count of assigned seats, kept apart from anything
             * this system derives. Where it disagrees with the accounts we
             * matched, the gap is the finding — not an error to be reconciled
             * away.
             */
            $table->unsignedInteger('consumed_reported')->default(0);

            $table->unsignedInteger('suspended_units')->default(0);
            $table->string('capability_status', 32)->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_present')->default(true);
            $table->timestamps();

            $table->unique(['integration_connection_id', 'sku_id'], 'licence_sku_unique');
            $table->index(['organization_id', 'classification']);
        });

        Schema::create('integration_directory_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_connection_id')
                ->constrained('integration_connections')->cascadeOnDelete();

            // The directory's own id, which survives a rename or a change of
            // address. Matching on those instead would lose somebody the day
            // they marry.
            $table->uuid('external_id');

            $table->string('display_name', 200)->nullable();
            $table->string('user_principal_name', 200)->nullable();
            $table->string('mail', 200)->nullable();

            /*
             * Whether the account can sign in.
             *
             * The one field here that is worth money on its own: a blocked
             * account cannot be in use by anybody, so every licence on it is
             * being paid for and consumed by nobody. That needs no employee
             * record to be true, which is why it can be reported today.
             */
            $table->boolean('account_enabled')->default(true);

            $table->unsignedSmallInteger('licence_count')->default(0);

            /*
             * Filled by the match, which comes after employees are migrated.
             * Null does not mean "not an employee" — for now it means "not
             * looked at yet", and the statistics say so rather than counting
             * every account as an orphan.
             */
            $table->foreignId('employee_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_present')->default(true);
            $table->timestamps();

            $table->unique(['integration_connection_id', 'external_id'], 'directory_account_unique');
            $table->index(['organization_id', 'account_enabled']);
        });

        Schema::create('integration_account_licences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_directory_account_id')
                ->constrained('integration_directory_accounts')->cascadeOnDelete();
            $table->foreignId('integration_licence_sku_id')
                ->constrained('integration_licence_skus')->cascadeOnDelete();
            $table->timestamps();

            /*
             * Which account holds which SKU, so "18 licences on blocked
             * accounts" can become "three Business Premium and two Copilot" —
             * the difference between a number and a decision.
             */
            $table->unique(
                ['integration_directory_account_id', 'integration_licence_sku_id'],
                'account_licence_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_account_licences');
        Schema::dropIfExists('integration_directory_accounts');
        Schema::dropIfExists('integration_licence_skus');
    }
};
