<?php

namespace Tests\Feature\Security;

use App\Mail\StepUpCodeMail;
use App\Models\Organization;
use App\Models\Security\SecurityStepUpCode;
use App\Models\User;
use App\Services\Security\StepUpVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Proving it is really you, right now — not just that the session cookie
 * is still valid.
 */
class StepUpVerificationTest extends StepUpTestCase
{
    public function test_requesting_a_code_emails_the_viewers_own_address(): void
    {
        Mail::fake();

        $this->service()->requestCode($this->user, 'payroll', '10.0.0.1', 'phpunit');

        Mail::assertSent(StepUpCodeMail::class, fn ($mail) => $mail->hasTo($this->user->email));
    }

    /** The code is never recoverable from the row — only comparable. */
    public function test_the_stored_code_is_hashed_not_plain(): void
    {
        Mail::fake();
        $this->service()->requestCode($this->user, 'payroll', null, null);

        $row = SecurityStepUpCode::firstOrFail();

        $this->assertNotEmpty($row->code_hash);
        $this->assertStringStartsWith('$2y$', $row->code_hash);
    }

    public function test_the_right_code_elevates_and_the_wrong_one_does_not(): void
    {
        Mail::fake();
        $sent = $this->captureCode();

        $wrong = $this->service()->verify($this->user, 'payroll', $this->wrongCode($sent));
        $this->assertFalse($wrong['ok']);
        $this->assertFalse($this->service()->isElevated($this->user, 'payroll'));

        $right = $this->service()->verify($this->user, 'payroll', $sent);
        $this->assertTrue($right['ok']);
        $this->assertTrue($this->service()->isElevated($this->user, 'payroll'));
    }

    public function test_a_code_cannot_be_reused_after_it_is_spent(): void
    {
        Mail::fake();
        $sent = $this->captureCode();

        $this->service()->verify($this->user, 'payroll', $sent);
        $second = $this->service()->verify($this->user, 'payroll', $sent);

        $this->assertFalse($second['ok']);
    }

    /** Five wrong guesses and the code is dead even if the sixth would be right. */
    public function test_the_code_locks_after_five_wrong_attempts(): void
    {
        Mail::fake();
        $sent = $this->captureCode();

        foreach (range(1, 5) as $i) {
            $this->service()->verify($this->user, 'payroll', $this->wrongCode($sent));
        }

        $result = $this->service()->verify($this->user, 'payroll', $sent);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Too many', $result['message']);
    }

    public function test_an_expired_code_is_refused(): void
    {
        Mail::fake();
        $sent = $this->captureCode();

        Carbon::setTestNow(now()->addMinutes(11));

        $result = $this->service()->verify($this->user, 'payroll', $sent);

        $this->assertFalse($result['ok']);
        Carbon::setTestNow();
    }

    /** Elevation itself expires, independent of the code that bought it. */
    public function test_elevation_expires_after_its_own_window(): void
    {
        Mail::fake();
        $sent = $this->captureCode();
        $this->service()->verify($this->user, 'payroll', $sent);

        $this->assertTrue($this->service()->isElevated($this->user, 'payroll'));

        Carbon::setTestNow(now()->addMinutes(16));

        $this->assertFalse($this->service()->isElevated($this->user, 'payroll'));
        Carbon::setTestNow();
    }

    /** A grant for one purpose says nothing about a different one. */
    public function test_elevation_is_scoped_to_its_purpose(): void
    {
        Mail::fake();
        $sent = $this->captureCode();
        $this->service()->verify($this->user, 'payroll', $sent);

        $this->assertTrue($this->service()->isElevated($this->user, 'payroll'));
        $this->assertFalse($this->service()->isElevated($this->user, 'something-else'));
    }

    private function service(): StepUpVerification
    {
        return app(StepUpVerification::class);
    }
}
