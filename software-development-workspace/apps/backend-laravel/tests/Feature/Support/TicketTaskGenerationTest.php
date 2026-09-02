<?php

namespace Tests\Feature\Support;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Support\Team;
use App\Models\Support\Ticket;
use App\Models\Support\TicketStatus;
use App\Models\Task\Task;
use App\Models\User;
use App\Services\Support\SupportDefaults;
use App\Services\Support\TicketService;
use App\Services\Support\TicketTaskSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A ticket carries a task — raised automatically, routed as the ticket is, and
 * completed when the ticket resolves.
 */
class TicketTaskGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        app(SupportDefaults::class)->ensureFor($this->org->id);
    }

    private function open(array $data = []): Ticket
    {
        return app(TicketService::class)->open($this->org->id, array_merge([
            'subject'         => 'Internet down at the branch',
            'body'            => 'Nothing since this morning.',
            'requester_name'  => 'Ann Otieno',
            'requester_email' => 'ann@client.co.ke',
            'source'          => 'email',
        ], $data));
    }

    private function taskFor(Ticket $ticket): ?Task
    {
        return Task::where('generated_from_type', Ticket::class)
            ->where('generated_from_id', (string) $ticket->id)->first();
    }

    public function test_opening_a_ticket_raises_a_routed_support_task(): void
    {
        $ticket = $this->open();

        $task = $this->taskFor($ticket);
        $this->assertNotNull($task);
        $this->assertSame('support', $task->category);
        $this->assertSame('support', $task->source);
        $this->assertStringContainsString($ticket->reference, $task->title);
        $this->assertNotSame(Task::DONE, $task->status);
    }

    public function test_only_one_task_exists_per_ticket(): void
    {
        $ticket = $this->open();

        // Re-sync a few times — still one task.
        app(TicketTaskSync::class)->sync($ticket);
        app(TicketTaskSync::class)->sync($ticket);

        $this->assertSame(1, Task::where('generated_from_type', Ticket::class)
            ->where('generated_from_id', (string) $ticket->id)->count());
    }

    public function test_the_task_routes_to_the_tickets_team_and_assignee(): void
    {
        $ticket = $this->open();

        $team = Team::create(['organization_id' => $this->org->id, 'name' => 'NetSec', 'slug' => 'netsec']);
        $user = User::factory()->create();
        $employee = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'Sam', 'last_name' => 'Eng',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01', 'user_id' => $user->id,
        ]);

        $ticket->update(['assigned_to' => $user->id, 'support_team_id' => $team->id]);
        app(TicketTaskSync::class)->sync($ticket->fresh());

        $task = $this->taskFor($ticket)->fresh();
        $this->assertSame($employee->id, $task->assigned_to);
        $this->assertSame($team->id, $task->support_team_id);
    }

    public function test_resolving_a_ticket_completes_its_task_and_reopening_revives_it(): void
    {
        $ticket = $this->open();
        $resolved = TicketStatus::where('organization_id', $this->org->id)->where('state', 'resolved')->firstOrFail();
        $open     = TicketStatus::where('organization_id', $this->org->id)->where('state', 'open')->firstOrFail();

        app(TicketService::class)->changeStatus($ticket, $resolved);
        $this->assertSame(Task::DONE, $this->taskFor($ticket)->fresh()->status);

        app(TicketService::class)->changeStatus($ticket->fresh(), $open);
        $this->assertNotSame(Task::DONE, $this->taskFor($ticket)->fresh()->status);
    }
}
