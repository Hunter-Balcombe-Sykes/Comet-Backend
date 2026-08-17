<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\StoreRecord;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Scheduled shop refresh: re-syncs every non-individual store's selection to
// the store's newest products WHEN the user's GLOBAL auto-latest is on
// (site.sites.shop_auto_latest — the one site-level toggle gates every
// store). Within that, a brand whose product list the user hand-picked
// (site.shop_brands.products_curated_at IS NOT NULL, stamped by
// ShopController::setProducts) is skipped — #SEM-1: the guard used to be
// selection_mode='manual', but that column's default IS 'manual' and
// addBrand() never sets it, so it could never distinguish "curated" from
// "never touched". When auto-latest is off, nothing is synced. The
// connection payload is a static marker (FOUND-25), so content changes live
// in content.* now (Task 6) — when any brand actually re-synced we purge the
// sitepage edge cache explicitly (the observer's payload-dirty gate can
// never fire for shop), and when nothing changed we signal 304 so the quiet
// bookkeeping path runs.
final readonly class ShopFetch implements FetchStrategy
{
    public function __construct(
        private ShopCatalog $catalog,
        private IntegrationConnectionCacheRefresher $refresher,
        // Nullable + container-fallback: ShopSyncFailureObservabilityTest and
        // ShopGlobalSettingsTest construct ShopFetch by hand with just
        // ($catalog, $refresher) — a required 3rd param would fail
        // construction for every one of those, not just the curation ones.
        // Re-home Task 7 dropped the ShopContentWriter that used to sit here:
        // its only use was isCurated(), and the curation stamp now rides on the
        // record the read already returns.
        private ?ShopConnections $shop = null,
    ) {}

    /** Real ShopConnections, or the container's when constructed without one. */
    private function shop(): ShopConnections
    {
        return $this->shop ?? app(ShopConnections::class);
    }

    public function fetch(IntegrationConnection $connection): array
    {
        // Auto-latest gate, KEPT under the 2026-08-17 opt-in shape — and it
        // now guards something new as well: this fetch reconciles each store
        // to its newest-N window and RETIRES what fell out (retireAbsent), so
        // with pins as the publish mechanism a scheduled run could pull a
        // pinned old product off the site. OFF (the new connect default)
        // means the catalogue stays as connected; the connect-time fill
        // (ShopBrandConnectJob) does not come through here, so the library
        // still populates. ON means the store tracks its newest — and the
        // read-time storefront arm (SectionCandidates) publishes that newest.
        if (data_get($connection->display_settings, 'auto_sync_latest') === false) {
            throw new FetchNotModifiedException('shop');
        }

        // Auto-latest ON → every non-individual store tracks its newest
        // products EXCEPT a store the user hand-curated (#SEM-1) — that
        // per-store fact is products_curated_at, not selection_mode (see the
        // class docblock for why selection_mode can't carry it).
        //
        // Re-home Task 7: the family comes off content.* via
        // ShopConnections::stores(), not $connection->shopBrands(). Three
        // hazards go with that relation. #428's eager-load requirement is moot
        // (nothing lazy-loads from a DTO). isCurated()'s own lookup is moot —
        // products_curated_at is ON the record now, so the filter is a property
        // read rather than a query per store. And the read is USER-scoped where
        // it used to be connection-scoped, which is what it always wanted:
        // one connection per store means a connection-scoped read saw exactly
        // one of the user's stores.
        $user = $connection->user;
        if ($user === null) {
            throw new FetchNotModifiedException('shop');
        }

        $ownerId = (string) $connection->user_id;
        $latestStores = $this->shop()->stores($user)
            ->filter(fn (StoreRecord $s): bool => $s->isIndividual === false && $s->productsCuratedAt === null);

        if ($latestStores->isEmpty()) {
            throw new FetchNotModifiedException('shop');
        }

        // OBS-2: syncLatest() now RE-THROWS HttpException for a genuinely
        // unreachable store (blocked egress, 5xx, timeout) instead of
        // swallowing it as a plain null — so we can tell "blocked" apart from
        // "reachable but empty". One blocked store must not stop the rest of
        // the batch from syncing.
        $synced = 0;
        $failed = 0;
        foreach ($latestStores as $store) {
            try {
                if ($this->catalog->syncLatest($store, $ownerId) !== null) {
                    $synced++;
                }
            } catch (HttpException) {
                $failed++;
            }
        }

        if ($synced > 0) {
            $this->refresher->refresh($connection);

            return $connection->payload ?? ['storage' => 'relational'];
        }

        if ($failed > 0) {
            // Every latest-mode store was unreachable this cycle — a real
            // failure signal, not quiet bookkeeping. PlatformRefresher routes
            // this to status='unavailable' + consecutive_failures++, tripping
            // the circuit breaker instead of resetting it to 0.
            throw new FetchUnavailableException('shop_all_unreachable');
        }

        // Every latest-mode store was reachable but genuinely had no products
        // — selections untouched, nothing to publish. This IS the quiet 304
        // path; no store was actually broken.
        throw new FetchNotModifiedException('shop');
    }
}
