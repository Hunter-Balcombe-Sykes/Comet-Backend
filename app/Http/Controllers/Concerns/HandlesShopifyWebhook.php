<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// Enforces the canonical Shopify webhook ingestion sequence:
//   1. HMAC verification (401 on failure)
//   2. JSON decode — 422 so Shopify retries; prevents silent event loss
//   3. SHOP-1 shop-domain cross-check — signed body vs unsigned header (400 on mismatch)
//   4. Atomic cache claim — no pre-check probe, so callers cannot infer dedup state
//      without a valid signature (Cache::add is the only dedup gate, not Cache::has)
//   5. Optional DB-level dedup (override claimWebhookEvent)
//   6. Shop domain lookup (200 if unknown)
//   7. dispatchWebhookJob — cache key released on failure so Shopify can retry
//
// Bugs fixed vs earlier controllers:
//   • Cache::has before HMAC → webhook-ID enumeration without a valid signature
//   • json_decode failure returning 200 → Shopify stops retrying, event lost permanently
//   • Uncaught dispatch exception left cache slot claimed → retries deduped, event lost
trait HandlesShopifyWebhook
{
    use DedupesShopifyWebhookEvent;
    use ValidatesShopifyWebhookHmac;

    /** Shopify topic string, e.g. "orders/paid". Used in log messages and DB dedup. */
    abstract protected function topic(): string;

    /**
     * Cache key prefix for atomic dedup, e.g. "shopify:webhook:order".
     * The trait appends ":{$webhookId}" to form the full key.
     */
    abstract protected function dedupCachePrefix(): string;

    /**
     * Dispatch the appropriate job. Only called after HMAC, dedup, shop lookup,
     * and JSON decode all succeed.
     */
    abstract protected function dispatchWebhookJob(
        ProfessionalIntegration $integration,
        array $payload,
        string $eventId,
    ): void;

    /**
     * Optional DB-level dedup hook. Returns true to proceed, false to short-circuit
     * as a duplicate. Override with:
     *   return $this->claimShopifyWebhookEvent($webhookId, $this->topic());
     * to enable the durable billing.webhook_events layer beneath the cache fast-path.
     */
    protected function claimWebhookEvent(string $webhookId): bool
    {
        return true;
    }

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signature = (string) $request->header('X-Shopify-Hmac-SHA256', '');
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');
        $eventId = (string) $request->header('X-Shopify-Event-Id', '');
        $shopDomain = mb_strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));

        // 1. HMAC first — dedup state is never exposed without a valid signature.
        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning("Shopify {$this->topic()} webhook: invalid HMAC signature", [
                'shop_domain' => $shopDomain,
            ]);

            return $this->error('invalid signature', 401);
        }

        // 2. JSON decode — 422 tells Shopify to retry, preventing permanent event
        //    loss. Done before the dedup claim so a malformed body does not burn a
        //    cache slot, which would dedup — and silently swallow — every retry.
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            Log::warning("Shopify {$this->topic()} webhook: malformed JSON body", [
                'shop_domain' => $shopDomain,
            ]);

            return $this->error('malformed payload', 422);
        }

        // 3. SHOP-1: the X-Shopify-Shop-Domain header is NOT covered by the HMAC —
        //    only the request body is. Resolve the authoritative shop identity from
        //    the signed payload and reject any delivery whose unsigned header
        //    disagrees, so a validly-signed webhook for shop A cannot be
        //    re-addressed to shop B by swapping the header. Checked before the
        //    dedup claim and the shop lookup so a spoofed delivery is refused
        //    outright (no slot to release). Order payloads carry no shop field —
        //    an empty payload domain skips the check (header-only, as before);
        //    the high-impact shop/update + uninstall payloads do carry it.
        $payloadDomain = mb_strtolower(trim(
            (string) ($payload['myshopify_domain'] ?? $payload['domain'] ?? '')
        ));
        if ($payloadDomain !== '' && $payloadDomain !== $shopDomain) {
            Log::warning("Shopify {$this->topic()} webhook: shop domain mismatch", [
                'header_shop_domain' => $shopDomain,
                'payload_shop_domain' => $payloadDomain,
            ]);

            return $this->error('shop_domain_mismatch', 400);
        }

        // 4. Atomic cache claim — Cache::add returns false if the key exists,
        //    deduplicating without a separate Cache::has probe.
        $cacheKey = null;
        if ($webhookId !== '') {
            $cacheKey = "{$this->dedupCachePrefix()}:{$webhookId}";
            if (! Cache::add($cacheKey, true, (int) config('partna.cache.ttls.webhook_idempotency'))) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }

        // 5. DB-level dedup (opt-in — override claimWebhookEvent to enable).
        if (! $this->claimWebhookEvent($webhookId)) {
            return $this->success(['received' => true, 'duplicate' => true]);
        }

        // 6. Shop domain lookup. Unknown domain is a soft 200 — not our shop.
        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            Log::warning("Shopify {$this->topic()} webhook: unknown shop domain", [
                'shop_domain' => $shopDomain,
            ]);

            return $this->success(['received' => true]);
        }

        // 7. Dispatch — release the cache slot on failure so Shopify can retry.
        //    Without this, an uncaught exception would leave the slot claimed and
        //    subsequent retries would be deduped, permanently losing the event.
        try {
            $this->dispatchWebhookJob($integration, $payload, $eventId);
        } catch (\Throwable $e) {
            if ($cacheKey !== null) {
                Cache::forget($cacheKey);
            }
            throw $e;
        }

        return $this->success(['received' => true]);
    }
}
