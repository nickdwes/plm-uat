<?php

namespace App\Mail;

use App\Models\Preboarding\PreboardingRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sends (or re-sends) the secure pre-hire form link to the candidate.
 */
class PreboardingLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $formUrl;
    public string $candidateName;
    public string $orgName;
    public string $expiryDays;

    public function __construct(PreboardingRecord $record, string $orgName = '')
    {
        $base = rtrim(env('FRONTEND_URL', config('app.url', 'https://hrms.bitwyse.co.ke')), '/');
        $this->formUrl       = $base . '/#/pre-onboarding/form/' . $record->token;
        $this->candidateName = trim($record->first_name . ' ' . $record->last_name);
        $this->orgName       = $orgName ?: 'HR Portal';
        $this->expiryDays    = (string) ($record->link_expiry_days ?: 3);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Pre-Hire Form Link — ' . $this->orgName,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    private function buildHtml(): string
    {
        $url     = htmlspecialchars($this->formUrl, ENT_QUOTES);
        $name    = htmlspecialchars($this->candidateName, ENT_QUOTES);
        $org     = htmlspecialchars($this->orgName, ENT_QUOTES);
        $expiry  = htmlspecialchars($this->expiryDays, ENT_QUOTES);

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center" style="padding:40px 16px">
      <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.08)">
        <!-- Header -->
        <tr><td style="background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);padding:32px 40px">
          <div style="color:#fff;font-size:22px;font-weight:700;margin-bottom:4px">{$org}</div>
          <div style="color:rgba(255,255,255,.8);font-size:14px">Pre-Hire Onboarding Form</div>
        </td></tr>
        <!-- Body -->
        <tr><td style="padding:32px 40px">
          <p style="font-size:16px;margin:0 0 16px">Hi {$name},</p>
          <p style="font-size:14px;color:#374151;line-height:1.7;margin:0 0 24px">
            Welcome to <strong>{$org}</strong>! We're excited to have you join the team.<br>
            Please complete your pre-hire form at your earliest convenience. The link is valid for
            <strong>{$expiry} day(s)</strong> from when this email was sent.
          </p>
          <div style="text-align:center;margin:28px 0">
            <a href="{$url}" style="background:#4f46e5;color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-size:15px;font-weight:600;display:inline-block">
              Open My Pre-Hire Form →
            </a>
          </div>
          <p style="font-size:12px;color:#6b7280;line-height:1.6;margin:0">
            If the button above doesn't work, copy and paste this link into your browser:<br>
            <a href="{$url}" style="color:#4f46e5;word-break:break-all">{$url}</a>
          </p>
        </td></tr>
        <!-- Footer -->
        <tr><td style="background:#f8fafc;padding:20px 40px;border-top:1px solid #e2e8f0">
          <p style="font-size:11px;color:#9ca3af;margin:0">
            This email was sent by {$org} HR. If you have questions, please contact your HR team directly.<br>
            Do not share this link — it is unique to you.
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
