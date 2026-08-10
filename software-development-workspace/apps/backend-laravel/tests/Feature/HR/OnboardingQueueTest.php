<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Preboarding\PreboardingRecord;
use App\Models\Role;
use App\Models\User;
use App\Services\HR\PreboardingService;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The onboarding queue — who is still filling things in, and who is waiting
 * on HR.
 *
 * Sections could be submitted one at a time and the record itself never moved
 * past `in_progress`, so nobody was told when somebody finished and the queue
 * could not tell a pre-hire halfway through their details from one waiting on
 * HR to act. That distinction is the whole point of the page.
 */
class OnboardingQueueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->hr = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'HR', 'scope' => 'organization',
            'permissions' => [Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_CREATE],
        ]);
        $this->hr->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $this->hr->forgetCachedRoles();

        Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'HR', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'user_id' => $this->hr->id,
        ]);

        Sanctum::actingAs($this->hr);
    }

    private function record(array $attrs = []): PreboardingRecord
    {
        return PreboardingRecord::create($attrs + [
            'organization_id' => $this->org->id,
            'first_name' => 'Sarah', 'last_name' => 'Kim',
            'email' => 'sarah@example.com',
            'status' => 'pending',
            'initiated_by' => $this->hr->id,
            'token' => bin2hex(random_bytes(16)),
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/preboarding{$path}?org={$this->org->slug}";
    }

    // ── The counts ────────────────────────────────────────────────────────

    /**
     * Onboarding states, not the requisition ones the mock inherited — there
     * is no approval chain here and nothing to hold.
     */
    public function test_the_counts_describe_onboarding_rather_than_approvals(): void
    {
        $this->record();                                    // invited, not started
        $this->record(['status' => 'in_progress']);         // filling it in
        $this->record(['status' => 'submitted']);           // back with HR
        $this->record(['status' => 'converted']);           // done this month

        $this->getJson($this->url('/summary'))
            ->assertOk()
            ->assertJsonPath('awaiting_submission', 1)
            ->assertJsonPath('in_progress', 1)
            ->assertJsonPath('submitted', 1)
            ->assertJsonPath('completed_this_month', 1);
    }

    /**
     * Their first day has arrived and they are still not on the system. The
     * one number with a deadline attached, and the reason the page is opened.
     */
    public function test_somebody_whose_start_date_has_passed_is_overdue(): void
    {
        $this->record([
            'status' => 'in_progress',
            'expected_start_date' => now()->subDays(3)->toDateString(),
        ]);

        $this->getJson($this->url('/summary'))->assertOk()->assertJsonPath('overdue', 1);
    }

    /** Somebody already on the system is not overdue, whatever the date says. */
    public function test_a_converted_record_is_never_overdue(): void
    {
        $this->record([
            'status' => 'converted',
            'expected_start_date' => now()->subMonth()->toDateString(),
        ]);

        $this->getJson($this->url('/summary'))->assertOk()->assertJsonPath('overdue', 0);
    }

    public function test_a_start_date_still_ahead_is_not_overdue(): void
    {
        $this->record([
            'status' => 'in_progress',
            'expected_start_date' => now()->addWeek()->toDateString(),
        ]);

        $this->getJson($this->url('/summary'))->assertOk()->assertJsonPath('overdue', 0);
    }

    // ── Finishing ─────────────────────────────────────────────────────────

    /**
     * The moment the designs' notification describes. Without it the record
     * sat at `in_progress` for ever and nobody knew to look.
     */
    public function test_the_record_closes_when_the_last_section_is_submitted(): void
    {
        $service = app(PreboardingService::class);

        // Built the way the application builds them, so the enabled sections
        // are seeded and the completion rule has something real to judge.
        $record = $service->create($this->org->id, [
            'first_name' => 'Sarah', 'last_name' => 'Kim', 'email' => 'sarah@example.com',
            'enabled_sections' => ['personal', 'contact'],
        ], $this->hr->id);

        $service->updateSection($record, 'personal', ['first_name' => 'Sarah'], 'candidate');
        $this->assertSame('in_progress', $record->fresh()->status, 'One of two is not finished.');

        $service->updateSection($record->fresh(), 'contact', ['phone' => '0712345678']);

        $fresh = $record->fresh();
        $this->assertSame('submitted', $fresh->status);
        $this->assertNotNull($fresh->submitted_at);
    }

    /** Whoever set it up is the one who has to act on it. */
    public function test_finishing_tells_whoever_initiated_it(): void
    {
        $service = app(PreboardingService::class);
        $record = $service->create($this->org->id, [
            'first_name' => 'Sarah', 'last_name' => 'Kim', 'email' => 'sarah@example.com',
            'enabled_sections' => ['personal'],
        ], $this->hr->id);

        $service->updateSection($record, 'personal', ['x' => 1]);

        $notification = Notification::where('user_id', $this->hr->id)->firstOrFail();

        $this->assertSame('onboarding.submitted', $notification->type);
        $this->assertSame('New Onboarding Submission', $notification->title);
        $this->assertStringContainsString('Sarah Kim', $notification->message);
    }

    /**
     * A section marked not applicable is a decision already taken, not work
     * outstanding — it must not hold the record open for ever.
     */
    public function test_a_not_applicable_section_does_not_hold_the_record_open(): void
    {
        $service = app(PreboardingService::class);
        $record = $service->create($this->org->id, [
            'first_name' => 'Sarah', 'last_name' => 'Kim', 'email' => 'sarah@example.com',
            'enabled_sections' => ['personal', 'contact', 'immigration'],
        ], $this->hr->id);

        $service->updateSection($record, 'personal', ['x' => 1]);
        $service->markSectionNotApplicable($record->fresh(), 'immigration');
        $service->updateSection($record->fresh(), 'contact', ['y' => 2]);

        $this->assertSame('submitted', $record->fresh()->status);
    }

    /** Told once. A second section arriving later must not re-announce it. */
    public function test_the_submission_is_announced_only_once(): void
    {
        $service = app(PreboardingService::class);
        $record = $service->create($this->org->id, [
            'first_name' => 'Sarah', 'last_name' => 'Kim', 'email' => 'sarah@example.com',
            'enabled_sections' => ['personal', 'contact'],
        ], $this->hr->id);

        $service->updateSection($record, 'personal', ['x' => 1]);
        $service->updateSection($record->fresh(), 'contact', ['y' => 2]);
        // Revisiting a section afterwards must not re-announce it.
        $service->updateSection($record->fresh(), 'personal', ['x' => 2]);

        $this->assertSame(1, Notification::where('type', 'onboarding.submitted')->count());
    }

    // ── Initiating ────────────────────────────────────────────────────────

    /**
     * The panel's fields are already carried by the record — which sections to
     * collect, how long the link lives, and whether dependants apply.
     */
    public function test_initiating_records_what_was_asked_for(): void
    {
        $this->postJson($this->url(), [
            'first_name' => 'Sarah', 'last_name' => 'Kim',
            'email' => 'sarah@example.com',
            'position' => 'Senior Product Designer',
            'employee_type' => 'permanent',
            'expected_start_date' => now()->addMonth()->toDateString(),
            'link_expiry_days' => 7,
            'medical_cover_eligible' => true,
            'enabled_sections' => ['personal_details', 'contact_details', 'dependants'],
        ])->assertCreated();

        $record = PreboardingRecord::firstOrFail();

        $this->assertSame(7, $record->link_expiry_days);
        $this->assertTrue($record->medical_cover_eligible);
        $this->assertContains('dependants', $record->form_config['enabled_sections'] ?? $record->form_config ?? []);
    }
}
