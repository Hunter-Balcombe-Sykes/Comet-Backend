<?php

// OBS-2: ShopCatalog::syncLatest() used to swallow HttpException (the
// fetch-failure signal every scraper raises via abort(502)) as a silent
// `return null` — indistinguishable from a genuinely-empty catalog. ShopFetch
// then threw FetchNotModifiedException regardless, which PlatformRefresher
// routes to status='ok' + consecutive_failures=0, erasing the failure signal.
// A permanently-blocked store reported healthy forever.
//
// Fix: syncLatest() now RE-THROWS HttpException (null means ONLY "reachable
// but empty"). ShopFetch classifies synced vs failed brands: any successful
// sync still publishes; an all-failed batch throws FetchUnavailableException
// (routes to status='unavailable' + consecutive_failures++); an all-empty
// batch keeps the original quiet FetchNotModifiedException path.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\BigCartelScraper;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\SquarespaceScraper;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\ShopFetch;
use App\Services\Platforms\WooCommerceScraper;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Task 6 fix round 2: ShopCatalog::syncLatest() and ShopContentWriter::
    // isCurated() both query content.* for real here (ShopCatalog is only
    // mocked away in the two shopCatalogWithShopify() tests below) — the
    // content.* SQLite stand-in schema must be attached or those queries
    // 500 with "no such table".
    setupIngestTables();
    setupContentTables();
});

function shopSyncUser(string $h): User
{
    $user = User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'subdomain' => $h,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $user->fresh();
}

/**
 * A shop connection plus its one content.* store, ready for ShopFetch's
 * synced/failed loop.
 *
 * Re-home Task 7: ShopFetch selects stores through ShopConnections::stores()
 * off content.*, not $connection->shopBrands(), and site.shop_brands is
 * written by nothing — so the fixture lands through the real writer and hands
 * back the connection ShopFetch is actually given. selection_mode is not set
 * because it no longer exists anywhere: #SEM-1's real gate is
 * products_curated_at, which a fresh store leaves null (= "sync me").
 */
function shopSyncStore(User $user, string $brandId, string $url = 'https://sf.example', int $position = 0): IntegrationConnection
{
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => $brandId,
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    app(ShopContentWriter::class)->upsertStore(new StoreRecord(
        externalRef: $brandId,
        provider: 'shopify',
        position: $position,
        url: $url,
    ), (string) $user->id);

    return $conn;
}

// ── ShopFetch: synced vs failed classification ────────────────────────────

it('ShopFetch throws FetchUnavailableException when every brand is unreachable', function () {
    $user = shopSyncUser('ssf1');
    $conn = shopSyncStore($user, 'b1');

    $catalog = Mockery::mock(ShopCatalog::class);
    $catalog->shouldReceive('syncLatest')->once()->andThrow(new HttpException(502, 'blocked'));
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldNotReceive('refresh');

    expect(fn () => (new ShopFetch($catalog, $refresher))->fetch($conn->fresh()))
        ->toThrow(FetchUnavailableException::class);
});

it('ShopFetch throws FetchNotModifiedException when every brand is reachable but empty', function () {
    $user = shopSyncUser('ssf2');
    $conn = shopSyncStore($user, 'b1');

    $catalog = Mockery::mock(ShopCatalog::class);
    $catalog->shouldReceive('syncLatest')->once()->andReturn(null);
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldNotReceive('refresh');

    expect(fn () => (new ShopFetch($catalog, $refresher))->fetch($conn->fresh()))
        ->toThrow(FetchNotModifiedException::class);
});

it('ShopFetch publishes when at least one brand synced despite another failing', function () {
    $user = shopSyncUser('ssf3');
    // Two stores, two anchors (convergence Phase 6: one connection per store).
    // ShopFetch is handed ONE of them and still sees both, because its store
    // read is user-scoped, not connection-scoped.
    $conn = shopSyncStore($user, 'ok-brand', 'https://ok.example', 0);
    shopSyncStore($user, 'blocked-brand', 'https://blocked.example', 1);

    $catalog = Mockery::mock(ShopCatalog::class);
    $catalog->shouldReceive('syncLatest')
        ->twice()
        ->andReturnUsing(fn (StoreRecord $s) => $s->externalRef === 'ok-brand' ? 3 : throw new HttpException(502, 'blocked'));
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldReceive('refresh')->once();

    $result = (new ShopFetch($catalog, $refresher))->fetch($conn->fresh());

    expect($result)->toBe(['storage' => 'relational']);
});

// ── ShopCatalog::syncLatest — HttpException re-throw + logging ───────────

/** A ShopCatalog wired to a mocked ShopifyScraper (the default provider). */
function shopCatalogWithShopify(ShopifyScraper $shopify): ShopCatalog
{
    return new ShopCatalog(
        $shopify,
        Mockery::mock(WooCommerceScraper::class),
        Mockery::mock(SquarespaceScraper::class),
        Mockery::mock(BigCartelScraper::class),
        Mockery::mock(GenericShopScraper::class),
    );
}

it('ShopCatalog::syncLatest re-throws HttpException and logs instead of swallowing it', function () {
    Log::spy();
    $user = shopSyncUser('ssc1');
    shopSyncStore($user, 'blocked-brand');
    // Re-home Task 7: syncLatest() takes the StoreRecord and its owner, not
    // the Eloquent model — so the call site reads the store the same way
    // ShopFetch does.
    $store = app(ShopConnections::class)->store($user, 'blocked-brand');

    $shopify = Mockery::mock(ShopifyScraper::class);
    $shopify->shouldReceive('fetchProducts')->once()->andThrow(new HttpException(502, 'Shopify returned HTTP 502'));

    expect(fn () => shopCatalogWithShopify($shopify)->syncLatest($store, (string) $user->id))
        ->toThrow(HttpException::class);

    // Matched by message + context, not by total warning count: creating the
    // connection above (`shopSyncStore`) also triggers the observer's
    // best-effort cache-purge, which logs its own unrelated warning in this
    // Cloudflare-config-less test env — a pre-existing, out-of-scope noise
    // source, not something this fix introduces.
    //
    // The context keys moved with the model: `brand_id` (the legacy row's
    // uuid PK) is now `collection_id` + `external_ref`, since those are what
    // identify a store once site.shop_brands is gone.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $ctx) => $message === 'shop.sync_latest.unreachable'
            && $ctx['collection_id'] === $store->collectionId
            && $ctx['external_ref'] === 'blocked-brand'
            && $ctx['url'] === $store->url)
        ->once();
});

it('ShopCatalog::syncLatest returns null (not an error) for a reachable but genuinely empty catalog', function () {
    $user = shopSyncUser('ssc2');
    shopSyncStore($user, 'empty-brand');
    $store = app(ShopConnections::class)->store($user, 'empty-brand');

    $shopify = Mockery::mock(ShopifyScraper::class);
    $shopify->shouldReceive('fetchProducts')->once()->andReturn([]);

    expect(shopCatalogWithShopify($shopify)->syncLatest($store, (string) $user->id))->toBeNull();
});

// ── End-to-end: the circuit breaker must NOT reset on a blocked store ─────

it('a persistently-blocked shop trips the circuit breaker instead of resetting it', function () {
    $user = shopSyncUser('ssce1');
    $conn = shopSyncStore($user, 'blocked-brand');

    // Bind a ShopCatalog mock so the registry-resolved ShopFetch (constructed
    // via app(ShopCatalog::class) — see PlatformRegistryServiceProvider)
    // picks it up without a real network fetch.
    $catalog = Mockery::mock(ShopCatalog::class);
    $catalog->shouldReceive('syncLatest')->once()->andThrow(new HttpException(502, 'blocked'));
    app()->instance(ShopCatalog::class, $catalog);

    app(PlatformRefresher::class)->refresh($conn->fresh());

    $conn->refresh();
    // Before the fix: FetchNotModifiedException → recordNotModified() →
    // status='ok', consecutive_failures reset to 0 — a permanently-blocked
    // store reporting healthy forever. Now: FetchUnavailableException →
    // recordFailure() → status='unavailable', consecutive_failures++.
    expect($conn->last_refresh_status)->toBe('unavailable')
        ->and($conn->consecutive_failures)->toBe(1);
});
