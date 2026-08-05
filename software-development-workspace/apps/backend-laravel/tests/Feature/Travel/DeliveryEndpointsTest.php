<?php

namespace Tests\Feature\Travel;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Travel\TravelRequest;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The delivery HTTP surface: manifest, couriers and the delivery note.
 *
 * These endpoints shipped without tests, and three bugs in the surrounding
 * screens were found by clicking rather than by testing. What is fixed here is
 * the set of rules that are invisible in a response body: who may sign a line
 * off, whether a consignment can be carried by somebody from another company,
 * and whether "delivered" can be claimed without the note that proves it.
 */
class DeliveryEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Employee $requester;
    private TravelRequest $delivery;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('travel_evidence');

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->requester = $this->employee();

        $this->delivery = TravelRequest::create([
            'organization_id' => $this->org->id,
            'employee_id'     => $this->requester->id,
            'travel_type'     => 'delivery',
            'departure_date'  => '2026-09-01',
            'return_date'     => '2026-09-01',
            'days'            => 1,
        ]);
    }

    private function employee(?Organization $org = null): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => ($org ?? $this->org)->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function actingAs_(array $permissions, ?Employee $employee = null): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = $employee ?? $this->employee();
        $employee->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function url(string $path): string
    {
        return "/api/v1/travel-requests/{$this->delivery->id}{$path}?org={$this->org->slug}";
    }

    private function addLine(array $attrs = []): int
    {
        return $this->delivery->manifest()->create($attrs + [
            'description' => 'Rack server', 'quantity' => 3, 'unit' => 'pcs',
        ])->id;
    }

    // ── Manifest ──────────────────────────────────────────────────────────

    public function test_a_manifest_line_can_be_added_while_the_request_is_a_draft(): void
    {
        $this->actingAs_([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN], $this->requester);

        $this->postJson($this->url('/manifest'), [
            'description' => 'Rack server', 'quantity' => 3, 'weight_kg' => 60,
        ])->assertOk();

        $this->assertSame(1, $this->delivery->manifest()->count());
    }

    public function test_what_was_sent_cannot_be_changed_after_approval(): void
    {
        // Editing the manifest once approved would alter what was agreed to.
        $line = $this->addLine();
        $this->delivery->update(['status' => 'approved']);

        $this->actingAs_([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN], $this->requester);

        $this->putJson($this->url("/manifest/{$line}"), ['quantity' => 99])->assertForbidden();
    }

    public function test_a_line_can_be_signed_for_after_approval(): void
    {
        // Receiving happens on arrival, when the request is no longer
        // editable — so it is gated on seeing the delivery, not editing it.
        $line = $this->addLine();
        $this->delivery->update(['status' => 'approved']);

        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->requester);

        $this->putJson($this->url("/manifest/{$line}"), [
            'quantity_received' => 3, 'condition_on_arrival' => 'good',
        ])->assertOk();
    }

    public function test_a_short_delivery_is_recorded_rather_than_rejected(): void
    {
        // Four of five arriving is the case worth capturing; refusing it would
        // just mean nobody records the shortfall at all.
        $line = $this->addLine();
        $this->delivery->update(['status' => 'approved']);

        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->requester);
        $this->putJson($this->url("/manifest/{$line}"), [
            'quantity_received' => 2, 'condition_on_arrival' => 'partial',
        ])->assertOk();

        $this->assertEquals(2, $this->delivery->manifest()->first()->quantity_received);
    }

    public function test_a_manifest_line_from_another_request_is_not_reachable(): void
    {
        $other = TravelRequest::create([
            'organization_id' => $this->org->id, 'employee_id' => $this->requester->id,
            'departure_date' => '2026-09-01', 'return_date' => '2026-09-01',
        ]);
        $foreign = $other->manifest()->create(['description' => 'Elsewhere', 'quantity' => 1]);

        $this->actingAs_([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN], $this->requester);

        $this->putJson($this->url("/manifest/{$foreign->id}"), ['quantity' => 5])->assertNotFound();
    }

    // ── Couriers ──────────────────────────────────────────────────────────

    public function test_the_first_courier_is_accountable_without_being_asked(): void
    {
        $this->actingAs_([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN], $this->requester);
        $courier = $this->employee();

        $res = $this->postJson($this->url('/couriers'), ['employee_id' => $courier->id])->assertOk();

        $this->assertTrue($res->json('data.couriers.0.is_primary'));
    }

    public function test_only_one_courier_is_accountable(): void
    {
        $this->actingAs_([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN], $this->requester);

        $this->postJson($this->url('/couriers'), ['employee_id' => $this->employee()->id])->assertOk();
        $res = $this->postJson($this->url('/couriers'), [
            'employee_id' => $this->employee()->id, 'is_primary' => true,
        ])->assertOk();

        $primaries = collect($res->json('data.couriers'))->where('is_primary', true);
        $this->assertCount(1, $primaries);
    }

    public function test_removing_the_accountable_courier_promotes_a_replacement(): void
    {
        // Couriers with nobody accountable makes "who signed for this"
        // unanswerable.
        $this->actingAs_([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN], $this->requester);

        $first = $this->postJson($this->url('/couriers'), ['employee_id' => $this->employee()->id])
            ->json('data.couriers.0.id');
        $this->postJson($this->url('/couriers'), ['employee_id' => $this->employee()->id])->assertOk();

        $res = $this->deleteJson($this->url("/couriers/{$first}"))->assertOk();

        $this->assertCount(1, collect($res->json('data.couriers'))->where('is_primary', true));
    }

    public function test_somebody_from_another_organisation_cannot_carry_the_consignment(): void
    {
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $foreign = $this->employee($beta);

        $this->actingAs_([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN], $this->requester);

        $this->postJson($this->url('/couriers'), ['employee_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    // ── Delivery note ─────────────────────────────────────────────────────

    public function test_uploading_a_delivery_note_names_it_on_the_request(): void
    {
        // The note is what a delivery closes against, so it is named rather
        // than left to be found among the attachments.
        $this->delivery->update(['status' => 'approved']);
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->requester);

        $this->post($this->url('/attachments'), [
            'document_type' => 'delivery_note',
            'file' => UploadedFile::fake()->create('note.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $this->assertNotNull($this->delivery->fresh()->delivery_note_attachment_id);
    }

    public function test_a_quotation_does_not_count_as_a_delivery_note(): void
    {
        // Without a document type, "has an attachment" would stand in for
        // "was delivered".
        $this->delivery->update(['status' => 'approved']);
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->requester);

        $this->post($this->url('/attachments'), [
            'document_type' => 'quotation',
            'file' => UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $this->assertNull($this->delivery->fresh()->delivery_note_attachment_id);
    }

    public function test_an_attachment_is_not_publicly_reachable(): void
    {
        $this->delivery->update(['status' => 'approved']);
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->requester);

        $res = $this->post($this->url('/attachments'), [
            'document_type' => 'delivery_note',
            'file' => UploadedFile::fake()->create('note.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $id = $res->json('data.attachments.0.id');
        $this->getJson($this->url("/attachments/{$id}/download"))->assertOk();

        // A colleague with no claim on this delivery.
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN]);
        $this->getJson($this->url("/attachments/{$id}/download"))->assertForbidden();
    }

    // ── Closing ───────────────────────────────────────────────────────────

    public function test_a_delivery_cannot_close_without_its_note(): void
    {
        $line = $this->addLine();
        $this->delivery->update(['status' => 'approved']);

        $this->actingAs_([Permission::TRAVEL_VIEW, Permission::TRAVEL_ASSESS_FINANCE]);
        $this->putJson($this->url("/manifest/{$line}"), ['quantity_received' => 3])->assertOk();

        $this->postJson($this->url('/close'))
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_a_delivery_cannot_close_with_a_line_unsigned(): void
    {
        $this->addLine();
        $this->delivery->update(['status' => 'approved']);

        $this->actingAs_([Permission::TRAVEL_VIEW, Permission::TRAVEL_ASSESS_FINANCE]);
        $this->post($this->url('/attachments'), [
            'document_type' => 'delivery_note',
            'file' => UploadedFile::fake()->create('note.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $this->postJson($this->url('/close'))
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_a_delivery_closes_once_signed_for_and_noted(): void
    {
        $line = $this->addLine();
        $this->delivery->update(['status' => 'approved']);

        $this->actingAs_([Permission::TRAVEL_VIEW, Permission::TRAVEL_ASSESS_FINANCE]);
        $this->putJson($this->url("/manifest/{$line}"), ['quantity_received' => 3])->assertOk();
        $this->post($this->url('/attachments'), [
            'document_type' => 'delivery_note',
            'file' => UploadedFile::fake()->create('note.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $this->postJson($this->url('/close'))->assertOk();

        $this->assertSame('closed', $this->delivery->fresh()->status);
    }

    public function test_the_gaps_endpoint_names_what_is_missing(): void
    {
        // "Not ready" tells nobody what to do about it.
        $this->addLine();
        $this->actingAs_([Permission::TRAVEL_VIEW, Permission::TRAVEL_ASSESS_FINANCE]);

        $gaps = $this->getJson($this->url('/delivery-gaps'))->assertOk()->json('data');

        $this->assertNotEmpty($gaps);
    }
}
