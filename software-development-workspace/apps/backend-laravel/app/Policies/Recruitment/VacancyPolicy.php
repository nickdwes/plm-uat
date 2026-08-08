<?php

namespace App\Policies\Recruitment;

use App\Models\Recruitment\Vacancy;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * Who may see and change a job opening.
 *
 * A vacancy is not a public advert with a login on it. It carries the hiring
 * manager, the pipeline candidates will move down, the application form, and —
 * through its template — the screening rules that decide who gets through. The
 * public route shows a title, a location and a description; this one shows how
 * the hiring is actually run.
 *
 * So it is gated on the recruitment permissions rather than on being "a
 * manager". The catalogue already carried `vacancies.manage` and
 * `recruitment.view` and this policy ignored both, which meant two things at
 * once: a custom role granted `vacancies.manage` was refused, and anybody who
 * managed anybody could read every opening in the organisation.
 *
 * The role checks remain as a fallback so that nothing which works today stops
 * working — an organisation that has never assigned the permissions still has
 * its HR team able to work.
 */
class VacancyPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::RECRUITMENT_VIEW, Permission::VACANCIES_MANAGE)
            || $this->isCompanyHR($user);
    }

    /**
     * Reading one.
     *
     * The organisation check is not redundant with the model's global scope:
     * anything that loads a vacancy without the scope — a job, a console
     * command, a public controller reaching for an internal payload — would
     * otherwise get an answer based only on who the reader is.
     */
    public function view(User $user, Vacancy $vacancy): bool
    {
        if (! $this->inCurrentOrg($vacancy)) {
            return false;
        }

        if ($this->canAny($user, Permission::RECRUITMENT_VIEW, Permission::VACANCIES_MANAGE)) {
            return true;
        }

        /*
         * The person doing the hiring, whatever else they hold.
         *
         * `hiring_manager_id` names a user rather than an employee — the one
         * place in recruitment where it does, and worth stating so the next
         * reader does not compare it against an employee id and get silence.
         */
        return $this->isCompanyHR($user) || $vacancy->hiring_manager_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::VACANCIES_MANAGE) || $this->isCompanyHR($user);
    }

    public function update(User $user, Vacancy $vacancy): bool
    {
        if (! $this->inCurrentOrg($vacancy)) {
            return false;
        }

        return $this->can($user, Permission::VACANCIES_MANAGE) || $this->isCompanyHR($user);
    }

    public function delete(User $user, Vacancy $vacancy): bool
    {
        return $this->update($user, $vacancy);
    }
}
