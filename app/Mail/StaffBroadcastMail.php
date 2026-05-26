<?php

namespace App\Mail;

use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

// V2: Sends staff broadcast emails to professionals with an unsubscribe link, using the Notification model and the staff_broadcast template.
class StaffBroadcastMail extends BaseTransactionalMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public Notification $notification,
        public string $unsubscribeUrl
    ) {}

    public function build()
    {
        return $this->buildEnvelope()
            ->subject($this->notification->title)
            ->view('emails.staff_broadcast', [
                'notification' => $this->notification,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ])
            ->withSymfonyMessage(function ($message): void {
                // RFC 8058 one-click unsubscribe. Required by Gmail/Yahoo bulk-sender
                // rules (Feb 2024) for any sender >5k msgs/day on marketing mail.
                // Both headers must be covered by the DKIM signature — Resend signs
                // the full header set by default. The URL points to a verb-tolerant
                // route that accepts an unauthenticated POST with body
                // "List-Unsubscribe=One-Click" and returns 2xx idempotently.
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribeUrl.'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }
}
