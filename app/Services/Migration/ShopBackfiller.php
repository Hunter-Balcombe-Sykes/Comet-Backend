<?php

namespace App\Services\Migration;

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\ShopBrand;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\ShopProductProjection;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Slice 5a §3.4: land site.shop_brands / site.shop_products into content.*
 * through the slice-0b manual lane — never raw writes into content.items.
 *
 * Coord is manual:{sha1(url)}, NOT manual:{uuid} (§3.3): syncLatest() deletes
 * and re-inserts every product row each cycle, so the legacy uuid is a fresh
 * value per sync and would mint a new item every run.
 */
class ShopBackfiller
{
    public function __construct(
        private readonly ProjectionWriter $writer,
        // upsertStore() lives on ShopContentWriter, not here: syncLatest()
        // needs the same upsert in Task 6, and a sync path must not depend on
        // a Services/Migration/ class.
        private readonly ShopContentWriter $stores,
    ) {}

    /**
     * Fix round 4, Finding 2: this loop mirrors ShopContentWriter::syncStore()
     * exactly on a urlless product — same collection-namespaced coord
     * fallback, same "only skip when there is no identifier at all" rule. It
     * did not before, and the two writers disagreeing on identical input was
     * not merely untidy: upsertStore() still runs for the brand, so the
     * per-brand fallback in ShopController::hybridBrandMap() does NOT engage,
     * and a urlless product in an otherwise-normal store just vanished from
     * /brands, /selection and the public payload — with no self-healing for a
     * curated store that never re-syncs.
     *
     * `skipped_unidentifiable` (was `skipped_no_url`) counts only the genuinely
     * unidentifiable product — no url AND no productId — so the count keeps
     * naming what it actually holds.
     *
     * @return array{stores: int, products: int, skipped_unidentifiable: int, failed: int}
     */
    public function run(bool $dryRun = false, ?string $userId = null): array
    {
        $result = ['stores' => 0, 'products' => 0, 'skipped_unidentifiable' => 0, 'failed' => 0];
        $touchedSites = [];

        $brands = ShopBrand::query()
            ->with(['products', 'connection'])
            ->orderBy('position')
            ->get()
            ->when($userId !== null, fn ($c) => $c->filter(
                fn (ShopBrand $b) => (string) $b->connection?->user_id === $userId));

        foreach ($brands as $brand) {
            try {
                // Fail LOUDLY on an ownerless connection (parent §8.2) — a
                // silent skip is a store that vanishes from the count.
                $ownerId = $brand->connection?->user_id;
                if ($ownerId === null) {
                    throw new \RuntimeException("shop_brand {$brand->id}: connection or owner missing.");
                }

                if ($dryRun) {
                    $result['stores']++;
                    $result['products'] += $brand->products->count();

                    continue;
                }

                $collectionId = $this->stores->upsertStore($brand, (string) $ownerId);
                $result['stores']++;

                foreach ($brand->products as $product) {
                    $url = trim((string) ($product->data['url'] ?? ''));
                    $productId = trim((string) ($product->data['productId'] ?? ''));

                    if ($url !== '') {
                        $coord = ShopProductProjection::coordFor($url);
                    } elseif ($productId !== '') {
                        $coord = ShopProductProjection::coordForProductId($collectionId, $productId);
                    } else {
                        // Never silent: counted here AND logged, the same way
                        // syncStore() logs its own unidentifiable product.
                        $result['skipped_unidentifiable']++;
                        Log::warning('shop.backfill.unidentifiable_product', [
                            'shop_brand_id' => $brand->id,
                            'shop_product_id' => $product->id,
                        ]);

                        continue;
                    }

                    $itemId = $this->writer->writeManualItem(
                        (string) $ownerId,
                        $coord,
                        ShopProductProjection::fromBlob($product->data, $brand->currency),
                    );

                    $this->linkToCollection($collectionId, $itemId, (int) $product->position);
                    $result['products']++;
                }

                if (($siteId = $this->siteIdFor((string) $ownerId)) !== null) {
                    $touchedSites[$siteId] = true;
                }
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Shop backfill failed for one brand.', [
                    'shop_brand_id' => $brand->id, 'error' => $e->getMessage(),
                ]);
                $result['failed']++;
            }
        }

        if (! $dryRun) {
            $this->invalidate(array_keys($touchedSites));
        }

        return $result;
    }

    private function linkToCollection(string $collectionId, string $itemId, int $position): void
    {
        DB::table('content.collection_items')->upsert([[
            'collection_id' => $collectionId,
            'item_id' => $itemId,
            'source_id' => null,
            'position' => $position,
        ]], ['collection_id', 'item_id'], ['position']);
    }

    private function siteIdFor(string $userId): ?string
    {
        $id = DB::connection('pgsql')->table('site.sites')->where('user_id', $userId)->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Raw-write seam — all three lanes per touched site (spec §4).
     * writeManualItem() bumped build state per item already; updated_at and the
     * edge purge are the two lanes it deliberately does not own.
     *
     * @param  list<string>  $siteIds
     */
    private function invalidate(array $siteIds): void
    {
        foreach ($siteIds as $siteId) {
            BuildState::bump($siteId);
            DB::connection('pgsql')->table('site.sites')
                ->where('id', $siteId)->update(['updated_at' => now()]);
            $subdomain = (string) (DB::connection('pgsql')->table('site.sites')
                ->where('id', $siteId)->value('subdomain') ?? '');
            if ($subdomain !== '') {
                CloudflareCachePurgeJob::dispatch($subdomain);
            }
        }
    }
}
