<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\Concerns\JitteredTtl;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// Job-context seeding of a ShopBrand from a scanned store link (signup-v2 C2).
// Mirrors ShopController::addBrand()'s post-detection write mechanics — the
// marker connection anchor, MAX_BRANDS cap, updateOrCreate by brand_id,
// position assignment, catalog-cache warm — without its HTTP/policy wrapper,
// same parallel-implementation convention as EventsSeeder (see its docblock).
//
// Contract: $user MUST be server-derived (queue-job userId, never request
// input); DISC-7 capability gating is the caller's responsibility. Detection
// ($detected) arrives pre-resolved from ShopProviderDetector — this class
// never probes.
class ShopBrandSeeder
{
    use JitteredTtl;

    /** Mirrors ShopController::MAX_BRANDS — keep in lockstep. */
    private const MAX_BRANDS = 5;

    /** Mirrors ShopController::CATALOG_TTL_MINUTES — keep in lockstep. */
    private const CATALOG_TTL_MINUTES = 10;

    /** Mirrors ShopController::MARKER (FOUND-25 relational storage anchor). */
    private const MARKER = ['storage' => 'relational'];

    public function __construct(
        private readonly ShopifyScraper $shopify,
        private readonly WooCommerceScraper $woocommerce,
        private readonly SquarespaceScraper $squarespace,
        private readonly IntegrationConnectionCacheRefresher $refresher,
    ) {}

    /**
     * @param  array{provider:string, origin:string, sourceUrl:string, page:array|null, store:array|null}  $detected
     */
    public function seed(User $user, array $detected): ?ShopBrand
    {
        // Tombstone: the user explicitly removed their shop connection before —
        // a scan must never resurrect it (same rule the socials seeds follow).
        $wasDisconnected = IntegrationConnection::onlyTrashed()
            ->where('user_id', $user->id)->where('platform', 'shop')
            ->exists();
        $hasLive = IntegrationConnection::query()
            ->where('user_id', $user->id)->where('platform', 'shop')
            ->exists();
        if ($wasDisconnected && ! $hasLive) {
            return null;
        }

        // Brand profile fetch (name/logo/currency + generic pages' products) —
        // mirrors ShopController::brandProfileFor(), outside the lock (slow HTTP).
        [$brand, $detectedProducts] = match ($detected['provider']) {
            ShopProviderDetector::PROVIDER_WOOCOMMERCE => [$this->woocommerce->fetchBrand($detected['origin']), null],
            ShopProviderDetector::PROVIDER_SQUARESPACE => [$this->squarespace->fetchBrand($detected['sourceUrl']), null],
            ShopProviderDetector::PROVIDER_BIGCARTEL => [$detected['store'], null],
            ShopProviderDetector::PROVIDER_GENERIC => [$detected['page']['brand'] ?? null, $detected['page']['products'] ?? null],
            default => [$this->shopify->fetchBrand($detected['origin']), null],
        };
        if (! is_array($brand) || ! isset($brand['id'])) {
            return null;
        }
        $id = (string) $brand['id'];

        $key = CacheKeyGenerator::platformConnectionLock('shop', (string) $user->id);
        try {
            $row = Cache::lock($key, 10)->block(5, function () use ($user, $detected, $brand, $id): ?ShopBrand {
                $connection = IntegrationConnection::updateOrCreate(
                    ['user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop'],
                    [
                        'payload' => self::MARKER,
                        'is_active' => true,
                        'last_refreshed_at' => now(),
                        'last_refresh_status' => 'ok',
                        'last_refresh_error' => null,
                        'consecutive_failures' => 0,
                    ],
                );

                $existing = ShopBrand::where('connection_id', $connection->id)->where('brand_id', $id)->first();
                $storeCount = ShopBrand::where('connection_id', $connection->id)->where('is_individual', false)->count();
                if (! $existing && $storeCount >= self::MAX_BRANDS) {
                    Log::info('shop_brand_seeder.cap', ['user_id' => (string) $user->id]);

                    return null;
                }

                $maxPosition = ShopBrand::where('connection_id', $connection->id)->max('position');
                $position = $existing?->position ?? (($maxPosition === null ? -1 : $maxPosition) + 1);

                // Phase 12 (2026-07-25): a store link shared with a discount or
                // referral param keeps it. Read from sourceUrl — the URL as
                // scanned, before detect() normalised it to an origin.
                // `?:` not `??` on the existing values: both columns default to
                // '' rather than NULL, so `??` would treat an empty string as
                // "already set" and never apply the scanned code. Applying only
                // when empty is what "never overwrite an existing code" means —
                // a discount the user typed by hand survives every re-scrape.
                $scanned = UrlParamExtractor::extract($detected['sourceUrl']);

                return ShopBrand::updateOrCreate(
                    ['connection_id' => $connection->id, 'brand_id' => $id],
                    [
                        'provider' => $detected['provider'],
                        'url' => $detected['origin'],
                        'source_url' => $detected['sourceUrl'],
                        'name' => $brand['name'] ?? null,
                        'currency' => $brand['currency'] ?? null,
                        'favicon' => $brand['favicon'] ?? null,
                        'logo' => $brand['logo'] ?? null,
                        'discount_code' => $existing?->discount_code ?: ($scanned['discountCode'] ?? ''),
                        'referral_query' => $existing?->referral_query ?: ($scanned['referralQuery'] ?? ''),
                        'is_individual' => false,
                        'position' => $position,
                    ],
                );
            });
        } catch (LockTimeoutException) {
            Log::warning('shop_brand_seeder.lock_timeout', ['user_id' => (string) $user->id]);

            return null;
        }

        if ($row === null) {
            return null;
        }

        // Warm the picker catalog when detection already read the products
        // (generic storefronts) — mirrors addBrand()'s own warm.
        if (is_array($detectedProducts)) {
            Cache::put(
                CacheKeyGenerator::shopifyBrandCatalog($id),
                $detectedProducts,
                self::applyJitter(self::CATALOG_TTL_MINUTES * 60),
            );
        }

        // Purge the sitepage edge cache so the new brand surfaces — the marker
        // payload never changes, so the observer's payload-dirty gate won't
        // fire on re-adds (mirrors the controller's explicit refresher call).
        $connection = IntegrationConnection::query()
            ->where('user_id', $user->id)->where('platform', 'shop')->where('resource_id', 'shop')
            ->first();
        if ($connection !== null) {
            $this->refresher->refresh($connection);
        }

        return $row;
    }
}
