<?php

namespace App\Http\Controllers\Api\Webhooks\Shopify;

use App\Enums\BrandStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ValidatesShopifyWebhookHmac;
use App\Jobs\Shopify\PurgeAffiliateProductSelectionsJob;
use App\Models\Brand\BrandStoreSettings;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Receives Shopify app/uninstalled webhooks. Clears access token and marks integration as disconnected.
class ShopifyAppUninstalledWebhookController extends ApiController
{
    use ValidatesShopifyWebhookHmac;

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signature = (string) $request->header('X-Shopify-Hmac-SHA256', '');
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');
        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));

        // 1. HMAC first — dedup state is never exposed without a valid signature.
        //    Putting dedup or any state check before HMAC would let unauthenticated
        //    callers probe whether a webhook ID is known.
        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning('Shopify app/uninstalled webhook: invalid HMAC signature', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->error('invalid signature', 401);
        }

        // 2. SHOP-1: cross-check the signed body against the unsigned shop-domain
        //    header before claiming a dedup slot or touching the DB. The
        //    app/uninstalled payload is the shop resource — myshopify_domain /
        //    domain are HMAC-protected; X-Shopify-Shop-Domain is not. Without
        //    this, a brand that owns a Shopify store can replay its own
        //    legitimately-signed uninstall webhook with the header swapped to
        //    another brand's domain and disconnect that brand's integration.
        $payload = json_decode($rawBody, true);
        $payloadDomain = is_array($payload)
            ? strtolower(trim((string) ($payload['myshopify_domain'] ?? $payload['domain'] ?? '')))
            : '';
        if ($payloadDomain !== '' && $payloadDomain !== $shopDomain) {
            Log::warning('Shopify app/uninstalled webhook: shop domain mismatch', [
                'header_shop_domain' => $shopDomain,
                'payload_shop_domain' => $payloadDomain,
            ]);

            return $this->error('shop_domain_mismatch', 400);
        }

        // 3. Cache-backed dedup gate (SEC-2 / LIFE-2). Cache::add is atomic on Redis
        //    (SETNX), so concurrent retries cannot both pass. Mirrors the canonical
        //    pattern in HandlesShopifyWebhook — inlined here because this controller
        //    pre-dates the trait and does inline mutations rather than dispatching a
        //    job. X-Shopify-Webhook-Id is absent only for manual / test deliveries;
        //    those fall through and rely on the secondary metadata guard below.
        $cacheKey = null;
        if ($webhookId !== '') {
            $cacheKey = 'shopify:webhook:app-uninstalled:'.$webhookId;
            if (! Cache::add($cacheKey, true, (int) config('partna.cache.ttls.webhook_idempotency'))) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }

        // 4. Release the cache slot on any post-dedup failure so Shopify's retry can
        //    succeed instead of being silently swallowed for the TTL window (~24h).
        //    Mirrors HandlesShopifyWebhook's try/catch + Cache::forget invariant.
        try {
            $result = DB::transaction(function () use ($shopDomain) {
                // 5. Lock the integration row inside the transaction so the read of
                //    disconnected_at (column, post-DATA-2) and the subsequent token-null
                //    write are atomic against EmbeddedSetupController::provisionShopifyIntegration
                //    (which uses the same lockForUpdate pattern on reinstall). Without
                //    this, a late-arriving uninstall webhook with a distinct webhook
                //    ID can race a reinstall: layer-1 cache dedup misses (new ID),
                //    the disconnected_at guard reads a pre-reinstall snapshot, then
                //    overwrites the freshly-issued access token.
                $integration = ProfessionalIntegration::query()
                    ->where('shopify_shop_domain', $shopDomain)
                    ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
                    ->lockForUpdate()
                    ->first();

                if (! $integration) {
                    return ['action' => 'unknown_shop'];
                }

                $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

                // 6. Secondary idempotency guard, now inside the lock. Durable across
                //    cache TTL expiry; if the second delivery lands after the cache
                //    forgets the webhook ID, the disconnected_at column still wins.
                if ($integration->disconnected_at !== null) {
                    return ['action' => 'duplicate'];
                }

                // Labels/audit-trail JSONB: the reason tag and pre-uninstall
                // wizard snapshot. The state itself — disconnected_at + the
                // 'uninstalled' webhook_registration_state — lives on dedicated
                // columns (DATA-2). Preserve pre-uninstall state so the brand
                // can resume where they left off on reinstall.
                $metadata['disconnected_reason'] = 'app_uninstalled';

                $brandProfile = BrandProfile::where('professional_id', $integration->professional_id)->first();
                $storeSettings = BrandStoreSettings::where('professional_id', $integration->professional_id)->first();

                $metadata['uninstalled_from_status'] = $brandProfile?->brand_status ?? 'onboarding';
                $metadata['uninstalled_wizard_state'] = [
                    'hydrogen_install_confirmed' => (bool) ($storeSettings?->hydrogen_install_confirmed ?? false),
                    'oxygen_deployment_token_set' => ! empty($storeSettings?->getRawOriginal('oxygen_deployment_token')),
                    'oxygen_storefront_id_present' => ! empty($storeSettings?->oxygen_storefront_id),
                ];

                $integration->update([
                    'access_token' => null,
                    'refresh_token' => null,
                    'provider_metadata' => $metadata,
                    'disconnected_at' => now(),
                    'webhook_registration_state' => 'uninstalled',
                ]);

                // Set brand to disconnected without clearing wizard progress —
                // preserve wizard state so the brand doesn't have to re-enter
                // profile/business details on reinstall.
                BrandProfile::where('professional_id', $integration->professional_id)
                    ->update([
                        'brand_status' => BrandStatus::Disconnected->value,
                        'setup_complete' => false,
                    ]);

                return [
                    'action' => 'disconnected',
                    'professional_id' => (string) $integration->professional_id,
                ];
            });
        } catch (\Throwable $e) {
            if ($cacheKey !== null) {
                Cache::forget($cacheKey);
            }
            throw $e;
        }

        if ($result['action'] === 'unknown_shop') {
            Log::warning('Shopify app/uninstalled webhook: unknown shop domain', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->success(['received' => true]);
        }

        if ($result['action'] === 'duplicate') {
            return $this->success(['received' => true, 'duplicate' => true]);
        }

        // 7. Dispatch the purge AFTER the transaction commits. Inside the closure a
        //    queue-connection failure would still commit the DB writes but lose the
        //    dispatch; here, either both happen or neither (the catch above releases
        //    the cache slot so Shopify retries the whole flow).
        //
        // Master Pattern 16 (DB-F#SCALE-3): a single-statement delete on a brand with
        // thousands of selections held row locks long enough to block concurrent
        // orders/paid commission lookups. The job chunks by primary-key cursor
        // (CHUNK_SIZE = 500), so no chunk holds locks for more than ~500 rows.
        //
        // Why purge at all: selections reference Shopify product GIDs that will go
        // stale the moment the brand reinstalls (new IDs) or stay stale if they
        // never do — either way they're not meaningful while the integration is
        // torn down.
        //
        // Note: we can't call the Shopify Admin API from here because the access
        // token has already been revoked by Shopify BEFORE this webhook fires.
        // Metafield definitions, collections, and the storefront access token
        // will remain in the brand's store unless they clicked "Disconnect" in
        // the Partna dashboard first (that path runs the full teardown via
        // ShopifyTeardownService while the token is still alive). See
        // docs/brand-catalog-v2.md for the recommended disconnect flow.
        PurgeAffiliateProductSelectionsJob::dispatch($result['professional_id']);

        Log::info('Shopify app uninstalled — integration disconnected.', [
            'professional_id' => $result['professional_id'],
            'shop_domain' => $shopDomain,
        ]);

        return $this->success(['received' => true]);
    }
}
