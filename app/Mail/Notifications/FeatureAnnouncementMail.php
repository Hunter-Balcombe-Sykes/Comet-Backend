<?php

namespace App\Mail\Notifications;

use App\Mail\BaseTransactionalMail;
use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

// V2: Sends feature-announcement broadcast emails (new product capabilities) using the Notification model and the feature_announcement template.
class FeatureAnnouncementMail extends BaseTransactionalMail
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Notification $notification) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->subject($this->notification->title)
            ->view('emails.notifications.feature_announcement');
    }
}
