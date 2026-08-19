<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Support\Engineer;
use App\Models\Support\TeamMember;
use App\Models\User;
use Database\Seeders\SmokeTestPeopleSeeder as Smoke;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Take the test people back out.
 *
 * Exists so the seeder can be run without it becoming a decision somebody
 * regrets. Test accounts that cannot be told apart from real ones are test
 * accounts that are still on the headcount report two years later — and these
 * ones share a password.
 */
class RemoveSmokePeople extends Command
{
    protected $signature = 'support:remove-smoke-people {--force : Skip the confirmation}';

    protected $description = 'Remove the smoke-test employees, users and role grants';

    public function handle(): int
    {
        $users = User::where('email', 'like', '%@' . Smoke::EMAIL_DOMAIN)->get();
        $staff = Employee::withoutGlobalScope('organization')
            ->where('employee_number', 'like', Smoke::NUMBER_PREFIX . '%')->get();

        if ($users->isEmpty() && $staff->isEmpty()) {
            $this->info('Nothing to remove.');

            return self::SUCCESS;
        }

        $this->line("{$users->count()} users and {$staff->count()} employees match the test markers.");

        if (! $this->option('force') && ! $this->confirm('Remove them?', true)) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($users, $staff) {
            $userIds     = $users->pluck('id');
            $employeeIds = $staff->pluck('id');

            /*
             * Anything pointing at these people goes first. A user row deleted
             * out from under a support assignment leaves a ticket assigned to
             * nobody, which reads as a data fault rather than a cleanup.
             */
            TeamMember::whereIn('user_id', $userIds)->delete();
            Engineer::withoutGlobalScope('organization')->whereIn('user_id', $userIds)->delete();

            DB::table('account_contract_engineers')->whereIn('user_id', $userIds)->delete();
            DB::table('support_tickets')->whereIn('assigned_to', $userIds)->update(['assigned_to' => null]);
            DB::table('support_tickets')->whereIn('service_desk_user_id', $userIds)
                ->update(['service_desk_user_id' => null]);

            DB::table('employee_organization_map')->whereIn('employee_id', $employeeIds)->delete();
            DB::table('user_roles')->whereIn('user_id', $userIds)->delete();

            // Reporting lines among themselves, before the rows go.
            Employee::withoutGlobalScope('organization')
                ->whereIn('reports_to', $employeeIds)->update(['reports_to' => null]);

            Employee::withoutGlobalScope('organization')->whereIn('id', $employeeIds)->forceDelete();
            User::whereIn('id', $userIds)->forceDelete();
        });

        $this->info('Removed.');

        return self::SUCCESS;
    }
}
