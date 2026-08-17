<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Shop\ShopContentWriter;
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
        private ?ShopContentWriter $content = null,
    ) {}

    public function fetch(IntegrationConnection $connection): array
    {
        // Auto-latest gate (2026-08-05: moved off the dropped site column and
        // onto the connection's own sparse display_settings — absent = ON,
        // the same predicate every fetch gate uses).
        if (data_get($connection->display_settings, 'auto_sync_latest') === false) {
            throw new FetchNotModifiedException('shop');
        }

        // Auto-latest ON → every non-individual store tracks its newest
        // products EXCEPT a brand the user hand-curated (#SEM-1) — that
        // per-brand fact is products_curated_at, not selection_mode (see the
        // class docblock for why selection_mode can't carry it).
        // ShopContentWriter::isCurated() replaces the whereNull() column
        // filter as of Task 6 (still reads the same live ShopBrand column —
        // see that method's docblock for why it isn't the content.storefronts
        // mirror).
        //
        // Re-home Task 2 dropped the 'products' eager load. #428 added it back
        // because syncLatest() reached the relation through
        // ShopBrand::toBrandArray() (since deleted), and the resulting
        // LazyLoadingViolationException failed this job on every multi-brand
        // connection. That method is gone and syncLatest() no longer touches
        // the relation at all, so eager-loading it now buys nothing. The
        // 'connection' load stays — syncLatest() reads connection->user_id,
        // and this IS the multi-row hydrate that arms strict mode.
        $content = $this->content ?? app(ShopContentWriter::class);
        $latestBrands = $connection->shopBrands()
            ->where('is_individual', false)
            ->with('connection')
            ->get()
            ->reject(fn ($b) => $content->isCurated($b->toStoreRecord(), (string) $connection->user_id));

        if ($latestBrands->isEmpty()) {
            throw new FetchNotModifiedException('shop');
        }

        // OBS-2: syncLatest() now RE-THROWS HttpException for a genuinely
        // unreachable store (blocked egress, 5xx, timeout) instead of
        // swallowing it as a plain null — so we can tell "blocked" apart from
        // "reachable but empty". One blocked store must not stop the rest of
        // the batch from syncing.
        $synced = 0;
        $failed = 0;
        foreach ($latestBrands as $brand) {
            try {
                if ($this->catalog->syncLatest($brand) !== null) {
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
