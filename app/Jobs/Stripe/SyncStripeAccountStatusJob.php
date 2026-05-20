<?php

namespace App\Jobs\Stripe;

use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Async wrapper around StripeConnectService::syncAccountStatus.
//
// Previously called inline from StripePlatformWebhookController::handleV2AccountEvent
// (and StripeConnectWebhookController::handleAccountUpdated for the v1 mirror). Inline
// invocation meant the webhook handler made a synchronous Stripe API call before
// returning 200. Stripe's webhook timeout is ~25-30s, so a slow Accounts API hit risked
// timing out the webhook → Stripe retried → dedup hit (or delete-on-failure if the
// retrieve threw) → silencing. Dispatching the sync as a job decouples the two:
// webhook returns 200 in milliseconds; the sync runs with its own retry budget.
//
// ShouldBeUnique prevents thundering-herd if Stripe sends multiple events for the
// same account in quick succession (account.updated + v2.core.account.updated mirror,
// or back-to-back capability state changes).
class SyncStripeAccountStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 60;

    public function __construct(public readonly string $professionalId)
    {
        $this->onQueue('stripe');
    }

    public function uniqueId(): string
    {
        return $this->professionalId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(StripeConnectService $service): void
    {
        $professional = Professional::find($this->professionalId);
        if (! $professional) {
            return;
        }

        // Respect local disconnect — late events shouldn't silently re-activate an
        // account the brand explicitly disconnected from their side.
        if ($professional->stripe_connect_status === 'not_connected') {
            Log::info('stripe.sync_account_status.skipped_not_connected', [
                'professional_id' => $this->professionalId,
            ]);

            return;
        }

        $service->syncAccountStatus($professional);

        Log::info('stripe.sync_account_status.synced', [
            'professional_id' => $this->professionalId,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        report($e);

        Log::error('SyncStripeAccountStatusJob exhausted retries', [
            'professional_id' => $this->professionalId,
            'error' => $e->getMessage(),
        ]);
    }
}
