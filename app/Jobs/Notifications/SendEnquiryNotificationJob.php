<?php

namespace App\Jobs\Notifications;

use App\Mail\SiteEnquiryNotification;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
class SendEnquiryNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Surface deterministic failures fast — fail after 2 consecutive throws
    // instead of burning the full backoff window before Horizon alerts.
    public int $maxExceptions = 2;

    public array $backoff = [30, 90, 180];

    public int $timeout = 30;

    // Must exceed $timeout and the worst-case tries+backoff span (~210s). The
    // enquiry is a one-shot UUID, so a stranded lock (OOM/deploy kill) only
    // blocks re-dispatch of an attempt already lost with the killed worker —
    // cost is low. 300 matches DispatchEnquiryNotificationsJob (same directory,
    // same enquiry-UUID key). See #SEM-10 reversal note below.
    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $enquiryId,
        public readonly string $blockId,
    ) {
        $this->onQueue(config('partna.queues.notifications', 'notifications'));
    }

    public function uniqueId(): string
    {
        return $this->enquiryId;
    }

    public function handle(): void
    {
        // #SEM-10 reversal: the idempotency stamp used to be written HERE, inside
        // the lock, before the send — an at-most-once choice under which a failed
        // send stayed "sent" forever, masking the drop from failed()/Nightwatch
        // despite this job's own (now-corrected) claim that failures surface
        // there. The stamp now moves to after a successful Mail::send() below,
        // matching SendTransactionalNotificationEmailJob's at-least-once shape.
        // The transaction is a read-only guard now; duplicate-dispatch
        // protection is ShouldBeUnique (above) plus the durable post-send stamp.
        $enquiry = DB::transaction(function () {
            $e = Enquiry::query()->lockForUpdate()->find($this->enquiryId);
            if ($e === null) {
                return null;
            }
            if ($e->email_sent_at !== null) {
                return false;
            }

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

        Mail::to(trim($notificationEmail))->send(new SiteEnquiryNotification($enquiry));

        // Stamp only after the send succeeds — see #SEM-10 reversal note in handle().
        $enquiry->forceFill(['email_sent_at' => now()])->saveQuietly();
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
