<?php

namespace App\Services\Vendor;

use App\Models\Training\VendorPartner;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Is this vendor already in the register?
 *
 * A duplicate supplier is not a tidiness problem: purchase orders, invoices
 * and delivery history split across two rows, and the performance scorecard
 * — which is computed from exactly those — starts answering a question
 * nobody asked. `accounts` has blocked duplicates on name since August;
 * vendors never did, and they carry more downstream weight.
 *
 * Exact matching only, on the identifiers that actually identify a company.
 * No fuzzy scoring: with a register of this size a near-match list would be
 * mostly noise, and the honest answer to "is this the same firm?" comes from
 * a registration number, not an edit distance. The candidates are handed to
 * a person to decide; nothing is merged automatically.
 */
class DuplicateProbe
{
    /**
     * Mailbox providers that say nothing about which company someone is at.
     *
     * Same list as AccountLogoFetcher, for the same reason: two vendors on
     * gmail.com are not the same vendor.
     */
    private const FREE_MAIL = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'ymail.com',
        'hotmail.com', 'outlook.com', 'live.com', 'msn.com', 'icloud.com',
        'me.com', 'aol.com', 'protonmail.com', 'proton.me', 'gmx.com', 'mail.com',
        'zoho.com', 'yandex.com',
    ];

    /**
     * Candidates that may be the same party, strongest signal first.
     *
     * @return array<int, array{id:int, name:string, vendor_number:?string, matched_on:string, detail:string}>
     */
    public function candidatesFor(int $orgId, array $data, ?int $ignoreId = null): array
    {
        $found = collect();

        // A registration number or tax ID is the company, definitionally.
        foreach (['registration_number' => 'registration number', 'tax_id' => 'tax ID'] as $field => $label) {
            $value = trim((string) ($data[$field] ?? ''));

            if ($value === '') {
                continue;
            }

            $this->matches($orgId, $ignoreId)
                ->whereRaw("lower({$field}) = ?", [mb_strtolower($value)])
                ->get()
                ->each(fn (VendorPartner $v) => $found->push($this->row($v, $label, $value)));
        }

        // The name, folded — what the unique index will refuse anyway.
        $name = trim((string) ($data['name'] ?? ''));
        if ($name !== '') {
            $this->matches($orgId, $ignoreId)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->get()
                ->each(fn (VendorPartner $v) => $found->push($this->row($v, 'name', $v->name)));
        }

        // A shared corporate email domain — weaker, but it is how the same
        // supplier gets entered twice under two trading names.
        $domain = $this->corporateDomain($data['contact_email'] ?? null);
        if ($domain !== null) {
            $this->matches($orgId, $ignoreId)
                ->whereRaw("lower(split_part(contact_email, '@', 2)) = ?", [$domain])
                ->get()
                ->each(fn (VendorPartner $v) => $found->push($this->row($v, 'email domain', $domain)));
        }

        // One row per vendor: the first (strongest) reason it surfaced wins.
        return $found->unique('id')->values()->all();
    }

    private function matches(int $orgId, ?int $ignoreId)
    {
        return VendorPartner::query()
            ->where('organization_id', $orgId)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId));
    }

    private function row(VendorPartner $vendor, string $matchedOn, string $detail): array
    {
        return [
            'id'                => $vendor->id,
            'name'              => $vendor->name,
            'vendor_number'     => $vendor->vendor_number,
            'onboarding_status' => $vendor->onboarding_status,
            'matched_on'        => $matchedOn,
            'detail'            => $detail,
        ];
    }

    /** The domain, unless it is a mailbox anyone can open. */
    private function corporateDomain(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }

        $domain = mb_strtolower(trim(Str::afterLast($email, '@')));

        if (! str_contains($domain, '.') || in_array($domain, self::FREE_MAIL, true)) {
            return null;
        }

        return $domain;
    }
}
