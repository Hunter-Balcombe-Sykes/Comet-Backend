<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Scheduled shop refresh: re-syncs every non-individual store's selection to
// the store's newest products WHEN the user's GLOBAL auto-latest is on
// (site.sites.shop_auto_latest — the per-brand selection_mode column is dormant
// as of 2026-07-08; the one site-level toggle decides for every store). When
// auto-latest is off, nothing is synced. The connection payload is a static
// marker (FOUND-25), so content changes live in the child shop_products rows —
// when any brand actually re-synced we purge the sitepage edge cache explicitly
// (the observer's payload-dirty gate can never fire for shop), and when
// nothing changed we signal 304 so the quiet bookkeeping path runs.
final readonly class ShopFetch implements FetchStrategy
{
    public function __construct(
        private ShopCatalog $catalog,
        private IntegrationConnectionCacheRefresher $refresher,
    ) {}

    public function fetch(IntegrationConnection $connection): array
    {
        // Global auto-latest gate (2026-07-08): off → no store auto-tracks
        // latest. Defaults ON when the site row predates the column. One
        // indexed lookup keyed by the connection's owner.
        $autoLatest = Site::query()
            ->where('user_id', $connection->user_id)
            ->value('shop_auto_latest');
        if ($autoLatest !== null && ! $autoLatest) {
            throw new FetchNotModifiedException('shop');
        }

        // Auto-latest ON → every non-individual store tracks its newest
        // products (the per-brand selection_mode is ignored under the global).
        $latestBrands = $connection->shopBrands()
            ->where('is_individual', false)
            ->with('products')
            ->get();

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
