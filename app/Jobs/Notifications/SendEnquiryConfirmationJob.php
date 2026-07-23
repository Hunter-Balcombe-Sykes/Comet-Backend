<?php

namespace App\Jobs\Notifications;

use App\Mail\Branding\ProEmailBrandResolver;
use App\Mail\EnquiryConfirmationMail;
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
use Illuminate\Support\Facades\RateLimiter;

// Sends the visitor-facing "we received your enquiry" confirmation to the
// person who submitted the contact form. No capability gate: public-submission
// origin, exactly like SendEnquiryNotificationJob. UUID-only payload — the
// visitor's email is re-fetched at handle() time so it never sits in Redis.
class SendEnquiryConfirmationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public array $backoff = [30, 90, 180];

    public int $timeout = 30;

    // Must exceed $timeout and the worst-case tries+backoff span (~210s) so the
    // lock outlives a full retry cycle, but stay short enough that a stranded
    // lock (OOM/deploy kill) self-heals quickly — the enquiry is a one-shot UUID,
    // so a strand only blocks re-dispatch of an attempt already lost with the
    // killed worker. 300 matches DispatchEnquiryNotificationsJob (same directory,
    // same key shape) — see #SEM-10 reversal note below.
    public int $uniqueFor = 300;

    public function __construct(public readonly string $enquiryId)
    {
        $this->onQueue(config('partna.queues.notifications', 'notifications'));
    }

    public function uniqueId(): string
    {
        return $this->enquiryId;
    }

    public function handle(): void
    {
        // #SEM-10 reversal: the idempotency stamp used to be written HERE, inside
        // the lock, before the send — that was an at-most-once choice (a failed
        // send stayed "sent" forever, masking the failure from failed()/Nightwatch).
        // The stamp now moves to after a successful Mail::send() below, matching
        // SendTransactionalNotificationEmailJob's at-least-once shape. Because the
        // write leaves the closure, this transaction no longer provides mutual
        // exclusion — the lock releases at commit with nothing written, so two
        // concurrent workers can both read null and both send. Duplicate-dispatch
        // protection is now ShouldBeUnique (above) plus the durable post-send
        // stamp for any later dispatch; the transaction is kept only for
        // shape-convergence with the sibling job.
        $enquiry = DB::transaction(function () {
            $e = Enquiry::query()->lockForUpdate()->find($this->enquiryId);
            if ($e === null) {
                return null;
            }
            if ($e->confirmation_sent_at !== null) {
                return false;
            }

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

        if ($this->rateLimitExceeded($recipient)) {
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

        Mail::to($recipient)->send(new EnquiryConfirmationMail(
            brand: $brand,
            visitorName: trim((string) ($enquiry->name ?? '')),
            subject: (string) $enquiry->subject,
        ));

        // Stamp only after the send succeeds — see #SEM-10 reversal note in handle().
        $enquiry->forceFill(['confirmation_sent_at' => now()])->saveQuietly();

        // Record the rate-limit hit only now that the send actually succeeded.
        // Splitting check-vs-hit closes a drop path the reorder would otherwise
        // create: under the old stamp-before-send order a retry never reached
        // this far (it bailed at the committed stamp), so hitting the limiter
        // pre-send was harmless. Post-reorder, every failed retry re-reaches
        // this code; hitting on each attempt would burn a visitor's hourly
        // tokens on failures alone and could silently drop a later legitimate
        // confirmation. Hitting only on success keeps the cap counting delivered
        // confirmations, exactly as before.
        $this->recordRateLimitHit($recipient);
    }

    // Per-recipient hourly cap (shared bucket with the subscription confirmation),
    // keyed by a hash so no raw email lands in a Redis key.
    private function rateLimitKey(string $email): string
    {
        return 'visitor_confirmation:'.hash('sha256', strtolower(trim($email)));
    }

    private function rateLimitExceeded(string $email): bool
    {
        $key = $this->rateLimitKey($email);
        $limit = (int) config('partna.throttle.visitor_confirmation_per_hour', 5);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            Log::warning('SendEnquiryConfirmationJob: visitor confirmation rate limit exceeded', ['key' => $key]);

            return true;
        }

        return false;
    }

    private function recordRateLimitHit(string $email): void
    {
        RateLimiter::hit($this->rateLimitKey($email), 3600);
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
