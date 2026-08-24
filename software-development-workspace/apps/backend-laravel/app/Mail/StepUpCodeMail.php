<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The code that proves whoever is looking at this inbox is the one at the
 * keyboard. Sent to the viewer's own address, never to anybody else's — this
 * is "prove it is you", not a way to hand somebody else access.
 */
class StepUpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $code,
        private readonly string $purpose,
        private readonly int $validMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your verification code: {$this->code}");
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->buildHtml());
    }

    private function buildHtml(): string
    {
        $e     = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $code  = $e($this->code);
        $mins  = $e($this->validMinutes);
        $label = $e($this->purposeLabel());

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;color:#111827">
  <table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px">
    <table width="480" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;padding:28px">
      <tr><td>
        <h1 style="margin:0 0 18px;font-size:19px">Verification code</h1>
        <p style="margin:0 0 16px">
          Somebody signed in as you asked to view {$label}. Enter this code to continue:
        </p>
        <div style="background:#eff6ff;border-radius:8px;padding:18px 16px;margin:0 0 16px;text-align:center">
          <span style="font-size:30px;font-weight:700;letter-spacing:6px;font-family:ui-monospace,monospace">{$code}</span>
        </div>
        <p style="margin:0 0 16px;font-size:13px;color:#6b7280">
          Valid for {$mins} minutes and only works once. If this was not you, your password may be
          known to somebody else — change it and tell IT.
        </p>
        <p style="margin:0;font-size:12px;color:#9ca3af">
          Sent automatically. Please do not reply to this address if it is unmonitored.
        </p>
      </td></tr>
    </table>
  </td></tr></table>
</body></html>
HTML;
    }

    private function purposeLabel(): string
    {
        return match ($this->purpose) {
            'payroll' => 'payroll and compensation figures',
            default   => 'sensitive information',
        };
    }
}
