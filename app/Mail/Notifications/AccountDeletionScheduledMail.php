<?php

namespace App\Mail\Notifications;

use App\Mail\BaseTransactionalMail;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

// V2: Sent after confirmation — 30-day grace period is running. Includes the
// scheduled deletion date and a one-click cancel link.
class AccountDeletionScheduledMail extends BaseTransactionalMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $displayName,
        public readonly string $deletesAt,
        public readonly string $cancelUrl,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->subject('Your account is scheduled for deletion')
            ->view('emails.account.deletion-scheduled');
    }
}
