<?php

namespace App\Mail;

use App\Models\Core\Site\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

// V2: Notifies the affiliate's configured inbox of a new enquiry submitted via the contact section block.
class SiteEnquiryNotification extends BaseTransactionalMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Enquiry $enquiry,
    ) {}

    public function build(): self
    {
        $name = $this->sanitizeHeader($this->enquiry->name);
        $subject = $this->sanitizeHeader($this->enquiry->subject);
        $dashboardUrl = rtrim((string) config('app.dashboard_url', config('app.url')), '/').'/enquiries';

        return $this->buildEnvelope()
            ->subject(Str::limit("New enquiry from {$name} — {$subject}", 77))
            ->view('emails.enquiry-notification', [
                'enquiry' => $this->enquiry,
                'dashboardUrl' => $dashboardUrl,
            ]);
    }

    // Defence-in-depth against CRLF email header injection on user-supplied
    // fields interpolated into the subject line. Symfony Mailer strips these
    // too, but CVE-2026-45067 proved that protection isn't bulletproof.
    private function sanitizeHeader(string $value): string
    {
        return (string) preg_replace('/[\r\n]+/', ' ', $value);
    }
}
