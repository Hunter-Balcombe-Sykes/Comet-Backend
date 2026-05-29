<?php

namespace App\Mail;

// Visitor-facing "you're subscribed" receipt, sent to the person who joined a
// newsletter list. Carries the unsubscribe link + RFC 8058 one-click headers
// (same pattern as StaffBroadcastMail). Tier-2 transactional email: not
// registered in config('partna.notifications.mailables').
class SubscriptionConfirmationMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $proDisplayName,
        public readonly string $siteUrl,
        public readonly string $unsubscribeUrl,
        public readonly ?string $visitorName = null,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->subject("You're subscribed — {$this->proDisplayName}")
            ->view('emails.subscription-confirmation', [
                'proDisplayName' => $this->proDisplayName,
                'siteUrl' => $this->siteUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'visitorName' => $this->visitorName,
            ])
            ->withSymfonyMessage(function ($message): void {
                // RFC 8058 one-click unsubscribe — required by Gmail/Yahoo bulk
                // rules. Mirrors StaffBroadcastMail. buildEnvelope() already added
                // its own withSymfonyMessage callback; both run.
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribeUrl.'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }
}
