<?php

namespace App\Notifications\Moderation;

use App\Models\Moderation\Decision;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CaseEscalatedStaffNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Decision $decision) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $target = match ($this->decision->decision_type) {
            'escalate_law_enforcement' => 'law enforcement',
            'escalate_esafety'         => 'eSafety Commissioner',
            default                    => 'an external authority',
        };

        return (new MailMessage)
            ->subject("[Partna T&S] Escalation to {$target}")
            ->line("Case {$this->decision->case_id} has been escalated to {$target}.")
            ->line('Reason: ' . ($this->decision->reason ?? 'see audit log'));
    }

    public function toArray(object $notifiable): array
    {
        return ['decision_id' => $this->decision->id, 'case_id' => $this->decision->case_id];
    }
}
