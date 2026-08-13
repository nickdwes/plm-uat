<?php

namespace App\Providers;

use App\Models\Recruitment\Application;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\Vacancy;
use App\Models\Training\CompetencyItem;
use App\Models\Training\EmployeeTrainingRecord;
use App\Models\Training\RoleCompetencyProfile;
use App\Models\Training\TrainingItem;
use App\Models\Training\VendorPartner;
use App\Policies\Recruitment\ApplicationPolicy;
use App\Policies\Recruitment\CandidatePolicy;
use App\Policies\Recruitment\VacancyPolicy;
use App\Policies\Training\CompetencyPolicy;
use App\Policies\Training\TrainingItemPolicy;
use App\Policies\Training\TrainingRecordPolicy;
use App\Policies\Training\VendorPartnerPolicy;
use App\Mail\Transports\MicrosoftGraphTransport;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * Where the link in the email points.
         *
         * The `#` is not decoration. The SPA runs on `createWebHashHistory`,
         * so a path without one never reaches the router — the static server
         * either 404s or serves the app at its root, which silently drops the
         * token and lands the person on a login screen with no explanation.
         * This URL had no `#` and had never been clicked, because nothing
         * routed the endpoint that sends it.
         *
         * The address is carried as a query parameter so the reset form can
         * submit it back; the broker checks the token against it, so a tampered
         * address simply fails rather than resetting somebody else's account.
         */
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            $base  = rtrim(config('app.frontend_url'), '/');
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return "{$base}/#/reset-password/{$token}?email={$email}";
        });

        // Microsoft Graph custom mail transport
        Mail::extend('graph', function () {
            return new MicrosoftGraphTransport(
                tenantId:     config('mail.mailers.graph.tenant_id', ''),
                clientId:     config('mail.mailers.graph.client_id', ''),
                clientSecret: config('mail.mailers.graph.client_secret', ''),
                fromAddress:  config('mail.from.address', ''),
                fromName:     config('mail.from.name', ''),
            );
        });

        // Recruitment policies
        Gate::policy(Vacancy::class,     VacancyPolicy::class);
        Gate::policy(Candidate::class,   CandidatePolicy::class);
        Gate::policy(Application::class, ApplicationPolicy::class);

        // Training & Competency policies
        Gate::policy(TrainingItem::class,          TrainingItemPolicy::class);
        Gate::policy(CompetencyItem::class,        CompetencyPolicy::class);
        Gate::policy(RoleCompetencyProfile::class, CompetencyPolicy::class);
        Gate::policy(EmployeeTrainingRecord::class, TrainingRecordPolicy::class);
        Gate::policy(VendorPartner::class,         VendorPartnerPolicy::class);

        // Asset register. Registered explicitly because auto-discovery only
        // resolves App\Models\X to App\Policies\XPolicy — it does not follow
        // sub-namespaces, so a policy in a folder is silently never applied.
        Gate::policy(\App\Models\Asset\Asset::class, \App\Policies\Asset\AssetPolicy::class);

        // Accounts (customer master). Laravel's policy auto-discovery does not
        // follow sub-namespaces, so an unregistered policy fails open here.
        Gate::policy(\App\Models\Account\Account::class, \App\Policies\Account\AccountPolicy::class);
        Gate::policy(\App\Models\Travel\TravelRequest::class, \App\Policies\Travel\TravelRequestPolicy::class);
        Gate::policy(\App\Models\Pipeline\Lead::class, \App\Policies\Pipeline\LeadPolicy::class);
        Gate::policy(\App\Models\Pipeline\Opportunity::class, \App\Policies\Pipeline\OpportunityPolicy::class);
        Gate::policy(\App\Models\Pipeline\Research::class, \App\Policies\Pipeline\ResearchPolicy::class);
        Gate::policy(\App\Models\Project\Project::class, \App\Policies\Project\ProjectPolicy::class);
        Gate::policy(\App\Models\Task\Task::class, \App\Policies\Task\TaskPolicy::class);
        Gate::policy(\App\Models\Attendance\AttendanceDay::class, \App\Policies\Attendance\AttendanceDayPolicy::class);
        Gate::policy(\App\Models\Recruitment\TalentRequisition::class, \App\Policies\Recruitment\TalentRequisitionPolicy::class);
    }
}
