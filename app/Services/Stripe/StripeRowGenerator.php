<?php

namespace App\Services\Stripe;

use App\Models\Commerce\CommissionPayout;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Throwable;

/**
 * Generator-based variant of StripeTransactionFetcher used by the async export
 * pipeline. Yields normalised rows one at a time so worker memory stays bounded
 * regardless of dataset size.
 *
 * The legacy fetcher (`forBrand`, `forAffiliate`) returned `array<row>` and
 * accumulated everything in memory — fine for ≤500 payouts but OOMs at 50K+.
 * Normalisation helpers are byte-identical to the legacy ones so the row shape
 * stays the same (TransactionResource is unaffected if it ever consumes us).
 */
class StripeRowGenerator
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * Yield normalised transaction rows for a collection of payouts.
     *
     * @param  iterable<CommissionPayout>  $payouts
     * @param  string  $role  'brand' or 'affiliate'
     * @return \Generator<int, array<string, mixed>>
     */
    public function forPayouts(iterable $payouts, string $role): \Generator
    {
        foreach ($payouts as $payout) {
            yield from $role === 'brand'
                ? $this->yieldBrand($payout)
                : $this->yieldAffiliate($payout);
        }
    }

    private function yieldBrand(CommissionPayout $payout): \Generator
    {
        if (! $payout->payment_intent_id) {
            return;
        }

        try {
            $pi = $this->stripe->paymentIntents->retrieve($payout->payment_intent_id, [
                'expand' => ['latest_charge.refunds'],
            ]);
        } catch (Throwable $e) {
            Log::warning('commission_export.stripe_retrieve_failed', [
                'role' => 'brand',
                'payout_id' => $payout->id,
                'pi_id' => $payout->payment_intent_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $charge = is_object($pi->latest_charge ?? null) ? $pi->latest_charge : null;
        if ($charge === null) {
            return;
        }

        yield $this->normalizeBrandCharge($charge, $payout);

        foreach ($charge->refunds->data ?? [] as $refund) {
            yield $this->normalizeBrandRefund($refund, $charge, $payout);
        }
    }

    private function yieldAffiliate(CommissionPayout $payout): \Generator
    {
        if (! $payout->charge_id) {
            return;
        }

        try {
            $charge = $this->stripe->charges->retrieve($payout->charge_id, [
                'expand' => ['transfer.reversals'],
            ]);
        } catch (Throwable $e) {
            Log::warning('commission_export.stripe_retrieve_failed', [
                'role' => 'affiliate',
                'payout_id' => $payout->id,
                'charge_id' => $payout->charge_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $transfer = is_object($charge->transfer ?? null) ? $charge->transfer : null;
        if ($transfer === null) {
            return;
        }

        yield $this->normalizeAffiliateTransfer($transfer, $payout);

        foreach ($transfer->reversals->data ?? [] as $reversal) {
            yield $this->normalizeAffiliateReversal($reversal, $transfer, $payout);
        }
    }

    // -------------------------------------------------------------------------
    // Normalisation helpers — copied verbatim from StripeTransactionFetcher.
    // DO NOT modify these; row shape must stay byte-identical to the legacy
    // fetcher so downstream consumers (TransactionResource, export formatters)
    // see the same structure.
    // -------------------------------------------------------------------------

    private function normalizeBrandCharge(object $charge, CommissionPayout $payout): array
    {
        $brand = $payout->brandProfessional;
        $affiliate = $payout->affiliateProfessional;

        return [
            'id' => 'charge:'.$charge->id,
            'type' => 'charge',
            'amount_cents' => (int) ($charge->amount ?? 0),
            'currency_code' => strtoupper((string) ($charge->currency ?? 'aud')),
            'status' => (string) ($charge->status ?? 'unknown'),
            'description' => $this->stringOrNull($charge->description ?? null)
                ?? sprintf('Commission charge — %s (%d %s)',
                    $affiliate?->display_name ?? 'affiliate',
                    (int) $payout->ledger_entry_count,
                    $payout->ledger_entry_count == 1 ? 'order' : 'orders'),
            'occurred_at' => $this->isoFromUnix($charge->created ?? null),
            'payout_id' => $payout->id,
            'orders_count' => (int) $payout->ledger_entry_count,
            'brand' => $this->shapeParty($brand),
            'affiliate' => $this->shapeParty($affiliate),
            'stripe_dashboard_url' => "https://dashboard.stripe.com/payments/{$charge->payment_intent}",
            'raw_stripe_id' => (string) $charge->id,
        ];
    }

    private function normalizeBrandRefund(object $refund, object $charge, CommissionPayout $payout): array
    {
        $brand = $payout->brandProfessional;
        $affiliate = $payout->affiliateProfessional;

        return [
            'id' => 'refund:'.$refund->id,
            'type' => 'refund',
            // Negative — refunds reduce what the brand paid.
            'amount_cents' => -1 * (int) ($refund->amount ?? 0),
            'currency_code' => strtoupper((string) ($refund->currency ?? $charge->currency ?? 'aud')),
            'status' => (string) ($refund->status ?? 'unknown'),
            'description' => sprintf('Refund of commission — %s', $affiliate?->display_name ?? 'affiliate'),
            'occurred_at' => $this->isoFromUnix($refund->created ?? null),
            'payout_id' => $payout->id,
            'orders_count' => null,
            'brand' => $this->shapeParty($brand),
            'affiliate' => $this->shapeParty($affiliate),
            'stripe_dashboard_url' => "https://dashboard.stripe.com/payments/{$charge->payment_intent}",
            'raw_stripe_id' => (string) $refund->id,
        ];
    }

    private function normalizeAffiliateTransfer(object $transfer, CommissionPayout $payout): array
    {
        $brand = $payout->brandProfessional;
        $affiliate = $payout->affiliateProfessional;
        $destinationPaymentId = $this->stringOrNull($transfer->destination_payment ?? null);

        return [
            'id' => 'transfer:'.$transfer->id,
            'type' => 'transfer',
            'amount_cents' => (int) ($transfer->amount ?? 0),
            'currency_code' => strtoupper((string) ($transfer->currency ?? 'aud')),
            'status' => 'completed',
            // The enrichment we landed in the previous merge sets this to
            // "Partna commission from {brand} (payout {id}, N orders)"; fall back if absent.
            'description' => $this->stringOrNull($transfer->description ?? null)
                ?? sprintf('Commission from %s (%d %s)',
                    $brand?->display_name ?? 'brand',
                    (int) $payout->ledger_entry_count,
                    $payout->ledger_entry_count == 1 ? 'order' : 'orders'),
            'occurred_at' => $this->isoFromUnix($transfer->created ?? null),
            'payout_id' => $payout->id,
            'orders_count' => (int) $payout->ledger_entry_count,
            'brand' => $this->shapeParty($brand),
            'affiliate' => $this->shapeParty($affiliate),
            // The affiliate-side deep-link points at their own Express dashboard payment row,
            // not the platform transfer object (which their account can't see).
            'stripe_dashboard_url' => $destinationPaymentId
                ? "https://dashboard.stripe.com/connect/accounts/{$affiliate?->stripe_connect_account_id}/payments/{$destinationPaymentId}"
                : null,
            'raw_stripe_id' => $destinationPaymentId ?? (string) $transfer->id,
        ];
    }

    private function normalizeAffiliateReversal(object $reversal, object $transfer, CommissionPayout $payout): array
    {
        $brand = $payout->brandProfessional;
        $affiliate = $payout->affiliateProfessional;

        return [
            'id' => 'reversal:'.$reversal->id,
            'type' => 'reversal',
            // Negative — reversals claw back from the affiliate's balance.
            'amount_cents' => -1 * (int) ($reversal->amount ?? 0),
            'currency_code' => strtoupper((string) ($reversal->currency ?? $transfer->currency ?? 'aud')),
            'status' => 'completed',
            'description' => sprintf('Clawback to %s', $brand?->display_name ?? 'brand'),
            'occurred_at' => $this->isoFromUnix($reversal->created ?? null),
            'payout_id' => $payout->id,
            'orders_count' => null,
            'brand' => $this->shapeParty($brand),
            'affiliate' => $this->shapeParty($affiliate),
            'stripe_dashboard_url' => null,
            'raw_stripe_id' => (string) $reversal->id,
        ];
    }

    private function shapeParty(?Professional $pro): ?array
    {
        if (! $pro) {
            return null;
        }

        return [
            'id' => (string) $pro->id,
            'name' => $pro->display_name,
            'handle' => $pro->handle,
        ];
    }

    private function isoFromUnix(?int $unix): ?string
    {
        if (! $unix) {
            return null;
        }

        return \Carbon\CarbonImmutable::createFromTimestamp($unix)->toIso8601String();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
