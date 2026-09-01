<?php

use App\Jobs\Platforms\AmazonShopConnectJob;
use App\Models\Core\User\User;
use App\Services\Platforms\AmazonShopScraper;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ScrapeCreators\AmazonShopNormalizer;
use App\Services\Shop\ShopAutoSelector;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Site\Pools\AutoSyncSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Fixtures\Recorded;

// Item 10b (2026-09-01): the Amazon influencer-storefront lane END TO END —
// AmazonShopConnectJob → AmazonShopScraper (budget + /v1/amazon/shop) →
// AmazonShopNormalizer → ShopContentWriter::syncStore under a StoreRecord —
// against the RECORDED live payload the adapter tests already pin
// (tests/fixtures/recorded/scrapecreators-amazon-shop.json, sydneydelrey).
// Two properties rule every test:
//
//  1. A usable vendor answer lands as an ordinary shop-pool store: provider
//     'amazon-shop', external_ref = the storefront handle, currency USD at
//     the STORE row (the payload carries none), picks as content.items.
//  2. Any other answer — transport, husk, budget refusal — is a NO-OP:
//     nothing minted, and an already-connected store keeps its catalogue.

function scAzConnectFixture(): array
{
    return Recorded::json('scrapecreators-amazon-shop.json');
}

function scAzConnectRun(User $user, string $url = 'https://www.amazon.com/shop/sydneydelrey'): void
{
    (new AmazonShopConnectJob((string) $user->id, $url))->handle(
        app(AmazonShopScraper::class),
        app(AmazonShopNormalizer::class),
        app(ShopConnections::class),
        app(ShopContentWriter::class),
        app(ShopAutoSelector::class),
        app(IntegrationConnectionCacheRefresher::class),
    );
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.amazon', 50);
});

it('connects the storefront end-to-end off the recorded payload', function () {
    [$user] = makeShopUser(withSite: true);
    Http::fake(['api.scrapecreators.com/v1/amazon/shop*' => Http::response(scAzConnectFixture())]);

    scAzConnectRun($user, 'https://www.amazon.com/shop/sydneydelrey?ref_=cm_sw_r_apin_aipsf');

    // The vendor was asked for the CANONICAL url — the paste's ref junk
    // never reaches the wire (or the store row).
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.scrapecreators.com/v1/amazon/shop?')
        && $request['url'] === 'https://www.amazon.com/shop/sydneydelrey');
    Http::assertSentCount(1);

    $store = app(ShopConnections::class)->stores($user)->get('sydneydelrey');
    expect($store)->not->toBeNull()
        ->and($store->provider)->toBe('amazon-shop')
        // amazon.com implies USD at the store row — the payload has no
        // currency field, so the mint owns the decision.
        ->and($store->currency)->toBe('USD')
        ->and($store->url)->toBe('https://www.amazon.com/shop/sydneydelrey')
        ->and($store->name)->toBe('sydney del rey')
        ->and($store->logoUrl)->toStartWith('https://m.media-amazon.com/')
        // Minted settled: the catalogue was in hand, nothing was deferred.
        ->and($store->connectStatus)->toBeNull()
        ->and($store->isIndividual)->toBeFalse();

    // All 16 recorded picks landed, linked in storefront order.
    $itemIds = DB::table('content.collection_items')
        ->where('collection_id', $store->collectionId)->orderBy('position')->pluck('item_id');
    expect($itemIds)->toHaveCount(16);

    // Spot-check the first pick through the pool's own tables: affiliate URL
    // verbatim, ASIN as sku, price in minor units under the STORE currency.
    $first = (string) $itemIds->first();
    expect(DB::table('content.f_link')->where('item_id', $first)->value('url'))
        ->toBe('https://www.amazon.com/shop/sydneydelrey/getProductDetails/B0H87Z7TSV?showRelatedPost=true&tag_override=sydneybertonc-20')
        ->and(DB::table('content.f_catalog')->where('item_id', $first)->value('sku'))->toBe('B0H87Z7TSV');
    $offer = DB::table('content.offers')->where('item_id', $first)->first();
    expect((int) $offer->amount_minor)->toBe(2798)
        ->and($offer->currency)->toBe('USD');

    // The anchor exists with auto-latest OFF (ShopConnections::anchor's own
    // mint rule) — nothing schedules this store into ShopFetch's latest loop.
    $connection = app(ShopConnections::class)->anchorFor($user, 'sydneydelrey');
    expect($connection)->not->toBeNull()
        ->and(($connection->display_settings ?? [])[AutoSyncSetting::KEY] ?? null)->toBeFalse();
});

it('derives the handle only from amazon.com storefront urls', function () {
    expect(AmazonShopScraper::handleFromUrl('https://www.amazon.com/shop/sydneydelrey?ref=x'))->toBe('sydneydelrey')
        ->and(AmazonShopScraper::handleFromUrl('amazon.com/shop/SydneyDelRey/lists/abc'))->toBe('sydneydelrey')
        // Another marketplace's storefront would make USD a guess — refused.
        ->and(AmazonShopScraper::handleFromUrl('https://www.amazon.co.uk/shop/someone'))->toBeNull()
        ->and(AmazonShopScraper::handleFromUrl('https://www.amazon.com/dp/B0H87Z7TSV'))->toBeNull()
        ->and(AmazonShopScraper::handleFromUrl('https://evil.test/shop/someone'))->toBeNull()
        ->and(AmazonShopScraper::handleFromUrl(''))->toBeNull();
});

it('treats a transport miss as a no-op and releases the budget slot', function () {
    [$user] = makeShopUser(withSite: true);
    config()->set('partna.limits.scrapecreators.sources.amazon', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    scAzConnectRun($user);
    scAzConnectRun($user);

    // The slot came back: with cap=1 an unreleased claim would refuse the
    // second run before the wire — instead both attempts reach it.
    Http::assertSentCount(2);
    expect(app(ShopConnections::class)->stores($user))->toHaveCount(0)
        ->and(DB::table('content.collections')->count())->toBe(0)
        ->and(DB::table('content.items')->count())->toBe(0);
});

it('keeps the slot spent on a billed husk and still writes nothing', function () {
    [$user] = makeShopUser(withSite: true);
    config()->set('partna.limits.scrapecreators.sources.amazon', 1);
    // The NotFound quirk: billed, success-shaped, no storefront inside.
    Http::fake(['api.scrapecreators.com/*' => Http::response(['success' => true, 'credits_charged' => 1])]);

    scAzConnectRun($user);
    scAzConnectRun($user);

    // One wire call only — the husk was billed, so the slot stays spent and
    // the second run is refused at the budget, before the wire.
    Http::assertSentCount(1);
    expect(app(ShopConnections::class)->stores($user))->toHaveCount(0)
        ->and(DB::table('content.collections')->count())->toBe(0);
});

it('never empties an already-connected catalogue on a refresh miss', function () {
    [$user] = makeShopUser(withSite: true);
    Http::fake(['api.scrapecreators.com/v1/amazon/shop*' => Http::response(scAzConnectFixture())]);
    scAzConnectRun($user);
    $store = app(ShopConnections::class)->stores($user)->get('sydneydelrey');
    expect(DB::table('content.collection_items')->where('collection_id', $store->collectionId)->count())->toBe(16);

    // The refresh answers a husk — syncStore must not run at all: an empty
    // input would retire every product this store carries.
    Http::fake(['api.scrapecreators.com/*' => Http::response(['success' => true, 'credits_charged' => 1])]);
    scAzConnectRun($user);

    expect(DB::table('content.collection_items')->where('collection_id', $store->collectionId)->count())->toBe(16)
        ->and(DB::table('content.items')->whereNotNull('removed_at')->count())->toBe(0)
        ->and(app(ShopConnections::class)->stores($user)->get('sydneydelrey')->currency)->toBe('USD');
});

it('folds a refresh onto the existing record instead of blanking user edits', function () {
    [$user] = makeShopUser(withSite: true);
    Http::fake(['api.scrapecreators.com/v1/amazon/shop*' => Http::response(scAzConnectFixture())]);
    scAzConnectRun($user);

    // The owner renames the store and corrects its currency after connect.
    $store = app(ShopConnections::class)->stores($user)->get('sydneydelrey');
    DB::table('content.collections')->where('id', $store->collectionId)->update(['label' => 'My Amazon Picks']);
    DB::table('content.storefronts')->where('collection_id', $store->collectionId)->update(['currency' => 'AUD']);

    scAzConnectRun($user);

    $after = app(ShopConnections::class)->stores($user)->get('sydneydelrey');
    expect($after->name)->toBe('My Amazon Picks')
        ->and($after->currency)->toBe('AUD')
        // Still ONE store — the refresh reused the collection, never minted.
        ->and(app(ShopConnections::class)->stores($user))->toHaveCount(1);
});

it('refuses a new store at the cap before spending a credit', function () {
    [$user] = makeShopUser(withSite: true);
    addShopStore($user);
    config()->set('partna.shop_brands_max', 1);
    Http::fake();

    scAzConnectRun($user);

    Http::assertNothingSent();
    expect(app(ShopConnections::class)->stores($user)->get('sydneydelrey'))->toBeNull();
});

it('refuses before spending when the staff kill switch disables integration.shop', function () {
    [$user] = makeShopUser(withSite: true);
    setupFeatureAvailabilityTable();
    DB::connection('pgsql')->table('core.feature_availability')->insert([
        'id' => (string) Str::uuid(),
        'feature_key' => 'integration.shop',
        'mode' => 'disabled',
        'segment_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Http::fake();

    scAzConnectRun($user);

    Http::assertNothingSent();
    expect(app(ShopConnections::class)->stores($user))->toHaveCount(0);
});
