<?php

namespace App\Jobs\Stripe;

use App\Models\Commerce\CommissionPayout;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Cancels commission payouts whose 60-day grace window has expired without
// the affiliate connecting Stripe Connect.
//
// Backstop for the UI promise on the affiliate dashboard ("payout will be
// voided in N days if you don't connect Stripe"). Without this job the
// per-payout void_at column was being written but never enforced — the
// older 30-day ledger-entry void path is unrelated and operates one layer
// below. Closes #CR-003.
// Scheduled: daily at 07:00 UTC via routes/console.php.
class VoidExpiredPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Generous timeout: the partial index keeps the scan tight, but the
    // chunk loop voids ledger entries inside a transaction per payout.
    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('stripe');
    }

    /** Backoff between retries: short for the first, longer for the second. */
    public function backoff(): array
    {
        return [60, 180];
    }

    public function handle(CommissionVoidService $voidService, NotificationPublisher $publisher): void
    {
        $this->fireGraceWarnings($publisher);

        $stats = $voidService->processExpiredPayouts();

        // Always emit a heartbeat — silence here would mask a stuck scheduler.
        // Promote to notice when real cancellations land so they stand out
        // from routine zero-action passes (matches markPendingFunding's pattern).
        if ($stats['cancelled_count'] > 0 || $stats['voided_entries'] > 0) {
            Log::notice('Expired payout void processing complete', $stats);
        } else {
            Log::info('Expired payout void processing complete', $stats);
        }
    }

    /**
     * Send T-30/T-7/T-1 grace warnings to affiliates (or brands, for brand-side
     * failure codes) who haven't unblocked the payout. Publishes through the
     * unified NotificationPublisher pipeline (in-app row + transactional email).
     *
     * Anchors on void_at (within a 24h window for the cron's daily cadence).
     * The grace_notifications_sent JSONB array gates per-tier so retries within
     * the same day are no-ops; the publisher's dedupe_key (payout_warning.{id}.t-{N})
     * is belt-and-braces for cross-process races.
     */
    private function fireGraceWarnings(NotificationPublisher $publisher): void
    {
        // Brand-side failure codes mean the affiliate cannot fix the issue —
        // route those warnings to the brand instead. The pre-existing query
        // already excludes payouts where the affiliate IS active, which
        // covered the affiliate-side cases; brand-side blockers may have an
        // active affiliate too, so we evaluate routing per-payout.
        $brandSideCodes = ['brand_payment_method_missing', 'wallet_currency_mismatch'];

        // DB-14: collapse the three per-tier queries (T-30/T-7/T-1) into a single
        // query that covers the full window union, then group in-memory.
        // Each tier window is [startOfDay(now+N), endOfDay(now+N)]; the union spans
        // startOfDay(now+1) → endOfDay(now+30). Rows outside any exact tier window are
        // filtered out in-memory, preserving identical tier assignment logic.
        $windowStart = now()->addDay()->startOfDay();
        $windowEnd = now()->addDays(30)->endOfDay();

        $allCandidates = CommissionPayout::query()
            ->where('status', 'pending')
            ->whereBetween('void_at', [$windowStart, $windowEnd])
            ->where(function ($q) use ($brandSideCodes) {
                // Either: brand-side blocker (notify brand regardless of affiliate state)
                // Or: affiliate-side issue with affiliate not yet active
                $q->whereIn('failure_code', $brandSideCodes)
                    ->orWhereDoesntHave('affiliateProfessional', fn ($a) => $a->where('stripe_connect_status', 'active'));
            })
            ->get();

        foreach ([30, 7, 1] as $daysOut) {
            $tag = 'T-'.$daysOut;
            // We want to fire the warning $daysOut days BEFORE void_at — i.e.
            // when void_at = now + $daysOut (within a 24h window for the cron's
            // daily cadence).
            $target = now()->addDays($daysOut);
            $tierStart = $target->copy()->startOfDay();
            $tierEnd = $target->copy()->endOfDay();

            $candidates = $allCandidates->filter(
                fn ($p) => $p->void_at >= $tierStart
                    && $p->void_at <= $tierEnd
                    && ! in_array($tag, $p->grace_notifications_sent ?? [], true)
            );

            foreach ($candidates as $payout) {
                $isBrandSide = in_array($payout->failure_code, $brandSideCodes, true);

                try {
                    if ($isBrandSide) {
                        $this->publishBrandWarning($publisher, $payout, $daysOut);
                    } else {
                        $this->publishAffiliateWarning($publisher, $payout, $daysOut);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Payout grace warning publish failed', [
                        'payout_id' => $payout->id,
                        'days_out' => $daysOut,
                        'brand_side' => $isBrandSide,
                        'message' => $e->getMessage(),
                    ]);

                    // Skip the per-tier gate update so the next cron run retries.
                    continue;
                }

                $sent = $payout->grace_notifications_sent ?? [];
                $sent[] = $tag;
                $payout->forceFill(['grace_notifications_sent' => array_values(array_unique($sent))])->save();
            }
        }
    }

    private function publishAffiliateWarning(NotificationPublisher $publisher, CommissionPayout $payout, int $daysOut): void
    {
        $affiliateId = $payout->affiliate_professional_id;
        if (! $affiliateId) {
            return;
        }

        $brand = $payout->brandProfessional?->display_name ?? 'a brand';
        $amount = '$'.number_format($payout->gross_commission_cents / 100, 2);

        // Mirrors the legacy MailMessage tiered copy: T-30 informational,
        // T-7 urgent, T-1 final/critical.
        [$title, $body] = match (true) {
            $daysOut >= 30 => [
                "Your {$amount} from {$brand} expires in 30 days",
                "You have {$amount} in commission from {$brand} ready to be paid. To receive it, connect a Stripe account. After 60 days unconnected, the commission expires and the brand keeps the funds.",
            ],
            $daysOut >= 7 => [
                "Only {$daysOut} days left to claim your {$amount} from {$brand}",
                "Your {$amount} commission from {$brand} expires in {$daysOut} days. Connect Stripe now and we'll send the funds within 24h.",
            ],
            default => [
                "Final notice: {$amount} from {$brand} expires tomorrow",
                "This is your final reminder. Your {$amount} commission from {$brand} expires in 24 hours. If you don't connect Stripe before then, the commission is forfeited.",
            ],
        };

        $publisher->publish(
            professionalId: (string) $affiliateId,
            frontendType: $daysOut <= 1 ? 'Critical' : 'Warning',
            category: 'payout_warnings',
            title: $title,
            body: $body,
            dedupeKey: "payout_warning.{$payout->id}.t-{$daysOut}",
            ctaUrl: '/affiliate/stripe/connect',
            primaryActionLabel: $daysOut <= 1 ? 'Connect Stripe — final reminder' : ($daysOut >= 30 ? 'Connect Stripe (5 min)' : 'Connect Stripe'),
            retentionConfigKey: 'payout_warning',
        );
    }

    private function publishBrandWarning(NotificationPublisher $publisher, CommissionPayout $payout, int $daysOut): void
    {
        $brandId = $payout->brand_professional_id;
        if (! $brandId) {
            return;
        }

        $affiliate = $payout->affiliateProfessional?->display_name ?? 'an affiliate';
        $amount = '$'.number_format($payout->gross_commission_cents / 100, 2);

        $reasonLine = match ($payout->failure_code) {
            'wallet_currency_mismatch' => 'Your wallet balance is in a different currency than this payout requires — please contact support to resolve.',
            default => 'Add a payment method in your Stripe settings so we can collect the commission and pay your affiliate.',
        };

        [$title, $body] = match (true) {
            $daysOut >= 30 => [
                "Commission payout of {$amount} to {$affiliate} blocked",
                "A commission payout of {$amount} to {$affiliate} is blocked and will expire in 30 days. {$reasonLine}",
            ],
            $daysOut >= 7 => [
                "{$daysOut} days to fix payment — {$amount} to {$affiliate}",
                "Your commission payout of {$amount} to {$affiliate} expires in {$daysOut} days. {$reasonLine}",
            ],
            default => [
                "Final notice: {$amount} payout to {$affiliate} expires tomorrow",
                "This is your final reminder. A commission payout of {$amount} to {$affiliate} expires in 24 hours and the affiliate will not be paid. {$reasonLine}",
            ],
        };

        $publisher->publish(
            professionalId: (string) $brandId,
            frontendType: $daysOut <= 1 ? 'Critical' : 'Warning',
            category: 'payout_warnings',
            title: $title,
            body: $body,
            dedupeKey: "payout_warning.{$payout->id}.t-{$daysOut}",
            ctaUrl: '/account/settings?section=stripe',
            primaryActionLabel: $daysOut <= 1 ? 'Fix payment settings — final reminder' : 'Fix payment settings',
            retentionConfigKey: 'payout_warning',
        );
    }

    public function failed(\Throwable $e): void
    {
        // Forward to the global handler so Nightwatch logs the failure.
        // Log::error gives us a structured breadcrumb in cloud logs even
        // if Nightwatch is offline.
        report($e);

        Log::error('VoidExpiredPayoutsJob failed after all retries', [
            'message' => $e->getMessage(),
        ]);
    }
}
