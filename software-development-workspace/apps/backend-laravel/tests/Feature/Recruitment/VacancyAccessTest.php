<?php

namespace Tests\Feature\Recruitment;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Recruitment\RecruitmentWorkflow;
use App\Models\Recruitment\Vacancy;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may reach a job opening.
 *
 * A vacancy is not a public advert with a login on it. It carries the hiring
 * manager, the pipeline candidates move down, the application form, and the
 * screening rules that decide who gets through. The public route shows a title,
 * a location and a description; this one shows how the hiring is run.
 *
 * What is proved here is that the two stay apart, and that the permission
 * catalogue actually governs the internal one — it carried `vacancies.manage`
 * and `recruitment.view` while the policy checked role names and ignored both.
 */
class VacancyAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private RecruitmentWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        // Every opening needs a pipeline; a vacancy with no board has nowhere
        // to put anybody who applies.
        $this->workflow = RecruitmentWorkflow::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard Hiring Pipeline', 'is_default' => true,
        ]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function signIn(array $permissions, string $roleName = null): Employee
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => $roleName ?? ('r' . fake()->unique()->numberBetween(1, 999999)),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function vacancy(array $attrs = []): Vacancy
    {
        return Vacancy::create($attrs + [
            'organization_id' => $this->org->id,
            'workflow_id' => $this->workflow->id,
            'name' => 'Senior Product Designer',
            'job_title' => 'Senior Product Designer',
            'status' => 'open',
            'open_positions' => 2,
            'location' => 'Nairobi',
            'description' => 'Design things.',
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/recruitment/vacancies{$path}?org={$this->org->slug}";
    }

    // ── The permission catalogue governs it ───────────────────────────────

    /**
     * The catalogue carried `vacancies.manage` and the policy ignored it, so a
     * custom role granted exactly that was refused — a permission that grants
     * nothing is worse than one that does not exist.
     */
    public function test_the_manage_permission_grants_access(): void
    {
        $this->signIn([Permission::VACANCIES_MANAGE]);
        $this->vacancy();

        $this->getJson($this->url())->assertOk();
    }

    public function test_the_recruitment_view_permission_grants_reading(): void
    {
        $this->signIn([Permission::RECRUITMENT_VIEW]);
        $vacancy = $this->vacancy();

        $this->getJson($this->url('/' . $vacancy->id))->assertOk();
    }

    /**
     * Reading is not managing. Somebody who may see the openings must not be
     * able to rewrite the pipeline or the application form.
     */
    public function test_reading_does_not_confer_changing(): void
    {
        $this->signIn([Permission::RECRUITMENT_VIEW]);
        $vacancy = $this->vacancy();

        $this->postJson($this->url(), [
            'name' => 'Sneaky', 'job_title' => 'Sneaky', 'open_positions' => 1,
            'workflow_id' => $this->workflow->id,
        ])->assertForbidden();

        $this->patchJson($this->url("/{$vacancy->id}/close"))->assertForbidden();
    }

    /**
     * Being a manager of anybody used to be enough to read every opening in
     * the organisation, which is wider than the hiring the reader is part of.
     */
    public function test_somebody_with_no_recruitment_permission_is_refused(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW]);
        $vacancy = $this->vacancy();

        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url('/' . $vacancy->id))->assertForbidden();
    }

    /**
     * The person doing the hiring reaches their own opening regardless.
     *
     * `hiring_manager_id` names a user rather than an employee, which is worth
     * knowing: the policy compares it against the reader's user id, not their
     * employee record.
     */
    public function test_the_hiring_manager_can_read_their_own_opening(): void
    {
        $employee = $this->signIn([Permission::EMPLOYEES_VIEW]);
        $vacancy  = $this->vacancy(['hiring_manager_id' => $employee->user_id]);

        $this->getJson($this->url('/' . $vacancy->id))->assertOk();

        // But not somebody else's.
        $this->getJson($this->url('/' . $this->vacancy()->id))->assertForbidden();
    }

    // ── Creating ──────────────────────────────────────────────────────────

    /**
     * The rule used to live only on the form request, checking a role name —
     * so it disagreed with the policy, which is the sort of thing nobody finds
     * until somebody cannot do their job.
     */
    public function test_creating_is_governed_by_the_same_rule_as_the_policy(): void
    {
        $this->signIn([Permission::VACANCIES_MANAGE]);

        $this->postJson($this->url(), [
            'name' => 'Senior Product Designer',
            'job_title' => 'Senior Product Designer',
            'open_positions' => 2,
            'workflow_id' => $this->workflow->id,
        ])->assertSuccessful();
    }

    // ── The two doors stay apart ──────────────────────────────────────────

    /**
     * The public advert is a title, a location and a description. The internal
     * record is how the hiring is run, and the two must not converge.
     *
     * The public portal sits at /api/public rather than under /api/v1 — the
     * version prefix wraps only the authenticated group, which is easy to miss
     * and worth stating where somebody will read it.
     */
    public function test_the_public_advert_withholds_the_internal_record(): void
    {
        $manager = User::factory()->create();

        $vacancy = $this->vacancy([
            'hiring_manager_id' => $manager->id,
            'application_form_schema' => [['key' => 'cv', 'label' => 'CV', 'type' => 'file']],
        ]);

        $body = $this->getJson("/api/public/jobs/{$vacancy->uuid}")->assertOk()->json('data');

        // The advert says what the job is.
        $this->assertSame('Senior Product Designer', $body['title']);

        /*
         * And nothing about how it is run. The internal id in particular: it is
         * the key to every authenticated endpoint on this record.
         */
        foreach (['id', 'hiring_manager_id', 'workflow_id', 'template_id', 'organization_id'] as $internal) {
            $this->assertArrayNotHasKey($internal, $body, "The public advert leaked {$internal}.");
        }
    }

    /** A closed opening is not an advert anybody should still be reading. */
    public function test_a_closed_opening_is_not_publicly_readable(): void
    {
        $vacancy = $this->vacancy(['status' => 'closed']);

        $this->getJson("/api/public/jobs/{$vacancy->uuid}")->assertNotFound();
    }

    // ── One organisation cannot read another's ────────────────────────────

    public function test_an_opening_in_another_organisation_is_not_reachable(): void
    {
        $this->signIn([Permission::VACANCIES_MANAGE]);

        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $theirs = Vacancy::withoutGlobalScopes()->create([
            'organization_id' => $other->id,
            'workflow_id' => $this->workflow->id,
            'name' => 'Somebody else’s role', 'job_title' => 'Somebody else’s role',
            'status' => 'open', 'open_positions' => 1,
        ]);

        $this->getJson($this->url('/' . $theirs->id))->assertNotFound();
    }
}
