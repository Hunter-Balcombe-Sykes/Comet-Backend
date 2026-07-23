<?php

namespace App\Jobs\Moderation\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Per-recipient send idempotency for moderation Notify* jobs (RV-10).
 *
 * markDispatched/markCompleted (HasActionLogLifecycle) guard the ActionLogEntry
 * as a whole; they cannot protect an individual recipient inside a
 * multi-recipient job, because a crash after recipient N's send but before the
 * entry is marked completed leaves the entry retryable and re-sends to every
 * recipient. This trait claims one atomic marker per recipient immediately
 * before that recipient's send, so a retry only re-attempts recipients whose
 * claim never landed (send never ran) or whose send actually threw.
 *
 * Deliberately not DB::transaction and not an entry-level flag — a mail/
 * notification send cannot be enrolled in a Postgres transaction, and a
 * transaction wrapping the marks alone adds nothing since each mark is
 * already a single atomic UPDATE. See plan-unit-7-rv10.md §3.
 *
 * Assumes the default cache store is shared across workers (Redis in
 * dev/prod, `array` in tests — CACHE_STORE must never be `array` in a
 * deployed env or these markers become per-process and useless).
 */
trait DedupesRecipientSends
{
    protected function recipientSendKey(string $actionLogId, string $recipient): string
    {
        $hash = substr(hash('sha256', $actionLogId.'|'.$recipient), 0, 32);

        return "moderation:notify-sent:{$actionLogId}:{$hash}";
    }

    /**
     * Atomically claim the send slot for one recipient (Cache::add = SETNX).
     * Returns false if a previous attempt already claimed (and presumably
     * sent) this recipient for this action log entry.
     */
    protected function claimRecipient(string $actionLogId, string $recipient): bool
    {
        $ttl = (int) config('partna.moderation.notify_send_marker_ttl_seconds', 604_800);
        $claimed = Cache::add($this->recipientSendKey($actionLogId, $recipient), 1, $ttl);

        if (! $claimed) {
            // Greppable breadcrumb: nothing in the app reads moderation.action_log,
            // so a skipped resend is otherwise invisible.
            Log::warning('moderation.notify.recipient_already_sent', [
                'job' => static::class,
                'action_log_id' => $actionLogId,
            ]);
        }

        return $claimed;
    }

    /**
     * Roll back a claim so a failed send is retryable. Without this, the
     * retry sees Cache::add return false, treats the failed recipient as
     * already delivered, and permanently drops the notification.
     */
    protected function releaseRecipient(string $actionLogId, string $recipient): void
    {
        Cache::forget($this->recipientSendKey($actionLogId, $recipient));
    }
}
