<?php

use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\GoogleBusinessApifyScraper;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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
    // The suggestions inbox reads routing.source_intents alongside the folded
    // payload findings (2026-08-19), so the schema must exist for the
    // Swap-offer cases below.
    setupRoutingTables();
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
        'bookingLinks' => ['https://calendly.com/fadelab', 'javascript:alert(2)'],
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

    // "Fade Lab Barbers" fits the 80-char sanity bound (raised from 15 —
    // owner, 2026-08-27, issue 10) so maybeAdoptGoogleName adopts it whole.
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

    // Reservation → NOT a live connection (2026-09-06: reservations always
    // route through the suggestion pipeline, same as booking) — an OpenTable
    // proposed intent instead.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'opentable')->exists())->toBeFalse();
    $ot = DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'opentable.reserve')->firstOrFail();
    expect($ot->state)->toBe('proposed');
    expect($ot->identifier)->toBe('266537');

    // Ordering → NOT live connections (A1, 2026-09-06: a fresh store found on
    // a harvest always routes through the suggestion pipeline too, same fix
    // as booking/reservations) — one proposed 'ordering' intent per store
    // (UberEats pickup + DoorDash delivery; javascript: dropped). The rich
    // per-provider metadata (fees/time/pickupUrl/deliveryUrl) is no longer
    // threaded through — same accepted information-loss tradeoff already
    // shipped for reservations.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'ordering')->exists())->toBeFalse();
    $orderIntents = DB::table('routing.source_intents')->where('user_id', $user->id)->where('routing_class', 'ordering')->get();
    expect($orderIntents)->toHaveCount(2);
    expect($orderIntents->pluck('surface_key')->sort()->values()->all())->toBe(['doordash.order', 'uber_eats.order']);
    expect($orderIntents->pluck('state')->unique()->all())->toBe(['proposed']);
    // Ordering is an exclusive-auto class (RoutingClass::isExclusiveAuto): at
    // most one candidate is ever pre-ticked in the setup dialog at a time —
    // SourceReconciler::hasLiveAutoSibling() downgrades every sibling after
    // the first to 'suggest', even though every brand still lands as its own
    // (non-pre-ticked) suggestion.
    expect($orderIntents->pluck('band')->sort()->values()->all())->toBe(['auto', 'suggest']);

    // Link socials → NOT live connections (2026-09-06: a Google Business
    // social link is a harvested discovery, not a direct request, same fix
    // as booking/reservations) — proposed facebook + tiktok intents instead.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->exists())->toBeFalse();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'tiktok')->exists())->toBeFalse();
    $fbIntent = DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'facebook.profile')->firstOrFail();
    expect($fbIntent->state)->toBe('proposed');
    expect($fbIntent->canonical_url)->toBe('https://facebook.com/fadelab');
    expect($fbIntent->identifier)->toBe('fadelab');
    expect($fbIntent->origin)->toBe('google_business');
    $ttIntent = DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'tiktok.profile')->firstOrFail();
    expect($ttIntent->state)->toBe('proposed');

    // Instagram → a pending placeholder + the budgeted scrape job dispatched.
    $ig = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'instagram')->firstOrFail();
    expect($ig->last_refresh_status)->toBe('pending');
    expect($ig->payload['source'])->toBe('google-business');
    Bus::assertDispatched(InstagramConnectJob::class, fn ($job) => $job->username === 'fadelab' && $job->userId === (string) $user->id);

    // Booking → NOT seeded: a food business books via Reservations (above),
    // even though Google's data also carried a booking link (proves the gate,
    // not absent data, is what withheld it).
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'booking')->exists())->toBeFalse();
});

// ── M-12: socials from the contacts crawl must survive projection ────────────

it('rejects a platform-chrome link scraped as a social instead of minting a fake profile', function () {
    config(['services.apify.token' => 'apify-token']);
    $item = gbApifyItem();
    // The live B6 shape: Apify's contacts crawl of an Instagram-as-website
    // page returned Meta's own docs link as the business's "facebook".
    $item['facebooks'] = ['https://developers.facebook.com/docs/instagram'];
    Http::fake(['api.apify.com/*' => Http::response([$item], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gba20', sector: 'restaurant');
    gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->exists())->toBeFalse();
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'facebook.profile')->exists())->toBeFalse();
    // The other socials still propose — one bad URL never blocks the rest
    // (2026-09-06: socials propose rather than connect, same as booking).
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'tiktok.profile')->where('state', 'proposed')->exists())->toBeTrue();
});

it('drops a legacy facebook /pages/ URL to nothing routable (catalog reserves that path on purpose)', function () {
    // 2026-09-06: the normalizer fallback here (isCanonicalFacebookHost() +
    // socialUsername()) used to exist specifically so a legacy
    // facebook.com/pages/<Name>/<id> Page could bypass the catalog's own
    // projector — which reserves 'pages' deliberately (Facebook.php's
    // RESERVED list mirrors FacebookNormalizer::RESERVED_SEGMENTS on
    // purpose: a bare /pages/ path is ambiguous without the second,
    // ID-bearing segment). Now that nothing bypasses the catalog, this one
    // legacy shape has no projector rule to place OR suggest it through —
    // same class of gap as GB's brandless direct.book booking link — so it
    // is silently dropped rather than reconstructing a guessed
    // facebook.com/<name> URL that may not even resolve to the same Page.
    config(['services.apify.token' => 'apify-token']);
    $item = gbApifyItem();
    $item['facebooks'] = ['https://www.facebook.com/pages/Fade-Lab/123456789'];
    Http::fake(['api.apify.com/*' => Http::response([$item], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gba21', sector: 'restaurant');
    gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->exists())->toBeFalse();
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'facebook.profile')->exists())->toBeFalse();
});

it('skips every contacts-crawl social when the listing website is itself a platform page', function () {
    config(['services.apify.token' => 'apify-token']);
    $item = gbApifyItem();  // carries real-looking facebook/tiktok/instagram
    Http::fake(['api.apify.com/*' => Http::response([$item], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gba22', sector: 'restaurant');
    $conn = gbApifyConnection($user);
    // B6 DOH live: Google's "website" is the business's Instagram profile —
    // Apify's contacts crawl therefore crawled instagram.com, and everything
    // it "found" is platform chrome, not the business's accounts.
    $conn->payload = [...$conn->payload, 'website' => 'https://www.instagram.com/doh.melbourne'];
    $conn->save();

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    foreach (['facebook', 'tiktok'] as $platform) {
        expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', $platform)->exists())
            ->toBeFalse("expected no {$platform} connection from platform-chrome socials");
    }

    // The Instagram FIND is the one legitimate outcome — it comes from the
    // WEBSITE divert (the business naming its own profile as their site,
    // routed through the engine with the ledger-accepted 'google_business'
    // origin), never from the chrome socials that were skipped above. Since
    // 2026-09-03 that origin is a harvest like any other — nothing a
    // harvester finds auto-connects — so the divert lands as a proposed
    // suggestion rather than a live connection.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'instagram')->exists())->toBeFalse();
    $intent = DB::table('routing.source_intents')
        ->where(['user_id' => $user->id, 'surface_key' => 'instagram.profile'])->first();
    expect($intent)->not->toBeNull()
        ->and($intent->identifier)->toBe('doh.melbourne')
        ->and((string) $intent->state)->toBe('proposed');
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

    // A1 (2026-09-06): NOT live connections any more — a fresh store always
    // routes through the suggestion pipeline, same fix as booking/
    // reservations. Still proves the same consolidation (pickup+delivery
    // collapse into one store) via the proposed-intent count, and which URL
    // won as the store's identity, rather than via a written payload.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'ordering')->exists())->toBeFalse();
    $orderIntents = DB::table('routing.source_intents')->where('user_id', $user->id)->where('routing_class', 'ordering')->get();
    expect($orderIntents)->toHaveCount(2);  // UE (pickup+delivery collapsed) + DoorDash

    $uber = $orderIntents->firstWhere('surface_key', 'uber_eats.order');
    expect($uber)->not->toBeNull();
    // The representative row prefers the delivery-typed provider.
    expect($uber->canonical_url)->toContain('diningMode=DELIVERY');
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

it('syncs booking + workplace for a standard (partna) account, but never its socials, reservations or ordering', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbApifyUser('gbapartna', 'partna');
    gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    // Booking is DISCOVERED for every account type (Google's appointment link,
    // only-if-empty) but, like every other booking brand (2026-09-06), only
    // ever proposed — never connected outright.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'booking')->exists())->toBeFalse();
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('routing_class', 'booking')->where('state', 'proposed')->exists())->toBeTrue();

    // Ruling R14 (overnight 2026-08-18) held that socials + workplace seed for
    // every account type. Its WORKPLACE half stands, and is asserted below.
    foreach (['opentable', 'reservations', 'online-ordering'] as $businessOnly) {
        expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', $businessOnly)->exists())
            ->toBeFalse("expected no {$businessOnly} row for a partna account");
    }

    // Its SOCIALS half was narrowed 2026-09-03 (owner). R14's premise was "an
    // individual who CONNECTS THEIR listing" — but in the pre-account flow the
    // listing attached to a partna is the WORKPLACE's, put there by
    // FreshaWorkplaceLinker, so these rows were the salon's accounts landing on
    // the person's page. lukemunnn got a finding offering to swap their own
    // Instagram for the shop's @Youthofdulwich. A business account, whose
    // listing IS its identity, still seeds socials — pinned by
    // WorkplaceListingSocialsGateTest.
    foreach (['facebook', 'tiktok'] as $social) {
        expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', $social)->exists())
            ->toBeFalse("expected no {$social} row for a partna: the listing is the workplace's");
    }

    // ...and no paid Instagram scrape of the salon's profile either.
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

    // No custom booking CARD from the website echo; the Fresha link is
    // proposed (2026-09-06: booking never connects outright, only suggests).
    // Scoped away from fresha/square explicitly: routing_class 'booking' spans
    // the whole family including those two, and the Fresha intent is exactly
    // what the next line asserts exists.
    expect(IntegrationConnection::query()->where('user_id', $user->id)
        ->where('routing_class', 'booking')
        ->whereNotIn('platform', ['fresha', 'square'])->exists())->toBeFalse();
    $fresha = DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'fresha.book')->firstOrFail();
    expect($fresha->state)->toBe('proposed');
    expect($fresha->canonical_url)->toBe('https://www.fresha.com/a/brother-wolf');
});

it('drops a same-domain appointment link to a Note, same as the sign-up lane already accepted (direct.book has no projector rule)', function () {
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

    // Pre-2026-09-06 this became a live `direct.book` connection outright —
    // the same "harvest connects with no accept step" shape as the Square/
    // Fresha/GlossGenius bug, now closed the same way: routed through
    // LinkRouter::routeBooking() instead of written directly. BUT `direct.book`
    // (owner ruling 2026-08-16: "the booking page no brand claims") has no
    // catalog projector rule — nothing in the URL alone says "this is a
    // booking page", only GB's own same-domain-as-website heuristic knows
    // that, and the catalog can't see it. So the suggestion pipeline can't
    // place OR propose it either; it lands as a bare Note, exactly the
    // limitation routeSignupFindings()'s own docblock already accepts for
    // the sign-up lane ("a brandless custom booking link has no projector
    // rule, so on this lane it becomes a Note observation rather than a
    // card"). Net effect: no live connection, no suggestion card, no
    // routing.source_intents row — a known gap (needs a catalog change to
    // close, not a GoogleBusinessAutoSync one), not something this test
    // pretends is fixed.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'booking')->exists())->toBeFalse();
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('routing_class', 'booking')->exists())->toBeFalse();
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

    // The reservation is a proposed intent, not a live connection (2026-09-06:
    // reservations never connect outright, only suggest — same as booking).
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'opentable')->exists())->toBeFalse();
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'opentable.reserve')->where('state', 'proposed')->exists())->toBeTrue();

    // ...and social still seeds a real connection (via Instagram specifically
    // — dispatchInstagram()'s own, deliberately-direct budget-metered path).
    // A1 (2026-09-06): ordering no longer does — same fix as reservations/
    // booking above — it shows up as a proposed intent instead.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->pluck('routing_class')->unique())
        ->toContain('social')
        ->not->toContain('ordering');
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('routing_class', 'ordering')->where('state', 'proposed')->exists())->toBeTrue();
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

    // The empty slots (ordering) still get proposed — A1 (2026-09-06): not as
    // live connections any more, same fix as reservations/booking.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'ordering')->exists())->toBeFalse();
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('routing_class', 'ordering')->where('state', 'proposed')->count())->toBe(2);
});

it('still drops a legacy /pages/ Facebook link to nothing (G4-4\'s fix is now dead: nothing bypasses the catalog to use it)', function () {
    // G4-4 originally fixed socialUsername() to delegate to FacebookNormalizer
    // instead of running its own reserved-segment-blind regex, so the
    // extracted username would be "DOC-Pizza-Carlton", not "pages". That fix
    // mattered because this lane used to WRITE the connection directly. As of
    // 2026-09-06 nothing here writes directly any more — every social link
    // takes LinkRouter::routeSocial()'s suggestion-pipeline door, and the
    // catalog's own facebook.profile detector reserves 'pages' on purpose
    // (Facebook.php), so a /pages/<Name>/<id> link has no projector rule to
    // place OR suggest it through at all. See the enrichment-side sibling
    // test's docblock (GoogleBusinessApifyTest) for the full reasoning.
    $user = gbApifyUser('gbafbpages');

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, [
        'socials' => ['facebook' => 'https://www.facebook.com/pages/DOC-Pizza-Carlton/12345'],
    ], 'DOC Pizza');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->exists())->toBeFalse();
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'facebook.profile')->exists())->toBeFalse();
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

// Sign-up preview (2026-09-02, A.5): reviewSamples on the STAGE_LISTING note.
// gbApifyItem() carries no `reviews` key, matching the real pre-claim path
// (GoogleBusinessPayload::stripThirdPartyPii drops reviews before the
// provisional write) — so this is the empty-array branch.
it('lands an empty reviewSamples on the STAGE_LISTING sign-up-preview note pre-claim', function () {
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    $user = gbApifyUser('gb-listing-preview');
    gbApifyConnection($user);
    $build = PreAccountBuild::factory()->make(['source_type' => 'google_business']);
    $build->user()->associate($user);
    $build->save();

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    $event = PreAccountBuildEvent::query()->where('build_id', $build->id)->where('stage', PreAccountBuildEvent::STAGE_LISTING)->where('status', 'landed')->firstOrFail();
    expect($event->payload['reviewSamples'])->toBe([]);
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
            'booking' => ['https://calendly.com/fadelab'],
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

it('connects exactly what this run found, and nothing the account cannot have', function () {
    config(['services.apify.token' => 'apify-token']);
    Http::fake(['api.apify.com/*' => Http::response([gbApifyItem()], 201)]);
    Bus::fake([InstagramConnectJob::class]);
    // Food sector: this test asserts reservation + online-ordering findings.
    $user = gbApifyUser('gbsync1', sector: 'restaurant');
    gbApifyConnection($user);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), emptyHarvester());

    $rows = IntegrationConnection::query()->where('user_id', $user->id)->get();

    // Link-only socials (facebook) are proposed, not connected (2026-09-06:
    // same fix as reservations — a Google Business social link is a
    // harvested discovery, not a direct request). Instagram is untouched by
    // this fix (its own Apify-budget-gated flow, out of scope here) and
    // still connects a pending placeholder while its scrape sits behind the
    // faked bus.
    // Ordering is ALSO proposed, not connected — A1 (2026-09-06), same fix.
    expect($rows->firstWhere('platform', 'opentable'))->toBeNull()
        ->and($rows->firstWhere('platform', 'facebook'))->toBeNull()
        ->and($rows->firstWhere('platform', 'instagram')->last_refresh_status)->toBe('pending')
        ->and($rows->where('routing_class', 'ordering'))->toHaveCount(0);
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'opentable.reserve')->where('state', 'proposed')->exists())->toBeTrue();
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'facebook.profile')->where('state', 'proposed')->exists())->toBeTrue();
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('routing_class', 'ordering')->where('state', 'proposed')->count())->toBe(2);
    // Nothing booking-class: a food business books via Reservations (above),
    // even though Google's data also carried a booking link.
    expect($rows->where('routing_class', 'booking'))->toHaveCount(0);
});

it('offers an already-connected platform as a Swap in the inbox, and swaps it in on accept', function () {
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

    // The inbox asks about Facebook (the manual one was left alone).
    $fb = collect(actingAsUser($user)->getJson('/api/routing/suggestions')->json('suggestions'))
        ->firstWhere('id', 'sync:google-business:facebook');
    expect($fb['actions'])->toBe(['replace', 'dismiss'])
        ->and($fb['url'])->toBe('https://facebook.com/fadelab')
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload['source'])->toBe('manual');

    // Swap puts Google's in.
    actingAsUser($user)->postJson('/api/routing/suggestions/sync:google-business:facebook/accept')->assertOk();
    $row = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail();
    expect($row->payload['source'])->toBe('google-business')
        ->and($row->payload['url'])->toBe('https://facebook.com/fadelab');
    // Answered, so it stops being asked.
    expect(collect(actingAsUser($user)->getJson('/api/routing/suggestions')->json('suggestions'))
        ->firstWhere('id', 'sync:google-business:facebook'))->toBeNull();
});

it('re-runs the Instagram scrape when swapping an existing Instagram', function () {
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

    expect(collect(actingAsUser($user)->getJson('/api/routing/suggestions')->json('suggestions'))
        ->firstWhere('id', 'sync:google-business:instagram'))->not->toBeNull();
    Bus::assertNotDispatched(InstagramConnectJob::class);   // slot was taken — no scrape spent

    actingAsUser($user)->postJson('/api/routing/suggestions/sync:google-business:instagram/accept')->assertOk();
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
