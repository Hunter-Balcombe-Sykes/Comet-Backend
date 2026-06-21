<?php

use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessApifyScraper;
use App\Services\Platforms\GoogleBusinessAutoSync;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// Defaults to a Business Partna because the GB auto-sync assertions below cover
// the FULL sync (reservations / ordering / socials), which is Business-only. Pass
// 'partna' to exercise the booking-only standard-account path.
function gbApifyUser(string $h, string $accountType = 'business'): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => $accountType,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** Seed the post-connect row: Place Details already merged, Apify pending. */
function gbApifyConnection(User $user, string $placeId = 'ChIJtest'): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => [
            'url' => 'https://maps.google.com/?cid=1',
            'placeId' => $placeId,
            'name' => 'Fade Lab Barbers',
            'rating' => 4.8,
            'apifyStatus' => 'pending',
        ],
        'last_refreshed_at' => now(),
    ]);
}

/** A representative dataset item with one unsafe URL per list to prove filtering. */
function gbApifyItem(): array
{
    return [
        'title' => 'Fade Lab Barbers',
        'menu' => 'https://fadelab.example/menu',
        // Google's long reserve URL is the fallback; the DIRECT OpenTable link wins.
        'reserveTableUrl' => 'https://www.google.com/maps/reserve/v/dine/c/abc',
        'tableReservationLinks' => [
            ['name' => 'opentable.com.au', 'url' => 'https://www.opentable.com.au/restaurant/profile/266537'],
        ],
        'restaurantData' => [
            'tableReservationProvider' => ['name' => 'OpenTable', 'reserveTableUrl' => 'https://www.google.com/maps/reserve/v/dine/c/abc'],
        ],
        'googleFoodUrl' => null,
        // Every platform, both pickup + delivery, link in orderUrl (url is null).
        'orderOnline' => [
            'pickUps' => [
                ['name' => 'UberEats', 'url' => null, 'orderUrl' => 'https://ubereats.example/fadelab?mode=pickup', 'pickUpTime' => 'Ready in 10–25 min', 'pickUpFees' => 'No fee'],
            ],
            'deliveries' => [
                ['name' => 'DoorDash', 'url' => null, 'orderUrl' => 'https://doordash.example/fadelab', 'deliveryTime' => '30–45 min', 'deliveryFees' => '$5.99'],
                ['name' => 'Sketchy', 'orderUrl' => 'javascript:alert(1)'],   // dropped by safeUrl
            ],
        ],
        'bookingLinks' => ['https://booking.example/fadelab', 'javascript:alert(2)'],
        'instagrams' => ['https://instagram.com/fadelab'],
        'facebooks' => ['https://facebook.com/fadelab'],
        'linkedIns' => [],                                          // empty → key dropped
        'tiktoks' => ['https://tiktok.com/@fadelab'],
    ];
}

// ── Connect dispatch ─────────────────────────────────────────────────────────

it('marks apify pending and dispatches the enrich job on a picker connect', function () {
    config(['services.google_maps.server_api_key' => 'server-key', 'services.apify.token' => 'apify-token']);
    Bus::fake([GoogleBusinessEnrichJob::class]);
    Http::fake([
        'maps.googleapis.com/maps/api/streetview/metadata*' => Http::response(['status' => 'ZERO_RESULTS']),
        'places.googleapis.com/*' => Http::response([
            'id' => 'ChIJtest',
            'displayName' => ['text' => 'Fade Lab Barbers'],
            'location' => ['latitude' => -37.8, 'longitude' => 144.96],
        ]),
    ]);
    $user = gbApifyUser('gba1');

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJtest', 'name' => 'Fade Lab', 'lat' => -37.0, 'lng' => 144.0,
    ])
        ->assertOk()
        ->assertJsonPath('apifyStatus', 'pending');

    Bus::assertDispatched(
        GoogleBusinessEnrichJob::class,
        fn (GoogleBusinessEnrichJob $job) => $job->placeId === 'ChIJtest' && $job->userId === (string) $user->id,
    );
});

it('does not dispatch the enrich job when no apify token is configured', function () {
    config(['services.google_maps.server_api_key' => 'server-key', 'services.apify.token' => null]);
    Bus::fake([GoogleBusinessEnrichJob::class]);
    Http::fake([
        'maps.googleapis.com/maps/api/streetview/metadata*' => Http::response(['status' => 'ZERO_RESULTS']),
        'places.googleapis.com/*' => Http::response([
            'id' => 'ChIJtest', 'displayName' => ['text' => 'Fade Lab'], 'location' => ['latitude' => -37.8, 'longitude' => 144.96],
        ]),
    ]);
    $user = gbApifyUser('gba2');

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJtest', 'name' => 'Fade Lab', 'lat' => -37.0, 'lng' => 144.0,
    ])
        ->assertOk()
        ->assertJsonMissingPath('apifyStatus');

    Bus::assertNotDispatched(GoogleBusinessEnrichJob::class);
});

it('adopts the Google Business name as display_name for a Business account', function () {
    config(['services.google_maps.server_api_key' => 'server-key', 'services.apify.token' => null]);
    Http::fake([
        'maps.googleapis.com/maps/api/streetview/metadata*' => Http::response(['status' => 'ZERO_RESULTS']),
        'places.googleapis.com/*' => Http::response([
            'id' => 'ChIJtest', 'displayName' => ['text' => 'Fade Lab'], 'location' => ['latitude' => -37.8, 'longitude' => 144.96],
        ]),
    ]);
    $user = gbApifyUser('gbbiz', 'business');

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJtest', 'name' => 'Fade Lab Barbers', 'lat' => -37.0, 'lng' => 144.0,
    ])->assertOk();

    expect($user->fresh()->display_name)->toBe('Fade Lab Barbers');
});

it('leaves display_name untouched for a standard (partna) account', function () {
    config(['services.google_maps.server_api_key' => 'server-key', 'services.apify.token' => null]);
    Http::fake([
        'maps.googleapis.com/maps/api/streetview/metadata*' => Http::response(['status' => 'ZERO_RESULTS']),
        'places.googleapis.com/*' => Http::response([
            'id' => 'ChIJtest', 'displayName' => ['text' => 'Fade Lab'], 'location' => ['latitude' => -37.8, 'longitude' => 144.96],
        ]),
    ]);
    $user = gbApifyUser('gbstd', 'partna');

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJtest', 'name' => 'Fade Lab Barbers', 'lat' => -37.0, 'lng' => 144.0,
    ])->assertOk();

    expect($user->fresh()->display_name)->toBe('Gbstd');
});

// ── Background enrichment ────────────────────────────────────────────────────

it('seeds reservation, ordering and social connections from the enrichment', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gba3');
    $conn = gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class));

    // The corrected actor input is still locked in.
    Http::assertSent(function ($request) {
        $body = $request->data();

        return ($body['scrapeContacts'] ?? null) === true
            && ($body['scrapePlaceDetailPage'] ?? null) === true
            && ! array_key_exists('scrapeSocialMediaProfiles', $body);
    });

    // Google Business payload is business-info ONLY now — the harvested links moved out.
    $conn->refresh();
    $p = $conn->payload;
    expect($p['apifyStatus'])->toBe('ok');
    expect($p)->toHaveKey('apifyFetchedAt');
    expect($p['rating'])->toBe(4.8);              // Place Details survives
    expect($p['name'])->toBe('Fade Lab Barbers');
    foreach (['menu', 'reservation', 'order', 'booking', 'socials'] as $moved) {
        expect($p)->not->toHaveKey($moved);
    }

    // Reservation → an OpenTable connection with the live keyless widget.
    $ot = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'opentable')->firstOrFail()->payload;
    expect($ot['rid'])->toBe('266537');
    expect($ot['embedUrl'])->toContain('rid=266537');
    expect($ot['source'])->toBe('google-business');

    // Ordering → one online-ordering row per provider, carrying the metadata.
    $orders = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'online-ordering')->get();
    expect($orders)->toHaveCount(2);              // UberEats pickup + DoorDash delivery; javascript: dropped
    $uber = $orders->first(fn ($r) => ($r->payload['name'] ?? null) === 'UberEats')->payload;
    expect($uber['url'])->toBe('https://ubereats.example/fadelab?mode=pickup');
    expect($uber['source'])->toBe('google-business');
    expect($uber['data'])->toMatchArray(['type' => 'pickup', 'time' => 'Ready in 10–25 min', 'fees' => 'No fee', 'sourcePlatform' => 'UberEats']);

    // Link socials → facebook + tiktok rows (with the source tag).
    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['url'])->toBe('https://facebook.com/fadelab');
    expect($fb['username'])->toBe('fadelab');
    expect($fb['source'])->toBe('google-business');
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'tiktok')->exists())->toBeTrue();

    // Instagram → a pending placeholder + the budgeted scrape job dispatched.
    $ig = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'instagram')->firstOrFail();
    expect($ig->last_refresh_status)->toBe('pending');
    expect($ig->payload['source'])->toBe('google-business');
    Bus::assertDispatched(InstagramConnectJob::class, fn ($job) => $job->username === 'fadelab' && $job->userId === (string) $user->id);

    // Booking → a custom card seeded from Google's appointment-booking link.
    $booking = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'booking')->firstOrFail()->payload;
    expect($booking['provider'])->toBe('custom');
    expect($booking['url'])->toBe('https://booking.example/fadelab');
    expect($booking['source'])->toBe('google-business');
});

it('syncs ONLY the booking link for a standard (partna) account', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gbapartna', 'partna');
    gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class));

    // Booking IS synced for every account type (Google's appointment link, only-if-empty).
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'booking')->exists())->toBeTrue();

    // The Business-only seeds are all skipped for a standard account.
    foreach (['opentable', 'reservations', 'online-ordering', 'facebook', 'tiktok', 'instagram'] as $businessOnly) {
        expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', $businessOnly)->exists())
            ->toBeFalse("expected no {$businessOnly} row for a partna account");
    }
    Bus::assertNotDispatched(InstagramConnectJob::class);
});

it('keeps the Google Business selection business-info-only after enrichment', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gba4');
    gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class));

    // The harvested links are gone from Google Business — just business-info + status.
    actingAsUser($user)->getJson('/api/platforms/google-business/selection')
        ->assertOk()
        ->assertJsonPath('selection.apifyStatus', 'ok')
        ->assertJsonMissingPath('selection.menu')
        ->assertJsonMissingPath('selection.socials');

    // The reservation now lives on the Reservations integration instead.
    actingAsUser($user)->getJson('/api/platforms/reservations/status')
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('provider', 'opentable');

    // ...and the auto-synced rows surface on the synced endpoint for the modal.
    $synced = actingAsUser($user)->getJson('/api/platforms/google-business/synced')
        ->assertOk()->json('synced');
    expect(collect($synced)->pluck('category'))->toContain('reservations', 'online-ordering', 'social');
});

it('only-if-empty: never overwrites a reservation or social the user already set', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gba8');
    gbApifyConnection($user);

    // Pre-existing manual reservation + facebook the user curated themselves.
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'opentable', 'resource_id' => 'opentable',
        'payload' => ['url' => 'https://www.opentable.com.au/restaurant/profile/999', 'rid' => '999', 'name' => 'Mine', 'embedUrl' => 'x', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'mine', 'url' => 'https://facebook.com/mine', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class));

    // Both kept their manual value + tag — the Google sync left them alone.
    $ot = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'opentable')->firstOrFail()->payload;
    expect($ot['rid'])->toBe('999');
    expect($ot['source'])->toBe('manual');
    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['username'])->toBe('mine');
    expect($fb['source'])->toBe('manual');

    // The empty slots (ordering) still seed.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'online-ordering')->count())->toBe(2);
});

it('marks apify unavailable when the scrape returns nothing', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);  // empty dataset
    $user = gbApifyUser('gba5');
    $conn = gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class));

    $conn->refresh();
    expect($conn->payload['apifyStatus'])->toBe('unavailable');
    expect($conn->payload['rating'])->toBe(4.8);   // core card untouched
    expect($conn->payload)->not->toHaveKey('menu');
});

it('skips enrichment when the stored place no longer matches (reconnect guard)', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    $user = gbApifyUser('gba6');
    $conn = gbApifyConnection($user, 'ChIJdifferent');   // row now points elsewhere

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class));

    $conn->refresh();
    expect($conn->payload)->not->toHaveKey('menu');
    expect($conn->payload['apifyStatus'])->toBe('pending');   // untouched
    Http::assertNothingSent();
});

// ── Public allowlist ─────────────────────────────────────────────────────────

it('keeps apify enrichment off the public endpoint', function () {
    $user = gbApifyUser('gba7');
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => [
            'url' => 'https://maps.google.com/?cid=1',
            'name' => 'Fade Lab',
            'rating' => 4.8,
            // Apify enrichment — dashboard-only this phase:
            'menu' => 'https://fadelab.example/menu',
            'reservation' => ['url' => 'https://book.example/fadelab'],
            'order' => ['googleFood' => 'https://food.google.example/fadelab'],
            'booking' => ['https://booking.example/fadelab'],
            'socials' => ['instagram' => 'https://instagram.com/fadelab'],
            'apifyStatus' => 'ok',
            'apifyFetchedAt' => '2026-06-16T00:00:00+00:00',
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $payload = $this->getJson('/api/public/profiles/gba7/integrations')
        ->assertOk()
        ->json('data.platforms.google-business.0.payload');

    expect($payload['rating'])->toBe(4.8);
    foreach (['menu', 'reservation', 'order', 'booking', 'socials', 'apifyStatus', 'apifyFetchedAt'] as $private) {
        expect($payload)->not->toHaveKey($private);
    }
});
