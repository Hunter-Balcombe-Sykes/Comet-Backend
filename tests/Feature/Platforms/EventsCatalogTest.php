<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\ManualEventWriter;
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
    setupItemSlugsTable();
    // Convergence Phase 6: a hand-added event is an `events` POOL item, so
    // these cases need the content lane.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function eventsUser(string $h): User
{
    $user = User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'business',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);

    // A hand-added event is a pool item, and a pool item needs a section, which
    // hangs off the SITE. The connection lane allowed a siteless owner to hold
    // one; the pool does not.
    $site = new Site(['subdomain' => $h, 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
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

    // Slice 7 Phase 4: a standalone event is an `events` POOL item, so it
    // reports the pool lane's `events-custom` / `link` attribution — the same
    // shape a hand-added event already used. The dashboard round-trips id and
    // removePath opaquely, so the scraping platform is no longer distinguished
    // and does not need to be: a pool item has no platform.
    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $url])
        ->assertOk()
        ->assertJsonPath('selection.events.0.name', 'Cool Show')
        ->assertJsonPath('selection.events.0.platform', 'events-custom')
        ->assertJsonPath('selection.events.0.source', 'link')
        // The dated half survives the move — the point of projecting the scrape
        // onto facets rather than storing a bare link.
        ->assertJsonPath('selection.events.0.venue', 'The Venue')
        ->assertJsonPath('selection.events.0.location', 'Melbourne')
        ->assertJsonPath('selection.events.0.startDate', '2099-01-01T10:00:00+10:00');

    // No connection at all: this is what stops the retired standalone payload
    // on /integrations being a data-loss event.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();

    $item = DB::connection('pgsql')->table('content.items')->where('user_id', $user->id)->first();
    expect($item->kind)->toBe('event')
        ->and($item->headline_cache)->toBe('Cool Show');
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
        ->assertJsonPath('selection.events.0.link', $url);

    // What this case pins is event-first routing, not the storage lane: no
    // `acct-` row means resolveHostUrl was never reached. The event itself is
    // a pool item now, so there is no `event-` row to look for.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
    expect(DB::connection('pgsql')->table('content.items')->where('user_id', $user->id)->where('kind', 'event')->count())->toBe(1);
});

// THE PERMALINK LANE, post-slice-7. A pasted single event used to get its slug
// from site.item_slugs, via IntegrationConnectionObserver::saved() →
// EventSlugSync, keyed on the payload's hex id. There is no connection to
// observe any more: the slug is minted by
// ProjectionWriter::refreshItemCaches() into content.item_slugs, keyed on the
// item id, because `event` is in ContentItemSlugAllocator::SLUGGED_KINDS.
//
// Both allocators derive their base identically (Str::slug, 80-char
// word-boundary truncate), so the slug STRING is unchanged — which is what
// lets slice 7 delete the 11 legacy site.item_slugs event rows in Phase 6
// without copying any of them across.
it('mints a content.item_slugs permalink for a pasted single event', function () {
    $url = 'https://www.eventbrite.com/e/cool-show-123';
    $scraper = Mockery::mock(EventbriteScraper::class);
    $scraper->shouldReceive('normalizeEventUrl')->andReturn($url);
    $scraper->shouldReceive('fetchSingleEvent')->with($url)->andReturn(sampleEvent($url));
    app()->instance(EventbriteScraper::class, $scraper);
    $user = eventsUser('ev4');

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $url])->assertOk();

    $slug = DB::connection('pgsql')->table('content.item_slugs')
        ->where('user_id', $user->id)->where('is_current', true)->value('slug');
    expect($slug)->toBe('cool-show');

    // And nothing lands in the legacy registry any more.
    expect(DB::connection('pgsql')->table('site.item_slugs')
        ->where('user_id', $user->id)->where('item_type', 'event')->count())->toBe(0);
});

it('no longer mints legacy item_slugs rows for an organiser account payload', function () {
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

    // Phase 6: site.item_slugs is no longer minted for events. Its last reader
    // moved to content.item_slugs in slice 2 Task 9 (PoolResolver serves event
    // slug/aliases from the content lane), so the legacy registry was retired
    // rather than kept in step with a lane nothing read.
    expect(DB::connection('pgsql')->table('site.item_slugs')
        ->where('user_id', $user->id)->where('item_type', 'event')->count())->toBe(0);
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

    // Convergence Phase 6: stored as an `events` POOL item of kind `event`, not
    // a `partna.manual_event` connection. `image` is deliberately NOT carried —
    // Phase 3 declined to mint content.media_assets for third-party image URLs
    // and this lane inherits that ruling, so a hand-added event has no artwork.
    expect(IntegrationConnection::query()->where('user_id', $user->id)
        ->where('surface_key', 'partna.manual_event')->exists())->toBeFalse();

    $cards = app(ManualEventWriter::class)->cards($user->fresh());
    expect($cards)->toHaveCount(1);
    expect($cards[0]['name'])->toBe('My Event');
    expect($cards[0]['url'])->toBe($url);

    $item = DB::connection('pgsql')->table('content.items')->where('id', $cards[0]['id'])->first();
    expect($item->kind)->toBe('event');
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
    // Both are pool items now, so both report the pool lane's attribution.
    // The ORDER is what still separates them: the dated eventbrite event sorts
    // before the dateless custom card, and it only can because the scraped
    // startDate survived the projection onto f_occurrence.
    expect(array_column($events, 'name'))->toBe(['Cool Show', 'Party']);
    expect($events[0]['startDate'])->toBe('2099-03-01T10:00:00+10:00');
    expect($events[1]['startDate'])->toBeNull();
});

// ── Manual reorder (PUT /api/platforms/events/order) ─────────────────────────

/**
 * Kept as a distinct name so the reorder cases still SAY they need a site.
 * eventsUser() provisions one for every user now — convergence Phase 6 made a
 * site a hard requirement for a hand-added event (it is a pool item, and a pool
 * item needs a section), so the two helpers converged.
 */
function eventsUserWithSite(string $h): User
{
    return eventsUser($h);
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
    // Built by hand, not via eventsUser(): that provisions a site now, and this
    // case is specifically about an owner who has none.
    $user = User::create([
        'handle' => 'evnosite',
        'handle_lc' => 'evnosite',
        'display_name' => 'Evnosite',
        'first_name' => 'Evnosite',
        'account_type' => 'business',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'evnosite@example.com',
    ]);

    actingAsUser($user)->putJson('/api/platforms/events/order', ['ids' => ['event-x']])
        ->assertStatus(404);
});

// ── Cross-tenant isolation (271-TEST-6) ──────────────────────────────────────
//
// Every test above this line acts as though one user exists, which is what the
// finding recorded: the facade had no assertion that user A cannot see or delete
// user B's events. The mechanism is scoped by construction — selection() reads
// via $user->integrationConnections(), removeCustom() via
// where('user_id', $user->id), reorder() writes to $user->site — so these lock
// in behaviour that is already correct rather than chasing a suspected bug.
//
// BOTH SIDES carry a positive control. "A cannot see B's event" is trivially
// true when A's selection is null, so each assertion below first proves the
// endpoint returned that identity's OWN event. Without it these would pass on
// two empty responses and prove nothing.
//
// POST /events/add is deliberately absent: it takes a URL and writes to the
// caller's own connections, so no path segment or body field can name another
// tenant's row. There is no cross-tenant shape to assert, and inventing one
// would be a test that looks like coverage without being it.

/** Mock the link scraper so a URL's last path segment becomes the event name. */
function mockLinkScraperByUrl(): void
{
    $link = Mockery::mock(LinkCardScraper::class);
    $link->shouldReceive('normalizeUrl')->andReturnUsing(fn (string $u) => $u);
    $link->shouldReceive('snapshotOrMinimal')->andReturnUsing(fn (string $u) => [
        'url' => $u,
        'name' => basename(parse_url($u, PHP_URL_PATH) ?: 'event'),
        'description' => null,
        'favicon' => null,
        'logo' => null,
    ]);
    app()->instance(LinkCardScraper::class, $link);
}

it('never lists another user\'s events in the selection', function () {
    mockLinkScraperByUrl();
    $a = eventsUser('eviso-a');
    $b = eventsUser('eviso-b');

    $aUrl = 'https://example.com/a-only';
    $bUrl = 'https://example.com/b-only';
    actingAsUser($a)->postJson('/api/platforms/events/add', ['url' => $aUrl])->assertOk();
    actingAsUser($b)->postJson('/api/platforms/events/add', ['url' => $bUrl])->assertOk();

    // A sees exactly its own. The first expectation is the positive control:
    // without it, an empty selection would satisfy the second one.
    $aEvents = actingAsUser($a)->getJson('/api/platforms/events/selection')->assertOk()->json('selection.events');
    expect(array_column($aEvents, 'name'))->toBe(['a-only'])
        ->and(array_column($aEvents, 'link'))->not->toContain($bUrl);

    // And symmetrically for B, so neither direction rests on an empty response.
    $bEvents = actingAsUser($b)->getJson('/api/platforms/events/selection')->assertOk()->json('selection.events');
    expect(array_column($bEvents, 'name'))->toBe(['b-only'])
        ->and(array_column($bEvents, 'link'))->not->toContain($aUrl);
});

it('never removes another user\'s custom event', function () {
    mockLinkScraperByUrl();
    $a = eventsUser('evdel-a');
    $b = eventsUser('evdel-b');

    $aId = actingAsUser($a)->postJson('/api/platforms/events/add', ['url' => 'https://example.com/a-keep'])
        ->assertOk()->json('selection.events.0.id');
    $bId = actingAsUser($b)->postJson('/api/platforms/events/add', ['url' => 'https://example.com/b-keep'])
        ->assertOk()->json('selection.events.0.id');
    expect($bId)->not->toBeNull()->and($aId)->not->toBe($bId);

    // 404, never 403 — confirming existence is an enumeration oracle.
    actingAsUser($a)->deleteJson("/api/platforms/events/custom/{$bId}")->assertStatus(404);

    // The half that matters more: a 404 with the delete APPLIED is the worst
    // outcome available, and it is exactly what an ownership check placed after
    // the mutation produces. B's row must still be there.
    // Convergence Phase 6: B's hand-added event is an `events` POOL item now,
    // so its survival is asserted against the pool, not a connection row.
    expect(app(ManualEventWriter::class)->cards($b->fresh()))
        ->toHaveCount(1);

    $bEvents = actingAsUser($b)->getJson('/api/platforms/events/selection')->assertOk()->json('selection.events');
    expect(array_column($bEvents, 'id'))->toBe([$bId]);
});

it('never reorders another user\'s events', function () {
    mockLinkScraperByUrl();
    $a = eventsUserWithSite('evord-a');
    $b = eventsUserWithSite('evord-b');

    actingAsUser($b)->postJson('/api/platforms/events/add', ['url' => 'https://example.com/b-one'])->assertOk();
    $bEvents = actingAsUser($b)->postJson('/api/platforms/events/add', ['url' => 'https://example.com/b-two'])
        ->assertOk()->json('selection.events');
    $bIds = array_column($bEvents, 'id');
    expect($bIds)->toHaveCount(2);

    // B fixes an explicit order, and it takes — the positive control.
    $bWanted = array_reverse($bIds);
    $after = actingAsUser($b)->putJson('/api/platforms/events/order', ['ids' => $bWanted])
        ->assertOk()->json('selection.events');
    expect(array_column($after, 'id'))->toBe($bWanted);

    // A reorders using B's ids. reorder() writes to the CALLER's site, so this
    // must be a scoped no-op rather than a write into B's ordering.
    actingAsUser($a)->putJson('/api/platforms/events/order', ['ids' => $bIds])->assertOk();

    $bAfterA = actingAsUser($b)->getJson('/api/platforms/events/selection')->assertOk()->json('selection.events');
    expect(array_column($bAfterA, 'id'))->toBe($bWanted);
});
