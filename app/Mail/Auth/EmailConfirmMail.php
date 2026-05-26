<?php

namespace App\Mail\Auth;

use App\Mail\BaseTransactionalMail;

/**
 * Email confirmation — sent on signup, email change, etc. Carries a 6-digit
 * OTP that the user types into the verify step of the sign-up form (or the
 * "verify your email" gate once the RequireEmailVerified middleware kicks
 * in). We deliberately do NOT include a click-link fallback — the email
 * is always opened alongside the form that's asking for the code.
 */
class EmailConfirmMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $displayName,
        public readonly string $code,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Verify your Partna email address')
            ->view('emails.auth.email-confirm');
    }
}
