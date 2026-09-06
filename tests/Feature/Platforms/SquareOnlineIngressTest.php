<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\MenuSource;
use App\Services\Platforms\StorefrontMarkers;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// A3 + D7 (menu deep-links plan, 2026-08-26): Square Online ingress.
// Custom-domain Square stores have NO host rule by design (#SEC-3 removed
// order.* as an open-redirect fix), so two other mechanisms carry them:
// the paired storefront marker (probe path) and the connection surface_key
// stamp (MenuSource resolution).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();
});

// ── StorefrontMarkers: paired square-online signature ───────────────────────

function sqoHtml(string $cdn, string $runtime): string
{
    return "<html><head><script src=\"https://{$cdn}/app/site.js\"></script></head>"
        ."<body><script>{$runtime}</script></body></html>";
}

it('detects a Square Online storefront on square.site and custom domains alike', function () {
    $runtime = 'window.__BOOTSTRAP_STATE__ = {"storeInfo":{"store_mode":"cart"}};';

    expect(StorefrontMarkers::detect(sqoHtml('cdn5.editmysite.com', $runtime)))->toBe('square-online')
        ->and(StorefrontMarkers::detect(sqoHtml('cdn6.editmysite.com', $runtime)))->toBe('square-online');
});

it('does NOT flag a plain Weebly site that shares the editmysite CDN but has no commerce runtime', function () {
    expect(StorefrontMarkers::detect(sqoHtml('cdn5.editmysite.com', 'var brochure = true;')))->toBeNull();
});

it('keeps the existing shop providers first — a Shopify page is never square-online', function () {
    $html = '<script src="https://cdn.shopify.com/x.js"></script><script src="https://cdn5.editmysite.com/y.js"></script><script>window.__BOOTSTRAP_STATE__={}</script>';

    expect(StorefrontMarkers::detect($html))->toBe('shopify');
});

// ── MenuSource: surface_key stamp beats host re-derivation (D7) ─────────────

function sqoUser(string $handle): User
{
    return User::create([
        'handle' => $handle, 'handle_lc' => strtolower($handle), 'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'business', 'sector' => 'restaurant', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ])->fresh();
}

function sqoConnection(User $user, string $url, string $surfaceKey): IntegrationConnection
{
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => $surfaceKey,
        'resource_id' => $rid,
        'payload' => ['id' => $rid, 'provider' => 'custom', 'url' => $url, 'name' => 'Order', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
}

it('resolves a custom-domain Square store by its surface_key where no host pattern can match', function () {
    $user = sqoUser('sqo1');
    sqoConnection($user, 'https://order.fat-tuna.com/', 'square.order');

    $links = app(MenuSource::class)->storeLinks($user);

    expect($links)->toHaveKey('square')
        ->and($links['square']['storeUrl'])->toBe('https://order.fat-tuna.com/');
});

it('falls back to host-pattern resolution when the surface is not a menu brand', function () {
    // A non-menu ordering surface carrying a menu-platform URL: surfaceSlug()
    // yields nothing, so the host pattern answers — the pre-D7 behaviour,
    // kept as the fallback.
    //
    // A.14 (2026-09-06): order_online.order carries Lifecycle::Retired in the
    // catalog, so IntegrationConnectionGuard now refuses to create one —
    // grubhub.order is a real, active, non-retired ordering surface that
    // MenuSource's scraper registry equally doesn't recognise, so it still
    // exercises this test's "not a menu brand" case.
    $user = sqoUser('sqo2');
    sqoConnection($user, 'https://ischia-restaurant.square.site/', 'grubhub.order');

    $links = app(MenuSource::class)->storeLinks($user);

    expect($links)->toHaveKey('square')
        ->and($links['square']['storeUrl'])->toBe('https://ischia-restaurant.square.site/');
});

it('never resolves a non-menu ordering surface into the scrape plan', function () {
    $user = sqoUser('sqo3');
    // grubhub.order is a real, active ordering surface but NOT a menu-scrape
    // platform. (A.14, 2026-09-06: menulog.order used to stand in for this,
    // but it carries Lifecycle::Retired and can no longer be connected.)
    sqoConnection($user, 'https://www.grubhub.com/restaurants-x', 'grubhub.order');

    expect(app(MenuSource::class)->storeLinks($user))->toBe([]);
});
