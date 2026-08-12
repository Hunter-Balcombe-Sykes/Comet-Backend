<?php

namespace App\Mail\Auth;

use App\Mail\BaseTransactionalMail;

/**
 * Reauthentication — Supabase emits this when a sensitive action (e.g. a
 * password change via updateUser with reauthentication enabled) asks the
 * user to prove it's still them. Carries a 6-digit OTP, same presentation
 * as EmailConfirmMail. Previously this action fell through resolveMailable
 * and Supabase sent its unbranded default template.
 */
class ReauthenticationMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $displayName,
        public readonly string $code,
        string $webhookId = '',
    ) {
        $this->webhookId = $webhookId;
    }

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Confirm it\'s you')
            ->view('emails.auth.reauthentication');
    }
}
