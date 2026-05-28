<?php

namespace App\Providers;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollPeriod;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\RecruitmentWorkflow;
use App\Models\Recruitment\Vacancy;
use App\Models\Recruitment\VacancyTemplate;
use App\Repositories\HR\EmployeeRepository;
use App\Repositories\Leave\LeaveRepository;
use App\Repositories\Payroll\PayrollRepository;
use App\Repositories\Recruitment\ApplicationRepository;
use App\Repositories\Recruitment\CandidateRepository;
use App\Repositories\Recruitment\VacancyRepository;
use App\Repositories\Recruitment\VacancyTemplateRepository;
use App\Repositories\Recruitment\WorkflowRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EmployeeRepository::class, fn () =>
            new EmployeeRepository(new Employee())
        );

        $this->app->singleton(LeaveRepository::class, fn () =>
            new LeaveRepository(new LeaveRequest())
        );

        $this->app->singleton(PayrollRepository::class, fn () =>
            new PayrollRepository(new PayrollPeriod())
        );

        $this->app->singleton(WorkflowRepository::class, fn () =>
            new WorkflowRepository(new RecruitmentWorkflow())
        );
        $this->app->singleton(VacancyTemplateRepository::class, fn () =>
            new VacancyTemplateRepository(new VacancyTemplate())
        );
        $this->app->singleton(VacancyRepository::class, fn () =>
            new VacancyRepository(new Vacancy())
        );
        $this->app->singleton(CandidateRepository::class, fn () =>
            new CandidateRepository(new Candidate())
        );
        $this->app->singleton(ApplicationRepository::class, fn () =>
            new ApplicationRepository(new Application())
        );
    }
}
