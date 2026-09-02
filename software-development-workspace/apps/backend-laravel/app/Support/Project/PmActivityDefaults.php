<?php

namespace App\Support\Project;

/**
 * The standard project-management activities a delivery kicks off with,
 * grouped by the default lifecycle phase they belong to. Seeded on demand so a
 * PM starts from the house checklist rather than a blank task list.
 *
 * Keys match ProjectPhase::DEFAULTS by name; an activity whose phase is not
 * present on the project is still created, just without a phase.
 */
class PmActivityDefaults
{
    /** @return array<string, array<int, string>> phase name => activity titles */
    public static function map(): array
    {
        return [
            'Preparation' => [
                'Project kick-off meeting',
                'Confirm scope & charter sign-off',
                'Set up project workspace & tools',
            ],
            'Planning' => [
                'Develop project plan & schedule',
                'Stakeholder register & RACI',
                'Communication plan',
                'Risk register review',
                'Resource & procurement plan',
            ],
            'Design' => [
                'Solution design document',
                'Design review & sign-off',
            ],
            'Implementation' => [
                'Implementation per plan',
                'Weekly status reporting',
            ],
            'Testing' => [
                'Test plan & UAT',
                'UAT sign-off',
            ],
            'Operationalization' => [
                'Go-live & cutover',
                'Operational handover',
            ],
            'Closure' => [
                'Project closure report',
                'Lessons learned',
                'Final client sign-off',
            ],
            'Training' => [
                'Training plan & delivery',
            ],
            'Support' => [
                'Support handover & SLA setup',
            ],
        ];
    }
}
