<?php

namespace Tests\Feature\Recruitment;

use App\Models\Organization;
use App\Models\Recruitment\Candidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Candidate CVs must not be reachable without a valid signature.
 *
 * They were: uploads landed on the `public` disk, which the storage symlink
 * exposes, so the URL alone was enough — no login, no expiry. These tests fix
 * the boundary in place, because the failure is silent. Nothing about a CV
 * stored on the wrong disk looks wrong until someone fetches one.
 */
class CandidateResumePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
    }

    private function candidateWithResume(): Candidate
    {
        $candidate = Candidate::create([
            'organization_id' => $this->org->id,
            'first_name'      => 'Ada',
            'last_name'       => 'Lovelace',
            'email'           => 'ada+' . fake()->unique()->numberBetween(1, 999999) . '@example.test',
        ]);

        $path = UploadedFile::fake()
            ->create('cv.pdf', 12, 'application/pdf')
            ->store("resumes/{$candidate->id}", 'candidate_docs');

        $candidate->update(['resume_path' => $path]);

        return $candidate->fresh();
    }

    public function test_uploaded_resumes_never_land_on_the_public_disk(): void
    {
        $candidate = $this->candidateWithResume();

        $this->assertTrue(
            Storage::disk('candidate_docs')->exists($candidate->resume_path),
            'CV should be on the private disk.'
        );
        $this->assertFalse(
            Storage::disk('public')->exists($candidate->resume_path),
            'CV must not be on the publicly served disk.'
        );
    }

    public function test_an_unsigned_request_for_a_resume_is_rejected(): void
    {
        $candidate = $this->candidateWithResume();

        $this->get("/api/public/candidates/{$candidate->id}/resume")
            ->assertForbidden();
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        $candidate = $this->candidateWithResume();
        $other     = $this->candidateWithResume();

        // Swapping the id after signing is the obvious attack: one legitimately
        // issued link would otherwise read every candidate's CV.
        $url = str_replace(
            "/{$candidate->id}/resume",
            "/{$other->id}/resume",
            $candidate->signedResumeUrl()
        );

        $this->get($url)->assertForbidden();
    }

    public function test_an_expired_signature_is_rejected(): void
    {
        $candidate = $this->candidateWithResume();
        $url       = $candidate->signedResumeUrl(30);

        $this->travel(31)->minutes();

        $this->get($url)->assertForbidden();
    }

    public function test_a_valid_signature_streams_the_file(): void
    {
        $candidate = $this->candidateWithResume();

        $response = $this->get($candidate->signedResumeUrl());

        $response->assertOk();
        // Laravel appends its own directives, so assert ours survives rather
        // than pinning the whole header.
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_a_candidate_without_a_resume_has_no_link(): void
    {
        $candidate = Candidate::create([
            'organization_id' => $this->org->id,
            'first_name'      => 'Grace',
            'last_name'       => 'Hopper',
            'email'           => 'grace@example.test',
        ]);

        $this->assertNull($candidate->signedResumeUrl());
    }

    public function test_the_signed_route_is_reachable_without_an_organisation_context(): void
    {
        // External panellists follow these links from email, with no bearer
        // token and no X-Organization header. If the tenancy global scope ran
        // here the lookup would fail and the link would 404 for exactly the
        // people it exists for.
        $candidate = $this->candidateWithResume();

        $url = URL::temporarySignedRoute(
            'candidates.resume.signed',
            now()->addMinutes(10),
            ['candidate' => $candidate->id],
        );

        $this->withHeaders([])->get($url)->assertOk();
    }
}
