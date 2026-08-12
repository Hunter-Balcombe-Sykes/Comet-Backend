<?php

namespace App\Services\Shop;

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\ShopBrand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slice 5a §3.1/§3.5: the one place that upserts a site.shop_brands row into
 * its content.collections + content.storefronts shape (upsertStore()), and
 * the one place that reconciles a store's product set into content.items on
 * a re-fetch (syncStore()). Both live here, not on ShopBackfiller,
 * Services/Migration/, because a sync path must not depend on a
 * Services/Migration/ class — ShopBackfiller injects this and calls the same
 * upsertStore().
 *
 * syncStore() replaces ShopCatalog::syncLatest()'s delete-then-insert. A
 * literal port of that transaction into content.* would re-mint item ids
 * every sync, breaking analytics.item_views references and any curation
 * pin — so it upserts by coord and retires (items.removed_at) what the
 * fetched catalogue no longer carries. Never deletes.
 */
class ShopContentWriter
{
    public function __construct(private readonly ProjectionWriter $writer) {}

    /**
     * Idempotent: keyed by (user_id, provider, external_ref) — external_ref
     * is site.shop_brands.brand_id, the PROVIDER's own store id (half of
     * shop_brands_connection_id_brand_id_key), stable across a rename.
     *
     * The label (the brand's display name) was the ORIGINAL key and is a
     * bug: it's a mutable, user-editable field (ShopController::updateBrand
     * writes site.shop_brands.name freely). A rename between two upsertStore()
     * calls — which Task 6's syncStore() makes on every scheduled cycle —
     * missed the old lookup, minting a second content.collections +
     * content.storefronts pair and orphaning the first, taking its
     * referral_query/discount_code (affiliate revenue) with it while both
     * rows stayed linked to the same product items.
     */
    public function upsertStore(ShopBrand $brand, string $ownerId): string
    {
        $externalRef = (string) $brand->brand_id;

        $existing = DB::table('content.collections as c')
            ->join('content.storefronts as s', 's.collection_id', '=', 'c.id')
            ->where('c.user_id', $ownerId)
            ->where('c.kind', 'storefront')
            ->where('s.provider', (string) $brand->provider)
            ->where('s.external_ref', $externalRef)
            ->value('c.id');

        $collectionId = (string) ($existing ?? Str::uuid());

        DB::table('content.collections')->upsert([[
            'id' => $collectionId,
            'user_id' => $ownerId,
            'parent_id' => null,
            'label' => (string) ($brand->name ?? $brand->brand_id),
            'kind' => 'storefront',
            'position' => (int) $brand->position,
            'is_user_created' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['id'], ['label', 'position', 'updated_at']);

        DB::table('content.storefronts')->upsert([[
            'collection_id' => $collectionId,
            'provider' => (string) $brand->provider,
            'external_ref' => $externalRef,
            'url' => $brand->url,
            'source_url' => $brand->source_url,
            'currency' => $brand->currency,
            'discount_code' => $brand->discount_code,
            'referral_query' => (string) ($brand->referral_query ?? ''),
            'is_individual' => (bool) $brand->is_individual,
            'fetch_mode' => $brand->fetch_mode,
            'connect_status' => $brand->connect_status,
            'connect_error' => $brand->connect_error,
            'products_curated_at' => $brand->products_curated_at,
            'logo_url' => $brand->logo,
            'favicon_url' => $brand->favicon,
            'logo_mark_url' => $brand->logo_mark_url,
            'logo_mark_svg_url' => $brand->logo_mark_svg_url,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['collection_id'], [
            'provider', 'external_ref', 'url', 'source_url', 'currency', 'discount_code',
            'referral_query', 'is_individual', 'fetch_mode', 'connect_status',
            'connect_error', 'products_curated_at', 'logo_url', 'favicon_url',
            'logo_mark_url', 'logo_mark_svg_url', 'updated_at',
        ]);

        return $collectionId;
    }

    /**
     * ShopProductProjection::fromBlob() reads currency/productId/image with a
     * direct array access (no ??) — makeShopProduct()'s fixture builder notes
     * that a real scraped blob always carries these (possibly null), but a
     * live catalogue fetch is not guaranteed to. Filled in here rather than
     * loosening fromBlob() itself, which stays a strict reader of the shape
     * ShopBackfiller's DB-round-tripped blobs already satisfy.
     */
    private const BLOB_DEFAULTS = ['currency' => null, 'productId' => null, 'image' => null];

    /**
     * Reconcile a store's fetched catalogue into content.items: upsert every
     * live product by coord, then retire (items.removed_at) whatever this
     * collection still links that the fetch no longer carries. Returns the
     * count written (post-dedupe, not the raw input count).
     *
     * @param  list<array<string,mixed>>  $products  raw scraper blobs, catalogue order
     */
    public function syncStore(string $userId, string $collectionId, array $products, ?string $currency): int
    {
        $seen = [];
        $written = 0;

        foreach ($products as $position => $blob) {
            $url = trim((string) ($blob['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $coord = ShopProductProjection::coordFor($url);
            // §1.7: one coord per canonical URL per user. Two catalogue entries
            // sharing a URL would poison that key for the whole resolution run,
            // so the dedupe happens BEFORE writing, not after.
            if (isset($seen[$coord])) {
                continue;
            }
            $seen[$coord] = true;

            $itemId = $this->writer->writeManualItem(
                $userId, $coord, ShopProductProjection::fromBlob($blob + self::BLOB_DEFAULTS, $currency));

            DB::table('content.collection_items')->upsert([[
                'collection_id' => $collectionId,
                'item_id' => $itemId,
                'source_id' => null,
                'position' => $position,
            ]], ['collection_id', 'item_id'], ['position']);

            $written++;
        }

        $this->retireAbsent($collectionId, array_keys($seen));

        return $written;
    }

    /**
     * Items still linked to this store but absent from the fetched catalogue.
     * items.removed_at ONLY — source_items.removed_at is cleared on
     * reappearance (ProjectionWriter::upsertSourceItem()), so writing it
     * there would resurrect a product the owner deliberately removed. And
     * never a hard delete: analytics.item_views references item ids, and
     * mergeInto()'s curation check reads site.section_items.
     *
     * @param  list<string>  $liveCoords
     */
    private function retireAbsent(string $collectionId, array $liveCoords): void
    {
        $absent = DB::table('content.collection_items as ci')
            ->join('content.source_items as si', 'si.item_id', '=', 'ci.item_id')
            ->where('ci.collection_id', $collectionId)
            ->when($liveCoords !== [], fn ($q) => $q->whereNotIn('si.coord', $liveCoords))
            ->pluck('ci.item_id')
            ->unique()
            ->all();

        if ($absent === []) {
            return;
        }

        DB::table('content.items')->whereIn('id', $absent)->whereNull('removed_at')
            ->update(['removed_at' => now(), 'updated_at' => now()]);
    }
}
