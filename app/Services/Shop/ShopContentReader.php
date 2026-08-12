<?php

namespace App\Services\Shop;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Slice 5a Task 7: the read counterpart to ShopContentWriter — rebuilds the
 * legacy brand-keyed map (the shape ShopBrand::toBrandArray() produces,
 * keyed by brand_id) from content.collections / content.storefronts, using
 * ShopContentWriter::cataloguesFor() for the nested `products` reconstruction
 * instead of writing a second parallel one.
 *
 * Feeds ShopController::brands()/brandProducts()/selection() directly, and
 * connectStatus()'s embedded brand payload (with a documented fallback — see
 * that call site). `catalog()` and the nine write endpoints are Task 8's
 * territory and keep reading site.shop_brands/site.shop_products — see the
 * class docblock on ShopController for the boundary.
 *
 * KNOWN GAPS — read this before trusting a value this class returns:
 *
 * 1. TIMING / EXISTENCE. A brand only has a row here once
 *    ShopContentWriter::upsertStore() has run for it at least once: the
 *    one-time ShopBackfiller migration, ShopCatalog::syncLatest() (reached
 *    from the scheduled shop refresh — up to
 *    config('partna.platforms.shop.refresh_interval') after connect, 6h by
 *    default — or immediately via updateBrand(selectionMode: 'latest')), or
 *    ShopProductSeeder. None of ShopController::addBrand(),
 *    ShopBrandConnectJob (the deferred-connect settle job), or setProducts()
 *    call upsertStore() today — Task 8 is what repoints those. Until Task 8
 *    ships, a brand between "just connected" (or just settled from a
 *    deferred connect) and "first synced" has NO row here and is silently
 *    ABSENT from brandMap() — see the Task 7 report for the blast radius on
 *    brands()/brandProducts()/connectStatus(), closed there by
 *    ShopController::hybridBrandMap() (see that method's docblock).
 *
 * 2. PRODUCT FIELD BACKFILL LAG. `handle`/`vendor`/`description`/`variantId`
 *    (stored as `variant_ref`) round-trip through content.f_catalog/f_text
 *    as of fix round 1, Finding 3 (migration 20260813100002, APPLIED to the
 *    shared dev database) — but only for an item written or re-synced AFTER
 *    that migration landed. `createdAt` (fix round 2, Finding 1) round-trips
 *    through content.f_published.published_from the same way. An item whose
 *    facets were written earlier reads back null (or, for `createdAt` only,
 *    the items.first_seen_at transitional fallback — see
 *    ShopContentWriter::cataloguesFor()'s docblock) until its next sync — a
 *    backfill-lag caveat, not a structural loss.
 *
 * 3. `selectionMode`/`linkMode` are NOT stored per brand — fix round 1,
 *    Finding 4: `selection_mode` was dead (every real row's value was
 *    already the default), and `link_mode` was already effectively one
 *    global setting in practice (see ShopController's own "DORMANT as of
 *    2026-07-08" comment on updateBrand() — the PUBLIC payload has stamped
 *    it from site.sites.shop_link_mode for a while; this class now does the
 *    same for the DASHBOARD shape). `selectionMode` is always the constant
 *    'manual'; `linkMode` is read from site.sites.shop_link_mode (one query
 *    per brandMap() call, not per brand — it is the same value for every
 *    brand a user has), falling back to Site::DEFAULT_SHOP_LINK_MODE when
 *    the site has no row or the column is null. This is a DELIBERATE
 *    behaviour change from the old per-brand ShopBrand::toBrandArray()
 *    shape (which showed whatever was last written to that brand's own,
 *    now-vestigial link_mode/selection_mode columns) — see the Task 7
 *    report, Fix round 1, for why the parity fixture's expectations moved
 *    to match.
 *
 * 4. popularityRank is keyed by product HANDLE (content_popularity_scores'
 *    own scoring-pipeline convention — see ShopBrand::toBrandArray()'s own
 *    docblock). Since gap 2 above, `handle` is populated once an item has
 *    synced since the Finding 3 migration, so the rank lookup can hit for
 *    up-to-date items — same backfill-lag caveat, not a permanent miss.
 *    ShopController's own private brandMap() (the pre-Task-7 path this
 *    class replaces for brands()/brandProducts()/selection()) ALWAYS passes
 *    a ranks array — never null — to ShopBrand::toBrandArray(), so
 *    popularityRank is present (not omitted) on the DASHBOARD shape too,
 *    despite that method's docblock describing the key as public-path-only
 *    — confirmed empirically, not from the docblock. This class mirrors
 *    that: pass $productRanks (even an empty array) to get the key; pass
 *    null (as connectStatus()'s fallback does) to omit it, matching
 *    ShopBrand::toBrandArray()'s own contract exactly.
 */
class ShopContentReader
{
    public function __construct(private readonly ShopContentWriter $writer) {}

    /**
     * $productRanks: product handle => rank (public path; see gap 4 above).
     * Pass even an empty array to get a null-valued `popularityRank` key on
     * every product (dashboard shape); pass null to omit the key entirely.
     *
     * @param  array<string, int>|null  $productRanks
     * @return array<string, array<string, mixed>> external_ref (brand_id) => brand array, ShopBrand::toBrandArray() shape
     */
    public function brandMap(User $user, ?array $productRanks = null): array
    {
        // Position order mirrors IntegrationConnection::shopBrands()'s own
        // ->orderBy('position')->orderBy('brand_id') — upsertStore() writes
        // content.collections.position from the live shop_brands.position,
        // and external_ref IS brand_id, so the same tiebreak applies.
        $rows = DB::table('content.storefronts as s')
            ->join('content.collections as c', 'c.id', '=', 's.collection_id')
            ->where('c.user_id', (string) $user->id)
            ->where('c.kind', 'storefront')
            ->orderBy('c.position')
            ->orderBy('s.external_ref')
            ->get([
                's.collection_id', 's.external_ref', 's.provider', 's.url', 's.source_url',
                's.currency', 's.discount_code', 's.referral_query', 's.is_individual',
                's.fetch_mode', 's.connect_status', 's.connect_error', 's.products_curated_at',
                's.logo_url', 's.favicon_url', 's.logo_mark_url', 's.logo_mark_svg_url',
                'c.label',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $catalogues = $this->writer->cataloguesFor($rows->pluck('collection_id')->all());
        // Fix round 1, Finding 4 — one value for the whole map (see class
        // docblock, gap 3), not a per-brand lookup.
        $linkMode = DB::table('site.sites')->where('user_id', (string) $user->id)
            ->value('shop_link_mode') ?? Site::DEFAULT_SHOP_LINK_MODE;

        $map = [];
        foreach ($rows as $row) {
            $externalRef = (string) ($row->external_ref ?? '');
            if ($externalRef === '') {
                // Belt-and-braces: every real row carries external_ref since
                // migration 20260813100001 backfilled it, but a keyless row
                // has nothing to key this map on — skip rather than collide
                // every such row onto the same '' key.
                continue;
            }

            $brand = [
                'id' => $externalRef,
                'provider' => (string) $row->provider,
                'url' => $row->url,
                'sourceUrl' => $row->source_url,
                'name' => $row->label,
                'currency' => $row->currency,
                'favicon' => $row->favicon_url,
                'logo' => $row->logo_url,
                'discountCode' => $row->discount_code ?? '',
                // See class docblock, gap 3: derived constants, not stored
                // per brand — selection_mode was always the default in
                // practice; link_mode is one global site setting.
                'selectionMode' => 'manual',
                'linkMode' => $linkMode,
                'referralQuery' => $row->referral_query ?? '',
                'products' => self::withPopularityRank($catalogues[$row->collection_id] ?? [], $productRanks),
            ];

            // Conditional emission mirrors ShopBrand::toBrandArray() exactly,
            // so a settled/non-individual/unprocessed brand's body stays
            // byte-identical to the pre-repoint shape (dark-merge contract —
            // IntegrationContractGoldenMasterTest and friends).
            if ($row->fetch_mode !== null) {
                $brand['fetchMode'] = $row->fetch_mode;
            }
            if ((bool) $row->is_individual) {
                $brand['individual'] = true;
            }
            if ($row->logo_mark_url !== null) {
                $brand['logoMark'] = $row->logo_mark_url;
            }
            if ($row->logo_mark_svg_url !== null) {
                $brand['logoMarkSvg'] = $row->logo_mark_svg_url;
            }
            if ($row->connect_status !== null) {
                $brand['connectStatus'] = $row->connect_status;
            }
            if ($row->connect_error !== null) {
                $brand['connectError'] = $row->connect_error;
            }
            if ($row->products_curated_at !== null) {
                $brand['productsCuratedAt'] = Carbon::parse((string) $row->products_curated_at)->toIso8601String();
            }

            $map[$externalRef] = $brand;
        }

        return $map;
    }

    /**
     * Mirrors ShopBrand::toBrandArray()'s own conditional: $productRanks ===
     * null omits the key entirely (matches connectStatus()'s fallback path);
     * any array (even empty) adds it, keyed by handle — see gap 4 above for
     * why that lookup is always a miss post-repoint.
     *
     * @param  list<array<string,mixed>>  $catalogue
     * @param  array<string, int>|null  $productRanks
     * @return list<array<string,mixed>>
     */
    private static function withPopularityRank(array $catalogue, ?array $productRanks): array
    {
        if ($productRanks === null) {
            return $catalogue;
        }

        return array_map(static function (array $product) use ($productRanks): array {
            $product['popularityRank'] = $productRanks[(string) ($product['handle'] ?? '')] ?? null;

            return $product;
        }, $catalogue);
    }
}
