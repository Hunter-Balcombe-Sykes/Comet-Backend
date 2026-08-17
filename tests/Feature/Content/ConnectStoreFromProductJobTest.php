<?php

use App\Jobs\Platforms\ConnectStoreFromProductJob;
use App\Jobs\Platforms\ShopBrandConnectJob;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ShopBrandIdentity;
use App\Services\Platforms\ShopBrandProfiler;
use App\Services\Platforms\ShopProviderDetector;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Site\Pools\AutoSyncSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// The store a product paste connects: pending row, anchor with auto-latest
// OFF, and the catalogue job dispatched — nothing published.

beforeEach(function () {
    Queue::fake();
});

$detected = ['provider' => ShopProviderDetector::PROVIDER_SHOPIFY, 'origin' => 'https://shop.test', 'sourceUrl' => 'https://shop.test', 'page' => null, 'store' => null, 'meta' => null];

it('mints a pending store with auto-latest off and hands off to the catalogue job', function () use ($detected) {
    [$user] = makeShopUser(withSite: true);
    mockShopService(ShopBrandIdentity::class, fn ($m) => $m->shouldReceive('for')->andReturn('shop-test'));
    mockShopService(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('syncCurrencyFor')->andReturn('AUD'));

    (new ConnectStoreFromProductJob((string) $user->id, $detected))->handle(
        app(ShopBrandIdentity::class), app(ShopBrandProfiler::class), app(ShopConnections::class),
        app(ShopContentWriter::class), app(IntegrationConnectionCacheRefresher::class),
    );

    $store = app(ShopConnections::class)->stores($user)->get('shop-test');
    expect($store)->not->toBeNull()
        ->and($store->connectStatus)->toBe('pending')
        ->and($store->url)->toBe('https://shop.test');

    $connection = app(ShopConnections::class)->anchorFor($user, 'shop-test');
    expect(($connection->display_settings ?? [])[AutoSyncSetting::KEY] ?? null)->toBeFalse();

    Queue::assertPushed(ShopBrandConnectJob::class);
    expect(DB::table('site.section_items')->count())->toBe(0);
});

it('does nothing when the store is already connected', function () use ($detected) {
    [$user] = makeShopUser(withSite: true);
    mockShopService(ShopBrandIdentity::class, fn ($m) => $m->shouldReceive('for')->andReturn('shop-test'));
    mockShopService(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('syncCurrencyFor')->andReturn('AUD'));
    $run = fn () => (new ConnectStoreFromProductJob((string) $user->id, $detected))->handle(
        app(ShopBrandIdentity::class), app(ShopBrandProfiler::class), app(ShopConnections::class),
        app(ShopContentWriter::class), app(IntegrationConnectionCacheRefresher::class),
    );
    $run();
    $run();
    Queue::assertPushed(ShopBrandConnectJob::class, 1);
    expect(app(ShopConnections::class)->stores($user)->count())->toBe(1);
});
