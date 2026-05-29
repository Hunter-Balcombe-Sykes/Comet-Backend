<?php

namespace App\Mail;

// Visitor-facing "we received your enquiry" receipt, sent to the person who
// submitted the contact form. Reply-To is set to the professional's contact
// inbox so a visitor reply reaches them directly. Tier-2 transactional email:
// not registered in config('partna.notifications.mailables').
class EnquiryConfirmationMail extends BaseTransactionalMail
{
    // $enquirySubject stores the form subject line. We cannot use constructor
    // property promotion with the name "subject" because Mailable::$subject is
    // non-readonly — PHP forbids redeclaring it as readonly on a subclass.
    public readonly string $enquirySubject;

    public function __construct(
        public readonly string $proDisplayName,
        public readonly string $visitorName,
        string $subject,
        public readonly string $siteUrl,
        public readonly ?string $replyToEmail,
    ) {
        $this->enquirySubject = $subject;
    }

    public function build(): self
    {
        $this->buildEnvelope()
            ->subject("We received your enquiry — {$this->proDisplayName}")
            ->view('emails.enquiry-confirmation', [
                'proDisplayName' => $this->proDisplayName,
                'visitorName' => $this->visitorName,
                'subject' => $this->enquirySubject,
                'siteUrl' => $this->siteUrl,
            ]);

        // Replace the Partna default reply-to so visitor replies reach the pro.
        // buildEnvelope() seeds the default; we drop it and set the pro inbox.
        if ($this->replyToEmail !== null && trim($this->replyToEmail) !== '') {
            $this->replyTo = [];
            $this->replyTo(trim($this->replyToEmail), $this->proDisplayName);
        }

        return $this;
    }
}
