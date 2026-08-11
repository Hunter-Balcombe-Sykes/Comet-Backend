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
        $dashboardUrl = rtrim((string) config('app.dashboard_url', config('app.url')), '/').'/account/features/enquiries';

        $mail = $this->buildEnvelope()
            ->subject(Str::limit("New enquiry from {$this->enquiry->name} — {$this->enquiry->subject}", 77))
            ->view('emails.enquiry-notification', [
                'enquiry' => $this->enquiry,
                'dashboardUrl' => $dashboardUrl,
            ]);

        // Reply-To is the enquirer, so the owner answers by just hitting Reply.
        $enquirerEmail = trim((string) $this->enquiry->email);
        if ($enquirerEmail !== '' && filter_var($enquirerEmail, FILTER_VALIDATE_EMAIL) !== false) {
            $mail->replyTo = [];
            $mail->replyTo($enquirerEmail, trim((string) $this->enquiry->name) ?: null);
        }

        return $mail;
    }
}
