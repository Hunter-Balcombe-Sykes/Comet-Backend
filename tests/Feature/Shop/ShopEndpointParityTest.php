<?php

use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\WooCommerceScraper;
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
// six products total. All three were originally seeded into site.shop_brands/
// site.shop_products (what the pre-Task-7 controllers read) and landed in
// content.* via app(ShopBackfiller::class)->run(). Both tables are dropped
// (20260819000200/210) and the backfiller with them, so the fixture now writes
// content.* directly through the same ShopContentWriter + ProjectionWriter
// lane the backfiller itself used — the shape it produced is preserved
// exactly, including brand-c's urlless product, which is still landed and
// still asserted below rather than special-cased.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

function parityFixture(): array
{
    // selection_mode/link_mode were set on brand-a here before the re-home.
    // content.storefronts has no column for either (slice 5a fix round 1,
    // Finding 4: selection_mode was always the default in practice, link_mode
    // is one global site setting), and the reader has derived both for a while
    // — so the expected bodies below are unaffected by their absence.
    [$user, $storeA, $site] = makeShopStore([
        'externalRef' => 'brand-a',
        'provider' => 'shopify',
        'url' => 'https://storea.example.com',
        'sourceUrl' => 'https://storea.example.com',
        'name' => 'Store A',
        'currency' => 'AUD',
        'discountCode' => 'SAVE10',
        'referralQuery' => 'ref=abc123',
        'logoMarkUrl' => 'https://cdn.example.com/a-mark.png',
        'logoMarkSvgUrl' => 'https://cdn.example.com/a-mark.svg',
        'position' => 0,
    ], withSite: true);

    $storeB = addShopStore($user, [
        'externalRef' => 'brand-b',
        'provider' => 'woocommerce',
        'url' => 'https://storeb.example.com',
        'sourceUrl' => 'https://storeb.example.com',
        'name' => 'Store B',
        'currency' => 'USD',
        'position' => 1,
    ]);

    // Fix round 3, Finding 5/6: a nameless brand — content.collections.label
    // is NOT NULL, so upsertStore() writes external_ref into it when name is
    // null. Bigcartel-shaped: that provider's own scraper return type is
    // `url:?string`, so its one product is urlless too (Finding 3/6).
    $storeC = addShopStore($user, [
        'externalRef' => 'brand-c',
        'provider' => 'bigcartel',
        'url' => 'https://storec.example.com',
        'sourceUrl' => 'https://storec.example.com',
        'name' => null,
        'currency' => 'AUD',
        'position' => 2,
    ]);

    makeShopStoreProduct($storeA, [
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
    makeShopStoreProduct($storeA, [
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
    makeShopStoreProduct($storeA, [
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

    makeShopStoreProduct($storeB, [
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
    // 'vendor' and no 'description', matching SquarespaceScraper::
    // fetchProducts(), which emits neither (nor an 'images' key: its product
    // array carries a single 'image' only, SquarespaceScraper.php:104).
    // vendor/description are OMITTED from the reconstructed response too
    // (Finding 2's fix), so this round-trips cleanly.
    //
    // Fix round 4, Finding 5 — comment corrected to what this fixture
    // ACTUALLY exercises: `images` below is an EMPTY ARRAY, not an absent
    // key. makeShopStoreProduct() cannot express key-absence at all — it
    // merges $data over a default blob that always carries 'images' => [] (see
    // tests/Pest.php), and every key it forces is one fromBlob() reads
    // without a `??` fallback. Nothing is lost on the WRITE side by that:
    // ShopProductProjection::media() reads `$data['images'] ?? []`, so
    // absent and `[]` are the same input. It does mean this fixture probes
    // the empty-array case, NOT the key-absent case, and the divergence
    // asserted below (`images` reading back as [cover]) is the same one p3
    // demonstrates rather than an additional Squarespace-specific one.
    makeShopStoreProduct($storeB, [
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
    makeShopStoreProduct($storeC, $rBlob);

    // Non-vacuity anchor, inherited from the ShopBackfiller run this replaced
    // (which asserted products=6, skipped_unidentifiable=0): all six products
    // — brand-c's urlless one included — really landed as live content.* items
    // before a single endpoint is called. Without it, an expected body that
    // came back empty could pass for the wrong reason.
    expect(DB::table('content.items')->where('kind', 'product')
        ->whereNull('removed_at')->count())->toBe(6);

    return [$user, $storeA, $storeB, $storeC, $site];
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
            // legacy blob showed [] (fix round 4, Finding 5: [] is what the
            // fixture actually stores — makeShopStoreProduct() cannot express an
            // absent 'images' key; see the fixture's own corrected comment).
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
                // DOCUMENTED DIVERGENCE (2026-08-17, Sell opt-in): autoLatest
                // joined the brand body — per-store, read off the anchor
                // connection's sparse display_settings. False here because
                // anchors mint with the explicit OFF default now.
                'autoLatest' => false,
                // DOCUMENTED DIVERGENCE (fix round 1, Finding 4): the
                // ORIGINAL dump showed 'latest' (this fixture's ShopBrand.
                // selection_mode) — selectionMode is now the derived
                // constant 'manual' (see ShopContentReader's docblock).
                // Deliberate, coordinator-directed spec change: selection_
                // mode's only real-world value was already the default.
                'discountCode' => 'SAVE10', 'selectionMode' => 'manual', 'referralQuery' => 'ref=abc123', 'individual' => false,
                'products' => brandAProductsDashboardShape(),
                'logoMark' => 'https://cdn.example.com/a-mark.png',
                'logoMarkSvg' => 'https://cdn.example.com/a-mark.svg',
            ],
            [
                'id' => 'brand-b', 'provider' => 'woocommerce', 'url' => 'https://storeb.example.com',
                'name' => 'Store B', 'currency' => 'USD', 'favicon' => null, 'logo' => null,
                'autoLatest' => false,
                // DOCUMENTED DIVERGENCE (fix round 1, Finding 4): the
                // ORIGINAL dump showed 'product' (this fixture never set
                // ShopBrand.link_mode, so toBrandArray()'s per-brand default
                // applied) — linkMode now reads site.sites.shop_link_mode
                // (one value for the whole map, this fixture's site
                // defaults to 'checkout'), since it is really ONE site-wide
                // setting, never a per-brand one.
                'discountCode' => '', 'selectionMode' => 'manual', 'referralQuery' => '', 'individual' => false,
                'products' => brandBProductsDashboardShape(),
            ],
            [
                'id' => 'brand-c', 'provider' => 'bigcartel', 'url' => 'https://storec.example.com',
                'autoLatest' => false,
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
                'discountCode' => '', 'selectionMode' => 'manual', 'referralQuery' => '', 'individual' => false,
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

    // autoLatest FALSE (owner, 2026-08-17): a store's auto-latest toggle is
    // written OFF on mint (ShopConnections::anchor) now that shop runs the
    // pins + latest-per-source shape — otherwise every store would publish
    // its newest product the moment it connects.
    $res->assertExactJson([
        // The SITE-WIDE linkMode lives here and stays — only the per-brand
        // echo left the wire (2026-08-19).
        'linkMode' => 'checkout',
        'autoLatest' => false,
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
            'autoLatest' => false,
            // DOCUMENTED DIVERGENCE — see the identical note in the
            // GET /brands test above.
            'discountCode' => 'SAVE10', 'selectionMode' => 'manual', 'referralQuery' => 'ref=abc123', 'individual' => false,
            'products' => $productsNoRank,
            'logoMark' => 'https://cdn.example.com/a-mark.png',
            'logoMarkSvg' => 'https://cdn.example.com/a-mark.svg',
        ],
    ]);
});

// ── Task 8: the nine write endpoints, repointed to content.* ──────────────
//
// Scope note carried over from the header above: this file's fixtures now
// also exercise the WRITE endpoints Task 8 repoints. makeStoreCollection()
// (tests/Pest.php) builds a store already landed in content.* through the real
// ShopContentWriter::upsertStore() + ProjectionWriter::writeManualItem() lane
// — the same one ShopBackfiller used before it and its source tables were
// dropped — not hand-rolled rows.

it('setProducts writes the selection to content.* in the given order', function () {
    // setProducts() always re-fetches the LIVE catalog (picker-cache miss
    // here) rather than reading what's already selected, so the mocked
    // catalog below is what the user is choosing from — withProducts: 0
    // seeds no catalogue at all, so the ordering assertion below is entirely
    // the ENDPOINT's work rather than partly the fixture's.
    // makeShopStore()'s defaults: provider=shopify, url=https://store.test,
    // currency=AUD.
    [$user, $collectionId, $brandId] = makeStoreCollection(withProducts: 0);
    mockShopService(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('fetchProducts')->with('https://store.test', 'AUD')->andReturn([
            ['productId' => 'sku-a', 'title' => 'A', 'url' => 'https://store.test/a', 'price' => '10.00', 'currency' => 'AUD', 'available' => true],
            ['productId' => 'sku-b', 'title' => 'B', 'url' => 'https://store.test/b', 'price' => '10.00', 'currency' => 'AUD', 'available' => true],
        ]);
    });

    actingAsUser($user)->putJson("/api/platforms/shop/brands/{$brandId}/selection", [
        'productIds' => ['sku-b', 'sku-a'],
    ])->assertOk();

    $positions = DB::table('content.collection_items')->where('collection_id', $collectionId)
        ->orderBy('position')->pluck('item_id');
    expect($positions)->toHaveCount(2);
    $itemIdForSku = fn (string $sku) => DB::table('content.f_catalog')->where('sku', $sku)->value('item_id');
    expect($positions->all())->toBe([$itemIdForSku('sku-b'), $itemIdForSku('sku-a')]);
});

it('setProducts stamps products_curated_at on the storefront', function () {
    // #SEM-1: this is the flag ShopContentWriter::isCurated() reads FIRST. It
    // used to read a legacy site.shop_brands column ahead of it; that column
    // and its table are gone, so this row is the sole source of truth.
    [$user, $collectionId, $brandId] = makeStoreCollection(withProducts: 1);
    mockShopService(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('fetchProducts')->with('https://store.test', 'AUD')->andReturn([
            ['productId' => 'sku-a', 'title' => 'A', 'url' => 'https://store.test/a', 'price' => '10.00', 'currency' => 'AUD', 'available' => true],
        ]);
    });

    actingAsUser($user)->putJson("/api/platforms/shop/brands/{$brandId}/selection",
        ['productIds' => ['sku-a']])->assertOk();

    expect(DB::table('content.storefronts')->where('collection_id', $collectionId)
        ->value('products_curated_at'))->not->toBeNull();
});

it('removeBrand retires items rather than deleting them', function () {
    [$user, $collectionId, $brandId] = makeStoreCollection(withProducts: 2);

    actingAsUser($user)->deleteJson("/api/platforms/shop/brands/{$brandId}")->assertOk();

    expect(DB::table('content.items')->where('kind', 'product')->count())->toBe(2)
        ->and(DB::table('content.items')->whereNull('removed_at')->count())->toBe(0)
        ->and(DB::table('content.collections')->count())->toBe(0);
});

it('removeProduct retires one item and leaves its siblings alone', function () {
    // The individual-products bucket, not a connected store — removeProduct()
    // only ever operates on ShopController::INDIVIDUAL_BRAND_ID. Built via
    // three real addProduct() calls (not makeStoreCollection(), which seeds a
    // regular store) so this exercises the actual endpoint pairing under test.
    // makeShopUser() rather than makeShopStore() for the same reason: a
    // connected store in content.* would put a second collection in every
    // count below.
    [$user] = makeShopUser();

    mockShopService(GenericShopScraper::class, function ($m) {
        $m->shouldReceive('readProductPage')->andReturn(
            ['outcome' => GenericShopScraper::OUTCOME_PRODUCT, 'product' => ['productId' => 'p1', 'title' => 'One', 'url' => 'https://s.test/p1', 'price' => '10.00'], 'storeUrl' => null],
            ['outcome' => GenericShopScraper::OUTCOME_PRODUCT, 'product' => ['productId' => 'p2', 'title' => 'Two', 'url' => 'https://s.test/p2', 'price' => '10.00'], 'storeUrl' => null],
            ['outcome' => GenericShopScraper::OUTCOME_PRODUCT, 'product' => ['productId' => 'p3', 'title' => 'Three', 'url' => 'https://s.test/p3', 'price' => '10.00'], 'storeUrl' => null],
        );
    });

    actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => 'https://s.test/p1'])->assertSuccessful();
    actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => 'https://s.test/p2'])->assertSuccessful();
    actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => 'https://s.test/p3'])->assertSuccessful();
    expect(DB::table('content.items')->whereNull('removed_at')->count())->toBe(3);

    actingAsUser($user)->deleteJson('/api/platforms/shop/products/p2')->assertOk();

    expect(DB::table('content.items')->whereNull('removed_at')->count())->toBe(2);
});

// Fix round 1, C1 regression. The bucket row's lifetime is the whole bug:
// removeProduct() used to ask site.shop_products "is this bucket empty now?",
// a table addProduct() had stopped writing, so the FIRST removal always
// deleted the bucket and stranded the surviving content.* products — the
// dashboard still listed them and every later DELETE 404'd on the missing
// bucket. Both halves are asserted here: the bucket SURVIVES a partial
// removal (with the siblings still listed and still removable), and is still
// cleaned up exactly as before once the last product goes.
//
// Re-home Task 7: the bucket IS its content.storefronts row now — the legacy
// site.shop_brands twin this used to check was written by nothing, so asking
// that table would answer "gone" from the very first call and make the guard
// vacuous, which is the same failure mode C1 was. That table is since dropped
// outright, which settles the question permanently.
it('removeProduct keeps the individual bucket row until the last product is gone', function () {
    [$user] = makeShopUser();

    mockShopService(GenericShopScraper::class, function ($m) {
        $m->shouldReceive('readProductPage')->andReturn(
            ['outcome' => GenericShopScraper::OUTCOME_PRODUCT, 'product' => ['productId' => 'p1', 'title' => 'One', 'url' => 'https://s.test/p1', 'price' => '10.00'], 'storeUrl' => null],
            ['outcome' => GenericShopScraper::OUTCOME_PRODUCT, 'product' => ['productId' => 'p2', 'title' => 'Two', 'url' => 'https://s.test/p2', 'price' => '10.00'], 'storeUrl' => null],
            ['outcome' => GenericShopScraper::OUTCOME_PRODUCT, 'product' => ['productId' => 'p3', 'title' => 'Three', 'url' => 'https://s.test/p3', 'price' => '10.00'], 'storeUrl' => null],
        );
    });

    foreach (['p1', 'p2', 'p3'] as $pid) {
        actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => "https://s.test/{$pid}"])->assertSuccessful();
    }

    $bucketExists = fn (): bool => DB::table('content.storefronts')->where('external_ref', 'individual')->exists();
    expect($bucketExists())->toBeTrue();

    // One of three removed: the bucket is NOT empty, so it must stay.
    actingAsUser($user)->deleteJson('/api/platforms/shop/products/p2')->assertOk();
    expect($bucketExists())->toBeTrue()
        ->and(orderedProductIdsFor('individual'))->toBe(['p3', 'p1']);

    // The siblings are still listed by the dashboard AND still removable —
    // the two symptoms the stranded bucket produced.
    actingAsUser($user)->getJson('/api/platforms/shop/brands')
        ->assertOk()
        ->assertJsonPath('brands.0.id', 'individual')
        ->assertJsonPath('brands.0.products.0.productId', 'p3')
        ->assertJsonPath('brands.0.products.1.productId', 'p1');

    actingAsUser($user)->deleteJson('/api/platforms/shop/products/p3')->assertOk();
    expect($bucketExists())->toBeTrue()
        ->and(orderedProductIdsFor('individual'))->toBe(['p1']);

    // Last one out: the bucket and its collection are both cleaned up,
    // exactly as before this fix.
    actingAsUser($user)->deleteJson('/api/platforms/shop/products/p1')
        ->assertOk()
        ->assertJsonPath('brands', []);
    expect($bucketExists())->toBeFalse()
        ->and(DB::table('content.storefronts')->where('external_ref', 'individual')->count())->toBe(0)
        ->and(DB::table('content.collections')->where('kind', 'storefront')->count())->toBe(0)
        // Retired, never hard-deleted.
        ->and(DB::table('content.items')->whereNull('removed_at')->count())->toBe(0)
        ->and(DB::table('content.items')->count())->toBe(3);
});

it('forget removes every store for the user and retires their items', function () {
    [$user, $collectionId, $brandId] = makeStoreCollection(withProducts: 2);

    actingAsUser($user)->deleteJson('/api/platforms/shop')->assertOk();

    expect(DB::table('content.collections')->count())->toBe(0)
        ->and(DB::table('content.items')->whereNull('removed_at')->count())->toBe(0);
});

// ── Step 5: the inertness proof, inverted by the DROP ─────────────────────
//
// This pair used to assert that no shop write endpoint touched site
// .shop_products (Task 8) or site.shop_brands (the re-home) — measured by
// max(updated_at) and row count staying put across all nine writes.
//
// Both tables are dropped (20260819000200 / 20260819000210), so those
// assertions cannot be made and cannot fail: there is nothing left to observe.
// The property they were protecting is now enforced by the database itself —
// a resurrected legacy write is an immediate hard error, not a silent stale
// card, which is precisely the outcome the two guards existed to reach.
//
// What is NOT settled by the DROP, and is worth exactly as much as the guards
// were, is the other side of that coin: every one of the nine write endpoints
// must still complete against content.* alone. That is what this test asserts
// now — exerciseAllShopWrites() calls each one once and requires a successful
// response from every one, so a path that still reached for a legacy table
// would fail here rather than anywhere subtler.

it('every shop write endpoint completes with the legacy tables gone', function () {
    // exerciseAllShopWrites() calls updateSettings(), which hard-requires a
    // current site.
    [$user, $store] = makeShopStore(withSite: true);

    // Nine write endpoints, each asserted successful inside the helper —
    // addBrand, updateBrand, catalog, selection, addProduct, removeProduct,
    // removeBrand, updateSettings, forget, in that order.
    exerciseAllShopWrites($this, $user, $store);

    // forget() runs last and wipes the family, so content.* is the only place
    // that can show the run really did the work: no storefront survives, and
    // every item it touched is retired rather than hard-deleted.
    expect(DB::table('content.collections')->where('kind', 'storefront')->count())->toBe(0)
        ->and(DB::table('content.storefronts')->count())->toBe(0)
        ->and(DB::table('content.items')->whereNull('removed_at')->count())->toBe(0);
});

// Per-store auto-latest (2026-08-17, Sell opt-in): PATCH /brands/{id}
// autoLatest writes THAT store's anchor alone and reads back per store —
// unlike /shop/settings autoLatest, which writes every store at once.
it('PATCH /brands/{id} autoLatest flips one store and leaves its siblings alone', function () {
    [$user] = parityFixture();

    actingAsUser($user)->patchJson('/api/platforms/shop/brands/brand-a', ['autoLatest' => true])
        ->assertOk();

    // Anchors mint OFF, so the flip under test is OFF → ON.
    $brands = collect(actingAsUser($user)->getJson('/api/platforms/shop/brands')->json('brands'))
        ->keyBy('id');
    expect($brands['brand-a']['autoLatest'])->toBeTrue()
        ->and($brands['brand-b']['autoLatest'])->toBeFalse();

    actingAsUser($user)->patchJson('/api/platforms/shop/brands/brand-a', ['autoLatest' => false])
        ->assertOk();
    expect(collect(actingAsUser($user)->getJson('/api/platforms/shop/brands')->json('brands'))
        ->keyBy('id')['brand-a']['autoLatest'])->toBeFalse();
});
