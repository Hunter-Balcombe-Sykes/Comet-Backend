<?php

namespace App\Services\Notifications;

use App\Models\Core\EmailSuppression;
use App\Support\EmailHasher;
use Illuminate\Support\Facades\Log;

/**
 * Reads and writes the core.email_suppressions list.
 *
 * Split of responsibility for fault tolerance:
 * - suppress() is fault-tolerant (swallows + logs any DB error). It runs on the
 *   Resend webhook request path, which must never 500 — Resend hammers retries
 *   on a non-2xx, and a suppression-write outage is not worth breaking the
 *   webhook over.
 * - isSuppressed() is NOT fault-tolerant — it lets a DB error propagate. The
 *   send-time gate (BlockSuppressedRecipients) wraps it and fails OPEN (allows
 *   the send) so a suppression-store outage can never block an OTP. Keeping the
 *   throw here makes that trade-off explicit at the one place it's decided.
 *
 * PII: addresses are hashed via EmailHasher (SHA256 HMAC, app.key pepper) — the
 * same scheme as WHK-3 and the send-time gate, so rows correlate and a suppressed
 * address matches at send time.
 */
class EmailSuppressionService
{
    /**
     * Add (or refresh) a suppression entry for an address. Idempotent: upserts on
     * the email hash, so a repeated event never creates a duplicate row, and the
     * original first_seen_at is preserved across re-signals. Never throws.
     *
     * @param  string  $reason  One of EmailSuppression::REASON_*.
     * @param  string|null  $source  Origin, e.g. 'resend'.
     * @param  string|null  $detail  Non-PII bounce subtype, e.g. 'Suppressed'. Never the bounce message.
     */
    public function suppress(string $email, string $reason, ?string $source = null, ?string $detail = null): void
    {
        $hash = EmailHasher::hash($email);
        if ($hash === null) {
            return; // blank/invalid address — nothing to suppress.
        }

        try {
            // firstOrNew + conditional first_seen_at (rather than updateOrCreate)
            // so first_seen_at is set only on insert and preserved on update,
            // while reason/source/detail refresh to the latest signal.
            $row = EmailSuppression::firstOrNew(['email_hash' => $hash]);
            if (! $row->exists) {
                $row->first_seen_at = now();
            }
            $row->reason = $reason;
            $row->source = $source;
            $row->detail = $detail;
            $row->save();
        } catch (\Throwable $e) {
            // A suppression-write failure must never break the webhook response.
            Log::error('email_suppression.write_failed', [
                'reason' => $reason,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * True when the address is on the suppression list. Hashes with the shared
     * EmailHasher so the lookup matches what suppress() (and WHK-3) wrote.
     * Intentionally NOT wrapped in try/catch — see the class docblock.
     */
    public function isSuppressed(string $email): bool
    {
        $hash = EmailHasher::hash($email);
        if ($hash === null) {
            return false;
        }

        return EmailSuppression::where('email_hash', $hash)->exists();
    }
}
