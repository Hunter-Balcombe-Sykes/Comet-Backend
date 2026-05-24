<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Common ancestor for every Partna transactional email.
 *
 * Concrete subclasses are responsible for setting their own subject + view +
 * payload via build(); this base only enforces shared envelope concerns
 * (from address, reply-to, headers, header-injection defence) so the entire
 * pipeline stays consistent.
 */
abstract class BaseTransactionalMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Apply shared envelope defaults before any subclass build() chain runs.
     */
    public function buildEnvelope(): self
    {
        return $this
            ->from(
                config('mail.from.address', 'hello@partna.au'),
                config('mail.from.name', 'Partna')
            )
            ->replyTo(
                config('mail.from.address', 'hello@partna.au'),
                config('mail.from.name', 'Partna')
            )
            ->withSymfonyMessage(function ($message): void {
                // Identify the pipeline for downstream analytics + bounce attribution.
                $message->getHeaders()->addTextHeader('X-Partna-Pipeline', 'transactional');
            });
    }

    /**
     * Set the subject, stripping CR/LF as defence-in-depth against header
     * injection. Symfony Mailer also strips these from headers, but
     * CVE-2026-45067 confirmed that protection isn't bulletproof, so we apply
     * our own filter here — at the single chokepoint every subject flows
     * through, including the envelope-API path that calls $this->subject()
     * during ensureEnvelopeIsHydrated().
     */
    public function subject($subject): self
    {
        return parent::subject((string) preg_replace('/[\r\n]+/', ' ', (string) $subject));
    }
}
