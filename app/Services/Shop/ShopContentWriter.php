<?php

namespace App\Services\Shop;

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Slice 5a §3.1/§3.5: the one place that upserts a storefront into its
 * content.collections + content.storefronts shape (upsertStore()), and
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
     * Idempotent: keyed by (user_id, kind='storefront', provider,
     * external_ref) — external_ref is the PROVIDER's own store id, stable
     * across a rename and carried by content.storefronts itself.
     *
     * Slice 7 Task 24: takes a StoreRecord, NOT the ShopBrand model. Every
     * value this method writes now arrives as data, so the whole method
     * — lookup and write — runs with `site.shop_brands` absent. That table is
     * a LIVE write target (unlike site.shop_products), so a writer type-hinted
     * on its model would break every subsequent shop write the moment
     * 20260817000900_drop_site_shop_brands.sql lands, not merely an old read.
     *
     * The label (the store's display name) was the ORIGINAL key and is a
     * bug: it's a mutable, user-editable field (ShopController::updateBrand
     * renames a store freely). A rename between two upsertStore()
     * calls — which Task 6's syncStore() makes on every scheduled cycle —
     * missed the old lookup, minting a second content.collections +
     * content.storefronts pair and orphaning the first, taking its
     * referral_query/discount_code (affiliate revenue) with it while both
     * rows stayed linked to the same product items.
     */
    public function upsertStore(StoreRecord $store, string $ownerId): string
    {
        $externalRef = $store->externalRef;

        $existing = $this->collectionIdFor($store, $ownerId);

        $collectionId = (string) ($existing ?? Str::uuid());

        // Incident 2026-08-12: these two upserts used to run un-transacted.
        // A bug (since fixed) made the storefronts write throw on Postgres
        // after the collections write had already committed — 9 real stores'
        // first-ever backfill orphaned a content.collections row with no
        // content.storefronts partner. collectionIdFor() below JOINs the two
        // tables, so an orphan is invisible to it: the retry's lookup missed
        // and minted a SECOND collection+storefront pair instead of reusing
        // the first, doubling every affected store. Pinned to the 'pgsql'
        // connection — see UserBootstrapService::bootstrap()'s identical
        // comment: both DB::table() calls below already resolve to the
        // default connection unqualified, which IS 'pgsql' everywhere except
        // when a test explicitly forces something else, so this pin matches
        // what the writes already target rather than introducing a new one.
        DB::connection('pgsql')->transaction(function () use ($collectionId, $ownerId, $store, $externalRef) {
            DB::table('content.collections')->upsert([[
                'id' => $collectionId,
                'user_id' => $ownerId,
                'parent_id' => null,
                'label' => $store->name ?? $externalRef,
                'kind' => 'storefront',
                'position' => $store->position,
                'is_user_created' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['id'], ['label', 'position', 'updated_at']);

            DB::table('content.storefronts')->upsert([[
                'collection_id' => $collectionId,
                'provider' => $store->provider,
                'external_ref' => $externalRef,
                'url' => $store->url,
                'source_url' => $store->sourceUrl,
                'currency' => $store->currency,
                'discount_code' => $store->discountCode,
                'referral_query' => $store->referralQuery,
                'is_individual' => $store->isIndividual,
                'fetch_mode' => $store->fetchMode,
                'connect_status' => $store->connectStatus,
                'connect_error' => $store->connectError,
                // Seeds this column on INSERT only. Fix round 1, Finding 2: the
                // UPDATE path below does NOT list this column plainly (which
                // would mean "always overwrite from the record") —
                // content.storefronts is the source of truth for #SEM-1, and
                // every routine sync calls upsertStore(). An unconditional
                // overwrite here would let the next sync silently clobber a
                // curation stamp written directly to this row.
                'products_curated_at' => $store->productsCuratedAt,
                'logo_url' => $store->logoUrl,
                'favicon_url' => $store->faviconUrl,
                'logo_mark_url' => $store->logoMarkUrl,
                'logo_mark_svg_url' => $store->logoMarkSvgUrl,
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
                //
                // SQLSTATE[42702]: a BARE `products_curated_at` here is ambiguous
                // on Postgres — it cannot tell the target row's column from
                // `excluded`'s — and Postgres rejects the whole statement at
                // parse time, before it even checks whether a row conflicts. All
                // 9 real stores' first-ever backfill hit exactly this on
                // 2026-08-12, writing nothing. SQLite has no such ambiguity (it
                // resolves a bare column to the target row), so the default
                // (SQLite) suite passed throughout. Qualifying with the table
                // name — Postgres's own documented ON CONFLICT idiom, no schema
                // prefix needed since the statement has only one target table —
                // disambiguates on both drivers; see tests/Postgres/
                // ShopStorefrontUpsertConflictTest.php for the real-Postgres pin.
                'products_curated_at' => DB::raw('coalesce(storefronts.products_curated_at, excluded.products_curated_at)'),
            ]);
        });

        return $collectionId;
    }

    /**
     * Read-only counterpart to upsertStore()'s own existence lookup — never
     * creates. Task 8: removeBrand()/forget() need this to find what (if
     * anything) to retire without forcing a content.* row into existence
     * just to immediately delete it.
     */
    public function collectionIdFor(StoreRecord $store, string $ownerId): ?string
    {
        $id = DB::table('content.collections as c')
            ->join('content.storefronts as s', 's.collection_id', '=', 'c.id')
            ->where('c.user_id', $ownerId)
            ->where('c.kind', 'storefront')
            ->where('s.provider', $store->provider)
            ->where('s.external_ref', $store->externalRef)
            ->value('c.id');

        if ($id !== null) {
            return (string) $id;
        }

        // Belt-and-braces orphan recovery (incident 2026-08-12): a
        // content.collections row with no content.storefronts partner can
        // only exist from BEFORE upsertStore()'s transaction fix — the join
        // above is blind to it by construction, since the real identity
        // (provider + external_ref) lives entirely on the missing storefronts
        // row. Reusing it by label — the one thing an orphaned collection
        // still carries — beats minting a second collection and stranding the
        // orphan permanently, which is exactly how the incident went from 9
        // orphans to 18 collections on retry. Label is NOT a general identity
        // key here (it's mutable, rejected everywhere else in this file for
        // that reason) — safe only because this fallback fires solely when
        // the correct join above already missed, so it can never shadow a
        // properly-linked row. New orphans cannot form going forward (the
        // transaction above is atomic), so this path is defence for
        // already-existing damage, not a load-bearing lookup.
        $orphanId = DB::table('content.collections as c')
            ->leftJoin('content.storefronts as s', 's.collection_id', '=', 'c.id')
            ->where('c.user_id', $ownerId)
            ->where('c.kind', 'storefront')
            ->where('c.label', $store->name ?? $store->externalRef)
            ->whereNull('s.collection_id')
            ->value('c.id');

        return $orphanId === null ? null : (string) $orphanId;
    }

    /**
     * Task 8 / removeBrand() + forget(): a store is leaving — retire every
     * item this collection carries (unless it survives via another of the
     * user's storefronts, same §1.7 URL-only-coord reasoning as
     * retireAbsent()), then delete the collection. The FK cascades
     * content.storefronts; content.collection_items is dropped explicitly
     * first anyway (not just relying on the cascade) — deterministic on
     * SQLite in tests, which doesn't enforce FK ON DELETE, same discipline
     * ShopController::removeBrand() already applies to the legacy tables.
     *
     * NEVER a hard delete of content.items — analytics.item_views
     * references item ids, and a merge's curation check reads
     * site.section_items. removed_at only.
     */
    public function retireStore(string $userId, string $collectionId): void
    {
        // Same orphan shape as upsertStore() (incident 2026-08-12), run
        // backwards: a failure between the storefronts delete and the
        // collections delete below would leave a collections row with no
        // storefronts partner — which defeats collectionIdFor()'s join on
        // the very next upsertStore() for this store, exactly like the
        // incident, except reached via teardown instead of creation. One
        // transaction closes that window the same way.
        DB::connection('pgsql')->transaction(function () use ($userId, $collectionId) {
            $itemIds = DB::table('content.collection_items')
                ->where('collection_id', $collectionId)
                ->pluck('item_id')->unique()->all();

            // Drop this collection's own links FIRST — mirrors retireAbsent()'s
            // ordering exactly, so the "still live elsewhere" check below can
            // never see this collection's own (soon-to-be-gone) link and
            // wrongly count an item as still live off its own about-to-be-
            // deleted store.
            DB::table('content.collection_items')->where('collection_id', $collectionId)->delete();

            if ($itemIds !== []) {
                $stillLive = DB::table('content.collection_items as ci')
                    ->join('content.collections as c', 'c.id', '=', 'ci.collection_id')
                    ->where('c.user_id', $userId)
                    ->where('c.kind', 'storefront')
                    ->whereIn('ci.item_id', $itemIds)
                    ->pluck('ci.item_id')->unique()->all();

                $toRetire = array_diff($itemIds, $stillLive);
                if ($toRetire !== []) {
                    DB::table('content.items')->whereIn('id', $toRetire)->whereNull('removed_at')
                        ->update(['removed_at' => now(), 'updated_at' => now()]);
                }
            }

            DB::table('content.storefronts')->where('collection_id', $collectionId)->delete();
            DB::table('content.collections')->where('id', $collectionId)->delete();
        });
    }

    /**
     * Task 6 / #SEM-1: whether ShopFetch must skip this brand's scheduled
     * resync because the user hand-picked its products
     * (ShopController::setProducts()).
     *
     * Fix round 1, Finding 2 — a two-way read, not a plain column swap:
     * content.storefronts.products_curated_at IS the source of truth, but a
     * store curated before that column existed may have no
     * content.storefronts row at all (never synced/backfilled). The record's
     * own productsCuratedAt covers that gap — read-only, so it can never race
     * with anything. Slice 7 Task 24: it is now supplied by the CALLER rather
     * than read off site.shop_brands here, so this method survives the DROP;
     * the fallback goes inert (always null) once every caller builds its
     * record from content.* alone.
     *
     * Fix round 2: no table-existence guard here — every real environment
     * is Postgres, where content.storefronts always exists (baseline
     * schema). The two test files that used to lack the content.* SQLite
     * stand-in schema now attach it in their own beforeEach instead.
     */
    public function isCurated(StoreRecord $store, string $ownerId): bool
    {
        $storefrontCuratedAt = DB::table('content.collections as c')
            ->join('content.storefronts as s', 's.collection_id', '=', 'c.id')
            ->where('c.user_id', $ownerId)
            ->where('c.kind', 'storefront')
            ->where('s.provider', $store->provider)
            ->where('s.external_ref', $store->externalRef)
            ->value('s.products_curated_at');

        return $storefrontCuratedAt !== null || $store->productsCuratedAt !== null;
    }

    /**
     * Reconcile a store's fetched catalogue into content.items: upsert every
     * live product by coord, then retire (items.removed_at) whatever this
     * collection still links that the fetch no longer carries. Returns the
     * count written (post-dedupe, not the raw input count).
     *
     * Fix round 3, Finding 3: a urlless product is NOT dropped merely for
     * lacking a url — Squarespace and BigCartel both legitimately emit one
     * (BigCartelScraper's own return type is `url:?string`). The coord falls
     * back to ShopProductProjection::coordForProductId() — collection-
     * namespaced, see that method for why — so the product still gets an
     * item, still gets tracked in `$seen` (so retireAbsent() doesn't wrongly
     * retire it), and still round-trips through currentCatalogue() for
     * ShopProductSeeder's re-seed merge. Only a product with NEITHER a url
     * NOR a product id is genuinely unidentifiable — skipped, and logged
     * (never silently) rather than counted in a return value, since this
     * method's return is a plain written-count already consumed as-is by
     * ShopCatalog::syncLatest(). ShopBackfiller::run() mirrors this exact
     * branch (fix round 4, Finding 2); the two must not drift.
     *
     * COST — this loop is O(N²) in database round-trips, and its callers hold
     * a 10s lock (CacheKeyGenerator::platformConnectionLock) while it runs.
     * Every writeManualItem() call re-runs resolveItems() over ALL of the
     * user's product source items and refreshItemCaches() over all the
     * resulting item ids, so per-product cost grows with the user's whole
     * product set, not with this store's. Roughly ~25 round-trips per product
     * at that scale. Real bounds: the individual bucket caps at 20, dev's
     * largest store holds 8, and syncLatest() takes at most the user's
     * existing selection size — all comfortably inside the lock. The
     * pathological case is a hand-built setProducts() payload at the 250
     * request cap: ~6,000 round-trips over Supavisor against a 10s lock TTL,
     * where the pre-branch path was one DELETE and one bulk INSERT.
     * Restructuring to a bulk write (resolve once, refresh once, at the end
     * of the loop) is DELIBERATELY DEFERRED — it means reaching into
     * ProjectionWriter's per-item contract, which every connector shares, and
     * no observed workload is near the ceiling. Revisit if a real store
     * exceeds ~50 products or the lock starts timing out.
     *
     * @param  list<array<string,mixed>>  $products  raw scraper blobs, catalogue order
     */
    public function syncStore(string $userId, string $collectionId, array $products, ?string $currency): int
    {
        $seen = [];
        $linked = [];
        $written = 0;

        foreach ($products as $position => $blob) {
            $url = trim((string) ($blob['url'] ?? ''));
            $productId = trim((string) ($blob['productId'] ?? ''));

            if ($url !== '') {
                $coord = ShopProductProjection::coordFor($url);
            } elseif ($productId !== '') {
                $coord = ShopProductProjection::coordForProductId($collectionId, $productId);
            } else {
                Log::warning('shop.sync_store.unidentifiable_product', [
                    'user_id' => $userId,
                    'collection_id' => $collectionId,
                    'position' => $position,
                ]);

                continue;
            }

            // §1.7: one coord per canonical identifier per user. Two catalogue
            // entries sharing a URL (or, now, sharing a productId with no url)
            // would poison that key for the whole resolution run, so the
            // dedupe happens BEFORE writing, not after.
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

            $linked[] = $itemId;
            $written++;
        }

        // Spec §3.3: clears items.removed_at for exactly the items this call
        // just linked (never wider — this can't reach outside $linked). This
        // method itself is NOT owner-exclusive — ShopFetch's scheduled
        // 6-hourly sync reaches it too. The owner-vs-connector boundary lives
        // in ShopFetch's CALLERS instead: it skips hand-curated brands
        // (ShopContentWriter::isCurated() → products_curated_at) and the
        // individual bucket (->where('is_individual', false)), so the
        // scheduled path can only ever un-retire what its OWN top-N windowing
        // retired, never a brand an owner curated by hand. Both exclusions are
        // pinned by ShopRetirementTest's "ShopFetch never calls syncLatest
        // for …" pair — delete either filter and one of those fails.
        //
        // The 20-item individual-bucket cap (which retires the oldest product
        // on EVERY add) is why this exists at all: without it, a re-added
        // product stays permanently absent from the Shop page while showing
        // normally in the dashboard.
        if ($linked !== []) {
            DB::table('content.items')
                ->whereIn('id', $linked)
                ->whereNotNull('removed_at')
                ->update(['removed_at' => null, 'updated_at' => now()]);
        }

        $this->retireAbsent($userId, $collectionId, array_keys($seen));

        return $written;
    }

    /**
     * Task 6 fix round 1, Finding 1 / Task 7 widen: syncStore()'s read-back
     * counterpart — the CURRENT catalogue of every collection in
     * $collectionIds, reconstructed from content.* into the same raw blob
     * shape ShopProductProjection::fromBlob() (and therefore syncStore())
     * consumes as input, in each collection's content.collection_items
     * position order. One query per table across the WHOLE set of
     * collections, never per collection — Task 7's ShopContentReader::
     * brandMap() is why: reading N stores' catalogues via N single-collection
     * calls would be N queries per table, an N+1 on /platforms/shop/selection's
     * public-serving path.
     *
     * Why the single-collection form (currentCatalogue() below) exists at
     * all: ShopProductSeeder merges one new product into the individual
     * bucket's existing selection on every call, and — as of Task 6 — has no
     * durable raw-blob store left to read "what's currently selected" from
     * (site.shop_products stops being written by that seeder). Without it, a
     * second seed() call for the same user (CommerceProbeJob fires once per
     * resolved link — legitimately more than once per user) would silently
     * drop whatever the FIRST call wrote to content.*, because syncStore()'s
     * retire-absent treats anything missing from its input array as gone.
     *
     * Faithful enough to round-trip through fromBlob() again unchanged, not
     * byte-identical to the original scrape: a variant whose price never
     * parsed in the first place has no matching content.offers row either
     * (fromBlob() silently drops such a variant's offer), so it comes back
     * with a null price here too — same missing offer on the next write as
     * on this one, not a NEW loss introduced by reading it back.
     *
     * Task 7 widen: also carries the DASHBOARD product shape's extra keys —
     * `handle`, `vendor`, `description`, `variantId`, `createdAt`. Fix round
     * 1, Finding 3 closed the original gap for the first four:
     * `handle`/`vendor`/`variantId` (stored as `variant_ref` — NOT
     * `variantId`, see ShopProductProjection::fromBlob()'s own comment on
     * why) now round-trip through content.f_catalog, and `description`
     * through content.f_text.body (migration 20260813100002,
     * ProjectionWriter's SINGLETON_FACETS widened to match) — a product
     * written before that migration lands still reads back null for these
     * four until its next sync, same as any other facet backfill.
     *
     * `createdAt` is fix round 2, Finding 1 (corrected in round 3, Finding
     * 1 — see the inline comment at the read site below): it is NOT
     * cosmetic — ShopCatalog::syncLatest() sorts a fetched catalogue on it
     * to pick a latest-mode store's newest products, ShopProductSeeder's
     * newest-first merge reads it via currentCatalogue(), and
     * SHOP_PRODUCT_ALLOWLIST still carries it on the public wire — so it is
     * populated from content.f_published.published_from (ShopProductProjection
     * writes that facet from the blob's own createdAt, verbatim, when
     * parseable), reformatted through Carbon on READ (NOT a verbatim
     * pass-through — that was round 2's mistake, SQLite-only-correct; see
     * below). items.first_seen_at is a TRANSITIONAL FALLBACK ONLY, for a
     * row that synced before this fix shipped and so has no f_published row
     * yet — not the source of truth, and not correct once every live item
     * has synced at least once since. The extra keys are harmless to the
     * existing syncStore()-input caller: fromBlob() only reads the keys it
     * knows and ignores the rest.
     *
     * Fix round 1, Finding 2 (was: DISCOVERED DEFECT, pre-existing): offer
     * `availability` is now written by ProjectionWriter::replaceCollections()
     * (app/Ingest/Projection/ProjectionWriter.php's $offerRows builder,
     * additive — `$offer['availability'] ?? null`), so this method's
     * fail-open read (`$offer === null || $offer->availability !==
     * 'out_of_stock'`) reflects the real scrape once an item has synced
     * since that fix landed. An item whose offers were written BEFORE the
     * fix still reads back available=true regardless of true stock until
     * its next sync — same backfill-lag caveat as the facet fields above,
     * not a new gap.
     *
     * @param  list<string>  $collectionIds
     * @return array<string, list<array<string,mixed>>> collection id => catalogue, position order
     */
    public function cataloguesFor(array $collectionIds): array
    {
        if ($collectionIds === []) {
            return [];
        }

        $links = DB::table('content.collection_items')
            ->whereIn('collection_id', $collectionIds)
            ->orderBy('position')
            ->get(['collection_id', 'item_id', 'position']);

        if ($links->isEmpty()) {
            return [];
        }

        $itemIds = $links->pluck('item_id')->unique()->values()->all();

        $urlByItem = DB::table('content.f_link')->whereIn('item_id', $itemIds)->pluck('url', 'item_id');
        // Fix round 1, Finding 3: sku/handle/vendor/variant_ref all live on
        // this one facet row (migration 20260813100002) — one keyed fetch,
        // not four.
        $catalogByItem = DB::table('content.f_catalog')->whereIn('item_id', $itemIds)
            ->get(['item_id', 'sku', 'handle', 'vendor', 'variant_ref'])->keyBy('item_id');
        // headline/body likewise share one f_text row.
        $textByItem = DB::table('content.f_text')->whereIn('item_id', $itemIds)
            ->get(['item_id', 'headline', 'body'])->keyBy('item_id');
        // Fix round 2, Finding 1: createdAt's real home. published_from is
        // only present once an item has synced since ShopProductProjection
        // started writing it — items.first_seen_at (below) is the
        // TRANSITIONAL fallback for a row backfilled/synced before that,
        // not the primary source; see this method's docblock.
        $publishedByItem = DB::table('content.f_published')->whereIn('item_id', $itemIds)->pluck('published_from', 'item_id');
        $firstSeenByItem = DB::table('content.items')->whereIn('id', $itemIds)->pluck('first_seen_at', 'id');

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

        $catalogues = [];
        foreach ($links->groupBy('collection_id') as $collectionId => $collectionLinks) {
            $catalogue = [];
            foreach ($collectionLinks as $link) {
                $itemId = $link->item_id;
                // Fix round 3, Finding 3: a missing f_link row is REACHABLE
                // now — syncStore() falls back to a 'pid:'-derived coord for
                // a urlless product (Squarespace/BigCartel both legitimately
                // emit one) rather than skipping it, so that item has no
                // f_link facet by construction (fromBlob() never writes one
                // for an empty url). Previously this `continue`d, silently
                // dropping such a product from every read even after
                // syncStore() stopped dropping it at write time — the exact
                // "skip and count, never silently" failure this fix closes.
                // `url` is genuinely null on the wire here, matching
                // BigCartelScraper's own documented `url:?string` shape.
                $url = $urlByItem[$itemId] ?? null;

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
                // Task 7 fix: the original (single-collection) version of this
                // method built `images` from gallery rows ONLY, dropping the
                // cover. That underserved its one caller silently (syncStore()
                // re-dedupes on write regardless — ShopProductProjection::
                // media() skips any images[] entry equal to `image` — so the
                // omission was invisible there) but is wrong for THIS caller:
                // a real scraped blob's `images[]` always includes the cover
                // as its first element (see e.g. ShopifyScraper::
                // fetchProducts()'s own "Compat single hero image (first
                // product image)" comment), and the Task 7 brief's field
                // mapping is explicit — images = cover then every gallery row.
                $images = $cover === null ? $gallery : [$cover, ...$gallery];

                $variants = [];
                foreach ($variantsByItem[$itemId] ?? [] as $variant) {
                    $variantOffer = $offerByVariantLabel[$variant->label] ?? null;
                    $variants[] = [
                        'title' => $variant->label,
                        'id' => $variant->sku,
                        'price' => $variantOffer !== null ? self::formatMinorUnits((int) $variantOffer->amount_minor) : null,
                        'available' => $variantOffer === null || $variantOffer->availability !== 'out_of_stock',
                        // Task 8 fix round 2, D1: this used to be
                        // unconditionally null — content.item_variants had no
                        // image column and fromBlob() dropped the key, so the
                        // sitepage's per-variant photo swap (#84) lost its
                        // input the moment the public wire moved to content.*.
                        // Migration 20260813100003 added the column; null here
                        // now means the source published no image, which is
                        // common and not a loss.
                        'image' => $variant->image_url,
                    ];
                }

                $catalog = $catalogByItem->get($itemId);
                $text = $textByItem->get($itemId);

                // Fix round 3, Finding 1 (CRITICAL, corrects round 2's own
                // mistake): published_from MUST be reformatted, not passed
                // through raw. Round 2 removed the Carbon call because it
                // made the SQLite test byte-match the legacy fixture — but
                // that only "worked" because the SQLite stand-in stores this
                // column as bare TEXT and hands back the exact literal
                // string. content.f_published.published_from is a real
                // Postgres `timestamptz`: on write, Postgres normalises
                // whatever offset arrives to UTC internally, and on read
                // (session TimeZone defaults to UTC — verified against the
                // real partna-pg-test Postgres container, not assumed) it
                // ALWAYS returns text in ITS OWN format
                // ('2026-01-05 00:00:00+00' — space, no 'T', two-digit
                // offset), never the original ISO-8601 string. A raw
                // pass-through is therefore SQLite-only-correct — exactly
                // the "tests run SQLite, prod is Postgres" trap this repo
                // keeps hitting. Confirmed empirically (see the Task 7
                // report, Fix round 3): Carbon::parse($raw)->utc()->
                // toIso8601String() produces the IDENTICAL string whether
                // $raw came from SQLite's literal text or Postgres's
                // UTC-normalised text, for the same instant — the ->utc()
                // call is load-bearing, not decorative: without it, a
                // non-UTC ORIGINAL offset (e.g. '+10:00') round-trips
                // through Postgres as its OWN '+00:00' text, which then
                // reformats to a DIFFERENT string than parsing the original
                // '+10:00' string directly would — the two drivers only
                // agree once both are forced to the same target zone before
                // formatting. items.first_seen_at (a Partna-side synthetic
                // timestamp, never an external string to preserve) is ONLY
                // the fallback for a row that synced before
                // ShopProductProjection started writing f_published —
                // transitional, not the source of truth.
                // Fix round 4, Finding 4: the fallback branch needs ->utc()
                // for exactly the same reason as the primary one above —
                // items.first_seen_at is a timestamptz too, so without it the
                // emitted offset tracks whatever zone the driver's text
                // happens to carry rather than being canonically UTC.
                $published = $publishedByItem[$itemId] ?? null;
                $createdAt = $published !== null
                    ? Carbon::parse((string) $published)->utc()->toIso8601String()
                    : (($firstSeen = $firstSeenByItem[$itemId] ?? null) === null ? null : Carbon::parse((string) $firstSeen)->utc()->toIso8601String());

                $product = [
                    'productId' => $catalog?->sku,
                    'title' => $text?->headline,
                    // Fix round 1, Finding 3: previously always null (see
                    // this method's OLD docblock in git history) — now
                    // round-trips through content.f_catalog/f_text (migration
                    // 20260813100002).
                    'handle' => $catalog?->handle,
                    'url' => $url,
                    'price' => $baseOffer !== null ? self::formatMinorUnits((int) $baseOffer->amount_minor) : null,
                    'currency' => $baseOffer?->currency,
                    'variantId' => $catalog?->variant_ref,
                    'available' => $baseOffer === null || $baseOffer->availability !== 'out_of_stock',
                    'image' => $cover,
                    'images' => $images,
                    'createdAt' => $createdAt,
                    'variants' => $variants,
                ];

                // Fix round 3, Finding 2: content.* cannot tell "the blob
                // never had this key" from "the blob had it set to null" —
                // both collapse to the same null column. OMITTING the key
                // when null, rather than emitting `vendor: null`/
                // `description: null`, is honest about that: a legacy
                // dashboard build reading `product.vendor` gets `undefined`
                // either way once JSON-decoded, whether the original blob
                // never had the key (SquarespaceScraper never emits it) or
                // had it explicitly null (GenericShopScraper's OpenGraph
                // fallback always does) — so this is a lossless
                // simplification for a genuinely null value, not a new
                // divergence. `handle`/`variantId` are NOT given the same
                // treatment: unlike vendor/description, every real scraper
                // examined either always emits them or the field is
                // meaningfully absent as a signal in its own right (see the
                // Task 7 report for the field-by-field accounting).
                if ($catalog?->vendor !== null) {
                    $product['vendor'] = $catalog->vendor;
                }
                if ($text?->body !== null) {
                    $product['description'] = $text->body;
                }

                $catalogue[] = $product;
            }
            $catalogues[$collectionId] = $catalogue;
        }

        return $catalogues;
    }

    /**
     * Single-collection convenience wrapper around cataloguesFor() — see that
     * method's docblock for the shape, the field-loss accounting, and why
     * ShopProductSeeder needs this at all.
     *
     * @return list<array<string,mixed>>
     */
    public function currentCatalogue(string $collectionId): array
    {
        return $this->cataloguesFor([$collectionId])[$collectionId] ?? [];
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
        // Fix 2026-08-13: the question is "does this item have NO live-coord
        // source item?", not "does it have ANY non-live-coord source item?".
        // The old form ran whereNotIn in SQL and ->unique() in PHP afterwards,
        // so an item carrying both a stale and a live coord — a product that
        // gained a URL upstream — matched row-wise on the stale row and was
        // retired while still in the catalogue. One-way, since removed_at is
        // cleared only by an owner-authored re-add (§3.3). `si2` (not `si`):
        // this is a correlated NOT EXISTS against the outer `ci`, so the
        // subquery needs its own alias distinct from `ci`.
        $absent = DB::table('content.collection_items as ci')
            ->where('ci.collection_id', $collectionId)
            ->when($liveCoords !== [], fn ($q) => $q->whereNotExists(
                fn ($e) => $e->from('content.source_items as si2')
                    ->whereColumn('si2.item_id', 'ci.item_id')
                    ->whereIn('si2.coord', $liveCoords)
            ))
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
