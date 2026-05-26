<?php

namespace App\Mail\Notifications;

use App\Mail\BaseTransactionalMail;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

// V2: Sent when a professional cancels their pending deletion during the grace period.
class AccountDeletionCancelledMail extends BaseTransactionalMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $displayName,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->subject('Your account deletion has been cancelled')
            ->view('emails.account.deletion-cancelled');
    }
}
