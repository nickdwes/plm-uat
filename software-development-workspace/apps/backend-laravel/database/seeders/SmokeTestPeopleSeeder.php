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
     * name, role, job title, reports to (by key), department hint.
     *
     * Ordered so a manager always exists before the people who report to them.
     */
    private const PEOPLE = [
        ['coo',        'Grace Wanjiku',   'operations_executive', 'Chief Operating Officer', null],
        ['cfo',        'Peter Otieno',    'finance_executive',    'Chief Financial Officer', 'coo'],
        ['finance',    'Mary Njeri',      'finance_officer',      'Finance Officer',         'cfo'],
        ['hr',         'Alice Kamau',     'org_hr',               'HR Manager',              'coo'],
        ['desk_lead',  'David Mwangi',    'service_desk_analyst', 'Service Desk Lead',       'coo'],
        ['eng_net',    'John Kariuki',    'support_engineer',     'Network Engineer',        'desk_lead'],
        ['eng_iam',    'Faith Chebet',    'support_engineer',     'Security Engineer',       'desk_lead'],
        ['manager',    'Samuel Kiprop',   'dept_manager',         'Technical Manager',       'coo'],
        ['staff_one',  'Brian Omondi',    'employee',             'Support Technician',      'manager'],
        ['staff_two',  'Ruth Achieng',    'employee',             'Field Technician',        'manager'],
        ['account',    'Nancy Wairimu',   'account_manager',      'Account Manager',         'coo'],
        ['buyer',      'Joseph Maina',    'procurement_officer',  'Procurement Officer',     'coo'],
        ['groupadmin', 'Esther Nyambura', 'group_admin',          'Group Administrator',     null],
    ];

    public function run(): void
    {
        $home = Organization::where('slug', 'konvergenz')->first()
            ?? Organization::orderBy('id')->firstOrFail();

        app()->instance('current.organization_id', $home->id);

        $made = [];

        DB::transaction(function () use ($home, &$made) {
            foreach (self::PEOPLE as $i => [$key, $name, $roleName, $title, $managerKey]) {
                $made[$key] = $this->person($home, $key, $name, $roleName, $title, $i, $made[$managerKey] ?? null);
            }

            $this->supportSetup($home, $made);
            $this->secondments($home, $made);
        });

        $this->command?->info('Seeded ' . count($made) . ' test people. Password: ' . self::PASSWORD);
    }

    // ── One person ────────────────────────────────────────────────────────────

    private function person(
        Organization $org,
        string $key,
        string $name,
        string $roleName,
        string $title,
        int $index,
        ?Employee $manager,
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
            ['employee_number' => self::NUMBER_PREFIX . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)],
            [
                'primary_organization_id' => $org->id,
                'user_id'         => $user->id,
                'first_name'      => $first,
                'last_name'       => $last,
                'work_email'      => $user->email,
                'employment_type' => 'permanent',
                'status'          => 'active',
                'date_hired'      => now()->subYears(2)->toDateString(),
                'country'         => 'KE',   // ISO code — the column is varchar(3)
                // Distinct and obviously fake, and national_id is unique.
                'national_id'     => '9900' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'basic_salary'    => 120000 + ($index * 10000),
                'reports_to'      => $manager?->id,
            ],
        );

        $this->grant($user, $roleName, $org->id);

        /*
         * Everyone also holds `employee`, because the self-service screens are
         * gated on it and a COO who cannot see their own payslip is not a
         * realistic thing to test against.
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
        $hostManager = Employee::withoutGlobalScope('organization')
            ->where('primary_organization_id', $host->id)
            ->whereNull('reports_to')
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
