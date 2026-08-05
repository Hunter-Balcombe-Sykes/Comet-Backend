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
use App\Models\Core\Site\ShopBrand;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
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

/** A shop connection with one brand, ready for ShopFetch's synced/failed loop. */
function shopSyncBrand(User $user, string $brandId): ShopBrand
{
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    return ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => $brandId, 'provider' => 'shopify',
        'url' => 'https://sf.example', 'selection_mode' => 'latest', 'position' => 0,
    ]);
}

// ── ShopFetch: synced vs failed classification ────────────────────────────

it('ShopFetch throws FetchUnavailableException when every brand is unreachable', function () {
    $user = shopSyncUser('ssf1');
    $brand = shopSyncBrand($user, 'b1');

    $catalog = Mockery::mock(ShopCatalog::class);
    $catalog->shouldReceive('syncLatest')->once()->andThrow(new HttpException(502, 'blocked'));
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldNotReceive('refresh');

    expect(fn () => (new ShopFetch($catalog, $refresher))->fetch($brand->connection->fresh()))
        ->toThrow(FetchUnavailableException::class);
});

it('ShopFetch throws FetchNotModifiedException when every brand is reachable but empty', function () {
    $user = shopSyncUser('ssf2');
    $brand = shopSyncBrand($user, 'b1');

    $catalog = Mockery::mock(ShopCatalog::class);
    $catalog->shouldReceive('syncLatest')->once()->andReturn(null);
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldNotReceive('refresh');

    expect(fn () => (new ShopFetch($catalog, $refresher))->fetch($brand->connection->fresh()))
        ->toThrow(FetchNotModifiedException::class);
});

it('ShopFetch publishes when at least one brand synced despite another failing', function () {
    $user = shopSyncUser('ssf3');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'ok-brand', 'provider' => 'shopify',
        'url' => 'https://ok.example', 'selection_mode' => 'latest', 'position' => 0,
    ]);
    ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'blocked-brand', 'provider' => 'shopify',
        'url' => 'https://blocked.example', 'selection_mode' => 'latest', 'position' => 1,
    ]);

    $catalog = Mockery::mock(ShopCatalog::class);
    $catalog->shouldReceive('syncLatest')
        ->twice()
        ->andReturnUsing(fn (ShopBrand $b) => $b->brand_id === 'ok-brand' ? 3 : throw new HttpException(502, 'blocked'));
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
    $brand = shopSyncBrand($user, 'blocked-brand');

    $shopify = Mockery::mock(ShopifyScraper::class);
    $shopify->shouldReceive('fetchProducts')->once()->andThrow(new HttpException(502, 'Shopify returned HTTP 502'));

    expect(fn () => shopCatalogWithShopify($shopify)->syncLatest($brand))
        ->toThrow(HttpException::class);

    // Matched by message + context, not by total warning count: creating the
    // connection above (`shopSyncBrand`) also triggers the observer's
    // best-effort cache-purge, which logs its own unrelated warning in this
    // Cloudflare-config-less test env — a pre-existing, out-of-scope noise
    // source, not something this fix introduces.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $ctx) => $message === 'shop.sync_latest.unreachable'
            && $ctx['brand_id'] === $brand->id && $ctx['url'] === $brand->url)
        ->once();
});

it('ShopCatalog::syncLatest returns null (not an error) for a reachable but genuinely empty catalog', function () {
    $user = shopSyncUser('ssc2');
    $brand = shopSyncBrand($user, 'empty-brand');

    $shopify = Mockery::mock(ShopifyScraper::class);
    $shopify->shouldReceive('fetchProducts')->once()->andReturn([]);

    expect(shopCatalogWithShopify($shopify)->syncLatest($brand))->toBeNull();
});

// ── End-to-end: the circuit breaker must NOT reset on a blocked store ─────

it('a persistently-blocked shop trips the circuit breaker instead of resetting it', function () {
    $user = shopSyncUser('ssce1');
    $brand = shopSyncBrand($user, 'blocked-brand');
    $conn = $brand->connection;

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
