<?php

namespace App\Mail\PreAccount;

use App\Mail\BaseTransactionalMail;

// "Your Partna site is ready — claim it" email. Goes to the build's
// contact_email directly (provisional users have no mail route). Same template
// family as the auth/early-access emails.
class ClaimInviteMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $claimUrl,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Your Partna site is ready — claim it')
            ->view('emails.account.claim-invite');
    }
}
