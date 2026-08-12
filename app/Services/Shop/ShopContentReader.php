<?php

namespace App\Services\Shop;

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
 *    brands()/brandProducts()/connectStatus().
 *
 * 2. FIELD LOSS, product level. `handle`, `vendor`, `description`,
 *    `variantId` come back null for every product, unconditionally — see
 *    ShopContentWriter::cataloguesFor()'s own docblock; not recoverable from
 *    the read side.
 *
 * 3. FIELD LOSS, brand level. `selectionMode` and `linkMode` have no column
 *    on content.storefronts at all (migration 20260813100000) — this class
 *    reports the code-side DEFAULTS ('manual' / 'product') for every brand,
 *    the same fallback ShopBrand::toBrandArray() itself uses for a NULL
 *    column. A brand whose owner set selectionMode='latest' or
 *    linkMode='checkout' via updateBrand() reads back as the default here —
 *    not fixable from the read side without a schema change.
 *
 * 4. popularityRank is keyed by product HANDLE (content_popularity_scores'
 *    own scoring-pipeline convention — see ShopBrand::toBrandArray()'s own
 *    docblock), and gap 2 above means `handle` is always null here. So even
 *    with real ranking data, every product's popularityRank comes back null
 *    post-repoint, not just in a fixture with no ranks seeded. Confirmed
 *    empirically, not from the docblock: ShopController's own private
 *    brandMap() (the pre-Task-7 path this class replaces for brands()/
 *    brandProducts()/selection()) ALWAYS passes a ranks array — never null —
 *    to ShopBrand::toBrandArray(), so popularityRank is present (not
 *    omitted) on the DASHBOARD shape too, despite that method's docblock
 *    describing the key as public-path-only. This class mirrors that: pass
 *    $productRanks (even an empty array) to get the key; pass null (as
 *    connectStatus()'s fallback does) to omit it, matching
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
                // See class docblock, gap 3: no content.* column for either.
                'selectionMode' => 'manual',
                'linkMode' => 'product',
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
