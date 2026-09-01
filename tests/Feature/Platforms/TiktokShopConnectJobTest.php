<?php

use App\Jobs\Ingest\RunSourceJob;
use App\Jobs\Platforms\AmazonShopConnectJob;
use App\Jobs\Platforms\TiktokShopConnectJob;
use App\Models\Core\User\User;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\TiktokShopScraper;
use App\Services\Shop\ShopAutoSelector;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures\Recorded;

// Item 10b (2026-09-01): the TikTok Shop CONNECT lane end to end —
// TiktokShopConnectJob → TiktokShopScraper::storefront (one billed call
// answering identity + catalogue) → syncStore under a StoreRecord — against
// the recorded Goli payload the adapter tests pin. AmazonShopConnectJobTest
// is the structural twin and carries the shared-lane cases (husk/slot
// mechanics, refresh folding, kill switch) — what's covered HERE is what is
// DIFFERENT about tiktok_shop: the seller-id identity, the one-call shape,
// the dedicated anchor surface, and the reviews-source provisioning that
// minting the anchor arms.

function scTtShopConnectFixture(): array
{
    return Recorded::json('scrapecreators-tiktok-shop-products.json');
}

function scTtShopConnectRun(User $user, string $url): void
{
    (new TiktokShopConnectJob((string) $user->id, $url))->handle(
        app(TiktokShopScraper::class),
        app(ShopConnections::class),
        app(ShopContentWriter::class),
        app(ShopAutoSelector::class),
        app(IntegrationConnectionCacheRefresher::class),
    );
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.tiktok_shop', 50);
    // The anchor mint provisions the tiktok_shop ingest source through the
    // real observer — the connector's eager run must stay queued, not inline.
    setupIngestTables();
    Bus::fake([RunSourceJob::class]);
});

it('connects the storefront end-to-end and arms the reviews lane off the anchor', function () {
    [$user] = makeShopUser(withSite: true);
    Http::fake(['api.scrapecreators.com/v1/tiktok/shop/products*' => Http::response(scTtShopConnectFixture())]);

    scTtShopConnectRun($user, 'https://www.tiktok.com/shop/store/goli-nutrition/7495794203056835079?enter_from=share');

    // One billed call, on the canonical slug-pinned URL — the paste's slug
    // and query junk never reach the wire.
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'url=https%3A%2F%2Fwww.tiktok.com%2Fshop%2Fstore%2Fs%2F7495794203056835079'));

    $store = app(ShopConnections::class)->stores($user)->get('7495794203056835079');
    expect($store)->not->toBeNull()
        ->and($store->provider)->toBe('tiktok_shop')
        ->and($store->currency)->toBe('USD')
        // Canonical id URL, never the vendor's slug echo.
        ->and($store->url)->toBe('https://www.tiktok.com/shop/store/s/7495794203056835079')
        ->and($store->name)->toBe('Goli Nutrition')
        ->and($store->connectStatus)->toBeNull()
        ->and($store->isIndividual)->toBeFalse();

    // All 4 recorded products landed through the ordinary catalogue path.
    expect(DB::table('content.collection_items')->where('collection_id', $store->collectionId)->count())->toBe(4);

    // The anchor sits on its own surface — and minting it provisioned the
    // reviews ingest source with the SAME seller-id identity (the whole
    // reason the surface row exists).
    $anchor = $user->integrationConnections()->where('surface_key', 'tiktok_shop.store')->first();
    expect($anchor)->not->toBeNull()
        ->and($anchor->resource_id)->toBe('7495794203056835079');
    $source = DB::table('ingest.sources')
        ->where('connection_id', $anchor->id)->where('source_key', 'tiktok_shop')->first();
    expect($source)->not->toBeNull()
        ->and($source->identifier)->toBe('7495794203056835079');
});

it('treats a transport miss as a no-op and releases the budget slot', function () {
    [$user] = makeShopUser(withSite: true);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    scTtShopConnectRun($user, '7495794203056835079');
    expect(app(ShopConnections::class)->stores($user))->toHaveCount(0)
        ->and($user->integrationConnections()->where('surface_key', 'tiktok_shop.store')->count())->toBe(0);

    // The slot came back: a retry reaches the wire again.
    scTtShopConnectRun($user, '7495794203056835079');
    Http::assertSentCount(2);
});

it('refuses an unrecognized reference before spending anything', function () {
    [$user] = makeShopUser(withSite: true);
    Http::fake();

    scTtShopConnectRun($user, 'https://www.tiktok.com/@goli');

    Http::assertNothingSent();
    expect(app(ShopConnections::class)->stores($user))->toHaveCount(0);
});

it('refuses a new store at the cap before spending a credit', function () {
    [$user] = makeShopUser(withSite: true);
    config()->set('partna.shop_brands_max', 1);
    addShopStore($user, ['provider' => 'shopify', 'externalRef' => 'occupied']);
    Http::fake();

    scTtShopConnectRun($user, '7495794203056835079');

    Http::assertNothingSent();
    expect(app(ShopConnections::class)->stores($user)->get('7495794203056835079'))->toBeNull();
});

// ── The dedicated connect endpoints (Item 10b routes) ───────────────────────

it('202s the tiktok-shop connect route and dispatches the job', function () {
    [$user] = makeShopUser(withSite: true);
    Bus::fake([TiktokShopConnectJob::class]);

    $response = actingAsUser($user)->postJson('/api/platforms/shop/tiktok-shop/connect', [
        'url' => 'https://www.tiktok.com/shop/store/goli-nutrition/7495794203056835079',
    ]);

    $response->assertStatus(202)->assertJsonPath('id', '7495794203056835079')->assertJsonPath('status', 'pending');
    Bus::assertDispatched(TiktokShopConnectJob::class, fn (TiktokShopConnectJob $job) => $job->userId === (string) $user->id);
});

it('422s the tiktok-shop route for a non-storefront url without dispatching', function () {
    [$user] = makeShopUser(withSite: true);
    Bus::fake([TiktokShopConnectJob::class]);

    actingAsUser($user)->postJson('/api/platforms/shop/tiktok-shop/connect', ['url' => 'https://www.tiktok.com/@goli'])
        ->assertStatus(422);
    Bus::assertNotDispatched(TiktokShopConnectJob::class);
});

it('202s the amazon-shop connect route and dispatches its job', function () {
    [$user] = makeShopUser(withSite: true);
    Bus::fake([AmazonShopConnectJob::class]);

    actingAsUser($user)->postJson('/api/platforms/shop/amazon-shop/connect', [
        'url' => 'https://www.amazon.com/shop/sydneydelrey?ref_=junk',
    ])->assertStatus(202)->assertJsonPath('id', 'sydneydelrey')->assertJsonPath('status', 'pending');
    Bus::assertDispatched(AmazonShopConnectJob::class);
});

it('422s the amazon-shop route for a non-storefront url without dispatching', function () {
    [$user] = makeShopUser(withSite: true);
    Bus::fake([AmazonShopConnectJob::class]);

    actingAsUser($user)->postJson('/api/platforms/shop/amazon-shop/connect', ['url' => 'https://www.amazon.com/dp/B0EXAMPLE'])
        ->assertStatus(422);
    Bus::assertNotDispatched(AmazonShopConnectJob::class);
});

it('422s at the cap on both routes before any dispatch', function () {
    [$user] = makeShopUser(withSite: true);
    config()->set('partna.shop_brands_max', 1);
    addShopStore($user, ['provider' => 'shopify', 'externalRef' => 'occupied']);
    Bus::fake([TiktokShopConnectJob::class, AmazonShopConnectJob::class]);

    actingAsUser($user)->postJson('/api/platforms/shop/tiktok-shop/connect', ['url' => '7495794203056835079'])
        ->assertStatus(422);
    actingAsUser($user)->postJson('/api/platforms/shop/amazon-shop/connect', ['url' => 'https://www.amazon.com/shop/sydneydelrey'])
        ->assertStatus(422);
    Bus::assertNotDispatched(TiktokShopConnectJob::class);
    Bus::assertNotDispatched(AmazonShopConnectJob::class);
});
