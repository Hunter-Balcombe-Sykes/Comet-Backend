<?php

namespace App\Mail\Auth;

use App\Mail\BaseTransactionalMail;

class MagicLinkMail extends BaseTransactionalMail
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
            ->subject('Your Partna sign-in link')
            ->view('emails.auth.magic-link');
    }
}
