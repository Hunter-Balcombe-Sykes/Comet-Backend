<?php

namespace App\Jobs\Stripe;

use App\Models\Commerce\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Reconciles payouts stuck in 'processing' against Stripe's PaymentIntent state.
 *
 * Scenario this closes: the payment_intent.succeeded webhook was permanently lost
 * — either Stripe's retry window exhausted, or a transient handler failure that
 * delete-on-failure (STRP-C) couldn't recover from before Stripe stopped retrying.
 * The payout sits in 'processing' indefinitely; the daily sweep re-queues it but
 * CommissionPayoutService::processPayoutBatch correctly no-ops because the
 * payment_intent_id is set (preventing duplicate PIs but offering no recovery).
 *
 * This job pulls the actual PI status from Stripe directly and advances the payout
 * state by calling the same markPaymentIntentSucceeded / markPaymentIntentFailed
 * paths the webhook would have used.
 *
 * Threshold: 3 days. BECS settles T+2 (48h); card PIs resolve same-day. A 3-day-old
 * 'processing' payout is unambiguously stuck regardless of payout method.
 */
class ReconcileStuckPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // No backoff — tries=1 means no retry, so backoff is moot, but required for hygiene.
    public int $backoff = 0;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('stripe');
    }

    public function handle(CommissionPayoutService $payoutService): void
    {
        $stuck = CommissionPayout::query()
            ->where('status', 'processing')
            ->whereNotNull('payment_intent_id')
            ->where('updated_at', '<', now()->subDays(3))
            ->orderBy('updated_at')
            ->limit(100)
            ->get();

        if ($stuck->isEmpty()) {
            Log::info('ReconcileStuckPayoutsJob nothing to reconcile');

            return;
        }

        $stripe = $this->makeStripeClient();

        $advanced = 0;
        $stillProcessing = 0;
        $errored = 0;

        foreach ($stuck as $payout) {
            try {
                $pi = $stripe->paymentIntents->retrieve($payout->payment_intent_id);
            } catch (\Throwable $e) {
                // §28.17 OBS-3 — report() so Nightwatch surfaces per-payout
                // failures alongside the structured log entry (silent log-only
                // swallowed real Stripe degradation incidents).
                report($e);
                Log::error('ReconcileStuckPayoutsJob retrieve failed', [
                    'payout_id' => $payout->id,
                    'payment_intent_id' => $payout->payment_intent_id,
                    'error' => $e->getMessage(),
                ]);
                $errored++;

                continue;
            }

            $status = (string) ($pi->status ?? '');

            if ($status === 'succeeded') {
                $payoutService->markPaymentIntentSucceeded($payout, $this->extractChargeId($pi));
                Log::warning('ReconcileStuckPayoutsJob recovered succeeded payout', [
                    'payout_id' => $payout->id,
                    'payment_intent_id' => $pi->id,
                    'reason' => 'webhook_lost_or_silenced',
                ]);
                $advanced++;

                continue;
            }

            if (in_array($status, ['requires_payment_method', 'canceled'], true)) {
                $code = (string) (
                    $pi->last_payment_error->code
                        ?? $pi->last_payment_error->decline_code
                        ?? 'reconciliation_terminal'
                );
                $message = (string) (
                    $pi->last_payment_error->message
                        ?? 'PI in terminal failure state detected by reconciliation job.'
                );
                $payoutService->markPaymentIntentFailed($payout, $code, $message);
                Log::warning('ReconcileStuckPayoutsJob marked stuck payout failed', [
                    'payout_id' => $payout->id,
                    'payment_intent_id' => $pi->id,
                    'pi_status' => $status,
                    'code' => $code,
                ]);
                $advanced++;

                continue;
            }

            // Still 'processing' / 'requires_capture' / etc. at Stripe — not stuck on
            // our side, leave for the next reconciliation pass. Heartbeat log lets ops
            // see long-running BECS settlement vs. truly stuck.
            Log::info('ReconcileStuckPayoutsJob still-processing payout heartbeat', [
                'payout_id' => $payout->id,
                'payment_intent_id' => $pi->id,
                'pi_status' => $status,
                'age_hours' => round(now()->diffInHours($payout->updated_at), 1),
            ]);
            $stillProcessing++;
        }

        Log::info('ReconcileStuckPayoutsJob completed', [
            'inspected' => $stuck->count(),
            'advanced' => $advanced,
            'still_processing_at_stripe' => $stillProcessing,
            'errored' => $errored,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        report($e);

        Log::error('ReconcileStuckPayoutsJob exhausted retries', [
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Indirection so tests can mock the Stripe client by overriding this method.
     */
    protected function makeStripeClient(): StripeClient
    {
        return new StripeClient(array_filter([
            'api_key' => config('services.stripe.secret_key'),
            'stripe_version' => config('services.stripe.api_version'),
        ]));
    }

    private function extractChargeId(object $paymentIntent): ?string
    {
        if (is_string($paymentIntent->latest_charge ?? null) && $paymentIntent->latest_charge !== '') {
            return $paymentIntent->latest_charge;
        }

        if (is_object($paymentIntent->latest_charge ?? null) && is_string($paymentIntent->latest_charge->id ?? null)) {
            return $paymentIntent->latest_charge->id;
        }

        return null;
    }
}
