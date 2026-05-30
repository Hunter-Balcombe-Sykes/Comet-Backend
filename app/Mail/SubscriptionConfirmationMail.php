<?php

namespace App\Mail;

use App\Mail\Branding\EmailBrand;

// Visitor-facing "you're subscribed" receipt, white-labelled to the professional
// via EmailBrand. Carries the unsubscribe link + RFC 8058 one-click headers.
// Tier-2 transactional email: not registered in config('partna.notifications.mailables').
class SubscriptionConfirmationMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly EmailBrand $brand,
        public readonly string $unsubscribeUrl,
        public readonly ?string $visitorName = null,
    ) {}

    public function build(): self
    {
        $this->buildEnvelope()
            ->from(config('mail.from.address', 'hello@partna.au'), $this->brand->proName)
            ->subject("You're subscribed — {$this->brand->proName}")
            ->view('emails.subscription-confirmation', [
                'brand' => $this->brand,
                'proDisplayName' => $this->brand->proName,
                'siteUrl' => $this->brand->siteUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'visitorName' => $this->visitorName,
            ])
            ->withSymfonyMessage(function ($message): void {
                // RFC 8058 one-click unsubscribe — required by Gmail/Yahoo bulk rules.
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribeUrl.'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });

        // Replace the Partna default reply-to with the pro inbox when present.
        if ($this->brand->replyToEmail !== null && trim($this->brand->replyToEmail) !== '') {
            $this->replyTo = [];
            $this->replyTo(trim($this->brand->replyToEmail), $this->brand->proName);
        }

        return $this;
    }
}
