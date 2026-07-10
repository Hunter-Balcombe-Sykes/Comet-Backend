<?php

namespace App\Mail\EarlyAccess;

use App\Mail\BaseTransactionalMail;

/**
 * Auto thank-you sent when someone joins the early-access waitlist through the
 * public marketing form. Same template family as the OTP/auth emails
 * (mail.layouts.partna) — no new layout.
 */
class EarlyAccessThankYouMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject("You're on the Partna early access list")
            ->view('emails.account.early-access-thank-you');
    }
}
