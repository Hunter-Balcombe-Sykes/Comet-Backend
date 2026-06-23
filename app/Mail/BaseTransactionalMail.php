<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Common ancestor for every Partna transactional email.
 *
 * Concrete subclasses are responsible for setting their own subject + view +
 * payload via build(); this base only enforces shared envelope concerns
 * (from address, reply-to, headers, header-injection defence) so the entire
 * pipeline stays consistent.
 *
 * WHK-5: Every auth mail carries a stable Message-ID derived from the
 * Supabase webhook-id. This means a Horizon retry of the same queued job
 * produces an identical Message-ID rather than a random one, so SMTP
 * providers and mail clients that dedup on Message-ID will suppress the
 * duplicate. The $webhookId property is serialized with the job, so it
 * survives the queue serialize → retry cycle unchanged.
 */
abstract class BaseTransactionalMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The Supabase webhook-id that triggered this email.
     *
     * Stored as a plain property so SerializesModels carries it through the
     * queue round-trip. Auth mailables set this in their constructor; all other
     * mailables leave it as the empty-string default and fall back to Symfony's
     * auto-generated Message-ID (see headers()).
     */
    public string $webhookId = '';

    /**
     * Return stable SMTP headers for this message.
     *
     * When $webhookId is set (auth mailables), the Message-ID is pinned to the
     * webhook-id so any Horizon retry produces the same identifier — no random
     * suffix, no clock component. webhook-ids already satisfy RFC 2822 local-part
     * rules (alphanumeric + hyphen/underscore), so no additional sanitization is needed.
     *
     * When $webhookId is empty (non-auth mailables), returns an empty Headers
     * instance so Symfony Mailer generates a random Message-ID as normal.
     */
    public function headers(): Headers
    {
        // Non-auth mailables never set $webhookId; fall back to Symfony's default
        // Message-ID to avoid building an invalid "@domain" local-part.
        if ($this->webhookId === '') {
            return new Headers();
        }

        // Extract the domain portion of the from address (e.g. "hello@partna.au" → "partna.au").
        $fromAddress = (string) config('mail.from.address', 'hello@partna.au');
        $domain = str_contains($fromAddress, '@')
            ? substr($fromAddress, strrpos($fromAddress, '@') + 1)
            : 'partna.au';

        return new Headers(messageId: $this->webhookId.'@'.$domain);
    }

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
