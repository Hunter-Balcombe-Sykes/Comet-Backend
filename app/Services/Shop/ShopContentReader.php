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
 * Feeds ShopController::brands()/brandProducts()/selection() directly,
 * connectStatus()'s embedded brand payload (with a documented fallback — see
 * that call site), and — since Task 8 fix round 1 — the PUBLIC sitepage's
 * shop card: PublicIntegrationController builds the map once per profile and
 * threads it into PublicIntegrationConnectionResource. `catalog()` is the one
 * endpoint still reading site.shop_brands (the live store's url/provider),
 * deliberately — see the class docblock on ShopController for the boundary.
 *
 * KNOWN GAPS — read this before trusting a value this class returns:
 *
 * 1. TIMING / EXISTENCE. A brand only has a row here once
 *    ShopContentWriter::upsertStore() has run for it at least once. Task 8
 *    repointed every writer that can mint one — addBrand(), updateBrand(),
 *    setProducts(), addProduct(), ShopBrandConnectJob (the deferred-connect
 *    settle, both success and terminal), ProcessShopBrandLogoJob — on top of
 *    the pre-existing ShopCatalog::syncLatest() / ShopProductSeeder / the
 *    one-time ShopBackfiller migration, so a brand touched by any of those
 *    always has a row and the Task 7 hybrid merge is gone.
 *
 *    What that leaves — and it is a DEPLOY ORDERING FACT, not a code gap: a
 *    brand connected BEFORE this slice ships and never touched since has no
 *    row here, so it is silently absent from the dashboard AND from the
 *    public sitepage (nothing falls back to the legacy tables any more).
 *    ShopBackfiller must run BEFORE this code deploys. Pinned by
 *    ShopPagePresenceTest's "drops the shop page for a legacy brand that was
 *    never backfilled into content.*".
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
                // Fix round 3, Finding 5: content.collections.label is
                // NOT NULL (upsertStore() writes `name ?? brand_id` into
                // it — there is no separate "unnamed" state the column can
                // hold), so a brand with no real name is indistinguishable
                // from a brand whose real name happens to equal its own id.
                // The label IS the id in the "no name" case specifically
                // because upsertStore() falls back to it — so null it back
                // out here rather than surface a value the legacy dashboard
                // never showed. Narrow false-positive this accepts: a store
                // genuinely NAMED the same string as its own brand_id would
                // also read back null — judged acceptable (a name identical
                // to an opaque provider id is not a real display name to
                // begin with) rather than adding a column to
                // content.storefronts to carry the two facts separately.
                'name' => $row->label === $externalRef ? null : $row->label,
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
