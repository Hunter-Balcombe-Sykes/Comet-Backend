<?php

namespace App\Mail\Auth;

use App\Mail\BaseTransactionalMail;

/**
 * Email change confirmation — sent in response to Supabase `email_change_new` action.
 * Uses the same link-click flow as PasswordResetMail; the verify URL lands on
 * /auth/confirm which calls verifyOtp with type=email_change on the client.
 */
class EmailChangeMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $displayName,
        public readonly string $verifyUrl,
        string $webhookId = '',
    ) {
        $this->webhookId = $webhookId;
    }

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Confirm your new Partna email')
            ->view('emails.auth.email-change');
    }
}
