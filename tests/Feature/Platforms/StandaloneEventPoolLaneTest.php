<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\ManualEventWriter;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\EventsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 7 Phase 4, round 2. Three live paths wrote a `resource_kind='event'`
// connection, not one:
//
//   1. EventsCatalog::storeStandalone()          — the Tickets & Events card
//   2. EventsPlatformController::addStandaloneEvent() — POST /platforms/{p}/events
//   3. EventsSeeder::seedStandalone()            — the link-scan / signup lane
//
// Round 1 repointed only (1), then emptied the payload all three publish — so
// (2) and (3) produced events that were publicly invisible with no error. This
// file pins that all three now land a content item, and that the per-platform
// add cap survived the move onto a lane where a "platform" no longer exists.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function speUser(string $h): User
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

    $site = new Site(['subdomain' => $h, 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

/** @return array<string, mixed> */
function speEvent(string $link, ?string $name = null): array
{
    return [
        'name' => $name ?? speNameFor($link), 'venue' => 'The Venue', 'location' => 'Melbourne',
        'startDate' => '2099-01-01T10:00:00+10:00', 'endDate' => null,
        'price' => 'Free', 'priceMin' => 0.0, 'currency' => 'AUD',
        'availability' => 'available', 'image' => 'https://img.example/e.jpg',
        'link' => $link,
    ];
}

/** A distinct headline per URL, so N adds are N events rather than one merged one. */
function speNameFor(string $url): string
{
    return Str::headline((string) (basename((string) parse_url($url, PHP_URL_PATH)) ?: 'event'));
}

/**
 * ONE argument-driven mock for the whole test, installed before the first
 * request — never re-mocked between requests.
 *
 * Illuminate\Routing\Route caches its resolved controller instance on the Route
 * object, and routes outlive individual requests inside one test process. So a
 * second app()->instance() call is picked up by the CONTAINER but not by the
 * already-constructed EventbriteController, which still holds the first
 * scraper: a loop that re-mocks per iteration silently replays the first URL.
 * That cost an afternoon; do not "simplify" this back into the loop.
 */
function speMockEventbrite(): void
{
    $scraper = Mockery::mock(EventbriteScraper::class);
    $scraper->shouldReceive('normalizeEventUrl')
        ->andReturnUsing(fn (string $u) => str_contains($u, '/e/') ? $u : null);
    $scraper->shouldReceive('normalizeOrgUrl')->andReturn(null);
    $scraper->shouldReceive('fetchSingleEvent')->andReturnUsing(fn (string $u) => speEvent($u));
    app()->instance(EventbriteScraper::class, $scraper);
}

/** Live `event`-kind items on this user's manual source. */
function speItemCount(User $user): int
{
    return DB::connection('pgsql')->table('content.items')
        ->where('user_id', $user->id)->where('kind', 'event')->whereNull('removed_at')->count();
}

// ── (2) the per-platform add verb ────────────────────────────────────────────

it('lands a content item, not a connection, for POST /platforms/eventbrite/events', function () {
    $url = 'https://www.eventbrite.com/e/cool-show-123';
    speMockEventbrite();
    $user = speUser('spe1');

    actingAsUser($user)->postJson('/api/platforms/eventbrite/events', ['url' => $url])
        ->assertOk()
        ->assertJsonPath('selection.events.0.name', speNameFor($url));

    expect(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
    expect(speItemCount($user))->toBe(1);

    expect(DB::connection('pgsql')->table('content.f_link')->value('url'))->toBe($url);
});

// The coord is the whole point of one lane: the card path, the per-platform
// path and the backfill all derive it from the event URL, so the same event
// added twice by different routes is ONE item.
it('folds the per-platform add onto the same coord the card path mints', function () {
    $url = 'https://www.eventbrite.com/e/cool-show-123';
    speMockEventbrite();
    $user = speUser('spe2');

    actingAsUser($user)->postJson('/api/platforms/eventbrite/events', ['url' => $url])->assertOk();
    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $url])->assertOk();

    expect(speItemCount($user))->toBe(1);
    expect(DB::connection('pgsql')->table('content.source_items')->value('coord'))
        ->toBe(ManualEventWriter::coordFor($url));
});

it('removes a pool-lane event through the per-platform remove route', function () {
    $url = 'https://www.eventbrite.com/e/cool-show-123';
    speMockEventbrite();
    $user = speUser('spe3');

    $id = actingAsUser($user)->postJson('/api/platforms/eventbrite/events', ['url' => $url])
        ->assertOk()->json('selection.events.0.id');

    actingAsUser($user)->deleteJson("/api/platforms/eventbrite/events/{$id}")->assertOk();

    expect(speItemCount($user))->toBe(0);
});

it('404s rather than removing another owner\'s pool event', function () {
    $url = 'https://www.eventbrite.com/e/cool-show-123';
    speMockEventbrite();
    $owner = speUser('spe4a');
    $other = speUser('spe4b');

    $id = actingAsUser($owner)->postJson('/api/platforms/eventbrite/events', ['url' => $url])
        ->assertOk()->json('selection.events.0.id');

    actingAsUser($other)->deleteJson("/api/platforms/eventbrite/events/{$id}")->assertStatus(404);

    expect(speItemCount($owner))->toBe(1);
});

it('a siteless owner gets a 422 rather than a silently dropped event', function () {
    $url = 'https://www.eventbrite.com/e/cool-show-123';
    speMockEventbrite();
    $user = User::create([
        'handle' => 'spe5', 'handle_lc' => 'spe5', 'display_name' => 'Spe5', 'first_name' => 'Spe5',
        'account_type' => 'business', 'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'spe5@example.com',
    ]);

    actingAsUser($user)->postJson('/api/platforms/eventbrite/events', ['url' => $url])
        ->assertStatus(422);
});

// ── The cap ──────────────────────────────────────────────────────────────────

// PRESERVED, not retired. Idempotency on a deterministic coord stops duplicates
// of the SAME event; it does nothing about an unbounded number of DIFFERENT
// ones, which is what this cap is for.
it('still 422s at the tenth standalone event on the per-platform route', function () {
    $user = speUser('spe6');

    speMockEventbrite();

    for ($i = 0; $i < ManualEventWriter::MAX_STANDALONE_EVENTS; $i++) {
        $url = "https://www.eventbrite.com/e/show-{$i}";
        actingAsUser($user)->postJson('/api/platforms/eventbrite/events', ['url' => $url])->assertOk();
    }

    expect(speItemCount($user))->toBe(ManualEventWriter::MAX_STANDALONE_EVENTS);

    $overflow = 'https://www.eventbrite.com/e/one-too-many';
    actingAsUser($user)->postJson('/api/platforms/eventbrite/events', ['url' => $overflow])
        ->assertStatus(422);

    expect(speItemCount($user))->toBe(ManualEventWriter::MAX_STANDALONE_EVENTS);
});

it('still 422s at the cap on the Tickets & Events card route', function () {
    $user = speUser('spe7');

    speMockEventbrite();

    for ($i = 0; $i < ManualEventWriter::MAX_STANDALONE_EVENTS; $i++) {
        $url = "https://www.eventbrite.com/e/card-{$i}";
        actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $url])->assertOk();
    }

    $overflow = 'https://www.eventbrite.com/e/card-overflow';
    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $overflow])
        ->assertStatus(422);

    expect(speItemCount($user))->toBe(ManualEventWriter::MAX_STANDALONE_EVENTS);
});

// The legacy cap only fired for a NEW event: re-adding one already held was an
// update, never a 422. That must survive, or a refresh of an existing event
// starts failing the moment a user reaches the cap.
it('lets an owner at the cap re-add an event they already hold', function () {
    $user = speUser('spe8');

    speMockEventbrite();

    $first = 'https://www.eventbrite.com/e/again-0';
    for ($i = 0; $i < ManualEventWriter::MAX_STANDALONE_EVENTS; $i++) {
        $url = "https://www.eventbrite.com/e/again-{$i}";
        actingAsUser($user)->postJson('/api/platforms/eventbrite/events', ['url' => $url])->assertOk();
    }

    // The re-add: an UPDATE on a coord the owner already holds, at the ceiling.
    actingAsUser($user)->postJson('/api/platforms/eventbrite/events', ['url' => $first])->assertOk();

    expect(speItemCount($user))->toBe(ManualEventWriter::MAX_STANDALONE_EVENTS);
});

// Scoped to the owner's MANUAL source, deliberately. The events pool also holds
// items the ingest connectors project from a connected ORGANISER, and counting
// those would let one organiser with 30 upcoming events block every hand-add.
it('counts only the owner\'s own events, not a connector\'s', function () {
    $user = speUser('spe9');

    expect(app(ManualEventWriter::class)->ownedEventCount($user))->toBe(0);

    $url = 'https://www.eventbrite.com/e/owned';
    speMockEventbrite();
    actingAsUser($user)->postJson('/api/platforms/eventbrite/events', ['url' => $url])->assertOk();

    expect(app(ManualEventWriter::class)->ownedEventCount($user->fresh()))->toBe(1);
});

// ── (3) the link-scan / signup seeder ────────────────────────────────────────

// DUAL-WRITE, deliberately. The connection row is kept because the synced-modal
// finding lane resolves by `platform|resourceId` against connection rows in TWO
// controllers (InstagramController + GoogleBusinessController shapeFinding) and
// derives its status from `last_refresh_status`. Teaching that lane about pool
// items is its own piece of work. The row publishes `[]` either way — the item
// is what reaches the sitepage.
it('seeds BOTH a connection row and a content item from a scanned event link', function () {
    $url = 'https://www.eventbrite.com/e/scanned-show';
    speMockEventbrite();
    $user = speUser('spe10');

    $rid = app(EventsSeeder::class)->seedStandalone($user, 'eventbrite', $url);

    // The connection survives: the modal finding is keyed on this id.
    expect($rid)->toStartWith('event-');
    expect(IntegrationConnection::query()->where('user_id', $user->id)
        ->where('resource_id', $rid)->where('resource_kind', 'event')->exists())->toBeTrue();

    // And the event actually publishes, which is the half round 1 was missing.
    expect(speItemCount($user))->toBe(1);
    expect(DB::connection('pgsql')->table('content.source_items')->value('coord'))
        ->toBe(ManualEventWriter::coordFor($url));
});

// A seeded event is in BOTH stores for the duration; neither selection reader
// may show it twice.
it('lists a seeded event once, not twice, in the unified selection', function () {
    $url = 'https://www.eventbrite.com/e/scanned-show';
    speMockEventbrite();
    $user = speUser('spe11');

    app(EventsSeeder::class)->seedStandalone($user, 'eventbrite', $url);

    $events = actingAsUser($user)->getJson('/api/platforms/events/selection')->assertOk()->json('selection.events');
    expect($events)->toHaveCount(1);

    $perPlatform = actingAsUser($user)->getJson('/api/platforms/eventbrite/selection')->assertOk()->json('selection.events');
    expect($perPlatform)->toHaveCount(1);
});

// A siteless owner cannot hold a pool item. The scan lane must still seed the
// connection rather than dropping the link on the floor — that is the whole
// point of it being best-effort.
it('still seeds the connection when the owner has no site to pin an item to', function () {
    $url = 'https://www.eventbrite.com/e/scanned-show';
    speMockEventbrite();
    $user = User::create([
        'handle' => 'spe12', 'handle_lc' => 'spe12', 'display_name' => 'Spe12', 'first_name' => 'Spe12',
        'account_type' => 'business', 'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'spe12@example.com',
    ]);

    $rid = app(EventsSeeder::class)->seedStandalone($user, 'eventbrite', $url);

    expect($rid)->toStartWith('event-');
    expect(speItemCount($user))->toBe(0);
});

it('carries the scraped event image into content.item_media as the cover', function () {
    // The scrape returns the event image (JSON-LD `image`, stored verbatim
    // in the payload) but projectStandalone() used to drop it under a Phase 3
    // ruling that LinkPoolWriter has since reversed for links and that
    // SchemaOrgEventProjector never had — so an event added by hand or found
    // in a bio link had no picture while the same event via a connected
    // organiser did (gsnwilliams, 2026-08-18). Same `media` projection as
    // the connector lane: a source_url-only asset, role `cover`.
    $url = 'https://www.eventbrite.com/e/cool-show-123';
    speMockEventbrite();
    $user = speUser('speimg');

    actingAsUser($user)->postJson('/api/platforms/eventbrite/events', ['url' => $url])->assertOk();

    $itemId = DB::connection('pgsql')->table('content.items')->where('user_id', $user->id)->value('id');
    $media = DB::connection('pgsql')->table('content.item_media')->where('item_id', $itemId)->get();
    expect($media)->toHaveCount(1)
        ->and($media[0]->role)->toBe('cover');

    $asset = DB::connection('pgsql')->table('content.media_assets')->where('id', $media[0]->asset_id)->first();
    expect($asset->source_url)->toBe('https://img.example/e.jpg');
});
