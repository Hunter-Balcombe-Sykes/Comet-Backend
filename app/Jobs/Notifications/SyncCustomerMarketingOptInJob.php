<?php

namespace App\Jobs\Notifications;

use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\User\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// No capability gate: operates on Customer, not Professional — account_type gating does not apply.
// V2: Asynchronously refreshes Customer.marketing_opt_in_cached after an EmailSubscription save.
// EmailSubscription is the source of truth; the cached column on Customer is a UX/perf shortcut
// that isMarketingOptedIn() falls back to a live lookup for when null — so a few seconds of
// staleness from the queue is well within the read API's tolerance.
//
// B3/P1-10: ctor payload is UUIDs only. The customer email and subscribed bool are
// derived inside handle() by reading the persisted EmailSubscription row, so the
// Redis-serialised job payload never contains PII. If the subscription row was
// erased between dispatch and handle (GDPR pseudonymise raced ahead), the lookup
// returns null and the job no-ops — exactly the desired post-erasure behaviour.
class SyncCustomerMarketingOptInJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Surface deterministic failures fast — fail after 2 consecutive throws
    // instead of burning the full backoff window before Horizon alerts.
    public int $maxExceptions = 2;

    public int $backoff = 30;

    public int $timeout = 30;

    // R3-JOB-5: dedupe concurrent syncs for the same subscription so the cached
    // column can't be overwritten by a stale race. 300s (10x $timeout), matching
    // the sibling $timeout=30 notification jobs (e.g. DispatchEnquiryNotificationsJob)
    // — long enough that a crashed worker's lock always outlives its own run, short
    // enough that a genuinely stuck lock clears well before the next subscription edit.
    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $userId,
        public readonly string $subscriptionId,
    ) {
        $this->onQueue(config('partna.queues.notifications', 'notifications'));
    }

    // Keyed on the EmailSubscription row, not the user — two subscriptions for the
    // same user (rare, but possible mid-migration) must not dedupe against each other.
    public function uniqueId(): string
    {
        return $this->subscriptionId;
    }

    public function handle(): void
    {
        $subscription = EmailSubscription::query()->find($this->subscriptionId);
        if (! $subscription) {
            // Subscription row gone (e.g., GDPR erasure ran between dispatch and
            // handle, or list was admin-deleted). Quiet no-op — no source of
            // truth to read from, no PII to surface.
            return;
        }

        $customer = Customer::query()
            ->where('user_id', $subscription->user_id)
            ->where('email', $subscription->email)
            ->first();

        if (! $customer) {
            // No matching Customer yet — the cache fallback in isMarketingOptedIn()
            // will resolve this from the live EmailSubscription row when one is created.
            return;
        }

        $customer->marketing_opt_in_cached = $subscription->status === 'subscribed';
        $customer->saveQuietly();
    }

    // §28.17 JOB-3 — explicit failed() so Nightwatch sees retry exhaustion.
    // B3/P1-11 — UUIDs only in the log context; email lives in the DB, not in logs.
    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('notifications.sync_customer_marketing_opt_in.failed', [
            'user_id' => $this->userId,
            'subscription_id' => $this->subscriptionId,
            'error' => $e->getMessage(),
        ]);
    }
}
