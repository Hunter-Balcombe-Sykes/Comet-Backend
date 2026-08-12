<?php

namespace App\Mail\Security;

use App\Mail\BaseTransactionalMail;

// Security notice: a second factor was enrolled (client-side via Supabase).
class TwoFactorEnabledMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $displayName,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Two-factor authentication is on')
            ->view('emails.security.two-factor-enabled');
    }
}
