<?php

namespace Tests\Feature\Travel;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Travel\TravelExpenseCategory;
use App\Models\Travel\TravelRequest;
use App\Models\Travel\TravelRequestItem;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Closing a trip.
 *
 * The property these tests hold is that the system asks the right person for
 * the right thing. The legacy reconciliation demanded a receipt for everything
 * from the traveller and held zero rows across 102 requests — half the
 * documents were never theirs to produce, so nobody bothered.
 */
class TravelReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Employee $traveller;
    private TravelRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->traveller = $this->employee();

        $this->request = TravelRequest::create([
            'organization_id' => $this->org->id,
            'employee_id'     => $this->traveller->id,
            'departure_date'  => '2026-09-01',
            'return_date'     => '2026-09-03',
            'days'            => 3,
            'status'          => 'approved',
        ]);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name'              => 'Test', 'last_name' => 'Person',
            'employment_type'         => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function category(string $type, string $provider): TravelExpenseCategory
    {
        return TravelExpenseCategory::create([
            'organization_id'   => $this->org->id,
            'name'              => ucfirst($type) . '-' . $provider . fake()->unique()->numberBetween(1, 9999),
            'evidence_type'     => $type,
            'evidence_provider' => $provider,
        ]);
    }

    private function item(TravelExpenseCategory $category, float $amount = 5000): TravelRequestItem
    {
        return $this->request->items()->create([
            'travel_expense_category_id' => $category->id,
            'amount_claimed'             => $amount,
            'unit'                       => 'per_trip',
            'quantity'                   => 1,
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
        return "/api/v1/travel-requests/{$this->request->id}{$path}?org={$this->org->slug}";
    }

    // ── Who supplies what ─────────────────────────────────────────────────

    public function test_the_traveller_uploads_a_receipt_they_hold(): void
    {
        // An Uber fare: the person who took the ride has the receipt.
        $item = $this->item($this->category('receipt', 'requestor'));
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);

        $this->post($this->url("/items/{$item->id}/evidence"), [
            'kind'        => 'receipt',
            'explanation' => 'Uber from the airport to the client site',
            'amount'      => 2400,
            'receipt'     => UploadedFile::fake()->create('uber.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $this->assertEquals(2400, (float) $item->fresh()->actual_cost, 'The actual spend comes from the evidence.');
    }

    public function test_the_traveller_cannot_supply_evidence_that_is_finances_to_give(): void
    {
        // An airline invoice the traveller never sees. Asking them for it is
        // what leaves requests open indefinitely.
        $item = $this->item($this->category('receipt', 'finance'));
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);

        $this->post($this->url("/items/{$item->id}/evidence"), [
            'kind'        => 'receipt',
            'explanation' => 'Ticket',
            'receipt'     => UploadedFile::fake()->create('ticket.pdf', 20, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('item');
    }

    public function test_finance_supplies_the_evidence_it_holds(): void
    {
        $item = $this->item($this->category('receipt', 'finance'));
        $this->actingAs_([Permission::TRAVEL_VIEW, Permission::TRAVEL_ASSESS_FINANCE]);

        $this->post($this->url("/items/{$item->id}/evidence"), [
            'kind'        => 'receipt',
            'explanation' => 'Kenya Airways e-ticket, booked centrally',
            'amount'      => 28000,
            'receipt'     => UploadedFile::fake()->create('eticket.pdf', 30, 'application/pdf'),
        ])->assertOk();

        $this->assertEquals(28000, (float) $item->fresh()->actual_cost);
    }

    public function test_a_per_diem_is_closed_by_acknowledgement_rather_than_a_receipt(): void
    {
        // There is nothing to produce a document for, but an allowance nobody
        // confirms receiving is one nobody can show was paid.
        $item = $this->item($this->category('acknowledgement', 'requestor'), 4500);
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);

        $this->postJson($this->url("/items/{$item->id}/evidence"), [
            'kind'        => 'acknowledgement',
            'explanation' => 'Received the full per diem for three days',
        ])->assertOk();

        $this->assertEquals(4500, (float) $item->fresh()->actual_cost);
        $this->assertFalse($item->fresh()->awaitsEvidence());
    }

    public function test_a_category_needing_nothing_is_settled_on_approval(): void
    {
        $item = $this->item($this->category('none', 'requestor'));

        $this->assertFalse($item->awaitsEvidence(), 'Nothing is outstanding.');

        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);
        $this->postJson($this->url("/items/{$item->id}/evidence"), [
            'kind' => 'acknowledgement', 'explanation' => 'Not needed',
        ])->assertStatus(422);
    }

    // ── Explanation is not optional ───────────────────────────────────────

    public function test_a_receipt_without_an_explanation_is_refused(): void
    {
        // A receipt nobody explained cannot be checked without asking the
        // person who uploaded it, which defeats the point.
        $item = $this->item($this->category('receipt', 'requestor'));
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);

        $this->post($this->url("/items/{$item->id}/evidence"), [
            'kind'    => 'receipt',
            'receipt' => UploadedFile::fake()->create('r.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('explanation');
    }

    // ── Closing ───────────────────────────────────────────────────────────

    public function test_a_request_cannot_be_closed_while_evidence_is_outstanding(): void
    {
        // A closed request is the record that the money was accounted for.
        // Closing one that is not makes the status worthless everywhere.
        $this->item($this->category('receipt', 'requestor'));
        $this->actingAs_([Permission::TRAVEL_VIEW, Permission::TRAVEL_ASSESS_FINANCE]);

        $this->postJson($this->url('/close'))
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_finance_closes_once_everything_is_in(): void
    {
        $item = $this->item($this->category('acknowledgement', 'either'));

        $this->actingAs_([Permission::TRAVEL_VIEW, Permission::TRAVEL_ASSESS_FINANCE]);
        $this->postJson($this->url("/items/{$item->id}/evidence"), [
            'kind' => 'acknowledgement', 'explanation' => 'Confirmed',
        ])->assertOk();

        $this->postJson($this->url('/close'))->assertOk();

        $this->assertSame('closed', $this->request->fresh()->status);
        $this->assertNotNull($this->request->fresh()->reconciled_at);
    }

    public function test_the_traveller_cannot_close_their_own_trip(): void
    {
        // Closing states that the money was accounted for; the person who
        // spent it should not be the one certifying that.
        $item = $this->item($this->category('acknowledgement', 'requestor'));

        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);
        $this->postJson($this->url("/items/{$item->id}/evidence"), [
            'kind' => 'acknowledgement', 'explanation' => 'Received',
        ])->assertOk();

        $this->postJson($this->url('/close'))->assertForbidden();
    }

    public function test_a_request_still_in_approval_cannot_be_reconciled(): void
    {
        $this->request->update(['status' => 'in_approval']);
        $item = $this->item($this->category('acknowledgement', 'requestor'));

        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);

        $this->postJson($this->url("/items/{$item->id}/evidence"), [
            'kind' => 'acknowledgement', 'explanation' => 'Too early',
        ])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_outstanding_names_the_lines_and_who_owes_them(): void
    {
        // "3 items outstanding" tells nobody whether they are the one holding
        // it up.
        $this->item($this->category('receipt', 'finance'));
        $this->actingAs_([Permission::TRAVEL_VIEW, Permission::TRAVEL_ASSESS_FINANCE]);

        $res = $this->getJson($this->url('/outstanding'))->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('finance', $res->json('data.0.from'));
        $this->assertSame('receipt', $res->json('data.0.needs'));
    }

    public function test_a_receipt_is_not_publicly_reachable(): void
    {
        Storage::fake('travel_evidence');

        $item = $this->item($this->category('receipt', 'requestor'));
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);

        $res = $this->post($this->url("/items/{$item->id}/evidence"), [
            'kind'        => 'receipt',
            'explanation' => 'Taxi fare',
            'receipt'     => UploadedFile::fake()->create('taxi.pdf', 10, 'application/pdf'),
        ])->assertOk();

        $evidenceId = $res->json('data.items.0.evidence.0.id');

        // Served through the application, which applies the same view policy
        // as the request itself.
        $this->getJson($this->url("/evidence/{$evidenceId}/download"))->assertOk();

        // A colleague in the same organisation, with no claim on this trip.
        // Someone outside the organisation never reaches the policy at all —
        // org resolution rejects them first — so this is the case that proves
        // the receipt is protected rather than merely unreachable.
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN]);
        $this->getJson($this->url("/evidence/{$evidenceId}/download"))->assertForbidden();
    }
}
