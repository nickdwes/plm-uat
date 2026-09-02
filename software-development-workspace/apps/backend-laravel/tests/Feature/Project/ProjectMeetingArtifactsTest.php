<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
use App\Models\Integration\IntegrationConnection;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectMeeting;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pulling a Teams meeting's recording link and transcript back onto the row.
 *
 * The Graph calls are faked here — what is proved is the orchestration: resolve
 * the online meeting from the join URL, take the latest recording and
 * transcript, store them, and report an honest status when nothing is there
 * yet or permission is missing.
 */
class ProjectMeetingArtifactsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);

        $conn = IntegrationConnection::create([
            'organization_id' => $this->org->id, 'name' => 'MS', 'provider' => 'microsoft',
            'tenant_id' => 'tenant-1', 'client_id' => 'client-1', 'client_secret' => 'secret-1',
            'is_active' => true,
        ]);
        $this->org->update(['calendar_connection_id' => $conn->id]);
        config(['app.hr_calendar_mailbox' => 'pm@alpha.example']);
        putenv('HR_CALENDAR_MAILBOX=pm@alpha.example');

        $this->project = Project::create([
            'organization_id' => $this->org->id, 'name' => 'Versa SD-WAN',
            'project_email' => 'sdwan@alpha.example',
        ]);
    }

    private function editor(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization',
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'T', 'last_name' => 'P',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01', 'user_id' => $user->id,
        ]);
        Sanctum::actingAs($user);
    }

    private function meeting(): ProjectMeeting
    {
        return ProjectMeeting::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'subject' => 'Kickoff', 'scheduled_at' => now()->subDay(), 'duration_minutes' => 30,
            'attendees' => [], 'status' => 'scheduled',
            'join_url' => 'https://teams.microsoft.com/l/meetup-join/xyz',
        ]);
    }

    private function url(int $id, string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/meetings/{$id}{$path}?org={$this->org->slug}";
    }

    public function test_it_stores_the_latest_recording_link_and_transcript_text(): void
    {
        $this->editor();
        $m = $this->meeting();

        Http::fake([
            'login.microsoftonline.com/*'          => Http::response(['access_token' => 'tok'], 200),
            '*users/sdwan@alpha.example*'             => Http::response(['id' => 'USER-GUID'], 200),
            '*/onlineMeetings?*'                    => Http::response(['value' => [['id' => 'MEET-1']]], 200),
            '*/onlineMeetings/MEET-1/recordings'    => Http::response(['value' => [
                ['id' => 'R1', 'createdDateTime' => '2026-08-01T10:00:00Z', 'recordingContentUrl' => 'https://stream/old'],
                ['id' => 'R2', 'createdDateTime' => '2026-08-02T10:00:00Z', 'recordingContentUrl' => 'https://stream/latest'],
            ]], 200),
            '*/onlineMeetings/MEET-1/transcripts'   => Http::response(['value' => [
                ['id' => 'T1', 'createdDateTime' => '2026-08-02T10:05:00Z'],
            ]], 200),
            '*/transcripts/T1/content*'             => Http::response("WEBVTT\n\n00:00.000 --> 00:02.000\nHello team.", 200),
        ]);

        $res = $this->postJson($this->url($m->id, '/sync'))->assertOk();

        $this->assertSame('updated', $res->json('status'));
        $this->assertSame('https://stream/latest', $res->json('data.recording_url'));
        $this->assertTrue($res->json('data.has_transcript'));
        $this->assertStringContainsString('Hello team.', $res->json('data.transcript'));
    }

    public function test_the_organiser_is_read_from_the_join_url_no_directory_lookup(): void
    {
        // A real Teams join URL carries the organiser's object id in `context`,
        // so no /users/{upn} lookup (and no User.Read.All) is needed.
        $this->editor();
        $m = ProjectMeeting::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'subject' => 'Kickoff', 'scheduled_at' => now()->subDay(), 'duration_minutes' => 30,
            'attendees' => [], 'status' => 'scheduled',
            'join_url' => 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc%40thread.v2/0?context=%7b%22Tid%22%3a%22t%22%2c%22Oid%22%3a%22ORG-OID%22%7d',
        ]);

        Http::fake([
            'login.microsoftonline.com/*'         => Http::response(['access_token' => 'tok'], 200),
            // Deliberately NOT faking */users/* — if the code tried a directory
            // lookup this request would go live and the test would break.
            '*/onlineMeetings?*'                  => Http::response(['value' => [['id' => 'MEET-9']]], 200),
            '*/onlineMeetings/MEET-9/recordings'  => Http::response(['value' => [
                ['id' => 'R9', 'createdDateTime' => '2026-08-02T10:00:00Z', 'recordingContentUrl' => 'https://stream/x'],
            ]], 200),
            '*/onlineMeetings/MEET-9/transcripts' => Http::response(['value' => []], 200),
        ]);

        $res = $this->postJson($this->url($m->id, '/sync'))->assertOk();
        $this->assertSame('updated', $res->json('status'));
        $this->assertSame('https://stream/x', $res->json('data.recording_url'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/users/sdwan@alpha.example'));
        // We store the stream link only — the recording media is never downloaded.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/recordings/R9/content'));
    }

    public function test_it_reports_when_the_meeting_is_not_yet_available(): void
    {
        $this->editor();
        $m = $this->meeting();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok'], 200),
            '*users/sdwan@alpha.example*'   => Http::response(['id' => 'USER-GUID'], 200),
            '*/onlineMeetings?*'          => Http::response(['value' => []], 200),
        ]);

        $res = $this->postJson($this->url($m->id, '/sync'))->assertOk();
        $this->assertSame('not_ready', $res->json('status'));
        $this->assertNull($res->json('data.recording_url'));
    }

    public function test_missing_graph_permission_is_reported_clearly(): void
    {
        $this->editor();
        $m = $this->meeting();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok'], 200),
            // Permission is refused at the first artifact call — resolving the organiser.
            '*users/sdwan@alpha.example*'   => Http::response(['error' => ['message' => 'Forbidden']], 403),
        ]);

        $res = $this->postJson($this->url($m->id, '/sync'))->assertStatus(422);
        $this->assertStringContainsString('Access denied', $res->json('message'));
    }

    public function test_sync_needs_the_update_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'V', 'scope' => 'organization', 'permissions' => [Permission::PROJECTS_VIEW],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
        $m = $this->meeting();

        $this->postJson($this->url($m->id, '/sync'))->assertForbidden();
    }
}
