<?php

namespace Tests\Feature\Task;

use App\Models\Employee;
use App\Models\Integration\IntegrationConnection;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectMeeting;
use App\Models\Role;
use App\Models\Support\Ticket;
use App\Models\Support\TicketMeeting;
use App\Models\Support\TicketStatus;
use App\Models\Task\Task;
use App\Models\Task\TaskMeeting;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Meetings scheduled from a task.
 *
 * The point is harmonised trackability: a task is only where a call is booked;
 * it is *tracked* where the task belongs. A project task's call surfaces on the
 * project's Correspondence, a support task's on the ticket's Meetings, and a
 * task with neither keeps its own — all through the one task_id link.
 */
class TaskMeetingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $conn = IntegrationConnection::create([
            'organization_id' => $this->org->id, 'name' => 'MS', 'provider' => 'microsoft',
            'tenant_id' => 'tenant-1', 'client_id' => 'client-1', 'client_secret' => 'secret-1',
            'is_active' => true,
        ]);
        $this->org->update(['calendar_connection_id' => $conn->id]);
        putenv('HR_CALENDAR_MAILBOX=pm@alpha.example');
    }

    private function actor(array $permissions): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
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

    private function task(array $attrs = []): Task
    {
        return Task::create($attrs + [
            'organization_id' => $this->org->id,
            'title'           => 'Service review',
        ]);
    }

    private function url(int $taskId, string $path = ''): string
    {
        return "/api/v1/tasks/{$taskId}/meetings{$path}?org={$this->org->slug}";
    }

    private function ticket(): Ticket
    {
        $status = TicketStatus::create([
            'organization_id' => $this->org->id, 'name' => 'New', 'slug' => 'new',
            'state' => 'open', 'is_default' => true, 'position' => 1, 'is_active' => true,
        ]);

        return Ticket::create([
            'organization_id' => $this->org->id, 'reference' => 'SD-1', 'subject' => 'VPN down',
            'ticket_status_id' => $status->id,
        ]);
    }

    /** createEvent is faked so no real Teams call is made. */
    private function fakeGraphCreate(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok'], 200),
            '*/events'                    => Http::response([
                'id'            => 'EVENT-1',
                'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/xyz'],
            ], 201),
        ]);
    }

    private function draft(): array
    {
        return [
            'subject'          => 'Weekly review',
            'scheduled_at'     => now()->addDay()->toIso8601String(),
            'duration_minutes' => 30,
            'attendees'        => [['address' => 'guest@firm.example', 'name' => 'Guest']],
        ];
    }

    // ── Where the tab says a task's meetings are tracked ─────────────────────

    public function test_a_project_task_reports_the_project_as_its_destination(): void
    {
        $this->actor([Permission::TASKS_VIEW]);
        $project = Project::create(['organization_id' => $this->org->id, 'name' => 'SD-WAN']);
        $task = $this->task(['project_id' => $project->id]);

        $this->getJson($this->url($task->id))
            ->assertOk()
            ->assertJsonPath('destination.type', 'project')
            ->assertJsonPath('destination.id', $project->id)
            ->assertJsonPath('destination.label', 'SD-WAN');
    }

    public function test_a_support_task_reports_the_ticket_as_its_destination(): void
    {
        $this->actor([Permission::TASKS_VIEW]);
        $ticket = $this->ticket();
        $task = $this->task([
            'category' => 'support', 'source' => 'support',
            'generated_from_type' => Ticket::class, 'generated_from_id' => $ticket->id,
        ]);

        $this->getJson($this->url($task->id))
            ->assertOk()
            ->assertJsonPath('destination.type', 'ticket')
            ->assertJsonPath('destination.id', $ticket->id)
            ->assertJsonPath('destination.label', 'SD-1');
    }

    public function test_a_standalone_task_is_tracked_on_itself(): void
    {
        $this->actor([Permission::TASKS_VIEW]);
        $task = $this->task();

        $this->getJson($this->url($task->id))
            ->assertOk()
            ->assertJsonPath('destination.type', 'none');
    }

    public function test_a_support_task_offers_the_people_copied_on_the_ticket_email(): void
    {
        $this->actor([Permission::TASKS_VIEW]);
        $ticket = $this->ticket();
        $ticket->update(['requester_name' => 'Ann', 'requester_email' => 'ann@client.example']);

        $email = \App\Models\Support\TicketEmail::create([
            'organization_id' => $this->org->id, 'support_ticket_id' => $ticket->id,
            'direction' => 'inbound', 'from_email' => 'ann@client.example',
            'subject' => 'VPN down', 'received_at' => now(),
        ]);
        $email->recipients()->createMany([
            ['kind' => 'to',  'name' => 'Support', 'address' => 'support@alpha.example'],
            ['kind' => 'cc',  'name' => 'Boss',    'address' => 'boss@client.example'],
            ['kind' => 'bcc', 'name' => 'Hidden',  'address' => 'hidden@client.example'],
        ]);
        $task = $this->task([
            'category' => 'support', 'source' => 'support',
            'generated_from_type' => Ticket::class, 'generated_from_id' => $ticket->id,
        ]);

        $suggestions = collect($this->getJson($this->url($task->id))->assertOk()->json('suggestions'));

        // The to and cc lines are offered; the bcc is not.
        $this->assertContains('support@alpha.example', $suggestions->pluck('address')->all());
        $this->assertContains('boss@client.example', $suggestions->pluck('address')->all());
        $this->assertNotContains('hidden@client.example', $suggestions->pluck('address')->all());
        $this->assertSame('Copied', $suggestions->firstWhere('address', 'boss@client.example')['group']);
    }

    // ── Scheduling routes the record to the right home ───────────────────────

    public function test_a_project_task_meeting_surfaces_on_the_project(): void
    {
        $this->actor([Permission::TASKS_VIEW, Permission::TASKS_UPDATE]);
        $this->fakeGraphCreate();
        $project = Project::create(['organization_id' => $this->org->id, 'name' => 'SD-WAN']);
        $task = $this->task(['project_id' => $project->id]);

        $this->postJson($this->url($task->id), $this->draft())->assertCreated();

        // One project meeting, carrying the task link — the very same row the
        // project's Correspondence lists.
        $row = ProjectMeeting::where('project_id', $project->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($task->id, $row->task_id);
        $this->assertSame('EVENT-1', $row->graph_event_id);

        // And it comes back on the task's own tab.
        $this->getJson($this->url($task->id))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_support_task_meeting_surfaces_on_the_ticket(): void
    {
        $this->actor([Permission::TASKS_UPDATE]);
        $this->fakeGraphCreate();
        $ticket = $this->ticket();
        $task = $this->task([
            'category' => 'support', 'source' => 'support',
            'generated_from_type' => Ticket::class, 'generated_from_id' => $ticket->id,
        ]);

        $this->postJson($this->url($task->id), $this->draft())->assertCreated();

        $row = TicketMeeting::where('support_ticket_id', $ticket->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($task->id, $row->task_id);
        $this->assertSame(1, $ticket->meetings()->count());
    }

    public function test_a_standalone_task_meeting_lands_in_task_meetings(): void
    {
        $this->actor([Permission::TASKS_UPDATE]);
        $this->fakeGraphCreate();
        $task = $this->task();

        $this->postJson($this->url($task->id), $this->draft())->assertCreated();

        $this->assertSame(1, TaskMeeting::where('task_id', $task->id)->count());
        $this->assertSame(0, ProjectMeeting::count());
        $this->assertSame(0, TicketMeeting::count());
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    public function test_scheduling_requires_at_least_one_attendee(): void
    {
        $this->actor([Permission::TASKS_UPDATE]);
        $task = $this->task();

        $this->postJson($this->url($task->id), ['subject' => 'X', 'scheduled_at' => now()->addDay(), 'duration_minutes' => 30, 'attendees' => []])
            ->assertStatus(422);
    }

    public function test_scheduling_needs_the_update_permission(): void
    {
        $this->actor([Permission::TASKS_VIEW]);
        $task = $this->task();

        $this->postJson($this->url($task->id), $this->draft())->assertForbidden();
    }
}
