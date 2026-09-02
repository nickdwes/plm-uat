<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Read-time redaction of audit payloads.
 *
 * PiiMasker already decides what is WRITTEN into an entry — salaries and
 * identifiers are withheld for everyone, contactables stored partially
 * masked. This class decides what a particular READER gets back: when an
 * entry's diff touches a classified field and the caller lacks the
 * *underlying* permission, the values are withheld for them. Otherwise the
 * audit log would be a side-channel — a reader without
 * `accounts.view_contacts` could not open a contact card, but could read the
 * old→new of its phone number here.
 *
 * Deliberately zero new permissions: the permission that classifies a field
 * on its own screen is the one that classifies it in the trail.
 */
class AuditRedactor
{
    /**
     * Which fields, on which models, answer to which permission.
     *
     * Field-level on purpose: an entry usually mixes classified and
     * operational changes, and the operational half ("status: draft→active")
     * is exactly what an auditor without the data permission is entitled to.
     */
    private const CLASSIFIED = [
        // Customer contact details — same constant that masks them on the
        // account and lead screens.
        'App\Models\Account\AccountContact'    => [
            'permission' => Permission::ACCOUNTS_VIEW_CONTACTS,
            'fields'     => ['email', 'phone', 'alternate_phone'],
        ],
        'App\Models\Pipeline\LeadStakeholder'  => [
            'permission' => Permission::ACCOUNTS_VIEW_CONTACTS,
            'fields'     => ['email', 'phone'],
        ],
        // Deal money — the commercials elevation.
        'App\Models\Pipeline\Opportunity'      => [
            'permission' => Permission::PIPELINE_VIEW_COMMERCIALS,
            'fields'     => ['contract_value', 'oem_ps_cost', 'expected_value', 'budget'],
        ],
        // Employee contactables: PiiMasker stores them partially masked, but
        // even the partial mask is reach-the-person data.
        'App\Models\Employee'                  => [
            'permission' => Permission::EMPLOYEES_VIEW_SENSITIVE,
            'fields'     => ['work_email', 'personal_email', 'email', 'phone', 'mobile_phone'],
        ],
    ];

    /**
     * The entry's changes as this reader may see them, plus whether anything
     * was withheld — surfaced as `values_redacted` so the UI can say "hidden
     * from you", never silently thin out the diff.
     *
     * @return array{changes: ?array, values_redacted: bool}
     */
    public static function forReader(?User $reader, AuditLog $log): array
    {
        $changes = $log->changes;

        if (empty($changes) || ! is_array($changes)) {
            return ['changes' => $changes, 'values_redacted' => false];
        }

        $rule = self::CLASSIFIED[$log->auditable_type] ?? null;

        if ($rule === null || ($reader?->hasPermission($rule['permission']) ?? false)) {
            return ['changes' => $changes, 'values_redacted' => false];
        }

        $redacted = false;

        foreach ($rule['fields'] as $field) {
            if (array_key_exists($field, $changes)) {
                $changes[$field] = ['masked' => true];
                $redacted = true;
            }
        }

        return ['changes' => $changes, 'values_redacted' => $redacted];
    }
}
