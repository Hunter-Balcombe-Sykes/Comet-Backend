<?php

namespace App\Jobs\Notifications;

use App\Mail\SiteEnquiryNotification;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// V2: Sends the contact-form notification email to the affiliate's configured inbox after an enquiry is saved.
// No capability gate: enquiries originate from public site contact forms and apply to all 3 account types
// (brand, partner, individual) — there is no account_type restriction for this notification path.
//
// B3/P1-10: ctor payload is UUIDs only. The brand's notification_email setting
// lives in the contact block's settings JSON — looked up at handle() time so
// the Redis payload carries no PII. If the block was disabled, deleted, or
// scrubbed of its notification_email between dispatch and handle, the job
// no-ops with a warning log keyed by enquiry_id only.
class SendEnquiryNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Surface deterministic failures fast — fail after 2 consecutive throws
    // instead of burning the full backoff window before Horizon alerts.
    public int $maxExceptions = 2;

    public array $backoff = [30, 90, 180];

    public int $timeout = 30;

    public function __construct(
        public readonly string $enquiryId,
        public readonly string $blockId,
    ) {
        $this->onQueue(config('partna.queues.notifications', 'notifications'));
    }

    public function handle(): void
    {
        // Lock + idempotency check in one transaction (mirrors SendEnquiryConfirmationJob).
        $enquiry = DB::transaction(function () {
            $e = Enquiry::query()->lockForUpdate()->find($this->enquiryId);
            if ($e === null) {
                return null;
            }
            if ($e->email_sent_at !== null) {
                return false;
            }

            // Stamp the idempotency flag while the row lock is still held so the
            // check-and-set is atomic. A concurrent worker (Horizon scale-out or a
            // retry) then reads the committed timestamp and bails instead of
            // double-sending. The mail send happens AFTER this commit, never inside
            // the lock. This is a deliberate at-most-once choice: if the send later
            // throws, the retry skips it (no double-send) rather than guaranteeing
            // delivery — permanent failures surface via report() in failed().
            $e->forceFill(['email_sent_at' => now()])->saveQuietly();

            return $e;
        });

        if ($enquiry === null) {
            Log::warning('SendEnquiryNotificationJob: enquiry not found', [
                'enquiry_id' => $this->enquiryId,
            ]);

            return;
        }

        if ($enquiry === false) {
            return; // already sent on a previous attempt
        }

        // Resolve the notification inbox from the contact block at handle() time
        // so the brand's email never sits in a Redis-serialised job payload.
        $block = Block::query()
            ->whereKey($this->blockId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if ($block === null) {
            Log::warning('SendEnquiryNotificationJob: contact block no longer available', [
                'enquiry_id' => $this->enquiryId,
                'block_id' => $this->blockId,
            ]);

            return;
        }

        $notificationEmail = data_get($block->settings, 'notification_email');
        if (! is_string($notificationEmail) || trim($notificationEmail) === '') {
            Log::warning('SendEnquiryNotificationJob: notification_email no longer configured', [
                'enquiry_id' => $this->enquiryId,
                'block_id' => $this->blockId,
            ]);

            return;
        }

        // email_sent_at was already stamped atomically under the lock above.
        Mail::to(trim($notificationEmail))->send(new SiteEnquiryNotification($enquiry));
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        // Don't log the professional's notification_email — log retention exceeds
        // GDPR/Privacy Act expectations; enquiry_id is sufficient to recover the
        // email from the database during incident response.
        Log::error('SendEnquiryNotificationJob failed permanently', [
            'enquiry_id' => $this->enquiryId,
            'block_id' => $this->blockId,
            'error' => $e->getMessage(),
        ]);
    }
}
