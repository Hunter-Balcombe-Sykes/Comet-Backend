<?php

use App\Ingest\Manifest\CostClass;
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

// T27c: the social feed pair. TikTok provisions off the router-shaped
// username; Facebook canonicalises whatever page-URL shape enrichment stored
// — plain vanity, hyphenated legacy pretty-URL, or /pages/<name>/<id> (the
// numeric id resolves on facebook.com). A %-encoded pseudo-handle stays a
// skip: it was never a page URL.
it('provisions tiktok from the username and facebook from every real page-url shape', function () {
    $userId = provisionerUser();

    $tiktok = makeConnection($userId, ['platform' => 'tiktok', 'payload' => ['url' => 'https://www.tiktok.com/@BourkeStreetBakery', 'username' => '@BourkeStreetBakery']]);
    $vanity = makeConnection($userId, ['platform' => 'facebook', 'payload' => ['url' => 'https://www.facebook.com/IndependentBakingCo/', 'username' => 'IndependentBakingCo']]);
    $legacy = makeConnection($userId, ['platform' => 'facebook', 'payload' => ['url' => 'https://www.facebook.com/Le-Taj-Restaurant-Lounge-186167158111059']]);
    $pages = makeConnection($userId, ['platform' => 'facebook', 'payload' => ['url' => 'http://www.facebook.com/pages/Amiconi-Restaurant/159505710742510']]);
    $junk = makeConnection($userId, ['platform' => 'facebook', 'payload' => ['url' => 'https://facebook.com/basette%20Barberia', 'username' => 'basette Barberia']]);

    expect(ingestSourceFor($tiktok)->identifier)->toBe('bourkestreetbakery')
        ->and(ingestSourceFor($vanity)->identifier)->toBe('https://www.facebook.com/IndependentBakingCo')
        ->and(ingestSourceFor($legacy)->identifier)->toBe('https://www.facebook.com/Le-Taj-Restaurant-Lounge-186167158111059')
        ->and(ingestSourceFor($pages)->identifier)->toBe('https://www.facebook.com/159505710742510')
        ->and(ingestSourceFor($junk))->toBeNull();
});

it('derives the fresha slug from the booking url and the youtube handle as a resolvable identifier', function () {
    $userId = provisionerUser();

    $fresha = makeConnection($userId, ['platform' => 'fresha', 'payload' => ['url' => 'https://fresha.com/a/brotherwolf-south-melbourne-s82k3a7o']]);
    $youtube = makeConnection($userId, ['platform' => 'youtube', 'payload' => ['handle' => 'mkbhd']]);

    expect(ingestSourceFor($fresha)->identifier)->toBe('brotherwolf-south-melbourne-s82k3a7o')
        ->and(ingestSourceFor($youtube)->identifier)->toBe('mkbhd');
});

it('provisions youtube from the router-shaped payload (username, not handle)', function () {
    // The routing lane writes ConnectionPayload::forWrite → {url, source,
    // username} for handle-kind surfaces; the legacy connect flow wrote
    // `handle`. Both spellings of the same fact must provision. This is the
    // 2026-08-18 gsnwilliams gap: a link-in-bio youtube.com/@x placed a
    // connection row and then silently skipped with no_identifier.
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'youtube',
        'resource_id' => 'dvlpmnttv',
        'payload' => ['url' => 'https://youtube.com/@dvlpmnttv', 'source' => 'link_in_bio', 'username' => 'dvlpmnttv'],
    ]);

    $row = ingestSourceFor($connection);
    expect($row)->not->toBeNull()
        ->and($row->identifier)->toBe('dvlpmnttv');
});

it('derives the fresha slug only from a real fresha host, locale segment and all', function () {
    // #TEST-3/D1 hardening: freshaSlug() used to be the only URL extractor in
    // this class with no host anchor — `fresha.com/a/…` matches inside a
    // hostile host's query string too. The locale group is the regression
    // guard on the fix itself: legacy/seeded rows may still hold a
    // `/en-au/a/…` path from before FreshaScraper::stripLocale existed, and a
    // naive anchor would silently stop provisioning those.
    $userId = provisionerUser();

    $locale = makeConnection($userId, ['platform' => 'fresha', 'payload' => ['url' => 'https://www.fresha.com/en-au/a/brotherwolf-s82k3a7o']]);
    $noLocale = makeConnection($userId, ['platform' => 'fresha', 'payload' => ['url' => 'https://fresha.com/a/brotherwolf-s82k3a7o']]);
    $wrongHost = makeConnection($userId, ['platform' => 'fresha', 'payload' => ['url' => 'https://notfresha.com/a/rival-salon']]);
    $spoofedQuery = makeConnection($userId, ['platform' => 'fresha', 'payload' => ['url' => 'https://evil.example/?next=fresha.com/a/rival-salon']]);

    expect(ingestSourceFor($locale)->identifier)->toBe('brotherwolf-s82k3a7o')
        ->and(ingestSourceFor($noLocale)->identifier)->toBe('brotherwolf-s82k3a7o')
        ->and(ingestSourceFor($wrongHost))->toBeNull()
        ->and(ingestSourceFor($spoofedQuery))->toBeNull();
});

it('derives a stable identifier from every fresha url shape live on dev', function () {
    // identifierFor() feeds every seeded Fresha row, and sync() treats a changed
    // identifier as "a different remote thing" and resets next_attempt_at. So the
    // four shapes that already resolve are pinned to their CURRENT dev values
    // byte-for-byte: this case exists to fail if the book-now alternative ever
    // perturbs one of them, not merely to show book-now working.
    //
    // Shapes are the five live dev connections (2026-08-14) plus the legacy
    // locale form, which no live row carries but the regex must keep accepting.
    $userId = provisionerUser();

    $shapes = [
        // www + /a/ — the common case
        'https://www.fresha.com/a/edward-scissorhands-balaclava-st-kilda-barber-melbourne-190-carlisle-street-g3vzbzld' => 'edward-scissorhands-balaclava-st-kilda-barber-melbourne-190-carlisle-street-g3vzbzld',
        'https://www.fresha.com/a/vision-hair-studio-melbourne-520-522-city-road-tzo6gxk0' => 'vision-hair-studio-melbourne-520-522-city-road-tzo6gxk0',
        // bare host, no www
        'https://fresha.com/a/brotherwolf-south-melbourne-melbourne-295-clarendon-street-s82k3a7o' => 'brotherwolf-south-melbourne-melbourne-295-clarendon-street-s82k3a7o',
        'https://www.fresha.com/a/some-salon-abc123' => 'some-salon-abc123',
        // The share URL Fresha's own app hands out. Trailing path and query are
        // discarded — including pId, which is NOT wired into selection.
        'https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260' => 'anseo-studio-v0v92jna',
        // Legacy, pre-stripLocale
        'https://www.fresha.com/en-au/a/brotherwolf-s82k3a7o' => 'brotherwolf-s82k3a7o',
    ];

    foreach ($shapes as $url => $expected) {
        $connection = makeConnection($userId, ['platform' => 'fresha', 'payload' => ['url' => $url]]);

        expect(ingestSourceFor($connection)?->identifier)->toBe($expected, "url: {$url}");
    }
});

it('keeps the book-now alternative anchored to a real fresha host', function () {
    // The book-now branch is a second alternative inside the SAME anchored
    // pattern, so it inherits the §17 host anchor. Pinned separately because an
    // unanchored book-now branch is the exact regression the anchor exists to
    // stop, and it would not show up in the shape table above.
    $userId = provisionerUser();

    $wrongHost = makeConnection($userId, ['platform' => 'fresha', 'payload' => ['url' => 'https://notfresha.com/book-now/rival-salon/all-offer']]);
    $spoofedQuery = makeConnection($userId, ['platform' => 'fresha', 'payload' => ['url' => 'https://evil.example/?next=https://www.fresha.com/book-now/rival-salon/all-offer']]);
    $spoofedPath = makeConnection($userId, ['platform' => 'fresha', 'payload' => ['url' => 'https://evil.example/book-now/rival-salon/all-offer']]);

    expect(ingestSourceFor($wrongHost))->toBeNull()
        ->and(ingestSourceFor($spoofedQuery))->toBeNull()
        ->and(ingestSourceFor($spoofedPath))->toBeNull();
});

it('accepts a real identifier stored in resource_id (seeded showcase rows)', function () {
    $userId = provisionerUser();

    // Substack was a third case here until Phase 1 de-sourced it — see the
    // demotion test below, which now pins that it provisions nothing.
    $youtube = makeConnection($userId, ['platform' => 'youtube', 'resource_id' => 'UCLA_DiR1FfKNvjuUpBHmylQ']);
    $spotify = makeConnection($userId, ['platform' => 'spotify', 'resource_id' => 'artist/4gzpq5DPGxSnKTe4SA8HAU']);

    expect(ingestSourceFor($youtube)->identifier)->toBe('UCLA_DiR1FfKNvjuUpBHmylQ')
        ->and(ingestSourceFor($spotify)->identifier)->toBe('https://open.spotify.com/artist/4gzpq5DPGxSnKTe4SA8HAU');
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
    // Kick is a real catalog surface with no ingest connector (instagram,
    // the old example here, gained one with the P7 fleet).
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'kick',
        'payload' => ['handle' => 'somestreamer'],
    ]);

    expect(ingestSourceFor($connection))->toBeNull();
});

it('refuses malformed handles and spoofed hosts rather than provisioning a wrong identity', function () {
    // The finding's stated failure mode is a regex typo that silently returns
    // null and kills a platform forever — already covered by the 21 positive
    // cases above. This is the OPPOSITE, actively harmful direction: a
    // too-permissive regex that provisions a source pointed at somebody
    // else's catalogue, which the class docblock calls "far worse than no row".
    $userId = provisionerUser();

    $spotifyHostSpoof = makeConnection($userId, ['platform' => 'spotify', 'payload' => ['url' => 'https://open.spotify.com.evil.example/artist/abc']]);
    $spotifyMalformedResource = makeConnection($userId, ['platform' => 'spotify', 'resource_id' => 'artist', 'payload' => []]);
    $instagramTrailingDot = makeConnection($userId, ['platform' => 'instagram', 'payload' => ['username' => 'bad.name.']]);
    $instagramTooLong = makeConnection($userId, ['platform' => 'instagram', 'payload' => ['username' => str_repeat('a', 31)]]);
    $twitchTooShort = makeConnection($userId, ['platform' => 'twitch', 'payload' => ['login' => 'ab']]);
    $twitchHyphen = makeConnection($userId, ['platform' => 'twitch', 'payload' => ['login' => 'has-dash']]);
    $bandcampHostSpoof = makeConnection($userId, ['platform' => 'bandcamp', 'payload' => ['url' => 'https://kinggizzard.bandcamp.com.evil.example/']]);
    $gumroadReservedSubdomain = makeConnection($userId, ['platform' => 'gumroad', 'payload' => ['url' => 'https://www.gumroad.com/l/x']]);

    expect(ingestSourceFor($spotifyHostSpoof))->toBeNull()
        ->and(ingestSourceFor($spotifyMalformedResource))->toBeNull()
        ->and(ingestSourceFor($instagramTrailingDot))->toBeNull()
        ->and(ingestSourceFor($instagramTooLong))->toBeNull()
        ->and(ingestSourceFor($twitchTooShort))->toBeNull()
        ->and(ingestSourceFor($twitchHyphen))->toBeNull()
        ->and(ingestSourceFor($bandcampHostSpoof))->toBeNull()
        ->and(ingestSourceFor($gumroadReservedSubdomain))->toBeNull();
});

it('creates no source for event- and link-grade resource rows', function () {
    $user = provisionerUser();
    $connection = IntegrationConnection::create([
        'user_id' => $user,
        'platform' => 'uber_eats.order',
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

it('leaves next_attempt_at alone when a payload write does not change the identifier', function () {
    // The mirror image of the test above: an UNCHANGED identifier must not
    // reset scheduling state. The existing idempotence test only asserts row
    // COUNT, so this is the one lifecycle branch that was actually unpinned.
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'bandcamp',
        'payload' => ['url' => 'https://artist.bandcamp.com'],
    ]);

    DB::table('ingest.sources')->where('connection_id', $connection->id)->update([
        'change_rate' => 0.7,
        'consecutive_failures' => 3,
        'next_attempt_at' => now()->addDays(3),
    ]);

    $result = app(SourceProvisioner::class)->sync($connection->fresh());

    expect($result['status'])->toBe('unchanged')
        ->and($result['source_key'])->toBe('bandcamp');

    $row = ingestSourceFor($connection);
    expect((float) $row->change_rate)->toBe(0.7)
        ->and((int) $row->consecutive_failures)->toBe(3)
        ->and(strtotime((string) $row->next_attempt_at))->toBeGreaterThan(time() + 60);
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

it('reports each sync outcome by name so the backfill command can count them', function () {
    // sync()'s whole array{status, source_key?, reason?} return shape is
    // otherwise unasserted anywhere — every existing test observes the
    // ingest.sources side-effect through the observer, never the value
    // sync() itself hands back to a direct caller (the backfill command).
    $userId = provisionerUser();
    $provisioner = app(SourceProvisioner::class);

    $linkRow = IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'uber_eats.order',
        'resource_id' => 'link-'.substr(sha1('t32link'), 0, 16),
        'resource_kind' => 'link',
        'payload' => ['url' => 'https://example.com'],
        'is_active' => true,
    ]);
    $kick = makeConnection($userId, ['platform' => 'kick', 'payload' => ['handle' => 'somestreamer']]);
    $placeholder = makeConnection($userId, ['platform' => 'bandcamp', 'resource_id' => 'bandcamp', 'payload' => []]);
    $inactive = makeConnection($userId, ['platform' => 'bandcamp', 'payload' => ['url' => 'https://artist.bandcamp.com'], 'is_active' => false]);
    $trashed = makeConnection($userId, ['platform' => 'bandcamp', 'payload' => ['url' => 'https://artist2.bandcamp.com']]);
    $trashed->delete();
    $fresh = makeConnection($userId, ['platform' => 'bandcamp', 'payload' => ['url' => 'https://artist3.bandcamp.com']]);
    // Wipe what the observer already provisioned for $fresh so sync() sees a
    // genuine no-existing-row case and reports 'created'.
    DB::table('ingest.sources')->where('connection_id', $fresh->id)->delete();

    expect($provisioner->sync($linkRow))->toBe(['status' => 'skipped', 'reason' => 'resource_row'])
        ->and($provisioner->sync($kick))->toBe(['status' => 'skipped', 'reason' => 'no_connector'])
        ->and($provisioner->sync($placeholder))->toBe(['status' => 'skipped', 'reason' => 'no_identifier', 'source_key' => 'bandcamp'])
        ->and($provisioner->sync($inactive->fresh()))->toBe(['status' => 'deactivated', 'source_key' => 'bandcamp'])
        ->and($provisioner->sync($trashed->fresh()))->toBe(['status' => 'retired', 'source_key' => 'bandcamp'])
        ->and($provisioner->sync($fresh))->toBe(['status' => 'created', 'source_key' => 'bandcamp']);

    // 'updated' and 'unchanged' — the last two statuses. Review caught
    // 'updated' missing; writing it surfaced that 'unchanged' was unpinned
    // too. A contract test enumerating five of seven states is worse than
    // none, because the enumeration itself implies completeness.
    //
    // Reaching 'updated' needs care: IntegrationConnectionObserver calls
    // sync() on save(), so simply moving the payload URL and then calling
    // sync() by hand returns 'unchanged' — the observer already did the
    // update. Staling the stored identifier directly is what forces the
    // `$existing->identifier !== $identifier` branch deterministically.
    DB::table('ingest.sources')
        ->where('connection_id', $fresh->id)
        ->update(['identifier' => 'stale-identifier']);

    expect($provisioner->sync($fresh->fresh()))->toBe(['status' => 'updated', 'source_key' => 'bandcamp'])
        // Immediately re-syncing the same row now changes nothing — which is
        // the 'unchanged' arm, and also proves the update above really did
        // converge rather than rewriting on every call.
        ->and($provisioner->sync($fresh->fresh()))->toBe(['status' => 'unchanged', 'source_key' => 'bandcamp']);
});

it('creates billed-connector sources unscheduled even once their effect drivers exist', function () {
    // R8 allow-lists google_business/spotify/soundcloud for the scheduler; this
    // test pins the invariant that paid connectors are OFF unless allow-listed.
    config(['partna.ingest_scheduled_paid_sources' => []]);
    // The drivers exist as of slice 0, but auto_sync stays false: turning on paid
    // auto-sync is a spend decision for the slice that consumes the data. The row
    // must exist (the seam is complete); it just must not auto-run yet.
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

it('provisions no ingest source for the five platforms Phase 1 demoted to link-only', function (string $platform, array $attributes) {
    // Each of these had a connector, a registry line and an identifierFor()
    // branch; Phase 1 deleted all three. The demotion is a behaviour, so it
    // gets a positive assertion rather than only the absence of the old tests.
    //
    // The refusal is structural, not a special case: sourceKeyFor() gates on
    // ConnectorRegistry::has(), so with the entry gone sync() returns
    // 'no_connector' and never reaches identifierFor(). That is why these
    // payloads are the ones that USED to provision successfully — if a
    // connector were ever re-registered without re-adding its identifierFor()
    // branch, this test would go red rather than silently provision nulls.
    $connection = makeConnection(provisionerUser(), ['platform' => $platform] + $attributes);

    expect(ingestSourceFor($connection))->toBeNull();
})->with([
    'twitch' => ['twitch', ['payload' => ['login' => 'SomeStreamer']]],
    'skool' => ['skool', ['payload' => ['url' => 'https://skool.com/Max-Business-School/about?ref=x']]],
    'strava' => ['strava', ['payload' => ['url' => 'https://strava.com/clubs/Midday-Milers']]],
    'gumroad' => ['gumroad', ['payload' => ['url' => 'https://Easlo.gumroad.com/l/brain']]],
    'substack' => ['substack', ['resource_id' => 'thebrowser', 'payload' => []]],
]);

it('provisions youtube_music only from a real UC channel id', function () {
    $userId = provisionerUser();

    $withId = makeConnection($userId, ['platform' => 'youtube-music', 'payload' => ['channelId' => 'UCabcdefghijklmnopqrstuv']]);
    $handleOnly = makeConnection($userId, ['platform' => 'youtube-music', 'payload' => ['handle' => 'someartist']]);

    expect(ingestSourceFor($withId)->identifier)->toBe('UCabcdefghijklmnopqrstuv')
        // Unlike plain youtube there is no handle fallback for Topic channels.
        ->and(ingestSourceFor($handleOnly))->toBeNull();
});

it('provisions instagram from the payload username, unscheduled (actor-billed, manual-only)', function () {
    $userId = provisionerUser();

    $connection = makeConnection($userId, ['platform' => 'instagram', 'payload' => ['username' => '@Some.Studio']]);

    $row = ingestSourceFor($connection);
    expect($row->identifier)->toBe('some.studio')
        // CostClass::Actor: the scheduler must never pick this up on its own.
        ->and((bool) $row->auto_sync)->toBeFalse();
});

it('provisions menu sources only from urls the platform host-pattern recognises', function () {
    $userId = provisionerUser();

    $squareOrder = makeConnection($userId, ['platform' => 'square-ordering', 'payload' => ['url' => 'https://fat-tuna.square.site/menu?mode=pickup']]);
    // A square BOOKING link (squareup.com) is not a scrapeable menu.
    $squareBook = makeConnection($userId, ['platform' => 'square', 'payload' => ['url' => 'https://squareup.com/appointments/book/abc']]);
    // #SEC-3: a Square Online CUSTOM domain (order.<merchant>.com) no longer
    // provisions. The host pattern's `^order\.(?!...)` arm was an
    // allowlist-by-exclusion — every order.* host that was not one of five named
    // competitors matched, so order.attacker.example was scraped and rendered
    // publicly under Square's brand. It cannot be anchored, because a real Square
    // Online custom domain is indistinguishable from an attacker's by hostname;
    // app/Catalog/Definitions/Square.php reached the same conclusion and gives
    // square.order no detector at all. Owner ruling 2026-08-26: drop the arm and
    // accept that such a store must be connected explicitly.
    $squareCustomDomain = makeConnection($userId, ['platform' => 'square-ordering', 'payload' => ['url' => 'https://order.fat-tuna.com/menu?mode=pickup']]);
    $uber = makeConnection($userId, ['surface_key' => 'uber_eats.order', 'payload' => ['url' => 'https://www.ubereats.com/au/store/doc-pizza/abc?diningMode=DELIVERY']]);
    $doordash = makeConnection($userId, ['surface_key' => 'doordash.order', 'payload' => ['url' => 'https://www.doordash.com/store/burger-republic-123/']]);

    expect(ingestSourceFor($squareOrder)->identifier)->toBe('https://fat-tuna.square.site/menu')
        ->and((bool) ingestSourceFor($squareOrder)->auto_sync)->toBeFalse()
        ->and(ingestSourceFor($squareBook))->toBeNull()
        ->and(ingestSourceFor($squareCustomDomain))->toBeNull()
        ->and(ingestSourceFor($uber)->identifier)->toBe('https://www.ubereats.com/au/store/doc-pizza/abc')
        ->and(ingestSourceFor($doordash)->identifier)->toBe('https://www.doordash.com/store/burger-republic-123');
});

// ── Selection ref (which sub-account's menu to fetch) ───────────────────────

function freshaConnection(array $payloadExtras): IntegrationConnection
{
    return makeConnection(provisionerUser(), [
        'platform' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/some-salon-abc123'] + $payloadExtras,
    ]);
}

it('writes the chosen employee id onto the ingest source', function () {
    $connection = freshaConnection(['selection' => ['mode' => 'employee', 'employee' => ['employeeId' => '4891132']]]);

    app(SourceProvisioner::class)->sync($connection);

    expect(DB::table('ingest.sources')->where('connection_id', $connection->id)->value('selection_ref'))
        ->toBe('4891132');
});

it('writes the storewide token when the owner chose the whole store', function () {
    $connection = freshaConnection(['selection' => ['mode' => 'storewide']]);

    app(SourceProvisioner::class)->sync($connection);

    expect(DB::table('ingest.sources')->where('connection_id', $connection->id)->value('selection_ref'))
        ->toBe('storewide');
});

it('leaves selection_ref null when nothing has been chosen', function () {
    $connection = freshaConnection(['selection' => null]);

    app(SourceProvisioner::class)->sync($connection);

    expect(DB::table('ingest.sources')->where('connection_id', $connection->id)->value('selection_ref'))
        ->toBeNull();
});

// The one that matters operationally: without this, switching who you are
// takes up to max_interval_secs (7 days) to show on the site.
it('refetches soon when the selection changes', function () {
    $connection = freshaConnection(['selection' => ['mode' => 'employee', 'employee' => ['employeeId' => '111']]]);
    app(SourceProvisioner::class)->sync($connection);
    DB::table('ingest.sources')->where('connection_id', $connection->id)
        ->update(['next_attempt_at' => now()->addDays(7)]);

    $connection->payload = ['url' => $connection->payload['url'], 'selection' => ['mode' => 'employee', 'employee' => ['employeeId' => '222']]];
    app(SourceProvisioner::class)->sync($connection);

    $row = DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    expect($row->selection_ref)->toBe('222')
        ->and(strtotime((string) $row->next_attempt_at))->toBeLessThanOrEqual(time() + 60);
});

it('ignores a stray employee id when mode does not explicitly say employee, matching the scheduled fetch path', function () {
    // FreshaFetch::fetch() only treats a connection as employee-mode when
    // `mode === 'employee'` literally -- an employee object surviving with no
    // mode, or a mode the picker never writes, must land no selection here
    // either, or the two paths would disagree about who the owner is.
    $modeAbsent = freshaConnection(['selection' => ['employee' => ['employeeId' => '999']]]);
    $modeOther = freshaConnection(['selection' => ['mode' => 'pending', 'employee' => ['employeeId' => '999']]]);

    app(SourceProvisioner::class)->sync($modeAbsent);
    app(SourceProvisioner::class)->sync($modeOther);

    expect(DB::table('ingest.sources')->where('connection_id', $modeAbsent->id)->value('selection_ref'))
        ->toBeNull()
        ->and(DB::table('ingest.sources')->where('connection_id', $modeOther->id)->value('selection_ref'))
        ->toBeNull();
});

it('never treats an employee id equal to the reserved storewide token as one', function () {
    // If a scraped employee id ever collided with the literal 'storewide',
    // returning it unguarded would make the connector fetch the WHOLE
    // STORE'S menu onto one individual's page -- exactly what selection_ref
    // exists to prevent.
    $connection = freshaConnection(['selection' => ['mode' => 'employee', 'employee' => ['employeeId' => 'storewide']]]);

    app(SourceProvisioner::class)->sync($connection);

    expect(DB::table('ingest.sources')->where('connection_id', $connection->id)->value('selection_ref'))
        ->toBeNull();
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

// ── ingest:backfill-sources --connector (slice 4) ────────────────────────────
//
// Provisioning is not free: it hands the scheduler a row it will then RUN, and
// several connectors are billed. The menu lane must not spend money on Google
// Places or an Apify Instagram run as a side effect of proving itself.
//
// square-ordering stands in for the menu platforms here because it is in
// LegacyPlatformMap::ROUTING_CLASS, so the fixture does not depend on the
// compiled catalog being present. The flag it exercises is connector-agnostic.

it('provisions only the named connectors when --connector is given', function () {
    $userId = provisionerUser();
    $square = makeConnection($userId, ['platform' => 'square-ordering', 'payload' => ['url' => 'https://order.square.site/merchant/abc']]);
    $fresha = makeConnection($userId, ['platform' => 'fresha', 'payload' => ['url' => 'https://fresha.com/a/brotherwolf-s82k3a7o']]);

    DB::table('ingest.sources')->delete();

    $this->artisan('ingest:backfill-sources', [
        '--user' => $userId,
        '--connector' => ['square'],
    ])->assertSuccessful();

    expect(ingestSourceFor($square))->not->toBeNull()
        ->and(ingestSourceFor($fresha))->toBeNull();
});

it('provisions every connector when --connector is omitted', function () {
    $userId = provisionerUser();
    $square = makeConnection($userId, ['platform' => 'square-ordering', 'payload' => ['url' => 'https://order.square.site/merchant/abc']]);
    $fresha = makeConnection($userId, ['platform' => 'fresha', 'payload' => ['url' => 'https://fresha.com/a/brotherwolf-s82k3a7o']]);

    DB::table('ingest.sources')->delete();

    $this->artisan('ingest:backfill-sources', ['--user' => $userId])->assertSuccessful();

    expect(ingestSourceFor($square))->not->toBeNull()
        ->and(ingestSourceFor($fresha))->not->toBeNull();
});

it('accepts several --connector values at once', function () {
    $userId = provisionerUser();
    $square = makeConnection($userId, ['platform' => 'square-ordering', 'payload' => ['url' => 'https://order.square.site/merchant/abc']]);
    $fresha = makeConnection($userId, ['platform' => 'fresha', 'payload' => ['url' => 'https://fresha.com/a/brotherwolf-s82k3a7o']]);
    $youtube = makeConnection($userId, ['platform' => 'youtube', 'payload' => ['handle' => 'mkbhd']]);

    DB::table('ingest.sources')->delete();

    $this->artisan('ingest:backfill-sources', [
        '--user' => $userId,
        '--connector' => ['square', 'fresha'],
    ])->assertSuccessful();

    expect(ingestSourceFor($square))->not->toBeNull()
        ->and(ingestSourceFor($fresha))->not->toBeNull()
        ->and(ingestSourceFor($youtube))->toBeNull();
});

// ── Cost class changes (convergence Phase 4) ────────────────────────────────

it('turns auto_sync OFF when its connector has become paid, and corrects the weight', function () {
    // R8 allow-lists google_business/spotify/soundcloud for the scheduler; this
    // test pins the invariant that paid connectors are OFF unless allow-listed.
    config(['partna.ingest_scheduled_paid_sources' => []]);
    // Phase 4 flipped spotify from a keyless oEmbed (Free) to an Apify actor
    // (Actor). Rows provisioned in the free era carry auto_sync=true and
    // cost_units=1, and nothing used to turn either back down — so the
    // scheduler would have kept dispatching a now-BILLED connector, charged at
    // the free weight. The seam that makes a connector paid has to be the seam
    // that stops it auto-running.
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'spotify',
        'payload' => ['url' => 'https://open.spotify.com/artist/5INjqkS1o8h1imAzPqGZBb'],
    ]);

    // Simulate the free-era row this connection would have had.
    DB::table('ingest.sources')
        ->where('connection_id', $connection->id)
        ->update(['auto_sync' => true, 'cost_units' => 1]);

    app(SourceProvisioner::class)->sync($connection->fresh());

    $row = ingestSourceFor($connection);
    expect((bool) $row->auto_sync)->toBeFalse()
        ->and((int) $row->cost_units)->toBe(CostClass::Actor->budgetWeight());
});

it('provisions a paid connector unscheduled from the start', function () {
    // R8 allow-lists google_business/spotify/soundcloud for the scheduler; this
    // test pins the invariant that paid connectors are OFF unless allow-listed.
    config(['partna.ingest_scheduled_paid_sources' => []]);
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'soundcloud',
        'payload' => ['url' => 'https://soundcloud.com/flume'],
    ]);

    $row = ingestSourceFor($connection);
    expect((bool) $row->auto_sync)->toBeFalse()
        ->and((int) $row->cost_units)->toBe(CostClass::Actor->budgetWeight());
});

it('leaves a free connector scheduled and does not churn its weight', function () {
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'bandcamp',
        'payload' => ['url' => 'https://artist.bandcamp.com'],
    ]);

    $before = ingestSourceFor($connection);
    expect(app(SourceProvisioner::class)->sync($connection->fresh())['status'])->toBe('unchanged');

    $row = ingestSourceFor($connection);
    expect((bool) $row->auto_sync)->toBeTrue()
        ->and((int) $row->cost_units)->toBe((int) $before->cost_units);
});

// Nightwatch #469 (2026-08-28): the observer's deleted() hook re-provisions,
// and on a FORCE delete the connection row is already gone — trashed() reads
// false (force deletion sets no deleted_at), so the insert pointed at a
// vanished platform_connections id and raised a 23503 the observer caught and
// reported. Force deletion is a real path: account erasure and GDPR use it.
it('retires rather than re-provisioning when a connection is force-deleted', function () {
    $userId = provisionerUser();
    $connection = makeConnection($userId, [
        'platform' => 'bandcamp',
        'payload' => ['url' => 'https://kinggizzard.bandcamp.com'],
    ]);

    expect(ingestSourceFor($connection))->not->toBeNull();

    // The model exactly as the observer sees it mid-forceDelete: Laravel
    // flips this protected flag for the duration of the delete, which is the
    // only signal distinguishing a force delete from a soft one.
    (function () {
        $this->forceDeleting = true;
    })->call($connection);

    $result = app(SourceProvisioner::class)->sync($connection);

    expect($result['status'])->toBe('retired')
        ->and((bool) DB::table('ingest.sources')->where('connection_id', $connection->id)->value('auto_sync'))->toBeFalse();
});

it('provisions the id a facebook profile.php link carries, rather than nothing', function () {
    // Bondi Junction Dental, 2026-08-31. GoogleBusinessAutoSync seeds this
    // exact pair: FacebookNormalizer lifts the ?id= into `username` precisely
    // so the identity survives a URL with no vanity handle in it. Reading the
    // payload as `url ?? username` threw that away — ?? short-circuits on the
    // key that IS present, so the url's refusal became the whole answer and a
    // reachable page provisioned nothing.
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'facebook',
        'payload' => [
            'username' => '100068321000028',
            'url' => 'https://www.facebook.com/profile.php?id=100068321000028',
            'source' => 'google-business',
        ],
    ]);

    expect(ingestSourceFor($connection)?->identifier)->toBe('https://www.facebook.com/100068321000028');
});

it('retires an existing source whose identifier has stopped resolving, instead of skipping past it', function () {
    // The hole the profile.php fix left: no_identifier returned BEFORE both
    // the retirement path and the identifier update, so the live row kept its
    // dead identifier and its schedule. Bandcamp rather than facebook because
    // a Free connector's row is auto_sync = true from birth — the flip is only
    // observable where there was something to switch off.
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'bandcamp',
        'payload' => ['url' => 'https://kinggizzard.bandcamp.com'],
    ]);

    expect((bool) ingestSourceFor($connection)->auto_sync)->toBeTrue();

    $connection->payload = [];
    $connection->save();

    $result = app(SourceProvisioner::class)->sync($connection);

    expect($result)->toBe(['status' => 'retired', 'reason' => 'no_identifier', 'source_key' => 'bandcamp'])
        ->and((bool) ingestSourceFor($connection)->auto_sync)->toBeFalse()
        // Retired, not erased: the row still owns its streams and records, and
        // the identifier it used to claim is the only trace of what broke.
        ->and(ingestSourceFor($connection)->identifier)->toBe('https://kinggizzard.bandcamp.com');
});

it('re-schedules the retired source the moment a resolvable identifier comes back', function () {
    // What makes retirement defensible rather than destructive: a payload that
    // was merely mid-write costs one unscheduled tick, not a dead row.
    $connection = makeConnection(provisionerUser(), [
        'platform' => 'bandcamp',
        'payload' => ['url' => 'https://kinggizzard.bandcamp.com'],
    ]);

    $connection->payload = [];
    $connection->save();
    expect((bool) ingestSourceFor($connection)->auto_sync)->toBeFalse();

    $connection->payload = ['url' => 'https://mildhighclub.bandcamp.com'];
    $connection->save();

    $row = ingestSourceFor($connection);
    expect((bool) $row->auto_sync)->toBeTrue()
        ->and($row->identifier)->toBe('https://mildhighclub.bandcamp.com');
});

it('lists a retired-for-no-identifier connection in the backfill report, not just a skipped one', function () {
    // The report keys on "does this connection sync", not on the status word.
    // A retirement is the arm that unschedules a row that WAS running, so
    // dropping it from the table would hide the louder of the two outcomes
    // behind the quieter one.
    $userId = provisionerUser();
    $broken = makeConnection($userId, ['platform' => 'bandcamp', 'payload' => ['url' => 'https://artist.bandcamp.com']]);
    $broken->payload = [];
    $broken->save();

    expect(ingestSourceFor($broken))->not->toBeNull();

    $this->artisan('ingest:backfill-sources', ['--user' => $userId])
        ->expectsOutputToContain('no_identifier')
        ->assertSuccessful();
});
