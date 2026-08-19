<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name'         => Role::GROUP_ADMIN,
                'display_name' => 'Group Administrator',
                'scope'        => 'group',
                /*
                 * Everything.
                 *
                 * This list used to name eight modules, written when the system
                 * had eight. Twenty-two have landed since — assets, support,
                 * recruitment, projects, integrations and the rest — and none
                 * of them were added, so the top administrator could not touch
                 * any of them. `roles` was among the missing, which meant the
                 * one person who could have fixed the others could not open the
                 * screen to do it.
                 *
                 * A wildcard rather than a list, because the failure was the
                 * list: every future module would have to remember to amend it,
                 * and this is the role that is supposed to reach everything.
                 * Narrowing an individual administrator is what the exclusion
                 * mechanism on the roles screen is for.
                 */
                'permissions'  => ['*'],
            ],
            [
                'name'         => Role::ORG_HR,
                'display_name' => 'Organization HR',
                'scope'        => 'organization',
                'permissions'  => [
                    'employees.*',
                    'departments.view',
                    'departments.manage',
                    'payroll.*',
                    'leave.*',
                    'reports.org',
                    'onboarding.*',
                ],
            ],
            [
                'name'         => Role::DEPT_MANAGER,
                'display_name' => 'Department Manager',
                'scope'        => 'organization',
                'permissions'  => [
                    'employees.view',
                    'leave.approve',
                    'leave.reject',
                    'leave.view',
                    'reports.team',
                ],
            ],
            [
                /*
                 * The desk's operational control point. Holds everything in the
                 * support module: placing work, moving it, escalating it, and
                 * configuring the structure that routes it.
                 *
                 * Two of those — support.manage_config and
                 * support.manage_contracts — silently change what every open
                 * ticket is measured by and who every future ticket goes to.
                 * They are included because the analyst is the person who
                 * maintains that structure, and the roles screen can exclude
                 * either one for a junior analyst without editing this.
                 */
                'name'         => Role::SERVICE_DESK_ANALYST,
                'display_name' => 'Service Desk Analyst',
                'description'  => 'Reviews, categorises, places, chases and escalates support '
                    . 'tickets. Manages a ticket without necessarily owning the fix.',
                'scope'        => 'organization',
                'permissions'  => ['support.*'],
            ],
            [
                /*
                 * The engineer who fixes things. Deliberately without
                 * support.reassign — moving somebody else's ticket is the
                 * desk's call — and without the two configuration permissions.
                 * They can still escalate, which is the whole point of having
                 * somebody senior to escalate to.
                 */
                'name'         => Role::SUPPORT_ENGINEER,
                'display_name' => 'Support Engineer',
                'description'  => 'Works assigned tickets, replies to customers, logs time and '
                    . 'escalates when specialist help is needed.',
                'scope'        => 'organization',
                'permissions'  => [
                    'support.view',
                    'support.create',
                    'support.update',
                    'support.close',
                    'support.log_time',
                    'support.escalate',
                ],
            ],
            [
                'name'         => Role::EMPLOYEE,
                'display_name' => 'Employee',
                'scope'        => 'organization',
                'permissions'  => [
                    'employees.view_own',
                    'leave.submit',
                    'leave.cancel_own',
                    'leave.view_own',
                    'payslip.view_own',
                ],
            ],
        ];

        foreach ($roles as $data) {
            Role::updateOrCreate(['name' => $data['name']], $data);
        }
    }
}
