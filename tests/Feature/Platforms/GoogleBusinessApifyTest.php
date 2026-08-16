<?php

use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\GoogleBusinessApifyScraper;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Harvester stub: returns nothing so each test exercises the Apify path exactly as before the website-harvest step landed. */
function emptyHarvester(): WebsiteLinkHarvester
{
    return new class(app(SafeUrlFetcher::class)) extends WebsiteLinkHarvester
    {
        public function harvest(?string $websiteUrl): array
        {
            return [];
        }
    };
}

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// Defaults to a Business Partna because the GB auto-sync assertions below cover
// the FULL sync (reservations / ordering / socials), which is Business-only. Pass
// 'partna' to exercise the booking-only standard-account path. Sector defaults
// to null (not-food) — reservations/online-ordering are food-business-only
// (2026-07-15 sector gating); pass sector: 'restaurant' (or similar) on the
// specific tests exercising that family.
function gbApifyUser(string $h, string $accountType = 'business', ?string $sector = null): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => $accountType,
        'sector' => $sector,
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
            'placeId' => $placeId,            // KEPT in payload (first-class)
            'name' => 'Fade Lab Barbers',
            'rating' => 4.8,
        ],
        'place_id' => $placeId,               // indexed mirror column
        'apify_status' => 'pending',          // promoted column (was payload.apifyStatus)
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
                ['name' => 'UberEats', 'url' => null, 'orderUrl' => 'https://www.ubereats.com/au/store/fadelab/abc?mode=pickup', 'pickUpTime' => 'Ready in 10–25 min', 'pickUpFees' => 'No fee'],
            ],
            'deliveries' => [
                ['name' => 'DoorDash', 'url' => null, 'orderUrl' => 'https://www.doordash.com/store/fadelab-123', 'deliveryTime' => '30–45 min', 'deliveryFees' => '$5.99'],
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

it('promotes apify_status to a column and mirrors place_id on a picker connect', function () {
    config(['services.google_maps.server_api_key' => 'server-key', 'services.apify.token' => 'apify-token']);
    Bus::fake([GoogleBusinessEnrichJob::class]);
    Http::fake([
        'maps.googleapis.com/maps/api/streetview/metadata*' => Http::response(['status' => 'ZERO_RESULTS']),
        'places.googleapis.com/*' => Http::response([
            'id' => 'ChIJtest', 'displayName' => ['text' => 'Fade Lab'], 'location' => ['latitude' => -37.8, 'longitude' => 144.96],
        ]),
    ]);
    $user = gbApifyUser('gbcol');

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJtest', 'name' => 'Fade Lab', 'lat' => -37.0, 'lng' => 144.0,
    ])->assertOk()->assertJsonPath('apifyStatus', 'pending');

    $conn = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'google-business')->firstOrFail();
    expect($conn->apify_status)->toBe('pending');          // promoted to column
    expect($conn->place_id)->toBe('ChIJtest');             // indexed mirror
    expect($conn->payload)->not->toHaveKey('apifyStatus');  // stripped from payload
    expect($conn->payload['placeId'])->toBe('ChIJtest');    // KEPT in payload (first-class)
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

it('adopts the Google Business name as display_name for a Business account, word-trimmed to the 15-char cap', function () {
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

    // Picker name "Fade Lab Barbers" (16 chars) is over the cap — maybeAdoptGoogleName
    // word-trims it, keeping whole words, rather than rejecting it outright.
    expect($user->fresh()->display_name)->toBe('Fade Lab');
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
    // Food sector: reservations + online-ordering are food-business-only
    // (2026-07-15 sector gating) — this fixture's enrichment carries reservation,
    // order AND booking links at once specifically to prove the gate (not
    // absent data) decides the outcome; see the booking assertion below.
    $user = gbApifyUser('gba3', sector: 'restaurant');
    $conn = gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

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
    expect($conn->apify_status)->toBe('ok');           // promoted to column
    expect($p)->not->toHaveKey('apifyStatus');         // stripped from payload
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
    $orders = IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'ordering')->get();
    expect($orders)->toHaveCount(2);              // UberEats pickup + DoorDash delivery; javascript: dropped
    $uber = $orders->first(fn ($r) => ($r->payload['name'] ?? null) === 'UberEats')->payload;
    expect($uber['url'])->toBe('https://www.ubereats.com/au/store/fadelab/abc?mode=pickup');
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

    // Booking → NOT seeded: a food business books via Reservations (above),
    // even though Google's data also carried a booking link (proves the gate,
    // not absent data, is what withheld it).
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'booking')->exists())->toBeFalse();
});

it('consolidates same-store pickup and delivery ordering providers into one row', function () {
    Bus::fake();
    // Online-ordering is food-business-only (2026-07-15 sector gating).
    $user = gbApifyUser('gbaorder', sector: 'restaurant');

    // Google lists each provider×mode separately. Two of these are the SAME Uber
    // Eats store (the diningMode query differs); they must collapse into one row
    // carrying both URLs. DoorDash is a distinct store → its own row.
    $enrichment = ['order' => ['providers' => [
        ['name' => 'UberEats', 'url' => 'https://www.ubereats.com/au/store/ollies/abc?diningMode=PICKUP', 'type' => 'pickup', 'time' => 'Ready in 10 min', 'fees' => 'No fee'],
        ['name' => 'UberEats', 'url' => 'https://www.ubereats.com/au/store/ollies/abc?diningMode=DELIVERY', 'type' => 'delivery', 'time' => '20–30 min', 'fees' => '$2'],
        ['name' => 'DoorDash', 'url' => 'https://www.doordash.com/store/ollies-1/', 'type' => 'delivery', 'time' => '25 min', 'fees' => '$3'],
    ]]];

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, $enrichment, 'Ollies');

    $orders = IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'ordering')->get();
    expect($orders)->toHaveCount(2);  // UE (pickup+delivery collapsed) + DoorDash

    $uber = $orders->first(fn ($r) => ($r->payload['name'] ?? null) === 'UberEats')->payload;
    expect($uber['data']['pickupUrl'])->toBe('https://www.ubereats.com/au/store/ollies/abc?diningMode=PICKUP');
    expect($uber['data']['deliveryUrl'])->toBe('https://www.ubereats.com/au/store/ollies/abc?diningMode=DELIVERY');
    // The representative row prefers the delivery-typed provider.
    expect($uber['url'])->toBe('https://www.ubereats.com/au/store/ollies/abc?diningMode=DELIVERY');
});

it('does not re-seed an ordering store the user already has (only-if-empty per store)', function () {
    Bus::fake();
    // Food sector so the only-if-empty check itself is exercised (not just a
    // capability skip that would trivially leave the manual row untouched).
    $user = gbApifyUser('gbaorder2', sector: 'restaurant');

    // The user already has the Uber Eats store (added manually, pickup variant).
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'uber_eats.order', 'resource_id' => 'order-existing',
        'payload' => ['id' => 'order-existing', 'provider' => 'custom', 'url' => 'https://www.ubereats.com/au/store/ollies/abc?diningMode=PICKUP', 'name' => 'Mine', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $enrichment = ['order' => ['providers' => [
        ['name' => 'UberEats', 'url' => 'https://www.ubereats.com/au/store/ollies/abc?diningMode=DELIVERY', 'type' => 'delivery'],
    ]]];

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, $enrichment, 'Ollies');

    // Same store → not re-seeded; the user's manual row is the only one.
    $orders = IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'ordering')->get();
    expect($orders)->toHaveCount(1);
    expect($orders->first()->payload['name'])->toBe('Mine');
});

it('syncs ONLY the booking link for a standard (partna) account', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gbapartna', 'partna');
    gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    // Booking IS synced for every account type (Google's appointment link, only-if-empty).
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'booking')->exists())->toBeTrue();

    // The Business-only seeds are all skipped for a standard account.
    foreach (['opentable', 'reservations', 'online-ordering', 'facebook', 'tiktok', 'instagram'] as $businessOnly) {
        expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', $businessOnly)->exists())
            ->toBeFalse("expected no {$businessOnly} row for a partna account");
    }
    Bus::assertNotDispatched(InstagramConnectJob::class);
});

it('seeds no booking when the only booking link is the business website', function () {
    config(['services.apify.token' => 'apify-token']);
    $item = gbApifyItem();
    $item['website'] = 'http://www.brotherwolf.com.au/';
    // The actor echoes the "Website" button into bookingLinks (the real bug seen
    // on Brother Wolf) — it must NOT become a booking card.
    $item['bookingLinks'] = [['name' => 'brotherwolf.com.au', 'url' => 'http://www.brotherwolf.com.au/']];
    Http::fake(['api.apify.com/*' => Http::response([$item], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gbweb1', 'business');
    gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    foreach (['booking', 'fresha', 'square'] as $p) {
        expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', $p)->exists())
            ->toBeFalse("the website must not seed a {$p} row");
    }
});

it('drops the website from booking links but keeps a real provider link', function () {
    config(['services.apify.token' => 'apify-token']);
    $item = gbApifyItem();
    $item['website'] = 'http://www.brotherwolf.com.au/';
    $item['bookingLinks'] = [
        ['name' => 'brotherwolf.com.au', 'url' => 'https://www.brotherwolf.com.au/'],  // website echo → dropped
        ['name' => 'Fresha', 'url' => 'https://www.fresha.com/a/brother-wolf'],          // real → kept
    ];
    Http::fake(['api.apify.com/*' => Http::response([$item], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gbweb2', 'business');
    gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    // No custom 'booking' card from the website; the Fresha link connected (pending).
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'booking')->exists())->toBeFalse();
    $fresha = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail()->payload;
    expect($fresha['url'])->toBe('https://www.fresha.com/a/brother-wolf');
    expect($fresha['selection'])->toBeNull();
});

it('keeps a same-domain appointment link and auto-syncs it as the booking card', function () {
    config(['services.apify.token' => 'apify-token']);
    $item = gbApifyItem();
    $item['website'] = 'https://www.fadelab.com.au/';
    // Google's "Book online" appointment link lives on the business's OWN domain.
    // It must NOT be filtered as the website echo — it's a real way to book.
    $item['bookingLinks'] = [
        ['name' => 'fadelab.com.au', 'url' => 'http://fadelab.com.au'],                 // website echo (diff scheme/www) → dropped
        ['name' => 'Book', 'url' => 'https://www.fadelab.com.au/book-appointment'],     // appointment → kept
    ];
    Http::fake(['api.apify.com/*' => Http::response([$item], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gbweb3', 'business');
    gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    // The same-domain appointment link became the user's (custom) booking card.
    $booking = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'booking')->firstOrFail()->payload;
    expect($booking['url'])->toBe('https://www.fadelab.com.au/book-appointment');
    expect($booking['source'])->toBe('google-business');
});

it('keeps the Google Business selection business-info-only after enrichment', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    // Food sector: this test asserts reservation + online-ordering synced rows.
    $user = gbApifyUser('gba4', sector: 'restaurant');
    gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

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
    // Food sector: this test asserts the only-if-empty rule for BOTH the
    // reservation slot (manual opentable, below) and online-ordering.
    $user = gbApifyUser('gba8', sector: 'restaurant');
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
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    // Both kept their manual value + tag — the Google sync left them alone.
    $ot = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'opentable')->firstOrFail()->payload;
    expect($ot['rid'])->toBe('999');
    expect($ot['source'])->toBe('manual');
    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['username'])->toBe('mine');
    expect($fb['source'])->toBe('manual');

    // The empty slots (ordering) still seed.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'ordering')->count())->toBe(2);
});

it('seeds a legacy /pages/ Facebook link with the extracted Page name, not "pages" (G4-4)', function () {
    // Regression for the real bug: GoogleBusinessAutoSync::socialUsername() used
    // to run its OWN standalone regex (independent of FacebookNormalizer) that
    // had no concept of reserved path segments, so a business whose website (or
    // Google listing) linked a legacy facebook.com/pages/<Name>/<id> Page had
    // "pages" itself stored as the username. socialUsername() now delegates to
    // FacebookNormalizer so both entry points share one fix.
    $user = gbApifyUser('gbafbpages');

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, [
        'socials' => ['facebook' => 'https://www.facebook.com/pages/DOC-Pizza-Carlton/12345'],
    ], 'DOC Pizza');

    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['username'])->toBe('DOC-Pizza-Carlton');
    expect($fb['url'])->toBe('https://www.facebook.com/pages/DOC-Pizza-Carlton/12345');
    expect($fb['source'])->toBe('google-business');
});

it('marks apify unavailable when the scrape returns nothing', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);  // empty dataset
    $user = gbApifyUser('gba5');
    $conn = gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    $conn->refresh();
    expect($conn->apify_status)->toBe('unavailable');
    expect($conn->payload['rating'])->toBe(4.8);   // core card untouched
    expect($conn->payload)->not->toHaveKey('menu');
});

it('skips enrichment when the stored place no longer matches (reconnect guard)', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    $user = gbApifyUser('gba6');
    $conn = gbApifyConnection($user, 'ChIJdifferent');   // row now points elsewhere

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    $conn->refresh();
    expect($conn->payload)->not->toHaveKey('menu');
    expect($conn->apify_status)->toBe('pending');   // untouched (indexed guard skipped the job)
    Http::assertNothingSent();
});

it('gates switched-off display sections out of the persisted payload but keeps placeId (WS-B2)', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gb-enrich-gate');
    $conn = gbApifyConnection($user);
    // Location switched off, with stored location data a re-enrich must not persist.
    $conn->forceFill([
        'display_settings' => ['location' => false],
        'payload' => [
            ...$conn->payload,
            'address' => '1 Test St',
            'lat' => -37.8,
            'lng' => 144.96,
            'addressParts' => ['street' => '1 Test St'],
            'streetView' => ['panoId' => 'PANO', 'lat' => -37.8, 'lng' => 144.96],
        ],
    ])->saveQuietly();

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    $p = $conn->fresh()->payload;
    // location off → its display keys are stripped from storage (matches GoogleBusinessFetch)…
    expect($p)->not->toHaveKeys(['address', 'lat', 'lng', 'addressParts', 'streetView']);
    // …but placeId (the refresh identity key) is preserved even though it's in the
    // location suppression set — else the next scheduled refresh 500s on missing_place_id.
    expect($p['placeId'])->toBe('ChIJtest');
    expect($p['rating'])->toBe(4.8);   // an unrelated (still-on) section survives
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

    // `name` is the still-public control: it proves the connection publishes at
    // all, so the private-key assertions below can't pass on an empty payload.
    // It replaces `rating`, which slice 6 retired from this lane (2026-08-13)
    // — asserted here so the swap is a recorded consequence, not a silent one.
    expect($payload['name'])->toBe('Fade Lab');
    expect($payload)->not->toHaveKey('rating');
    foreach (['menu', 'reservation', 'order', 'booking', 'socials', 'apifyStatus', 'apifyFetchedAt'] as $private) {
        expect($payload)->not->toHaveKey($private);
    }
});

// ── Per-connect findings + Change-to ─────────────────────────────────────────

it('exposes only this run findings on /synced with a live status per platform', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    // Food sector: this test asserts reservation + online-ordering findings.
    $user = gbApifyUser('gbsync1', sector: 'restaurant');
    gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    $synced = actingAsUser($user)->getJson('/api/platforms/google-business/synced')->assertOk()->json('synced');

    // Reservation + social link seeds land 'synced'; instagram stays 'syncing'
    // (its scrape is still pending behind the faked bus).
    expect(collect($synced)->firstWhere('platform', 'opentable')['status'])->toBe('synced');
    expect(collect($synced)->firstWhere('platform', 'opentable')['category'])->toBe('reservations');
    expect(collect($synced)->firstWhere('platform', 'facebook')['status'])->toBe('synced');
    expect(collect($synced)->firstWhere('platform', 'instagram')['status'])->toBe('syncing');
    expect(collect($synced)->where('category', 'online-ordering'))->toHaveCount(2);
    // No booking finding: a food business books via Reservations (above), even
    // though Google's data also carried a booking link.
    expect(collect($synced)->firstWhere('platform', 'booking'))->toBeNull();
});

it('marks an already-connected platform as a conflict and Change-to swaps it in', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gbsync2');
    gbApifyConnection($user);
    // The user already curated a Facebook themselves.
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'mine', 'url' => 'https://facebook.com/mine', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    // /synced reports Facebook as a conflict (the manual one was left alone).
    $fb = collect(actingAsUser($user)->getJson('/api/platforms/google-business/synced')->json('synced'))->firstWhere('platform', 'facebook');
    expect($fb['status'])->toBe('conflict');
    expect($fb['foundUrl'])->toBe('https://facebook.com/fadelab');
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload['source'])->toBe('manual');

    // "Change to" swaps in Google's.
    actingAsUser($user)->postJson('/api/platforms/google-business/synced/apply', ['platform' => 'facebook'])->assertOk();
    $row = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail();
    expect($row->payload['source'])->toBe('google-business');
    expect($row->payload['url'])->toBe('https://facebook.com/fadelab');
    expect(collect(actingAsUser($user)->getJson('/api/platforms/google-business/synced')->json('synced'))->firstWhere('platform', 'facebook')['status'])->toBe('synced');
});

it('Change-to re-runs the Instagram scrape when swapping an existing Instagram', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gbsync3');
    gbApifyConnection($user);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['username' => 'mine', 'source' => 'manual'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    expect(collect(actingAsUser($user)->getJson('/api/platforms/google-business/synced')->json('synced'))->firstWhere('platform', 'instagram')['status'])->toBe('conflict');
    Bus::assertNotDispatched(InstagramConnectJob::class);   // slot was taken — no scrape spent

    actingAsUser($user)->postJson('/api/platforms/google-business/synced/apply', ['platform' => 'instagram'])->assertOk();
    Bus::assertDispatched(InstagramConnectJob::class, fn ($job) => $job->username === 'fadelab' && $job->userId === (string) $user->id);
});

it('skips the scrape and sends no HTTP when the apify budget is exhausted', function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.limits.apify.actors.google-business', 0); // no budget
    Http::fake();

    $result = app(GoogleBusinessApifyScraper::class)->fetch('ChIJtest', 'user-1');

    expect($result)->toBeNull();
    Http::assertNothingSent();
});

it('skips the paid Apify run when a non-food business website satisfies the harvest', function () {
    Http::fake(); // any Apify call would get a fake 200 [] — the assertion below proves none fired
    $user = gbApifyUser('harvest-skip');
    $connection = gbApifyConnection($user);
    $connection->forceFill(['payload' => [
        ...$connection->payload,
        'category' => 'Barber shop',
        'website' => 'https://barber.example.com.au',
    ]])->saveQuietly();

    $harvester = new class(app(SafeUrlFetcher::class)) extends WebsiteLinkHarvester
    {
        public function harvest(?string $websiteUrl): array
        {
            return ['socials' => ['instagram' => 'https://instagram.com/barber']];
        }
    };

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), $harvester);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));

    expect($connection->fresh()->apify_status)->toBe('ok');
});
