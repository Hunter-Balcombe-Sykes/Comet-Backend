<?php

namespace App\Mail\Account;

use App\Mail\BaseTransactionalMail;

// One-shot welcome, queued when the build SETTLES -- from builds:settle-sweep
// or from claim, whichever of the two lands second (2026-09-03). It used to
// ride claim() alone, but claiming no longer waits on the build reaching
// ready, so "your site is live" routinely announced an empty page. The
// dedupe is pre_account_builds.welcomed_at, not this class.
class WelcomeMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $handle,
        /** @var list<string> */
        public readonly array $connectedPlatforms = [],
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Welcome to Partna — your site is live')
            ->view('emails.account.welcome', ['connectedPlatforms' => $this->connectedPlatforms]);
    }
}
