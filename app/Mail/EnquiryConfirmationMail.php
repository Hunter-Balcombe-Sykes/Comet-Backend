<?php

namespace App\Mail;

use App\Mail\Branding\EmailBrand;

// Visitor-facing "we received your enquiry" receipt, white-labelled to the
// professional via EmailBrand. From shows the pro's name (Partna sending domain);
// Reply-To is the pro's contact inbox so a visitor reply reaches them directly.
// Tier-2 transactional email: not registered in config('partna.notifications.mailables').
class EnquiryConfirmationMail extends BaseTransactionalMail
{
    // Mailable::$subject is non-readonly, so we cannot promote a "subject" arg
    // as readonly — keep the form subject under a distinct name.
    public readonly string $enquirySubject;

    public function __construct(
        public readonly EmailBrand $brand,
        public readonly string $visitorName,
        string $subject,
    ) {
        $this->enquirySubject = $subject;
    }

    public function build(): self
    {
        $this->buildEnvelope()
            ->from(config('mail.from.address', 'hello@partna.au'), $this->brand->proName)
            ->subject("We received your enquiry — {$this->brand->proName}")
            ->view('emails.enquiry-confirmation', [
                'brand' => $this->brand,
                'proDisplayName' => $this->brand->proName,
                'visitorName' => $this->visitorName,
                'subject' => $this->enquirySubject,
                'siteUrl' => $this->brand->siteUrl,
            ]);

        // Replace the Partna default reply-to with the pro inbox when present.
        if ($this->brand->replyToEmail !== null && trim($this->brand->replyToEmail) !== '') {
            $this->replyTo = [];
            $this->replyTo(trim($this->brand->replyToEmail), $this->brand->proName);
        }

        return $this;
    }
}
