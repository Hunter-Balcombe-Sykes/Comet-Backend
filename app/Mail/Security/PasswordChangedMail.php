<?php

namespace App\Mail\Security;

use App\Mail\BaseTransactionalMail;

// Security notice: password changed (client-side via Supabase; the dashboard
// pings POST /me/security-events afterwards). Notice-only.
class PasswordChangedMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $displayName,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Your Partna password was changed')
            ->view('emails.security.password-changed');
    }
}
