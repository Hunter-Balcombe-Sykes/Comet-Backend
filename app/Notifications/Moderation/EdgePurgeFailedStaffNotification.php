<?php

namespace App\Notifications\Moderation;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to on-call staff when CloudflareCachePurgeJob permanently fails for a
 * moderation hide_content takedown. The owner stays active for hide_content,
 * so the KV routing sync never retires the page — this purge was the only
 * backstop, and its failure means the hidden content may still be served
 * from the edge cache until someone purges it manually.
 */
class EdgePurgeFailedStaffNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $handle,
        public readonly string $moderationCaseId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Partna T&S] Edge purge failed — hidden content may still be served')
            ->line("Case ID: {$this->moderationCaseId}")
            ->line("Handle: {$this->handle}")
            ->line('The Cloudflare edge purge for this hide_content takedown exhausted all retries.')
            ->line('hide_content leaves the owner account active, so the KV routing sync never retires this page — the edge purge was the only backstop. Hidden content may still be served from the edge cache until it is purged manually.')
            ->action('Review case', config('app.url')."/staff/cases/{$this->moderationCaseId}");
    }

    public function toArray(object $notifiable): array
    {
        return ['handle' => $this->handle, 'moderation_case_id' => $this->moderationCaseId];
    }
}
