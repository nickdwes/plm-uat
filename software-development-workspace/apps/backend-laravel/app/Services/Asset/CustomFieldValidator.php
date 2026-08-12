<?php

namespace App\Services\Asset;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetCategory;
use App\Models\Asset\AssetCategoryField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Answers checked against the questions their category actually asks.
 *
 * The definitions are data, so the rules have to be built at request time
 * rather than declared in a form request. Kept in one place because create and
 * update must agree — a value refused on the way in and accepted on an edit is
 * worse than either rule on its own.
 */
class CustomFieldValidator
{
    /**
     * Validate what the asset would end up with, not what was sent.
     *
     * An edit that changes one field arrives carrying only that field, and
     * checking it alone fails every *other* required question — which is
     * already answered and sitting on the record. So the incoming values are
     * merged over the existing ones and the result is what gets checked.
     *
     * @param  array<string,mixed>  $answers   what this request is changing
     * @param  array<string,mixed>  $existing  what the asset already holds
     * @return array<string,mixed>  the merged answers, minus anything not asked for
     */
    public function check(
        ?string $categoryName,
        ?string $assetClass,
        array $answers,
        array $existing = [],
        ?int $ignoreAssetId = null,
    ): array {
        if ($categoryName === null) {
            return [];
        
}

        $category = AssetCategory::where('name', $categoryName)
            ->where('asset_class', $assetClass ?? 'physical')
            ->first();

        if ($category === null) {
            /*
             * A category nobody configured — including anything typed under
             * "other". There are no questions, so there are no answers, and
             * silently keeping arbitrary keys would let the column become a
             * second notes field.
             */
            return [];
        }

        $fields = $category->fields()->get();

        if ($fields->isEmpty()) {
            return [];
        }

        $merged = array_merge($existing, $answers);

        $rules = [];
        $labels = [];

        foreach ($fields as $field) {
            $rules["custom_fields.{$field->key}"] = $field->rules();
            $labels["custom_fields.{$field->key}"] = $field->label;
        }

        $validator = Validator::make(['custom_fields' => $merged], $rules, [], $labels);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $this->assertUnique($category, $fields, $merged, $ignoreAssetId);

        // Only what was asked for. An answer to a question that no longer
        // exists is kept on assets that already have it, but nothing new is
        // written under a key the category does not define.
        return collect($merged)
            ->only($fields->pluck('key')->all())
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->all();
    }

    /**
     * No two assets share a value that is supposed to identify one thing.
     *
     * An IMEI, a VIN, a MAC address or a hostname on two records means the same
     * item has been registered twice, and every count from then on is wrong.
     *
     * Scoped to the organisation and the category: two tenants may hold the
     * same asset tag, and a serial number that collides between a laptop and a
     * monitor is a coincidence rather than a duplicate.
     *
     * Checked here rather than by a database constraint. A unique index over a
     * JSON key has to name that key, and these keys are configuration — the
     * alternative is the application running DDL off the back of a form
     * somebody filled in. At this size the trade is not worth it, and the error
     * belongs where it can say which asset already has the value.
     */
    private function assertUnique($category, $fields, array $merged, ?int $ignoreAssetId): void
    {
        $unique = $fields->where('is_unique', true);

        if ($unique->isEmpty()) {
            return;
        }

        $errors = [];

        foreach ($unique as $field) {
            $value = $merged[$field->key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $clash = Asset::query()
                ->where('asset_class', $category->asset_class)
                ->where('category', $category->name)
                ->when($ignoreAssetId, fn ($q) => $q->whereKeyNot($ignoreAssetId))
                // Compared as text and case-insensitively: a MAC typed in
                // capitals is the same MAC.
                ->whereRaw('lower(custom_fields->>?) = lower(?)', [$field->key, (string) $value])
                ->first();

            if ($clash) {
                $errors["custom_fields.{$field->key}"] = [
                    "{$field->label} {$value} is already recorded against {$clash->name} ({$clash->asset_tag}).",
                ];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
