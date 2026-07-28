<?php

use App\Ingest\SourceProvisioner;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The connect-time seam (plan §4): a platform connection with a registered
// connector gets exactly one ingest.sources row, with the connector's real
// Pull identifier derived from what the connection actually stores. Runs the
// REAL observer path — connections are created through the model, and the
// provisioner assertions are about what landed in ingest.sources afterwards.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
});

function provisionerUser(): string
{
    return createTenant('prov-'.Str::lower(Str::random(6)))->id;
}

/** @param array<string, mixed> $attributes */
function makeConnection(string $userId, array $attributes): IntegrationConnection
{
    return IntegrationConnection::create($attributes + [
        'user_id' => $userId,
        'resource_id' => 'acct-'.substr(sha1(Str::random(8)), 0, 16),
        'payload' => [],
        'is_active' => true,
    ]);
}

function ingestSourceFor(IntegrationConnection $connection): ?object
{
    return DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
}

// ── Identifier derivation, per platform ─────────────────────────────────────

it('provisions a bandcamp source from the payload artist origin url', function () {
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'bandcamp',
        'payload' => ['url' => 'https://kinggizzard.bandcamp.com', 'link' => 'https://kinggizzard.bandcamp.com/album/x'],
    ]);

    $row = ingestSourceFor($connection);
    expect($row)->not->toBeNull()
        ->and($row->source_key)->toBe('bandcamp')
        ->and($row->identifier)->toBe('https://kinggizzard.bandcamp.com')
        ->and($row->surface_key)->toBe('bandcamp.artist')
        ->and((bool) $row->auto_sync)->toBeTrue();
});

it('provisions vimeo from apiPath, spotify from the entity url, and google business from place_id', function () {
    $userId = provisionerUser();

    $vimeo = makeConnection($userId, ['platform' => 'vimeo', 'payload' => ['apiPath' => 'patagonia']]);
    $spotify = makeConnection($userId, ['platform' => 'spotify', 'payload' => ['url' => 'https://open.spotify.com/artist/5INjqkS1o8h1imAzPqGZBb']]);
    $google = makeConnection($userId, ['platform' => 'google-business', 'place_id' => 'ChIJizFTarNC1moRjM6M4Z_OGAg']);

    expect(ingestSourceFor($vimeo)->identifier)->toBe('patagonia')
        ->and(ingestSourceFor($spotify)->identifier)->toBe('https://open.spotify.com/artist/5INjqkS1o8h1imAzPqGZBb')
        ->and(ingestSourceFor($google)->identifier)->toBe('ChIJizFTarNC1moRjM6M4Z_OGAg');
});

it('extracts the numeric apple id from both apple url grammars', function () {
    $userId = provisionerUser();

    $music = makeConnection($userId, ['platform' => 'apple-music', 'payload' => ['input' => 'https://music.apple.com/au/artist/tame-impala/290242959']]);
    $podcasts = makeConnection($userId, ['platform' => 'apple-podcast', 'payload' => ['input' => 'https://podcasts.apple.com/au/podcast/the-daily/id1200361736']]);

    expect(ingestSourceFor($music)->identifier)->toBe('290242959')
        ->and(ingestSourceFor($podcasts)->identifier)->toBe('1200361736');
});

it('derives the fresha slug from the booking url and the youtube handle as a resolvable identifier', function () {
    $userId = provisionerUser();

    $fresha = makeConnection($userId, ['platform' => 'fresha', 'payload' => ['url' => 'https://fresha.com/a/brotherwolf-south-melbourne-s82k3a7o']]);
    $youtube = makeConnection($userId, ['platform' => 'youtube', 'payload' => ['handle' => 'mkbhd']]);

    expect(ingestSourceFor($fresha)->identifier)->toBe('brotherwolf-south-melbourne-s82k3a7o')
        ->and(ingestSourceFor($youtube)->identifier)->toBe('mkbhd');
});

it('accepts a real identifier stored in resource_id (seeded showcase rows)', function () {
    $userId = provisionerUser();

    $youtube = makeConnection($userId, ['platform' => 'youtube', 'resource_id' => 'UCLA_DiR1FfKNvjuUpBHmylQ']);
    $spotify = makeConnection($userId, ['platform' => 'spotify', 'resource_id' => 'artist/4gzpq5DPGxSnKTe4SA8HAU']);
    $substack = makeConnection($userId, ['platform' => 'substack', 'resource_id' => 'thebrowser']);

    expect(ingestSourceFor($youtube)->identifier)->toBe('UCLA_DiR1FfKNvjuUpBHmylQ')
        ->and(ingestSourceFor($spotify)->identifier)->toBe('https://open.spotify.com/artist/4gzpq5DPGxSnKTe4SA8HAU')
        ->and(ingestSourceFor($substack)->identifier)->toBe('thebrowser');
});

// ── The gate: no guessing ───────────────────────────────────────────────────

it('creates no source when no usable identifier can be derived', function () {
    // Legacy placeholder resource_id + empty payload: a wrong guess here
    // would poll somebody else's catalogue, so the only correct row is none.
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'bandcamp',
        'resource_id' => 'bandcamp',
        'payload' => [],
    ]);

    expect(ingestSourceFor($connection))->toBeNull();
});

it('creates no source for a brand with no registered connector', function () {
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'instagram',
        'payload' => ['username' => 'nasa'],
    ]);

    expect(ingestSourceFor($connection))->toBeNull();
});

it('creates no source for event- and link-grade resource rows', function () {
    $user = provisionerUser();
    $connection = IntegrationConnection::create([
        'user_id' => $user,
        'platform' => 'custom',
        'resource_id' => 'link-'.substr(sha1('x'), 0, 16),
        'resource_kind' => 'link',
        'payload' => ['url' => 'https://example.com'],
        'is_active' => true,
    ]);

    expect(ingestSourceFor($connection))->toBeNull();
});

// ── Lifecycle ───────────────────────────────────────────────────────────────

it('is idempotent: re-syncing the same connection writes exactly one row', function () {
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'bandcamp',
        'payload' => ['url' => 'https://artist.bandcamp.com'],
    ]);

    app(SourceProvisioner::class)->sync($connection);
    app(SourceProvisioner::class)->sync($connection);

    expect(DB::table('ingest.sources')->where('connection_id', $connection->id)->count())->toBe(1);
});

it('updates the identifier and re-schedules when the payload identity changes, without touching learned cadence', function () {
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'bandcamp',
        'payload' => ['url' => 'https://old.bandcamp.com'],
    ]);

    // Simulate a learned schedule the provisioner must not clobber.
    DB::table('ingest.sources')->where('connection_id', $connection->id)->update([
        'change_rate' => 0.9,
        'consecutive_failures' => 2,
        'next_attempt_at' => now()->addDays(3),
    ]);

    $connection->payload = ['url' => 'https://new.bandcamp.com'];
    $connection->save();

    $row = ingestSourceFor($connection);
    expect($row->identifier)->toBe('https://new.bandcamp.com')
        ->and((float) $row->change_rate)->toBe(0.9)
        ->and((int) $row->consecutive_failures)->toBe(2)
        ->and(strtotime((string) $row->next_attempt_at))->toBeLessThanOrEqual(time() + 5);
});

it('turns auto_sync off on deactivate and soft-delete, and back on on restore', function () {
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'bandcamp',
        'payload' => ['url' => 'https://artist.bandcamp.com'],
    ]);
    expect((bool) ingestSourceFor($connection)->auto_sync)->toBeTrue();

    $connection->is_active = false;
    $connection->save();
    expect((bool) ingestSourceFor($connection)->auto_sync)->toBeFalse();

    $connection->is_active = true;
    $connection->save();
    expect((bool) ingestSourceFor($connection)->auto_sync)->toBeTrue();

    $connection->delete();
    expect((bool) ingestSourceFor($connection)->auto_sync)->toBeFalse();

    $connection->restore();
    expect((bool) ingestSourceFor($connection)->auto_sync)->toBeTrue();
});

it('never creates a row for an inactive connection', function () {
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'bandcamp',
        'payload' => ['url' => 'https://artist.bandcamp.com'],
        'is_active' => false,
    ]);

    expect(ingestSourceFor($connection))->toBeNull();
});

it('creates billed-connector sources unscheduled until their effect drivers exist', function () {
    // HttpIo::runBilledEffect throws for every billed effect until the P7
    // drivers land — scheduling google_business now would error every run
    // until the source went dead. The row must exist (the seam is complete);
    // it just must not auto-run yet.
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'google-business',
        'place_id' => 'ChIJizFTarNC1moRjM6M4Z_OGAg',
    ]);

    $row = ingestSourceFor($connection);
    expect($row)->not->toBeNull()
        ->and((bool) $row->auto_sync)->toBeFalse();

    // A later payload write must not silently re-enable it either.
    $connection->payload = ['placeId' => 'ChIJizFTarNC1moRjM6M4Z_OGAg'];
    $connection->save();
    expect((bool) ingestSourceFor($connection)->auto_sync)->toBeFalse();
});

it('seeds scheduling defaults from the connector manifest', function () {
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'fresha',
        'payload' => ['url' => 'https://fresha.com/a/some-salon-abc'],
    ]);

    $row = ingestSourceFor($connection);
    // FreshaConnector declares 172800s; the band must open up to at least a week.
    expect((int) $row->min_interval_secs)->toBe(172800)
        ->and((int) $row->max_interval_secs)->toBe(604800)
        ->and((int) $row->cost_units)->toBe(1);
});

it('provisions eventbrite from a regional organiser url normalized to .com, and humanitix from host or event-derived urls', function () {
    $userId = provisionerUser();

    $eventbrite = makeConnection($userId, ['platform' => 'eventbrite', 'payload' => ['url' => 'https://www.eventbrite.com.au/o/Laneway-Collective-1234567890']]);
    $humanitix = makeConnection($userId, ['platform' => 'humanitix', 'payload' => ['url' => 'https://events.humanitix.com/host/Run-Club-Melbourne?ref=x']]);

    expect(ingestSourceFor($eventbrite)->identifier)->toBe('https://www.eventbrite.com/o/laneway-collective-1234567890')
        ->and(ingestSourceFor($humanitix)->identifier)->toBe('https://events.humanitix.com/host/run-club-melbourne');
});

it('refuses a spoofed eventbrite host and a humanitix event page with no host path', function () {
    $userId = provisionerUser();

    $spoofed = makeConnection($userId, ['platform' => 'eventbrite', 'payload' => ['url' => 'https://eventbrite.evil.com/o/fake-1']]);
    $eventOnly = makeConnection($userId, ['platform' => 'humanitix', 'payload' => ['url' => 'https://events.humanitix.com/dawn-run-august']]);

    expect(ingestSourceFor($spoofed))->toBeNull()
        ->and(ingestSourceFor($eventOnly))->toBeNull();
});

it('provisions soundcloud from a payload link url or a bare profile slug in resource_id', function () {
    $userId = provisionerUser();

    $linked = makeConnection($userId, ['platform' => 'soundcloud', 'payload' => ['url' => 'https://soundcloud.com/forss/sets/soulhack']]);
    $bare = makeConnection($userId, ['platform' => 'soundcloud', 'resource_id' => 'forss', 'payload' => []]);
    $placeholder = makeConnection($userId, ['platform' => 'soundcloud', 'resource_id' => 'soundcloud', 'payload' => []]);

    expect(ingestSourceFor($linked)->identifier)->toBe('https://soundcloud.com/forss/sets/soulhack')
        ->and(ingestSourceFor($bare)->identifier)->toBe('https://soundcloud.com/forss')
        // The legacy placeholder slug is not an identity.
        ->and(ingestSourceFor($placeholder))->toBeNull();
});

it('provisions twitch from the payload login, lowercased, and refuses placeholders', function () {
    $userId = provisionerUser();

    $login = makeConnection($userId, ['platform' => 'twitch', 'payload' => ['login' => 'SomeStreamer']]);
    $placeholder = makeConnection($userId, ['platform' => 'twitch', 'resource_id' => 'twitch', 'payload' => []]);

    expect(ingestSourceFor($login)->identifier)->toBe('somestreamer')
        ->and(ingestSourceFor($placeholder))->toBeNull();
});

it('provisions skool and strava as canonical community urls, refusing product chrome slugs', function () {
    $userId = provisionerUser();

    $skool = makeConnection($userId, ['platform' => 'skool', 'payload' => ['url' => 'https://skool.com/Max-Business-School/about?ref=x']]);
    $skoolChrome = makeConnection($userId, ['platform' => 'skool', 'payload' => ['url' => 'https://www.skool.com/signup']]);
    $strava = makeConnection($userId, ['platform' => 'strava', 'payload' => ['url' => 'https://strava.com/clubs/Midday-Milers']]);
    $stravaBare = makeConnection($userId, ['platform' => 'strava', 'resource_id' => '289149', 'payload' => []]);

    expect(ingestSourceFor($skool)->identifier)->toBe('https://www.skool.com/max-business-school')
        ->and(ingestSourceFor($skoolChrome))->toBeNull()
        ->and(ingestSourceFor($strava)->identifier)->toBe('https://www.strava.com/clubs/Midday-Milers')
        ->and(ingestSourceFor($stravaBare)->identifier)->toBe('https://www.strava.com/clubs/289149');
});

it('provisions youtube_music only from a real UC channel id', function () {
    $userId = provisionerUser();

    $withId = makeConnection($userId, ['platform' => 'youtube-music', 'payload' => ['channelId' => 'UCabcdefghijklmnopqrstuv']]);
    $handleOnly = makeConnection($userId, ['platform' => 'youtube-music', 'payload' => ['handle' => 'someartist']]);

    expect(ingestSourceFor($withId)->identifier)->toBe('UCabcdefghijklmnopqrstuv')
        // Unlike plain youtube there is no handle fallback for Topic channels.
        ->and(ingestSourceFor($handleOnly))->toBeNull();
});

it('provisions gumroad from the store subdomain, never the apex or www', function () {
    $userId = provisionerUser();

    $store = makeConnection($userId, ['platform' => 'gumroad', 'payload' => ['url' => 'https://Easlo.gumroad.com/l/brain']]);
    $apex = makeConnection($userId, ['platform' => 'gumroad', 'payload' => ['url' => 'https://gumroad.com/discover']]);

    expect(ingestSourceFor($store)->identifier)->toBe('easlo')
        ->and(ingestSourceFor($apex))->toBeNull();
});

// ── Backfill command ────────────────────────────────────────────────────────

it('backfills sources for existing connections and reports skips', function () {
    $userId = provisionerUser();
    $bandcamp = makeConnection($userId, ['platform' => 'bandcamp', 'payload' => ['url' => 'https://artist.bandcamp.com']]);
    $orphan = makeConnection($userId, ['platform' => 'youtube', 'resource_id' => 'youtube', 'payload' => []]);

    // Wipe what the observer already provisioned — the command must stand alone.
    DB::table('ingest.sources')->delete();

    $this->artisan('ingest:backfill-sources', ['--user' => $userId])
        ->expectsOutputToContain('no_identifier')
        ->assertSuccessful();

    expect(DB::table('ingest.sources')->where('connection_id', $bandcamp->id)->count())->toBe(1)
        ->and(DB::table('ingest.sources')->where('connection_id', $orphan->id)->count())->toBe(0);
});

it('backfill --dry-run writes nothing', function () {
    $userId = provisionerUser();
    makeConnection($userId, ['platform' => 'bandcamp', 'payload' => ['url' => 'https://artist.bandcamp.com']]);
    DB::table('ingest.sources')->delete();

    $this->artisan('ingest:backfill-sources', ['--user' => $userId, '--dry-run' => true])
        ->assertSuccessful();

    expect(DB::table('ingest.sources')->count())->toBe(0);
});
