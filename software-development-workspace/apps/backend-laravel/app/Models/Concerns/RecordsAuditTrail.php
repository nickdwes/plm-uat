<?php

namespace App\Models\Concerns;

use App\Services\Audit\AuditLogger;
use App\Support\Audit\PiiMasker;

/**
 * Auto-records create / update / delete of a model into the central audit log.
 *
 * Applied only to the sensitive, consequential models — not every table — so
 * the trail stays readable rather than drowning in routine row churn. A model
 * opts in by `use RecordsAuditTrail;` and may narrow what is captured with
 * `protected array $auditExclude = [...]` (columns whose changes are noise or
 * secret, e.g. `updated_at`, `client_secret`).
 */
trait RecordsAuditTrail
{
    public static function bootRecordsAuditTrail(): void
    {
        static::created(function ($model) {
            AuditLogger::record(
                action: 'created',
                auditable: $model,
                description: static::auditLabel($model) . ' created',
                organizationId: $model->getAttribute('organization_id') ?? $model->getAttribute('primary_organization_id'),
            );
        });

        static::updated(function ($model) {
            $changes = static::auditChanges($model);
            if (empty($changes)) {
                return; // nothing meaningful changed (only excluded columns).
            }

            AuditLogger::record(
                action: 'updated',
                auditable: $model,
                description: static::auditLabel($model) . ' updated',
                changes: $changes,
                organizationId: $model->getAttribute('organization_id') ?? $model->getAttribute('primary_organization_id'),
            );
        });

        static::deleted(function ($model) {
            AuditLogger::record(
                action: 'deleted',
                auditable: $model,
                description: static::auditLabel($model) . ' deleted',
                organizationId: $model->getAttribute('organization_id') ?? $model->getAttribute('primary_organization_id'),
            );
        });
    }

    /**
     * Field-by-field old→new, minus excluded columns, and with every value run
     * through the PII masker: sensitive fields are recorded as having changed
     * with their values withheld, contactable fields are partially masked, and
     * only operational fields keep their literal before/after.
     */
    protected static function auditChanges($model): array
    {
        $exclude = array_merge(
            ['updated_at', 'created_at'],
            property_exists($model, 'auditExclude') ? $model->auditExclude : [],
        );
        $redactExtra = property_exists($model, 'auditRedact') ? $model->auditRedact : [];
        $maskExtra   = property_exists($model, 'auditMask') ? $model->auditMask : [];

        $changes = [];
        foreach (array_keys($model->getChanges()) as $field) {
            if (in_array($field, $exclude, true)) {
                continue;
            }

            $oldClass = PiiMasker::forField($field, $model->getOriginal($field), $redactExtra, $maskExtra);
            $newClass = PiiMasker::forField($field, $model->getAttribute($field), $redactExtra, $maskExtra);

            // Redacted fields record the fact of a change, never the value.
            if (! $oldClass['store'] || ! $newClass['store']) {
                $changes[$field] = ['masked' => true];
                continue;
            }

            $changes[$field] = ['old' => $oldClass['value'], 'new' => $newClass['value']];
        }

        return $changes;
    }

    /**
     * A label for the record that does not itself leak PII — a stable
     * identifier (reference, code, employee number) is preferred over a
     * person's name, so the description column is not a back-door copy of the
     * data the diffs are careful to mask. A model may supply its own
     * non-personal label via auditName().
     */
    protected static function auditLabel($model): string
    {
        if (method_exists($model, 'auditName')) {
            return $model->auditName();
        }

        $base = class_basename($model);

        // Non-personal identifiers first; a name is used only when nothing
        // more abstract exists.
        foreach (['reference', 'code', 'employee_number', 'slug'] as $attr) {
            if (filled($model->getAttribute($attr))) {
                return "{$base} {$model->getAttribute($attr)}";
            }
        }

        foreach (['name', 'title'] as $attr) {
            if (filled($model->getAttribute($attr))) {
                return "{$base} \"{$model->getAttribute($attr)}\"";
            }
        }

        return "{$base} #{$model->getKey()}";
    }
}
