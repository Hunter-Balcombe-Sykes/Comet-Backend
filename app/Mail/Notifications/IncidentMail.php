<?php

namespace App\Mail\Notifications;

use App\Mail\BaseTransactionalMail;
use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

// V2: Sends incident broadcast emails (outages / service disruption) using the Notification model and the incident template.
class IncidentMail extends BaseTransactionalMail
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Notification $notification) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->subject($this->notification->title)
            ->view('emails.notifications.incident');
    }
}
