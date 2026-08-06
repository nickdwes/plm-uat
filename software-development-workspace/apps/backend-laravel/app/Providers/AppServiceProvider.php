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
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
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
    }
}
