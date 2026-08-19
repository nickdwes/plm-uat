<?php

namespace Tests\Feature\Support;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Support\Calendar;
use App\Models\Support\Sla;
use App\Models\Support\SlaTarget;
use App\Models\Support\TicketPriority;
use App\Models\User;
use App\Services\Support\SupportDefaults;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Configuring service levels.
 *
 * This is the screen that silently changes what every open ticket is judged by,
 * so it is gated apart from answering tickets and it refuses the two edits that
 * would quietly break measurement.
 */
class SlaConfigTest extends TestCase
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

    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Desk', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function headers(): array
    {
        return ['X-Organization' => 'alpha'];
    }

    public function test_the_screen_creates_a_default_calendar_on_first_view(): void
    {
        $this->signIn([Permission::SUPPORT_MANAGE_CONFIG]);

        $this->getJson('/api/v1/support/sla', $this->headers())
            ->assertOk()
            ->assertJsonPath('calendars.0.is_default', true)
            // Eight to five, Monday to Friday, as adopted from the plan.
            ->assertJsonCount(5, 'calendars.0.windows');

        $this->assertSame(1, Calendar::count());
    }

    /** Configuring service levels is not the same job as answering a ticket. */
    public function test_an_agent_without_the_config_permission_is_refused(): void
    {
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        $this->getJson('/api/v1/support/sla', $this->headers())->assertForbidden();
    }

    public function test_a_target_can_be_set_per_priority(): void
    {
        $this->signIn([Permission::SUPPORT_MANAGE_CONFIG]);

        $sla = Sla::create([
            'organization_id' => $this->org->id, 'name' => 'Standard', 'is_default' => true,
        ]);
        $critical = TicketPriority::where('name', 'Critical')->firstOrFail();

        $this->postJson("/api/v1/support/sla/policies/{$sla->id}/targets", [
            'priority_id' => $critical->id,
            'response_minutes' => 60, 'resolution_minutes' => 480,
            'hod_escalation_minutes' => 240,
        ], $this->headers())->assertCreated();

        $this->assertSame(60, SlaTarget::firstOrFail()->response_minutes);
    }

    /**
     * A resolution target inside the response target would breach resolution
     * before anybody had answered — almost always a slip, and silent if
     * accepted.
     */
    public function test_a_resolution_target_shorter_than_the_response_is_refused(): void
    {
        $this->signIn([Permission::SUPPORT_MANAGE_CONFIG]);

        $sla = Sla::create(['organization_id' => $this->org->id, 'name' => 'Standard']);

        $this->postJson("/api/v1/support/sla/policies/{$sla->id}/targets", [
            'priority_id' => TicketPriority::where('name', 'High')->firstOrFail()->id,
            'response_minutes' => 480, 'resolution_minutes' => 60,
        ], $this->headers())->assertStatus(422);

        $this->assertSame(0, SlaTarget::count());
    }

    /**
     * Two fallbacks is a coin toss over what every uncontracted ticket is
     * promised.
     */
    public function test_only_one_sla_can_be_the_fallback(): void
    {
        $this->signIn([Permission::SUPPORT_MANAGE_CONFIG]);

        $this->postJson('/api/v1/support/sla/policies',
            ['name' => 'First', 'is_default' => true], $this->headers())->assertCreated();
        $this->postJson('/api/v1/support/sla/policies',
            ['name' => 'Second', 'is_default' => true], $this->headers())->assertCreated();

        $this->assertSame(1, Sla::where('is_default', true)->count());
        $this->assertSame('Second', Sla::where('is_default', true)->first()->name);
    }

    /** A calendar edited a day at a time is half-applied while tickets run. */
    public function test_a_calendars_week_is_replaced_in_one_write(): void
    {
        $this->signIn([Permission::SUPPORT_MANAGE_CONFIG]);

        $calendar = app(SupportDefaults::class)->defaultCalendar($this->org->id);

        $this->putJson("/api/v1/support/sla/calendars/{$calendar->id}/windows", [
            'windows' => [
                ['weekday' => 1, 'opens_at' => '09:00', 'closes_at' => '18:00'],
                ['weekday' => 6, 'opens_at' => '09:00', 'closes_at' => '13:00'],
            ],
        ], $this->headers())->assertOk();

        $fresh = Calendar::findOrFail($calendar->id);

        $this->assertSame(2, $fresh->windows()->count());
        // Saturday morning is now working time, and it counts.
        $this->assertSame(240, $fresh->workingMinutesBetween(
            \Carbon\Carbon::parse('2026-08-22 00:00', 'Africa/Nairobi'),
            \Carbon\Carbon::parse('2026-08-22 23:59', 'Africa/Nairobi'),
        ));
    }

    /** Moveable feasts are added by hand, so adding one has to work. */
    public function test_a_holiday_can_be_added_and_removed(): void
    {
        $this->signIn([Permission::SUPPORT_MANAGE_CONFIG]);

        $calendar = app(SupportDefaults::class)->defaultCalendar($this->org->id);

        $created = $this->postJson("/api/v1/support/sla/calendars/{$calendar->id}/holidays", [
            'observed_on' => '2027-04-02', 'name' => 'Good Friday',
        ], $this->headers())->assertCreated()->json('data.id');

        $this->assertSame(0, Calendar::findOrFail($calendar->id)->workingMinutesBetween(
            \Carbon\Carbon::parse('2027-04-02 00:00', 'Africa/Nairobi'),
            \Carbon\Carbon::parse('2027-04-02 23:59', 'Africa/Nairobi'),
        ));

        $this->deleteJson("/api/v1/support/sla/calendars/{$calendar->id}/holidays/{$created}",
            [], $this->headers())->assertOk();
    }
}
