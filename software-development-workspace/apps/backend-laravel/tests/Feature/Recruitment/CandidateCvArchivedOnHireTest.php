<?php

namespace Tests\Feature\Recruitment;

use App\Models\EmployeeDocument;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Models\Preboarding\PreboardingRecord;
use App\Models\Recruitment\Candidate;
use App\Services\HR\PreboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A hired candidate's CV must survive the six-month retention rule.
 *
 * Candidate documents expire by lifecycle policy, which is right for people
 * nobody hired and wrong for the one whose CV became part of a personnel file.
 * Azure cannot tell them apart, so the copy happens at conversion.
 *
 * These tests exist because the failure is invisible. Nothing errors, nothing
 * is logged at the time, and the gap only appears months later when a document
 * that used to be there is not.
 */
class CandidateCvArchivedOnHireTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private PreboardingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org     = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->service = app(PreboardingService::class);

        // Conversions happen as an authenticated HR user; employee_documents
        // records who filed the document and the column is NOT NULL.
        $this->actingAs(User::factory()->create());
    }

    private function candidate(string $email, bool $withCv = true): Candidate
    {
        $candidate = Candidate::create([
            'organization_id' => $this->org->id,
            'first_name'      => 'Ada',
            'last_name'       => 'Lovelace',
            'email'           => $email,
        ]);

        if ($withCv) {
            $path = UploadedFile::fake()
                ->create('cv.pdf', 12, 'application/pdf')
                ->store("resumes/{$candidate->id}", 'candidate_docs');
            $candidate->update(['resume_path' => $path]);
        }

        return $candidate->fresh();
    }


    /**
     * A real employee row: employee_documents has a foreign key, so an
     * invented id fails the insert and — inside a transaction — poisons
     * everything after it.
     */
    private function employee(): int
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name'              => 'Ada',
            'last_name'               => 'Lovelace',
            'employment_type'         => 'permanent',
            'date_hired'              => '2026-01-01',
        ])->id;
    }

    private function record(string $email, ?int $candidateId = null): PreboardingRecord
    {
        return PreboardingRecord::create([
            'organization_id' => $this->org->id,
            'candidate_id'    => $candidateId,
            'first_name'      => 'Ada',
            'last_name'       => 'Lovelace',
            'email'           => $email,
            'status'          => 'in_progress',
            'token'           => \Illuminate\Support\Str::random(56),
        ]);
    }

    public function test_the_cv_is_copied_into_employee_documents_on_conversion(): void
    {
        $employeeId = $this->employee();
        $candidate = $this->candidate('ada@example.test');
        $record    = $this->record('ada@example.test', $candidate->id);

        $this->service->markConverted($record, employeeId: $employeeId);

        $doc = EmployeeDocument::where('employee_id', $employeeId)->where('category', 'cv')->first();

        $this->assertNotNull($doc, 'A hired candidate\'s CV must be preserved.');
        $this->assertTrue(
            Storage::disk('employee_documents')->exists($doc->storage_path),
            'The archived CV must actually exist on the employee disk.'
        );
        $this->assertSame(
            Storage::disk('candidate_docs')->get($candidate->resume_path),
            Storage::disk('employee_documents')->get($doc->storage_path),
            'The archived copy must be byte-identical to the original.'
        );
    }

    public function test_it_resolves_the_candidate_by_email_when_candidate_id_is_absent(): void
    {
        $employeeId = $this->employee();
        // candidate_id is nullable and unset on every existing preboarding
        // record, so matching by email is the path that actually runs.
        $this->candidate('grace@example.test');
        $record = $this->record('grace@example.test', candidateId: null);

        $this->service->markConverted($record, employeeId: $employeeId);

        $this->assertNotNull(
            EmployeeDocument::where('employee_id', $employeeId)->where('category', 'cv')->first(),
            'The CV must be found by email when candidate_id is not set.'
        );
    }

    public function test_converting_twice_does_not_duplicate_the_document(): void
    {
        $employeeId = $this->employee();
        $candidate = $this->candidate('repeat@example.test');
        $record    = $this->record('repeat@example.test', $candidate->id);

        $this->service->markConverted($record, employeeId: $employeeId);
        $this->service->markConverted($record->fresh(), employeeId: $employeeId);

        $this->assertSame(
            1,
            EmployeeDocument::where('employee_id', $employeeId)->where('category', 'cv')->count(),
            'Re-running a conversion must not stack up copies.'
        );
    }

    public function test_a_candidate_without_a_cv_converts_without_error(): void
    {
        $employeeId = $this->employee();
        $candidate = $this->candidate('nocv@example.test', withCv: false);
        $record    = $this->record('nocv@example.test', $candidate->id);

        $converted = $this->service->markConverted($record, employeeId: $employeeId);

        $this->assertSame('converted', $converted->status);
        $this->assertSame(0, EmployeeDocument::where('employee_id', $employeeId)->count());
    }

    public function test_a_missing_source_file_does_not_block_the_hire(): void
    {
        $employeeId = $this->employee();
        // The database can point at a file that is no longer on disk — three
        // candidates in production already do. Hiring must not fail for it.
        $candidate = $this->candidate('ghost@example.test');
        Storage::disk('candidate_docs')->delete($candidate->resume_path);

        $record    = $this->record('ghost@example.test', $candidate->id);
        $converted = $this->service->markConverted($record, employeeId: $employeeId);

        $this->assertSame('converted', $converted->status, 'The hire must still complete.');
        $this->assertSame(0, EmployeeDocument::where('employee_id', $employeeId)->count());
    }

    public function test_a_candidate_from_another_organisation_is_never_matched(): void
    {
        $employeeId = $this->employee();
        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);

        $foreign = Candidate::create([
            'organization_id' => $other->id,
            'first_name'      => 'Ada', 'last_name' => 'Lovelace',
            'email'           => 'shared@example.test',
        ]);
        $foreign->update([
            'resume_path' => UploadedFile::fake()
                ->create('cv.pdf', 12, 'application/pdf')
                ->store("resumes/{$foreign->id}", 'candidate_docs'),
        ]);

        // Same address, different tenant: matching on email alone would leak
        // one organisation's candidate document into another's employee file.
        $record = $this->record('shared@example.test', candidateId: null);

        $this->service->markConverted($record, employeeId: $employeeId);

        $this->assertSame(
            0,
            EmployeeDocument::where('employee_id', $employeeId)->count(),
            'A candidate belonging to another organisation must never be archived here.'
        );
    }
}
