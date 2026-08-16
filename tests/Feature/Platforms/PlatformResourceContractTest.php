<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\EventsPayload;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\YoutubeScraper;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Task 7: GET /api/platforms/shop/brands now reads content.storefronts
    // (ShopContentReader) with a fallback to the legacy site.shop_brands map
    // — attach the stand-in schema so the content.* half of that read
    // doesn't 500 on SQLite's real absence of the table.
    setupContentTables();
});

function platformContractUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

// Seed a stored selection row for the given platform/payload.
function seedPlatformConnection(User $user, string $platform, array $payload, ?string $resourceId = null): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => $resourceId ?? $platform,
        'payload' => $payload,
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

/**
 * Lands one Fresha-shaped content.* service row — Task 12, slice 3b:
 * services[] moved from a verbatim stored-blob pass-through to a fixed
 * nine-key projection reproduced from content.* (FreshaServiceItems). The
 * fresha selection contract test below used to assert a 2-key pass-through
 * entry; that shape is now categorically unreachable (the reader always
 * emits all nine keys), so its `services` expectation was updated to match
 * — this landed row is what makes the new expectation real rather than
 * hand-typed. 5000 minor + 'AUD' -> displayPrice() renders '$50'; 1800
 * seconds -> displayDuration() renders '30min'; no description -> null.
 * File-local (not shared with other Fresha test files' identically-purposed
 * helpers) because `pest` invoked against a single file path does not parse
 * sibling test files.
 */
function landFreshaContractService(string $userId, string $serviceId = 's:1', string $name = 'Cut'): void
{
    $now = now();
    $sourceId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => null, 'label' => 'Fresha', 'priority' => 100,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $itemId = addItem($userId, 'service', $name);

    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => "fresha:store:{$serviceId}", 'record_key' => $serviceId,
        'item_id' => $itemId, 'kind' => 'service', 'projector_version' => 1,
        'first_seen_at' => $now, 'last_seen_at' => $now,
    ]);

    DB::table('content.offers')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'channel' => 'fresha', 'qualifier' => 'exact', 'amount_minor' => 5000,
        'currency' => 'AUD', 'updated_at' => $now,
    ]);

    DB::table('content.f_duration')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId, 'seconds' => 1800, 'updated_at' => $now,
    ]);

    $collectionId = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $collectionId, 'user_id' => $userId, 'parent_id' => null,
        'label' => 'Cuts', 'kind' => 'service_category', 'external_ref' => $serviceId.'-cat',
        'position' => 0, 'is_user_created' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId, 'source_id' => $sourceId, 'position' => 0,
    ]);
}

// ── Facebook / TikTok (LinkConnectionResource) ───────────────────────────────

it('facebook connect returns exactly {username,url}', function () {
    actingAsUser(platformContractUser('fb1'))
        ->postJson('/api/platforms/facebook/connect', ['username' => 'jane.doe'])
        ->assertOk()
        ->assertExactJson([
            'username' => 'jane.doe',
            'url' => 'https://www.facebook.com/jane.doe',
        ]);
});

it('facebook selection wraps the stored payload and strips unknown keys', function () {
    $user = platformContractUser('fb2');
    seedPlatformConnection($user, 'facebook', [
        'username' => 'jane.doe',
        'url' => 'https://www.facebook.com/jane.doe',
        '_internal' => 'leak', // not on the allowlist
    ]);

    actingAsUser($user)->getJson('/api/platforms/facebook/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'username' => 'jane.doe',
            'url' => 'https://www.facebook.com/jane.doe',
        ]]);
});

it('tiktok connect returns exactly {username,url}', function () {
    actingAsUser(platformContractUser('tk1'))
        ->postJson('/api/platforms/tiktok/connect', ['username' => '@dancer'])
        ->assertOk()
        ->assertExactJson([
            'username' => 'dancer',
            'url' => 'https://www.tiktok.com/@dancer',
        ]);
});

it('tiktok selection wraps the stored payload and strips unknown keys', function () {
    $user = platformContractUser('tk2');
    seedPlatformConnection($user, 'tiktok', [
        'username' => 'dancer',
        'url' => 'https://www.tiktok.com/@dancer',
        '_internal' => 'leak',
    ]);

    actingAsUser($user)->getJson('/api/platforms/tiktok/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'username' => 'dancer',
            'url' => 'https://www.tiktok.com/@dancer',
        ]]);
});

// ── YouTube (TileConnectionResource) ─────────────────────────────────────────

it('youtube connect returns the canonical tile shape with latest passed through verbatim', function () {
    $user = platformContractUser('yt1');
    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('normalizeHandle')->andReturn('mychannel');
        $m->shouldReceive('fetchRecentVideos')->andReturn([
            ['videoId' => 'v1', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'date' => '2026-03-03T00:00:00+00:00', 'thumbnail' => 't'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/youtube/connect', ['channel' => '@mychannel'])
        ->assertOk()
        ->assertExactJson([
            // Multi-account platforms echo the account row id on connect.
            'id' => 'acct-'.substr(sha1('mychannel'), 0, 16),
            'handle' => 'mychannel',
            'name' => 'Vid',
            'description' => 'd',
            'link' => 'l',
            'thumbnail' => 't',
            // `date` rides through verbatim inside the nested latest tile.
            'latest' => ['videoId' => 'v1', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'date' => '2026-03-03T00:00:00+00:00', 'thumbnail' => 't'],
        ]);
});

it('youtube selection strips unknown top-level keys but keeps nested latest verbatim', function () {
    $user = platformContractUser('yt2');
    seedPlatformConnection($user, 'youtube', [
        'handle' => 'mychannel', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't',
        'latest' => ['videoId' => 'v1', 'extraNested' => 'kept'], 'highlights' => [['videoId' => 'stale']],
        '_internal' => 'leak',
    ]);

    actingAsUser($user)->getJson('/api/platforms/youtube/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'handle' => 'mychannel', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't',
            'latest' => ['videoId' => 'v1', 'extraNested' => 'kept'],
        ]]);
});

// ── Apple Music + Podcast (Tile subclasses) ──────────────────────────────────

it('apple music + podcast connect return their per-platform flat fields', function () {
    $user = platformContractUser('apl1');
    $this->mock(AppleSearch::class, function ($m) {
        $m->shouldReceive('fetchAlbums')->andReturn([
            ['collectionId' => 'a1', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l'],
        ]);
        $m->shouldReceive('fetchEpisodes')->andReturn([
            ['trackId' => 'e1', 'name' => 'Ep', 'thumbnail' => 't', 'description' => 'd', 'releaseDate' => '2026-02-02T00:00:00Z', 'link' => 'l'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/apple/music/connect', ['artist' => 'Artist'])
        ->assertOk()
        ->assertExactJson([
            'id' => 'acct-'.substr(sha1('artist'), 0, 16),
            'input' => 'Artist', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l',
            'latest' => ['collectionId' => 'a1', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l'],
        ]);

    actingAsUser($user)->postJson('/api/platforms/apple/podcast/connect', ['show' => 'Show'])
        ->assertOk()
        ->assertExactJson([
            'id' => 'acct-'.substr(sha1('show'), 0, 16),
            'input' => 'Show', 'name' => 'Ep', 'thumbnail' => 't', 'description' => 'd', 'releaseDate' => '2026-02-02T00:00:00Z', 'link' => 'l',
            'latest' => ['trackId' => 'e1', 'name' => 'Ep', 'thumbnail' => 't', 'description' => 'd', 'releaseDate' => '2026-02-02T00:00:00Z', 'link' => 'l'],
        ]);
});

// ── Eventbrite (EventsPlatformController::accountData — bespoke, no Resource) ─

it('eventbrite connect returns the account shape with ids stamped on events', function () {
    $user = platformContractUser('eb1');
    $event = ['name' => 'Gig', 'startDate' => '2099-01-01T00:00:00+00:00', 'endDate' => '2099-01-02T00:00:00+00:00'];
    $this->mock(EventbriteScraper::class, function ($m) use ($event) {
        $m->shouldReceive('normalizeOrgUrl')->andReturn('https://www.eventbrite.com/o/acme-1');
        $m->shouldReceive('fetchEvents')->andReturn([
            'organiser' => 'Acme',
            'events' => [$event],
        ]);
    });

    $stamped = EventsPayload::withIds([$event])[0];

    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => 'https://www.eventbrite.com/o/acme-1'])
        ->assertOk()
        ->assertExactJson([
            'id' => 'acct-'.substr(sha1('https://www.eventbrite.com/o/acme-1'), 0, 16),
            'url' => 'https://www.eventbrite.com/o/acme-1',
            'organiser' => 'Acme',
            'next' => $stamped,
            'upcoming' => [$stamped],
        ]);
});

it('eventbrite selection filters past events, strips unknown keys, and exposes accounts + merged events', function () {
    $user = platformContractUser('eb2');
    $future = ['name' => 'Future', 'endDate' => '2099-01-02T00:00:00+00:00'];
    seedPlatformConnection($user, 'eventbrite', [
        'url' => 'https://www.eventbrite.com/o/acme-1',
        'organiser' => 'Acme',
        'next' => ['name' => 'Past', 'endDate' => '2000-01-01T00:00:00+00:00'],
        'upcoming' => [
            ['name' => 'Past', 'endDate' => '2000-01-01T00:00:00+00:00'],
            $future,
        ],
        '_internal' => 'leak',
    ]);

    $stamped = EventsPayload::withIds([$future])[0];
    // Merged events are tagged with their source account row.
    $merged = [...$stamped, 'source' => 'account', 'accountId' => 'eventbrite'];

    actingAsUser($user)->getJson('/api/platforms/eventbrite/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'accounts' => [[
                'id' => 'eventbrite',
                'url' => 'https://www.eventbrite.com/o/acme-1',
                'organiser' => 'Acme',
                'next' => $stamped,
                'upcoming' => [$stamped],
            ]],
            'events' => [$merged],
            // Legacy mirror keys (pre-multi-account consumers).
            'url' => 'https://www.eventbrite.com/o/acme-1',
            'organiser' => 'Acme',
            'next' => $merged,
            'upcoming' => [$merged],
        ]]);
});

// ── Humanitix (EventsPlatformController::accountData — bespoke, no Resource) ──
//
// TEST-2: Humanitix is key-identical to Eventbrite at the resource level but
// routes through the HumanitixController + HumanitixScraper subclass. Pinning
// its selection shape separately catches any future divergence between the two.

it('humanitix selection filters past events, strips unknown keys, and exposes accounts + merged events', function () {
    $user = platformContractUser('hum1');
    $future = ['name' => 'Humanitix Future', 'endDate' => '2099-06-02T00:00:00+00:00'];
    seedPlatformConnection($user, 'humanitix', [
        'url' => 'https://events.humanitix.com/host/acme',
        'organiser' => 'Acme Events',
        'next' => ['name' => 'Past', 'endDate' => '2000-01-01T00:00:00+00:00'],
        'upcoming' => [
            ['name' => 'Past', 'endDate' => '2000-01-01T00:00:00+00:00'],
            $future,
        ],
        '_internal' => 'leak',
    ]);

    $stamped = EventsPayload::withIds([$future])[0];
    // Merged events are tagged with their source account row.
    $merged = [...$stamped, 'source' => 'account', 'accountId' => 'humanitix'];

    actingAsUser($user)->getJson('/api/platforms/humanitix/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'accounts' => [[
                'id' => 'humanitix',
                'url' => 'https://events.humanitix.com/host/acme',
                'organiser' => 'Acme Events',
                'next' => $stamped,
                'upcoming' => [$stamped],
            ]],
            'events' => [$merged],
            // Legacy mirror keys (pre-multi-account consumers).
            'url' => 'https://events.humanitix.com/host/acme',
            'organiser' => 'Acme Events',
            'next' => $merged,
            'upcoming' => [$merged],
        ]]);
});

it('humanitix accounts list strips unknown keys and exposes the account shape', function () {
    $user = platformContractUser('hum2');
    $future = ['name' => 'Future Gig', 'endDate' => '2099-06-02T00:00:00+00:00'];
    seedPlatformConnection($user, 'humanitix', [
        'url' => 'https://events.humanitix.com/host/acme',
        'organiser' => 'Acme Events',
        'next' => $future,
        'upcoming' => [$future],
        '_internal' => 'leak',
    ], 'acct-'.substr(sha1('https://events.humanitix.com/host/acme'), 0, 16));

    $stamped = EventsPayload::withIds([$future])[0];

    actingAsUser($user)->getJson('/api/platforms/humanitix/accounts')
        ->assertOk()
        ->assertExactJson(['accounts' => [[
            'id' => 'acct-'.substr(sha1('https://events.humanitix.com/host/acme'), 0, 16),
            'url' => 'https://events.humanitix.com/host/acme',
            'organiser' => 'Acme Events',
            'next' => $stamped,
            'upcoming' => [$stamped],
        ]]]);
});

// ── Instagram (InstagramConnectionResource — drops internal _folder) ──────────

it('instagram selection drops the internal _folder key', function () {
    $user = platformContractUser('ig1');
    seedPlatformConnection($user, 'instagram', [
        'username' => 'jane', 'fullName' => 'Jane', 'profilePicUrl' => 'https://media.partna.au/p/profile.jpg',
        'businessCategory' => null, 'followersCount' => 10, 'postsCount' => 3,
        'mode' => 'automatic', 'images' => ['https://media.partna.au/p/img-0.jpg'],
        'videoUrl' => 'https://media.partna.au/p/reel.mp4', 'videoPoster' => 'https://media.partna.au/p/reel-cover.jpg', 'imagesDropped' => 0,
        '_folder' => 'platforms/instagram/123', // internal — must NOT be emitted
    ]);

    $selection = actingAsUser($user)->getJson('/api/platforms/instagram/selection')
        ->assertOk()
        ->json('selection');

    expect($selection)->not->toHaveKey('_folder');
    expect($selection)->toEqual([
        'username' => 'jane', 'fullName' => 'Jane', 'profilePicUrl' => 'https://media.partna.au/p/profile.jpg',
        'businessCategory' => null, 'followersCount' => 10, 'postsCount' => 3,
        'mode' => 'automatic', 'images' => ['https://media.partna.au/p/img-0.jpg'],
        'videoUrl' => 'https://media.partna.au/p/reel.mp4', 'videoPoster' => 'https://media.partna.au/p/reel-cover.jpg', 'imagesDropped' => 0,
    ]);
});

it('instagram connectStatus ready payload drops _folder', function () {
    $user = platformContractUser('ig2');
    seedPlatformConnection($user, 'instagram', [
        'username' => 'jane', 'fullName' => 'Jane', 'profilePicUrl' => null,
        'businessCategory' => null, 'followersCount' => 0, 'postsCount' => 0,
        'mode' => 'automatic', 'images' => [], 'imagesDropped' => 0,
        '_folder' => 'platforms/instagram/123',
    ]);

    $body = actingAsUser($user)->getJson('/api/platforms/instagram/connect/status')
        ->assertOk()
        ->json();

    expect($body['status'])->toBe('ready');
    expect($body['connection'])->not->toHaveKey('_folder');
});

// ── Shop (ShopBrandResource — single object + collection; provider-agnostic) ──

it('shopify addBrand returns the canonical brand object shape', function () {
    $user = platformContractUser('sh1');
    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probe')->andReturn(true);
        // W9: see ShopRelationalStorageTest's 'rel-brand' block — id mirrors fetchBrand()'s.
        $m->shouldReceive('probeMeta')->andReturn(['id' => 'brand-1', 'name' => 'Brand']);
        // W9 Unit 4: ShopBrandIdentity::for() now calls brandIdFrom($meta, $origin).
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 'brand-1', 'name' => 'Brand', 'currency' => 'AUD',
            'favicon' => 'https://b/favicon.ico', 'logo' => 'https://b/logo.png',
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://b.example.com'])
        ->assertOk()
        ->assertExactJson([
            'id' => 'brand-1',
            'provider' => 'shopify',
            'url' => 'https://b.example.com',
            'name' => 'Brand',
            'currency' => 'AUD',
            'favicon' => 'https://b/favicon.ico',
            'logo' => 'https://b/logo.png',
            'discountCode' => '',
            'selectionMode' => 'manual',
            // Task 8: addBrand()'s response is now built from ShopContentReader
            // (matching GET /brands) — linkMode is derived from site.sites.
            // shop_link_mode, and platformContractUser() has no site row, so
            // it falls to Site::DEFAULT_SHOP_LINK_MODE ('checkout') rather
            // than ShopBrand's own per-brand column default ('product'). Same
            // documented divergence as ShopEndpointParityTest's GET /brands.
            'linkMode' => 'checkout',
            'referralQuery' => '',
            'individual' => false,
            'products' => [],
        ]);
});

it('shopify brands list strips unknown per-brand keys', function () {
    $user = platformContractUser('sh2');
    // FOUND-25: brands are relational rows now — fixed columns mean there is no
    // "unknown key" to leak, but ShopBrandResource must still shape the row into
    // exactly this contract (no internal columns like source_url/fetch_mode).
    $conn = seedPlatformConnection($user, 'shopify.store', ['storage' => 'relational']);
    $brand = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'brand-1', 'provider' => 'shopify',
        'url' => 'https://b', 'name' => 'B', 'currency' => 'AUD',
        'favicon' => null, 'logo' => null, 'discount_code' => 'SAVE',
    ]);
    // Task 8: brands() now reads ShopContentReader with no legacy fallback
    // (hybridBrandMap() is gone) — land this fixture in content.* the same
    // way a real addBrand() connect would.
    app(ShopContentWriter::class)->upsertStore($brand, (string) $user->id);

    actingAsUser($user)->getJson('/api/platforms/shop/brands')
        ->assertOk()
        ->assertExactJson(['brands' => [[
            'id' => 'brand-1', 'provider' => 'shopify', 'url' => 'https://b', 'name' => 'B', 'currency' => 'AUD',
            'favicon' => null, 'logo' => null, 'discountCode' => 'SAVE',
            // Task 8: see the addBrand test above for why 'checkout'.
            'selectionMode' => 'manual', 'linkMode' => 'checkout', 'referralQuery' => '',
            'individual' => false, 'products' => [],
        ]]]);
});

// ── Fresha (FreshaSelectionResource) ─────────────────────────────────────────

it('fresha selection wraps the nested selection blob and strips unknown keys', function () {
    $user = platformContractUser('fr1');
    // Slice 3b Task 12 (fix round 3, controller ruling): services[] moved
    // from a verbatim stored-blob pass-through to a fixed nine-key
    // projection reproduced from content.* — the stored blob's own
    // `services` entry below is deliberately left in its OLD two-key
    // pass-through shape (what a pre-3b connection would still have on
    // disk) to prove the point: it is now IGNORED. What the response
    // actually returns comes from landFreshaContractService() below, not
    // from this array. This test's real subject — stripping the unknown
    // `_internal` key from the selection wrapper — is unchanged and still
    // enforced by the assertion below.
    seedPlatformConnection($user, 'fresha', [
        'url' => 'https://www.fresha.com/a/acme',
        'selection' => [
            'url' => 'https://www.fresha.com/a/acme',
            'storeName' => 'Acme',
            'employee' => ['employeeId' => 'e1', 'displayName' => 'Jo', 'jobTitle' => null, 'avatarUrl' => null, 'rating' => null],
            'services' => [['serviceId' => 's:1', 'name' => 'Cut']],
            'hiddenServiceIds' => [],
            '_internal' => 'leak',
        ],
    ]);
    landFreshaContractService($user->id);

    actingAsUser($user)->getJson('/api/platforms/fresha/selection')
        ->assertOk()
        ->assertExactJson([
            'selection' => [
                'url' => 'https://www.fresha.com/a/acme',
                'storeName' => 'Acme',
                'mode' => 'employee',
                'employee' => ['employeeId' => 'e1', 'displayName' => 'Jo', 'jobTitle' => null, 'avatarUrl' => null, 'rating' => null],
                // The nine-key shape genuinely reproduced from content.* via
                // landFreshaContractService() above — NOT the stored blob's
                // two-key entry seeded above, which this response no longer
                // reads at all.
                'services' => [[
                    'name' => 'Cut',
                    'price' => '$50',
                    'category' => 'Cuts',
                    'currency' => 'AUD',
                    'duration' => '30min',
                    'serviceId' => 's:1',
                    'priceValue' => 50,
                    'description' => null,
                    'hasVariants' => false,
                ]],
                'hiddenServiceIds' => [],
            ],
            // Also returns the stored url (for pending / Google-seeded connections
            // that have a url but no selection yet → the dashboard's "Finish setup").
            'url' => 'https://www.fresha.com/a/acme',
        ]);
});

it('fresha selection exposes storewide mode with a null employee', function () {
    $user = platformContractUser('fr3');
    seedPlatformConnection($user, 'fresha', [
        'url' => 'https://www.fresha.com/a/acme',
        'selection' => [
            'url' => 'https://www.fresha.com/a/acme',
            'storeName' => 'Acme',
            'mode' => 'storewide',
            'employee' => null,
            'services' => [['serviceId' => 's:1', 'name' => 'Cut']],
            'hiddenServiceIds' => [],
        ],
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/selection')
        ->assertOk()
        ->assertJsonPath('selection.mode', 'storewide')
        ->assertJsonPath('selection.employee', null);
});

it('fresha service-visibility returns the wrapped selection shape', function () {
    $user = platformContractUser('fr2');
    seedPlatformConnection($user, 'fresha', [
        'url' => 'https://www.fresha.com/a/acme',
        'selection' => [
            'url' => 'https://www.fresha.com/a/acme', 'storeName' => 'Acme',
            'employee' => ['employeeId' => 'e1', 'displayName' => 'Jo'],
            'services' => [['serviceId' => 's:1', 'name' => 'Cut']],
            'hiddenServiceIds' => [],
        ],
    ]);

    actingAsUser($user)->postJson('/api/platforms/fresha/service-visibility', ['serviceId' => 's:1', 'hidden' => true])
        ->assertOk()
        ->assertJsonPath('hiddenServiceIds', ['s:1'])
        ->assertJsonPath('storeName', 'Acme')
        ->assertJsonPath('employee.employeeId', 'e1');
});
