<?php

namespace App\Mail\EarlyAccess;

use App\Mail\BaseTransactionalMail;

/**
 * Early-access invite — carries the finish-signup link
 * ({frontend}/signup?invite=<token>). The token locks the signup to this email
 * through the OTP step. Same template family as the OTP/auth emails.
 */
class EarlyAccessInviteMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $signupUrl,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject("You're in — set up your Partna site")
            ->view('emails.account.early-access-invite');
    }
}
