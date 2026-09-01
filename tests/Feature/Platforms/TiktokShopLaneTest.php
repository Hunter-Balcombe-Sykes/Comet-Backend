<?php

use App\Ingest\Connectors\TiktokShopConnector;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\RecordView;
use App\Ingest\Projection\TiktokShopReviewProjector;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\TiktokShopVendorDriver;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Services\Platforms\TiktokShopScraper;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Item 10b (2026-09-01): the TikTok Shop LANE, end to end on the recorded
// Goli fixtures — the wiring the contract tests (ScrapeCreatorsTiktokShopTest)
// deliberately left out. Two pools, two paths, one identity:
//
//  1. Products → shop pool: TiktokShopScraper feeds ShopContentWriter::
//     syncStore's catalogue-blob path — the SAME writer every other store
//     provider uses — keyed on the seller id as external_ref.
//  2. Reviews → reviews pool: TiktokShopVendorDriver walks products →
//     per-product reviews under the Item 8 budget contract, the connector
//     lands review Records, and TiktokShopReviewProjector (Fresha's twin)
//     projects them with product aggregates as source_stats.

function ttShopLaneProductsFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-tiktok-shop-products.json')),
        true
    );
}

function ttShopLaneReviewsFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-tiktok-shop-product-reviews.json')),
        true
    );
}

function ttShopLaneCtx(array $input): BilledEffectContext
{
    return new BilledEffectContext('vendor', 'tiktok_shop', $input, 'run-1', 'source-1', 'user-1');
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.tiktok_shop', 50);
});

// ── (a) Products: seller-id identity and the syncStore catalogue path ───────

it('resolves the seller id from every connect-surface shape and refuses the rest', function () {
    expect(TiktokShopScraper::sellerIdFrom('7495794203056835079'))->toBe('7495794203056835079')
        ->and(TiktokShopScraper::sellerIdFrom('https://www.tiktok.com/shop/store/goli-nutrition/7495794203056835079'))->toBe('7495794203056835079')
        ->and(TiktokShopScraper::sellerIdFrom('https://tiktok.com/shop/store/x/7495794203056835079?enter_from=share'))->toBe('7495794203056835079')
        ->and(TiktokShopScraper::sellerIdFrom('tiktok.com/shop/store/goli-nutrition/7495794203056835079/'))->toBe('7495794203056835079')
        ->and(TiktokShopScraper::sellerIdFrom('https://www.tiktok.com/@goli'))->toBeNull()
        ->and(TiktokShopScraper::sellerIdFrom('goli-nutrition'))->toBeNull()
        ->and(TiktokShopScraper::sellerIdFrom(''))->toBeNull();
});

it('lands the recorded storefront in the shop pool through the syncStore catalogue-blob path', function () {
    setupIngestTables();
    [$user, $store] = makeShopStore([
        'provider' => 'tiktok_shop',
        'externalRef' => '7495794203056835079',
        'url' => 'https://www.tiktok.com/shop/store/goli-nutrition/7495794203056835079',
        'sourceUrl' => 'https://www.tiktok.com/shop/store/goli-nutrition/7495794203056835079',
        'currency' => 'USD',
    ]);
    Http::fake(['api.scrapecreators.com/v1/tiktok/shop/products*' => Http::response(ttShopLaneProductsFixture())]);

    $blob = app(TiktokShopScraper::class)->fetchProducts($store->url);
    $written = app(ShopContentWriter::class)->syncStore(
        (string) $store->userId,
        (string) $store->collectionId,
        $blob,
        $store->currency,
    );

    expect($written)->toBe(4)
        // Endpoint order preserved (no createdAt anywhere in the blob), read
        // back off content.f_catalog.sku through the storefront collection.
        ->and(orderedProductIdsFor('7495794203056835079'))->toBe([
            '1729527313880355335',
            '1731194857673101831',
            '1729587769570529799',
            '1729589345444205063',
        ]);

    // The trailing-zero quirk survives the whole lane: "30.8" → 3080 minor.
    $trioItem = DB::table('content.f_catalog')->where('sku', '1731194857673101831')->value('item_id');
    $offer = DB::table('content.offers')->where('item_id', $trioItem)->first();
    expect((int) $offer->amount_minor)->toBe(3080)
        ->and($offer->currency)->toBe('USD');

    // One billed call, on the canonical slug-pinned URL, region pinned US.
    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'url=https%3A%2F%2Fwww.tiktok.com%2Fshop%2Fstore%2Fs%2F7495794203056835079')
        && str_contains($request->url(), 'region=US'));
});

it('treats a billed products husk as broken, never as an empty catalogue, and keeps the slot spent', function () {
    config()->set('partna.limits.scrapecreators.sources.tiktok_shop', 1);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(['success' => true, 'credits_charged' => 1, 'products' => []]),
    ]);
    $scraper = app(TiktokShopScraper::class);

    expect(fn () => $scraper->fetchProducts('7495794203056835079'))->toThrow(HttpException::class);
    // The husk billed upstream, so with cap=1 the retry refuses BEFORE the
    // wire — spent is spent.
    expect(fn () => $scraper->fetchProducts('7495794203056835079'))->toThrow(HttpException::class);
    Http::assertSentCount(1);
});

it('releases the claimed slot on a products transport failure so a later sync may retry', function () {
    config()->set('partna.limits.scrapecreators.sources.tiktok_shop', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);
    $scraper = app(TiktokShopScraper::class);

    expect(fn () => $scraper->fetchProducts('7495794203056835079'))->toThrow(HttpException::class);
    // The slot came back: the second attempt reaches the wire again.
    expect(fn () => $scraper->fetchProducts('7495794203056835079'))->toThrow(HttpException::class);
    Http::assertSentCount(2);
});

it('refuses the shop fetch before spending when the daily cap is exhausted', function () {
    config()->set('partna.limits.scrapecreators.sources.tiktok_shop', 0);
    Http::fake();

    expect(fn () => app(TiktokShopScraper::class)->fetchProducts('7495794203056835079'))
        ->toThrow(HttpException::class);
    Http::assertNothingSent();
});

// ── (b) Reviews driver: products walk, budget mechanics, refusals ───────────

it('walks the top products in vendor order and answers product-stamped review rows', function () {
    Http::fake([
        'api.scrapecreators.com/v1/tiktok/shop/products*' => Http::response(ttShopLaneProductsFixture()),
        'api.scrapecreators.com/v1/tiktok/shop/product/reviews*' => Http::response(ttShopLaneReviewsFixture()),
    ]);

    $result = app(TiktokShopVendorDriver::class)->run(ttShopLaneCtx(['seller_id' => '7495794203056835079']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        // Every reviews call returns the same recorded page — the id-keyed
        // dedupe folds 3×3 vendor rows into 3.
        ->and($result->data)->toHaveCount(3)
        // Rows are stamped with the product they were walked under; the
        // first-seen (best-selling) product wins the dedupe.
        ->and($result->data[0]['product_id'])->toBe('1729527313880355335')
        ->and($result->data[0]['product_title'])->toStartWith('Goli Ashwagandha & Vitamin D Gummy')
        ->and($result->data[0]['product_url'])->toBe('https://www.tiktok.com/shop/pdp/1729527313880355335')
        ->and($result->data[0]['review_id'])->toBe('7505445725870786347')
        ->and($result->data[0]['product_rating'])->toBe(4.5);
    // 1 products call + review_products_per_run (default 3) review calls.
    Http::assertSentCount(4);
    // The EXACT slash path — the hyphen variant 404s live (found 2026-09-02;
    // a wildcard fake would keep answering it and hide the regression).
    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/v1/tiktok/shop/product/reviews?product_id=1729527313880355335'));
});

it('bounds spend at review_products_per_run', function () {
    config()->set('partna.limits.scrapecreators.tiktok_shop.review_products_per_run', 1);
    Http::fake([
        'api.scrapecreators.com/v1/tiktok/shop/products*' => Http::response(ttShopLaneProductsFixture()),
        'api.scrapecreators.com/v1/tiktok/shop/product/reviews*' => Http::response(ttShopLaneReviewsFixture()),
    ]);

    $result = app(TiktokShopVendorDriver::class)->run(ttShopLaneCtx(['seller_id' => '7495794203056835079']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered);
    Http::assertSentCount(2);
});

it('walks past a reviewless product instead of abandoning the run', function () {
    Http::fake([
        'api.scrapecreators.com/v1/tiktok/shop/products*' => Http::response(ttShopLaneProductsFixture()),
        // Every product answers the zero-reviews husk — routine, not rotation.
        'api.scrapecreators.com/v1/tiktok/shop/product/reviews*' => Http::response(['success' => true, 'credits_charged' => 1, 'product_reviews' => []]),
    ]);

    $result = app(TiktokShopVendorDriver::class)->run(ttShopLaneCtx(['seller_id' => '7495794203056835079']));

    // It kept walking (all 3 per-run slots spent), then folded to noAnswer —
    // never answered([]).
    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
    Http::assertSentCount(4);
});

it('refuses to run without a key — there is no fallback lane to fall through to', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake();

    expect(fn () => app(TiktokShopVendorDriver::class)->run(ttShopLaneCtx(['seller_id' => '7495794203056835079'])))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
});

it('refuses before spending when the reviews daily cap is exhausted', function () {
    config()->set('partna.limits.scrapecreators.sources.tiktok_shop', 0);
    Http::fake();

    expect(fn () => app(TiktokShopVendorDriver::class)->run(ttShopLaneCtx(['seller_id' => '7495794203056835079'])))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
});

it('answers noAnswer for an unresolvable seller reference without touching the wire', function () {
    Http::fake();

    $result = app(TiktokShopVendorDriver::class)->run(ttShopLaneCtx(['seller_id' => 'not-a-seller']));

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
    Http::assertNothingSent();
});

// ── (c) Connector: reviews enter the reviews pool like fresha's ─────────────

function ttShopLaneIo(array $effect): Io
{
    return new class($effect) implements Io
    {
        public array $calls = [];

        public function __construct(private array $effect) {}

        public function get(string $url, array $headers = []): array
        {
            throw new RuntimeException('unexpected GET');
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            throw new RuntimeException('unexpected POST');
        }

        public function getMany(array $urls, array $headers = []): array
        {
            throw new RuntimeException('unexpected getMany');
        }

        public function effect(string $kind, string $name, array $input): array
        {
            $this->calls[] = [$kind, $name, $input];

            return $this->effect;
        }
    };
}

function ttShopLanePull(): Pull
{
    return new Pull(identifier: '7495794203056835079', stream: TiktokShopConnector::manifest()->stream('reviews'), config: []);
}

function ttShopLaneReviewRow(string $id, array $extra = []): array
{
    return $extra + [
        'review_id' => $id,
        'rating' => 5.0,
        'text' => 'Love this product!',
        'author' => 'Alessandra',
        'author_photo' => 'https://p19-common-sign.tiktokcdn-us.com/avatar.jpg',
        'publish_time' => '2025-05-17T16:02:53.000Z',
        'verified' => true,
        'variant' => 'Item: 1 Bottle',
        'product_id' => '1729527313880355335',
        'product_title' => 'Goli Ashwagandha & Vitamin D Gummy',
        'product_url' => 'https://www.tiktok.com/shop/pdp/1729527313880355335',
        'product_rating' => 4.5,
        'product_rating_count' => 94616,
    ];
}

it('lands review records off the vendor rows and drops what cannot carry an id and rating', function () {
    $io = ttShopLaneIo(['status' => 'ok', 'cached' => false, 'data' => [
        ttShopLaneReviewRow('7505445725870786347'),
        ttShopLaneReviewRow('7462440092775794475', ['author' => 'E**n', 'author_photo' => null, 'text' => null]),
        ['review_id' => 'not-numeric', 'rating' => 5],
        ['review_id' => '71'],
    ]]);

    $messages = iterator_to_array((new TiktokShopConnector)->pull(ttShopLanePull(), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($io->calls)->toBe([['vendor', 'tiktok_shop', ['seller_id' => '7495794203056835079']]])
        ->and($records)->toHaveCount(2)
        ->and($records[0]->stream)->toBe('reviews')
        ->and($records[0]->key)->toBe('7505445725870786347')
        ->and($records[0]->doc['product_rating'])->toBe(4.5)
        ->and($records[1]->doc['author'])->toBe('E**n')
        // The anonymous-reviewer shape: absent avatar lands as no key at all.
        ->and($records[1]->doc)->not->toHaveKey('author_photo');
});

it('folds a refused effect into Unavailable and an empty answer into a Note', function () {
    $refused = iterator_to_array((new TiktokShopConnector)->pull(ttShopLanePull(), ttShopLaneIo(['status' => 'refused'])), false);
    expect($refused)->toHaveCount(1)->and($refused[0])->toBeInstanceOf(Unavailable::class);

    $empty = iterator_to_array((new TiktokShopConnector)->pull(ttShopLanePull(), ttShopLaneIo(['status' => 'ok', 'cached' => false, 'data' => []])), false);
    expect($empty)->toHaveCount(1)->and($empty[0])->toBeInstanceOf(Note::class);
});

// ── (d) Projector: Fresha's twin, product aggregates as source stats ────────

it('projects a review to the review kind with product aggregates riding as source_stats', function () {
    $item = (new TiktokShopReviewProjector)->project(new RecordView(ttShopLaneReviewRow('7505445725870786347')));

    expect($item['kind'])->toBe('review')
        // NULL by contract — reviewer PII must not fold into headline_cache.
        ->and($item['headline'])->toBeNull()
        ->and($item['facets']['f_review'])->toBe([
            'author_name' => 'Alessandra',
            'author_photo_url' => 'https://p19-common-sign.tiktokcdn-us.com/avatar.jpg',
            'rating' => 5.0,
            'text' => 'Love this product!',
            'reviewed_at' => '2025-05-17T16:02:53.000Z',
        ])
        ->and($item['facets']['f_rated'])->toBe(['rating' => 5.0, 'rating_max' => 5.0])
        ->and($item['facets']['f_published'])->toBe(['published_from' => '2025-05-17T16:02:53.000Z'])
        // product_rating / product_rating_count → the venue-stats seat.
        ->and($item['source_stats'])->toBe(['rating_avg' => 4.5, 'rating_count' => 94616]);
});

it('renders an anonymous or redacted reviewer honestly and omits stats it does not have', function () {
    $doc = ttShopLaneReviewRow('7462440092775794475');
    unset($doc['author'], $doc['author_photo'], $doc['product_rating'], $doc['product_rating_count']);

    $item = (new TiktokShopReviewProjector)->project(new RecordView($doc));

    expect($item['facets']['f_review'])->not->toHaveKey('author_name')
        ->and($item['facets']['f_review'])->not->toHaveKey('author_photo_url')
        ->and($item)->not->toHaveKey('source_stats');
});

it('refuses to project a ratingless row', function () {
    expect((new TiktokShopReviewProjector)->project(new RecordView(['review_id' => '71', 'text' => 'no rating'])))->toBeNull();
});
