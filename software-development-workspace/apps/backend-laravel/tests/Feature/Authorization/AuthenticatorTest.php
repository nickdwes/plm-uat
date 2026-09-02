<?php

namespace Tests\Feature\Authorization;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Identity\Authenticator;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use OTPHP\TOTP;
use Tests\TestCase;

/**
 * The local second factor.
 *
 * The algorithm is `spomky-labs/otphp` and is not retested here. What is
 * tested is everything around it, which is where authentication bugs actually
 * live: that an unconfirmed secret authenticates nobody, that a code cannot be
 * spent twice, that guessing is throttled, that recovery codes are hashed and
 * consumed, and that a password on its own still gets an attacker nothing.
 */
class AuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization',
            'permissions' => [Permission::ROLES_VIEW, Permission::ROLES_ASSIGN],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    /** The code an app would show right now for this secret. */
    private function currentCode(User $user): string
    {
        $totp = TOTP::createFromSecret($user->fresh()->totp_secret);
        $totp->setPeriod(30);
        $totp->setDigits(6);
        $totp->setDigest('sha1');

        return $totp->now();
    }

    private function url(User $u, string $path = ''): string
    {
        return "/api/v1/authorization/users/{$u->id}{$path}?org={$this->org->slug}";
    }

    // ── Enrolment ─────────────────────────────────────────────────────────

    public function test_enrolment_returns_a_secret_and_a_scannable_uri(): void
    {
        $this->admin();
        $person = User::factory()->create(['alt_username' => 'j.otieno']);

        $res = $this->postJson($this->url($person, '/authenticator'))->assertOk();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $res->json('data.secret'));
        $this->assertStringStartsWith('otpauth://totp/', $res->json('data.uri'));
        $this->assertStringContainsString('issuer=KNS', $res->json('data.uri'));
        $this->assertStringContainsString($res->json('data.secret'), $res->json('data.uri'));
        // The label is the local username, which is what the app will show.
        $this->assertStringContainsString('j.otieno', urldecode($res->json('data.uri')));
    }

    public function test_a_secret_that_was_never_confirmed_authenticates_nobody(): void
    {
        // A secret exists from the moment the QR is drawn. An enrolment
        // somebody abandoned half-way must not leave the account reachable.
        $this->admin();
        $person = User::factory()->create();

        $this->postJson($this->url($person, '/authenticator'))->assertOk();
        $person->refresh();

        $this->assertFalse($person->hasConfirmedAuthenticator());
        $this->assertFalse(app(Authenticator::class)->verify($person, $this->currentCode($person)));
    }

    public function test_confirming_needs_a_code_that_actually_matches(): void
    {
        $this->admin();
        $person = User::factory()->create();
        $this->postJson($this->url($person, '/authenticator'))->assertOk();

        $this->postJson($this->url($person, '/authenticator/confirm'), ['code' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertNull($person->fresh()->totp_confirmed_at);
    }

    public function test_confirming_turns_it_on_and_hands_over_recovery_codes_once(): void
    {
        $this->admin();
        $person = User::factory()->create();
        $this->postJson($this->url($person, '/authenticator'))->assertOk();

        $res = $this->postJson($this->url($person, '/authenticator/confirm'), [
            'code' => $this->currentCode($person),
        ])->assertOk();

        $codes = $res->json('data.recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertTrue($person->fresh()->hasConfirmedAuthenticator());

        // Hashed at rest: this response is the only time anybody, us included,
        // can read them.
        foreach ($person->fresh()->recovery_codes as $stored) {
            $this->assertStringStartsNotWith($codes[0], $stored);
        }
        $this->assertTrue(Hash::check($codes[0], $person->fresh()->recovery_codes[0]));
    }

    public function test_the_secret_is_never_returned_again(): void
    {
        // Issued once. A secret that can be fetched twice is one that can be
        // fetched by the wrong person twice.
        $this->admin();
        $person = User::factory()->create();
        $this->postJson($this->url($person, '/authenticator'))->assertOk();

        $row = collect($this->getJson("/api/v1/authorization/users?org={$this->org->slug}")->assertOk()->json('data'))
            ->firstWhere('id', $person->id);

        $this->assertArrayNotHasKey('totp_secret', $row);
        $this->assertArrayNotHasKey('recovery_codes', $row);
    }

    public function test_re_issuing_to_somebody_enrolled_has_to_be_meant(): void
    {
        $this->admin();
        $person = User::factory()->create();
        $this->postJson($this->url($person, '/authenticator'))->assertOk();
        $this->postJson($this->url($person, '/authenticator/confirm'), ['code' => $this->currentCode($person)])->assertOk();

        $this->postJson($this->url($person, '/authenticator'))
            ->assertStatus(422)->assertJsonValidationErrors('confirm_reissue');

        $this->postJson($this->url($person, '/authenticator'), ['confirm_reissue' => true])->assertOk();

        // The old app and the old recovery codes are gone with the old secret.
        $this->assertNull($person->fresh()->totp_confirmed_at);
        $this->assertNull($person->fresh()->recovery_codes);
    }

    // ── Verification ──────────────────────────────────────────────────────

    private function enrolled(): User
    {
        $person = User::factory()->create([
            'auth_method' => User::AUTH_LOCAL_TOTP,
            'password'    => Hash::make('correct-horse-battery'),
        ]);
        app(Authenticator::class)->beginEnrollment($person);
        app(Authenticator::class)->confirmEnrollment($person->fresh(), $this->currentCode($person));

        return $person->fresh();
    }

    public function test_a_code_cannot_be_spent_twice(): void
    {
        // TOTP on its own is not single-use: the same digits stay valid for
        // the whole step, and with the window for up to ninety seconds.
        $person = $this->enrolled();

        // Past the step the confirming code was spent in.
        $this->travel(31)->seconds();
        $code = $this->currentCode($person);

        $this->assertTrue(app(Authenticator::class)->verify($person->fresh(), $code));
        $this->assertFalse(app(Authenticator::class)->verify($person->fresh(), $code));
    }

    public function test_guessing_is_locked_out_after_five_wrong_codes(): void
    {
        $person = $this->enrolled();
        $auth = app(Authenticator::class);

        foreach (range(1, 5) as $i) {
            $auth->verify($person->fresh(), '000000');
        }

        $this->expectExceptionMessageMatches('/Too many wrong codes/');
        $auth->verify($person->fresh(), $this->currentCode($person));
    }

    public function test_a_recovery_code_works_once_and_then_is_gone(): void
    {
        $person = User::factory()->create(['auth_method' => User::AUTH_LOCAL_TOTP]);
        $auth = app(Authenticator::class);
        $auth->beginEnrollment($person);
        $codes = $auth->confirmEnrollment($person->fresh(), $this->currentCode($person));

        $this->assertTrue($auth->useRecoveryCode($person->fresh(), $codes[0]));
        $this->assertCount(7, $person->fresh()->recovery_codes);

        // A code that still worked afterwards would be a password on paper.
        $this->assertFalse($auth->useRecoveryCode($person->fresh(), $codes[0]));
    }

    // ── Signing in ────────────────────────────────────────────────────────

    public function test_a_password_alone_gets_an_enrolled_user_nothing(): void
    {
        $person = $this->enrolled();

        $res = $this->postJson('/api/login', [
            'email' => $person->email, 'password' => 'correct-horse-battery',
        ])->assertOk();

        $this->assertTrue($res->json('mfa_required'));
        // No token. A stolen password buys an attacker exactly nothing.
        $this->assertNull($res->json('token'));
    }

    public function test_the_code_completes_the_sign_in(): void
    {
        $person = $this->enrolled();
        $this->travel(31)->seconds();

        $challenge = $this->postJson('/api/login', [
            'email' => $person->email, 'password' => 'correct-horse-battery',
        ])->assertOk()->json('challenge');

        $res = $this->postJson('/api/login/verify-otp', [
            'challenge' => $challenge, 'code' => $this->currentCode($person),
        ])->assertOk();

        $this->assertNotNull($res->json('token'));
    }

    public function test_a_challenge_is_spent_even_when_the_code_is_wrong(): void
    {
        // Otherwise one password buys five minutes of unlimited guesses
        // against a six-digit number.
        $person = $this->enrolled();

        $challenge = $this->postJson('/api/login', [
            'email' => $person->email, 'password' => 'correct-horse-battery',
        ])->assertOk()->json('challenge');

        $this->postJson('/api/login/verify-otp', ['challenge' => $challenge, 'code' => '000000'])
            ->assertStatus(422);

        $this->travel(31)->seconds();

        $this->postJson('/api/login/verify-otp', [
            'challenge' => $challenge, 'code' => $this->currentCode($person),
        ])->assertStatus(422)->assertJsonValidationErrors('challenge');
    }

    public function test_somebody_who_has_not_enrolled_signs_in_as_before(): void
    {
        // The gate is a confirmed enrolment, not the method. Requiring a code
        // from somebody who never scanned a QR locks them out of their own
        // account — and on this tenant that would have been everybody.
        $person = User::factory()->create([
            'auth_method' => User::AUTH_LOCAL_TOTP,
            'password'    => Hash::make('correct-horse-battery'),
        ]);

        $res = $this->postJson('/api/login', [
            'email' => $person->email, 'password' => 'correct-horse-battery',
        ])->assertOk();

        $this->assertNotNull($res->json('token'));
    }
}
