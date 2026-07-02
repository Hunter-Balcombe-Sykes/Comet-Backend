<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function gbDetailsUser(string $h): User
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

/** A representative Place Details (New) response covering every mapped field. */
function gbPlaceDetailsResponse(): array
{
    return [
        'id' => 'ChIJtest',
        'displayName' => ['text' => 'Fade Lab Barbers'],
        'formattedAddress' => '12 Example St, Melbourne VIC 3000',
        'location' => ['latitude' => -37.8123, 'longitude' => 144.9601],
        'googleMapsUri' => 'https://maps.google.com/?cid=123',
        'googleMapsLinks' => [
            'writeAReviewUri' => 'https://example.google/writereview',
            'reviewsUri' => 'https://example.google/reviews',
            'directionsUri' => 'https://example.google/directions',
        ],
        'businessStatus' => 'OPERATIONAL',
        'primaryTypeDisplayName' => ['text' => 'Barber shop'],
        'nationalPhoneNumber' => '(03) 9123 4567',
        'internationalPhoneNumber' => '+61 3 9123 4567',
        'websiteUri' => 'https://fadelab.example',
        'rating' => 4.8,
        'userRatingCount' => 127,
        'utcOffsetMinutes' => 600,
        'regularOpeningHours' => [
            'weekdayDescriptions' => ['Monday: 9:00 AM – 5:00 PM'],
            'periods' => [['open' => ['day' => 1, 'hour' => 9, 'minute' => 0], 'close' => ['day' => 1, 'hour' => 17, 'minute' => 0]]],
        ],
        'currentOpeningHours' => [
            'weekdayDescriptions' => ["Monday: Closed (King's Birthday)", 'Tuesday: 9:00 AM – 5:00 PM'],
        ],
        'postalAddress' => [
            'addressLines' => ['12 Example St'],
            'locality' => 'Melbourne',
            'administrativeArea' => 'VIC',
            'postalCode' => '3000',
            'regionCode' => 'AU',
        ],
        'editorialSummary' => ['text' => 'A neighbourhood barber shop.'],
        'reviewSummary' => ['text' => ['text' => 'People love the fades.']],
        'reviews' => array_map(fn (int $i) => [
            'rating' => 5,
            'text' => ['text' => "Review {$i}"],
            'relativePublishTimeDescription' => "{$i} weeks ago",
            'publishTime' => '2026-05-01T00:00:00Z',
            'authorAttribution' => [
                'displayName' => "Reviewer {$i}",
                'uri' => "https://maps.google.com/contrib/{$i}",
                'photoUri' => "https://lh3.example/{$i}.jpg",
            ],
        ], range(1, 7)),   // 7 in the response — mapper caps at 5
        'photos' => array_map(fn (int $i) => [
            'name' => "places/ChIJtest/photos/photo-{$i}",
            'widthPx' => 4000,
            'heightPx' => 3000,
            'authorAttributions' => [['displayName' => "Author {$i}"]],
        ], range(1, 12)),  // 12 in the response — mapper caps at 10
        'outdoorSeating' => false,
        'goodForChildren' => true,
        'paymentOptions' => ['acceptsCreditCards' => true],
        'servesCoffee' => true,
    ];
}

// ── Connect-time enrichment ──────────────────────────────────────────────────

it('enriches a picker connect with the place details snapshot', function () {
    config(['services.google_maps.server_api_key' => 'server-key']);
    Http::fake([
        // Order matters: specific media + street view patterns before the
        // details catch-all.
        'places.googleapis.com/v1/places/*/photos/*' => Http::response(['photoUri' => 'https://lh3.example/resolved.jpg']),
        'maps.googleapis.com/maps/api/streetview/metadata*' => Http::response([
            'status' => 'OK', 'pano_id' => 'pano123', 'location' => ['lat' => -37.8123, 'lng' => 144.9601],
        ]),
        'places.googleapis.com/*' => Http::response(gbPlaceDetailsResponse()),
    ]);
    $user = gbDetailsUser('gbd1');

    $res = actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJtest',
        'name' => 'Fade Lab',
        'address' => 'old picker address',
        'lat' => -37.0,
        'lng' => 144.0,
    ])
        ->assertOk()
        // Details are authoritative over the picker fields.
        ->assertJsonPath('name', 'Fade Lab Barbers')
        ->assertJsonPath('address', '12 Example St, Melbourne VIC 3000')
        ->assertJsonPath('url', 'https://maps.google.com/?cid=123')
        ->assertJsonPath('placeId', 'ChIJtest')
        ->assertJsonPath('rating', 4.8)
        ->assertJsonPath('reviewCount', 127)
        ->assertJsonPath('businessStatus', 'OPERATIONAL')
        ->assertJsonPath('category', 'Barber shop')
        ->assertJsonPath('phone', '(03) 9123 4567')
        ->assertJsonPath('website', 'https://fadelab.example')
        ->assertJsonPath('links.writeReview', 'https://example.google/writereview')
        ->assertJsonPath('hours.utcOffsetMinutes', 600);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'places.googleapis.com/v1/places/ChIJtest')
        && $request->hasHeader('X-Goog-Api-Key', 'server-key')
        && $request->hasHeader('X-Goog-FieldMask'));

    // The stored payload keeps the internal-only keys too…
    $payload = IntegrationConnection::query()
        ->where('user_id', $user->id)->where('platform', 'google-business')
        ->firstOrFail()->payload;
    expect($payload['reviews'])->toHaveCount(5);    // capped
    expect($payload['photos'])->toHaveCount(10);    // capped
    expect($payload['photos'][0]['url'])->toBe('https://lh3.example/resolved.jpg');    // resolved media URL
    expect($payload['streetView']['panoId'])->toBe('pano123');
    expect($payload['currentHours']['weekdays'])->toHaveCount(2);   // holiday-aware variant
    expect($payload['addressParts']['postcode'])->toBe('3000');
    expect($payload['amenities']['outdoorSeating'])->toBeFalse();   // false ≠ absent
    expect($payload['amenities']['serves']['coffee'])->toBeTrue();
    expect($payload['reviewSummary'])->toBe('People love the fades.');
    expect($payload)->toHaveKey('detailsFetchedAt');

    // …and the connect response (dashboard shape) carries the gallery +
    // street view too. The PUBLIC allowlist still strips both.
    expect($res->json('photos.0.url'))->toBe('https://lh3.example/resolved.jpg');
    expect($res->json('streetView.panoId'))->toBe('pano123');
});

it('keeps the plain picker selection when no server key is configured', function () {
    config(['services.google_maps.server_api_key' => null]);
    Http::fake();
    $user = gbDetailsUser('gbd2');

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJplain', 'name' => 'Plain Place', 'lat' => -37.0, 'lng' => 144.0,
    ])
        ->assertOk()
        ->assertJsonPath('name', 'Plain Place')
        ->assertJsonMissingPath('rating');

    Http::assertNothingSent();
});

it('keeps the picker selection when the details fetch fails', function () {
    config(['services.google_maps.server_api_key' => 'server-key']);
    Http::fake(['places.googleapis.com/*' => Http::response(['error' => 'boom'], 500)]);
    $user = gbDetailsUser('gbd3');

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJfail', 'name' => 'Still Works', 'lat' => -37.0, 'lng' => 144.0,
    ])
        ->assertOk()
        ->assertJsonPath('name', 'Still Works')
        ->assertJsonMissingPath('rating');
});

// ── Refresh cron ─────────────────────────────────────────────────────────────

it('refreshes a stale google business connection via place details', function () {
    config(['services.google_maps.server_api_key' => 'server-key']);
    Http::fake([
        'places.googleapis.com/v1/places/*/photos/*' => Http::response(['photoUri' => 'https://lh3.example/resolved.jpg']),
        // No outdoor pano here — exercises the streetView-absent path.
        'maps.googleapis.com/maps/api/streetview/metadata*' => Http::response(['status' => 'ZERO_RESULTS']),
        'places.googleapis.com/*' => Http::response(gbPlaceDetailsResponse()),
    ]);
    $user = gbDetailsUser('gbd4');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => ['url' => 'https://old.example', 'placeId' => 'ChIJtest', 'name' => 'Old Name', 'lat' => -37.0, 'lng' => 144.0],
        'last_refreshed_at' => now()->subWeek(),
    ]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    $conn->refresh();
    expect($conn->last_refresh_status)->toBe('ok');
    expect($conn->payload['name'])->toBe('Fade Lab Barbers');
    expect($conn->payload['rating'])->toBe(4.8);
    expect($conn->payload['placeId'])->toBe('ChIJtest');    // preserved through the merge
    expect($conn->payload['photos'][0]['url'])->toBe('https://lh3.example/resolved.jpg');
    expect($conn->payload)->not->toHaveKey('streetView');   // ZERO_RESULTS → absent
    expect($conn->payload)->toHaveKey('detailsFetchedAt');
});

it('skips the google api while the snapshot is fresh', function () {
    config(['services.google_maps.server_api_key' => 'server-key']);
    Http::fake();
    $user = gbDetailsUser('gbd5');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => [
            'url' => 'https://maps.google.com/?cid=1', 'placeId' => 'ChIJtest', 'name' => 'Fade Lab',
            'rating' => 4.5, 'detailsFetchedAt' => now()->subDay()->toIso8601String(),
        ],
        'last_refreshed_at' => now()->subWeek(),
    ]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    $conn->refresh();
    expect($conn->last_refresh_status)->toBe('ok');
    expect($conn->payload['rating'])->toBe(4.5);    // untouched
    Http::assertNothingSent();
});

it('records legacy link connections without a placeId as unavailable, quietly', function () {
    config(['services.google_maps.server_api_key' => 'server-key']);
    Http::fake();
    $user = gbDetailsUser('gbd6');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => ['url' => 'https://www.google.com/maps/place/Fade+Lab/@-37.81,144.96,17z', 'name' => 'Fade Lab', 'lat' => -37.81, 'lng' => 144.96],
        'last_refreshed_at' => now()->subWeek(),
    ]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    $conn->refresh();
    expect($conn->last_refresh_status)->toBe('unavailable');
    expect($conn->last_refresh_error)->toBe('missing_place_id');
    expect($conn->payload['name'])->toBe('Fade Lab');   // last-known-good kept
    Http::assertNothingSent();
});

// ── Public allowlist ─────────────────────────────────────────────────────────

it('exposes enrichment on the public endpoint but strips internal keys', function () {
    $user = gbDetailsUser('gbd7');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => [
            'url' => 'https://maps.google.com/?cid=1',
            'name' => 'Fade Lab',
            'address' => '12 Example St',
            'lat' => -37.8,
            'lng' => 144.9,
            'rating' => 4.8,
            'reviewCount' => 127,
            'businessStatus' => 'OPERATIONAL',
            'category' => 'Barber shop',
            'phone' => '(03) 9123 4567',
            'website' => 'https://fadelab.example',
            'hours' => ['weekdays' => ['Monday: 9:00 AM – 5:00 PM']],
            'links' => ['writeReview' => 'https://example.google/writereview'],
            'reviews' => [['author' => 'Reviewer 1', 'rating' => 5, 'text' => 'Great cut']],
            'reviewSummary' => 'People love the fades.',
            'editorialSummary' => 'A neighbourhood barber shop.',
            'amenities' => ['goodForChildren' => true],
            // Photos are public too — the sitepage home background renders them
            // (stored as resolved Google CDN URLs). Made public in b4d43135.
            'photos' => [['url' => 'https://lh3.googleusercontent.com/p/example']],
            // internal-only:
            'placeId' => 'ChIJsecret',
            'phoneIntl' => '+61 3 9123 4567',
            'priceLevel' => 'PRICE_LEVEL_MODERATE',
            'priceRange' => ['startPrice' => ['units' => '20']],
            'detailsFetchedAt' => '2026-06-01T00:00:00+00:00',
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $payload = $this->getJson('/api/public/profiles/gbd7/integrations')
        ->assertOk()
        ->json('data.platforms.google-business.0.payload');

    expect($payload['rating'])->toBe(4.8);
    expect($payload['reviewCount'])->toBe(127);
    expect($payload['reviews'])->toHaveCount(1);
    expect($payload['links'])->toHaveKey('writeReview');
    expect($payload['hours']['weekdays'])->toHaveCount(1);
    expect($payload['amenities']['goodForChildren'])->toBeTrue();
    expect($payload['photos'])->toHaveCount(1);   // public: home-background source
    foreach (['placeId', 'phoneIntl', 'priceLevel', 'priceRange', 'detailsFetchedAt'] as $private) {
        expect($payload)->not->toHaveKey($private);
    }
});
