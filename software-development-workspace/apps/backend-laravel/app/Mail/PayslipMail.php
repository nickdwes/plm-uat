<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A payslip, on its way to somebody's personal inbox.
 *
 * The attachment is already encrypted before it reaches this class — see
 * PayslipPdf. Nothing here decides whether to protect it, because that decision
 * is not available to make.
 *
 * The covering note says what opens the document and never the value. A password
 * printed in the same email as the attachment protects nobody, and it is the
 * most natural mistake in the world to make while being helpful.
 */
class PayslipMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $employeeName,
        private readonly string $periodName,
        private readonly string $organisationName,
        private readonly string $passwordHint,
        private readonly string $pdf,
        private readonly string $filename,
        private readonly ?string $subjectLine = null,
        private readonly ?string $bodyText = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine ?: "Payslip — {$this->periodName}",
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->buildHtml());
    }

    public function attachments(): array
    {
        return [
            \Illuminate\Mail\Mailables\Attachment::fromData(fn () => $this->pdf, $this->filename)
                ->withMime('application/pdf'),
        ];
    }

    private function buildHtml(): string
    {
        $e    = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $name = $e($this->employeeName);
        $org  = $e($this->organisationName);
        $when = $e($this->periodName);
        $hint = $e($this->passwordHint);

        $message = $this->bodyText
            ? '<p style="margin:0 0 16px">' . nl2br($e($this->bodyText)) . '</p>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;color:#111827">
  <table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;padding:28px">
      <tr><td>
        <p style="margin:0 0 4px;font-size:13px;color:#6b7280">{$org}</p>
        <h1 style="margin:0 0 18px;font-size:19px">Your payslip for {$when}</h1>

        <p style="margin:0 0 16px">Hello {$name},</p>
        {$message}
        <p style="margin:0 0 16px">Your payslip is attached as a PDF.</p>

        <div style="background:#eff6ff;border-radius:8px;padding:14px 16px;margin:0 0 16px">
          <strong style="display:block;margin-bottom:4px">The file is password protected</strong>
          It opens with: {$hint}
        </div>

        <p style="margin:0 0 16px;font-size:13px;color:#6b7280">
          If it will not open, or anything on it looks wrong, reply to this email
          rather than forwarding it on.
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
}
