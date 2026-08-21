<?php

namespace Tests\Feature\Recruitment;

use App\Models\Organization;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\Interview;
use App\Models\Recruitment\InterviewScore;
use App\Models\Recruitment\Vacancy;
use App\Services\Recruitment\CandidateSuitability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How well somebody fits the job they applied for.
 *
 * Two halves from two kinds of evidence: what the applicant claimed on the
 * form, and what the people who met them concluded. Most of what follows is
 * about refusing to produce a confident number out of half an answer.
 */
class CandidateSuitabilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        app()->instance('current.organization_id', $this->org->id);
    }

    private function suitability(): CandidateSuitability
    {
        return app(CandidateSuitability::class);
    }

    private function application(array $required, array $claimed = null): Application
    {
        // A vacancy must belong to a hiring workflow, so give it the simplest one.
        $workflow = \App\Models\Recruitment\RecruitmentWorkflow::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard',
            'is_default' => true,
        ]);

        $vacancy = Vacancy::create([
            'organization_id' => $this->org->id,
            'workflow_id' => $workflow->id,
            'job_title' => 'Senior Product Designer',
            'name' => 'Senior Product Designer',
            'open_positions' => 1,
            'status' => 'open',
            'required_skills' => $required,
            'application_form_schema' => [
                ['key' => 'years_experience', 'label' => 'Years', 'type' => 'number', 'required' => true],
                ['key' => 'skills', 'label' => 'Which of these do you have?', 'type' => 'skills'],
            ],
        ]);

        $candidate = Candidate::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Sarah', 'last_name' => 'Johnson',
            'email' => 'sarah' . fake()->unique()->numberBetween(1, 9999) . '@example.test',
        ]);

        return Application::create([
            'vacancy_id' => $vacancy->id,
            'candidate_id' => $candidate->id,
            'status' => 'active',
            'applied_at' => now(),
            'form_data' => $claimed === null
                ? ['years_experience' => 6]
                : ['years_experience' => 6, 'skills' => $claimed],
        ]);
    }

    /** @param  list<float>  $averages  one per panelist */
    private function interview(Application $application, array $averages, bool $submitted = true): void
    {
        $interview = Interview::create([
            'organization_id' => $this->org->id,
            'application_id' => $application->id,
            'vacancy_id' => $application->vacancy_id,
            'title' => 'Technical Round',
            'interview_type' => 'panel',
            'scheduled_at' => now()->subDay(),
            'status' => 'completed',
        ]);

        foreach ($averages as $i => $average) {
            // A panelist is an employee, so each score needs a real one behind it.
            $panelist = \App\Models\Employee::create([
                'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
                'primary_organization_id' => $this->org->id,
                'first_name' => 'Panelist', 'last_name' => (string) ($i + 1),
                'employment_type' => 'permanent', 'status' => 'active', 'date_hired' => '2022-01-01',
            ]);

            InterviewScore::create([
                'interview_id' => $interview->id,
                'panelist_id'  => $panelist->id,
                'average_score' => $average,
                'recommendation' => $average >= 4 ? 'hire' : 'no_hire',
                'submitted' => $submitted,
                'submitted_at' => $submitted ? now() : null,
            ]);
        }
    }

    // ── The rule that matters most ────────────────────────────────────────────

    /**
     * Nobody has interviewed them, so there is no answer yet.
     *
     * Counting an absent panel score as nought would rank an untouched
     * shortlist below the one person who interviewed badly, and whoever sorted
     * by this column would work down the list in exactly the wrong order.
     */
    public function test_a_candidate_nobody_has_met_has_no_suitability_yet(): void
    {
        $application = $this->application(['Figma', 'User Research'], ['Figma', 'User Research']);

        $result = $this->suitability()->for($application);

        $this->assertSame(100.0, $result['skills']['score'], 'the half we do know is answered');
        $this->assertNull($result['panel']['score']);
        $this->assertNull($result['overall']['score']);
        $this->assertStringContainsString('Nobody has interviewed', $result['overall']['why']);
    }

    /** An interview with no submitted scorecard is not a verdict either. */
    public function test_an_unsubmitted_scorecard_is_not_an_opinion(): void
    {
        $application = $this->application(['Figma'], ['Figma']);
        $this->interview($application, [4.5], submitted: false);

        $result = $this->suitability()->for($application);

        $this->assertNull($result['panel']['score']);
        $this->assertStringContainsString('no panelist has submitted', $result['panel']['note']);
    }

    /** A role that states no requirement cannot measure anyone against it. */
    public function test_a_vacancy_with_no_stated_skills_scores_nobody_on_them(): void
    {
        $application = $this->application([], ['Figma']);
        $this->interview($application, [4.0]);

        $result = $this->suitability()->for($application);

        $this->assertNull($result['skills']['score']);
        $this->assertNull($result['overall']['score']);
        $this->assertStringContainsString('does not state the skills', $result['skills']['note']);
    }

    // ── With both halves ──────────────────────────────────────────────────────

    public function test_the_two_halves_combine_by_their_stated_weights(): void
    {
        // Two of four skills, and a panel averaging 4 out of 5.
        $application = $this->application(
            ['Figma', 'User Research', 'Web Development', 'Content Strategy'],
            ['Figma', 'User Research'],
        );
        $this->interview($application, [4.0, 4.0]);

        $result = $this->suitability()->for($application);

        $this->assertSame(50.0, $result['skills']['score']);
        $this->assertSame(80.0, $result['panel']['score']);
        // 50 × 0.4 + 80 × 0.6
        $this->assertSame(68.0, $result['overall']['score']);
        $this->assertSame(0.4, $result['weights']['skills']);
    }

    /** The working is shown, because a shortlist gets defended. */
    public function test_it_names_which_skills_matched_and_which_are_missing(): void
    {
        $application = $this->application(
            ['Figma', 'User Research', 'Content Strategy'],
            ['Figma'],
        );

        $result = $this->suitability()->for($application);

        $this->assertSame(['Figma'], $result['skills']['matched']);
        $this->assertSame(['User Research', 'Content Strategy'], $result['skills']['missing']);
    }

    /**
     * Two people typing the same skill differently is one skill.
     *
     * Treating "UI/UX Design" and "ui/ux  design" as different would quietly
     * mark a qualified applicant down for their capitalisation.
     */
    public function test_a_skill_matches_however_it_was_typed(): void
    {
        $application = $this->application(['UI/UX Design'], ['ui/ux  design']);

        $result = $this->suitability()->for($application);

        $this->assertSame(100.0, $result['skills']['score']);
        // The role's own wording is what is shown back, not the applicant's.
        $this->assertSame(['UI/UX Design'], $result['skills']['matched']);
    }

    /** A form that never asks about skills cannot match any. */
    public function test_a_form_that_does_not_ask_matches_nothing_and_says_so(): void
    {
        $application = $this->application(['Figma', 'User Research']);   // no skills answer

        $result = $this->suitability()->for($application);

        $this->assertSame(0.0, $result['skills']['score']);
        $this->assertStringContainsString('does not ask', $result['skills']['note']);
    }

    // ── The panel ─────────────────────────────────────────────────────────────

    /**
     * One panelist is one opinion, and it should not read as a panel's.
     */
    public function test_a_single_scorecard_is_flagged_as_one_persons_view(): void
    {
        $application = $this->application(['Figma'], ['Figma']);
        $this->interview($application, [5.0]);

        $result = $this->suitability()->for($application);

        $this->assertSame(1, $result['panel']['panelists']);
        $this->assertStringContainsString("one person's view", $result['panel']['note']);
    }

    public function test_several_panelists_are_averaged_and_counted(): void
    {
        $application = $this->application(['Figma'], ['Figma']);
        $this->interview($application, [5.0, 3.0, 4.0]);

        $result = $this->suitability()->for($application);

        $this->assertSame(3, $result['panel']['panelists']);
        $this->assertSame(4.0, $result['panel']['average']);
        $this->assertSame(80.0, $result['panel']['score']);
        $this->assertNull($result['panel']['note'], 'three panelists is a panel');
    }

    /** What they each recommended, kept alongside the average. */
    public function test_the_recommendations_are_tallied_not_averaged_away(): void
    {
        $application = $this->application(['Figma'], ['Figma']);
        $this->interview($application, [5.0, 4.5, 2.0]);

        $result = $this->suitability()->for($application);

        $this->assertSame(2, $result['panel']['recommendations']['hire']);
        $this->assertSame(1, $result['panel']['recommendations']['no_hire']);
    }
}
