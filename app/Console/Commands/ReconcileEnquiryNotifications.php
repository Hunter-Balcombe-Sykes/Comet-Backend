<?php

namespace App\Console\Commands;

use App\Jobs\Notifications\DispatchEnquiryNotificationsJob;
use App\Jobs\Notifications\SendEnquiryConfirmationJob;
use App\Models\Core\Site\Enquiry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drains enquiries whose notification dispatch failed because Redis was
 * unreachable (PublicEnquiryController::dispatchEnquiryNotifications).
 *
 * WHY THIS IS KEYED ON notifications_pending_since AND NOT ON A NULL
 * email_sent_at / confirmation_sent_at. Those are the jobs' post-send
 * idempotency stamps, and both jobs are GATED — a correctly skipped
 * notification also leaves them NULL. A sweep keyed on NULL would re-dispatch
 * permanently-skipped enquiries every five minutes forever. The marker means
 * "dispatch failed", which is a strictly narrower and recoverable condition.
 */
class ReconcileEnquiryNotifications extends Command
{
    protected $signature = 'enquiries:reconcile-notifications';

    protected $description = 'Re-dispatch enquiry notifications whose original dispatch failed (Redis outage recovery)';

    public function handle(): int
    {
        $batch = (int) config('partna.enquiry.reconcile_batch_size', 200);
        $confirmationWindow = (int) config('partna.enquiry.confirmation_reconcile_window_minutes', 60);
        $alertAfter = (int) config('partna.enquiry.notifications_pending_alert_minutes', 30);

        $reconciled = 0;
        $oldestPending = null;

        DB::connection('pgsql')->transaction(function () use ($batch, $confirmationWindow, &$reconciled, &$oldestPending) {
            // SKIP LOCKED so an overlapping run (or a second server) takes a
            // different slice rather than blocking — mirrors the claim-vs-prune
            // pattern in PreAccountBuildService. SQLite ignores the lock clause
            // (Feature suite unaffected); the Postgres behavior is the contract.
            $pending = Enquiry::query()
                ->whereNotNull('notifications_pending_since')
                ->orderBy('notifications_pending_since')
                ->limit($batch)
                ->lock('for update skip locked')
                ->get();

            foreach ($pending as $enquiry) {
                $pendingSince = $enquiry->notifications_pending_since;

                if ($oldestPending === null || $pendingSince->lt($oldestPending)) {
                    $oldestPending = $pendingSince;
                }

                try {
                    DispatchEnquiryNotificationsJob::dispatch((string) $enquiry->id);

                    // The visitor's receipt is skipped once stale: "we received
                    // your message" arriving hours later reads worse than none.
                    // The professional's notification has no such problem.
                    if ($pendingSince->gt(now()->subMinutes($confirmationWindow))) {
                        SendEnquiryConfirmationJob::dispatch((string) $enquiry->id);
                    } else {
                        Log::warning('enquiry.notify.confirmation_stale', [
                            'enquiry_id' => (string) $enquiry->id,
                            'pending_minutes' => $pendingSince->diffInMinutes(now()),
                        ]);
                    }
                } catch (Throwable $e) {
                    // Queue still down. Leave the marker so the next tick
                    // retries rather than losing the notification entirely.
                    //
                    // Deliberately ONE catch for both dispatch() calls, not two —
                    // one marker column means a partial failure (job 1 enqueues,
                    // job 2 throws) re-dispatches BOTH on the next tick, including
                    // the one that already made it onto the queue. That is safe,
                    // not just tolerated: DispatchEnquiryNotificationsJob claims an
                    // atomic Cache::add SETNX before its side-effect (handle()) and
                    // SendEnquiryConfirmationJob is ShouldBeUnique and checks
                    // confirmation_sent_at — a re-dispatch of either is a no-op.
                    Log::warning('enquiry.notify.reconcile_failed', [
                        'enquiry_id' => (string) $enquiry->id,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                // Cleared once the job is ENQUEUED, not executed. Delivery from
                // here is the queue's problem, bounded by each job's own
                // tries/backoff and the failed-jobs table.
                $enquiry->forceFill(['notifications_pending_since' => null])->save();
                $reconciled++;
            }
        });

        if ($reconciled > 0) {
            $this->info("Reconciled {$reconciled} enquiry notification(s).");
        }

        // Log::warning does not reach Nightwatch — it alerts on exceptions and
        // reports, never on log queries. report() is what actually pages, and
        // "leads were captured but nobody was told" is worth paging on. Once
        // per run, not once per row.
        if ($oldestPending !== null && $oldestPending->lt(now()->subMinutes($alertAfter))) {
            report(new \RuntimeException(
                'Enquiry notifications pending since '.$oldestPending->toIso8601String().
                ' — leads captured but not delivered.'
            ));
        }

        return self::SUCCESS;
    }
}
