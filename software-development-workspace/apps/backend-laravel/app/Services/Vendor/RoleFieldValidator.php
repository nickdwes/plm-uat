<?php

namespace App\Services\Vendor;

use App\Models\Vendor\StakeholderRoleField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Answers checked against the questions this party's roles actually ask.
 *
 * The definitions are data, so the rules are built at request time rather
 * than declared in a form request. One place, because create and update must
 * agree: a value refused on the way in and accepted on an edit is worse than
 * either rule on its own.
 */
class RoleFieldValidator
{
    /**
     * Validate what the vendor would end up with, not what was sent.
     *
     * An edit that changes one field arrives carrying only that field, and
     * checking it alone fails every other required question — which is
     * already answered and sitting on the record. So the incoming values are
     * merged over the existing ones and the result is what gets checked.
     *
     * @param  string[]              $roles     roles in force
     * @param  array<string,mixed>   $answers   what this request is changing
     * @param  array<string,mixed>   $existing  what the vendor already holds
     * @return array<string,mixed>   the merged answers, minus anything not asked for
     */
    public function check(
        int $organizationId,
        array $roles,
        string $partyClass,
        array $answers,
        array $existing = [],
    ): array {
        $fields = StakeholderRoleField::applyingTo($organizationId, $roles, $partyClass);

        if ($fields->isEmpty()) {
            return [];
        }

        $merged = array_merge($existing, $answers);

        $rules  = [];
        $labels = [];

        foreach ($fields as $field) {
            /*
             * Shape is checked here; completeness is not.
             *
             * A required role field does not stop a DRAFT being saved — the
             * whole point of a draft is that it is unfinished, and a form
             * that refuses to save half-filled loses the half. Missing
             * answers surface as activation blockers instead, beside the
             * missing documents, and stop the SUBMISSION. Same rule, one
             * place, already understood by everyone using the screen.
             */
            $rules["role_details.{$field->key}"]  = array_values(array_filter(
                $field->rules(),
                fn (string $rule) => $rule !== 'required',
            ));
            $labels["role_details.{$field->key}"] = $field->label;
        }

        $validator = Validator::make(['role_details' => $merged], $rules, [], $labels);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /*
         * New answers only for questions that are actually asked; answers
         * already on the record are kept whatever the roles say now.
         *
         * This is where it parts company with the asset engine, which drops
         * anything its category no longer defines. Roles END here — that is
         * the point of the effective dating — and a distributor that stopped
         * distributing last year should not have the territories it covered
         * silently erased along with the role. The form stops asking; the
         * record keeps the answer.
         */
        $asked = $fields->pluck('key')->all();

        return collect($merged)
            ->only(array_unique([...$asked, ...array_keys($existing)]))
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->all();
    }
}
