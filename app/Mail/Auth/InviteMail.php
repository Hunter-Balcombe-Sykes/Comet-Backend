<?php

namespace App\Mail\Auth;

use App\Mail\BaseTransactionalMail;

class InviteMail extends BaseTransactionalMail
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
            ->subject('You\'ve been invited to Partna')
            ->view('emails.auth.invite');
    }
}
