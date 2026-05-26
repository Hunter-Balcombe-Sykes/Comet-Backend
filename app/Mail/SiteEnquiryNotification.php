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
        // CRLF sanitisation happens centrally in BaseTransactionalMail::subject().
        $dashboardUrl = rtrim((string) config('app.dashboard_url', config('app.url')), '/').'/enquiries';

        return $this->buildEnvelope()
            ->subject(Str::limit("New enquiry from {$this->enquiry->name} — {$this->enquiry->subject}", 77))
            ->view('emails.enquiry-notification', [
                'enquiry' => $this->enquiry,
                'dashboardUrl' => $dashboardUrl,
            ]);
    }
}
