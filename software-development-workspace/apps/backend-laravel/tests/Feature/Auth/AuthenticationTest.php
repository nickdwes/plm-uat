<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signing in and out.
 *
 * These posted to `/login` and `/logout` — the paths in the scaffolded
 * `routes/auth.php`, which was never registered in `bootstrap/app.php` and so
 * defined nothing. The real endpoints have always been under the api prefix.
 * The failures were reporting a genuine hole and got read as scaffolding noise.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user', 'organizations']);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/logout')
            ->assertNoContent();
    }
}
