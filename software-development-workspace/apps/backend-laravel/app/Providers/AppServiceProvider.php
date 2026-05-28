<?php

namespace App\Providers;

use App\Models\Recruitment\Application;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\Vacancy;
use App\Policies\Recruitment\ApplicationPolicy;
use App\Policies\Recruitment\CandidatePolicy;
use App\Policies\Recruitment\VacancyPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
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

        // Recruitment policies
        Gate::policy(Vacancy::class,     VacancyPolicy::class);
        Gate::policy(Candidate::class,   CandidatePolicy::class);
        Gate::policy(Application::class, ApplicationPolicy::class);
    }
}
