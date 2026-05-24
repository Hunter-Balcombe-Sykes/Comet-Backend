<?php

namespace App\Mail\Notifications;

use App\Mail\BaseTransactionalMail;
use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

// V2: Sends profile task reminder emails (incomplete setup steps, action items) using the Notification model and the profile_tasks template.
class ProfileTaskMail extends BaseTransactionalMail
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Notification $notification) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->subject($this->notification->title)
            ->view('emails.notifications.profile_tasks');
    }
}
