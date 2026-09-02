<?php

namespace App\Providers;

use App\Models\Recruitment\Application;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\Vacancy;
use App\Models\Training\CompetencyItem;
use App\Models\Training\EmployeeTrainingRecord;
use App\Models\Training\RoleCompetencyProfile;
use App\Models\Training\TrainingBudgetRequest;
use App\Models\Training\TrainingItem;
use App\Models\Training\VendorPartner;
use App\Policies\Recruitment\ApplicationPolicy;
use App\Policies\Recruitment\CandidatePolicy;
use App\Policies\Recruitment\VacancyPolicy;
use App\Policies\Training\CompetencyPolicy;
use App\Policies\Training\TrainingBudgetRequestPolicy;
use App\Policies\Training\TrainingItemPolicy;
use App\Policies\Training\TrainingRecordPolicy;
use App\Policies\Training\VendorPartnerPolicy;
use App\Mail\Transports\MicrosoftGraphTransport;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * A default for the organisation in context, so reading it never throws.
         *
         * `ResolveOrganizationContext` binds this with `instance()`, and for
         * somebody operating across the whole group it binds **null** — which
         * means no filter. But the container tests for a bound instance with
         * `isset()`, and `isset()` is false for null. So a key that was
         * deliberately bound to null looks unbound, and `app('current.
         * organization_id')` tries to resolve it as a class and throws
         * `BindingResolutionException`.
         *
         * Two hundred and seventeen places read this key without guarding, so
         * the fix belongs here rather than at each of them: a default binding
         * is consulted only when no instance is set, which is exactly the case
         * that was breaking. A real organisation id still short-circuits to the
         * instance and never reaches this.
         *
         * The symptom was a group administrator opening any list and getting a
         * 500 — the one account that is meant to see everything was the only
         * one that could not.
         */
        $this->app->bind('current.organization_id', fn () => null);
        $this->app->bind('current.organization', fn () => null);
    }

    public function boot(): void
    {
        /*
         * A stronger default password everywhere the reset/creation flow uses
         * Password::defaults(): at least 12 characters and not one that has
         * appeared in a known breach (checked against HaveIBeenPwned's k-anon
         * range API — only a hash prefix leaves the server).
         */
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->uncompromised()
            : Password::min(12));

        /*
         * The security audit trail. Failed logins and lockouts were fired as
         * events but listened to by nothing, so brute-force and credential
         * stuffing left no record. Written to the dedicated `security` channel.
         */
        Event::listen(Failed::class, function (Failed $event) {
            $email = $event->credentials['email'] ?? null;
            Log::channel('security')->warning('Failed login', ['email' => $email, 'ip' => request()->ip()]);
            // No authenticated user — actorName carries the attempted identity.
            \App\Services\Audit\AuditLogger::record(
                action: 'login_failed',
                description: 'Failed login for ' . ($email ?? 'unknown'),
                actorName: $email,
            );
        });

        Event::listen(Lockout::class, function () {
            Log::channel('security')->warning('Login lockout (rate limit hit)', ['ip' => request()->ip()]);
            \App\Services\Audit\AuditLogger::record(
                action: 'login_locked',
                description: 'Login lockout — rate limit reached',
            );
        });

        Event::listen(\Illuminate\Auth\Events\Login::class, function ($event) {
            \App\Services\Audit\AuditLogger::record(
                action: 'login',
                description: $event->user->name . ' signed in',
                userId: $event->user->id, actorName: $event->user->name,
            );
        });

        Event::listen(\Illuminate\Auth\Events\Logout::class, function ($event) {
            if ($event->user) {
                \App\Services\Audit\AuditLogger::record(
                    action: 'logout',
                    description: $event->user->name . ' signed out',
                    userId: $event->user->id, actorName: $event->user->name,
                );
            }
        });

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
        Gate::policy(TrainingBudgetRequest::class, TrainingBudgetRequestPolicy::class);
        Gate::policy(VendorPartner::class,         VendorPartnerPolicy::class);

        // Asset register. Registered explicitly because auto-discovery only
        // resolves App\Models\X to App\Policies\XPolicy — it does not follow
        // sub-namespaces, so a policy in a folder is silently never applied.
        Gate::policy(\App\Models\Asset\Asset::class, \App\Policies\Asset\AssetPolicy::class);
        Gate::policy(
            \App\Models\Integration\IntegrationConnection::class,
            \App\Policies\Integration\IntegrationConnectionPolicy::class,
        );

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

        // Service desk
        Gate::policy(\App\Models\Support\Ticket::class, \App\Policies\TicketPolicy::class);
    }
}
