<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Scheduled shop refresh: re-syncs every latest-mode brand's selection to the
// store's newest products. Manual-mode brands are untouched (their selection
// is the user's hand-picked set). The connection payload is a static marker
// (FOUND-25), so content changes live in the child shop_products rows — when
// any brand actually re-synced we purge the sitepage edge cache explicitly
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
        $latestBrands = $connection->shopBrands()
            ->where('selection_mode', 'latest')
            ->where('is_individual', false)
            ->with('products')
            ->get();

        if ($latestBrands->isEmpty()) {
            throw new FetchNotModifiedException;
        }

        $synced = 0;
        foreach ($latestBrands as $brand) {
            if ($this->catalog->syncLatest($brand) !== null) {
                $synced++;
            }
        }

        if ($synced === 0) {
            // Every latest-mode store was unreachable this cycle — selections
            // untouched, nothing to publish.
            throw new FetchNotModifiedException;
        }

        $this->refresher->refresh($connection);

        return $connection->payload ?? ['storage' => 'relational'];
    }
}
