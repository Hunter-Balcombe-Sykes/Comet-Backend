<?php

namespace App\Notifications\Moderation;

use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CsamAutoActionStaffNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly ModerationCase $case) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Partna T&S] CSAM auto-action — review required')
            ->line("Case ID: {$this->case->id}")
            ->line('A CSAM hash match was detected. The user has been auto-suspended and the upload quarantined.')
            ->line('Please review the case and confirm the auto-action.')
            ->action('Review case', config('app.url')."/staff/cases/{$this->case->id}");
    }

    public function toArray(object $notifiable): array
    {
        return ['case_id' => $this->case->id, 'case_type' => $this->case->case_type];
    }
}
