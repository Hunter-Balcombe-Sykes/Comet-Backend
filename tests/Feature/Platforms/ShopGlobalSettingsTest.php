<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\ShopFetch;
use App\Site\Pools\AutoSyncSetting;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// GLOBAL shop link controls (2026-07-08): the per-brand link-mode + auto-latest
// controls collapsed into ONE site-level choice each, stored on site.sites and
// managed via GET/PATCH /platforms/shop/settings. The public payload stamps
// every brand's linkMode from the global (covered in PublicIntegrationAllowlistTest);
// here we cover the settings endpoint itself + the ShopFetch auto-latest gate.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function shopSettingsUser(string $h): User
{
    $user = User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
    // The settings endpoint reads/writes the user's site row; every logged-in
    // user has one. Insert bare — the columns fall back to their code defaults.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $h,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user->fresh();
}

it('GET shop settings returns the direct-to-checkout + auto-latest-on defaults', function () {
    $user = shopSettingsUser('gset1');

    actingAsUser($user)->getJson('/api/platforms/shop/settings')
        ->assertOk()
        ->assertJson(['linkMode' => 'checkout', 'autoLatest' => true]);
});

it('PATCH shop settings persists linkMode + autoLatest to the site row', function () {
    $user = shopSettingsUser('gset2');
    // 2026-08-05: autoLatest persists on the store connection's own
    // display_settings — a store must exist to carry it.
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true,
    ]);

    actingAsUser($user)->patchJson('/api/platforms/shop/settings', [
        'linkMode' => 'product',
        'autoLatest' => false,
    ])
        ->assertOk()
        ->assertJson(['linkMode' => 'product', 'autoLatest' => false]);

    $row = DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->first();
    expect($row->shop_link_mode)->toBe('product');
    // 2026-08-05: auto-latest lives on the store connection's own sparse
    // display_settings now (one toggle grammar) — the site column is gone.
    expect(AutoSyncSetting::isOn((string) $user->id, 'shop'))->toBeFalse();
});

it('PATCH shop settings applies only the field present (partial update)', function () {
    $user = shopSettingsUser('gset3');

    // Only linkMode → autoLatest keeps its default (true).
    actingAsUser($user)->patchJson('/api/platforms/shop/settings', ['linkMode' => 'product'])
        ->assertOk()
        ->assertJson(['linkMode' => 'product', 'autoLatest' => true]);
});

it('PATCH shop settings rejects an unknown link mode', function () {
    $user = shopSettingsUser('gset4');

    actingAsUser($user)->patchJson('/api/platforms/shop/settings', ['linkMode' => 'teleport'])
        ->assertStatus(422);
});

it('PATCH shop settings purges the sitepage edge cache when a shop is connected', function () {
    Bus::fake();
    $user = shopSettingsUser('gset5');
    // A shop connection must exist for the explicit refresh to fire (the marker
    // payload never goes dirty on its own — see ShopController::updateSettings).
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->patchJson('/api/platforms/shop/settings', ['linkMode' => 'checkout'])
        ->assertOk();

    Bus::assertDispatched(CloudflareCachePurgeJob::class);
});

it('the shop link mode + auto-latest columns are aliased under /shopify too', function () {
    $user = shopSettingsUser('gset6');

    actingAsUser($user)->getJson('/api/platforms/shopify/settings')
        ->assertOk()
        ->assertJson(['linkMode' => 'checkout', 'autoLatest' => true]);
});

// ── ShopFetch: the global auto-latest gate drives the scheduled sync ──────────

/** A shop connection + a non-individual brand for the ShopFetch strategy. */
function shopFetchConnection(User $user, bool $autoLatest): IntegrationConnection
{
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
        // 2026-08-05: the auto-latest gate reads the connection's own sparse
        // display_settings (absent = ON) — the site column is gone.
        'display_settings' => $autoLatest ? null : ['auto_sync_latest' => false],
    ]);
    ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'sf-brand', 'provider' => 'shopify',
        // #SEM-1: per-brand selection_mode is 'manual' by DB default and
        // addBrand() never sets it, so it can't distinguish "curated" from
        // "never touched". This brand STILL syncs when the global auto-latest
        // is on because it has no products_curated_at on record — that
        // column, not selection_mode, is what ShopFetch actually reads.
        'url' => 'https://sf.example', 'selection_mode' => 'manual', 'position' => 0,
    ]);

    return $conn;
}

it('ShopFetch syncs every non-individual brand when the global auto-latest is on', function () {
    $user = shopSettingsUser('sfon');
    $conn = shopFetchConnection($user, autoLatest: true);

    $catalog = Mockery::mock(ShopCatalog::class);
    // The manual-mode brand IS synced because the GLOBAL is on.
    $catalog->shouldReceive('syncLatest')->once()->andReturn(3);
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldReceive('refresh')->once();

    $result = (new ShopFetch($catalog, $refresher))->fetch($conn->fresh());
    expect($result)->toBe(['storage' => 'relational']);
});

it('ShopFetch syncs nothing when the global auto-latest is off', function () {
    $user = shopSettingsUser('sfoff');
    $conn = shopFetchConnection($user, autoLatest: false);

    $catalog = Mockery::mock(ShopCatalog::class);
    // Global off → the gate short-circuits before any brand is touched.
    $catalog->shouldNotReceive('syncLatest');
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldNotReceive('refresh');

    expect(fn () => (new ShopFetch($catalog, $refresher))->fetch($conn->fresh()))
        ->toThrow(FetchNotModifiedException::class);
});

it('ShopFetch skips a brand with products_curated_at set even when the global auto-latest is on', function () {
    $user = shopSettingsUser('sfcurated');
    $conn = shopFetchConnection($user, autoLatest: true);
    ShopBrand::where('connection_id', $conn->id)->where('brand_id', 'sf-brand')
        ->update(['products_curated_at' => now()]);

    $catalog = Mockery::mock(ShopCatalog::class);
    // The curated brand is the ONLY brand on this connection — with it
    // excluded, $latestBrands is empty, so syncLatest/refresh must never fire.
    $catalog->shouldNotReceive('syncLatest');
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldNotReceive('refresh');

    expect(fn () => (new ShopFetch($catalog, $refresher))->fetch($conn->fresh()))
        ->toThrow(FetchNotModifiedException::class);
});
