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
            // Seeds this column on INSERT only. Fix round 1, Finding 2: the
            // UPDATE path below does NOT list this column plainly (which
            // would mean "always overwrite from $brand") — content.storefronts
            // is becoming the source of truth for #SEM-1 (Task 8 stops
            // writing site.shop_brands entirely), and every routine sync
            // calls upsertStore(). An unconditional overwrite here would let
            // the next sync silently clobber a value Task 8 stamped directly
            // on this row back to whatever the (eventually frozen) legacy
            // column says.
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
            'connect_error', 'logo_url', 'favicon_url',
            'logo_mark_url', 'logo_mark_svg_url', 'updated_at',
            // COALESCE(existing, new): keep whatever this row already has and
            // only take the incoming value when the row has never recorded a
            // curation event (still null) — see the INSERT-side comment above.
            'products_curated_at' => DB::raw('coalesce(products_curated_at, excluded.products_curated_at)'),
        ]);

        return $collectionId;
    }

    /**
     * Task 6 / #SEM-1: whether ShopFetch must skip this brand's scheduled
     * resync because the user hand-picked its products
     * (ShopController::setProducts()).
     *
     * Fix round 1, Finding 2 — a three-way transitional read, not a plain
     * column swap: content.storefronts.products_curated_at is BECOMING the
     * source of truth (Task 8 stops writing site.shop_brands entirely), but
     * two gaps mean it cannot be trusted alone yet:
     *   1. A brand curated through the not-yet-repointed setProducts() may
     *      have no content.storefronts row at all (never synced/backfilled).
     *   2. Even once a row exists, it only picks up a NEW curation event on
     *      the next upsertStore() call — and upsertStore() itself no longer
     *      overwrites an already-stamped value (see that method's own fix),
     *      so a row stamped before this fix shipped could still read stale
     *      until its next sync.
     * Falling back to the live ShopBrand column covers both gaps without
     * reintroducing the original staleness bug this method's first version
     * (round 0) was written to dodge — this fallback is read-only, so it
     * can never race with anything. DROP this fallback once
     * site.shop_brands.products_curated_at is retired for good.
     *
     * Fix round 2: no table-existence guard here — every real environment
     * is Postgres, where content.storefronts always exists (baseline
     * schema). The two test files that used to lack the content.* SQLite
     * stand-in schema now attach it in their own beforeEach instead.
     */
    public function isCurated(ShopBrand $brand): bool
    {
        $storefrontCuratedAt = DB::table('content.collections as c')
            ->join('content.storefronts as s', 's.collection_id', '=', 'c.id')
            ->where('c.user_id', (string) $brand->connection->user_id)
            ->where('c.kind', 'storefront')
            ->where('s.provider', (string) $brand->provider)
            ->where('s.external_ref', (string) $brand->brand_id)
            ->value('s.products_curated_at');

        return $storefrontCuratedAt !== null || $brand->products_curated_at !== null;
    }

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
                $userId, $coord, ShopProductProjection::fromBlob($blob, $currency));

            DB::table('content.collection_items')->upsert([[
                'collection_id' => $collectionId,
                'item_id' => $itemId,
                'source_id' => null,
                'position' => $position,
            ]], ['collection_id', 'item_id'], ['position']);

            $written++;
        }

        $this->retireAbsent($userId, $collectionId, array_keys($seen));

        return $written;
    }

    /**
     * Task 6 fix round 1, Finding 1: syncStore()'s read-back counterpart —
     * the collection's CURRENT catalogue, reconstructed from content.* into
     * the same raw blob shape ShopProductProjection::fromBlob() (and
     * therefore syncStore()) consumes as input, in content.collection_items
     * position order.
     *
     * Why this exists: ShopProductSeeder merges one new product into the
     * individual bucket's existing selection on every call, and — as of this
     * task — has no durable raw-blob store left to read "what's currently
     * selected" from (site.shop_products stops being written by that
     * seeder). Without this, a second seed() call for the same user
     * (CommerceProbeJob fires once per resolved link — legitimately more
     * than once per user) would silently drop whatever the FIRST call wrote
     * to content.*, because syncStore()'s retire-absent treats anything
     * missing from its input array as gone.
     *
     * Faithful enough to round-trip through fromBlob() again unchanged, not
     * byte-identical to the original scrape: a variant whose price never
     * parsed in the first place has no matching content.offers row either
     * (fromBlob() silently drops such a variant's offer), so it comes back
     * with a null price here too — same missing offer on the next write as
     * on this one, not a NEW loss introduced by reading it back.
     *
     * @return list<array<string,mixed>>
     */
    public function currentCatalogue(string $collectionId): array
    {
        $itemIds = DB::table('content.collection_items')
            ->where('collection_id', $collectionId)
            ->orderBy('position')
            ->pluck('item_id')
            ->all();

        if ($itemIds === []) {
            return [];
        }

        $urlByItem = DB::table('content.f_link')->whereIn('item_id', $itemIds)->pluck('url', 'item_id');
        $skuByItem = DB::table('content.f_catalog')->whereIn('item_id', $itemIds)->pluck('sku', 'item_id');
        $titleByItem = DB::table('content.f_text')->whereIn('item_id', $itemIds)->pluck('headline', 'item_id');

        $offersByItem = [];
        foreach (DB::table('content.offers')->whereIn('item_id', $itemIds)->get() as $offer) {
            $offersByItem[$offer->item_id][] = $offer;
        }

        $mediaByItem = [];
        foreach (
            DB::table('content.item_media as im')
                ->join('content.media_assets as a', 'a.id', '=', 'im.asset_id')
                ->whereIn('im.item_id', $itemIds)
                ->orderBy('im.position')
                ->get(['im.item_id', 'im.role', 'a.source_url']) as $row
        ) {
            $mediaByItem[$row->item_id][] = $row;
        }

        $variantsByItem = [];
        foreach (DB::table('content.item_variants')->whereIn('item_id', $itemIds)->orderBy('position')->get() as $variant) {
            $variantsByItem[$variant->item_id][] = $variant;
        }

        $catalogue = [];
        foreach ($itemIds as $itemId) {
            $url = $urlByItem[$itemId] ?? null;
            if ($url === null) {
                // No f_link row to rebuild a blob from — unreachable for a
                // real manual product item (writeManualItem() always writes
                // f_link from the coord's own url), but skip rather than
                // mint a urlless blob syncStore() would drop anyway.
                continue;
            }

            $offers = $offersByItem[$itemId] ?? [];
            $baseOffer = null;
            $offerByVariantLabel = [];
            foreach ($offers as $offer) {
                if ($offer->variant_label === null) {
                    $baseOffer = $offer;
                } else {
                    $offerByVariantLabel[$offer->variant_label] = $offer;
                }
            }

            $cover = null;
            $gallery = [];
            foreach ($mediaByItem[$itemId] ?? [] as $mediaRow) {
                if ($mediaRow->role === 'cover' && $cover === null) {
                    $cover = $mediaRow->source_url;
                } elseif ($mediaRow->role === 'gallery') {
                    $gallery[] = $mediaRow->source_url;
                }
            }

            $variants = [];
            foreach ($variantsByItem[$itemId] ?? [] as $variant) {
                $variantOffer = $offerByVariantLabel[$variant->label] ?? null;
                $variants[] = [
                    'title' => $variant->label,
                    'id' => $variant->sku,
                    'price' => $variantOffer !== null ? self::formatMinorUnits((int) $variantOffer->amount_minor) : null,
                    'available' => $variantOffer === null || $variantOffer->availability !== 'out_of_stock',
                ];
            }

            $catalogue[] = [
                'productId' => $skuByItem[$itemId] ?? null,
                'title' => $titleByItem[$itemId] ?? null,
                'url' => $url,
                'price' => $baseOffer !== null ? self::formatMinorUnits((int) $baseOffer->amount_minor) : null,
                'currency' => $baseOffer?->currency,
                'available' => $baseOffer === null || $baseOffer->availability !== 'out_of_stock',
                'image' => $cover,
                'images' => $gallery,
                'variants' => $variants,
            ];
        }

        return $catalogue;
    }

    /** "1234" minor units → "12.34" — the inverse of ShopProductProjection::minorUnits(). */
    private static function formatMinorUnits(int $amountMinor): string
    {
        return sprintf('%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100);
    }

    /**
     * Items still linked to THIS store's collection but absent from the
     * fetched catalogue. The link to this collection is always dropped — the
     * product genuinely left this store's catalogue. Whether the ITEM is
     * retired is a separate question:
     *
     * coordFor() is URL-only, not store-scoped (§1.7 — store-scoping it would
     * mint two manual coords for one canonical URL and poison that URL for
     * the identity resolver). So two of the same user's stores listing the
     * same product URL resolve to ONE content.items row. Fix round 1,
     * Finding 2: retiring on ANY one store's drop made that shared item
     * disappear from every OTHER store still listing it, permanently
     * (removed_at is never auto-cleared). The correct rule (parent programme
     * spec §9.8) is retire only when the item is absent from EVERY live
     * catalogue of this user — i.e. no content.collection_items row still
     * joins it to one of this user's storefront collections, checked AFTER
     * this collection's own stale link is removed.
     *
     * items.removed_at ONLY — source_items.removed_at is cleared on
     * reappearance (ProjectionWriter::upsertSourceItem()), so writing it
     * there would resurrect a product the owner deliberately removed. And
     * never a hard delete: analytics.item_views references item ids, and
     * mergeInto()'s curation check reads site.section_items.
     *
     * @param  list<string>  $liveCoords
     */
    private function retireAbsent(string $userId, string $collectionId, array $liveCoords): void
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

        DB::table('content.collection_items')
            ->where('collection_id', $collectionId)
            ->whereIn('item_id', $absent)
            ->delete();

        // Re-check AFTER dropping this collection's own link: an item still
        // joined to another of this user's storefront collections survives
        // in that store's own last-synced catalogue, so it must not retire.
        $stillLive = DB::table('content.collection_items as ci')
            ->join('content.collections as c', 'c.id', '=', 'ci.collection_id')
            ->where('c.user_id', $userId)
            ->where('c.kind', 'storefront')
            ->whereIn('ci.item_id', $absent)
            ->pluck('ci.item_id')
            ->unique()
            ->all();

        $toRetire = array_diff($absent, $stillLive);
        if ($toRetire === []) {
            return;
        }

        DB::table('content.items')->whereIn('id', $toRetire)->whereNull('removed_at')
            ->update(['removed_at' => now(), 'updated_at' => now()]);
    }
}
