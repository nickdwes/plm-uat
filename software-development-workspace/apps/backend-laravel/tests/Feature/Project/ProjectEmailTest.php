<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
use App\Models\Integration\IntegrationConnection;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pulling a project's shared mailbox into its correspondence. Graph is faked;
 * what is proved is the ingestion — messages stored, de-duplicated on the Graph
 * id, guarded when there is no mailbox, and gated by permission.
 */
class ProjectEmailTest extends TestCase
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
            'tenant_id' => 'tenant-1', 'client_id' => 'client-1', 'client_secret' => 'secret-1', 'is_active' => true,
        ]);
        $this->org->update(['calendar_connection_id' => $conn->id]);

        $this->project = Project::create([
            'organization_id' => $this->org->id, 'name' => 'SD-WAN', 'project_email' => 'sdwan@alpha.example',
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

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/emails{$path}?org={$this->org->slug}";
    }

    private function fakeInbox(array $messages): void
    {
        Http::fake([
            'login.microsoftonline.com/*'  => Http::response(['access_token' => 'tok'], 200),
            '*/mailFolders/inbox/messages*' => Http::response(['value' => $messages], 200),
        ]);
    }

    private function message(string $id, string $subject, string $received = '2026-08-20T09:00:00Z'): array
    {
        return [
            'id' => $id, 'internetMessageId' => "<{$id}@mail>", 'subject' => $subject,
            'bodyPreview' => 'Preview of ' . $subject, 'body' => ['contentType' => 'HTML', 'content' => "<p>{$subject}</p>"],
            'from' => ['emailAddress' => ['name' => 'KCB Delivery', 'address' => 'delivery@kcb.example']],
            'toRecipients' => [['emailAddress' => ['name' => 'SD-WAN', 'address' => 'sdwan@alpha.example']]],
            'ccRecipients' => [], 'receivedDateTime' => $received,
            'hasAttachments' => false, 'internetMessageHeaders' => [],
        ];
    }

    public function test_it_pulls_and_stores_inbound_email(): void
    {
        $this->editor();
        $this->fakeInbox([
            $this->message('AAA', 'Circuit delivery update', '2026-08-21T09:00:00Z'),
            $this->message('BBB', 'Site survey', '2026-08-20T09:00:00Z'),
        ]);

        $res = $this->postJson($this->url('/sync'))->assertOk();

        $this->assertSame(2, $res->json('new'));
        $this->assertSame(2, $res->json('fetched'));
        $this->assertCount(2, $res->json('data'));
        $this->assertSame('Circuit delivery update', $res->json('data.0.subject')); // newest first
        $this->assertSame('delivery@kcb.example', $res->json('data.0.from_email'));
    }

    public function test_a_second_pull_only_adds_new_messages(): void
    {
        $this->editor();

        // One fake, two responses: first pull sees AAA, second sees AAA + CCC.
        Http::fake([
            'login.microsoftonline.com/*'  => Http::response(['access_token' => 'tok'], 200),
            '*/mailFolders/inbox/messages*' => Http::sequence()
                ->push(['value' => [$this->message('AAA', 'First')]])
                ->push(['value' => [$this->message('AAA', 'First'), $this->message('CCC', 'Second')]]),
        ]);

        $this->postJson($this->url('/sync'))->assertOk()->assertJsonPath('new', 1);

        $res = $this->postJson($this->url('/sync'))->assertOk();
        $this->assertSame(1, $res->json('new'));  // only CCC is new
        $this->assertCount(2, $res->json('data'));
    }

    public function test_a_project_without_a_mailbox_is_told_to_set_one(): void
    {
        $this->editor();
        $this->project->update(['project_email' => null]);

        $this->postJson($this->url('/sync'))->assertStatus(422)
            ->assertJsonFragment(['message' => 'Set the project mailbox first, then fetch its email.']);
    }

    public function test_fetching_needs_the_update_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'V', 'scope' => 'organization', 'permissions' => [Permission::PROJECTS_VIEW],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        $this->postJson($this->url('/sync'))->assertForbidden();
    }
}
