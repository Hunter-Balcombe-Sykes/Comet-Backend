<?php

use App\Catalog\LegacyPlatformMap;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Policies\IntegrationConnectionPolicy;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\Strategies\Fetch\GoogleBusinessFetch;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function displaySeedConnection(string $userId, array $payload, string $platform = 'google-business', ?array $displaySettings = null): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => $id,
        'user_id' => $userId,
        'surface_key' => LegacyPlatformMap::surfaceFor($platform),
        'routing_class' => LegacyPlatformMap::routingClassFor(LegacyPlatformMap::surfaceFor($platform)),
        'resource_id' => 'res-'.Str::random(6),
        'payload' => json_encode($payload),
        'display_settings' => $displaySettings !== null ? json_encode($displaySettings) : null,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

it('returns every declared toggle defaulting ON', function () {
    $pro = createTenant('toggles-defaults');
    displaySeedConnection($pro->id, ['name' => 'Cafe']);

    $response = actingAsUser($pro)->getJson('/api/platforms/google-business/display-settings');

    $response->assertOk();
    $toggles = collect($response->json('toggles'));
    expect($toggles->pluck('key')->all())->toBe(['reviews', 'hours', 'photos', 'location', 'menu'])
        ->and($toggles->every(fn ($t) => $t['enabled'] === true))->toBeTrue();
});

it('exposes the events auto_sync_latest toggle on both tickets platforms, defaulting ON', function () {
    $pro = createTenant('toggles-events');
    displaySeedConnection($pro->id, ['url' => 'https://www.eventbrite.com/o/acme-1', 'organiser' => 'Acme'], 'eventbrite');
    displaySeedConnection($pro->id, ['url' => 'https://events.humanitix.com/host/acme', 'organiser' => 'Acme'], 'humanitix');

    foreach (['eventbrite', 'humanitix'] as $platform) {
        $toggles = actingAsUser($pro)->getJson("/api/platforms/{$platform}/display-settings")
            ->assertOk()
            ->json('toggles');
        expect(array_column($toggles, 'key'))->toBe(['auto_sync_latest'])
            ->and($toggles[0]['enabled'])->toBeTrue();
    }

    // Flipping OFF persists sparsely (deviation-only) so the fetch strategies
    // can read it straight off the connection row.
    $rowId = IntegrationConnection::query()->where('user_id', $pro->id)->where('platform', 'eventbrite')->value('id');
    actingAsUser($pro)
        ->patchJson('/api/platforms/eventbrite/display-settings', ['toggles' => ['auto_sync_latest' => false]])
        ->assertOk()
        ->assertJsonPath('toggles.0.enabled', false);
    expect(IntegrationConnection::query()->find($rowId)->display_settings)->toBe(['auto_sync_latest' => false]);
});

it('exposes bandcamp settings as the one auto-sync toggle (show_all_releases left with Featured)', function () {
    $pro = createTenant('toggles-bandcamp');
    $id = displaySeedConnection($pro->id, ['url' => 'https://artist.bandcamp.com', 'artist' => 'Artist'], 'bandcamp');

    // Which releases appear is the Listen pool's selection now — the only
    // bandcamp toggle is auto_sync_latest, default ON.
    $toggles = collect(actingAsUser($pro)->getJson('/api/platforms/bandcamp/display-settings')->assertOk()->json('toggles'));
    expect($toggles->pluck('key')->all())->toBe(['auto_sync_latest']);
    expect($toggles->firstWhere('key', 'auto_sync_latest')['enabled'])->toBeTrue();

    // Flipping it off stores the deviation; back on removes the key (sparse).
    actingAsUser($pro)
        ->patchJson('/api/platforms/bandcamp/display-settings', ['toggles' => ['auto_sync_latest' => false]])
        ->assertOk()
        ->assertJsonPath('toggles.0.enabled', false);
    expect(IntegrationConnection::query()->find($id)->display_settings)->toBe(['auto_sync_latest' => false]);

    actingAsUser($pro)
        ->patchJson('/api/platforms/bandcamp/display-settings', ['toggles' => ['auto_sync_latest' => true]])
        ->assertOk()
        ->assertJsonPath('toggles.0.enabled', true);
    expect(IntegrationConnection::query()->find($id)->display_settings)->toBeNull();
});

it('exposes BOTH listen switches on spotify (releases + tracks) and apple-music, one per format arm (F27)', function () {
    // Spotify sources releases (discography actor) as well as tracks; with
    // only the track key declared its release arm could never be switched
    // off — "Newest release" stayed on the site with Apple's and Bandcamp's
    // release switches both off (session 3, Men I Trust).
    $pro = createTenant('toggles-spotify');
    displaySeedConnection($pro->id, ['url' => 'https://open.spotify.com/artist/abc', 'name' => 'Artist'], 'spotify');
    $keys = collect(actingAsUser($pro)->getJson('/api/platforms/spotify/display-settings')->assertOk()->json('toggles'))->pluck('key')->all();
    expect($keys)->toBe(['auto_sync_latest', 'auto_sync_latest_track']);

    $apple = createTenant('toggles-apple');
    displaySeedConnection($apple->id, ['input' => 'https://music.apple.com/us/artist/x/1', 'name' => 'Artist'], 'apple-music');
    $keys = collect(actingAsUser($apple)->getJson('/api/platforms/apple-music/display-settings')->assertOk()->json('toggles'))->pluck('key')->all();
    expect($keys)->toBe(['auto_sync_latest', 'auto_sync_latest_track']);
});

it('persists a toggle flip sparsely and reports it disabled', function () {
    $pro = createTenant('toggles-flip');
    $id = displaySeedConnection($pro->id, ['name' => 'Cafe']);

    actingAsUser($pro)
        ->patchJson('/api/platforms/google-business/display-settings', [
            'toggles' => ['reviews' => false],
        ])
        ->assertOk()
        ->assertJsonPath('toggles.0.enabled', false);

    $stored = IntegrationConnection::query()->find($id)->display_settings;
    expect($stored)->toBe(['reviews' => false]);

    // Re-enabling removes the key entirely (sparse deviations only).
    actingAsUser($pro)
        ->patchJson('/api/platforms/google-business/display-settings', [
            'toggles' => ['reviews' => true],
        ])
        ->assertOk();

    expect(IntegrationConnection::query()->find($id)->display_settings)->toBeNull();
});

// ── SEC-107: authorize every write up front, before any save ──────────────
// update() authorizes every connection BEFORE the first save, so a denial can
// never leave a half-applied write across a multi-connection platform. The
// site-column half of the original test died with the siteColumn bridge
// (2026-08-05 — instagram is a plain connection toggle now); the atomicity
// property still holds and is proven against the connection rows themselves.

it('does not persist any connection write when the authorize gate denies (atomicity)', function () {
    $pro = createTenant('toggles-atomic');
    displaySeedConnection($pro->id, ['username' => 'artist'], 'instagram');

    $before = DB::table('site.platform_connections')
        ->where('user_id', $pro->id)->where('platform', 'instagram')
        ->value('display_settings');

    $this->app->bind(IntegrationConnectionPolicy::class, fn () => new class extends IntegrationConnectionPolicy
    {
        public function update(User $actor, Model $resource): bool|Response
        {
            return Response::denyAsNotFound();
        }
    });

    actingAsUser($pro)
        ->patchJson('/api/platforms/instagram/display-settings', ['toggles' => ['auto_sync_latest' => false]])
        ->assertStatus(404);

    expect(DB::table('site.platform_connections')
        ->where('user_id', $pro->id)->where('platform', 'instagram')
        ->value('display_settings'))->toEqual($before);
});

it('rejects unknown toggle keys and untoggleable platforms', function () {
    $pro = createTenant('toggles-unknown');
    displaySeedConnection($pro->id, ['name' => 'Cafe']);

    actingAsUser($pro)
        ->patchJson('/api/platforms/google-business/display-settings', [
            'toggles' => ['nonsense' => false],
        ])
        ->assertStatus(422);

    // spotify gained auto_sync_latest on 2026-08-18 (it sources tracks into
    // the listen pool); a platform with genuinely no toggles is the fixture.
    actingAsUser($pro)
        ->getJson('/api/platforms/x/display-settings')
        ->assertStatus(404);
});

it('reports a toggle ON when ANY live account of the platform has it on, matching AutoSyncSetting::isOn (W2)', function () {
    $pro = createTenant('toggles-multi');
    displaySeedConnection($pro->id, ['name' => 'Cafe']);
    // Two youtube accounts: one explicitly off, one absent (= on).
    displaySeedConnection($pro->id, ['handle' => 'c0'], 'youtube', ['auto_sync_latest' => false]);
    displaySeedConnection($pro->id, ['handle' => 'c1'], 'youtube', null);
    actingAsUser($pro)->getJson('/api/platforms/youtube/display-settings')
        ->assertOk()->assertJsonPath('toggles.0.enabled', true);
    // Now both off → OFF.
    DB::table('site.platform_connections')->where('user_id', $pro->id)->where('surface_key', 'youtube.channel')
        ->update(['display_settings' => json_encode(['auto_sync_latest' => false])]);
    actingAsUser($pro)->getJson('/api/platforms/youtube/display-settings')
        ->assertOk()->assertJsonPath('toggles.0.enabled', false);
});

it('suppresses toggled-off sections from the public integrations payload', function () {
    $pro = createTenant('toggles-public');
    displaySeedConnection($pro->id, [
        'name' => 'Cafe',
        'rating' => 4.5,
        'reviewCount' => 12,
        'reviews' => [['author' => 'A', 'text' => 'Great']],
        'hours' => ['weekdays' => ['Monday: 9–5']],
        'photos' => ['https://example.com/p.jpg'],
        'address' => '1 Test St',
    ], displaySettings: ['reviews' => false, 'photos' => false]);

    $response = actingAsUser($pro)->getJson('/api/public/profiles/'.$pro->handle.'/integrations');

    $response->assertOk();
    $body = $response->json('data.platforms.google-business.0.payload') ?? [];
    expect($body)->not->toHaveKeys(['reviews', 'reviewSummary', 'rating', 'reviewCount', 'photos'])
        ->and($body['hours'] ?? null)->not->toBeNull()
        ->and($body['address'] ?? null)->toBe('1 Test St');
});

// ── WS-B2.2: toggles gate the DASHBOARD card + the scheduled refresh, not just
// the public sitepage ────────────────────────────────────────────────────────

it('suppresses toggled-off sections from the dashboard google-business selection', function () {
    $pro = createTenant('toggles-dash');
    displaySeedConnection($pro->id, [
        'placeId' => 'places/X',
        'name' => 'Cafe',
        'rating' => 4.5,
        'reviewCount' => 12,
        'reviewSummary' => 'Lovely',
        'reviews' => [['author' => 'A', 'text' => 'Great']],
        'hours' => ['weekdays' => ['Monday: 9-5']],
        'photos' => [['ref' => 'places/X/photos/1', 'url' => 'https://lh3/1.jpg']],
        'address' => '1 Test St',
    ], displaySettings: ['reviews' => false, 'photos' => false]);

    $selection = actingAsUser($pro)
        ->getJson('/api/platforms/google-business/selection')
        ->assertOk()
        ->json('selection');

    expect($selection)->not->toHaveKeys(['reviews', 'reviewSummary', 'rating', 'reviewCount', 'photos'])
        ->and($selection['hours'] ?? null)->not->toBeNull()
        ->and($selection['address'] ?? null)->toBe('1 Test St');
});

it('does not refresh switched-off sections into the stored google-business payload', function () {
    $pro = createTenant('toggles-refresh');
    $id = displaySeedConnection($pro->id, ['placeId' => 'places/X'], displaySettings: ['reviews' => false]);
    $connection = IntegrationConnection::query()->find($id);

    $service = Mockery::mock(GoogleBusinessService::class);
    $service->shouldReceive('fetchPlaceDetails')->once()->andReturn([
        'name' => 'Cafe',
        'rating' => 4.9,
        'reviewCount' => 99,
        'reviewSummary' => 'Fresh',
        'reviews' => [['author' => 'new', 'text' => 'fresh review']],
        'hours' => ['weekdays' => ['Monday: 8-6']],
    ]);

    $merged = (new GoogleBusinessFetch($service))->fetch($connection);

    // reviews toggle off → the fresh reviews/rating/reviewCount/reviewSummary are
    // NOT written into storage; hours (still on) + name flow through unchanged.
    expect($merged)->not->toHaveKeys(['reviews', 'reviewSummary', 'rating', 'reviewCount'])
        ->and($merged['hours'] ?? null)->not->toBeNull()
        ->and($merged['name'] ?? null)->toBe('Cafe');
});

// ── WS-F4: the location toggle also drops the map (placeId) + street-view ──────

it('suppresses placeId and streetView from the dashboard selection when location is off', function () {
    $pro = createTenant('toggles-location');
    displaySeedConnection($pro->id, [
        'placeId' => 'places/X',
        'name' => 'Cafe',
        'address' => '1 Test St',
        'lat' => -37.8,
        'lng' => 144.96,
        'addressParts' => ['street' => '1 Test St'],
        'streetView' => ['panoId' => 'PANO123', 'lat' => -37.8, 'lng' => 144.96],
        'rating' => 4.5,
    ], displaySettings: ['location' => false]);

    $selection = actingAsUser($pro)
        ->getJson('/api/platforms/google-business/selection')
        ->assertOk()
        ->json('selection');

    // placeId (the map lever) + streetView + addressParts drop entirely — before
    // WS-F4 placeId survived, so the map still rendered on "Location & map" OFF.
    expect($selection)->not->toHaveKeys(['placeId', 'streetView', 'addressParts']);
    // address/lat/lng are always-present resource fields → suppressed to null.
    expect($selection['address'])->toBeNull();
    expect($selection['lat'])->toBeNull();
    expect($selection['lng'])->toBeNull();
    // an unrelated (still-on) section survives untouched.
    expect($selection['rating'] ?? null)->toBe(4.5);
});

// ── WS-B2.2 (I1): display toggles also gate public multipage page PRESENCE ────
// so a toggled-off GB section doesn't advertise an empty page in nav/pageOrder.
// menu is a Business-only page, so a business tenant is required. Reviews is no
// longer presented as a page at all (2026-07-13) — see the two tests below.

function dsBusinessTenant(string $handle): User
{
    $pro = createTenant($handle);
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update(['account_type' => 'business']);
    AccountCapabilities::flushCache();

    return User::query()->with('site')->findOrFail($pro->id);
}

function dsFetchedMenu(string $userId): void
{
    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'store_name' => 'Test Menu',
        'last_fetched_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('drops the reviews page from presence when the reviews toggle is off', function () {
    $pro = dsBusinessTenant('pages-rev-off');
    displaySeedConnection($pro->id, ['name' => 'Cafe'], displaySettings: ['reviews' => false]);

    $r = app(SitepageDataResolverService::class);
    $pages = $r->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->not->toContain('reviews');
});

it('never presents the reviews page, even with the reviews toggle on', function () {
    // Reviews is no longer a standalone page (2026-07-13) — the review DATA and
    // the dashboard toggle are unchanged; it will render inside the About page
    // once that's built. Until then presence never includes 'reviews'.
    $pro = dsBusinessTenant('pages-rev-on');
    displaySeedConnection($pro->id, ['name' => 'Cafe']); // no display_settings → ON

    $r = app(SitepageDataResolverService::class);
    $pages = $r->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->not->toContain('reviews');
});

it('drops the menu page from presence when the menu toggle is off', function () {
    $pro = dsBusinessTenant('pages-menu-off');
    displaySeedConnection($pro->id, ['name' => 'Cafe'], displaySettings: ['menu' => false]);
    dsFetchedMenu($pro->id);

    $r = app(SitepageDataResolverService::class);
    $pages = $r->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->not->toContain('menu');
});

it('keeps the menu page present when the menu toggle is on', function () {
    $pro = dsBusinessTenant('pages-menu-on');
    displaySeedConnection($pro->id, ['name' => 'Cafe']);
    dsFetchedMenu($pro->id);

    $r = app(SitepageDataResolverService::class);
    $pages = $r->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->toContain('menu');
});
