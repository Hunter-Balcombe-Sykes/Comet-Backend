<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// FI-15 (2026-08-20, T9 live): after seven listing swaps the ST. ALi
// workplace still carried Kings Domain's description — machine-sourced
// workplace fields survived the disconnect and every later listing
// inherited them. Disconnecting the listing clears fields whose
// field_sources stamp names a machine source; user-typed values stay.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

it('clears machine-sourced workplace fields when the Google Business listing disconnects', function () {
    $pro = createTenant('fi15-swap', ['account_type' => 'business']);
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    $workplace = new Workplace([
        'description' => 'Superior Cuts, Fit for a King.',
        'previous_website' => 'https://kingsdomain.com.au/',
        'category' => 'Barber shop',
    ]);
    // site_id is not mass-assignable (#SEC-17) — set explicitly.
    $workplace->site_id = (string) $site->id;
    // field_sources is deliberately NOT fillable — system-written property.
    $workplace->field_sources = [
        'description' => ['source' => 'website-scan', 'at' => now()->toIso8601String()],
        'previous_website' => ['source' => 'google-business', 'at' => now()->toIso8601String()],
        // category typed by the USER — no machine stamp — must survive.
    ];
    $workplace->save();

    $connection = new IntegrationConnection([
        'surface_key' => 'google_business.listing', 'routing_class' => 'social',
        'resource_id' => 'google-business', 'payload' => ['name' => 'Kings Domain'], 'is_active' => true,
    ]);
    $connection->user_id = $pro->id;
    $connection->save();

    $connection->delete();

    $workplace = Workplace::query()->where('site_id', (string) $site->id)->firstOrFail();
    expect($workplace->description)->toBeNull()
        ->and($workplace->previous_website)->toBeNull()
        ->and($workplace->category)->toBe('Barber shop')
        ->and($workplace->field_sources)->not->toHaveKey('description');
});

/** The retest follow-up's connection fixture: one row per provenance class. */
function fi15cConnection(string $userId, string $platform, string $surfaceKey, array $payload): IntegrationConnection
{
    $connection = new IntegrationConnection([
        'surface_key' => $surfaceKey, 'routing_class' => 'social',
        'resource_id' => $platform, 'payload' => $payload, 'is_active' => true,
    ]);
    $connection->user_id = $userId;
    $connection->platform = $platform;
    $connection->save();

    return $connection;
}

it('takes listing-sourced connections with the listing — machine website_import included, user rows kept', function () {
    // Retest 2026-08-20: after the seven T9 swaps the ST. ALi account still
    // held Kings Domain's fresha/facebook plus another business's ordering
    // rows. Machine-created connections leave with the listing that seeded
    // them; the website_import rows leave too because previous_website itself
    // was machine-stamped here.
    //
    // Queue::fake(): saving a workplace with a previous_website dispatches
    // ScanPreviousWebsiteContentJob, whose run (sync driver in tests) really
    // fetched kingsdomain.com.au and seeded a real fresha row mid-test.
    // Faking the queue keeps every dispatched job un-run.
    Queue::fake();
    $pro = createTenant('fi15c-machine', ['account_type' => 'business']);
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    $workplace = new Workplace(['previous_website' => 'https://kingsdomain.com.au/']);
    // site_id is not mass-assignable (#SEC-17) — set explicitly.
    $workplace->site_id = (string) $site->id;
    $workplace->field_sources = ['previous_website' => ['source' => 'google-business', 'at' => now()->toIso8601String()]];
    $workplace->save();

    $listing = fi15cConnection($pro->id, 'google-business', 'google_business.listing', ['name' => 'Kings Domain']);
    $fresha = fi15cConnection($pro->id, 'fresha', 'fresha.book', ['source' => 'google-business', 'url' => 'https://fresha.com/kings-domain']);
    $spotify = fi15cConnection($pro->id, 'spotify', 'spotify.player', ['source' => 'website_import', 'url' => 'https://open.spotify.com/playlist/x']);
    $manual = fi15cConnection($pro->id, 'instagram', 'instagram.profile', ['username' => 'myown']);

    $listing->delete();

    expect(IntegrationConnection::query()->whereKey($fresha->getKey())->exists())->toBeFalse()
        ->and(IntegrationConnection::query()->whereKey($spotify->getKey())->exists())->toBeFalse()
        ->and(IntegrationConnection::query()->whereKey($manual->getKey())->exists())->toBeTrue();
});

it('keeps website_import connections when the previous website was typed by the owner', function () {
    Queue::fake();
    $pro = createTenant('fi15c-user', ['account_type' => 'business']);
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    // No machine stamp on previous_website — the owner typed it in, so the
    // content scan it fed belongs to THEM, not to the listing.
    $workplace = new Workplace(['previous_website' => 'https://my-own-site.example/']);
    // site_id is not mass-assignable (#SEC-17) — set explicitly.
    $workplace->site_id = (string) $site->id;
    $workplace->save();

    $listing = fi15cConnection($pro->id, 'google-business', 'google_business.listing', ['name' => 'Kings Domain']);
    $youtube = fi15cConnection($pro->id, 'youtube', 'youtube.channel', ['source' => 'website_import', 'url' => 'https://youtube.com/@me']);
    $tiktok = fi15cConnection($pro->id, 'tiktok', 'tiktok.profile', ['source' => 'google-business', 'url' => 'https://tiktok.com/@biz']);

    $listing->delete();

    expect(IntegrationConnection::query()->whereKey($youtube->getKey())->exists())->toBeTrue()
        ->and(IntegrationConnection::query()->whereKey($tiktok->getKey())->exists())->toBeFalse();
});

// PWL-1 ("Select your workplace" replacing rather than offering both,
// 2026-09-05): a picker connect that swaps to a DIFFERENT business must not
// silently drop the one that was there — it preserves the outgoing listing
// as a site.workplace_candidates row (source 'previously_connected') so the
// listing pass can offer it back. Written while migrating this read off raw
// payload-array access onto GoogleBusinessPayload (2026-09-06) — no test
// exercised this branch, and the migration also caught a real CHECK-
// constraint gap: 'previously_connected' was never added to the column's
// vocabulary, so every real swap 500'd on insert until
// 20260906090000_workplace_candidates_previously_connected_source.sql.
it('preserves the outgoing Google Business listing as a workplace candidate when the picker swaps businesses', function () {
    config(['services.google_maps.server_api_key' => 'server-key', 'services.apify.token' => null]);
    Http::fake([
        'maps.googleapis.com/maps/api/streetview/metadata*' => Http::response(['status' => 'ZERO_RESULTS']),
        'places.googleapis.com/*' => Http::response([
            'id' => 'ChIJnew', 'displayName' => ['text' => 'New Spot'], 'location' => ['latitude' => -37.8, 'longitude' => 144.96],
        ]),
    ]);
    $user = User::create([
        'handle' => 'gbswap1', 'handle_lc' => 'gbswap1', 'display_name' => 'Gbswap1', 'first_name' => 'Gbswap1',
        'account_type' => 'business', 'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'gbswap1@example.com',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => [
            'name' => 'Old Spot', 'address' => '1 Old St, Melbourne VIC', 'lat' => -37.81, 'lng' => 144.95,
            'rating' => 4.5, 'reviewCount' => 120,
            'photos' => [['ref' => 'ref1', 'url' => 'https://example.com/old-photo.jpg']],
        ],
        'place_id' => 'ChIJold', 'apify_status' => null, 'last_refreshed_at' => now(),
    ]);

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJnew', 'name' => 'New Spot', 'lat' => -37.8, 'lng' => 144.96,
    ])->assertOk();

    $candidate = DB::table('site.workplace_candidates')
        ->where('user_id', $user->id)->where('place_id', 'ChIJold')->first();
    expect($candidate)->not->toBeNull()
        ->and($candidate->name)->toBe('Old Spot')
        ->and($candidate->address)->toBe('1 Old St, Melbourne VIC')
        ->and((float) $candidate->lat)->toBe(-37.81)
        ->and((float) $candidate->lng)->toBe(144.95)
        ->and((float) $candidate->rating)->toBe(4.5)
        ->and((int) $candidate->review_count)->toBe(120)
        ->and($candidate->photo_url)->toBe('https://example.com/old-photo.jpg')
        ->and($candidate->source)->toBe('previously_connected')
        ->and($candidate->state)->toBe('proposed');

    $conn = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'google-business')->firstOrFail();
    expect($conn->place_id)->toBe('ChIJnew');
});

it('does not duplicate the outgoing candidate row on a second swap away from the same old business', function () {
    config(['services.google_maps.server_api_key' => 'server-key', 'services.apify.token' => null]);
    Http::fake([
        'maps.googleapis.com/maps/api/streetview/metadata*' => Http::response(['status' => 'ZERO_RESULTS']),
        'places.googleapis.com/*' => Http::response([
            'id' => 'ChIJnew2', 'displayName' => ['text' => 'Another Spot'], 'location' => ['latitude' => -37.7, 'longitude' => 144.9],
        ]),
    ]);
    $user = User::create([
        'handle' => 'gbswap2', 'handle_lc' => 'gbswap2', 'display_name' => 'Gbswap2', 'first_name' => 'Gbswap2',
        'account_type' => 'business', 'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'gbswap2@example.com',
    ]);
    DB::table('site.workplace_candidates')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'place_id' => 'ChIJold2', 'name' => 'Old Spot 2',
        'source' => 'previously_connected', 'corroboration' => json_encode([]), 'state' => 'proposed', 'created_at' => now(),
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['name' => 'Old Spot 2'], 'place_id' => 'ChIJold2', 'apify_status' => null, 'last_refreshed_at' => now(),
    ]);

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJnew2', 'name' => 'Another Spot', 'lat' => -37.7, 'lng' => 144.9,
    ])->assertOk();

    expect(DB::table('site.workplace_candidates')->where('user_id', $user->id)->where('place_id', 'ChIJold2')->count())->toBe(1);
});
