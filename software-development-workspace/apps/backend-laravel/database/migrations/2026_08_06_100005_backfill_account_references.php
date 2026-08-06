<?php

use App\Support\Account\ReferenceSequence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A reference for every customer that predates the column.
 *
 * Left null, those records show "—" where the screens show a Client ID, and
 * they never fill in on their own: allocation happens on create, and these are
 * already created. Done as its own migration rather than folded into the one
 * that added the column, because that one has already run.
 *
 * Enrolment date is set from when the record was created, which is the closest
 * true answer available and better than a blank on the Overview.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')->whereNull('reference')->orderBy('id')
            ->get(['id', 'organization_id', 'created_at'])
            ->each(function ($account) {
                DB::table('accounts')->where('id', $account->id)->update([
                    'reference' => ReferenceSequence::next('CL', 'accounts', (int) $account->organization_id),
                ]);
            });

        DB::statement('UPDATE accounts SET enrolled_on = created_at::date WHERE enrolled_on IS NULL AND created_at IS NOT NULL');

        DB::table('account_contracts')->whereNull('reference')->orderBy('id')
            ->get(['id', 'organization_id'])
            ->each(function ($contract) {
                DB::table('account_contracts')->where('id', $contract->id)->update([
                    'reference' => ReferenceSequence::next('CT', 'account_contracts', (int) $contract->organization_id),
                ]);
            });
    }

    public function down(): void
    {
        // Nothing. Taking references away would break anything that has since
        // quoted one, and a reference is meant to outlive the row it names.
    }
};
