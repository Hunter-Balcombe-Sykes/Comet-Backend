<?php

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function scraperUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('requires auth on the eventbrite + youtube dashboard routes', function () {
    $this->getJson('/api/platforms/eventbrite/selection')->assertUnauthorized();
    $this->getJson('/api/platforms/youtube/selection')->assertUnauthorized();
});

it('connects an Eventbrite organiser scoped to the authenticated user', function () {
    $user = scraperUser('eborg');

    $this->mock(EventbriteScraper::class, function ($m) {
        $m->shouldReceive('normalizeOrgUrl')->andReturn('https://www.eventbrite.com/o/acme-1');
        $m->shouldReceive('fetchEvents')->andReturn([
            'organiser' => 'Acme',
            'events' => [['name' => 'Gig', 'startDate' => '2099-01-01T00:00:00+00:00']],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => 'https://www.eventbrite.com/o/acme-1'])
        ->assertOk()
        ->assertJsonPath('organiser', 'Acme');

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->exists())->toBeTrue();
});

it('connects a YouTube channel scoped to the authenticated user', function () {
    $user = scraperUser('ytuser');

    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('normalizeHandle')->andReturn('mychannel');
        $m->shouldReceive('fetchRecentVideos')->andReturn([
            ['videoId' => 'v1', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/youtube/connect', ['channel' => '@mychannel'])
        ->assertOk()
        ->assertJsonPath('handle', 'mychannel')
        ->assertJsonPath('latest.videoId', 'v1');

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'youtube')->exists())->toBeTrue();
});

it('requires auth, queues connect per-user (202), and allows immediate re-connect on Instagram', function () {
    $this->getJson('/api/platforms/instagram/selection')->assertUnauthorized();

    $user = scraperUser('iguser');
    config(['services.apify.token' => 'test-token']);

    Queue::fake();

    // connect() now returns 202 immediately and dispatches the job — no scraper call in the HTTP layer.
    actingAsUser($user)->postJson('/api/platforms/instagram/connect', ['username' => 'iguser'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending');

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'instagram')->exists())->toBeTrue();
    Queue::assertPushed(InstagramConnectJob::class);

    // No per-user cooldown — an immediate second connect is allowed (the global
    // daily cap is the only remaining cost guard).
    actingAsUser($user)->postJson('/api/platforms/instagram/connect', ['username' => 'iguser'])
        ->assertStatus(202);
});

it('requires auth on the fresha dashboard routes', function () {
    $this->getJson('/api/platforms/fresha/selection')->assertUnauthorized();
    $this->getJson('/api/platforms/fresha/team')->assertUnauthorized();
});

it('reads back a per-user Fresha selection + url from the connection payload', function () {
    $user = scraperUser('fresh');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/acme-salon',
            'selection' => [
                'url' => 'https://www.fresha.com/a/acme-salon',
                'storeName' => 'Acme',
                'employee' => ['employeeId' => 'e1', 'displayName' => 'Jo'],
                'services' => [],
            ],
        ],
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/selection')
        ->assertOk()
        ->assertJsonPath('selection.storeName', 'Acme')
        ->assertJsonPath('selection.employee.employeeId', 'e1');

    actingAsUser($user)->getJson('/api/platforms/fresha/url')
        ->assertOk()
        ->assertJsonPath('url', 'https://www.fresha.com/a/acme-salon');
});

it('shows + hides individual Fresha services via service-visibility', function () {
    $user = scraperUser('hideserv');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/acme-salon',
            'selection' => [
                'url' => 'https://www.fresha.com/a/acme-salon',
                'storeName' => 'Acme',
                'employee' => ['employeeId' => 'e1', 'displayName' => 'Jo'],
                'services' => [
                    ['serviceId' => 's:1', 'name' => 'Cut'],
                    ['serviceId' => 's:2', 'name' => 'Colour'],
                ],
            ],
        ],
    ]);

    // Hide one service — it lands in hiddenServiceIds.
    actingAsUser($user)->postJson('/api/platforms/fresha/service-visibility', [
        'serviceId' => 's:1', 'hidden' => true,
    ])->assertOk()->assertJsonPath('hiddenServiceIds', ['s:1']);

    // Hiding again is idempotent — no duplicate.
    actingAsUser($user)->postJson('/api/platforms/fresha/service-visibility', [
        'serviceId' => 's:1', 'hidden' => true,
    ])->assertOk()->assertJsonPath('hiddenServiceIds', ['s:1']);

    // Show it again — the list empties.
    actingAsUser($user)->postJson('/api/platforms/fresha/service-visibility', [
        'serviceId' => 's:1', 'hidden' => false,
    ])->assertOk()->assertJsonPath('hiddenServiceIds', []);
});

it('rejects hiding a service that is not in the saved Fresha menu', function () {
    $user = scraperUser('hidebad');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/acme-salon',
            'selection' => [
                'url' => 'https://www.fresha.com/a/acme-salon',
                'storeName' => 'Acme',
                'employee' => ['employeeId' => 'e1', 'displayName' => 'Jo'],
                'services' => [['serviceId' => 's:1', 'name' => 'Cut']],
            ],
        ],
    ]);

    actingAsUser($user)->postJson('/api/platforms/fresha/service-visibility', [
        'serviceId' => 's:999', 'hidden' => true,
    ])->assertStatus(404);
});

it('requires auth on the fresha service-visibility route', function () {
    $this->postJson('/api/platforms/fresha/service-visibility', ['serviceId' => 's:1', 'hidden' => true])
        ->assertUnauthorized();
});

it('requires auth on the apple dashboard routes', function () {
    $this->getJson('/api/platforms/apple/music/selection')->assertUnauthorized();
    $this->getJson('/api/platforms/apple/podcast/selection')->assertUnauthorized();
});

it('stores Apple Music + Podcast as independent per-user connections', function () {
    $user = scraperUser('apl');

    $this->mock(AppleSearch::class, function ($m) {
        $m->shouldReceive('fetchAlbums')->andReturn([
            ['collectionId' => 'a1', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l'],
        ]);
        $m->shouldReceive('fetchEpisodes')->andReturn([
            ['trackId' => 'e1', 'name' => 'Ep', 'thumbnail' => 't', 'description' => 'd', 'link' => 'l'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/apple/music/connect', ['artist' => 'Artist'])
        ->assertOk()->assertJsonPath('name', 'Album');
    actingAsUser($user)->postJson('/api/platforms/apple/podcast/connect', ['show' => 'Show'])
        ->assertOk()->assertJsonPath('name', 'Ep');

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'apple-music')->exists())->toBeTrue();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'apple-podcast')->exists())->toBeTrue();

    // Disconnecting Music leaves Podcast intact (independent rows).
    actingAsUser($user)->deleteJson('/api/platforms/apple/music')->assertOk();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'apple-music')->exists())->toBeFalse();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'apple-podcast')->exists())->toBeTrue();
});

it('requires auth on the shopify dashboard routes', function () {
    $this->getJson('/api/platforms/shopify/brands')->assertUnauthorized();
    $this->getJson('/api/platforms/shopify/selection')->assertUnauthorized();
});

it('adds Shopify brands per-user (one row, brand map) and caps at 5', function () {
    $user = scraperUser('shop');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probe')->andReturn(true);
        $m->shouldReceive('fetchBrand')->andReturnUsing(fn ($origin) => [
            'id' => md5($origin), 'name' => 'Brand', 'currency' => 'USD', 'favicon' => null, 'logo' => null,
        ]);
    });

    foreach (['a', 'b', 'c', 'd', 'e'] as $s) {
        actingAsUser($user)->postJson('/api/platforms/shopify/brands', ['url' => "https://{$s}.example.com"])->assertOk();
    }
    // A 6th distinct brand exceeds the cap.
    actingAsUser($user)->postJson('/api/platforms/shopify/brands', ['url' => 'https://f.example.com'])
        ->assertStatus(422);

    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->first();
    expect($conn)->not->toBeNull();
    // FOUND-25: brands live in site.shop_brands now, not the connection payload.
    expect(ShopBrand::where('connection_id', $conn->id)->where('is_individual', false)->count())->toBe(5);
});
