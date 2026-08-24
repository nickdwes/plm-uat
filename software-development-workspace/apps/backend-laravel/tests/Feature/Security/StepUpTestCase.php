<?php

namespace Tests\Feature\Security;

use App\Mail\StepUpCodeMail;
use App\Models\Organization;
use App\Models\User;
use App\Services\Security\StepUpVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Shared fixture: one user, and a way to pull the code a fake mail actually
 * carried without ever storing it anywhere the assertion could cheat from.
 */
abstract class StepUpTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        $this->user = User::factory()->create(['email' => 'viewer@kns.co.ke']);
    }

    protected function captureCode(): string
    {
        app(StepUpVerification::class)->requestCode($this->user, 'payroll', null, null);

        $code = null;
        Mail::assertSent(StepUpCodeMail::class, function ($mail) use (&$code) {
            $rendered = $mail->render();
            preg_match('/letter-spacing:\s*6px[^>]*>(\d{6})</', $rendered, $m);
            $code = $m[1] ?? null;
            return true;
        });

        $this->assertNotNull($code, 'Could not read the code back out of the rendered email.');

        return $code;
    }

    protected function wrongCode(string $real): string
    {
        $wrong = str_pad((string) ((int) $real + 1) % 1000000, 6, '0', STR_PAD_LEFT);
        return $wrong === $real ? '000001' : $wrong;
    }
}
