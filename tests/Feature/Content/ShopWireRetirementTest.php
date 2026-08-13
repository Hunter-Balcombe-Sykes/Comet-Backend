<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\Queue;

// Slice 5b Task 8 — the retirement guard. Products left the legacy
// /integrations shop keys for `profile.pools.shop` on 2026-08-13, and a
// retirement with no test is one merge away from being undone (the same
// reason LegacyEventsLaneRetiredTest exists for slice 2's events lane).
//
// Two properties matter:
//   1. the shop platform emits NOTHING publicly — an EMPTY payload, not a
//      filtered subset. A re-added allowlist key would republish an owner's
//      whole catalogue on a second, CDN-cached surface;
//   2. the envelope survives, so a consumer iterating `platforms` sees no
//      shape change — only an empty payload.
//
// Plus the presence half: the Shop page is advertised from the POOL now, the
// same question `profile.pools.shop` answers, rather than from a second query
// that has to be kept in lockstep by hand.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    Queue::fake();
});

// setupSitesTable() already provisions site.platform_connections — it is the
// documented alias for exactly that (tests/Pest.php:598). There is no separate
// platform-connections helper; do not add one.
//
// 'partna.storefront' is the shop surface key (App\Catalog\LegacyPlatformMap:97),
// and site.platform_connections.platform is GENERATED from surface_key — so a
// raw poolConnection() insert with that key is a row the shop branch of the
// public endpoint really does see as platform 'shop'.

it('publishes an empty payload for a shop connection but keeps the envelope', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    shopProduct($pro->id, $store, 'Hat');
    poolConnection($pro->id, 'partna.storefront');

    $response = $this->getJson("/api/public/profiles/{$pro->handle}/integrations");

    $response->assertOk();
    $shop = $response->json('data.platforms.shop.0');

    expect($shop)->toHaveKeys(['resourceId', 'payload', 'lastRefreshedAt'])
        ->and($shop['payload'])->toBe([]);
});

it('leaks no product, store or brand field anywhere in the /integrations body', function () {
    // Belt-and-suspenders (the fresha teamMenu idiom): prove the catalogue
    // doesn't ride somewhere ELSE in the response, not merely that the
    // brand-keyed map is gone from `payload`.
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id, ['url' => 'https://leakcheck.example.com', 'discount_code' => 'LEAKCODE10']);
    shopProduct($pro->id, $store, 'Leak Check Hat');
    poolConnection($pro->id, 'partna.storefront');

    $body = $this->getJson("/api/public/profiles/{$pro->handle}/integrations")
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('Leak Check Hat')
        ->and($body)->not->toContain('leakcheck.example.com')
        ->and($body)->not->toContain('LEAKCODE10')
        ->and($body)->not->toContain('A Vendor')      // f_catalog.vendor
        ->and($body)->not->toContain('products')
        ->and($body)->not->toContain('linkMode')
        ->and($body)->not->toContain('popularityRank');
});

it('derives Shop page presence from the pool selection', function () {
    [$pro, $siteId] = poolTenant();
    $site = Site::query()->findOrFail($siteId);
    $store = shopStore($pro->id);
    shopProduct($pro->id, $store, 'Hat');

    $this->artisan('content:provision-shop-pins')->assertSuccessful();

    expect(app(PoolResolver::class)->hasSelection($site, 'shop'))->toBeTrue();
});

it('reports no Shop selection for a user with no products', function () {
    [$pro, $siteId] = poolTenant();

    expect(app(PoolResolver::class)->hasSelection(Site::query()->findOrFail($siteId), 'shop'))->toBeFalse();
});
