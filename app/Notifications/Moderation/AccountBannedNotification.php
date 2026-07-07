<?php

namespace App\Notifications\Moderation;

use App\Models\Moderation\Decision;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountBannedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Decision $decision) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Partna account has been permanently closed')
            ->view('mail.moderation.account-banned', ['decision' => $this->decision]);
    }

    public function toArray(object $notifiable): array
    {
        return ['decision_id' => $this->decision->id, 'reason' => $this->decision->reason];
    }
}
