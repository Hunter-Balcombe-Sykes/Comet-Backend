<?php

namespace App\Jobs\Shopify;

use App\Jobs\Stripe\ProcessCommissionPayoutsJob;
use App\Models\Brand\BrandStoreSettings;
use App\Models\Commerce\Order;
use App\Models\Commerce\OrderEvent;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Cache\AnalyticsCacheService;
use App\Services\Customers\ContactCaptureService;
use App\Services\Store\BrandCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Phase 3: Processes orders/paid webhooks. Writes commerce.orders (LWW upsert) +
// commerce.order_events (idempotent via shopify_event_id). Triggers maintain
// order_items and brand_affiliate_rollup automatically.
class ProcessShopifyOrderWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 30;

    public function __construct(
        public string $brandProfessionalId,
        public array $orderPayload,
        public string $shopifyEventId = '',
        // 'webhook' for live webhook deliveries; 'reconciler' for backstop-sourced events.
        public string $source = 'webhook',
    ) {
        $this->onQueue('integrations');
    }

    public function handle(
        ContactCaptureService $contactCapture,
        BrandCatalogService $catalogService,
        AnalyticsCacheService $analyticsCache,
    ): void {
        $start = microtime(true);

        $this->process($contactCapture, $catalogService, $analyticsCache);

        // Alert threshold: 15s = 50% of job timeout. Slow path here usually means
        // the BrandCatalogService metafield fetch or the LWW upsert is degraded.
        // Configure a Nightwatch alert on this warning to catch trouble before
        // the 30s timeout starts failing webhook deliveries.
        $durationMs = (int) round((microtime(true) - $start) * 1000);
        if ($durationMs > 15_000) {
            Log::warning('ProcessShopifyOrderWebhookJob slow processing', [
                'order_id' => (string) Arr::get($this->orderPayload, 'id', ''),
                'brand_professional_id' => $this->brandProfessionalId,
                'duration_ms' => $durationMs,
                'attempt' => $this->attempts(),
            ]);
        }
    }

    private function process(
        ContactCaptureService $contactCapture,
        BrandCatalogService $catalogService,
        AnalyticsCacheService $analyticsCache,
    ): void {
        $orderId = (string) Arr::get($this->orderPayload, 'id', '');
        $shopDomain = strtolower(trim((string) Arr::get($this->orderPayload, 'domain', '')));
        $noteAttributes = Arr::get($this->orderPayload, 'note_attributes', []);
        $lineItems = Arr::get($this->orderPayload, 'line_items', []);
        $currency = strtoupper(trim((string) Arr::get($this->orderPayload, 'currency', 'AUD')));
        $occurredAt = Arr::get($this->orderPayload, 'created_at', now()->toIso8601String());
        $shopifyUpdatedAt = Arr::get($this->orderPayload, 'updated_at', $occurredAt);

        if ($orderId === '') {
            Log::warning('ProcessShopifyOrderWebhookJob: missing order id, skipping', [
                'brand_professional_id' => $this->brandProfessionalId,
            ]);

            return;
        }

        // Defence-in-depth: confirm the brand target is actually a brand account.
        // A professional converted away from brand (currently impossible — brand is terminal —
        // but guards against future bugs or stale-rollback scenarios) would cause wrong commerce writes.
        $brandProfessional = Professional::query()->whereKey($this->brandProfessionalId)->first();
        if (! $brandProfessional || ! $brandProfessional->isBrand()) {
            Log::warning('ProcessShopifyOrderWebhookJob: brand professional not found or not a brand, skipping', [
                'order_id' => $orderId,
                'brand_professional_id' => $this->brandProfessionalId,
                'account_type' => $brandProfessional?->account_type?->value,
            ]);

            return;
        }

        // Resolve affiliate from note_attributes. The cart attribute key is
        // `_partna_affiliate_id` and the value is the affiliate's professional UUID
        // (Hydrogen's `app/routes/$affiliateSlug.tsx` action sets
        // `[{key: '_partna_affiliate_id', value: affiliate.id}]` on cartCreate).
        // The legacy `'affiliate'` key + handle-based lookup was a sidest-era
        // pattern that the partna rename missed; resolving by UUID is the new
        // canonical path and is consistent with the partna-affiliate-discount
        // Shopify Function which also keys on this attribute.
        $affiliateId = $this->extractCartAttribute($noteAttributes, '_partna_affiliate_id');

        if ($affiliateId === '') {
            Log::info('ProcessShopifyOrderWebhookJob: no affiliate attribute, skipping', [
                'order_id' => $orderId,
                'brand_professional_id' => $this->brandProfessionalId,
            ]);

            return;
        }

        $affiliate = Professional::query()
            ->where('id', $affiliateId)
            ->first();

        if (! $affiliate) {
            Log::warning('ProcessShopifyOrderWebhookJob: affiliate not found', [
                'order_id' => $orderId,
                'affiliate_id' => $affiliateId,
            ]);

            return;
        }

        // Verify affiliate is connected to this brand before writing any commerce rows.
        $isConnected = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->where('brand_professional_id', $this->brandProfessionalId)
            ->exists();

        if (! $isConnected) {
            Log::warning('ProcessShopifyOrderWebhookJob: affiliate not connected to brand', [
                'order_id' => $orderId,
                'affiliate_id' => (string) $affiliate->id,
                'brand_professional_id' => $this->brandProfessionalId,
            ]);

            return;
        }

        // Resolve integration for currency validation and metafield overrides.
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $this->brandProfessionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        // Currency mismatch guard: commission math would be wrong unit on multi-currency misconfiguration.
        $shopCurrency = strtoupper(trim((string) Arr::get($integration?->provider_metadata ?? [], 'shop_currency', '')));
        if ($shopCurrency !== '' && $shopCurrency !== $currency) {
            Log::warning('ProcessShopifyOrderWebhookJob: currency mismatch, skipping', [
                'order_id' => $orderId,
                'brand_professional_id' => $this->brandProfessionalId,
                'order_currency' => $currency,
                'shop_currency' => $shopCurrency,
            ]);

            return;
        }

        // Use shop_domain from the integration record (authoritative); fall back to payload.
        $shopDomain = $integration?->shopify_shop_domain ?? $shopDomain;

        $brandSettings = BrandStoreSettings::where('professional_id', $this->brandProfessionalId)->first();
        $platformDefault = (float) config('partna.store.default_commission_rate', 15);

        // Collect GIDs for a single-call metafield override fetch.
        $productGids = $this->extractProductGids($lineItems);
        $overrideMap = ($integration && ! empty($productGids))
            ? $catalogService->fetchCommissionOverridesForProducts($integration, $productGids)
            : [];

        // Pre-compute per-line commission and serialize into line_items JSONB.
        // Postgres triggers can't access PHP business logic (metafields, brand defaults),
        // so commission is resolved here and stored in the JSONB for the trigger to read.
        [$enrichedLineItems, $totalGrossCents, $totalDiscountCents, $totalCommissionCents, $commissionRate, $rateSource] =
            $this->buildEnrichedLineItems($lineItems, $overrideMap, $brandSettings, $platformDefault, $currency, $orderId);

        // Strip GDPR-tracked PII to a safe shopify_data snapshot before writing.
        // Per ADR 0001, full redaction happens in RedactCustomerJob; here we store
        // only the non-PII structural fields needed for reconciliation.
        $shopifyData = $this->buildSafeShopifyData($this->orderPayload);

        // LWW upsert: the WHERE guard is enforced in SQL, not PHP — out-of-order
        // delivery with an older shopify_updated_at leaves the existing row untouched.
        $this->upsertOrder(
            orderId: $orderId,
            shopDomain: $shopDomain,
            brandProfessionalId: $this->brandProfessionalId,
            affiliateProfessionalId: (string) $affiliate->id,
            grossCents: $totalGrossCents,
            discountCents: $totalDiscountCents,
            commissionCents: $totalCommissionCents,
            commissionRate: $commissionRate,
            rateSource: $rateSource,
            currency: $currency,
            lineItems: $enrichedLineItems,
            shopifyData: $shopifyData,
            occurredAt: $occurredAt,
            shopifyUpdatedAt: $shopifyUpdatedAt,
        );

        // Fetch the order id for the event FK after upsert.
        $order = Order::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('shopify_order_id', $orderId)
            ->first();

        if ($order) {
            $this->insertOrderEvent(
                orderId: (string) $order->id,
                eventType: 'paid',
                shopifyEventId: $this->shopifyEventId,
                source: $this->source,
                metadata: [
                    'shopify_order_id' => $orderId,
                    'financial_status' => Arr::get($this->orderPayload, 'financial_status', ''),
                ],
                shopifyTriggeredAt: $occurredAt,
            );

            // Invalidate caches for both brand and affiliate so dashboards reflect this order.
            $analyticsCache->invalidateAnalytics($this->brandProfessionalId);
            $analyticsCache->invalidateAnalytics((string) $affiliate->id);

            // Per-product invalidation for the embedded product-analytics block — keyed
            // by numeric Shopify product_id from line items (matches the controller's
            // cache key shape after stripping the gid:// prefix). Regex-validated so
            // a tampered payload can't write junk segments into Redis DEL keys —
            // mirrors the defense applied by extractProductGids() below.
            $productIds = array_values(array_filter(array_map(
                fn ($li) => (string) Arr::get($li, 'product_id', ''),
                $lineItems
            ), fn (string $id) => $id !== '' && preg_match('/^\d+$/', $id) === 1));
            if ($productIds !== []) {
                $analyticsCache->invalidateProductAnalytics($this->brandProfessionalId, $productIds);
            }

            // Instant payout: when the brand has opted into hold_days=0 and all
            // payout prerequisites are met, kick the payout sweep now instead of
            // waiting up to an hour for the next scheduled cron. The sweep job
            // uses lockForUpdate so a concurrent hourly run won't double-batch.
            $this->dispatchInstantPayoutIfEligible($brandSettings, $affiliate);
        }

        // Capture the buyer as an affiliate contact — independent of commerce writes.
        $this->captureAffiliateContact($contactCapture, (string) $affiliate->id, $noteAttributes, $orderId);

        Log::info('ProcessShopifyOrderWebhookJob: processed', [
            'order_id' => $orderId,
            'shopify_event_id' => $this->shopifyEventId,
            'brand_professional_id' => $this->brandProfessionalId,
            'affiliate_id' => (string) $affiliate->id,
            'commission_cents' => $totalCommissionCents,
        ]);
    }

    /**
     * Dispatch the payout sweep immediately when a brand has opted into instant payouts
     * AND all payout prerequisites are met.
     *
     * v2 Option A eligibility (all required):
     *   - Brand store settings has payout_hold_days === 0 (the Instant tier).
     *   - Affiliate's Stripe Connect status is 'active' (transfer recipient ready).
     *   - Brand has a saved PaymentMethod (stripe_payment_method_id non-null) to fund the
     *     destination charge from.
     *   - Brand's Stripe Connect status is 'active' AND has a v2 Account ID.
     *
     * Without this fast path Instant brands wait up to 60 min for the next scheduled sweep,
     * so the feature is best-effort: a soft failure here just falls back to the cron.
     */
    private function dispatchInstantPayoutIfEligible(
        ?BrandStoreSettings $brandSettings,
        Professional $affiliate,
    ): void {
        if ($brandSettings?->payout_hold_days !== 0) {
            return;
        }

        if ($affiliate->stripe_connect_status !== 'active') {
            return;
        }

        $brand = Professional::query()
            ->whereKey($this->brandProfessionalId)
            ->first([
                'id',
                'stripe_connect_account_id',
                'stripe_connect_status',
                'stripe_payment_method_id',
            ]);

        if (! $brand
            || ! $brand->stripe_connect_account_id
            || $brand->stripe_connect_status !== 'active'
            || ! $brand->stripe_payment_method_id
        ) {
            return;
        }

        ProcessCommissionPayoutsJob::dispatch();

        Log::info('ProcessShopifyOrderWebhookJob: dispatched instant payout sweep', [
            'brand_professional_id' => $this->brandProfessionalId,
            'affiliate_id' => (string) $affiliate->id,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        report($e);

        Log::error('ProcessShopifyOrderWebhookJob exhausted all retries', [
            'brand_professional_id' => $this->brandProfessionalId,
            'shopify_event_id' => $this->shopifyEventId,
            'shopify_order_id' => (string) Arr::get($this->orderPayload, 'id', ''),
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * INSERT ... ON CONFLICT DO UPDATE WHERE EXCLUDED.shopify_updated_at > orders.shopify_updated_at.
     * The LWW guard is entirely in SQL — PHP never compares timestamps.
     */
    private function upsertOrder(
        string $orderId,
        string $shopDomain,
        string $brandProfessionalId,
        string $affiliateProfessionalId,
        int $grossCents,
        int $discountCents,
        int $commissionCents,
        float $commissionRate,
        string $rateSource,
        string $currency,
        array $lineItems,
        array $shopifyData,
        string $occurredAt,
        string $shopifyUpdatedAt,
    ): void {
        $now = now()->toIso8601String();
        $netCents = max(0, $grossCents - $discountCents);

        DB::connection('pgsql')->statement(<<<'SQL'
            INSERT INTO commerce.orders (
                shopify_order_id, shopify_shop_domain,
                brand_professional_id, affiliate_professional_id,
                status, gross_cents, discount_cents, refund_cents, net_cents,
                commission_cents, commission_rate, rate_source, currency_code,
                line_items, shopify_data,
                shopify_updated_at, occurred_at, created_at, updated_at
            ) VALUES (
                ?, ?,
                ?::uuid, ?::uuid,
                'approved', ?, ?, 0, ?,
                ?, ?, ?, ?,
                ?::jsonb, ?::jsonb,
                ?::timestamptz, ?::timestamptz, ?::timestamptz, ?::timestamptz
            )
            ON CONFLICT (shopify_shop_domain, shopify_order_id) DO UPDATE SET
                status           = 'approved',
                gross_cents      = EXCLUDED.gross_cents,
                discount_cents   = EXCLUDED.discount_cents,
                net_cents        = EXCLUDED.net_cents,
                commission_cents = EXCLUDED.commission_cents,
                commission_rate  = EXCLUDED.commission_rate,
                rate_source      = EXCLUDED.rate_source,
                currency_code    = EXCLUDED.currency_code,
                line_items       = EXCLUDED.line_items,
                shopify_data     = EXCLUDED.shopify_data,
                shopify_updated_at = EXCLUDED.shopify_updated_at,
                updated_at       = EXCLUDED.updated_at
            WHERE EXCLUDED.shopify_updated_at > commerce.orders.shopify_updated_at
        SQL, [
            $orderId, $shopDomain,
            $brandProfessionalId, $affiliateProfessionalId,
            $grossCents, $discountCents, $netCents,
            $commissionCents, $commissionRate, $rateSource, $currency,
            json_encode($lineItems), json_encode($shopifyData),
            $shopifyUpdatedAt, $occurredAt, $now, $now,
        ]);
    }

    /**
     * Insert one order_events row. The unique partial index on shopify_event_id
     * provides durable idempotency — catch the violation and no-op.
     */
    private function insertOrderEvent(
        string $orderId,
        string $eventType,
        string $shopifyEventId,
        string $source,
        array $metadata,
        string $shopifyTriggeredAt,
    ): void {
        try {
            (new OrderEvent)->forceFill([
                'order_id' => $orderId,
                'event_type' => $eventType,
                'source' => $source,
                'shopify_event_id' => $shopifyEventId !== '' ? $shopifyEventId : null,
                'shopify_triggered_at' => $shopifyTriggeredAt,
                'metadata' => $metadata,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Duplicate shopify_event_id — this webhook has already been processed.
            // The orders table LWW upsert above is also idempotent, so this is safe to skip.
        }
    }

    /**
     * Build line_items JSONB with pre-computed commission per line.
     * Returns [$enrichedItems, $totalGrossCents, $totalDiscountCents, $totalCommissionCents, $rate, $rateSource].
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @param  array<string, float|null>  $overrideMap
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int, 3: int, 4: float, 5: string}
     */
    private function buildEnrichedLineItems(
        array $lineItems,
        array $overrideMap,
        ?BrandStoreSettings $brandSettings,
        float $platformDefault,
        string $currency,
        string $orderId,
    ): array {
        $enriched = [];
        $totalGrossCents = 0;
        $totalDiscountCents = 0;
        $totalCommissionCents = 0;
        $resolvedRate = 0.0;
        $resolvedRateSource = 'platform_default';

        foreach ($lineItems as $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }

            $lineItemId = (string) Arr::get($lineItem, 'id', '');
            $unitPrice = (float) Arr::get($lineItem, 'price', 0);
            $quantity = (int) Arr::get($lineItem, 'quantity', 1);

            if ($lineItemId === '' || $unitPrice <= 0 || $quantity <= 0) {
                continue;
            }

            $productIdStr = (string) Arr::get($lineItem, 'product_id', '');
            $productGid = ($productIdStr !== '' && preg_match('/^\d+$/', $productIdStr))
                ? "gid://shopify/Product/{$productIdStr}"
                : '';

            [$rate, $rateSource] = $this->resolveCommissionRate(
                $productGid,
                $overrideMap,
                $brandSettings,
                $platformDefault,
            );
            $resolvedRate = $rate;
            $resolvedRateSource = $rateSource;

            $linePricePreDiscount = (int) round($unitPrice * $quantity * 100);
            $totalDiscountRaw = (float) Arr::get($lineItem, 'total_discount', 0);
            $lineDiscountCents = (int) round($totalDiscountRaw * 100);
            $lineTotalCents = max(0, $linePricePreDiscount - $lineDiscountCents);

            $lineCommissionCents = (int) round($lineTotalCents * ($rate / 100));

            $totalGrossCents += $lineTotalCents;
            $totalDiscountCents += $lineDiscountCents;
            $totalCommissionCents += $lineCommissionCents;

            $enriched[] = [
                'shopify_line_item_id' => $lineItemId,
                'shopify_product_id' => $productIdStr,
                'shopify_variant_id' => (string) Arr::get($lineItem, 'variant_id', ''),
                'sku' => (string) Arr::get($lineItem, 'sku', ''),
                'title' => (string) Arr::get($lineItem, 'title', ''),
                'quantity' => $quantity,
                'unit_price_cents' => (int) round($unitPrice * 100),
                'discount_cents' => $lineDiscountCents,
                'line_total_cents' => $lineTotalCents,
                'commission_cents' => $lineCommissionCents,
                'commission_rate' => $rate,
            ];
        }

        return [$enriched, $totalGrossCents, $totalDiscountCents, $totalCommissionCents, $resolvedRate, $resolvedRateSource];
    }

    /**
     * Extract Shopify product GIDs from line_items for the metafield API call.
     * Validates numeric-only IDs to prevent injection via tampered payloads.
     *
     * @param  array<int, mixed>  $lineItems
     * @return list<string>
     */
    private function extractProductGids(array $lineItems): array
    {
        $gids = [];
        foreach ($lineItems as $li) {
            if (! is_array($li)) {
                continue;
            }
            $productId = (string) Arr::get($li, 'product_id', '');
            if ($productId !== '' && preg_match('/^\d+$/', $productId)) {
                $gids[] = "gid://shopify/Product/{$productId}";
            }
        }

        return array_values(array_unique($gids));
    }

    /**
     * Build a PII-safe shopify_data snapshot for storage.
     * Full PII paths are redacted by RedactCustomerJob; here we exclude the
     * top-level customer object and billing/shipping addresses (contain name/email/phone).
     * Non-PII structural fields (financial_status, name, currency, etc.) are preserved
     * for reconciliation and display.
     */
    private function buildSafeShopifyData(array $payload): array
    {
        // Store non-PII fields for reconciliation. PII paths are enumerated in ADR 0001
        // and stripped later by GDPR jobs; storing them here risks leakage before redaction.
        $safe = [
            'id' => Arr::get($payload, 'id'),
            'name' => Arr::get($payload, 'name'),
            'financial_status' => Arr::get($payload, 'financial_status'),
            'fulfillment_status' => Arr::get($payload, 'fulfillment_status'),
            'total_price' => Arr::get($payload, 'total_price'),
            'subtotal_price' => Arr::get($payload, 'subtotal_price'),
            'total_discounts' => Arr::get($payload, 'total_discounts'),
            'total_tax' => Arr::get($payload, 'total_tax'),
            'currency' => Arr::get($payload, 'currency'),
            'created_at' => Arr::get($payload, 'created_at'),
            'updated_at' => Arr::get($payload, 'updated_at'),
        ];

        return array_filter($safe, fn ($v) => $v !== null);
    }

    /**
     * Upsert the buyer into the affiliate's contacts list and marketing subscribers.
     * Non-blocking: failures are logged and swallowed so they never fail commission processing.
     *
     * @param  array<int, array<string, mixed>>|mixed  $noteAttributes
     */
    private function captureAffiliateContact(
        ContactCaptureService $contactCapture,
        string $affiliateId,
        mixed $noteAttributes,
        string $orderId,
    ): void {
        $customer = Arr::get($this->orderPayload, 'customer', []);
        $billingAddress = Arr::get($this->orderPayload, 'billing_address', []);

        $email = trim((string) Arr::get($customer, 'email', ''));
        if ($email === '') {
            return;
        }

        $billingName = trim((string) Arr::get($billingAddress, 'name', ''));
        $firstName = trim((string) Arr::get($customer, 'first_name', ''));
        $lastName = trim((string) Arr::get($customer, 'last_name', ''));
        $fullName = $billingName !== '' ? $billingName : trim($firstName.' '.$lastName);
        $fullName = $fullName !== '' ? $fullName : null;

        $marketingConsent = $this->parseMarketingOptInAttribute($noteAttributes);

        $contactCapture->captureContact($affiliateId, [
            'email' => $email,
            'full_name' => $fullName,
            'phone' => (string) Arr::get($billingAddress, 'phone', ''),
            'source' => 'shopify_order',
            'external_id' => $orderId !== '' ? $orderId : null,
            'marketing_opt_in' => $marketingConsent === false ? false : null,
        ]);

        if ($marketingConsent === true) {
            $contactCapture->captureMarketingSubscription(
                $affiliateId,
                $email,
                $fullName,
                'shopify_order',
            );
        }
    }

    /**
     * Parse the `partna_marketing_opt_in` cart attribute into explicit tri-state.
     * true=explicit opt-in, false=explicit opt-out, null=missing or unrecognized.
     *
     * @param  array<int, array<string, mixed>>|mixed  $noteAttributes
     */
    private function parseMarketingOptInAttribute(mixed $noteAttributes): ?bool
    {
        $raw = strtolower($this->extractCartAttribute($noteAttributes, 'partna_marketing_opt_in'));
        if ($raw === '') {
            return null;
        }
        if (in_array($raw, ['true', '1', 'yes'], true)) {
            return true;
        }
        if (in_array($raw, ['false', '0', 'no'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Extract a value from Shopify note_attributes (cart-level attributes).
     */
    private function extractCartAttribute(mixed $noteAttributes, string $key): string
    {
        if (! is_array($noteAttributes)) {
            return '';
        }

        foreach ($noteAttributes as $attr) {
            if (is_array($attr) && strtolower(trim((string) ($attr['name'] ?? ''))) === $key) {
                return trim((string) ($attr['value'] ?? ''));
            }
        }

        return '';
    }

    /**
     * Resolve commission rate for a line item. Precedence:
     *   1. product metafield `sidest.commission_override` (brand-set per-product)
     *   2. brand.brand_store_settings.default_commission_rate (brand default)
     *   3. config('partna.store.default_commission_rate', 15) (platform fallback)
     *
     * @param  array<string, float|null>  $overrideMap
     * @return array{0: float, 1: string} [rate, rate_source]
     */
    private function resolveCommissionRate(
        string $productGid,
        array $overrideMap,
        ?BrandStoreSettings $brandSettings,
        float $platformDefault,
    ): array {
        if ($productGid !== '' && isset($overrideMap[$productGid]) && $overrideMap[$productGid] !== null) {
            $rate = (float) $overrideMap[$productGid];
            if ($rate > 0 && $rate <= 100) {
                return [$rate, 'metafield_override'];
            }

            // Present but out-of-bounds: use a valid rate for the maths but quarantine
            // from payout eligibility until ops corrects the metafield.
            Log::warning('shopify.commission_override.out_of_bounds', [
                'product_gid' => $productGid,
                'value' => $rate,
            ]);
            $fallbackRate = (float) ($brandSettings?->default_commission_rate ?? $platformDefault);

            return [$fallbackRate, 'pending'];
        }

        if ($brandSettings && $brandSettings->default_commission_rate !== null) {
            return [(float) $brandSettings->default_commission_rate, 'brand_default'];
        }

        return [$platformDefault, 'platform_default'];
    }
}
