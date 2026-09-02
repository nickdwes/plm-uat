<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Models\Vendor\VendorDocument;
use App\Models\Vendor\VendorDocumentReminder;
use App\Services\Asset\RenewalSchedule;
use App\Services\NotificationService;
use App\Support\Authorization\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Tell somebody before a supplier's paperwork lapses.
 *
 * Compliance documents expire quietly. A tax compliance certificate runs a
 * year and stops being valid on a date nobody has in their diary, and the
 * first anyone hears of it is a payment held up or a purchase order that
 * should never have been raised.
 *
 * Drives `Asset\RenewalSchedule` — six months, three, one, then weekly, then
 * daily inside a fortnight, then daily after it has lapsed. That class was
 * written for asset renewals and has had **no driver attached since August**:
 * the ladder, its urgency labels and its stable stage keys were all sitting
 * there unused. Same logic, so a licence and a certificate warn on the same
 * rhythm, and nobody has to learn two.
 *
 * Idempotent through `vendor_document_reminders` — one rung fires once, so a
 * run twice in a day or a day missed entirely changes nothing.
 */
class RemindVendorExpiries extends Command
{
    protected $signature = 'vendors:remind-expiries
        {--org= : One organisation, by id — everything by default}
        {--dry-run : Report what would be sent and record nothing}
        {--seed : Record the backlog silently — for the first run against live data}';

    protected $description = 'Warn about vendor compliance documents approaching or past expiry';

    public function handle(RenewalSchedule $schedule, NotificationService $notifications): int
    {
        $dry   = (bool) $this->option('dry-run');
        $seed  = (bool) $this->option('seed');
        $only  = $this->option('org') ? (int) $this->option('org') : null;
        $today = CarbonImmutable::now()->startOfDay();

        $organizations = $only
            ? Organization::whereKey($only)->pluck('id')
            : Organization::pluck('id');

        $sent = 0;

        foreach ($organizations as $organizationId) {
            // Vendor rows are read through the organisation scope, so the
            // sweep has to say which tenant it is standing in.
            app()->instance('current.organization_id', $organizationId);

            $documents = VendorDocument::query()
                ->where('organization_id', $organizationId)
                ->where('is_perpetual', false)
                ->whereNotNull('expiry_date')
                ->with('vendor')
                ->get()
                // A supplier nobody trades with any more should not be
                // generating work for the people who stopped trading with it.
                ->filter(fn (VendorDocument $d) => $d->vendor !== null
                    && $d->vendor->deleted_at === null
                    && $d->vendor->onboarding_status !== 'rejected');

            if ($documents->isEmpty()) {
                continue;
            }

            $recipients = $this->recipientsFor($organizationId);

            foreach ($documents as $document) {
                $stage = $schedule->due(
                    CarbonImmutable::parse($document->expiry_date),
                    $today,
                );

                if ($stage === null) {
                    continue;
                }

                $alreadySent = VendorDocumentReminder::where('vendor_document_id', $document->id)
                    ->where('stage_key', $stage['key'])
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

                if ($dry) {
                    $this->line(sprintf(
                        '  would warn: %s — %s (%s, %d days)',
                        $document->vendor->name, $document->label, $stage['urgency'], $stage['days_out'],
                    ));
                    $sent++;
                    continue;
                }

                VendorDocumentReminder::create([
                    'vendor_document_id' => $document->id,
                    'stage_key'          => $stage['key'],
                    'sent_on'            => $today->toDateString(),
                    'days_out'           => $stage['days_out'],
                ]);

                /*
                 * `--seed` records the backlog without telling anyone. The
                 * first run against live data would otherwise fire every rung
                 * every expired document has ever passed, at everybody, at
                 * once — which teaches people to ignore the feature on day one.
                 */
                if ($seed) {
                    $sent++;
                    continue;
                }

                foreach ($recipients as $userId) {
                    $notifications->create(
                        organizationId: (string) $organizationId,
                        userId: $userId,
                        type: 'vendor.document_expiring',
                        title: $this->title($document, $stage),
                        message: $this->message($document, $stage),
                        data: [
                            'vendor_id'   => $document->vendor_partner_id,
                            'document_id' => $document->id,
                            'days_out'    => $stage['days_out'],
                            'urgency'     => $stage['urgency'],
                        ],
                        notifiableType: VendorPartner::class,
                        notifiableId: (string) $document->vendor_partner_id,
                    );
                }

                $sent++;
            }
        }

        $this->info($dry
            ? "{$sent} warning(s) would go out."
            : ($seed ? "{$sent} rung(s) recorded silently." : "{$sent} warning(s) sent."));

        return self::SUCCESS;
    }

    /**
     * Whoever administers suppliers in this tenant.
     *
     * Asked of the permission, not a role name — a custom role that holds
     * `vendors.manage` is exactly as responsible as the seeded one, and the
     * whole RBAC epic exists so code stops naming roles.
     */
    private function recipientsFor(int $organizationId): array
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q
                ->where('user_roles.organization_id', $organizationId)
                /*
                 * A group-scoped assignment carries NO organisation — it
                 * reaches every tenant. Asking only for rows that name this
                 * one skips group administrators entirely, which is to say
                 * the people most likely to be responsible for suppliers.
                 */
                ->orWhereNull('user_roles.organization_id'))
            ->get()
            // hasPermission() understands scope, validity windows and denies;
            // the query above only narrows the candidates it has to consider.
            ->filter(fn (User $u) => $u->hasPermission(Permission::VENDORS_MANAGE, $organizationId))
            ->pluck('id')
            ->all();
    }

    private function title(VendorDocument $document, array $stage): string
    {
        return $stage['urgency'] === 'expired'
            ? "{$document->vendor->name}: {$document->label} has expired"
            : "{$document->vendor->name}: {$document->label} expires in {$stage['days_out']} days";
    }

    private function message(VendorDocument $document, array $stage): string
    {
        $on = $document->expiry_date->toFormattedDateString();

        return $stage['urgency'] === 'expired'
            ? "Expired on {$on}. The vendor is blocked from activation until it is replaced."
            : "Due for renewal on {$on}. Ask the supplier for the replacement before it lapses.";
    }
}
