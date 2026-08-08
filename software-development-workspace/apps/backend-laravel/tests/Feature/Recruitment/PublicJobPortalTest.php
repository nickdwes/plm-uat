<?php

namespace Tests\Feature\Recruitment;

use App\Models\Organization;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\RecruitmentWorkflow;
use App\Models\Recruitment\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The public job portal — the one door anonymous strangers can reach.
 *
 * Everything else in this system is behind a login and an organisation scope.
 * This is not, and it accepts file uploads from people nobody has vetted, so
 * what it does with them is worth pinning down.
 *
 * The portal sits at /api/public rather than under /api/v1: the version prefix
 * wraps only the authenticated group.
 */
class PublicJobPortalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private RecruitmentWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('candidate_docs');

        /*
         * The throttle is real and its state is shared across a run, so every
         * test after the first few would trip it on requests that have nothing
         * to do with rate limiting. It is switched off here and asserted
         * directly below, on the route, which is both stable and the thing
         * actually worth guaranteeing.
         */
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha', 'is_active' => true]);
        app()->instance('current.organization_id', $this->org->id);

        $this->workflow = RecruitmentWorkflow::create([
            'organization_id' => $this->org->id, 'name' => 'Standard', 'is_default' => true,
        ]);
        $this->workflow->stages()->create([
            'name' => 'Application Received', 'order_index' => 1, 'stage_type' => 'applied',
        ]);
    }

    private function vacancy(array $attrs = []): Vacancy
    {
        return Vacancy::create($attrs + [
            'organization_id' => $this->org->id,
            'workflow_id' => $this->workflow->id,
            'name' => 'Senior Product Designer', 'job_title' => 'Senior Product Designer',
            'status' => 'open', 'open_positions' => 2, 'location' => 'Nairobi',
        ]);
    }

    private function apply(Vacancy $v, array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->post("/api/public/jobs/{$v->uuid}/apply", $body + [
            'first_name' => 'Amina', 'last_name' => 'Otieno',
            'email' => 'amina@example.com',
            'cv' => UploadedFile::fake()->create('cv.pdf', 40, 'application/pdf'),
        ]);
    }

    // ── What is done with the files ───────────────────────────────────────

    /**
     * A CV carries a name, an address, a phone number and an employment
     * history. It is the most sensitive thing this system accepts from
     * outside, and it arrived through the one door with no login on it —
     * straight past the vault every other document class goes through.
     */
    public function test_an_uploaded_cv_is_encrypted_at_rest(): void
    {
        $v = $this->vacancy();

        $this->apply($v)->assertCreated();

        $path = Candidate::firstOrFail()->resume_path;

        $this->assertNotNull($path);
        $this->assertStringStartsWith('KVAULT001:', Storage::disk('candidate_docs')->get($path));
    }

    public function test_an_uploaded_attachment_is_encrypted_at_rest(): void
    {
        $v = $this->vacancy();

        $this->apply($v, [
            'attachment' => UploadedFile::fake()->create('portfolio.pdf', 40, 'application/pdf'),
        ])->assertCreated();

        $path = Application::firstOrFail()->form_data['attachment_path'] ?? null;

        $this->assertNotNull($path);
        $this->assertStringStartsWith('KVAULT001:', Storage::disk('candidate_docs')->get($path));
    }

    /** A renamed executable satisfies an extension check on its own. */
    public function test_a_file_whose_bytes_are_not_what_its_name_claims_is_refused(): void
    {
        $v = $this->vacancy();

        $this->apply($v, [
            'cv' => UploadedFile::fake()->create('cv.pdf', 40, 'application/x-msdownload'),
        ])->assertJsonValidationErrorFor('cv');
    }

    // ── What it tells a stranger ──────────────────────────────────────────

    /**
     * The old reply let anybody holding a vacancy link test whether a given
     * address had applied, one email at a time. Answering identically either
     * way removes the oracle — and re-applying usually means sending a newer
     * CV, which is what now happens.
     */
    public function test_applying_twice_is_indistinguishable_from_applying_once(): void
    {
        $v = $this->vacancy();

        $first = $this->apply($v)->assertCreated();

        $second = $this->apply($v, [
            'cv' => UploadedFile::fake()->create('newer.pdf', 40, 'application/pdf'),
        ])->assertCreated();

        $this->assertSame($first->json('message'), $second->json('message'));
        $this->assertSame($first->json('reference'), $second->json('reference'));

        // One application, and the newer CV kept.
        $this->assertSame(1, Application::count());
    }

    /**
     * `APP-000042` told an applicant how many applications the whole portal
     * had taken — across every organisation on it, since the board is shared.
     */
    public function test_the_reference_does_not_disclose_how_many_have_applied(): void
    {
        $first  = $this->apply($this->vacancy())->assertCreated()->json('reference');
        $second = $this->apply($this->vacancy(['name' => 'Another role']), [
            'email' => 'other@example.com',
        ])->assertCreated()->json('reference');

        $application = Application::orderBy('id')->first();

        $this->assertStringStartsWith('APP-', $first);
        // Not the padded primary key it used to be.
        $this->assertNotSame('APP-' . str_pad((string) $application->id, 6, '0', STR_PAD_LEFT), $first);
        // And two references give away no ordering between them.
        $this->assertNotSame($first, $second);
    }

    /**
     * The limit is the only thing between this endpoint and somebody filling
     * the disk with 5MB files, so it is pinned rather than assumed.
     *
     * Asserted on the route rather than by making six requests: the limiter's
     * state is shared across a run, so exercising it makes every later test
     * depend on how many requests happened to come before.
     */
    public function test_the_apply_endpoint_is_rate_limited(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/public/jobs/{uuid}/apply',
        );

        $this->assertNotNull($route, 'The apply route has moved.');
        $this->assertContains('throttle:5,1', $route->gatherMiddleware());
    }

    /** The advert says what the job is, and nothing about how it is run. */
    public function test_the_advert_withholds_every_internal_identifier(): void
    {
        $v = $this->vacancy(['application_form_schema' => [['key' => 'cv']]]);

        $body = $this->getJson("/api/public/jobs/{$v->uuid}")->assertOk()->json('data');

        foreach (['id', 'organization_id', 'workflow_id', 'template_id', 'hiring_manager_id'] as $internal) {
            $this->assertArrayNotHasKey($internal, $body, "The advert leaked {$internal}.");
        }
    }

    // ── What it refuses to advertise ──────────────────────────────────────

    /**
     * A switched-off tenant should not still be advertising, and certainly not
     * still collecting CVs into itself.
     */
    public function test_a_deactivated_organisation_disappears_from_the_board(): void
    {
        $v = $this->vacancy();

        $this->getJson("/api/public/jobs/{$v->uuid}")->assertOk();
        $this->getJson('/api/public/jobs')->assertOk()->assertJsonCount(1, 'data');

        $this->org->update(['is_active' => false]);

        $this->getJson("/api/public/jobs/{$v->uuid}")->assertNotFound();
        $this->getJson('/api/public/jobs')->assertOk()->assertJsonCount(0, 'data');
        $this->apply($v)->assertNotFound();
    }

    /**
     * A vacancy nobody remembered to close would keep accepting applications
     * for ever, and every one is a CV taken for a role nobody is filling.
     */
    public function test_a_vacancy_past_its_closing_date_stops_accepting_applications(): void
    {
        $v = $this->vacancy(['closing_date' => now()->subDay()->toDateString()]);

        $this->apply($v)->assertNotFound();
        $this->assertSame(0, Application::count());
    }

    public function test_a_vacancy_closing_today_is_still_open(): void
    {
        $v = $this->vacancy(['closing_date' => now()->toDateString()]);

        $this->apply($v)->assertCreated();
    }

    public function test_a_closed_vacancy_cannot_be_applied_to(): void
    {
        $v = $this->vacancy(['status' => 'closed']);

        $this->apply($v)->assertNotFound();
        $this->assertSame(0, Application::count());
    }
}
