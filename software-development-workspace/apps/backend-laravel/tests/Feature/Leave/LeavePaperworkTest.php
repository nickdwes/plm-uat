<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\Leave\LeaveRequestAttachment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use App\Support\Storage\DocumentVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The paperwork and the conversation on a leave request.
 *
 * A sick note is a medical record and a compassionate leave attachment is
 * usually a death notice, so the rules here are about who may read them and
 * when they stop being editable — not about file handling.
 */
class LeavePaperworkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Employee $applicant;
    private Employee $approver;
    private LeaveRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('employee_documents');

        $this->org = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        app()->instance('current.organization_id', $this->org->id);

        $type = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'Sick Leave', 'code' => 'SL',
            'days_per_year' => 30, 'counting_method' => 'calendar_days',
        ]);

        $this->approver  = $this->employee('Angie', 'Mumo');
        $this->applicant = $this->employee('James', 'Kinyanjui', $this->approver->id);

        $this->request = LeaveRequest::create([
            'organization_id' => $this->org->id,
            'employee_id'     => $this->applicant->id,
            'leave_type_id'   => $type->id,
            'from_date'       => now()->toDateString(),
            'to_date'         => now()->addDays(2)->toDateString(),
            'days_requested'  => 3,
            'reason'          => 'Unwell',
            'status'          => 'pending',
        ]);
    }

    private function employee(string $first, string $last, ?int $reportsTo = null): Employee
    {
        return Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => $last,
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'status' => 'active', 'reports_to' => $reportsTo,
        ]);
    }

    private function signIn(Employee $as, array $permissions): User
    {
        $user = User::factory()->create();
        $as->update(['user_id' => $user->id]);

        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Leave', 'scope' => 'organization',
            'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function url(string $path): string
    {
        return "/api/v1/leave/{$this->request->id}{$path}?org={$this->org->slug}";
    }

    // ── Attachments ───────────────────────────────────────────────────────

    public function test_an_applicant_can_attach_a_sick_note(): void
    {
        $this->signIn($this->applicant, [Permission::LEAVE_VIEW_OWN, Permission::LEAVE_SUBMIT]);

        $res = $this->postJson($this->url('/attachments'), [
            'file' => UploadedFile::fake()->create('sick-note.pdf', 40, 'application/pdf'),
        ])->assertCreated();

        $this->assertSame('sick-note.pdf', $res->json('data.original_name'));
        $this->assertDatabaseCount('leave_request_attachments', 1);
    }

    /**
     * Storage must never hold the plaintext. Blob encryption protects against
     * a stolen disk and nothing else; this is a medical record.
     */
    public function test_the_bytes_are_encrypted_before_they_reach_storage(): void
    {
        $this->signIn($this->applicant, [Permission::LEAVE_VIEW_OWN, Permission::LEAVE_SUBMIT]);

        $this->postJson($this->url('/attachments'), [
            'file' => UploadedFile::fake()->createWithContent('note.pdf', 'PATIENT IS UNWELL'),
        ])->assertCreated();

        $stored = LeaveRequestAttachment::first();
        $raw    = Storage::disk(LeaveRequestAttachment::DISK)->get($stored->storage_path);

        $this->assertStringNotContainsString('PATIENT IS UNWELL', $raw);
        $this->assertTrue(DocumentVault::isSealed($raw));
    }

    public function test_a_download_returns_the_original_content(): void
    {
        $this->signIn($this->applicant, [Permission::LEAVE_VIEW_OWN, Permission::LEAVE_SUBMIT]);

        $this->postJson($this->url('/attachments'), [
            'file' => UploadedFile::fake()->createWithContent('note.pdf', 'PATIENT IS UNWELL'),
        ])->assertCreated();

        $id = LeaveRequestAttachment::first()->id;

        $body = $this->get($this->url("/attachments/{$id}/download"))
            ->assertOk()->streamedContent();

        $this->assertSame('PATIENT IS UNWELL', $body);
    }

    /**
     * Evidence added after the verdict looks like evidence that was weighed,
     * and nothing in the record could later tell the two apart.
     */
    public function test_nothing_can_be_attached_once_the_request_is_decided(): void
    {
        $this->signIn($this->applicant, [Permission::LEAVE_VIEW_OWN, Permission::LEAVE_SUBMIT]);

        $this->request->update(['status' => 'approved']);

        $this->postJson($this->url('/attachments'), [
            'file' => UploadedFile::fake()->create('late.pdf', 10, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_an_approver_cannot_remove_the_note_they_were_shown(): void
    {
        $this->signIn($this->applicant, [Permission::LEAVE_VIEW_OWN, Permission::LEAVE_SUBMIT]);

        $this->postJson($this->url('/attachments'), [
            'file' => UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'),
        ])->assertCreated();

        $id = LeaveRequestAttachment::first()->id;

        $this->signIn($this->approver, [Permission::LEAVE_VIEW_TEAM, Permission::LEAVE_APPROVE]);

        $this->deleteJson($this->url("/attachments/{$id}"))->assertForbidden();
        $this->assertDatabaseCount('leave_request_attachments', 1);
    }

    public function test_an_unrelated_employee_cannot_read_the_paperwork(): void
    {
        $this->signIn($this->applicant, [Permission::LEAVE_VIEW_OWN, Permission::LEAVE_SUBMIT]);

        $this->postJson($this->url('/attachments'), [
            'file' => UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'),
        ])->assertCreated();

        $stranger = $this->employee('Sara', 'Thompson');
        $this->signIn($stranger, [Permission::LEAVE_VIEW_OWN]);

        $this->getJson($this->url('/attachments'))->assertForbidden();
    }

    // ── Messages ──────────────────────────────────────────────────────────

    public function test_an_approver_can_ask_a_question_and_the_applicant_reads_it(): void
    {
        $this->signIn($this->approver, [Permission::LEAVE_VIEW_TEAM, Permission::LEAVE_APPROVE]);

        $this->postJson($this->url('/messages'), [
            'body' => 'Could you move this to the following week?',
        ])->assertCreated();

        $this->signIn($this->applicant, [Permission::LEAVE_VIEW_OWN]);

        $this->getJson($this->url('/messages'))->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * An approver discussing cover with a colleague is not writing to the
     * applicant. Without a private side the only safe thread is one nobody
     * uses candidly.
     */
    public function test_a_private_note_is_hidden_from_the_applicant(): void
    {
        $this->signIn($this->approver, [Permission::LEAVE_VIEW_TEAM, Permission::LEAVE_APPROVE]);

        $this->postJson($this->url('/messages'), [
            'body' => 'Third time this quarter — worth a word with HR.',
            'visible_to_employee' => false,
        ])->assertCreated();

        $this->getJson($this->url('/messages'))->assertOk()->assertJsonCount(1, 'data');

        $this->signIn($this->applicant, [Permission::LEAVE_VIEW_OWN]);

        $this->getJson($this->url('/messages'))->assertOk()->assertJsonCount(0, 'data');
    }

    /** A message the applicant writes to nobody would have no audience at all. */
    public function test_an_applicant_cannot_write_a_private_message(): void
    {
        $this->signIn($this->applicant, [Permission::LEAVE_VIEW_OWN]);

        $this->postJson($this->url('/messages'), [
            'body' => 'Please see the attached note.',
            'visible_to_employee' => false,
        ])->assertCreated()->assertJsonPath('data.visible_to_employee', true);
    }
}
