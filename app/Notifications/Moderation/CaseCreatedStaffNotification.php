<?php

namespace App\Notifications\Moderation;

use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CaseCreatedStaffNotification extends Notification
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
            ->subject("[Partna T&S] New severity-{$this->case->severity} case on {$this->case->reportable_type}")
            ->line("Case ID: {$this->case->id}")
            ->line("Type: {$this->case->case_type}")
            ->line("Signal count: {$this->case->signal_count}")
            ->action('Open case in staff dashboard', config('app.url') . "/staff/cases/{$this->case->id}");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'case_id'      => $this->case->id,
            'case_type'    => $this->case->case_type,
            'severity'     => $this->case->severity,
            'signal_count' => $this->case->signal_count,
        ];
    }
}
