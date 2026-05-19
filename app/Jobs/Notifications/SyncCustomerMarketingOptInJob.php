<?php

namespace App\Jobs\Notifications;

use App\Models\Core\Professional\Customer;
use Illuminate\Bus\Queueable;
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
class SyncCustomerMarketingOptInJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Surface deterministic failures fast — fail after 2 consecutive throws
    // instead of burning the full backoff window before Horizon alerts.
    public int $maxExceptions = 2;

    public int $backoff = 30;

    public int $timeout = 30;

    public function __construct(
        public readonly string $professionalId,
        public readonly string $email,
        public readonly bool $subscribed,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $customer = Customer::query()
            ->where('professional_id', $this->professionalId)
            ->where('email', $this->email)
            ->first();

        if (! $customer) {
            // No matching Customer yet — the cache fallback in isMarketingOptedIn()
            // will resolve this from the live EmailSubscription row when one is created.
            return;
        }

        $customer->marketing_opt_in_cached = $this->subscribed;
        $customer->saveQuietly();
    }

    // §28.17 JOB-3 — explicit failed() so Nightwatch sees retry exhaustion.
    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('notifications.sync_customer_marketing_opt_in.failed', [
            'professional_id' => $this->professionalId,
            'email' => $this->email,
            'subscribed' => $this->subscribed,
            'error' => $e->getMessage(),
        ]);
    }
}
