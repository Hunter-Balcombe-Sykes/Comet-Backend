<?php

namespace App\Services\Stripe;

use App\Jobs\Stripe\ExecuteCommissionPayoutJob;
use App\Models\Brand\BrandStoreSettings;
use App\Models\Commerce\CommissionPayout;
use App\Models\Commerce\CommissionPayoutItem;
use App\Models\Commerce\Order;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\AnalyticsCacheService;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;

/**
 * Commission payouts under Stripe v2 Option A — destination charge to affiliate.
 *
 * A single platform-scope PaymentIntent does two things atomically at settlement:
 *   1. Charges the brand's saved card/BECS account (customer_account=brand_acct, payment_method=brand_pm).
 *   2. Routes funds — application_fee to the platform balance and (gross - fee) to the affiliate
 *      (transfer_data.destination=affiliate_acct).
 *
 * NOTE — no on_behalf_of: Stripe rejects on_behalf_of != transfer_data.destination for card
 * payment_method_types. Phase 13 canary §C2 verdict was platform-as-merchant-of-record (which
 * matches our domain: Partna bills the brand for commission, the card statement showing
 * "Partna" is correct). Consistent with fees_collector=application + losses_collector=application
 * already set on v2 Account creation.
 *
 * Failure paths short-circuit before the PI create. The completion handshake comes from the
 * payment_intent.succeeded webhook on the platform endpoint (see StripePlatformWebhookController).
 *
 * State machine: pending → processing → completed | failed | cancelled. No collecting/transferring
 * states — the legacy 3-step direct-charge chain (PI → Transfer → Transfer) is gone.
 */
class CommissionPayoutService
{
    private StripeClient $stripe;

    private NotificationPublisher $publisher;

    private AnalyticsCacheService $analyticsCache;

    private float $platformFeePercent;

    private int $systemHoldDays;

    private int $gracePeriodDays;

    public function __construct(?StripeClient $stripe = null, ?NotificationPublisher $publisher = null, ?AnalyticsCacheService $analyticsCache = null)
    {
        $this->stripe = $stripe ?? new StripeClient(array_filter([
            'api_key' => config('services.stripe.secret_key'),
            'stripe_version' => config('services.stripe.api_version'),
        ]));
        $this->publisher = $publisher ?? app(NotificationPublisher::class);
        $this->analyticsCache = $analyticsCache ?? app(AnalyticsCacheService::class);
        $this->platformFeePercent = config('partna.store.platform_fee_percent', 3);
        $this->systemHoldDays = max(0, (int) config('partna.store.payout_hold_days', 7));
        // Clamp to [1, 365]. Legacy 'grace_period_days' key is honored for backward compat.
        $this->gracePeriodDays = max(1, min(365, (int) config(
            'partna.store.payout_grace_period_days',
            (int) config('partna.store.grace_period_days', 60),
        )));
    }

    /**
     * Main entry point: find all eligible unpaid commissions, batch them, dispatch a
     * per-payout job for each. Returns dispatch counts only — actual results are reported
     * per-job via Horizon and the payment_intent.* webhooks.
     *
     * @return array{batches_dispatched: int, batches_created: int, batches_requeued: int}
     */
    public function processEligiblePayouts(): array
    {
        $stats = [
            'batches_dispatched' => 0,
            'batches_created' => 0,
            'batches_requeued' => 0,
        ];

        // Resume any in-flight batches. ExecuteCommissionPayoutJob's idempotent flow handles
        // pending/processing states safely — pending re-runs the PI create with the same
        // idempotency key (Stripe returns the original PI if one already exists), processing
        // waits for the webhook.
        $existingPending = CommissionPayout::query()
            ->whereIn('status', ['pending', 'processing'])
            ->whereNull('processed_at')
            ->where('eligible_after', '<=', now())
            ->orderBy('eligible_after')
            ->limit(500)
            ->get();

        foreach ($existingPending as $pendingPayout) {
            ExecuteCommissionPayoutJob::dispatch($pendingPayout->id);
            $stats['batches_dispatched']++;
            $stats['batches_requeued']++;
        }

        // Brand eligibility: must have an ACTIVE v2 Account with a saved PaymentMethod
        // (card or BECS). The dual-capability check (card_payments + stripe_transfers) was
        // already enforced when we set stripe_connect_status='active' via the webhook.
        // Dual-read during §28.1 migration window: match on account_type first,
        // fall back to professional_type for rows not yet backfilled.
        $eligibleBrandIds = Professional::query()
            ->where(fn ($q) => $q
                ->where('account_type', 'brand')
                ->orWhere(fn ($q2) => $q2->whereNull('account_type')->where('professional_type', 'brand'))
            )
            ->whereNotNull('stripe_connect_account_id')
            ->where('stripe_connect_status', 'active')
            ->whereNotNull('stripe_payment_method_id')
            ->pluck('id');

        // Find brands with unpaid approved orders past their grace window. payout_eligible_at
        // is set at order creation (created_at + brand_store_settings.payout_hold_days). Orders
        // with NULL payout_eligible_at (pre-v2 backfill not yet applied) fall back to the
        // per-brand cutoff calculation below.
        $brandIds = Order::query()
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->where('refund_cents', 0)
            ->where('rate_source', '!=', 'pending')
            ->whereIn('brand_professional_id', $eligibleBrandIds)
            ->distinct()
            ->pluck('brand_professional_id');

        $brandSettings = BrandStoreSettings::query()
            ->whereIn('professional_id', $brandIds)
            ->pluck('payout_hold_days', 'professional_id');

        foreach ($brandIds as $brandId) {
            $holdDays = $this->resolveHoldDays($brandSettings[$brandId] ?? null);
            $cutoff = now()->utc()->subDays($holdDays);

            $groups = Order::query()
                ->where('status', 'approved')
                ->whereNull('payout_id')
                ->where('refund_cents', 0)
                ->where('brand_professional_id', $brandId)
                // Per-order grace: new orders use payout_eligible_at directly; old orders
                // (NULL value) fall back to the legacy occurred_at<=cutoff check.
                ->where(function ($q) use ($cutoff) {
                    $q->where('payout_eligible_at', '<=', now())
                        ->orWhere(function ($q2) use ($cutoff) {
                            $q2->whereNull('payout_eligible_at')
                                ->where('occurred_at', '<=', $cutoff);
                        });
                })
                ->select([
                    'brand_professional_id',
                    'affiliate_professional_id',
                    'currency_code',
                    DB::raw('SUM(commission_cents) as total_cents'),
                    DB::raw('COUNT(*) as entry_count'),
                ])
                ->groupBy('brand_professional_id', 'affiliate_professional_id', 'currency_code')
                ->having(DB::raw('SUM(commission_cents)'), '>', 0)
                ->get();

            foreach ($groups as $group) {
                try {
                    $payout = $this->createPayoutBatch(
                        $group->brand_professional_id,
                        $group->affiliate_professional_id,
                        $group->currency_code,
                        $cutoff,
                    );

                    if (! $payout) {
                        continue;
                    }

                    $stats['batches_created']++;
                    ExecuteCommissionPayoutJob::dispatch($payout->id);
                    $stats['batches_dispatched']++;
                } catch (\Throwable $e) {
                    Log::error('Commission payout batch creation failed', [
                        'brand_id' => $group->brand_professional_id,
                        'affiliate_id' => $group->affiliate_professional_id,
                        'currency' => $group->currency_code,
                        'error' => $e instanceof ApiErrorException ? ($e->getStripeCode() ?? 'stripe_error') : get_class($e),
                    ]);
                }
            }
        }

        return $stats;
    }

    /**
     * Resolve the effective hold days for a brand. Brand override > system default.
     * 0 (instant) is a legitimate value — UpdateBrandStoreSettingsRequest constrains
     * brand input to 0/7/14/28 so there's no need for a server-side floor.
     */
    private function resolveHoldDays(?int $brandPayoutHoldDays): int
    {
        return max(0, $brandPayoutHoldDays ?? $this->systemHoldDays);
    }

    /**
     * Create a payout batch record and link all eligible orders inside a lockForUpdate
     * transaction so a concurrent sweep cannot double-claim the same orders.
     *
     * The forceCreate is additionally protected by the `commission_payouts_natural_key_uq`
     * partial UNIQUE index — keyed on
     * (brand_professional_id, affiliate_professional_id, currency_code, eligible_after::date)
     * for non-terminal statuses. If a concurrent path bypasses the row lock and inserts
     * first, the second writer loses with a UniqueConstraintViolationException and we
     * treat the conflict as "another worker created this batch" by returning null.
     * The date-bucketed expression in the index is required because eligible_after is
     * set from now()->utc()->subDays(...) at microsecond precision; without bucketing
     * the index would never catch two same-day sweeps.
     */
    private function createPayoutBatch(
        string $brandId,
        string $affiliateId,
        string $currency,
        \DateTimeInterface $cutoff,
    ): ?CommissionPayout {
        try {
            return $this->createPayoutBatchTransactional($brandId, $affiliateId, $currency, $cutoff);
        } catch (UniqueConstraintViolationException $e) {
            // Partial-unique conflict on (brand_professional_id, affiliate_professional_id,
            // currency_code, eligible_after::date) for a non-terminal payout — another
            // sweep beat us to it. The transaction has already rolled back, so no orders
            // were linked.
            Log::warning('Commission payout batch lost natural-key race; treating as created by concurrent worker', [
                'brand_id' => $brandId,
                'affiliate_id' => $affiliateId,
                'currency' => $currency,
                'cutoff' => $cutoff->format(\DateTimeInterface::ATOM),
            ]);

            return null;
        }
    }

    private function createPayoutBatchTransactional(
        string $brandId,
        string $affiliateId,
        string $currency,
        \DateTimeInterface $cutoff,
    ): ?CommissionPayout {
        return DB::transaction(function () use ($brandId, $affiliateId, $currency, $cutoff) {
            $orders = Order::query()
                ->where('status', 'approved')
                ->whereNull('payout_id')
                ->where('refund_cents', 0)
                ->where('brand_professional_id', $brandId)
                ->where('affiliate_professional_id', $affiliateId)
                ->where('currency_code', $currency)
                ->where(function ($q) use ($cutoff) {
                    $q->where('payout_eligible_at', '<=', now())
                        ->orWhere(function ($q2) use ($cutoff) {
                            $q2->whereNull('payout_eligible_at')
                                ->where('occurred_at', '<=', $cutoff);
                        });
                })
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                return null;
            }

            $grossCents = (int) $orders->sum('commission_cents');
            if ($grossCents <= 0) {
                return null;
            }

            $platformFeeCents = (int) round($grossCents * $this->platformFeePercent / 100);
            $netPayoutCents = $grossCents - $platformFeeCents;

            if ($netPayoutCents <= 0) {
                return null;
            }

            $payout = CommissionPayout::forceCreate([
                'brand_professional_id' => $brandId,
                'affiliate_professional_id' => $affiliateId,
                'status' => 'pending',
                'gross_commission_cents' => $grossCents,
                'platform_fee_cents' => $platformFeeCents,
                'net_payout_cents' => $netPayoutCents,
                'currency_code' => strtoupper($currency),
                'ledger_entry_count' => $orders->count(),
                'eligible_after' => $cutoff,
                'void_at' => now()->addDays($this->gracePeriodDays),
            ]);

            foreach ($orders as $order) {
                CommissionPayoutItem::create([
                    'payout_id' => $payout->id,
                    'order_id' => $order->id,
                    'amount_cents' => $order->commission_cents,
                ]);
            }

            Order::whereIn('id', $orders->pluck('id')->all())
                ->update(['payout_id' => $payout->id]);

            return $payout;
        });
    }

    /**
     * Re-validate orders linked to a pending payout against the current DB state.
     *
     * A refund webhook arriving between createPayoutBatch and the Stripe PI create could
     * flip orders ineligible. This check runs inside a lockForUpdate transaction so a
     * concurrent refund cannot race past us. Stale orders are released back to the next
     * sweep, the batch is rebuilt from the remaining valid orders, and if nothing valid
     * remains the payout is cancelled.
     */
    private function revalidatePayoutOrders(CommissionPayout $payout): ?CommissionPayout
    {
        return DB::transaction(function () use ($payout) {
            $orders = Order::query()
                ->where('payout_id', $payout->id)
                ->lockForUpdate()
                ->get();

            [$validOrders, $staleOrders] = $orders->partition(
                fn ($o) => $o->status === 'approved' && (int) $o->refund_cents === 0
            );

            if ($staleOrders->isEmpty()) {
                return $payout;
            }

            $staleIds = $staleOrders->pluck('id')->all();
            Order::whereIn('id', $staleIds)->update(['payout_id' => null]);
            CommissionPayoutItem::where('payout_id', $payout->id)
                ->whereIn('order_id', $staleIds)
                ->delete();

            if ($validOrders->isEmpty()) {
                $payout->forceFill(['status' => 'cancelled', 'processed_at' => now()])->save();
                Log::notice('Commission payout cancelled: all linked orders became ineligible before processing', [
                    'payout_id' => $payout->id,
                    'stale_count' => count($staleIds),
                ]);

                return null;
            }

            $grossCents = (int) $validOrders->sum('commission_cents');
            $platformFeeCents = (int) round($grossCents * $this->platformFeePercent / 100);
            $netPayoutCents = $grossCents - $platformFeeCents;

            if ($netPayoutCents <= 0) {
                $validIds = $validOrders->pluck('id')->all();
                Order::whereIn('id', $validIds)->update(['payout_id' => null]);
                CommissionPayoutItem::where('payout_id', $payout->id)
                    ->whereIn('order_id', $validIds)
                    ->delete();
                $payout->forceFill(['status' => 'cancelled', 'processed_at' => now()])->save();
                Log::notice('Commission payout cancelled: net payout became non-positive after order revalidation', [
                    'payout_id' => $payout->id,
                    'gross_cents' => $grossCents,
                ]);

                return null;
            }

            $payout->forceFill([
                'gross_commission_cents' => $grossCents,
                'platform_fee_cents' => $platformFeeCents,
                'net_payout_cents' => $netPayoutCents,
                'ledger_entry_count' => $validOrders->count(),
            ])->save();

            Log::notice('Commission payout batch rebuilt after order revalidation', [
                'payout_id' => $payout->id,
                'stale_count' => count($staleIds),
                'remaining_count' => $validOrders->count(),
                'gross_cents' => $grossCents,
                'net_cents' => $netPayoutCents,
            ]);

            return $payout->fresh();
        });
    }

    /**
     * Process a single payout via the Option A destination charge.
     *
     * One platform-scope PaymentIntent does the whole job. The PI succeeded webhook
     * (StripePlatformWebhookController) marks the payout completed; we leave it in
     * 'processing' here and return null. SCA (requires_action) is treated as a hard
     * failure because off_session card payouts cannot prompt the brand for 3DS.
     *
     * Return values:
     * - true:  PI succeeded synchronously (rare — only for fully off-session card flows
     *          that don't require any extra processing time on Stripe's end)
     * - null:  PI accepted by Stripe and is processing — webhook will complete it
     * - false: hard failure (charge declined, brand misconfigured, idempotent retry exhausted)
     */
    public function processPayoutBatch(CommissionPayout $payout): ?bool
    {
        // Terminal-state guard (defence-in-depth — ExecuteCommissionPayoutJob's
        // handle() guard already short-circuits these before reaching here).
        // Synchronous callers (admin retry path, future flows) must not be able to
        // re-enter PI create on a payout that settled into a terminal state. For
        // BECS payouts the original idempotency key has expired by T+2 — Stripe
        // would issue a second PI, charging the brand twice.
        // retryPayout() resets status to 'pending' BEFORE calling here, so admin
        // retries pass through this guard correctly.
        if (in_array($payout->status, ['completed', 'failed', 'cancelled'], true)) {
            return $payout->status === 'completed';
        }

        // Defence-in-depth: terminal states that should never reach PI creation.
        // The job-level guard catches this first; this layer protects direct callers.
        if (in_array($payout->status, ['failed', 'cancelled'], true)) {
            return false;
        }

        // Re-dispatch guard. The daily sweep re-queues 'processing' payouts so a missed
        // payment_intent.succeeded webhook eventually gets reconciled — but the sweep
        // can fire any number of times during BECS's T+2 settlement window. Stripe's
        // idempotency-key cache is only 24h, so calling PI.create with the same
        // pi_{payout_id} key on day 2 of a BECS payout creates a SECOND PaymentIntent
        // (Stripe forgot the original) — that's a duplicate charge against the brand.
        //
        // If we already have a PI on record and the payout is in flight, no-op and let
        // the webhook complete it. Webhook handlers (markPaymentIntentSucceeded /
        // markPaymentIntentFailed) drive the terminal-state transition.
        if ($payout->status === 'processing' && $payout->payment_intent_id !== null) {
            return null;
        }

        if ($payout->status === 'pending') {
            $payout = $this->revalidatePayoutOrders($payout);
            if ($payout === null) {
                return null;
            }
        }

        $brand = Professional::find($payout->brand_professional_id);
        $affiliate = Professional::find($payout->affiliate_professional_id);

        if (! $brand) {
            $this->failPayout($payout, 'brand_missing', 'Brand account was not found.');

            return false;
        }

        if (! $affiliate?->stripe_connect_account_id || $affiliate->stripe_connect_status !== 'active') {
            $this->failPayout($payout, 'affiliate_not_connected', 'Affiliate Stripe Connect account is not active.');

            return false;
        }

        if (
            ! $brand->stripe_connect_account_id
            || $brand->stripe_connect_status !== 'active'
        ) {
            $this->failPayout(
                $payout,
                'brand_not_ready',
                'Brand has not completed Stripe Connect onboarding.',
            );

            return false;
        }

        // Phase 4 — multi-PM selection. preferred → BECS-if-present → card → legacy fallback.
        $resolved = $this->resolveBrandPaymentMethod($brand);
        if ($resolved === null) {
            $this->failPayout(
                $payout,
                'brand_not_ready',
                'Brand has no payment method on file.',
            );

            return false;
        }

        if ($payout->net_payout_cents <= 0) {
            $this->failPayout($payout, 'net_payout_zero', 'Net payout amount is zero.');

            return false;
        }

        $retryKey = $payout->retry_count > 0 ? '_r'.$payout->retry_count : '';
        $paymentMethodType = $resolved['type'];
        $paymentMethodId = $resolved['id'];
        $currencyLower = strtolower($payout->currency_code);

        $payout->forceFill([
            'status' => 'processing',
            'failure_code' => null,
            'failure_reason' => null,
        ])->save();

        try {
            // Platform-scope create (no stripe_account header).
            //   customer_account: brand's v2 Account (NOT a v1 'customer' — the Account IS the customer).
            //   transfer_data.destination: (gross - application_fee) routes to the affiliate at settlement.
            //   application_fee_amount:    routes our cut to the platform balance.
            // Stripe handles fund movement atomically; we never call transfers->create.
            //
            // No on_behalf_of: Stripe rejects on_behalf_of != transfer_data.destination for card
            // (and card_present) payment_method_types — "must settle in the country of the
            // destination account". Plan §C2 canary verdict: platform becomes merchant of record
            // for the commission charge, which is correct for our domain (Partna bills the brand
            // for affiliate commission; the card statement showing "Partna" matches reality).
            // Consistent with fees_collector=application + losses_collector=application already
            // set at v2 Account creation.
            $pi = $this->stripe->paymentIntents->create([
                'amount' => $payout->gross_commission_cents,
                'currency' => $currencyLower,
                'customer_account' => $brand->stripe_connect_account_id,
                'payment_method' => $paymentMethodId,
                'payment_method_types' => [$paymentMethodType],
                'confirm' => true,
                'off_session' => true,
                'transfer_data' => ['destination' => $affiliate->stripe_connect_account_id],
                'application_fee_amount' => $payout->platform_fee_cents,
                'description' => "Commission payout #{$payout->id}",
                'metadata' => [
                    'sidest_payout_id' => $payout->id,
                    'brand_id' => $brand->id,
                    'affiliate_id' => $affiliate->id,
                    'batch_date' => now()->toDateString(),
                ],
            ], [
                'idempotency_key' => 'pi_'.$payout->id.$retryKey,
            ]);

            $payout->forceFill([
                'payment_intent_id' => $pi->id,
                'charge_id' => $this->extractLatestChargeId($pi),
            ])->save();

            if ($pi->status === 'succeeded') {
                $this->markCompleted($payout, $brand, $affiliate);

                return true;
            }

            if ($pi->status === 'requires_action') {
                // off_session card payouts cannot prompt for 3DS — treat as a hard failure.
                // BECS PIs go 'processing' → succeeded T+2, which is the normal path.
                $this->failPayout($payout, 'charge_requires_action', 'Card charge requires authentication — cannot proceed off-session.');

                return false;
            }

            // 'processing' (BECS) or 'requires_capture'/other intermediate state — webhook will resolve.
            Log::info('Commission payout PI accepted, awaiting webhook', [
                'payout_id' => $payout->id,
                'payment_intent_id' => $pi->id,
                'pi_status' => $pi->status,
                'payout_method' => $paymentMethodType,
            ]);

            return null;
        } catch (ApiConnectionException|RateLimitException $e) {
            // Transient — re-throw so Horizon retries. The idempotency key ensures Stripe
            // returns the original PI on retry rather than creating a duplicate. Status
            // stays at 'processing'.
            throw $e;
        } catch (ApiErrorException $e) {
            $this->failPayout(
                $payout,
                $e->getStripeCode() ?? 'stripe_error',
                $this->formatStripeError($e),
            );

            return false;
        }
    }

    /**
     * Webhook hook: payment_intent.succeeded on the platform scope advances the payout.
     * Called by StripePlatformWebhookController. Idempotent — skips if already completed.
     *
     * After marking the payout completed, enriches the affiliate-visible Stripe objects
     * (Transfer + destination_payment) with brand identity so the affiliate's Express
     * dashboard shows where the payment came from. Enrichment is best-effort — failure
     * logs but does not roll back the payout completion.
     */
    public function markPaymentIntentSucceeded(CommissionPayout $payout, ?string $chargeId = null): void
    {
        if ($payout->status === 'completed') {
            return;
        }

        if ($chargeId !== null && $payout->charge_id === null) {
            $payout->forceFill(['charge_id' => $chargeId])->save();
        }

        $brand = Professional::find($payout->brand_professional_id);
        $affiliate = Professional::find($payout->affiliate_professional_id);

        if (! $brand || ! $affiliate) {
            Log::error('payment_intent.succeeded for payout with missing brand/affiliate', [
                'payout_id' => $payout->id,
            ]);

            return;
        }

        $this->markCompleted($payout, $brand, $affiliate);

        // Best-effort enrichment of the affiliate-side Stripe objects so their Express
        // dashboard transaction shows the brand name + payout context. Failure here is
        // non-fatal: the payout is already marked completed and the underlying financial
        // record is intact regardless of whether Stripe display fields are set.
        try {
            $this->enrichAffiliateTransactionView($payout->fresh(), $brand, $affiliate);
        } catch (\Throwable $e) {
            Log::warning('payout.enrich_affiliate_view_failed', [
                'payout_id' => $payout->id,
                'payment_intent_id' => $payout->payment_intent_id,
                'charge_id' => $payout->charge_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Populate description + metadata on the Transfer (platform-side) and the
     * destination_payment (affiliate-side, what their Express dashboard reads).
     *
     * Stripe does not auto-propagate the platform PI's description/metadata to either
     * the Transfer or destination_payment — they're separate Stripe objects scoped to
     * the connected account. We write to both: the Transfer for platform-side ops
     * visibility, and the destination_payment (via Stripe-Account header) for the
     * affiliate's own dashboard view. Idempotent — re-running sets the same values.
     */
    private function enrichAffiliateTransactionView(
        CommissionPayout $payout,
        Professional $brand,
        Professional $affiliate,
    ): void {
        if (! $payout->charge_id) {
            return;
        }

        // Expand `transfer` so we get the Transfer object inline and avoid a second
        // round-trip just to read destination_payment off it.
        $charge = $this->stripe->charges->retrieve($payout->charge_id, [
            'expand' => ['transfer'],
        ]);

        if (! is_object($charge)) {
            return;
        }

        $transferObject = is_object($charge->transfer ?? null) ? $charge->transfer : null;
        $transferId = $transferObject?->id
            ?? (is_string($charge->transfer ?? null) ? $charge->transfer : null);
        $destinationPaymentId = $transferObject?->destination_payment ?? null;

        $description = $this->buildAffiliateFacingDescription($brand, $payout);
        $metadata = [
            'sidest_brand_handle' => (string) ($brand->handle ?? ''),
            'sidest_brand_name' => (string) ($brand->display_name ?? ''),
            'sidest_payout_id' => $payout->id,
            'sidest_orders_count' => (string) $payout->ledger_entry_count,
            'sidest_batch_date' => now()->toDateString(),
        ];

        if ($transferId) {
            $this->stripe->transfers->update($transferId, [
                'description' => $description,
                'metadata' => $metadata,
            ]);
        }

        if ($destinationPaymentId && $affiliate->stripe_connect_account_id) {
            // Stripe-Account header scopes this Charges API call to the affiliate's
            // connected account, where destination_payment lives. Without the header
            // we'd hit a 404 — destination_payment objects are not visible on the
            // platform account directly.
            $this->stripe->charges->update(
                $destinationPaymentId,
                [
                    'description' => $description,
                    'metadata' => $metadata,
                ],
                ['stripe_account' => $affiliate->stripe_connect_account_id],
            );
        }
    }

    /**
     * Human-readable description for the affiliate's Express dashboard. Stripe caps
     * description at 1024 chars; we stay compact so it renders cleanly in the
     * transaction-detail header.
     */
    private function buildAffiliateFacingDescription(Professional $brand, CommissionPayout $payout): string
    {
        $brandName = trim((string) ($brand->display_name ?? $brand->handle ?? 'a Partna brand'));
        if ($brandName === '') {
            $brandName = 'a Partna brand';
        }

        $shortPayoutId = substr((string) $payout->id, 0, 8);
        $orderCount = (int) $payout->ledger_entry_count;
        $orderSuffix = $orderCount === 1 ? 'order' : 'orders';

        return sprintf(
            'Partna commission from %s (payout %s, %d %s)',
            $brandName,
            $shortPayoutId,
            $orderCount,
            $orderSuffix,
        );
    }

    /**
     * Webhook hook: payment_intent.payment_failed on the platform scope fails the payout.
     * Called by StripePlatformWebhookController. Preserves payment_intent_id for audit.
     */
    public function markPaymentIntentFailed(CommissionPayout $payout, string $code, string $message): void
    {
        if (in_array($payout->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $this->failPayout($payout, $code, $message);
    }

    private function markCompleted(CommissionPayout $payout, Professional $brand, Professional $affiliate): void
    {
        $payout->forceFill([
            'status' => 'completed',
            'processed_at' => now(),
            'transfer_completed_at' => now(),
            'failure_code' => null,
            'failure_reason' => null,
        ])->save();

        $this->analyticsCache->bumpAnalyticsVersion($brand->id);
        $this->analyticsCache->bumpAnalyticsVersion($affiliate->id);

        Log::info('Commission payout completed', [
            'payout_id' => $payout->id,
            'gross_cents' => $payout->gross_commission_cents,
            'platform_fee_cents' => $payout->platform_fee_cents,
            'net_cents' => $payout->net_payout_cents,
            'currency' => $payout->currency_code,
            'payment_intent_id' => $payout->payment_intent_id,
        ]);
    }

    private function failPayout(CommissionPayout $payout, string $code, string $reason): void
    {
        // Release orders so the next sweep can re-batch them under the same or a different affiliate.
        // Delete the payout_items rows too — the cpi_unique_order partial index allows each order in
        // at most one item row, so leaving them behind blocks re-batching even though orders.payout_id
        // is null. The next sweep would hit a UniqueConstraintViolationException on CommissionPayoutItem
        // create. Cleanup mirrored in ExecuteCommissionPayoutJob::failed for the queue-exhausted path.
        CommissionPayoutItem::where('payout_id', $payout->id)->delete();
        Order::where('payout_id', $payout->id)->update(['payout_id' => null]);

        $payout->forceFill([
            'status' => 'failed',
            'failure_code' => $code,
            'failure_reason' => $reason,
            'processed_at' => now(),
        ])->save();

        Log::warning('Commission payout failed', [
            'payout_id' => $payout->id,
            'code' => $code,
            'reason' => $reason,
            'payment_intent_id' => $payout->payment_intent_id,
        ]);
    }

    /**
     * Manually retry a failed payout (admin endpoint).
     *
     * Increments retry_count so a fresh Stripe idempotency key is used — without this
     * Stripe would return the original failed/declined PI from within the 24-hour key
     * TTL window. Stale failure metadata is cleared so the next attempt records the
     * fresh outcome cleanly.
     *
     * Runs synchronously so the admin sees the result immediately.
     */
    public function retryPayout(CommissionPayout $payout): bool
    {
        if (! in_array($payout->status, ['failed', 'cancelled'], true)) {
            return false;
        }

        // Release any orders that were detached on the prior failure so they can be
        // re-claimed by this attempt's revalidatePayoutOrders path. The orders that
        // were on this payout last attempt may or may not still be eligible.
        $payout->forceFill([
            'status' => 'pending',
            'failure_code' => null,
            'failure_reason' => null,
            'retry_count' => ($payout->retry_count ?? 0) + 1,
            'processed_at' => null,
        ])->save();

        return $this->processPayoutBatch($payout) === true;
    }

    /**
     * Get payout summary for a professional (as brand or affiliate).
     */
    public function getPayoutSummary(Professional $professional): array
    {
        $asBrand = CommissionPayout::query()
            ->where('brand_professional_id', $professional->id)
            ->selectRaw('status, COUNT(*) as count, SUM(gross_commission_cents) as total_cents')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $asAffiliate = CommissionPayout::query()
            ->where('affiliate_professional_id', $professional->id)
            ->selectRaw('status, COUNT(*) as count, SUM(net_payout_cents) as total_cents')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'as_brand' => $asBrand,
            'as_affiliate' => $asAffiliate,
        ];
    }

    /**
     * Pull the charge ID from a PI response. latest_charge is either a string ID or an
     * expanded Charge object depending on the request. May be null for PIs in 'processing'
     * (e.g. BECS pre-settlement) — populated later by charge.refunded reconciliation.
     */
    private function extractLatestChargeId(object $paymentIntent): ?string
    {
        if (is_string($paymentIntent->latest_charge ?? null) && $paymentIntent->latest_charge !== '') {
            return $paymentIntent->latest_charge;
        }

        if (is_object($paymentIntent->latest_charge ?? null) && is_string($paymentIntent->latest_charge->id ?? null)) {
            return $paymentIntent->latest_charge->id;
        }

        return null;
    }

    private function formatStripeError(ApiErrorException $e): string
    {
        $code = $e->getStripeCode() ?? 'stripe_error';
        $message = $e->getMessage();
        $requestId = $e->getRequestId() ?? 'no_request_id';

        return sprintf('[%s] %s (request_id=%s)', $code, $message, $requestId);
    }

    /**
     * Phase 4 — multi-PM resolution at PI-create time. Mirrors StripeConnectService's
     * public resolver; inlined here to avoid a circular service dependency between
     * Stripe services.
     *
     * Selection order:
     *   1. preferred_payout_method when set AND that type's id is present
     *   2. BECS if becs_payment_method_id is present
     *   3. card if card_payment_method_id is present
     *   4. legacy stripe_payment_method_id (rows not migrated yet)
     *
     * @return array{id: string, type: string}|null
     */
    private function resolveBrandPaymentMethod(Professional $brand): ?array
    {
        $cardId = $brand->stripe_card_payment_method_id ?? null;
        $becsId = $brand->stripe_becs_payment_method_id ?? null;
        $preferred = $brand->preferred_payout_method ?? null;

        if ($preferred === 'card' && $cardId) {
            return ['id' => $cardId, 'type' => 'card'];
        }
        if ($preferred === 'becs' && $becsId) {
            return ['id' => $becsId, 'type' => 'au_becs_debit'];
        }

        if ($becsId) {
            return ['id' => $becsId, 'type' => 'au_becs_debit'];
        }
        if ($cardId) {
            return ['id' => $cardId, 'type' => 'card'];
        }

        if (! empty($brand->stripe_payment_method_id)) {
            $type = ($brand->payout_method ?? null) === 'becs' ? 'au_becs_debit' : 'card';

            return ['id' => $brand->stripe_payment_method_id, 'type' => $type];
        }

        return null;
    }
}
