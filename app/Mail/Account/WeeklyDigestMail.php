<?php

namespace App\Mail\Account;

use App\Mail\BaseTransactionalMail;

/**
 * Weekly analytics digest — real numbers for the last full week. Sent by
 * partna:notify-weekly-summary alongside the in-app notification; gated there
 * by the analytics_weekly preference, so it carries that category's one-click
 * unsubscribe headers (RFC 8058) like any category mail would.
 */
class WeeklyDigestMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $displayName,
        public readonly string $weekLabel,
        public readonly int $visits,
        public readonly int $visitors,
        public readonly int $taps,
        public readonly ?string $topLinkLabel,
        public readonly ?int $topLinkClicks,
        public readonly ?string $unsubscribeUrl,
    ) {}

    public function build(): self
    {
        $mail = $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject("Your week on Partna — {$this->visits} visits")
            ->view('emails.account.weekly-digest');

        if ($this->unsubscribeUrl !== null) {
            $url = $this->unsubscribeUrl;
            $mail->withSymfonyMessage(function ($message) use ($url): void {
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$url.'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
        }

        return $mail;
    }
}
