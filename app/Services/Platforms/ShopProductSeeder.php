<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Job-context seeding of ONE individually-added product from a scanned product
// page (signup-v2 C2). Mirrors ShopController::addProduct()'s locked body —
// the reserved 'individual' bucket, MAX_INDIVIDUAL_PRODUCTS cap, dedup by
// productId, newest-first ordering — without its HTTP/policy wrapper (same
// convention as EventsSeeder/ShopBrandSeeder; see their docblocks for the
// server-derived-user + caller-gating contract).
//
// Slice 5a: the products live in content.* now, so this is no longer a
// "transactional rebuild" of site.shop_products. The existing half is read
// back through ShopContentWriter::currentCatalogue() and the merged set is
// reconciled by syncStore(), which upserts by coord and retires (never
// deletes) what the merge dropped. site.shop_brands is still written as the
// bucket anchor until slice 7 retires it.
class ShopProductSeeder
{
    /** Mirrors ShopController::MAX_INDIVIDUAL_PRODUCTS — keep in lockstep. */
    private const MAX_INDIVIDUAL_PRODUCTS = 20;

    public function __construct(
        private readonly IntegrationConnectionCacheRefresher $refresher,
        private readonly ShopContentWriter $content,
        private readonly ShopConnections $shop,
    ) {}

    /**
     * @param  array<string,mixed>  $product  GenericShopScraper::readProductPage()'s product shape
     */
    public function seed(User $user, array $product): bool
    {
        // Tombstone parity with ShopBrandSeeder: an explicitly-removed shop
        // connection is never resurrected by a scan.
        //
        // Convergence Phase 6: matched across the whole shop family, not the
        // retired 'shop' slug. A user who disconnected their shop after the
        // split has tombstoned per-store rows and no 'shop' row at all, so the
        // old match found neither the tombstone nor a live row — and the guard
        // silently stopped guarding.
        $surfaces = [...ShopConnections::surfaces(), ShopConnections::LEGACY_SURFACE];
        $wasDisconnected = IntegrationConnection::onlyTrashed()
            ->where('user_id', $user->id)->whereIn('surface_key', $surfaces)
            ->exists();
        $hasLive = IntegrationConnection::query()
            ->where('user_id', $user->id)->whereIn('surface_key', $surfaces)
            ->exists();
        if ($wasDisconnected && ! $hasLive) {
            return false;
        }

        $key = CacheKeyGenerator::platformConnectionLock('shop', (string) $user->id);
        try {
            $written = Cache::lock($key, 10)->block(5, function () use ($user, $product): bool {
                // The individual-products bucket is not a store, so it anchors
                // on `partna.manual_product` — hidden, dormant and explicitly
                // NOT retired (§16 names it "the manual product add-path"),
                // which is exactly this case. `partna.storefront` is retired.
                $connection = $this->shop->individualAnchor($user);

                $maxPosition = $this->shop->brands($user)->max('position');
                $individual = ShopBrand::firstOrCreate(
                    ['connection_id' => $connection->id, 'brand_id' => ShopBrand::INDIVIDUAL_BRAND_ID],
                    [
                        'provider' => ShopProviderDetector::PROVIDER_GENERIC,
                        'url' => '',
                        'source_url' => '',
                        'currency' => $product['currency'] ?? null,
                        'discount_code' => '',
                        'is_individual' => true,
                        'position' => ($maxPosition === null ? -1 : $maxPosition) + 1,
                    ],
                );

                $productId = $product['productId'] ?? null;

                // Task 6 fix round 1, Finding 1: the "existing" half now
                // reads content.* (ShopContentWriter::currentCatalogue()),
                // not legacy site.shop_products — this seeder no longer
                // writes that table, so a second seed() call for the same
                // user (CommerceProbeJob fires once per resolved link, so
                // this is a real, non-racy path, not a corner case) would
                // otherwise read a frozen snapshot missing whatever the
                // FIRST call wrote, and syncStore()'s retire-absent would
                // silently drop it. Newest first, de-duped by productId,
                // capped — byte-for-byte the addProduct() ordering contract.
                $collectionId = $this->content->upsertStore($individual->toStoreRecord(), (string) $user->id);
                $ordered = collect($this->content->currentCatalogue($collectionId))
                    ->reject(fn (array $p) => ($p['productId'] ?? null) === $productId)
                    ->prepend($product)
                    ->take(self::MAX_INDIVIDUAL_PRODUCTS)
                    ->values();

                $this->content->syncStore((string) $user->id, $collectionId, $ordered->all(), $individual->currency);

                // Final review F4: lane 2. writeManualItem() covers the build
                // state and refresh() covers the edge, but nothing moved
                // site.sites.updated_at — the column
                // IndividualProfilePayloadBuilder composes its 60s cache key
                // from — so a freshly seeded product stayed invisible to the
                // public payload for the full TTL. Raw DB::table() and
                // site-nullable for the same reasons as
                // ShopController::bumpSiteCache().
                DB::connection('pgsql')->table('site.sites')
                    ->where('user_id', $user->id)
                    ->update(['updated_at' => now()]);

                $this->refresher->refresh($connection);

                return true;
            });
        } catch (LockTimeoutException) {
            Log::warning('shop_product_seeder.lock_timeout', ['user_id' => (string) $user->id]);

            return false;
        }

        return $written;
    }
}
