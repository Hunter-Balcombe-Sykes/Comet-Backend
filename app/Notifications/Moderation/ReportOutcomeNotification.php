<?php

namespace App\Notifications\Moderation;

use App\Models\Moderation\Decision;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportOutcomeNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Decision $decision) {}

    // Reporter-facing only — anonymous reporters have no DB user row, so 'database' is excluded.
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $outcome = match ($this->decision->decision_type) {
            'dismiss'                   => 'We reviewed your report and determined no action was needed.',
            'warn'                      => 'We reviewed your report and warned the user.',
            'hide_content', 'hide_site' => 'We reviewed your report and removed the content.',
            'suspend_user', 'ban_user'  => 'We reviewed your report and took action against the account.',
            default                     => 'We reviewed your report.',
        };

        return (new MailMessage)
            ->subject('Update on the page you reported')
            ->line('Thank you for your report.')
            ->line($outcome);
    }

    public function toArray(object $notifiable): array
    {
        return ['outcome' => $this->decision->decision_type, 'decision_id' => $this->decision->id];
    }
}
