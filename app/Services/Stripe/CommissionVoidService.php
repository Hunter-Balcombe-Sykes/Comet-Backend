<?php

namespace App\Services\Stripe;

use App\Models\Commerce\CommissionPayout;
use App\Models\Commerce\CommissionPayoutItem;
use App\Models\Commerce\Order;
use App\Models\Commerce\OrderEvent;
use App\Models\Core\Professional\Professional;
use App\Services\Notifications\NotificationPublisher;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles voiding commissions for affiliates who haven't connected Stripe.
 *
 * Phase 4+ model: a "pending commission" is a commerce.orders row with
 * status='approved' AND payout_id IS NULL. Voiding it sets status='voided';
 * the rollup_apply_delta trigger automatically reverses the rollup deltas.
 * The audit trail goes into commerce.order_events with event_type='voided'.
 *
 * Two phases run on the daily cron:
 *   1. processVoidableCommissions() — void orders past their 30-day window
 *   2. sendGracePeriodWarnings()    — nudge affiliates to connect Stripe
 *
 * Called from ProcessCommissionPayoutsJob after normal payout processing.
 */
class CommissionVoidService
{
    private int $voidWindowDays;

    public function __construct(private readonly NotificationPublisher $publisher)
    {
        $this->voidWindowDays = (int) config('partna.store.commission_void_window_days', 30);
    }

    /**
     * Find and void all approved orders past their void window
     * for affiliates without active Stripe Connect accounts.
     *
     * @return array{voided_count: int, voided_cents: int}
     */
    public function processVoidableCommissions(): array
    {
        $cutoff = now()->utc()->subDays($this->voidWindowDays);
        $stats = ['voided_count' => 0, 'voided_cents' => 0];

        // Pre-fetch inactive affiliate IDs so the orders query uses set membership
        // (hash join) instead of a correlated EXISTS probe per row.
        $inactiveAffiliateIds = Professional::query()
            ->where('stripe_connect_status', '!=', 'active')
            ->pluck('id')
            ->all();

        if ($inactiveAffiliateIds === []) {
            return $stats;
        }

        // Chunk to avoid OOM. Each order is voided with an optimistic update so a
        // concurrent payout-stamp won't get overwritten.
        Order::query()
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->where('occurred_at', '<=', $cutoff)
            ->whereIn('affiliate_professional_id', $inactiveAffiliateIds)
            ->chunkById(500, function ($orders) use (&$stats) {
                foreach ($orders as $order) {
                    try {
                        if ($this->voidOrder($order, 'no_stripe_connected')) {
                            $stats['voided_count']++;
                            $stats['voided_cents'] += (int) $order->commission_cents;
                        }
                    } catch (\Throwable $e) {
                        Log::error('Failed to void commission order', [
                            'order_id' => (string) $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($stats['voided_count'] > 0) {
            Log::info('Commission void processing complete', $stats);
        }

        return $stats;
    }

    /**
     * Void orders that are stuck because the brand has no payment method on file.
     * Mirrors processVoidableCommissions but keyed on brand state instead of affiliate state —
     * processEligiblePayouts filters brands without a card, so their orders never get batched
     * and would otherwise sit forever (the affiliate-side void only fires for inactive affiliates).
     *
     * Reason code: brand_no_payment_method.
     *
     * @return array{voided_count: int, voided_cents: int}
     */
    public function processBrandUnfundedCommissions(): array
    {
        $cutoff = now()->utc()->subDays($this->voidWindowDays);
        $stats = ['voided_count' => 0, 'voided_cents' => 0];

        // Dual-read during §28.1 migration window: match on account_type first,
        // fall back to professional_type for rows not yet backfilled.
        $unfundedBrandIds = Professional::query()
            ->where(fn ($q) => $q
                ->where('account_type', 'brand')
                ->orWhere(fn ($q2) => $q2->whereNull('account_type')->where('professional_type', 'brand'))
            )
            ->whereNull('stripe_payment_method_id')
            ->pluck('id')
            ->all();

        if ($unfundedBrandIds === []) {
            return $stats;
        }

        Order::query()
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->where('occurred_at', '<=', $cutoff)
            ->whereIn('brand_professional_id', $unfundedBrandIds)
            ->chunkById(500, function ($orders) use (&$stats) {
                foreach ($orders as $order) {
                    try {
                        if ($this->voidOrder($order, 'brand_no_payment_method')) {
                            $stats['voided_count']++;
                            $stats['voided_cents'] += (int) $order->commission_cents;
                        }
                    } catch (\Throwable $e) {
                        Log::error('Failed to void brand-unfunded commission order', [
                            'order_id' => (string) $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($stats['voided_count'] > 0) {
            Log::info('Brand-unfunded commission void processing complete', $stats);
        }

        return $stats;
    }

    /**
     * Void a single approved order. Uses an optimistic lock guarded by
     * status='approved' so a concurrent terminal-state transition (refund/cancel)
     * can't be overwritten.
     *
     * Two payout-id contexts:
     *   - $expectedPayoutId === null (default): the order must be unclaimed
     *     (payout_id IS NULL). Used by the 30-day-window void and by
     *     staff/disconnect manual voids — voiding can't proceed once a
     *     payout batch has stamped the row.
     *   - $expectedPayoutId set: the order must be linked to that exact
     *     (just-cancelled) payout. Used by cancelExpiredPayout when the
     *     affiliate didn't activate Stripe Connect within the grace window.
     *
     * Setting status='voided' fires the rollup_apply_delta trigger which subtracts
     * the order's contribution from brand_affiliate_rollup and adds the full
     * commission_cents to reversed_commission_cents.
     *
     * @return bool True if the order was voided, false if it was already claimed.
     */
    public function voidOrder(Order $order, string $reason, ?string $expectedPayoutId = null): bool
    {
        return DB::transaction(function () use ($order, $reason, $expectedPayoutId): bool {
            $query = Order::query()
                ->where('id', $order->id)
                ->where('status', 'approved');

            if ($expectedPayoutId === null) {
                $query->whereNull('payout_id');
            } else {
                $query->where('payout_id', $expectedPayoutId);
            }

            $updated = $query->update(['status' => 'voided']);

            if ($updated === 0) {
                Log::info('Skipped voiding order — status or payout_id changed concurrently', [
                    'order_id' => (string) $order->id,
                    'expected_payout_id' => $expectedPayoutId,
                ]);

                return false;
            }

            // OrderEvent::$guarded = ['*'] — go through forceFill to insert.
            (new OrderEvent)->forceFill([
                'order_id' => $order->id,
                'event_type' => 'voided',
                'source' => str_starts_with($reason, 'staff_manual') ? 'manual' : 'system',
                'shopify_event_id' => null,
                'shopify_triggered_at' => now(),
                'amount_delta_cents' => -1 * (int) $order->commission_cents,
                'metadata' => [
                    'reason' => $reason,
                    'voided_at' => now()->toIso8601String(),
                ],
            ])->save();

            return true;
        });
    }

    /**
     * Cancel commission payouts whose 60-day grace window has expired
     * without the affiliate connecting Stripe Connect.
     *
     * Scans pending payouts past their void_at, checks the affiliate's Stripe status, and
     * cancels if not active. Linked orders are voided in the same transaction so the
     * affiliate's dashboard shows a consistent "expired" state.
     *
     * Under Option A the void path only applies to 'pending' (PI not yet created). Once a
     * payout is 'processing' (PI accepted by Stripe), the void path is a no-op — the PI
     * resolves via the payment_intent.* webhook and the result is whatever Stripe decides.
     *
     * Called from VoidExpiredPayoutsJob (nightly cron).
     *
     * @return array{cancelled_count: int, cancelled_cents: int, voided_entries: int}
     */
    public function processExpiredPayouts(): array
    {
        $stats = ['cancelled_count' => 0, 'cancelled_cents' => 0, 'voided_entries' => 0];

        $inactiveAffiliateIds = Professional::query()
            ->where('stripe_connect_status', '!=', 'active')
            ->pluck('id')
            ->all();

        if ($inactiveAffiliateIds === []) {
            return $stats;
        }

        CommissionPayout::query()
            ->where('status', 'pending')
            ->where('void_at', '<', now())
            ->whereIn('affiliate_professional_id', $inactiveAffiliateIds)
            ->with('brandProfessional:id,display_name')
            ->chunkById(200, function ($payouts) use (&$stats): void {
                foreach ($payouts as $payout) {
                    try {
                        $this->cancelExpiredPayout($payout, $stats);
                    } catch (\Throwable $e) {
                        Log::error('Failed to cancel expired payout', [
                            'payout_id' => $payout->id,
                            'exception' => $e,
                        ]);
                    }
                }
            });

        return $stats;
    }

    /**
     * Cancel a single pending payout and void its linked orders.
     * Optimistic lock guards against an in-flight ExecuteCommissionPayoutJob advancing
     * the status to 'processing' between the SELECT and UPDATE.
     */
    private function cancelExpiredPayout(CommissionPayout $payout, array &$stats): void
    {
        $cancelled = false;

        DB::transaction(function () use ($payout, &$stats, &$cancelled): void {
            // Re-check the affiliate's Stripe Connect status inside the transaction.
            // The pre-fetched inactiveAffiliateIds in processExpiredPayouts can be many
            // minutes stale by the time chunkById reaches the last batch — an affiliate
            // who completed onboarding mid-job shouldn't lose their payout.
            $affiliate = Professional::query()
                ->where('id', $payout->affiliate_professional_id)
                ->lockForUpdate()
                ->first();

            if ($affiliate && $affiliate->stripe_connect_status === 'active') {
                Log::info('payout.expired_cancel.affiliate_now_active', [
                    'payout_id' => $payout->id,
                    'affiliate_id' => $affiliate->id,
                ]);

                return;
            }

            $updated = CommissionPayout::query()
                ->where('id', $payout->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'failure_code' => 'grace_period_expired',
                    'failure_reason' => 'Affiliate did not connect Stripe Connect within the grace period.',
                    'processed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                Log::info('Skipped cancelling expired payout — status changed concurrently', [
                    'payout_id' => $payout->id,
                ]);

                return;
            }

            // Phase 4+: void the linked orders directly. The rollup trigger handles
            // the per-day delta reversal automatically. We do this BEFORE clearing
            // payout_id so the optimistic guard (status='approved' AND payout_id=?)
            // catches concurrent payout-stamp races.
            $voidedOrders = $this->voidOrdersLinkedToPayout($payout->id, 'payout_grace_expired');

            // Now clear the payout_id stamp on those (now voided) orders so the
            // legacy join-based reports don't surface them as still linked.
            $this->clearOrderStampsForVoidedPayout($payout->id);

            $stats['cancelled_count']++;
            $stats['cancelled_cents'] += (int) $payout->gross_commission_cents;
            $stats['voided_entries'] += $voidedOrders;
            $cancelled = true;
        });

        if ($cancelled) {
            $this->publisher->publish(
                professionalId: $payout->affiliate_professional_id,
                frontendType: 'Warning',
                category: 'commissions',
                title: 'Commission payout expired',
                body: sprintf(
                    'Your %s payout from %s has been cancelled because Stripe Connect was not activated within the grace period.',
                    Money::format($payout->net_payout_cents, $payout->currency_code),
                    $payout->brandProfessional?->display_name ?? 'a brand',
                ),
                dedupeKey: "payout_voided.{$payout->id}",
                ctaUrl: '/account/settings?section=stripe',
                retentionConfigKey: 'commission',
            );
        }
    }

    /**
     * Send warning notifications to affiliates approaching void deadlines.
     *
     * Grace period warnings (day 20 and day 28 after signup):
     *   Sent to affiliates still within their grace period who haven't connected.
     *
     * Per-commission warnings (5 days before void window expires):
     *   Sent to post-grace affiliates for each commission about to void.
     *
     * @return array{warnings_sent: int}
     */
    public function sendGracePeriodWarnings(): array
    {
        $stats = ['warnings_sent' => 0];

        $stats['warnings_sent'] += $this->sendSignupWarnings();
        $stats['warnings_sent'] += $this->sendPerCommissionWarnings();
        $stats['warnings_sent'] += $this->sendPerPayoutWarnings();

        return $stats;
    }

    /**
     * Signup grace-period warnings: fired at "10 days left" and "2 days left"
     * before a non-active affiliate's signup grace deadline.
     *
     * v2 proxy: the deadline is computed as `professionals.created_at + signup_grace_period_days`
     * (default 30d). This restores the original v1 semantics — the dropped
     * `stripe_grace_period_ends_at` column was itself defined as `created_at + 30 days`
     * (see 20260416000000_add_commission_grace_period.sql), so `created_at` is a
     * direct, lossless replacement signal. No schema change is needed.
     *
     * Windows scale with `partna.store.signup_grace_period_days` so re-tuning the
     * grace length (e.g., to 60d) keeps the "10/2 days left" semantics intact.
     * Only affiliates with positive pending commissions are notified.
     */
    private function sendSignupWarnings(): int
    {
        $sent = 0;
        $graceDays = (int) config('partna.store.signup_grace_period_days', 30);

        $warningWindows = [
            'signup_10d_left' => [
                'created_range' => [
                    now()->subDays($graceDays - 10)->startOfDay(),
                    now()->subDays($graceDays - 10)->endOfDay(),
                ],
                'title' => 'Connect Stripe — 10 days left',
                'body' => 'Connect your Stripe account within 10 days or your %s in pending earnings will be forfeited.',
            ],
            'signup_2d_left' => [
                'created_range' => [
                    now()->subDays($graceDays - 2)->startOfDay(),
                    now()->subDays($graceDays - 2)->endOfDay(),
                ],
                'title' => 'Connect Stripe — 2 days left',
                'body' => '2 days left — connect Stripe now or your %s in pending earnings will be forfeited.',
            ],
        ];

        foreach ($warningWindows as $key => $window) {
            // Only partners have commission rows — individuals don't sell, brands
            // don't receive commissions. The legacy professional_type dual-branch
            // (pre-§28.1 backfill) was removed once dev DB confirmed zero
            // NULL-account_type rows (audit #9).
            Professional::query()
                ->where('account_type', 'partner')
                ->where('stripe_connect_status', '!=', 'active')
                ->whereBetween('created_at', $window['created_range'])
                ->chunkById(200, function ($affiliates) use (&$sent, $key, $window) {
                    // Batch-load pending amounts for the chunk to avoid N+1.
                    $pendingAmounts = $this->getPendingCommissionCentsBatch(
                        $affiliates->pluck('id')->all()
                    );

                    foreach ($affiliates as $affiliate) {
                        $amount = $pendingAmounts[$affiliate->id] ?? 0;
                        if ($amount <= 0) {
                            continue;
                        }

                        $this->publisher->publish(
                            professionalId: $affiliate->id,
                            frontendType: 'Warning',
                            category: 'commissions',
                            title: $window['title'],
                            body: sprintf($window['body'], Money::format($amount, 'AUD')),
                            dedupeKey: "stripe_warning.{$key}.{$affiliate->id}",
                            ctaUrl: '/account/settings?section=stripe',
                            retentionConfigKey: 'commission',
                        );
                        $sent++;
                    }
                });
        }

        return $sent;
    }

    /**
     * Per-commission warning: 5 days before each commission's void window.
     * For affiliates who are past their initial grace period.
     */
    private function sendPerCommissionWarnings(): int
    {
        $sent = 0;
        $voidWindowDays = $this->voidWindowDays;
        // Orders that will void in 0..5 days = orders whose occurred_at falls
        // in the window [now - voidWindowDays, now - voidWindowDays + 5 days].
        $windowStart = now()->subDays($voidWindowDays);
        $windowEnd = now()->subDays($voidWindowDays - 5);

        // v2 model: all non-active affiliates are eligible for per-commission warnings.
        // The "grace period" distinction (v1's stripe_grace_period_ends_at) is replaced
        // by sendSignupWarnings (created_at-based) for the early signup nudges, while
        // this method handles the per-commission 5-day expiry warning. Both can fire
        // for the same affiliate — they target different deadlines.
        $inactiveAffiliateIds = Professional::query()
            ->where('stripe_connect_status', '!=', 'active')
            ->pluck('id')
            ->all();

        if ($inactiveAffiliateIds === []) {
            return 0;
        }

        Order::query()
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->whereBetween('occurred_at', [$windowStart, $windowEnd])
            ->whereIn('affiliate_professional_id', $inactiveAffiliateIds)
            ->chunkById(500, function ($orders) use (&$sent, $voidWindowDays) {
                foreach ($orders as $order) {
                    $voidDate = $order->occurred_at->copy()->addDays($voidWindowDays);
                    $daysLeft = (int) now()->diffInDays($voidDate, false);

                    if ($daysLeft < 0 || $daysLeft > 5) {
                        continue;
                    }

                    $this->publisher->publish(
                        professionalId: $order->affiliate_professional_id,
                        frontendType: 'Warning',
                        category: 'commissions',
                        title: 'Commission expiring soon',
                        body: sprintf(
                            'Connect Stripe within %d days or your %s commission from %s will be forfeited.',
                            $daysLeft,
                            Money::format((int) $order->commission_cents, $order->currency_code),
                            $order->occurred_at->format('M j'),
                        ),
                        dedupeKey: "stripe_warning.commission.{$order->id}",
                        ctaUrl: '/account/settings?section=stripe',
                        retentionConfigKey: 'commission',
                    );
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * Per-payout warning: 10 and 2 days before each payout's void_at deadline.
     * Mirrors sendSignupWarnings but is keyed off payout.void_at — the actual
     * deadline enforced by processExpiredPayouts — so warnings align with enforcement.
     */
    private function sendPerPayoutWarnings(): int
    {
        $sent = 0;

        $warningWindows = [
            'day10' => [
                'range' => [now()->addDays(10)->startOfDay(), now()->addDays(10)->endOfDay()],
                'title' => 'Connect Stripe — 10 days left',
                'body' => 'Connect Stripe within 10 days or your %s payout will be cancelled.',
            ],
            'day2' => [
                'range' => [now()->addDays(2)->startOfDay(), now()->addDays(2)->endOfDay()],
                'title' => 'Connect Stripe — 2 days left',
                'body' => '2 days left — connect Stripe now or your %s payout will be cancelled.',
            ],
        ];

        // Pre-fetch once for both warning windows (day 10 and day 2) to avoid
        // a correlated subquery on each chunk pass.
        $inactiveAffiliateIds = Professional::query()
            ->where('stripe_connect_status', '!=', 'active')
            ->pluck('id')
            ->all();

        if ($inactiveAffiliateIds === []) {
            return $sent;
        }

        foreach ($warningWindows as $key => $window) {
            CommissionPayout::query()
                ->where('status', 'pending')
                ->whereBetween('void_at', $window['range'])
                ->whereIn('affiliate_professional_id', $inactiveAffiliateIds)
                ->chunkById(200, function ($payouts) use (&$sent, $key, $window): void {
                    foreach ($payouts as $payout) {
                        $this->publisher->publish(
                            professionalId: $payout->affiliate_professional_id,
                            frontendType: 'Warning',
                            category: 'commissions',
                            title: $window['title'],
                            body: sprintf($window['body'], Money::format($payout->net_payout_cents, $payout->currency_code)),
                            dedupeKey: "stripe_warning.payout.{$key}.{$payout->id}",
                            ctaUrl: '/account/settings?section=stripe',
                            retentionConfigKey: 'commission',
                        );
                        $sent++;
                    }
                });
        }

        return $sent;
    }

    /**
     * Check if an affiliate is within their grace period (Stripe not yet required).
     *
     * TODO[stripe-v2]: stripe_grace_period_ends_at column dropped. Grace period
     * distinction is no longer available; affiliates are assumed post-grace.
     * Phase 4 will add capability-based grace period checks.
     */
    public function isInGracePeriod(Professional $affiliate): bool
    {
        return false;
    }

    /**
     * Voids up to $cap pending orders for a specific affiliate-brand pair.
     * Returns overflow: true (without voiding) when count exceeds cap — caller should
     * dispatch VoidPendingCommissionsForLinkJob instead.
     *
     * @return array{count: int, total_cents: int, overflow: bool}
     */
    public function voidPendingForAffiliateBrand(
        string $affiliateProfessionalId,
        string $brandProfessionalId,
        string $reason,
        int $cap = 200,
    ): array {
        $pendingCount = Order::query()
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->count();

        if ($pendingCount > $cap) {
            return ['count' => 0, 'total_cents' => 0, 'overflow' => true];
        }

        return $this->runVoidLoop($affiliateProfessionalId, $brandProfessionalId, $reason);
    }

    /**
     * Loops voidOrder() over every approved+unstamped order for the pair.
     *
     * @return array{count: int, total_cents: int, overflow: bool}
     */
    public function runVoidLoop(
        string $affiliateProfessionalId,
        string $brandProfessionalId,
        string $reason,
    ): array {
        $voidedCount = 0;
        $voidedCents = 0;

        Order::query()
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->orderBy('occurred_at')
            ->chunkById(50, function ($orders) use (&$voidedCount, &$voidedCents, $reason): void {
                foreach ($orders as $order) {
                    if ($this->voidOrder($order, $reason)) {
                        $voidedCount++;
                        $voidedCents += (int) $order->commission_cents;
                    }
                }
            });

        return ['count' => $voidedCount, 'total_cents' => $voidedCents, 'overflow' => false];
    }

    /**
     * @return array{count: int, total_cents: int}
     */
    public function pendingSummaryForAffiliateBrand(
        string $affiliateProfessionalId,
        string $brandProfessionalId,
    ): array {
        $row = DB::table('commerce.orders')
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(commission_cents), 0) AS t')
            ->first();

        return [
            'count' => (int) ($row->c ?? 0),
            'total_cents' => (int) ($row->t ?? 0),
        ];
    }

    /**
     * Batch-load pending commission totals for multiple affiliates in one query.
     *
     * @param  string[]  $affiliateIds
     * @return array<string, int> affiliate_id => total_pending_cents
     */
    private function getPendingCommissionCentsBatch(array $affiliateIds): array
    {
        if ($affiliateIds === []) {
            return [];
        }

        return Order::query()
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->whereIn('affiliate_professional_id', $affiliateIds)
            ->groupBy('affiliate_professional_id')
            ->pluck(DB::raw('SUM(commission_cents)'), 'affiliate_professional_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Void all approved orders linked to a payout via payout_items. Used when a
     * payout is being cancelled because the affiliate didn't activate Stripe Connect.
     * Returns the number of orders successfully voided. The optimistic guard
     * (`payout_id = $payoutId`) means orders already in a terminal state, or whose
     * payout_id changed concurrently, are silently skipped.
     */
    private function voidOrdersLinkedToPayout(string $payoutId, string $reason): int
    {
        $orderIds = CommissionPayoutItem::query()
            ->where('payout_id', $payoutId)
            ->whereNotNull('order_id')
            ->pluck('order_id')
            ->all();

        if (empty($orderIds)) {
            return 0;
        }

        $voided = 0;
        foreach (Order::whereIn('id', $orderIds)->where('status', 'approved')->get() as $order) {
            if ($this->voidOrder($order, $reason, $payoutId)) {
                $voided++;
            }
        }

        return $voided;
    }

    /**
     * Clear payout_id on any commerce.orders rows linked to this payout via payout items.
     * Called inside the cancelExpiredPayout transaction — any orders that were not voided
     * (e.g., already refunded/cancelled) get their stamp cleared so reports don't surface
     * them as still linked to a cancelled payout.
     */
    private function clearOrderStampsForVoidedPayout(string $payoutId): void
    {
        $orderIds = CommissionPayoutItem::query()
            ->where('payout_id', $payoutId)
            ->whereNotNull('order_id')
            ->pluck('order_id')
            ->all();

        if (! empty($orderIds)) {
            Order::whereIn('id', $orderIds)
                ->update(['payout_id' => null]);
        }
    }
}
