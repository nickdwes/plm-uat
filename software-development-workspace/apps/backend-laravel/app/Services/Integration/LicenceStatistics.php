<?php

namespace App\Services\Integration;

use App\Models\Integration\DirectoryAccount;
use App\Models\Integration\LicenceSku;
use Illuminate\Support\Collection;

/**
 * Counting the snapshot.
 *
 * Everything here is derived on read. Nothing is stored, for the reason this
 * codebase keeps arriving at: a stored total is wrong the moment anything under
 * it changes, and nothing runs nightly to correct it.
 *
 * The numbers are grouped by what somebody can act on. Seats at the ceiling are
 * urgent and idle seats are money, and they are different conversations with
 * different people — so they are not averaged into one "utilisation" figure
 * that hides both.
 */
class LicenceStatistics
{
    public function for(?int $connectionId = null): array
    {
        $skus     = $this->skus($connectionId);
        $accounts = $this->accounts($connectionId);

        $paid = $skus->filter(fn (LicenceSku $s) => $s->isPaid());

        $bought = (int) $paid->sum('seats_total');
        $used   = (int) $paid->sum('consumed_reported');

        return [
            'seats' => [
                'purchased' => $bought,
                'assigned'  => $used,
                'spare'     => max(0, $bought - $used),
                /*
                 * Over paid SKUs only. Including the free ones puts three
                 * million nominal seats in the denominator and renders this as
                 * nought on a healthy estate — which is what the first version
                 * of this arithmetic did.
                 */
                'utilisation' => $bought > 0 ? (int) round(100 * $used / $bought) : null,
            ],

            'skus' => [
                'total'    => $skus->count(),
                'paid'     => $paid->count(),
                'excluded' => $skus->count() - $paid->count(),
                // Kept visible rather than quietly dropped: a reader who does
                // not know these were set aside cannot check the judgement.
                'excluded_nominal_seats' => (int) $skus
                    ->reject(fn (LicenceSku $s) => $s->isPaid())
                    ->sum('seats_total'),
            ],

            'accounts' => $this->accountStats($accounts),

            /*
             * The one finding that needs no employee record to be true, and so
             * the only one that can be acted on before the register is
             * populated.
             */
            'waste' => $this->waste($accounts),

            'at_capacity' => $paid
                ->filter(fn (LicenceSku $s) => $s->isFull())
                ->map(fn (LicenceSku $s) => [
                    'name'      => $s->name,
                    'seats'     => $s->seats_total,
                    'assigned'  => $s->consumed_reported,
                ])->values()->all(),

            // A third consumed is where spare seats stop being slack and start
            // being a renewal conversation.
            'mostly_idle' => $paid
                ->filter(fn (LicenceSku $s) => $s->seats_total >= 5
                    && $s->consumed_reported / max(1, $s->seats_total) < 0.34)
                ->sortByDesc(fn (LicenceSku $s) => $s->seatsSpare())
                ->map(fn (LicenceSku $s) => [
                    'name'  => $s->name,
                    'idle'  => $s->seatsSpare(),
                    'seats' => $s->seats_total,
                ])->values()->all(),

            'by_sku' => $skus
                ->sortBy('name')
                ->map(fn (LicenceSku $s) => [
                    'name'           => $s->name,
                    'part_number'    => $s->part_number,
                    'classification' => $s->classification,
                    'seats'          => $s->seats_total,
                    'assigned'       => $s->consumed_reported,
                    'spare'          => $s->seatsSpare(),
                    'status'         => $s->capability_status,
                    'present'        => $s->is_present,
                ])->values()->all(),
        ];
    }

    private function accountStats(Collection $accounts): array
    {
        return [
            'total'   => $accounts->count(),
            'enabled' => $accounts->where('account_enabled', true)->count(),
            'blocked' => $accounts->where('account_enabled', false)->count(),

            /*
             * Reported as "not matched yet", not as "no employee".
             *
             * Until employees are migrated every account is unmatched, and
             * calling 151 of them orphans would turn the whole directory into
             * a page of findings nobody can act on.
             */
            'matched_to_employee' => $accounts->whereNotNull('employee_id')->count(),
            'awaiting_match'      => $accounts->whereNull('employee_id')->count(),
        ];
    }

    /**
     * Licences held by accounts that cannot sign in.
     *
     * Broken down by SKU, because "18 licences" is a number and "three Business
     * Premium and two Copilot" is a decision.
     */
    private function waste(Collection $accounts): array
    {
        $blocked = $accounts->filter(fn (DirectoryAccount $a) => $a->isPaidForNothing());

        $bySku    = [];
        $paidHeld = 0;

        foreach ($blocked as $account) {
            foreach ($account->skus as $sku) {
                $bySku[$sku->name] ??= ['name' => $sku->name, 'count' => 0, 'classification' => $sku->classification];
                $bySku[$sku->name]['count']++;

                if ($sku->classification === LicenceSkus::PAID) {
                    $paidHeld++;
                }
            }
        }

        // Paid first, then by how many — the free ones are context, not spend.
        uasort($bySku, fn ($a, $b) => [$b['classification'] === LicenceSkus::PAID, $b['count']]
            <=> [$a['classification'] === LicenceSkus::PAID, $a['count']]);

        $paidAccounts = $blocked->filter(
            fn (DirectoryAccount $a) => $a->skus->contains('classification', LicenceSkus::PAID),
        );

        return [
            'blocked_accounts_with_licences' => $blocked->count(),
            'licences_held'                  => (int) $blocked->sum('licence_count'),

            /*
             * The figure that is actually money.
             *
             * A blocked account holding six free SKUs costs nothing; counting
             * those alongside a paid seat inflates the finding, and an inflated
             * finding is one somebody checks once and then stops trusting. On
             * the real tenant this is the difference between "18 licences" and
             * three Exchange Online seats.
             */
            'paid_licences_held'             => $paidHeld,
            'accounts_holding_paid'          => $paidAccounts->count(),

            'by_sku'                         => array_values($bySku),
            'accounts'                       => $blocked
                ->sortByDesc(fn (DirectoryAccount $a) => $a->skus->contains('classification', LicenceSkus::PAID))
                ->map(fn (DirectoryAccount $a) => [
                    'name'      => $a->display_name,
                    'address'   => $a->address(),
                    'licences'  => $a->licence_count,
                    'holds_paid' => $a->skus->contains('classification', LicenceSkus::PAID),
                    'skus'      => $a->skus->map(fn ($s) => [
                        'name' => $s->name,
                        'paid' => $s->classification === LicenceSkus::PAID,
                    ])->values()->all(),
                ])->values()->all(),
        ];
    }

    private function skus(?int $connectionId): Collection
    {
        return LicenceSku::query()
            ->where('is_present', true)
            ->when($connectionId, fn ($q) => $q->where('integration_connection_id', $connectionId))
            ->get();
    }

    private function accounts(?int $connectionId): Collection
    {
        return DirectoryAccount::query()
            ->with('skus:id,name,classification')
            ->where('is_present', true)
            ->when($connectionId, fn ($q) => $q->where('integration_connection_id', $connectionId))
            ->get();
    }
}
