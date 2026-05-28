<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\ApplicationStageHistory;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\RecruitmentWorkflow;
use App\Models\Recruitment\VacancyTemplate;
use App\Models\Recruitment\Vacancy;
use App\Models\Recruitment\WorkflowStage;
use App\Models\User;
use Illuminate\Database\Seeder;

class RecruitmentSeeder extends Seeder
{
    public function run(): void
    {
        $org  = Organization::first();
        $user = User::first();

        if (!$org || !$user) {
            $this->command->warn('No organization or user found — skipping recruitment seed.');
            return;
        }

        // ── Default workflow ────────────────────────────────────────────────
        $workflow = RecruitmentWorkflow::create([
            'organization_id' => $org->id,
            'name'            => 'Standard Hiring Pipeline',
            'description'     => 'Default 6-stage pipeline for all positions.',
            'is_default'      => true,
        ]);

        $defaultStages = [
            ['name' => 'Application Received',  'stage_type' => 'application'],
            ['name' => 'Phone Screening',        'stage_type' => 'screening'],
            ['name' => 'Interview',              'stage_type' => 'interview'],
            ['name' => 'Shortlisted',            'stage_type' => 'decision'],
            ['name' => 'Offer Extended',         'stage_type' => 'offer'],
            ['name' => 'Hired',                  'stage_type' => 'hired'],
        ];

        foreach ($defaultStages as $index => $stage) {
            WorkflowStage::create(array_merge($stage, [
                'workflow_id' => $workflow->id,
                'order_index' => $index,
            ]));
        }

        // ── Vacancy template ──────────────────────────────────────────────
        $template = VacancyTemplate::create([
            'organization_id'         => $org->id,
            'workflow_id'             => $workflow->id,
            'name'                    => 'Software Engineer Template',
            'job_title'               => 'Software Engineer',
            'location'                => 'Nairobi, KE',
            'default_positions'       => 2,
            'is_active'               => true,
            'application_form_schema' => [
                ['key' => 'years_experience', 'label' => 'Years of Experience', 'type' => 'number',   'required' => true],
                ['key' => 'cover_letter',     'label' => 'Cover Letter',         'type' => 'textarea', 'required' => false],
                ['key' => 'linkedin',         'label' => 'LinkedIn Profile URL', 'type' => 'url',      'required' => false],
            ],
            'screening_rules' => [
                ['type' => 'keyword_match', 'keywords' => ['laravel', 'php', 'vue', 'typescript'], 'weight' => 20],
                ['type' => 'required_field', 'field'   => 'years_experience',                      'weight' => 10],
            ],
        ]);

        // ── Open vacancy ──────────────────────────────────────────────────
        $vacancy = Vacancy::create([
            'organization_id'         => $org->id,
            'template_id'             => $template->id,
            'workflow_id'             => $workflow->id,
            'name'                    => 'Senior Software Engineer — Backend',
            'job_title'               => 'Senior Software Engineer',
            'location'                => 'Nairobi, KE',
            'open_positions'          => 2,
            'status'                  => 'open',
            'description'             => 'We are looking for a senior backend engineer to join our growing team.',
            'application_form_schema' => $template->application_form_schema,
        ]);

        // Draft vacancy
        Vacancy::create([
            'organization_id' => $org->id,
            'workflow_id'     => $workflow->id,
            'name'            => 'HR Business Partner',
            'job_title'       => 'HR Business Partner',
            'location'        => 'Remote',
            'open_positions'  => 1,
            'status'          => 'draft',
        ]);

        // ── Candidates ────────────────────────────────────────────────────
        $stages = $workflow->stages()->orderBy('order_index')->get();
        $firstStage = $stages->first();
        $secondStage = $stages->get(1);

        $candidate1 = Candidate::create([
            'organization_id' => $org->id,
            'first_name'      => 'Alice',
            'last_name'       => 'Mwangi',
            'email'           => 'alice.mwangi@example.com',
            'phone'           => '+254 700 111 222',
            'keywords'        => ['laravel', 'php', 'vue', 'typescript', 'postgresql'],
            'source'          => 'LinkedIn',
        ]);

        $candidate2 = Candidate::create([
            'organization_id' => $org->id,
            'first_name'      => 'Brian',
            'last_name'       => 'Ochieng',
            'email'           => 'brian.ochieng@example.com',
            'phone'           => '+254 722 333 444',
            'keywords'        => ['python', 'django', 'react'],
            'source'          => 'Referral',
        ]);

        // ── Applications ──────────────────────────────────────────────────
        $app1 = Application::create([
            'vacancy_id'       => $vacancy->id,
            'candidate_id'     => $candidate1->id,
            'current_stage_id' => $secondStage?->id ?? $firstStage?->id,
            'status'           => 'active',
            'screening_score'  => 85,
            'form_data'        => ['years_experience' => 6, 'cover_letter' => 'Excited to apply...'],
            'applied_at'       => now()->subDays(3),
        ]);

        ApplicationStageHistory::create([
            'application_id' => $app1->id,
            'from_stage_id'  => null,
            'to_stage_id'    => $firstStage->id,
            'moved_by'       => $user->id,
            'moved_at'       => now()->subDays(3),
        ]);

        if ($secondStage) {
            ApplicationStageHistory::create([
                'application_id' => $app1->id,
                'from_stage_id'  => $firstStage->id,
                'to_stage_id'    => $secondStage->id,
                'moved_by'       => $user->id,
                'notes'          => 'Strong candidate, moved to phone screen.',
                'moved_at'       => now()->subDays(1),
            ]);
        }

        Application::create([
            'vacancy_id'       => $vacancy->id,
            'candidate_id'     => $candidate2->id,
            'current_stage_id' => $firstStage?->id,
            'status'           => 'active',
            'screening_score'  => 42,
            'form_data'        => ['years_experience' => 3],
            'applied_at'       => now()->subDay(),
        ]);

        $this->command->info('Recruitment seed complete.');
    }
}
