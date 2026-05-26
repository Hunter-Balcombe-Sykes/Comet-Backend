<?php

namespace App\Mail\Notifications;

use App\Mail\BaseTransactionalMail;
use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

// V2: Sends policy-update broadcast emails (ToS / privacy policy changes) using the Notification model and the policy_update template.
class PolicyUpdateMail extends BaseTransactionalMail
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Notification $notification) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->subject($this->notification->title)
            ->view('emails.notifications.policy_update');
    }
}
