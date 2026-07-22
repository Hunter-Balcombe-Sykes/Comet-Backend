<?php

namespace App\Jobs\Notifications;

use App\Mail\StaffBroadcastMail;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// No capability gate: subscriber-level, not professional-level — same justification as SendStaffBroadcastEmailsJob.
// Sends individual staff broadcast email, respecting unsubscribe preferences and subscriber status.
// Dispatched by SendStaffBroadcastEmailsJob — one job per recipient so failures isolate and retry independently.
class SendStaffBroadcastEmailToSubscriberJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Surface deterministic failures fast — fail after 2 consecutive throws
    // instead of burning the full backoff window before Horizon alerts.
    public int $maxExceptions = 2;

    public array $backoff = [10, 30, 60];

    public int $timeout = 30;

    public function __construct(
        public string $notificationId,
        public string $subscriptionId
    ) {
        // Normally inherited from the batch's ->onQueue('mail') in
        // SendStaffBroadcastEmailsJob, but a future direct ::dispatch() (retry
        // tooling, a new caller) must not silently land on 'default'.
        $this->onQueue(config('partna.queues.mail', 'mail'));
    }

    public function handle(): void
    {
        $notification = Notification::query()->find($this->notificationId);
        if (! $notification) {
            // Silent return inside an allowFailures() batch is indistinguishable
            // from success — log so we can correlate dropped sends to the cause.
            Log::warning('SendStaffBroadcastEmailToSubscriberJob: notification not found', [
                'notification_id' => $this->notificationId,
                'subscription_id' => $this->subscriptionId,
            ]);

            return;
        }

        $sub = EmailSubscription::query()->find($this->subscriptionId);
        if (! $sub) {
            Log::warning('SendStaffBroadcastEmailToSubscriberJob: subscription not found', [
                'notification_id' => $this->notificationId,
                'subscription_id' => $this->subscriptionId,
            ]);

            return;
        }

        // Respect unsubscribes that happened after the broadcast was queued
        if ($sub->status !== 'subscribed') {
            return;
        }

        // Claim the send slot before touching the mailer — at-most-once delivery.
        // A crash between insert and send leaves a "sent" receipt for a never-sent
        // email, which is the correct bias for broadcast: losing one copy is better
        // than a subscriber receiving duplicates.
        $inserted = DB::table('notifications.broadcast_email_receipts')->insertOrIgnore([
            'notification_id' => $this->notificationId,
            'subscription_id' => $this->subscriptionId,
        ]);

        if ($inserted === 0) {
            return; // already delivered on a previous attempt
        }

        $unsubscribeUrl = route('public.unsubscribe', ['token' => $sub->unsubscribe_token]);

        Mail::to($sub->email)->send(
            new StaffBroadcastMail($notification, $unsubscribeUrl)
        );
    }

    public function failed(\Throwable $e): void
    {
        // Forward to Nightwatch so the failure is observable by notification_id.
        report($e);

        Log::error('Staff broadcast email permanently failed', [
            'notification_id' => $this->notificationId,
            'subscription_id' => $this->subscriptionId,
            'message' => $e->getMessage(),
        ]);
    }
}
