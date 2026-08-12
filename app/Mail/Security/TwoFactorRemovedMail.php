<?php

namespace App\Mail\Security;

use App\Mail\BaseTransactionalMail;

/**
 * Security notice: a second factor was removed from the account. Sent from
 * MfaController::destroy — the one MFA mutation that flows through this
 * backend. Notice-only; carries no links to act on the change itself.
 */
class TwoFactorRemovedMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $displayName,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Two-factor authentication was removed from your account')
            ->view('emails.security.two-factor-removed');
    }
}
