<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Support\Engineer;
use App\Models\Support\Team;
use App\Models\Support\TeamMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * People to test with.
 *
 * One holder of every role, a reporting line deep enough to exercise
 * manager-scoped views, and four secondment scenarios — including the two
 * negative ones, which is where this sort of fixture usually stops short.
 *
 * ── Everything here is removable in one statement ──
 *
 * Every account uses the @smoke.kns.test domain and every employee number
 * starts SMK-. Test people who cannot be told apart from real ones are test
 * people who are still in the system two years later, appearing on headcount
 * reports and payroll runs.
 *
 * Run with:    php artisan db:seed --class=SmokeTestPeopleSeeder
 * Remove with: php artisan support:remove-smoke-people
 */
class SmokeTestPeopleSeeder extends Seeder
{
    /** Shared across every test account. Deliberately obvious. */
    public const PASSWORD = 'Smoke!Test-2026';

    public const EMAIL_DOMAIN = 'smoke.kns.test';
    public const NUMBER_PREFIX = 'SMK-';

    /**
     * Departments per organisation, created where the organisation has none.
     *
     * Three of the four tenants were entirely empty — no departments, no
     * business units, nothing — so a hierarchy could not be built in them at
     * all. These are plausible for what each company actually does, because a
     * test tenant staffed with "Department 1" tells you nothing when you are
     * looking at a headcount screen trying to judge whether it reads right.
     */
    private const DEPARTMENTS = [
        'tiberbu' => ['Clinical Systems', 'Health Information', 'Client Services', 'Finance', 'People & Culture'],
        'visibility' => ['Software Engineering', 'Managed Services', 'Commercial', 'Finance & Admin'],
        'teedu' => ['Faculty', 'Student Services', 'Operations', 'Finance'],
    ];

    /**
     * key, name, role, job title, reports-to key, department, grade level.
     *
     * The grade is explicit rather than derived from the role. A bursar and a
     * finance officer hold the same role and sit two rungs apart, and inferring
     * it would quietly get that wrong on exactly the records somebody checks.
     *
     * Grouped by organisation, and ordered so a manager always exists before
     * the people who report to them.
     */
    private const PEOPLE = [
        'konvergenz' => [
            ['coo',        'Grace Wanjiku',   'operations_executive', 'Chief Operating Officer', null,        'Service Delivery', 5],
            ['cfo',        'Peter Otieno',    'finance_executive',    'Chief Financial Officer', 'coo',       'Finance', 5],
            ['finance',    'Mary Njeri',      'finance_officer',      'Finance Officer',         'cfo',       'Finance', 2],
            ['hr',         'Alice Kamau',     'org_hr',               'HR Manager',              'coo',       'HR', 3],
            ['desk_lead',  'David Mwangi',    'service_desk_analyst', 'Service Desk Lead',       'coo',       'Service Delivery', 3],
            ['eng_net',    'John Kariuki',    'support_engineer',     'Network Engineer',        'desk_lead', 'Technical', 2],
            ['eng_iam',    'Faith Chebet',    'support_engineer',     'Security Engineer',       'desk_lead', 'Technical', 2],
            ['manager',    'Samuel Kiprop',   'dept_manager',         'Technical Manager',       'coo',       'Technical', 4],
            ['staff_one',  'Brian Omondi',    'employee',             'Support Technician',      'manager',   'Technical', 1],
            ['staff_two',  'Ruth Achieng',    'employee',             'Field Technician',        'manager',   'Technical', 1],
            ['account',    'Nancy Wairimu',   'account_manager',      'Account Manager',         'coo',       'BFSI', 3],
            ['buyer',      'Joseph Maina',    'procurement_officer',  'Procurement Officer',     'coo',       'Service Delivery', 2],
            ['groupadmin', 'Esther Nyambura', 'group_admin',          'Group Administrator',     null,        null, 4],
        ],

        'tiberbu' => [
            ['tb_head',    'Daniel Mutiso',   'operations_executive', 'Managing Director',       null,        'Client Services', 5],
            ['tb_hr',      'Lucy Wambui',     'org_hr',               'HR & Admin Manager',      'tb_head',   'People & Culture', 3],
            ['tb_clin',    'Dr Susan Adhiambo', 'dept_manager',       'Head of Clinical Systems','tb_head',   'Clinical Systems', 4],
            ['tb_analyst', 'Kevin Barasa',    'service_desk_analyst', 'Service Desk Analyst',    'tb_clin',   'Client Services', 2],
            ['tb_eng',     'Mercy Wanjiru',   'support_engineer',     'Systems Engineer',        'tb_clin',   'Clinical Systems', 2],
            ['tb_hi',      'Paul Ochieng',    'employee',             'Health Records Officer',  'tb_clin',   'Health Information', 1],
            ['tb_fin',     'Janet Muthoni',   'finance_officer',      'Finance Officer',         'tb_head',   'Finance', 2],
            ['tb_acct',    'Victor Kimani',   'account_manager',      'Client Relationship Lead','tb_head',   'Client Services', 3],
        ],

        'visibility' => [
            ['vs_head',    'Anne Wangari',    'operations_executive', 'General Manager',         null,        'Managed Services', 5],
            ['vs_cfo',     'Tom Kiplagat',    'finance_executive',    'Finance Director',        'vs_head',   'Finance & Admin', 5],
            ['vs_dev',     'Ian Njoroge',     'dept_manager',         'Engineering Manager',     'vs_head',   'Software Engineering', 4],
            ['vs_eng_one', 'Cynthia Auma',    'support_engineer',     'Software Engineer',       'vs_dev',    'Software Engineering', 2],
            ['vs_eng_two', 'Dennis Mwaura',   'support_engineer',     'Platform Engineer',       'vs_dev',    'Managed Services', 3],
            ['vs_hr',      'Beatrice Nduta',  'org_hr',               'People Lead',             'vs_head',   'Finance & Admin', 3],
            ['vs_sales',   'Eric Wafula',     'account_manager',      'Business Development',    'vs_head',   'Commercial', 3],
        ],

        'teedu' => [
            ['td_head',    'Prof James Mburu','operations_executive', 'Principal',               null,        'Operations', 5],
            ['td_faculty', 'Rose Nekesa',     'dept_manager',         'Head of Faculty',         'td_head',   'Faculty', 4],
            ['td_tutor',   'Simon Cheruiyot', 'employee',             'Lecturer',                'td_faculty','Faculty', 2],
            ['td_student', 'Winnie Akinyi',   'employee',             'Student Services Officer','td_head',   'Student Services', 1],
            ['td_fin',     'Charles Barasa',  'finance_officer',      'Bursar',                  'td_head',   'Finance', 3],
            ['td_hr',      'Agnes Chelimo',   'org_hr',               'HR Officer',              'td_head',   'Operations', 2],
        ],
    ];

    public function run(): void
    {
        $made  = [];
        $index = 0;

        DB::transaction(function () use (&$made, &$index) {
            foreach (self::PEOPLE as $slug => $roster) {
                $org = Organization::where('slug', $slug)->first();

                if (! $org) {
                    $this->command?->warn("No organisation '{$slug}' — skipped.");
                    continue;
                }

                app()->instance('current.organization_id', $org->id);

                $departments = $this->departments($org, $slug);
                $grades      = $this->grades($org);

                foreach ($roster as [$key, $name, $roleName, $title, $managerKey, $departmentName, $gradeLevel]) {
                    $index++;

                    $made[$key] = $this->person(
                        $org, $key, $name, $roleName, $index,
                        $made[$managerKey] ?? null,
                        $departments[$departmentName] ?? null,
                        $this->designation($org, $title, $gradeLevel, $grades),
                    );
                }

                $this->supportSetup($org, $made);
            }

            $this->secondments(Organization::where('slug', 'konvergenz')->firstOrFail(), $made);
        });

        $this->command?->info('Seeded ' . count($made) . ' test people across '
            . count(self::PEOPLE) . ' organisations. Password: ' . self::PASSWORD);
    }

    /**
     * Departments for an organisation, created where it has none.
     *
     * Three of the four tenants were empty, so nobody could be placed anywhere
     * and every hierarchy screen had nothing to draw. Existing departments are
     * left alone — Konvergenz has sixteen real ones and this is not the place
     * to second-guess them.
     *
     * @return array<string, int> name => id
     */
    private function departments(Organization $org, string $slug): array
    {
        $existing = \App\Models\Department::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->pluck('id', 'name');

        foreach (self::DEPARTMENTS[$slug] ?? [] as $name) {
            if ($existing->has($name)) {
                continue;
            }

            $department = \App\Models\Department::create([
                'organization_id' => $org->id,
                'name'            => $name,
                'code'            => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 4)),
                'is_active'       => true,
            ]);

            $existing->put($name, $department->id);
        }

        return $existing->all();
    }

    /**
     * The grade ladder, mirroring the one Konvergenz already uses.
     *
     * Matched on code rather than name, because Konvergenz's existing rungs are
     * named "Senior Engineer / Manager" and "C-Suite" and a fixture that
     * duplicated them under tidier names would leave two ladders where there
     * should be one.
     *
     * @return array<int, int> level => id
     */
    private function grades(Organization $org): array
    {
        $ladder = [
            1 => ['G1', 'Trainee'],
            2 => ['G2', 'Officer / Engineer'],
            3 => ['G3', 'Senior / Specialist'],
            4 => ['G4', 'Manager / Unit Head'],
            5 => ['G5', 'Executive'],
        ];

        $out = [];

        foreach ($ladder as $level => [$code, $name]) {
            $grade = \App\Models\JobGrade::withoutGlobalScope('organization')
                ->where('organization_id', $org->id)
                ->where('code', $code)
                ->first();

            $grade ??= \App\Models\JobGrade::create([
                'organization_id' => $org->id,
                'name'            => $name,
                'code'            => $code,
                'level'           => $level,
                'currency'        => 'KES',
            ]);

            $out[$level] = $grade->id;
        }

        return $out;
    }

    /**
     * A designation for a job title, on the right rung.
     *
     * Created per organisation because a "Finance Officer" at the academy is
     * not the same post as one at the network company — they report to
     * different people and sit under different grades, and sharing the record
     * would make the org chart lie.
     */
    private function designation(Organization $org, string $title, int $gradeLevel, array $grades): int
    {
        $designation = \App\Models\Designation::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('title', $title)
            ->first();

        $designation ??= \App\Models\Designation::create([
            'organization_id' => $org->id,
            'title'           => $title,
            'job_grade_id'    => $grades[$gradeLevel] ?? null,
        ]);

        // An existing designation with no grade gets one rather than being left
        // half-configured — the grade is what the salary bands hang off.
        if ($designation->job_grade_id === null && isset($grades[$gradeLevel])) {
            $designation->update(['job_grade_id' => $grades[$gradeLevel]]);
        }

        return $designation->id;
    }

    // ── One person ────────────────────────────────────────────────────────────

    private function person(
        Organization $org,
        string $key,
        string $name,
        string $roleName,
        int $index,
        ?Employee $manager,
        ?int $departmentId,
        ?int $designationId,
    ): Employee {
        [$first, $last] = explode(' ', $name, 2);

        $user = User::updateOrCreate(
            ['email' => $key . '@' . self::EMAIL_DOMAIN],
            [
                'name'           => $name,
                'password'       => Hash::make(self::PASSWORD),
                'account_status' => 'active',
                'sso_only'       => false,
            ],
        );

        $employee = Employee::withoutGlobalScope('organization')->updateOrCreate(
            ['employee_number' => self::NUMBER_PREFIX . str_pad((string) $index, 3, '0', STR_PAD_LEFT)],
            [
                'primary_organization_id' => $org->id,
                'user_id'         => $user->id,
                'first_name'      => $first,
                'last_name'       => $last,
                'work_email'      => $user->email,
                'department_id'   => $departmentId,
                'designation_id'  => $designationId,
                'employment_type' => 'permanent',
                'status'          => 'active',
                'date_hired'      => now()->subYears(2)->toDateString(),
                'country'         => 'KE',
                // Distinct and obviously fake, and national_id is unique.
                'national_id'     => '9900' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'basic_salary'    => 120000 + ($index * 5000),
                // Within their own organisation only — a manager in another
                // tenant would be a reporting line the org scope cannot follow.
                'reports_to'      => $manager?->primary_organization_id === $org->id ? $manager->id : null,
            ],
        );

        $this->grant($user, $roleName, $org->id);

        /*
         * Everyone also holds `employee`, because the self-service screens are
         * gated on it and a managing director who cannot open their own payslip
         * is not a realistic thing to test against.
         */
        if ($roleName !== 'employee' && $roleName !== 'group_admin') {
            $this->grant($user, 'employee', $org->id);
        }

        return $employee;
    }

    /** A role in an organisation, optionally time-bounded or already revoked. */
    private function grant(
        User $user,
        string $roleName,
        int $organizationId,
        ?string $from = null,
        ?string $until = null,
        ?string $reason = null,
        bool $revoked = false,
    ): void {
        $role = Role::where('name', $roleName)->first();

        if (! $role) {
            return;
        }

        DB::table('user_roles')->updateOrInsert(
            [
                'user_id'         => $user->id,
                'role_id'         => $role->id,
                'organization_id' => $organizationId,
            ],
            [
                'scope'        => $role->scope,
                'valid_from'   => $from,
                'valid_until'  => $until,
                'grant_reason' => $reason,
                'revoked_at'   => $revoked ? now() : null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
        );

        $user->forgetCachedRoles();
    }

    // ── Secondments ───────────────────────────────────────────────────────────

    /**
     * Four scenarios, including the two that are supposed to *not* work.
     *
     * A fixture set that only contains the happy case proves the feature can
     * grant access. Whether it can withdraw it — on expiry, on revocation — is
     * the half that matters, and it is the half nobody has data for.
     */
    private function secondments(Organization $home, array $made): void
    {
        $host      = Organization::where('slug', 'tiberbu')->first();
        $otherHost = Organization::where('slug', 'visibility')->first();

        // 1. Live, part-time, cross-organisation. Reports to somebody else there.
        if ($host && isset($made['eng_net'])) {
            $this->second($made['eng_net'], $host, [
                'allocation_pct' => 40,
                'engagement_type' => 'secondment',
                'role'            => 'Network Engineer (seconded)',
                'effective_from'  => now()->subMonth()->toDateString(),
                'effective_to'    => now()->addMonths(5)->toDateString(),
                'is_billable'     => true,
                'notes'           => 'Two days a week supporting the Tiberbu network estate.',
            ]);

            $this->grant(
                $made['eng_net']->user, 'support_engineer', $host->id,
                from: now()->subMonth()->toDateString(),
                until: now()->addMonths(5)->toDateString(),
                reason: 'Secondment — Tiberbu network estate, 2 days a week',
            );
        }

        /*
         * 2. Acting up. A temporary elevation in the home organisation, which
         * is a different thing from a secondment and is routinely modelled as
         * though it were the same — so it is here, separately, to prove it is
         * not.
         */
        if (isset($made['eng_iam'])) {
            $this->grant(
                $made['eng_iam']->user, 'service_desk_analyst', $home->id,
                from: now()->subDays(3)->toDateString(),
                until: now()->addDays(11)->toDateString(),
                reason: 'Acting service desk lead while David Mwangi is on leave',
            );
        }

        // 3. Expired. Should grant nothing.
        if ($otherHost && isset($made['staff_two'])) {
            $this->second($made['staff_two'], $otherHost, [
                'allocation_pct'  => 50,
                'engagement_type' => 'secondment',
                'role'            => 'Field Technician (ended)',
                'effective_from'  => now()->subMonths(8)->toDateString(),
                'effective_to'    => now()->subMonth()->toDateString(),
                'is_active'       => false,
                'notes'           => 'Ended. Kept to prove an expired secondment grants nothing.',
            ]);

            $this->grant(
                $made['staff_two']->user, 'employee', $otherHost->id,
                from: now()->subMonths(8)->toDateString(),
                until: now()->subMonth()->toDateString(),
                reason: 'Secondment ended — should no longer grant access',
            );
        }

        /*
         * 5. The other direction. Secondment tested only outward from the main
         *    tenant proves the org switcher works for one org's people; a
         *    Visibility engineer working inside Konvergenz is the case that
         *    catches scope assumptions written from the main tenant's point of
         *    view.
         */
        if (isset($made['vs_eng_two'])) {
            $this->second($made['vs_eng_two'], $home, [
                'allocation_pct'  => 50,
                'engagement_type' => 'secondment',
                'role'            => 'Platform Engineer (inbound)',
                'effective_from'  => now()->subWeeks(2)->toDateString(),
                'effective_to'    => now()->addMonths(3)->toDateString(),
                'is_billable'     => true,
                'notes'           => 'Half a week on the Konvergenz managed platform.',
            ]);

            $this->grant(
                $made['vs_eng_two']->user, 'support_engineer', $home->id,
                from: now()->subWeeks(2)->toDateString(),
                until: now()->addMonths(3)->toDateString(),
                reason: 'Inbound secondment from Visibility — platform work',
            );
        }

        // 6. A third tenant, so the switcher has more than two entries for
        //    somebody who is genuinely in several.
        if (isset($made['tb_eng'])) {
            $teedu = Organization::where('slug', 'teedu')->first();

            if ($teedu) {
                $this->second($made['tb_eng'], $teedu, [
                    'allocation_pct'  => 20,
                    'engagement_type' => 'shared_service',
                    'role'            => 'Systems Engineer (shared)',
                    'effective_from'  => now()->subMonths(3)->toDateString(),
                    'notes'           => 'One day a week supporting the academy systems. No end date.',
                ]);

                $this->grant(
                    $made['tb_eng']->user, 'support_engineer', $teedu->id,
                    from: now()->subMonths(3)->toDateString(),
                    reason: 'Shared service — academy systems, one day a week',
                );
            }
        }

        // 4. Revoked rather than expired. A different question, and a different
        //    answer to "why can this person no longer see it".
        if ($host && isset($made['staff_one'])) {
            $this->grant(
                $made['staff_one']->user, 'employee', $host->id,
                from: now()->subMonths(2)->toDateString(),
                until: now()->addMonths(4)->toDateString(),
                reason: 'Secondment withdrawn early — revoked, not expired',
                revoked: true,
            );
        }
    }

    /** The host-organisation half: allocation, dates, and who they report to there. */
    private function second(Employee $employee, Organization $host, array $context): void
    {
        /*
         * Whoever heads the host organisation. A seconded engineer answers to
         * somebody there, not to their substantive manager — which is the whole
         * reason the reporting line lives on the pivot rather than the employee.
         */
        $hostManager = Employee::withoutGlobalScope('organization')
            ->where('primary_organization_id', $host->id)
            ->whereNull('reports_to')
            ->orderBy('id')
            ->value('id');

        $employee->organizations()->syncWithoutDetaching([
            $host->id => array_merge([
                'is_active'    => true,
                'access_scope' => 'operational',
                // A seconded engineer answers to somebody in the host
                // organisation, not to their substantive manager.
                'reports_to'   => $hostManager,
            ], $context),
        ]);

        $employee->update(['is_shared' => true]);
    }

    // ── Support module fixtures ───────────────────────────────────────────────

    /** Enough structure that contract routing has something to route to. */
    private function supportSetup(Organization $org, array $made): void
    {
        $practices = \App\Models\OrgPractice::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)->pluck('id', 'name');

        $profiles = [
            ['eng_net',   ['fortinet', 'mpls', 'cisco', 'bgp']],
            ['eng_iam',   ['entra-id', 'pam', 'conditional-access']],
            ['desk_lead', ['triage', 'itil']],
        ];

        foreach ($profiles as [$key, $skills]) {
            if (! isset($made[$key])) {
                continue;
            }

            Engineer::withoutGlobalScope('organization')->updateOrCreate(
                ['organization_id' => $org->id, 'user_id' => $made[$key]->user_id],
                [
                    'org_practice_id'    => $practices->first(),
                    'skills'             => $skills,
                    'accepts_assignment' => true,
                ],
            );
        }

        $queues = [
            ['Network Support',  ['eng_net'],              'eng_net'],
            ['IAM and Security', ['eng_iam'],              'eng_iam'],
            ['Service Desk',     ['desk_lead', 'eng_net'], 'desk_lead'],
        ];

        foreach ($queues as [$queueName, $memberKeys, $leadKey]) {
            $team = Team::withoutGlobalScope('organization')
                ->where('organization_id', $org->id)
                ->where('name', $queueName)->first();

            if (! $team) {
                continue;
            }

            foreach ($memberKeys as $key) {
                if (! isset($made[$key])) {
                    continue;
                }

                TeamMember::updateOrCreate(
                    ['support_team_id' => $team->id, 'user_id' => $made[$key]->user_id],
                    ['is_lead' => $key === $leadKey, 'accepts_assignment' => true],
                );
            }
        }
    }
}
