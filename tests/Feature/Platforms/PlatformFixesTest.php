<?php

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// Facebook is per-user + authenticated now — connect stores under the logged-in
// user (no external fetch; pure link parsing). actingAsUser supplies the session.
function fbActingUser(): User
{
    return User::create([
        'handle' => 'fbtester',
        'handle_lc' => 'fbtester',
        'display_name' => 'FB Tester',
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'fb@example.com',
    ]);
}

it('stores a legacy /pages/Name/ID Facebook link without mangling the username', function () {
    $res = actingAsUser(fbActingUser())->postJson('/api/platforms/facebook/connect', [
        'username' => 'https://www.facebook.com/pages/Some-Cafe/123456789',
    ]);

    $res->assertOk();
    expect($res->json('username'))->toBe('');
    expect($res->json('url'))->toBe('https://www.facebook.com/pages/Some-Cafe/123456789');
});

it('strips a query string from a /pages/ Facebook link', function () {
    $res = actingAsUser(fbActingUser())->postJson('/api/platforms/facebook/connect', [
        'username' => 'https://www.facebook.com/pages/Some-Cafe/123456789?ref=bookmarks',
    ]);

    $res->assertOk();
    expect($res->json('url'))->toBe('https://www.facebook.com/pages/Some-Cafe/123456789');
});

it('still stores a vanity Facebook handle', function () {
    $res = actingAsUser(fbActingUser())->postJson('/api/platforms/facebook/connect', ['username' => '@nike']);

    $res->assertOk();
    expect($res->json('username'))->toBe('nike');
    expect($res->json('url'))->toBe('https://www.facebook.com/nike');
});

// Eventbrite read-time past-event filtering (seed the cache, read it back).

it('drops elapsed events from the Eventbrite selection at read time', function () {
    $past = now()->subDays(2)->toIso8601String();
    $future = now()->addDays(5)->toIso8601String();

    $user = fbActingUser();
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'eventbrite', 'resource_id' => 'eventbrite',
        'payload' => [
            'url' => 'https://www.eventbrite.com/o/acme-1',
            'organiser' => 'Acme',
            'next' => ['name' => 'Old gig', 'startDate' => $past, 'endDate' => $past],
            'upcoming' => [
                ['name' => 'Old gig', 'startDate' => $past, 'endDate' => $past],
                ['name' => 'Future gig', 'startDate' => $future, 'endDate' => $future],
            ],
        ],
    ]);

    $res = actingAsUser($user)->getJson('/api/platforms/eventbrite/selection');

    $res->assertOk();
    expect($res->json('selection.upcoming'))->toHaveCount(1);
    expect($res->json('selection.upcoming.0.name'))->toBe('Future gig');
    expect($res->json('selection.next.name'))->toBe('Future gig');
});

it('keeps an in-progress event (started, not yet ended) in the Eventbrite selection', function () {
    $started = now()->subHour()->toIso8601String();
    $endsLater = now()->addHours(3)->toIso8601String();

    $user = fbActingUser();
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'eventbrite', 'resource_id' => 'eventbrite',
        'payload' => [
            'url' => 'https://www.eventbrite.com/o/acme-1',
            'organiser' => 'Acme',
            'next' => null,
            'upcoming' => [
                ['name' => 'Live now', 'startDate' => $started, 'endDate' => $endsLater],
            ],
        ],
    ]);

    $res = actingAsUser($user)->getJson('/api/platforms/eventbrite/selection');

    $res->assertOk();
    expect($res->json('selection.upcoming'))->toHaveCount(1);
    expect($res->json('selection.next.name'))->toBe('Live now');
});

it('keeps an event with no dates at all in the Eventbrite selection', function () {
    $past = now()->subDays(2)->toIso8601String();

    $user = fbActingUser();
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'eventbrite', 'resource_id' => 'eventbrite',
        'payload' => [
            'url' => 'https://www.eventbrite.com/o/acme-1',
            'organiser' => 'Acme',
            'next' => null,
            'upcoming' => [
                ['name' => 'Old gig', 'startDate' => $past, 'endDate' => $past],
                ['name' => 'Dateless gig', 'startDate' => null, 'endDate' => null],
            ],
        ],
    ]);

    $res = actingAsUser($user)->getJson('/api/platforms/eventbrite/selection');

    // filterPastEvents: $end === null survives, so a dateless event is kept while
    // the elapsed one is dropped (the intentional null-both-dates path).
    $res->assertOk();
    expect($res->json('selection.upcoming'))->toHaveCount(1);
    expect($res->json('selection.upcoming.0.name'))->toBe('Dateless gig');
    expect($res->json('selection.next.name'))->toBe('Dateless gig');
});

// Apple "most recent" tile refreshes when highlights are updated (mock the
// iTunes Search fetch so no live call is made).

it('refreshes the Apple Music "most recent" tile when highlights are updated', function () {
    $this->mock(AppleSearch::class, function ($m) {
        $m->shouldReceive('fetchAlbums')->andReturn([
            ['collectionId' => '111', 'name' => 'New Album', 'thumbnail' => 't1', 'releaseDate' => '2026-06-01', 'link' => 'l1'],
            ['collectionId' => '222', 'name' => 'Old Album', 'thumbnail' => 't2', 'releaseDate' => '2025-01-01', 'link' => 'l2'],
        ]);
    });

    $user = fbActingUser();
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'apple-music', 'resource_id' => 'apple-music',
        'payload' => [
            'input' => 'someartist',
            'name' => 'Stale Album',
            'latest' => ['collectionId' => '999', 'name' => 'Stale Album'],
            'highlights' => [],
        ],
    ]);

    $res = actingAsUser($user)->postJson('/api/platforms/apple/music/highlights', ['albumIds' => ['222']]);

    $res->assertOk();
    expect($res->json('latest.name'))->toBe('New Album');   // refreshed from the newest re-fetch
    expect($res->json('name'))->toBe('New Album');          // flat back-compat field too
    expect($res->json('highlights'))->toHaveCount(1);       // chosen highlight still snapshotted
    expect($res->json('highlights.0.collectionId'))->toBe('222');
});

it('refreshes the Apple Podcast "most recent" tile when highlights are updated', function () {
    $this->mock(AppleSearch::class, function ($m) {
        $m->shouldReceive('fetchEpisodes')->andReturn([
            ['trackId' => '111', 'name' => 'New Episode', 'thumbnail' => 't1', 'description' => 'd1', 'link' => 'l1'],
            ['trackId' => '222', 'name' => 'Old Episode', 'thumbnail' => 't2', 'description' => 'd2', 'link' => 'l2'],
        ]);
    });

    $user = fbActingUser();
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'apple-podcast', 'resource_id' => 'apple-podcast',
        'payload' => [
            'input' => 'someshow',
            'name' => 'Stale Episode',
            'latest' => ['trackId' => '999', 'name' => 'Stale Episode'],
            'highlights' => [],
        ],
    ]);

    $res = actingAsUser($user)->postJson('/api/platforms/apple/podcast/highlights', ['episodeIds' => ['222']]);

    $res->assertOk();
    expect($res->json('latest.name'))->toBe('New Episode');
    expect($res->json('name'))->toBe('New Episode');
    expect($res->json('highlights'))->toHaveCount(1);
    expect($res->json('highlights.0.trackId'))->toBe('222');
});

// YouTube "most recent" tile refreshes when highlights are updated (mock the
// scrape so no live call is made) — parity with the Apple tiles above.
it('refreshes the YouTube "most recent" tile when highlights are updated', function () {
    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('fetchRecentVideos')->andReturn([
            ['videoId' => '111', 'name' => 'New Video', 'description' => 'd1', 'link' => 'l1', 'thumbnail' => 't1'],
            ['videoId' => '222', 'name' => 'Old Video', 'description' => 'd2', 'link' => 'l2', 'thumbnail' => 't2'],
        ]);
    });

    $user = fbActingUser();
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => [
            'handle' => 'mychannel',
            'name' => 'Stale Video',
            'latest' => ['videoId' => '999', 'name' => 'Stale Video'],
            'highlights' => [],
        ],
    ]);

    $res = actingAsUser($user)->postJson('/api/platforms/youtube/highlights', ['videoIds' => ['222']]);

    $res->assertOk();
    expect($res->json('latest.name'))->toBe('New Video');   // refreshed from the newest re-fetch
    expect($res->json('name'))->toBe('New Video');          // flat back-compat field too
    expect($res->json('highlights'))->toHaveCount(1);       // chosen highlight still snapshotted
    expect($res->json('highlights.0.videoId'))->toBe('222');
});

// Shopify: PUT /selection reuses the picker-warmed catalog instead of
// re-scraping the whole store on every save.

function seedShopifyBrand(): User
{
    $user = fbActingUser();
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => [
            'b1' => [
                'id' => 'b1', 'url' => 'https://shop.example.com', 'name' => 'Shop',
                'currency' => 'USD', 'favicon' => null, 'logo' => null,
                'discountCode' => '', 'products' => [],
            ],
        ],
    ]);

    return $user;
}

it('reuses the warmed catalog on Shopify setProducts (no re-scrape)', function () {
    $user = seedShopifyBrand();
    Cache::put('platforms.shopify.brands.catalog.b1', [
        ['productId' => 'p1', 'title' => 'A'],
        ['productId' => 'p2', 'title' => 'B'],
    ], now()->addMinutes(10));

    // Catalog is warm — the controller must NOT hit the scraper.
    $this->mock(ShopifyScraper::class, fn ($m) => $m->shouldNotReceive('fetchProducts'));

    $res = actingAsUser($user)->putJson('/api/platforms/shopify/brands/b1/selection', ['productIds' => ['p2']]);

    $res->assertOk();
    expect($res->json('products'))->toHaveCount(1);
    expect($res->json('products.0.productId'))->toBe('p2');
});

it('re-scrapes on Shopify setProducts only when the catalog cache is cold', function () {
    $user = seedShopifyBrand(); // no catalog cache seeded

    $this->mock(ShopifyScraper::class, fn ($m) => $m->shouldReceive('fetchProducts')->once()->andReturn([
        ['productId' => 'p1', 'title' => 'A'],
        ['productId' => 'p2', 'title' => 'B'],
    ]));

    $res = actingAsUser($user)->putJson('/api/platforms/shopify/brands/b1/selection', ['productIds' => ['p1']]);

    $res->assertOk();
    expect($res->json('products'))->toHaveCount(1);
    expect($res->json('products.0.productId'))->toBe('p1');
});

// SEM-5 regression: when the global daily Apify cap is already reached, the
// connect endpoint must return the "busy" 429 WITHOUT setting the per-user
// cooldown key, so the user is not locked out after the cap resets at midnight.

it('returns busy 429 when the daily Apify cap is reached without locking the user cooldown', function () {
    config(['services.apify.token' => 'test-token']);

    $user = fbActingUser();
    $dayKey = 'platforms:instagram:apify-daily:'.now()->format('Y-m-d');
    $cooldownKey = "platforms:instagram:cooldown:{$user->id}";

    // Pre-seed the daily counter at the cap (200).
    Cache::put($dayKey, 200, now()->addDay());

    $res = actingAsUser($user)->postJson('/api/platforms/instagram/connect', ['username' => 'testuser']);

    $res->assertStatus(429);
    expect($res->json('message'))->toContain('busy');

    // The cooldown key must NOT have been set — user is not penalised for a cap hit.
    expect(Cache::has($cooldownKey))->toBeFalse();
});

it('allows a connect attempt after the daily cap resets when no cooldown was set', function () {
    config(['services.apify.token' => 'test-token']);
    Queue::fake();

    $user = fbActingUser();
    $dayKey = 'platforms:instagram:apify-daily:'.now()->format('Y-m-d');
    $cooldownKey = "platforms:instagram:cooldown:{$user->id}";

    // Simulate: cap was hit, then reset (cap key gone, no cooldown set).
    Cache::forget($dayKey);
    expect(Cache::has($cooldownKey))->toBeFalse();

    // Should succeed (202) — the scrape now runs in the job, not here.
    actingAsUser($user)->postJson('/api/platforms/instagram/connect', ['username' => 'testuser'])
        ->assertStatus(202);
});

// Instagram: failed image mirrors are surfaced (imagesDropped) instead of
// silently saving fewer images than the user picked. This is now enforced in
// InstagramConnectJob (the async path) — tested directly on the job.

it('surfaces dropped Instagram images when mirroring fails (job-level)', function () {
    Storage::fake('media');
    // Every CDN request fails — mirrors should all be dropped.
    Http::fake(['*' => Http::response('', 500)]);

    $user = fbActingUser();
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => null,
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')
        ->andReturn(['fullName' => 'Jane', 'businessCategoryName' => null]);
    $scraper->shouldReceive('recentCoverImages')
        ->andReturn([
            'https://scontent.cdninstagram.com/1.jpg',
            'https://scontent.cdninstagram.com/2.jpg',
            'https://scontent.cdninstagram.com/3.jpg',
        ]);
    $scraper->shouldReceive('profilePicUrl')->andReturn(null);

    $job = new InstagramConnectJob($user->id, 'jane', $connection->id);
    $job->handle($scraper);

    $connection->refresh();
    expect($connection->payload['images'])->toHaveCount(0);   // all mirrors failed
    expect($connection->payload['imagesDropped'])->toBe(3);   // ...and that's surfaced
});
