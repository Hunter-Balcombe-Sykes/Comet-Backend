<?php

namespace App\Mail\Auth;

use App\Mail\BaseTransactionalMail;

/**
 * Password reset email — sent in response to Supabase `recovery` action.
 * Verify-link is constructed by the SupabaseEmailHookController from the
 * `token_hash` + `redirect_to` claims; we just render it here.
 */
class PasswordResetMail extends BaseTransactionalMail
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
            ->subject('Reset your Partna password')
            ->view('emails.auth.password-reset');
    }
}
