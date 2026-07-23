<?php

namespace App\Jobs\Notifications;

use App\Mail\Branding\ProEmailBrandResolver;
use App\Mail\SubscriptionConfirmationMail;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Site\Block;
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

// Sends the visitor-facing "you're subscribed" confirmation to the person who
// joined a newsletter list. No capability gate: public-submission origin, same
// as SendEnquiryNotificationJob. UUID-only payload — email re-fetched at handle().
class SendSubscriptionConfirmationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public array $backoff = [30, 90, 180];

    public int $timeout = 30;

    // Must exceed $timeout and the worst-case tries+backoff span (~210s). Unlike
    // the enquiry job, this key is a DURABLE subscription row reused across
    // unsubscribe/re-subscribe cycles, so a stranded lock (OOM/deploy kill)
    // black-holes a genuine later opt-in for the whole TTL — 300s bounds that
    // to five minutes. Matches DispatchEnquiryNotificationsJob's value. See
    // #SEM-10 reversal note below.
    public int $uniqueFor = 300;

    public function __construct(public readonly string $subscriptionId)
    {
        $this->onQueue(config('partna.queues.notifications', 'notifications'));
    }

    public function uniqueId(): string
    {
        return $this->subscriptionId;
    }

    public function handle(): void
    {
        // #SEM-10 reversal: see SendEnquiryConfirmationJob::handle() for the full
        // rationale. The stamp moves from inside this lock to after a successful
        // send, so the transaction is now a read-only guard; duplicate-dispatch
        // protection is ShouldBeUnique (above) plus the durable post-send stamp.
        $sub = DB::transaction(function () {
            $s = EmailSubscription::query()->lockForUpdate()->find($this->subscriptionId);
            if ($s === null) {
                return null;
            }
            if ($s->confirmation_sent_at !== null) {
                return false;
            }

            return $s;
        });

        if ($sub === null) {
            Log::warning('SendSubscriptionConfirmationJob: subscription not found', ['subscription_id' => $this->subscriptionId]);

            return;
        }
        if ($sub === false) {
            return; // already confirmed
        }

        // An unsubscribe could have landed between dispatch and run — don't
        // confirm a subscription that is no longer active.
        if ($sub->status !== 'subscribed') {
            return;
        }

        $recipient = trim((string) $sub->email);
        if ($recipient === '') {
            return;
        }

        $user = $sub->user;

        // Newsletter block holds the per-block toggle. Resolved via the pro's site.
        $block = null;
        if ($user && ($site = $user->site)) {
            $block = Block::query()
                ->where('site_id', $site->id)
                ->where('block_group', 'sections')
                ->where('block_type', 'newsletter')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();
        }

        if ($block !== null && data_get($block->settings, 'send_visitor_confirmation', true) === false) {
            return;
        }

        if ($this->rateLimitExceeded($recipient)) {
            return;
        }

        $unsubscribeUrl = route('public.unsubscribe', ['token' => $sub->unsubscribe_token]);

        // Resolve the white-label brand outside any DB lock; fall back to Partna
        // on failure so a branding error never drops the confirmation.
        $resolver = app(ProEmailBrandResolver::class);
        $siteId = ($user && $user->site) ? (string) $user->site->id : null;
        try {
            $brand = $siteId !== null ? $resolver->forSite($siteId) : $resolver->partna();
        } catch (\Throwable $e) {
            Log::warning('email brand resolve failed; falling back to Partna brand', [
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);
            $brand = $resolver->partna();
        }

        Mail::to($recipient)->send(new SubscriptionConfirmationMail(
            brand: $brand,
            unsubscribeUrl: $unsubscribeUrl,
            visitorName: $sub->full_name ?: null,
        ));

        // Stamp only after the send succeeds — see #SEM-10 reversal note in handle().
        $sub->forceFill(['confirmation_sent_at' => now()])->saveQuietly();

        // Record the rate-limit hit only now that the send actually succeeded —
        // see SendEnquiryConfirmationJob::recordRateLimitHit() for the full
        // rationale (shared bucket, must stay logic-identical to that job).
        $this->recordRateLimitHit($recipient);
    }

    private function rateLimitKey(string $email): string
    {
        return 'visitor_confirmation:'.hash('sha256', strtolower(trim($email)));
    }

    private function rateLimitExceeded(string $email): bool
    {
        $key = $this->rateLimitKey($email);
        $limit = (int) config('partna.throttle.visitor_confirmation_per_hour', 5);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            Log::warning('SendSubscriptionConfirmationJob: visitor confirmation rate limit exceeded', ['key' => $key]);

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
        Log::error('SendSubscriptionConfirmationJob failed permanently', [
            'subscription_id' => $this->subscriptionId,
            'error' => $e->getMessage(),
            'job_id' => $this->job?->getJobId(),
            'attempt' => $this->attempts(),
        ]);
    }
}
