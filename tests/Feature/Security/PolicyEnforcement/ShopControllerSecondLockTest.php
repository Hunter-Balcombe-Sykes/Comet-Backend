<?php

/**
 * #SEC-10 — defence-in-depth: ShopController::updateBrand/catalog/setProducts/
 * addProduct/removeProduct now call authorizeForUser() in addition to their
 * pre-existing user-scoped lookups (see the finding in
 * audits/sweeps/2026-08-24-unified-actions-security/CONSOLIDATED.md).
 *
 * HONESTY NOTE: every one of these five lookups is already user-scoped
 * ($this->shop->store($user, $id), brandMap($user)), so there is no
 * cross-tenant HTTP request that can reach a real denial — the 404 guard
 * fires first, every time, for any id the current user doesn't own. That is
 * the whole point of "defence in depth": the second lock has no live hole to
 * close today.
 *
 * What CAN be tested, and is the only honest thing to assert, is that the
 * wiring is real: the controller genuinely calls into
 * IntegrationConnectionPolicy for each of the five methods, and genuinely
 * respects a deny. We prove that by forcing the policy method to deny (via a
 * partial mock) and observing the HTTP response flip to 403 — if a future
 * edit deleted the authorizeForUser() call, these tests would go red because
 * the mocked method would never be invoked and the response would revert to
 * its normal 2xx.
 *
 * A companion test pins the OTHER half of the ordering requirement: an
 * unknown/foreign id must still 404 WITHOUT ever consulting the policy (the
 * existence guard runs first — CLAUDE.md's 403-vs-404 standard).
 */

use App\Policies\IntegrationConnectionPolicy;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\GenericShopScraper;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

it('#SEC-10: updateBrand honours an IntegrationConnectionPolicy::update deny', function () {
    [$user, $store] = makeShopStore(['externalRef' => 'sec10-updatebrand']);

    $this->partialMock(IntegrationConnectionPolicy::class, function ($mock) {
        $mock->shouldReceive('update')->once()->andReturn(Response::deny('blocked', 403));
    });

    actingAsUser($user)
        ->patchJson("/api/platforms/shop/brands/{$store->externalRef}", ['discountCode' => 'SAVE'])
        ->assertStatus(403);
});

it('#SEC-10: catalog honours an IntegrationConnectionPolicy::update deny', function () {
    [$user, $store] = makeShopStore(['externalRef' => 'sec10-catalog']);

    $this->partialMock(IntegrationConnectionPolicy::class, function ($mock) {
        $mock->shouldReceive('update')->once()->andReturn(Response::deny('blocked', 403));
    });

    actingAsUser($user)
        ->postJson("/api/platforms/shop/brands/{$store->externalRef}/catalog", ['products' => [['productId' => 'p1']]])
        ->assertStatus(403);
});

it('#SEC-10: setProducts honours an IntegrationConnectionPolicy::update deny', function () {
    [$user, $store] = makeShopStore(['externalRef' => 'sec10-setproducts']);

    // Warm the picker cache so the pre-lock read never attempts a live
    // scrape — irrelevant to what's under test here (the authorize call runs
    // inside the lock, after this) and would otherwise need Http::fake().
    Cache::put(CacheKeyGenerator::shopifyBrandCatalog('sec10-setproducts'), [], now()->addMinutes(10));

    $this->partialMock(IntegrationConnectionPolicy::class, function ($mock) {
        $mock->shouldReceive('update')->once()->andReturn(Response::deny('blocked', 403));
    });

    actingAsUser($user)
        ->putJson("/api/platforms/shop/brands/{$store->externalRef}/selection", ['productIds' => []])
        ->assertStatus(403);
});

it('#SEC-10: addProduct honours an IntegrationConnectionPolicy::create deny', function () {
    [$user] = makeShopUser();

    $this->mock(GenericShopScraper::class, fn ($m) => $m->shouldNotReceive('readProductPage'));
    $this->partialMock(IntegrationConnectionPolicy::class, function ($mock) {
        $mock->shouldReceive('create')->once()->andReturn(Response::deny('blocked', 403));
    });

    actingAsUser($user)
        ->postJson('/api/platforms/shop/products', ['url' => 'https://example.com/product/tee'])
        ->assertStatus(403);
});

it('#SEC-10: removeProduct honours an IntegrationConnectionPolicy::update deny', function () {
    [$user] = makeShopUser();

    $this->mock(GenericShopScraper::class, fn ($m) => $m->shouldReceive('readProductPage')
        ->with('https://example.com/only')
        ->andReturn([
            'outcome' => GenericShopScraper::OUTCOME_PRODUCT,
            'product' => ['productId' => 'only', 'title' => 'Only', 'url' => 'https://example.com/only'],
            'storeUrl' => null,
        ]));

    // Seed the individual bucket for real — no policy mock yet, so
    // addProduct's own new authorizeForUser('create', ...) call runs
    // unmocked here and must allow this (it's the real owner).
    actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => 'https://example.com/only'])
        ->assertOk();

    $this->partialMock(IntegrationConnectionPolicy::class, function ($mock) {
        $mock->shouldReceive('update')->once()->andReturn(Response::deny('blocked', 403));
    });

    actingAsUser($user)->deleteJson('/api/platforms/shop/products/only')
        ->assertStatus(403);
});

it('#SEC-10: the 404-on-unknown-id guard runs BEFORE the policy is ever consulted', function () {
    [$user] = makeShopUser();

    // The policy must not even be asked about a brand that was never found —
    // 404 vs 403 (CLAUDE.md: 404 when the resource doesn't exist/belong to
    // the user). shouldNotReceive turns any call into a hard Mockery failure.
    $this->partialMock(IntegrationConnectionPolicy::class, function ($mock) {
        $mock->shouldNotReceive('update');
        $mock->shouldNotReceive('create');
    });

    actingAsUser($user)
        ->patchJson('/api/platforms/shop/brands/does-not-exist', ['discountCode' => 'SAVE'])
        ->assertStatus(404)
        ->assertJsonPath('message', 'Brand not found.');

    actingAsUser($user)
        ->deleteJson('/api/platforms/shop/products/does-not-exist')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Product not found.');
});
