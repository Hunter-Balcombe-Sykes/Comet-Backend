<?php

namespace App\Notifications\Moderation;

use App\Models\Moderation\Decision;
use App\Notifications\Concerns\BuildsPartnaMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountSuspendedNotification extends Notification
{
    use BuildsPartnaMailMessage;
    use Queueable;

    public function __construct(public readonly Decision $decision) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->partnaMailMessage(
            'Your Partna account has been suspended',
            'mail.moderation.account-suspended',
            ['decision' => $this->decision],
            dedupeKey: (string) $this->decision->id,
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'decision_id' => $this->decision->id,
            'reason' => $this->decision->reason,
            'decided_at' => $this->decision->decided_at,
        ];
    }
}
