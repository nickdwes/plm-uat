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
                'permissions'  => [
                    'organizations.*',
                    'employees.*',
                    'departments.*',
                    'payroll.*',
                    'leave.*',
                    'reports.*',
                    'settings.*',
                    'audit.*',
                ],
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
