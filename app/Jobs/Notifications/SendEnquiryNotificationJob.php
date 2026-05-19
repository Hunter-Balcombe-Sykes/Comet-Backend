<?php

namespace App\Jobs\Notifications;

use App\Mail\SiteEnquiryNotification;
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
        public readonly string $notificationEmail,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        // Lock the enquiry row so two concurrent workers (retry overlapping with the
        // original, or Horizon scale-out) can't both see email_sent_at = null and
        // both deliver the email. Mirrors SendTransactionalNotificationEmailJob.
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

        Mail::to($this->notificationEmail)->send(new SiteEnquiryNotification($enquiry));

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
            'error' => $e->getMessage(),
        ]);
    }
}
