<?php

namespace App\Observers\Core;

use App\Models\Commerce\CommissionPayout;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// V2: Core. Publishes payout lifecycle notifications — completion to the
// affiliate, context-aware failure messages to whoever can act on them, and
// pending/action-required alerts to both parties when a charge can't complete
// immediately. Runs after commit so rolled-back transactions don't emit.
class CommissionPayoutObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(private readonly NotificationPublisher $publisher) {}

    public function updated(CommissionPayout $payout): void
    {
        try {
            $statusChanged = $payout->isDirty('status');

            // Successful completion — affiliate sees their money landed.
            if ($statusChanged && $payout->status === 'completed') {
                $this->bustPayoutStateCache($payout);
                $this->notifyCompleted($payout);

                return;
            }

            // Terminal failure — notify the party who can act (affiliate for
            // Connect onboarding, nobody usefully for brand_missing / system
            // transfer errors — support picks those up from the failure log).
            if ($statusChanged && $payout->status === 'failed') {
                $this->bustPayoutStateCache($payout);
                $this->notifyFailed($payout);

                return;
            }

            // Pending with a newly-set failure code — the batch is on hold
            // waiting for the brand to resolve a card issue. Notify both
            // parties so the affiliate knows their money is delayed (and why)
            // and the brand knows what action they need to take.
            $failureCodeJustSet = $payout->isDirty('failure_code')
                && $payout->failure_code !== null
                && $payout->getOriginal('failure_code') === null;

            if ($payout->status === 'pending' && $failureCodeJustSet) {
                $this->bustPayoutStateCache($payout);
                $this->notifyPending($payout);

                return;
            }
        } catch (\Throwable $e) {
            Log::warning('CommissionPayout updated notification failed', $this->logContext(__METHOD__, [
                'payout_id' => $payout->id,
                'brand_professional_id' => $payout->brand_professional_id,
                'affiliate_professional_id' => $payout->affiliate_professional_id,
                'message' => $e->getMessage(),
            ]));
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Notification builders */
    /* ------------------------------------------------------------------ */

    private function notifyCompleted(CommissionPayout $payout): void
    {
        $affiliateId = trim((string) ($payout->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        $amountLabel = $this->formatAmount(
            (int) ($payout->net_payout_cents ?? 0),
            (string) ($payout->currency_code ?? 'AUD'),
        );

        $this->publisher->publish(
            professionalId: $affiliateId,
            frontendType: 'Success',
            category: 'payouts',
            title: 'Payout sent',
            body: "A commission payout of {$amountLabel} has been transferred to your connected account.",
            dedupeKey: "payout.completed.{$payout->id}",
            ctaUrl: '/account/store?section=payouts',
            retentionConfigKey: 'payout',
        );
    }

    private function notifyFailed(CommissionPayout $payout): void
    {
        $affiliateId = trim((string) ($payout->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        $code = (string) ($payout->failure_code ?? '');

        [$title, $body] = match ($code) {
            'affiliate_not_connected' => [
                'Stripe Connect setup required',
                'We couldn\'t send your commission payout because your Stripe Connect account isn\'t active. Finish onboarding to receive payments.',
            ],
            'brand_missing' => [
                'Payout failed',
                'The brand account associated with this commission is no longer available. Please contact support.',
            ],
            'transfer_failed' => [
                'Payout failed',
                'A system error prevented the transfer. Our team has been notified and will resolve it shortly.',
            ],
            default => [
                'Payout failed',
                'A commission payout could not be processed. Please contact support if this persists.',
            ],
        };

        $ctaUrl = $code === 'affiliate_not_connected'
            ? '/account/payments?section=stripe-connect'
            : '/account/store?section=payouts';

        $this->publisher->publish(
            professionalId: $affiliateId,
            frontendType: 'Critical',
            category: 'payouts',
            title: $title,
            body: $body,
            dedupeKey: "payout.failed.{$payout->id}",
            ctaUrl: $ctaUrl,
            retentionConfigKey: 'payout',
        );
    }

    private function notifyPending(CommissionPayout $payout): void
    {
        $affiliateId = trim((string) ($payout->affiliate_professional_id ?? ''));
        $brandId = trim((string) ($payout->brand_professional_id ?? ''));
        $code = (string) ($payout->failure_code ?? '');

        // Brand-side action messages.
        [$brandTitle, $brandBody] = match ($code) {
            'brand_payment_method_missing' => [
                'Payment method required',
                'Add a payment method in your Stripe settings to release pending commission payouts.',
            ],
            'charge_requires_action' => [
                'Payment authentication required',
                'A commission payout charge needs your authentication. Please review in your Stripe settings.',
            ],
            'charge_failed' => [
                'Payment method declined',
                'Your payment method was declined while processing a commission payout. Please update it — we\'ll retry automatically.',
            ],
            default => [
                'Payout action required',
                'A commission payout requires your attention. Please review your funding balance.',
            ],
        };

        if ($brandId !== '') {
            $this->publisher->publish(
                professionalId: $brandId,
                frontendType: 'Warning',
                category: 'payouts',
                title: $brandTitle,
                body: $brandBody,
                dedupeKey: "payout.action_required.{$payout->id}",
                ctaUrl: '/account/commerce?section=payouts',
                retentionConfigKey: 'payout',
            );
        }

        // Affiliate-side "your money is delayed" message. Generic because the
        // fix is on the brand's side — the affiliate just needs to know why
        // their payout is late and that they don't need to do anything.
        if ($affiliateId !== '') {
            $amountLabel = $this->formatAmount(
                (int) ($payout->net_payout_cents ?? 0),
                (string) ($payout->currency_code ?? 'AUD'),
            );

            $affiliateBody = match ($code) {
                'charge_requires_action' => "Your {$amountLabel} payout is on hold while the brand authenticates the charge. We'll retry automatically.",
                'charge_failed' => "Your {$amountLabel} payout is delayed — the brand's payment method was declined. We'll retry automatically once they update it.",
                'brand_payment_method_missing' => "Your {$amountLabel} payout is on hold until the brand adds a payment method. We'll retry automatically.",
                default => "Your {$amountLabel} commission payout is temporarily on hold. We'll retry automatically.",
            };

            $this->publisher->publish(
                professionalId: $affiliateId,
                frontendType: 'Warning',
                category: 'payouts',
                title: 'Payout delayed',
                body: $affiliateBody,
                dedupeKey: "payout.delayed.{$payout->id}",
                ctaUrl: '/account/store?section=payouts',
                retentionConfigKey: 'payout',
            );
        }
    }

    /**
     * Clear both the primary and :stale affiliate payout-state cache keys.
     * The analytics endpoint caches payout state under affiliatePayoutState;
     * CacheLockService writes a :stale twin — forgetting only the primary
     * leaves the SWR fast path serving the pre-change value until TTL expires.
     */
    private function bustPayoutStateCache(CommissionPayout $payout): void
    {
        $affiliateId = trim((string) ($payout->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        $key = CacheKeyGenerator::affiliatePayoutState($affiliateId);
        Cache::deleteMultiple([$key, $key.':stale']);
    }

    private function formatAmount(int $cents, string $currencyCode): string
    {
        $currencyCode = strtoupper(trim($currencyCode));
        if ($currencyCode === '') {
            $currencyCode = 'AUD';
        }

        $prefix = match ($currencyCode) {
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            'AUD' => 'A$',
            default => $currencyCode.' ',
        };

        return $prefix.number_format($cents / 100, 2, '.', ',');
    }
}
