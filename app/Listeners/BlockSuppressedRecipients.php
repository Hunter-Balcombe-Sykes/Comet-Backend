<?php

namespace App\Listeners;

use App\Services\Notifications\EmailSuppressionService;
use App\Support\EmailHasher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

/**
 * Send-time suppression gate. Listens on MessageSending — the chokepoint every
 * outbound email passes through (OTP, claim-invite, transactional alike) — and
 * cancels delivery to any address on core.email_suppressions.
 *
 * Returning false from a MessageSending listener halts the send (Laravel's
 * Mailer::shouldSendMessage sends only when the event result !== false).
 *
 * Fail posture: OPEN. If the suppression lookup throws (store outage, schema
 * drift), the send PROCEEDS and a warning is logged. A stuck OTP is worse than
 * one email to a dead address — the webhook + list are the durable defence;
 * this gate is best-effort belt-and-braces.
 *
 * Single-recipient assumption: Partna mailables are single-recipient, so
 * cancelling the whole message when any To address is suppressed is correct (no
 * partial-send semantics to worry about). We still iterate every To defensively.
 */
class BlockSuppressedRecipients
{
    public function __construct(
        private readonly EmailSuppressionService $suppressions,
    ) {}

    /**
     * @return bool false cancels the send; true allows it.
     */
    public function handle(MessageSending $event): bool
    {
        foreach ($event->message->getTo() as $address) {
            $email = $address->getAddress();

            try {
                if ($this->suppressions->isSuppressed($email)) {
                    // Log the hash, never the plaintext address (PII).
                    Log::info('mail.suppressed', [
                        'email_hash' => EmailHasher::hash($email),
                    ]);

                    return false;
                }
            } catch (\Throwable $e) {
                // Fail OPEN — never let a suppression-store fault block a send.
                Log::warning('mail.suppression_check_failed', [
                    'email_hash' => EmailHasher::hash($email),
                    'error' => $e->getMessage(),
                ]);
                // Do not cancel on error; fall through to allow.
            }
        }

        return true;
    }
}
