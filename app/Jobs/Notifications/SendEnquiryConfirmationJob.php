<?php

namespace App\Jobs\Notifications;

use App\Mail\Branding\ProEmailBrandResolver;
use App\Mail\EnquiryConfirmationMail;
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
use Illuminate\Support\Facades\RateLimiter;

// Sends the visitor-facing "we received your enquiry" confirmation to the
// person who submitted the contact form. No capability gate: public-submission
// origin, exactly like SendEnquiryNotificationJob. UUID-only payload — the
// visitor's email is re-fetched at handle() time so it never sits in Redis.
class SendEnquiryConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public array $backoff = [30, 90, 180];

    public int $timeout = 30;

    public function __construct(public readonly string $enquiryId)
    {
        $this->onQueue(config('partna.queues.notifications', 'notifications'));
    }

    public function handle(): void
    {
        // Lock + idempotency check in one transaction (mirrors SendEnquiryNotificationJob).
        $enquiry = DB::transaction(function () {
            $e = Enquiry::query()->lockForUpdate()->find($this->enquiryId);
            if ($e === null) {
                return null;
            }
            if ($e->confirmation_sent_at !== null) {
                return false;
            }

            // Stamp the idempotency flag while the row lock is still held so the
            // check-and-set is atomic. A concurrent worker (Horizon scale-out or a
            // retry) then reads the committed timestamp and bails instead of
            // double-sending. The mail send happens AFTER this commit, never inside
            // the lock. This is a deliberate at-most-once choice: if the send later
            // throws, the retry skips it (no double-send) rather than guaranteeing
            // delivery — permanent failures surface via report() in failed().
            $e->forceFill(['confirmation_sent_at' => now()])->saveQuietly();

            return $e;
        });

        if ($enquiry === null) {
            Log::warning('SendEnquiryConfirmationJob: enquiry not found', ['enquiry_id' => $this->enquiryId]);

            return;
        }
        if ($enquiry === false) {
            return; // already confirmed on a previous attempt
        }

        $recipient = trim((string) $enquiry->email);
        if ($recipient === '') {
            return; // redacted / no email — nothing to confirm
        }

        // Contact block holds the per-block toggle + the pro's reply-to inbox.
        $block = Block::query()
            ->where('site_id', $enquiry->site_id)
            ->where('block_group', 'sections')
            ->where('block_type', 'contact')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if ($block !== null && data_get($block->settings, 'send_visitor_confirmation', true) === false) {
            return; // professional disabled visitor confirmations
        }

        if (! $this->withinRateLimit($recipient)) {
            return;
        }

        // Resolve the white-label brand AFTER the idempotency transaction has
        // committed — never inside the lockForUpdate hold above. A branding
        // failure must never drop a transactional email, so fall back to Partna.
        $resolver = app(ProEmailBrandResolver::class);
        try {
            $brand = $resolver->forSite((string) $enquiry->site_id);
        } catch (\Throwable $e) {
            Log::warning('email brand resolve failed; falling back to Partna brand', [
                'site_id' => (string) $enquiry->site_id,
                'error' => $e->getMessage(),
            ]);
            $brand = $resolver->partna();
        }

        // confirmation_sent_at was already stamped atomically under the lock above.
        Mail::to($recipient)->send(new EnquiryConfirmationMail(
            brand: $brand,
            visitorName: trim((string) ($enquiry->name ?? '')),
            subject: (string) $enquiry->subject,
        ));
    }

    // Per-recipient hourly cap (shared bucket with the subscription confirmation),
    // keyed by a hash so no raw email lands in a Redis key.
    private function withinRateLimit(string $email): bool
    {
        $key = 'visitor_confirmation:'.hash('sha256', strtolower(trim($email)));
        $limit = (int) config('partna.throttle.visitor_confirmation_per_hour', 5);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            Log::warning('SendEnquiryConfirmationJob: visitor confirmation rate limit exceeded', ['key' => $key]);

            return false;
        }
        RateLimiter::hit($key, 3600);

        return true;
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        Log::error('SendEnquiryConfirmationJob failed permanently', [
            'enquiry_id' => $this->enquiryId,
            'error' => $e->getMessage(),
            'job_id' => $this->job?->getJobId(),
            'attempt' => $this->attempts(),
        ]);
    }
}
