<?php

namespace App\Mail\Account;

use App\Mail\BaseTransactionalMail;

// One-shot welcome, queued when ClaimSiteService::claim() succeeds — the
// moment the account + site exist. Claim is once-per-account, so no dedupe
// machinery is needed.
class WelcomeMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $handle,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Welcome to Partna — your site is live')
            ->view('emails.account.welcome');
    }
}
