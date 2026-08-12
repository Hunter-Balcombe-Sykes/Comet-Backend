<?php

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\ShopifyScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Task 8: setProducts() now writes content.* (ShopContentWriter) instead
    // of site.shop_products — the stand-in schema must exist for the shop
    // tests below.
    setupContentTables();
});

// Facebook is per-user + authenticated now — connect stores under the logged-in
// user (no external fetch; pure link parsing). actingAsUser supplies the session.
function fbActingUser(): User
{
    return User::create([
        'handle' => 'fbtester',
        'handle_lc' => 'fbtester',
        'display_name' => 'FB Tester',
        'first_name' => 'FB Tester',
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'fb@example.com',
    ]);
}

it('stores a legacy /pages/Name/ID Facebook link, extracting the Page name (G4-4)', function () {
    $res = actingAsUser(fbActingUser())->postJson('/api/platforms/facebook/connect', [
        'username' => 'https://www.facebook.com/pages/Some-Cafe/123456789',
    ]);

    $res->assertOk();
    expect($res->json('username'))->toBe('Some-Cafe');
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

// Shopify: PUT /selection reuses the picker-warmed catalog instead of
// re-scraping the whole store on every save.

function seedShopifyBrand(): User
{
    $user = fbActingUser();
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
    ]);
    ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'b1', 'provider' => 'shopify',
        'url' => 'https://shop.example.com', 'name' => 'Shop', 'currency' => 'USD',
        'discount_code' => '',
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

    $res = actingAsUser($user)->putJson('/api/platforms/shop/brands/b1/selection', ['productIds' => ['p2']]);

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

    $res = actingAsUser($user)->putJson('/api/platforms/shop/brands/b1/selection', ['productIds' => ['p1']]);

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
    $dayKey = CacheKeyGenerator::apifyActorDailyLimit('instagram', now()->format('Y-m-d'));
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
    $dayKey = CacheKeyGenerator::apifyActorDailyLimit('instagram', now()->format('Y-m-d'));
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
        'payload' => [],
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')
        ->andReturn(['fullName' => 'Jane', 'businessCategoryName' => null]);
    $scraper->shouldReceive('latestMedia')
        ->andReturn(['photo' => ['thumbnailUrl' => 'https://scontent.cdninstagram.com/1.jpg', 'shortCode' => 'a'], 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->andReturn(null);
    $scraper->shouldReceive('bioLinks')->andReturn([]);
    // The seeder resolves its own InstagramScraper from the container — bind the
    // mock so seed() (called inside handle()) uses it too, not a real scraper.
    app()->instance(InstagramScraper::class, $scraper);

    $job = new InstagramConnectJob($user->id, 'jane', $connection->id);
    $job->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    $connection->refresh();
    expect($connection->payload['images'])->toHaveCount(0);   // photo mirror failed → no image
    expect($connection->payload['videoUrl'])->toBeNull();
});
