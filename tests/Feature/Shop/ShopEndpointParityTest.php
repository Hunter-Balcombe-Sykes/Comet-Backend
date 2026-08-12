<?php

use App\Models\Core\Site\ShopBrand;
use App\Services\Migration\ShopBackfiller;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\WooCommerceScraper;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Support\Facades\DB;

// Slice 5a Task 7 §Step 1-2. A response-shape diff, not a shape review: the
// expected arrays below were CAPTURED by dumping the real JSON these five
// endpoints returned against the UNCHANGED (pre-Task-7) controllers — pasted
// here verbatim from that dump (see the Task 7 report for the raw output).
// They are never hand-derived from a docblock, and after the repoint they
// must never be silently edited to hide a real failure — every divergence
// from that original dump that survives fix rounds 1-3 is named and
// asserted EXPLICITLY below, not avoided (fix round 3, Finding 6).
//
// Scope: the FIVE read endpoints Task 7 repoints (brands, brandProducts,
// selection, settings, connectStatus). The nine write endpoints are Task 8's
// territory and untouched by this task, so their parity is out of this
// file's scope.
//
// Assertions use TestResponse::assertExactJson(), not expect()->toEqual() —
// fix round 3 MINOR: toEqual() uses loose `==` (null == false and
// '25.00' == 25.0 both pass), which is not what "byte-identical" means here.
//
// Fixture: one user, THREE stores (brand-a/b fully populated Shopify/
// WooCommerce-shaped; brand-c nameless + a urlless product, bigcartel-shaped),
// six products total. brand-a/b are seeded via site.shop_brands/
// site.shop_products (what the pre-Task-7 controllers read) then landed in
// content.* via app(ShopBackfiller::class)->run() — the real migration path.
// brand-c's product is landed via a DIRECT ShopContentWriter::syncStore()
// call instead (see the fixture's own comment for why: ShopBackfiller::run()
// has the SAME urlless-skip gap syncStore() had before fix round 3, Finding
// 3 — out of this round's explicit scope, flagged in the Task 7 report, not
// fixed here).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

function parityFixture(): array
{
    [$user, $brandA, $site] = makeShopBrand([
        'brand_id' => 'brand-a',
        'provider' => 'shopify',
        'url' => 'https://storea.example.com',
        'source_url' => 'https://storea.example.com',
        'name' => 'Store A',
        'currency' => 'AUD',
        'discount_code' => 'SAVE10',
        'referral_query' => 'ref=abc123',
        'selection_mode' => 'latest',
        'link_mode' => 'checkout',
        'logo_mark_url' => 'https://cdn.example.com/a-mark.png',
        'logo_mark_svg_url' => 'https://cdn.example.com/a-mark.svg',
        'position' => 0,
    ], withSite: true);

    $brandB = ShopBrand::create([
        'connection_id' => $brandA->connection_id,
        'brand_id' => 'brand-b',
        'provider' => 'woocommerce',
        'url' => 'https://storeb.example.com',
        'source_url' => 'https://storeb.example.com',
        'name' => 'Store B',
        'currency' => 'USD',
        'discount_code' => '',
        'referral_query' => '',
        'is_individual' => false,
        'position' => 1,
    ]);

    // Fix round 3, Finding 5/6: a nameless brand — content.collections.label
    // is NOT NULL, so upsertStore() writes brand_id into it when name is
    // null. Bigcartel-shaped: that provider's own scraper return type is
    // `url:?string`, so its one product is urlless too (Finding 3/6).
    $brandC = ShopBrand::create([
        'connection_id' => $brandA->connection_id,
        'brand_id' => 'brand-c',
        'provider' => 'bigcartel',
        'url' => 'https://storec.example.com',
        'source_url' => 'https://storec.example.com',
        'name' => null,
        'currency' => 'AUD',
        'discount_code' => '',
        'referral_query' => '',
        'is_individual' => false,
        'position' => 2,
    ]);

    makeShopProduct($brandA, [
        'productId' => 'p1',
        'title' => 'Classic Tee',
        'handle' => 'classic-tee',
        'vendor' => 'Acme Apparel',
        'description' => 'A classic tee, hand-stitched.',
        'image' => 'https://cdn.example.com/p1.jpg',
        'images' => ['https://cdn.example.com/p1.jpg', 'https://cdn.example.com/p1-alt.jpg'],
        'price' => '25.00',
        'currency' => 'AUD',
        'variantId' => 'v1',
        'available' => true,
        'url' => 'https://storea.example.com/products/classic-tee',
        'createdAt' => '2026-01-05T00:00:00Z',
        'variants' => [
            ['id' => 'v1', 'title' => 'Small', 'price' => '25.00', 'available' => true, 'image' => null],
            ['id' => 'v2', 'title' => 'Large', 'price' => '27.00', 'available' => false, 'image' => null],
        ],
    ]);
    makeShopProduct($brandA, [
        'productId' => 'p2',
        'title' => 'Canvas Tote',
        'handle' => 'canvas-tote',
        'vendor' => 'Acme Apparel',
        'description' => 'Sturdy canvas tote bag.',
        'image' => 'https://cdn.example.com/p2.jpg',
        'images' => ['https://cdn.example.com/p2.jpg'],
        'price' => '15.00',
        'currency' => 'AUD',
        'variantId' => 'p2',
        'available' => true,
        'url' => 'https://storea.example.com/products/canvas-tote',
        'createdAt' => '2026-02-10T00:00:00Z',
        'variants' => [],
    ]);
    makeShopProduct($brandA, [
        'productId' => 'p3',
        'title' => 'Wool Beanie',
        'handle' => 'wool-beanie',
        'vendor' => 'Acme Apparel',
        'description' => 'Warm wool beanie.',
        // Fix round 3, Finding 2: round 2 wrongly "corrected" this to
        // include the cover in images[], on the false claim that no real
        // scraper emits `image` with an empty/absent `images`.
        // SquarespaceScraper::fetchProducts() and GenericShopScraper::
        // productFromOpenGraph() both do exactly that. Reverted to this
        // legitimate single-cover, no-gallery shape — content.* genuinely
        // cannot represent it (media() collapses `[]` and `[cover]` onto
        // the same one cover row), so it is a real, permanent, DOCUMENTED
        // divergence (asserted explicitly below), not a fixture bug.
        'image' => 'https://cdn.example.com/p3.jpg',
        'images' => [],
        'price' => '18.00',
        'currency' => 'AUD',
        'variantId' => 'p3',
        'available' => false,
        'url' => 'https://storea.example.com/products/wool-beanie',
        'createdAt' => '2026-03-01T00:00:00Z',
        'variants' => [],
    ]);

    makeShopProduct($brandB, [
        'productId' => 'q1',
        'title' => 'Ceramic Mug',
        'handle' => 'ceramic-mug',
        'vendor' => 'Store B Goods',
        'description' => 'Handmade ceramic mug.',
        'image' => 'https://cdn.example.com/q1.jpg',
        'images' => ['https://cdn.example.com/q1.jpg'],
        'price' => '12.00',
        'currency' => 'USD',
        'variantId' => 'q1',
        'available' => true,
        'url' => 'https://storeb.example.com/product/ceramic-mug',
        'createdAt' => '2026-01-20T00:00:00Z',
        'variants' => [],
    ]);
    // Fix round 3, Finding 2/6: deliberately Squarespace-shaped — no
    // 'vendor', no 'description', no 'images' key at all (matching
    // SquarespaceScraper::fetchProducts()'s real return array exactly).
    // vendor/description are OMITTED from the reconstructed response too
    // (Finding 2's fix), so this round-trips cleanly; `images` still
    // diverges the same way p3's does (see above).
    makeShopProduct($brandB, [
        'productId' => 'q2',
        'title' => 'Linen Napkin Set',
        'handle' => 'linen-napkin-set',
        'image' => 'https://cdn.example.com/q2.jpg',
        'images' => [],
        'price' => '32.00',
        'currency' => 'USD',
        'variantId' => 'q2',
        'available' => true,
        'url' => 'https://storeb.example.com/product/linen-napkin-set',
        'createdAt' => '2026-01-25T00:00:00Z',
        'variants' => [],
    ]);

    // Fix round 3, Finding 3/6: r1 has no url at all (explicit null, matching
    // BigCartelScraper's own `url:?string` return type) — proves a urlless-
    // but-identified product is neither dropped by the writer nor skipped by
    // the reader.
    $rBlob = [
        'productId' => 'r1',
        'title' => 'Mystery Box',
        'url' => null,
        'price' => '9.99',
        'currency' => 'AUD',
        'available' => true,
        'image' => null,
        'images' => [],
        'variants' => [],
        'createdAt' => '2026-04-01T00:00:00Z',
    ];
    makeShopProduct($brandC, $rBlob);

    // The actual production migration path — lands brand-a/b's seeded data
    // in content.* the same way ShopBackfiller does on real dev/prod data.
    app(ShopBackfiller::class)->run();

    // brand-c's collection now exists (upsertStore() ran unconditionally),
    // but ShopBackfiller::run()'s own per-product loop ALSO skips a urlless
    // product (`if ($url === '') { $result['skipped_no_url']++; continue; }`
    // — the same gap syncStore() had before this round, NOT fixed here, out
    // of explicit scope — see the Task 7 report). Land r1 via the REAL,
    // FIXED sync path directly instead — exactly what a scheduled
    // ShopCatalog::syncLatest() resync does in production.
    $collectionCId = DB::table('content.storefronts')->where('external_ref', 'brand-c')->value('collection_id');
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionCId, [$rBlob], 'AUD');

    return [$user, $brandA, $brandB, $brandC, $site];
}

/**
 * brandProducts() always LIVE-scrapes via ShopCatalog::providerProducts() —
 * it never reads stored products — so both the baseline and the repointed
 * run need the SAME scraper mock. This is unaffected by Task 7: it proves
 * the picker's live path is untouched, which is the point of covering it
 * here at all.
 */
function mockParityScrapers(): void
{
    test()->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('fetchProducts')
            ->with('https://storea.example.com', 'AUD')
            ->andReturn([
                ['productId' => 'p1', 'title' => 'Classic Tee', 'url' => 'https://storea.example.com/products/classic-tee', 'price' => '25.00', 'currency' => 'AUD', 'available' => true],
            ]);
    });
    test()->mock(WooCommerceScraper::class, function ($m) {
        $m->shouldReceive('fetchProducts')
            ->with('https://storeb.example.com')
            ->andReturn([
                ['productId' => 'q1', 'title' => 'Ceramic Mug', 'url' => 'https://storeb.example.com/product/ceramic-mug', 'price' => '12.00', 'currency' => 'USD', 'available' => true],
            ]);
    });
}

// brand-a's three products, DASHBOARD shape (brands()/selection() go through
// ShopController's private brandMap(), which ALWAYS passes a (possibly
// empty) popularity-ranks array to toBrandArray() — never null — so
// popularityRank IS present here despite ShopBrand::toBrandArray()'s own
// docblock describing it as public-path-only. Captured from the dump, not
// the docblock — see this file's header.
//
// createdAt below is '+00:00'-notated, NOT the 'Z' the fixture stores (fix
// round 3, Finding 1): content.f_published.published_from is a real
// Postgres timestamptz, so the read path reformats through
// Carbon::parse(...)->utc()->toIso8601String() to guarantee SQLite and
// Postgres emit the identical string — that formatter always renders the
// offset form, never 'Z'. Both drivers producing the same string is the
// achievable goal; matching the ORIGINAL literal ('Z') is not, once the
// value lives in a real typed column — see the Task 7 report for the
// empirical proof against a real Postgres container.
function brandAProductsDashboardShape(): array
{
    return [
        [
            'productId' => 'p1', 'title' => 'Classic Tee',
            'url' => 'https://storea.example.com/products/classic-tee',
            'price' => '25.00', 'currency' => 'AUD', 'available' => true,
            'image' => 'https://cdn.example.com/p1.jpg',
            'images' => ['https://cdn.example.com/p1.jpg', 'https://cdn.example.com/p1-alt.jpg'],
            'variants' => [
                ['id' => 'v1', 'title' => 'Small', 'price' => '25.00', 'available' => true, 'image' => null],
                ['id' => 'v2', 'title' => 'Large', 'price' => '27.00', 'available' => false, 'image' => null],
            ],
            'handle' => 'classic-tee', 'vendor' => 'Acme Apparel',
            'description' => 'A classic tee, hand-stitched.', 'variantId' => 'v1',
            'createdAt' => '2026-01-05T00:00:00+00:00', 'popularityRank' => null,
        ],
        [
            'productId' => 'p2', 'title' => 'Canvas Tote',
            'url' => 'https://storea.example.com/products/canvas-tote',
            'price' => '15.00', 'currency' => 'AUD', 'available' => true,
            'image' => 'https://cdn.example.com/p2.jpg',
            'images' => ['https://cdn.example.com/p2.jpg'],
            'variants' => [],
            'handle' => 'canvas-tote', 'vendor' => 'Acme Apparel',
            'description' => 'Sturdy canvas tote bag.', 'variantId' => 'p2',
            'createdAt' => '2026-02-10T00:00:00+00:00', 'popularityRank' => null,
        ],
        [
            'productId' => 'p3', 'title' => 'Wool Beanie',
            'url' => 'https://storea.example.com/products/wool-beanie',
            'price' => '18.00', 'currency' => 'AUD', 'available' => false,
            'image' => 'https://cdn.example.com/p3.jpg',
            // DOCUMENTED DIVERGENCE (fix round 3, Finding 2): the legacy
            // blob's images WAS []. content.* cannot represent "cover set,
            // gallery empty" separately from "cover set, gallery repeats
            // it" — the reconstruction always emits [cover, ...gallery]
            // once a cover exists (the Task 7 brief's own explicit mapping).
            // A REAL divergence, asserted, not avoided.
            'images' => ['https://cdn.example.com/p3.jpg'],
            'variants' => [],
            'handle' => 'wool-beanie', 'vendor' => 'Acme Apparel',
            'description' => 'Warm wool beanie.', 'variantId' => 'p3',
            'createdAt' => '2026-03-01T00:00:00+00:00', 'popularityRank' => null,
        ],
    ];
}

function brandBProductsDashboardShape(): array
{
    return [
        [
            'productId' => 'q1', 'title' => 'Ceramic Mug',
            'url' => 'https://storeb.example.com/product/ceramic-mug',
            'price' => '12.00', 'currency' => 'USD', 'available' => true,
            'image' => 'https://cdn.example.com/q1.jpg',
            'images' => ['https://cdn.example.com/q1.jpg'],
            'variants' => [],
            'handle' => 'ceramic-mug', 'vendor' => 'Store B Goods',
            'description' => 'Handmade ceramic mug.', 'variantId' => 'q1',
            'createdAt' => '2026-01-20T00:00:00+00:00', 'popularityRank' => null,
        ],
        [
            'productId' => 'q2', 'title' => 'Linen Napkin Set',
            'url' => 'https://storeb.example.com/product/linen-napkin-set',
            'price' => '32.00', 'currency' => 'USD', 'available' => true,
            'image' => 'https://cdn.example.com/q2.jpg',
            // DOCUMENTED DIVERGENCE — see p3's identical note above. The
            // legacy blob (deliberately Squarespace-shaped: no 'images' key
            // at all) also showed [].
            'images' => ['https://cdn.example.com/q2.jpg'],
            'variants' => [],
            'handle' => 'linen-napkin-set',
            // No 'vendor'/'description' keys — fix round 3, Finding 2: the
            // legacy blob never had them either (Squarespace's own scraper
            // never emits them), so OMITTING rather than nulling round-trips
            // this byte-identically, not a divergence.
            'variantId' => 'q2',
            'createdAt' => '2026-01-25T00:00:00+00:00', 'popularityRank' => null,
        ],
    ];
}

// brand-c's one product — see parityFixture()'s own comments. No legacy dump
// exists for this brand (it is new fixture coverage, fix round 3, Finding
// 6), so this shape is derived by reasoning, not captured: `handle` and
// `variantId` are present as explicit null (Finding 2's omit-when-null
// treatment was scoped to vendor/description only, not these two) — but
// this is NOT a divergence from the legacy shape either, since $rBlob never
// set 'handle'/'variantId' at all and the legacy dashboard's verbatim
// pass-through would show them as PHP array keys missing entirely, which
// json_encode() renders as absent, not null — so this IS a real, minor
// divergence (key-absent vs key-present-with-null), same category as
// vendor/description before Finding 2's fix, just not extended to these
// two fields this round. Documented here, not silently accepted.
function brandCProductsDashboardShape(): array
{
    return [
        [
            'productId' => 'r1', 'title' => 'Mystery Box',
            'url' => null,
            'price' => '9.99', 'currency' => 'AUD', 'available' => true,
            'handle' => null,
            'image' => null,
            'images' => [],
            'variantId' => null,
            'createdAt' => '2026-04-01T00:00:00+00:00', 'popularityRank' => null,
            'variants' => [],
        ],
    ];
}

it('GET /brands — matches the pre-Task-7 dump, with every remaining divergence named', function () {
    [$user] = parityFixture();

    $res = actingAsUser($user)->getJson('/api/platforms/shop/brands')->assertOk();

    $res->assertExactJson([
        'brands' => [
            [
                'id' => 'brand-a', 'provider' => 'shopify', 'url' => 'https://storea.example.com',
                'name' => 'Store A', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
                // DOCUMENTED DIVERGENCE (fix round 1, Finding 4): the
                // ORIGINAL dump showed 'latest' (this fixture's ShopBrand.
                // selection_mode) — selectionMode is now the derived
                // constant 'manual' (see ShopContentReader's docblock).
                // Deliberate, coordinator-directed spec change: selection_
                // mode's only real-world value was already the default.
                'discountCode' => 'SAVE10', 'selectionMode' => 'manual', 'linkMode' => 'checkout',
                'referralQuery' => 'ref=abc123', 'individual' => false,
                'products' => brandAProductsDashboardShape(),
                'logoMark' => 'https://cdn.example.com/a-mark.png',
                'logoMarkSvg' => 'https://cdn.example.com/a-mark.svg',
            ],
            [
                'id' => 'brand-b', 'provider' => 'woocommerce', 'url' => 'https://storeb.example.com',
                'name' => 'Store B', 'currency' => 'USD', 'favicon' => null, 'logo' => null,
                // DOCUMENTED DIVERGENCE (fix round 1, Finding 4): the
                // ORIGINAL dump showed 'product' (this fixture never set
                // ShopBrand.link_mode, so toBrandArray()'s per-brand default
                // applied) — linkMode now reads site.sites.shop_link_mode
                // (one value for the whole map, this fixture's site
                // defaults to 'checkout'), since it is really ONE site-wide
                // setting, never a per-brand one.
                'discountCode' => '', 'selectionMode' => 'manual', 'linkMode' => 'checkout',
                'referralQuery' => '', 'individual' => false,
                'products' => brandBProductsDashboardShape(),
            ],
            [
                'id' => 'brand-c', 'provider' => 'bigcartel', 'url' => 'https://storec.example.com',
                // DOCUMENTED DIVERGENCE (fix round 3, Finding 5): a
                // nameless brand reads back `name: null`, not its brand_id
                // — content.collections.label is NOT NULL and upsertStore()
                // falls back to writing the id into it; the reader
                // recognises that exact fallback (label === external_ref)
                // and nulls it back out rather than surface a value the
                // legacy dashboard never showed. This is new fixture
                // coverage, not a captured legacy dump — the legacy value
                // for THIS fixture would genuinely have been null too
                // (ShopBrand.name was set to null), so there is no
                // divergence to accept here, only a fix to prove.
                'name' => null, 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
                'discountCode' => '', 'selectionMode' => 'manual', 'linkMode' => 'checkout',
                'referralQuery' => '', 'individual' => false,
                'products' => brandCProductsDashboardShape(),
            ],
        ],
    ]);
});

it('GET /brands/{id}/products — matches the pre-Task-7 dump', function () {
    [$user] = parityFixture();
    mockParityScrapers();

    $res = actingAsUser($user)->getJson('/api/platforms/shop/brands/brand-a/products')->assertOk();

    $res->assertExactJson([
        'products' => [
            [
                'productId' => 'p1', 'title' => 'Classic Tee',
                'url' => 'https://storea.example.com/products/classic-tee',
                'price' => '25.00', 'currency' => 'AUD', 'available' => true,
            ],
        ],
    ]);
});

it('GET /selection — matches the pre-Task-7 dump', function () {
    [$user] = parityFixture();

    $res = actingAsUser($user)->getJson('/api/platforms/shop/selection')->assertOk();

    $res->assertExactJson([
        'selection' => [
            'url' => 'https://storea.example.com',
            'provider' => 'shopify',
            'discountCode' => 'SAVE10',
            'products' => brandAProductsDashboardShape(),
        ],
    ]);
});

it('GET /settings — matches the pre-Task-7 dump', function () {
    [$user] = parityFixture();

    $res = actingAsUser($user)->getJson('/api/platforms/shop/settings')->assertOk();

    $res->assertExactJson([
        'linkMode' => 'checkout',
        'autoLatest' => true,
    ]);
});

it('GET /brands/{id}/connect/status — matches the pre-Task-7 dump', function () {
    [$user] = parityFixture();

    $res = actingAsUser($user)->getJson('/api/platforms/shop/brands/brand-a/connect/status')->assertOk();

    // No popularityRank here — connectStatus() calls toBrandArray() with no
    // args (default null), unlike brands()/selection() above.
    $productsNoRank = array_map(function (array $p) {
        unset($p['popularityRank']);

        return $p;
    }, brandAProductsDashboardShape());

    $res->assertExactJson([
        'status' => 'ready',
        'id' => 'brand-a',
        'brand' => [
            'id' => 'brand-a', 'provider' => 'shopify', 'url' => 'https://storea.example.com',
            'name' => 'Store A', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
            // DOCUMENTED DIVERGENCE — see the identical note in the
            // GET /brands test above.
            'discountCode' => 'SAVE10', 'selectionMode' => 'manual', 'linkMode' => 'checkout',
            'referralQuery' => 'ref=abc123', 'individual' => false,
            'products' => $productsNoRank,
            'logoMark' => 'https://cdn.example.com/a-mark.png',
            'logoMarkSvg' => 'https://cdn.example.com/a-mark.svg',
        ],
    ]);
});
