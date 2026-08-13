<?php

namespace Tests\Feature\Integration;

use App\Services\Integration\LicenceSkus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Telling a licence you pay for from one you merely have.
 *
 * These cases are taken from a real tenant, because the bug they guard against
 * only appeared against real data: the free SKUs carry nominal seat counts in
 * the millions, and summing them dropped seat utilisation to nought across an
 * estate that was actually 56% consumed.
 */
class LicenceSkuClassificationTest extends TestCase
{
    private LicenceSkus $skus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skus = new LicenceSkus();
    }

    public static function skuProvider(): array
    {
        return [
            // Paid, and the seat counts are real purchases.
            'business premium'   => ['Microsoft_365_ Business_ Premium_(no Teams)', 55, LicenceSkus::PAID],
            'exchange plan 1'    => ['EXCHANGESTANDARD', 45, LicenceSkus::PAID],
            'entra p2'           => ['AAD_PREMIUM_P2', 50, LicenceSkus::PAID],
            'copilot'            => ['Microsoft_365_Copilot', 12, LicenceSkus::PAID],
            'business central'   => ['DYN365_BUSCENTRAL_PREMIUM', 50, LicenceSkus::PAID],

            // Free, and the seat counts are nominal ceilings.
            'windows store'      => ['WINDOWS_STORE', 1_000_000, LicenceSkus::FREE],
            'customer voice'     => ['FORMS_PRO', 1_000_000, LicenceSkus::FREE],
            'power bi free'      => ['POWER_BI_STANDARD', 1_000_000, LicenceSkus::FREE],
            'power apps viral'   => ['POWERAPPS_VIRAL', 10_000, LicenceSkus::FREE],
            'flow free'          => ['FLOW_FREE', 10_000, LicenceSkus::FREE],

            // Trials, pilots and partner sandboxes.
            'sales trial'        => ['Dynamics_365_Sales_Premium_Viral_Trial', 10_000, LicenceSkus::TRIAL],
            'partner sandbox'    => ['Dynamics_365_Business_Central_Partner_Sandbox', 10, LicenceSkus::TRIAL],

            // The backstop: an unknown SKU with a nominal ceiling is not a purchase.
            'unknown nominal'    => ['SOME_FUTURE_FREE_THING', 1_000_000, LicenceSkus::FREE],
            'unknown ordinary'   => ['SOME_FUTURE_PAID_THING', 25, LicenceSkus::PAID],
        ];
    }

    #[DataProvider('skuProvider')]
    public function test_skus_are_bucketed(string $part, int $seats, string $expected): void
    {
        $this->assertSame($expected, $this->skus->classify($part, $seats), $part);
    }

    /**
     * The bug this whole class exists for.
     *
     * Against the real tenant: 473 paid seats with 265 in use is 56%. Summing
     * every SKU puts three million nominal seats in the denominator and reports
     * nought, on an estate where four SKUs are completely full.
     */
    public function test_nominal_seats_do_not_drown_utilisation(): void
    {
        $tenant = [
            ['EXCHANGESTANDARD', 45, 29],
            ['AAD_PREMIUM_P2', 50, 4],
            ['Microsoft_365_ Business_ Premium_(no Teams)', 55, 55],
            ['WINDOWS_STORE', 1_000_000, 0],
            ['FORMS_PRO', 1_000_000, 0],
            ['POWER_BI_STANDARD', 1_000_000, 22],
        ];

        $naiveBought = 0;
        $paidBought  = 0;
        $paidUsed    = 0;

        foreach ($tenant as [$part, $seats, $used]) {
            $naiveBought += $seats;

            if ($this->skus->isCounted($part, $seats)) {
                $paidBought += $seats;
                $paidUsed   += $used;
            }
        }

        $this->assertSame(3_000_150, $naiveBought);
        $this->assertSame(0, (int) round(100 * 88 / $naiveBought), 'the naive sum rounds to nought');

        $this->assertSame(150, $paidBought);
        $this->assertSame(88, $paidUsed);
        $this->assertSame(59, (int) round(100 * $paidUsed / $paidBought));
    }

    public function test_part_numbers_become_readable_names(): void
    {
        $this->assertSame('Entra ID P2', $this->skus->name('AAD_PREMIUM_P2'));
        $this->assertSame('Microsoft 365 Business Basic', $this->skus->name('O365_BUSINESS_ESSENTIALS'));
    }

    /**
     * An unmapped SKU keeps its own name rather than being given a made-up one.
     *
     * A wrong friendly name is worse than a cryptic accurate one, because only
     * the cryptic one makes somebody go and check.
     */
    public function test_unmapped_part_numbers_are_tidied_not_invented(): void
    {
        $this->assertSame('PROJECTPREMIUM', $this->skus->name('PROJECTPREMIUM'));
        $this->assertSame('CRM HYBRIDCONNECTOR', $this->skus->name('CRM_HYBRIDCONNECTOR'));
    }

    /**
     * Graph returns part numbers containing zero-width characters — the real
     * tenant has one on its Cloud PC SKU — and two identical-looking names that
     * fail to match are the sort of thing that costs an afternoon.
     */
    public function test_zero_width_characters_are_stripped_from_names(): void
    {
        $this->assertSame('CPC E 8C 32GB 512GB', $this->skus->name("CPC_E_8C_32GB_512GB\u{200B}"));
    }
}
