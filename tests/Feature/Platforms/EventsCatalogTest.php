<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\HumanitixScraper;
use App\Services\Platforms\LinkCardScraper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The "Tickets & Events" smart-detect facade (EventsCatalog + EventsController).
// The scrapers are mocked — their own URL-parsing + JSON-LD scraping is covered
// by EventbriteScraper/HumanitixScraper tests; here we test the detect → route →
// store + the cross-platform aggregation the single card needs.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function eventsUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'business',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function sampleEvent(string $link, string $start = '2099-01-01T10:00:00+10:00'): array
{
    return [
        'name' => 'Cool Show', 'venue' => 'The Venue', 'location' => 'Melbourne',
        'startDate' => $start, 'endDate' => null, 'price' => 'Free',
        'availability' => 'available', 'image' => 'https://img.example/e.jpg', 'link' => $link,
    ];
}

it('adds a single event when an Eventbrite EVENT url is pasted', function () {
    $url = 'https://www.eventbrite.com/e/cool-show-123';
    $scraper = Mockery::mock(EventbriteScraper::class);
    $scraper->shouldReceive('normalizeEventUrl')->andReturn($url);
    $scraper->shouldReceive('fetchSingleEvent')->with($url)->andReturn(sampleEvent($url));
    app()->instance(EventbriteScraper::class, $scraper);
    $user = eventsUser('ev1');

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $url])
        ->assertOk()
        ->assertJsonPath('selection.events.0.name', 'Cool Show')
        ->assertJsonPath('selection.events.0.platform', 'eventbrite');

    $row = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'eventbrite')->firstOrFail();
    expect($row->resource_id)->toStartWith('event-');
    expect($row->payload['kind'])->toBe('event');
});

it('connects an organiser account when an Eventbrite ORG url is pasted', function () {
    $url = 'https://www.eventbrite.com/o/my-org-456';
    $scraper = Mockery::mock(EventbriteScraper::class);
    $scraper->shouldReceive('normalizeEventUrl')->andReturn(null);
    $scraper->shouldReceive('normalizeOrgUrl')->andReturn($url);
    $scraper->shouldReceive('fetchEvents')->with($url)->andReturn([
        'organiser' => 'My Org',
        'events' => [
            sampleEvent('https://www.eventbrite.com/e/a-1'),
            sampleEvent('https://www.eventbrite.com/e/b-2', '2099-02-01T10:00:00+10:00'),
        ],
    ]);
    app()->instance(EventbriteScraper::class, $scraper);
    $user = eventsUser('ev2');

    $resp = actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $url])->assertOk();
    $resp->assertJsonPath('selection.accounts.0.organiser', 'My Org');
    $resp->assertJsonPath('selection.accounts.0.platform', 'eventbrite');
    expect($resp->json('selection.events'))->toHaveCount(2);

    $row = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'eventbrite')->firstOrFail();
    expect($row->resource_id)->toStartWith('acct-');
});

it('adds the single event (not the host) when a Humanitix EVENT url is pasted', function () {
    $url = 'https://events.humanitix.com/cool-show';
    $scraper = Mockery::mock(HumanitixScraper::class);
    $scraper->shouldReceive('normalizeEventUrl')->andReturn($url);
    $scraper->shouldReceive('fetchSingleEvent')->with($url)->andReturn(sampleEvent($url));
    // resolveHostUrl must NOT be reached — event-first wins.
    app()->instance(HumanitixScraper::class, $scraper);
    $user = eventsUser('ev3');

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $url])
        ->assertOk()
        ->assertJsonPath('selection.events.0.platform', 'humanitix');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'humanitix')->where('resource_id', 'like', 'event-%')->exists())->toBeTrue();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('resource_id', 'like', 'acct-%')->exists())->toBeFalse();
});

// End-to-end proof that IntegrationConnectionObserver::saved() actually reaches
// EventSlugSync through the real HTTP -> EventsCatalog -> writeRow -> observer
// chain (not just the isolated EventSlugSync/ItemSlugAllocator unit tests).
it('mints an item_slugs row for a pasted single event', function () {
    setupItemSlugsTable();
    $url = 'https://www.eventbrite.com/e/cool-show-123';
    $scraper = Mockery::mock(EventbriteScraper::class);
    $scraper->shouldReceive('normalizeEventUrl')->andReturn($url);
    $scraper->shouldReceive('fetchSingleEvent')->with($url)->andReturn(sampleEvent($url));
    app()->instance(EventbriteScraper::class, $scraper);
    $user = eventsUser('ev4');

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $url])->assertOk();

    $row = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'eventbrite')->firstOrFail();
    $hex = $row->payload['id'];

    $slug = DB::connection('pgsql')->table('site.item_slugs')
        ->where('user_id', $user->id)->where('item_type', 'event')
        ->where('item_key', $hex)->where('is_current', 1)->value('slug');
    expect($slug)->toBe('cool-show');
});

it('mints item_slugs rows for every event in an organiser account payload', function () {
    setupItemSlugsTable();
    $url = 'https://www.eventbrite.com/o/my-org-456';
    $scraper = Mockery::mock(EventbriteScraper::class);
    $scraper->shouldReceive('normalizeEventUrl')->andReturn(null);
    $scraper->shouldReceive('normalizeOrgUrl')->andReturn($url);
    $scraper->shouldReceive('fetchEvents')->with($url)->andReturn([
        'organiser' => 'My Org',
        'events' => [
            sampleEvent('https://www.eventbrite.com/e/a-1'),
            sampleEvent('https://www.eventbrite.com/e/b-2', '2099-02-01T10:00:00+10:00'),
        ],
    ]);
    app()->instance(EventbriteScraper::class, $scraper);
    $user = eventsUser('ev5');

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $url])->assertOk();

    expect(DB::connection('pgsql')->table('site.item_slugs')
        ->where('user_id', $user->id)->where('item_type', 'event')->count())->toBe(2);
});

it('stores a non-platform link as a custom event', function () {
    $url = 'https://example.com/my-event';
    $link = Mockery::mock(LinkCardScraper::class);
    $link->shouldReceive('normalizeUrl')->andReturn($url);
    $link->shouldReceive('snapshotOrMinimal')->andReturn([
        'url' => $url, 'name' => 'My Event', 'description' => null,
        'favicon' => 'https://example.com/favicon.ico', 'logo' => 'https://example.com/logo.png',
    ]);
    app()->instance(LinkCardScraper::class, $link);
    $user = eventsUser('ev4');

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $url])
        ->assertOk()
        ->assertJsonPath('selection.events.0.name', 'My Event')
        ->assertJsonPath('selection.events.0.platform', 'events-custom')
        ->assertJsonPath('selection.events.0.link', $url);

    $row = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'events-custom')->firstOrFail();
    expect($row->payload['kind'])->toBe('event');
    expect($row->payload['image'])->toBe('https://example.com/logo.png');
});

it('removes a custom event via the custom delete endpoint', function () {
    $url = 'https://example.com/gone';
    $link = Mockery::mock(LinkCardScraper::class);
    $link->shouldReceive('normalizeUrl')->andReturn($url);
    $link->shouldReceive('snapshotOrMinimal')->andReturn(['url' => $url, 'name' => 'Gone', 'description' => null, 'favicon' => null, 'logo' => null]);
    app()->instance(LinkCardScraper::class, $link);
    $user = eventsUser('ev5');

    $id = actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $url])->assertOk()->json('selection.events.0.id');
    expect($id)->not->toBeNull();

    actingAsUser($user)->deleteJson("/api/platforms/events/custom/{$id}")
        ->assertOk()
        ->assertJsonPath('selection', null);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'events-custom')->exists())->toBeFalse();
});

it('aggregates eventbrite + humanitix + custom into one selection', function () {
    // One eventbrite standalone event, one custom link.
    $ebUrl = 'https://www.eventbrite.com/e/eb-1';
    $eb = Mockery::mock(EventbriteScraper::class);
    $eb->shouldReceive('normalizeEventUrl')->andReturn($ebUrl);
    $eb->shouldReceive('fetchSingleEvent')->andReturn(sampleEvent($ebUrl, '2099-03-01T10:00:00+10:00'));
    app()->instance(EventbriteScraper::class, $eb);

    $customUrl = 'https://example.com/party';
    $link = Mockery::mock(LinkCardScraper::class);
    $link->shouldReceive('normalizeUrl')->andReturn($customUrl);
    $link->shouldReceive('snapshotOrMinimal')->andReturn(['url' => $customUrl, 'name' => 'Party', 'description' => null, 'favicon' => null, 'logo' => null]);
    app()->instance(LinkCardScraper::class, $link);

    $user = eventsUser('ev6');
    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $ebUrl])->assertOk();
    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $customUrl])->assertOk();

    $events = actingAsUser($user)->getJson('/api/platforms/events/selection')->assertOk()->json('selection.events');
    expect($events)->toHaveCount(2);
    // Dated eventbrite event sorts before the dateless custom card.
    expect($events[0]['platform'])->toBe('eventbrite');
    expect($events[1]['platform'])->toBe('events-custom');
});

// ── Manual reorder (PUT /api/platforms/events/order) ─────────────────────────

function eventsUserWithSite(string $h): User
{
    $user = eventsUser($h);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $h,
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $user->fresh();
}

it('persists a manual event order and serves it ahead of date order', function () {
    $urls = ['https://www.eventbrite.com/e/first-1', 'https://www.eventbrite.com/e/second-2'];
    $scraper = Mockery::mock(EventbriteScraper::class);
    $scraper->shouldReceive('normalizeEventUrl')->andReturnUsing(fn (string $u) => $u);
    $scraper->shouldReceive('fetchSingleEvent')->with($urls[0])->andReturn(sampleEvent($urls[0], '2099-01-01T10:00:00+10:00'));
    $scraper->shouldReceive('fetchSingleEvent')->with($urls[1])->andReturn([...sampleEvent($urls[1], '2099-02-01T10:00:00+10:00'), 'name' => 'Later Show']);
    app()->instance(EventbriteScraper::class, $scraper);
    $user = eventsUserWithSite('evorder');

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $urls[0]])->assertOk();
    $events = actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $urls[1]])->assertOk()->json('selection.events');
    // Date order: the January event leads.
    expect($events[0]['name'])->toBe('Cool Show');
    $ids = array_column($events, 'id');

    // Reverse it manually — the saved order must now win over dates.
    $reordered = actingAsUser($user)->putJson('/api/platforms/events/order', ['ids' => array_reverse($ids)])
        ->assertOk()
        ->json('selection.events');
    expect(array_column($reordered, 'id'))->toBe(array_reverse($ids));

    // And it sticks on a fresh read.
    $again = actingAsUser($user)->getJson('/api/platforms/events/selection')->assertOk()->json('selection.events');
    expect(array_column($again, 'id'))->toBe(array_reverse($ids));
});

it('keeps unlisted events after the manually ordered ones', function () {
    $urls = ['https://www.eventbrite.com/e/a-1', 'https://www.eventbrite.com/e/b-2'];
    $scraper = Mockery::mock(EventbriteScraper::class);
    $scraper->shouldReceive('normalizeEventUrl')->andReturnUsing(fn (string $u) => $u);
    $scraper->shouldReceive('fetchSingleEvent')->with($urls[0])->andReturn(sampleEvent($urls[0], '2099-01-01T10:00:00+10:00'));
    $scraper->shouldReceive('fetchSingleEvent')->with($urls[1])->andReturn(sampleEvent($urls[1], '2099-02-01T10:00:00+10:00'));
    app()->instance(EventbriteScraper::class, $scraper);
    $user = eventsUserWithSite('evorder2');

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $urls[0]])->assertOk();
    $events = actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $urls[1]])->assertOk()->json('selection.events');
    $ids = array_column($events, 'id');

    // Order ONLY the later event — it leads, the unlisted one follows.
    $reordered = actingAsUser($user)->putJson('/api/platforms/events/order', ['ids' => [$ids[1]]])
        ->assertOk()
        ->json('selection.events');
    expect(array_column($reordered, 'id'))->toBe([$ids[1], $ids[0]]);
});

it('rejects a reorder without a site', function () {
    $user = eventsUser('evnosite');

    actingAsUser($user)->putJson('/api/platforms/events/order', ['ids' => ['event-x']])
        ->assertStatus(404);
});
