<?php

use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessApifyScraper;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function gbApifyUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'individual',
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
        'reserveTableUrl' => 'https://book.example/fadelab',
        'tableReservationLinks' => [
            ['name' => 'OpenTable', 'url' => 'https://opentable.example/fadelab'],
        ],
        'restaurantData' => [
            'tableReservationProvider' => ['name' => 'OpenTable', 'reserveTableUrl' => 'https://book.example/fadelab'],
        ],
        'googleFoodUrl' => 'https://food.google.example/fadelab',
        'orderBy' => [
            ['name' => 'DoorDash', 'url' => 'https://doordash.example/fadelab'],
            ['name' => 'Sketchy', 'url' => 'javascript:alert(1)'],   // dropped by safeUrl
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

// ── Background enrichment ────────────────────────────────────────────────────

it('merges apify action links + socials and drops unsafe urls', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    $user = gbApifyUser('gba3');
    $conn = gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))->handle(app(GoogleBusinessApifyScraper::class));

    $conn->refresh();
    $p = $conn->payload;

    expect($p['menu'])->toBe('https://fadelab.example/menu');
    expect($p['reservation']['url'])->toBe('https://book.example/fadelab');
    expect($p['reservation']['provider'])->toBe('OpenTable');
    expect($p['reservation']['links'])->toBe(['https://opentable.example/fadelab']);
    expect($p['order']['googleFood'])->toBe('https://food.google.example/fadelab');
    expect($p['order']['providers'])->toHaveCount(1);              // javascript: provider dropped
    expect($p['order']['providers'][0]['name'])->toBe('DoorDash');
    expect($p['booking'])->toBe(['https://booking.example/fadelab']); // javascript: link dropped
    expect($p['socials']['instagram'])->toBe('https://instagram.com/fadelab');
    expect($p['socials']['tiktok'])->toBe('https://tiktok.com/@fadelab');
    expect($p['socials'])->not->toHaveKey('linkedin');             // empty array → dropped
    expect($p['apifyStatus'])->toBe('ok');
    expect($p)->toHaveKey('apifyFetchedAt');

    // Place Details values survive the merge.
    expect($p['rating'])->toBe(4.8);
    expect($p['name'])->toBe('Fade Lab Barbers');
});

it('exposes the apify enrichment on the dashboard selection endpoint', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    $user = gbApifyUser('gba4');
    gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))->handle(app(GoogleBusinessApifyScraper::class));

    actingAsUser($user)->getJson('/api/platforms/google-business/selection')
        ->assertOk()
        ->assertJsonPath('selection.menu', 'https://fadelab.example/menu')
        ->assertJsonPath('selection.socials.instagram', 'https://instagram.com/fadelab')
        ->assertJsonPath('selection.apifyStatus', 'ok');
});

it('marks apify unavailable when the scrape returns nothing', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);  // empty dataset
    $user = gbApifyUser('gba5');
    $conn = gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))->handle(app(GoogleBusinessApifyScraper::class));

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

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))->handle(app(GoogleBusinessApifyScraper::class));

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
