<?php

namespace App\Mail\Notifications;

use App\Mail\BaseTransactionalMail;
use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

// OV-H: generic email for any `critical` notification. Renders the Notification's
// title/body/cta through the shared Partna layout family (same base + layout the OTP
// email uses) — no new email design. Used both as the registered mailable for critical
// categories and as SendTransactionalNotificationEmailJob's fallback for any critical
// notification whose category has no category-specific mailable.
class CriticalNotificationMail extends BaseTransactionalMail
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Notification $notification) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->subject($this->notification->title)
            ->view('emails.notifications.critical');
    }
}
