<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Getting back into an account nobody can sign into.
 *
 * Until now there was no way: the controllers existed, no route reached them,
 * and the only remedy was somebody with database access rewriting a hash.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->postJson('/api/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->postJson('/api/reset-password', [
                'token'                 => $notification->token,
                'email'                 => $user->email,
                'password'              => 'new-password-9!',
                'password_confirmation' => 'new-password-9!',
            ])->assertOk();

            return true;
        });

        $this->assertTrue(Hash::check('new-password-9!', $user->fresh()->password));
    }

    /**
     * The emailed link has to survive the trip.
     *
     * The SPA runs on hash history, so a URL without `#` never reaches the
     * router — the person lands on the login screen with the token silently
     * dropped and no idea why. The builder had no `#` and had never been
     * exercised, because nothing routed the endpoint that sends it.
     */
    public function test_the_reset_link_points_at_a_route_the_spa_can_read(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'someone@kns.co.ke']);
        $this->postJson('/api/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            $this->assertStringContainsString('/#/reset-password/', $url);
            $this->assertStringContainsString('email=someone%40kns.co.ke', $url);

            return true;
        });
    }

    /**
     * Resetting kills every session opened with the old password.
     *
     * Somebody resetting because a token was stolen has not fixed anything if
     * the stolen token still works — and these last a fortnight.
     */
    public function test_resetting_revokes_existing_tokens(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $user->createToken('laptop');
        $this->assertSame(1, $user->tokens()->count());

        $this->postJson('/api/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->postJson('/api/reset-password', [
                'token'                 => $notification->token,
                'email'                 => $user->email,
                'password'              => 'new-password-9!',
                'password_confirmation' => 'new-password-9!',
            ])->assertOk();

            return true;
        });

        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * An unknown address gets the same reply as a known one.
     *
     * Otherwise this endpoint answers "does this person work here?" for anyone
     * who asks, which for a staff directory is worth something to whoever is
     * asking.
     */
    public function test_an_unknown_address_is_indistinguishable_from_a_known_one(): void
    {
        Notification::fake();

        $user  = User::factory()->create();
        $known = $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        $this->assertSame(
            $known->json('status'),
            $this->postJson('/api/forgot-password', ['email' => 'nobody@kns.co.ke'])
                ->assertOk()
                ->json('status'),
        );

        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    /** A leaver keeps their row for attribution; they do not keep a way back in. */
    public function test_a_disabled_account_gets_no_link(): void
    {
        Notification::fake();

        $user = User::factory()->create(['account_status' => User::STATUS_DISABLED]);

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertNothingSent();
    }

    /**
     * Somebody who signs in through Azure has no local password to reset, and
     * issuing one would create a second way in that skips the tenant's policy.
     */
    public function test_an_sso_only_account_gets_no_link(): void
    {
        Notification::fake();

        $user = User::factory()->create(['sso_only' => true]);

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertNothingSent();
    }
}
