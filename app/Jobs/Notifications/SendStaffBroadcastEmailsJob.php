<?php

namespace App\Jobs\Notifications;

use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

// No capability gate: subscribers are not Professional-keyed (whereNull('user_id')),
// so account_type gating does not apply — this is a marketing list, not a per-account feature.
// Fans out staff broadcast emails to all marketing-list subscribers using Bus::batch()
// so each sub-chunk of jobs shares one Redis pipeline write instead of one write per job —
// the marketing list grows unboundedly with sign-ups, so this is the urgent batch fix.
class SendStaffBroadcastEmailsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Surface deterministic failures fast — fail after 2 consecutive throws
    // instead of burning the full backoff window before Horizon alerts.
    public int $maxExceptions = 2;

    public array $backoff = [10, 30, 60];

    public int $timeout = 120;

    // Cap on how long the uniqueness lock survives. Without this the lock has no
    // expiry, so a SIGKILL'd/OOM'd worker that never reaches the normal release
    // leaves a permanent lock — every future broadcast for that notification is
    // then silently dropped by ShouldBeUnique. 600s comfortably exceeds the 120s
    // timeout + retry/backoff window.
    public int $uniqueFor = 600;

    // Prevent concurrent fan-out for the same notification. The leaf job's
    // broadcast_email_receipts PK already blocks duplicate sends, but without this
    // a concurrent dispatch doubles the per-subscriber queue work.
    public function uniqueId(): string
    {
        return 'staff-broadcast:'.$this->notificationId;
    }

    // Batch size sourced from config('partna.notifications.batch_chunk_size')
    // so this and NotificationPublisher's chunking stay in lockstep without
    // manual sync (#CFG-3).

    public function __construct(
        public string $notificationId,
        public string $listKey = 'sidest_updates'
    ) {
        // Coordinator job: long chunkById walk over EmailSubscription. Keep it off
        // the default queue so a large broadcast doesn't back-pressure unrelated work.
        $this->onQueue(config('partna.queues.notifications', 'notifications'));
    }

    public function handle(): void
    {
        $notification = Notification::query()->find($this->notificationId);
        if (! $notification) {
            // Notification deleted between dispatch and run — broadcast aborted.
            // Log so Nightwatch/ops can distinguish this from a successful no-op.
            Log::warning('SendStaffBroadcastEmailsJob: notification not found, broadcast aborted', [
                'notification_id' => $this->notificationId,
                'list_key' => $this->listKey,
            ]);

            return;
        }

        EmailSubscription::query()
            ->whereNull('user_id')
            ->where('list_key', $this->listKey)
            ->where('status', 'subscribed')
            ->orderBy('id')
            ->chunkById(500, function ($subs) use ($notification) {
                $jobs = $subs->map(fn ($sub) => new SendStaffBroadcastEmailToSubscriberJob(
                    $notification->id,
                    $sub->id,
                ))->all();

                // One Redis pipeline write per batch vs. one per job if dispatched
                // individually. allowFailures() preserves the per-job retry semantics
                // SendStaffBroadcastEmailToSubscriberJob's $tries=3 promises — without it,
                // a single failure cancels remaining (still-pending) jobs in the batch.
                foreach (array_chunk($jobs, (int) config('partna.notifications.batch_chunk_size', 200)) as $chunk) {
                    $batch = Bus::batch($chunk)
                        ->onQueue(config('partna.queues.mail', 'mail'))
                        ->name('staff-broadcast:'.$notification->id)
                        ->allowFailures()
                        ->dispatch();

                    Log::info('Staff broadcast batch dispatched', [
                        'batch_id' => $batch->id,
                        'notification_id' => $notification->id,
                        'list_key' => $this->listKey,
                        'job_count' => count($chunk),
                    ]);
                }
            });
    }

    public function failed(\Throwable $e): void
    {
        // Forward to Nightwatch so the dropped broadcast is observable by notification_id.
        report($e);

        Log::error('Staff broadcast fan-out permanently failed', [
            'notification_id' => $this->notificationId,
            'list_key' => $this->listKey,
            'message' => $e->getMessage(),
        ]);
    }
}
