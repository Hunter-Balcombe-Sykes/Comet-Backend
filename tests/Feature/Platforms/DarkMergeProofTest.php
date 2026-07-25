<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\EventsPayload;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\HumanitixScraper;
use App\Services\Platforms\SkoolScraper;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// CA-DM — the consolidated dark-merge proof for the whole async-connect
// programme (CA-W2..CA-W7 + the events/add organiser branch).
//
// The merge-safety argument for landing every one of those units on
// `development` is a single claim: with PARTNA_CONNECT_DEFERRED unset,
// each converted endpoint behaves EXACTLY as it does today. Three independent
// reviews found the per-unit flag-off cases too weak to carry that claim —
// each asserted one or two keys with assertJsonPath while being NAMED as a
// byte-identity proof, and two surfaces (POST /api/platforms/humanitix/connect
// and the organiser branch of POST /api/platforms/events/add) had no
// exact-body coverage anywhere at all.
//
// So every case below pins THREE things per endpoint, and nothing less:
//   1. the exact HTTP status code,
//   2. the FULL response body via assertExactJson (never assertJsonPath on a
//      subset — a subset assertion cannot detect an ADDED key, which is
//      precisely the regression a dark merge would introduce),
//   3. Queue::assertNothingPushed() — no ConnectFetchJob, no anything.
//
// Plus the stored row: last_refresh_status + array_keys($row->payload), the
// latter a CONTENT assertion rather than a non-null check. `payload` is
// NOT NULL DEFAULT '{}' in Postgres while the suite runs SQLite, so a write
// that left a null/partial placeholder passes locally and 500s in production
// with SQLSTATE 23502.
//
// Naming: the flag-off cases follow this codebase's `DELIBERATELY VACUOUS —`
// convention (see DeferredConnectTest.php) so a future reviewer does not
// delete them as assertion-free no-ops. The irony to avoid is in the tail of
// this file: the LAST section flips the flag ON for two of the same endpoints
// and proves they invert to 202, which is what makes the flag-off cases
// sensitive to the thing they claim to measure rather than green by default.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Fresha's storewide connect projects the scraped menu into site.services
    // and takes the booking-XOR advisory lock on the way through.
    setupServicesTable();
    shimPgAdvisoryLockForSqlite();
});

function darkMergeUser(string $h, string $accountType = 'partna', ?string $sector = null): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => $accountType,
        'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** The account resource_id every multi-account connect derives from its canonical key. */
function darkMergeAcctId(string $canonicalKey): string
{
    return 'acct-'.substr(sha1(strtolower(trim($canonicalKey))), 0, 16);
}

/** A future-dated event so dropElapsed() never prunes it out of a selection. */
function darkMergeEvent(string $link): array
{
    return [
        'name' => 'Gig',
        'venue' => 'The Venue',
        'location' => 'Melbourne',
        'startDate' => '2099-01-01T00:00:00+00:00',
        'endDate' => '2099-01-02T00:00:00+00:00',
        'price' => 'Free',
        'availability' => 'available',
        'image' => 'https://img.example/e.jpg',
        'link' => $link,
    ];
}

// ── 1/7 + 2/7. Apple Music + Apple Podcast (multi-account) ──────────────────

it('DELIBERATELY VACUOUS — flag off: apple music connect is a 200 with today\'s exact body and pushes nothing', function () {
    // Vacuous by construction: config('partna.connect.deferred') is [] in every
    // test (phpunit.xml sets no PARTNA_CONNECT_DEFERRED), so this exercises the
    // pre-existing synchronous branch. That IS the point — it is the tripwire
    // for the flag-off default regressing, and unlike the per-unit version it
    // pins the whole body, so an ADDED key fails here too.
    config(['partna.connect.deferred' => []]);
    $user = darkMergeUser('dmapplemusic');

    $album = ['collectionId' => 'a1', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l'];
    $this->mock(AppleSearch::class, function ($m) use ($album) {
        $m->shouldReceive('fetchAlbums')->once()->andReturn([$album]);
        // Best-effort enrichment (#76) — stubbed to null so `genre` stays out
        // of the body deliberately rather than by accident.
        $m->shouldReceive('fetchGenre')->andReturn(null);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/apple/music/connect', ['artist' => 'Radiohead'])
        ->assertStatus(200)
        ->assertExactJson([
            'id' => darkMergeAcctId('Radiohead'),
            'input' => 'Radiohead',
            'name' => 'Album',
            'thumbnail' => 't',
            'releaseDate' => '2026-01-01',
            'link' => 'l',
            'latest' => $album,
            'highlights' => [],
        ]);

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'apple-music')->firstOrFail();
    expect($row->last_refresh_status)->toBe('ok');
    expect($row->last_refreshed_at)->not->toBeNull();
    expect(array_keys($row->payload))->toBe(['input', 'name', 'thumbnail', 'releaseDate', 'link', 'latest', 'highlights']);
});

it('DELIBERATELY VACUOUS — flag off: apple podcast connect is a 200 with today\'s exact body and pushes nothing', function () {
    config(['partna.connect.deferred' => []]);
    $user = darkMergeUser('dmapplepod');

    $episode = ['trackId' => 'e1', 'name' => 'Ep', 'thumbnail' => 't', 'description' => 'd', 'releaseDate' => '2026-02-02T00:00:00Z', 'link' => 'l'];
    $this->mock(AppleSearch::class, fn ($m) => $m->shouldReceive('fetchEpisodes')->once()->andReturn([$episode]));

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/apple/podcast/connect', ['show' => 'Serial'])
        ->assertStatus(200)
        ->assertExactJson([
            'id' => darkMergeAcctId('Serial'),
            'input' => 'Serial',
            'name' => 'Ep',
            'thumbnail' => 't',
            'description' => 'd',
            'releaseDate' => '2026-02-02T00:00:00Z',
            'link' => 'l',
            'latest' => $episode,
            'highlights' => [],
        ]);

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'apple-podcast')->firstOrFail();
    expect($row->last_refresh_status)->toBe('ok');
    expect(array_keys($row->payload))->toBe(['input', 'name', 'thumbnail', 'description', 'releaseDate', 'link', 'latest', 'highlights']);
});

// ── 3/7. Skool (single-selection; a vendor miss is a 404 TODAY) ─────────────

it('DELIBERATELY VACUOUS — flag off: skool connect is a 200 with today\'s exact body and pushes nothing', function () {
    config(['partna.connect.deferred' => []]);
    $user = darkMergeUser('dmskool');

    $this->mock(SkoolScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->once()->andReturn('https://www.skool.com/some-community');
        $m->shouldReceive('fetchCommunity')->once()->andReturn([
            'url' => 'https://www.skool.com/some-community',
            'name' => 'Some Community',
            'image' => 'https://img.example/avatar.jpg',
            'description' => 'A great community',
        ]);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/some-community'])
        ->assertStatus(200)
        ->assertExactJson([
            'url' => 'https://www.skool.com/some-community',
            'name' => 'Some Community',
            'image' => 'https://img.example/avatar.jpg',
            'description' => 'A great community',
        ]);

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'skool')->firstOrFail();
    expect($row->last_refresh_status)->toBe('ok');
    expect(array_keys($row->payload))->toBe(['url', 'name', 'image', 'description']);
});

it('DELIBERATELY VACUOUS — flag off: a skool vendor miss is still a 404 (not 422, not 502) and writes no row', function () {
    // Skool is the one converted endpoint whose vendor-miss status is 404 —
    // every other one 422s. Pinned separately because the deferred path CANNOT
    // reproduce a 404 (the vendor is not called at 202 time), so if activation
    // ever silently became the default, this is the assertion that changes.
    config(['partna.connect.deferred' => []]);
    $user = darkMergeUser('dmskoolmiss');

    $this->mock(SkoolScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->once()->andReturn('https://www.skool.com/gone');
        $m->shouldReceive('fetchCommunity')->once()->andReturn(null);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/gone'])
        ->assertStatus(404)
        ->assertExactJson(['message' => 'Could not read that Skool community — check the URL.']);

    Queue::assertNothingPushed();
    expect(IntegrationConnection::where('user_id', $user->id)->exists())->toBeFalse();
});

// ── 4/7 + 5/7. Eventbrite + Humanitix (multi-account) ───────────────────────

it('DELIBERATELY VACUOUS — flag off: eventbrite connect is a 200 with today\'s exact body and pushes nothing', function () {
    // PlatformResourceContractTest already pins this body; repeated here so the
    // seven-surface dark-merge argument is verifiable in ONE file, and with the
    // queue assertion that contract test does not make.
    config(['partna.connect.deferred' => []]);
    $user = darkMergeUser('dmeventbrite');
    $url = 'https://www.eventbrite.com/o/acme-1';
    $event = darkMergeEvent('https://www.eventbrite.com/e/gig-1');
    $stamped = EventsPayload::withIds([$event])[0];

    $this->mock(EventbriteScraper::class, function ($m) use ($url, $event) {
        $m->shouldReceive('normalizeOrgUrl')->once()->andReturn($url);
        $m->shouldReceive('fetchEvents')->once()->andReturn(['organiser' => 'Acme', 'events' => [$event]]);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => $url])
        ->assertStatus(200)
        ->assertExactJson([
            'id' => darkMergeAcctId($url),
            'url' => $url,
            'organiser' => 'Acme',
            'next' => $stamped,
            'upcoming' => [$stamped],
        ]);

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->firstOrFail();
    expect($row->last_refresh_status)->toBe('ok');
    // hiddenEventIds is stored but never on the connect wire — pinning the
    // stored key list catches a leak in either direction.
    expect(array_keys($row->payload))->toBe(['url', 'organiser', 'next', 'upcoming', 'hiddenEventIds']);
});

it('DELIBERATELY VACUOUS — flag off: humanitix connect is a 200 with today\'s exact body and pushes nothing', function () {
    // The gap the reviews found: NO test anywhere pinned this endpoint's body.
    // Humanitix is also the one platform whose URL normalisation is itself a
    // live fetch AND the row's identity (resolveHostUrl), so the row must be
    // keyed on the RESOLVED host url, not the posted one — asserted below.
    config(['partna.connect.deferred' => []]);
    $user = darkMergeUser('dmhumanitix');
    $postedUrl = 'https://events.humanitix.com/some-event';
    $hostUrl = 'https://events.humanitix.com/host/acme';
    $event = darkMergeEvent('https://events.humanitix.com/gig-1');
    $stamped = EventsPayload::withIds([$event])[0];

    $this->mock(HumanitixScraper::class, function ($m) use ($hostUrl, $event) {
        $m->shouldReceive('resolveHostUrl')->once()->andReturn($hostUrl);
        $m->shouldReceive('fetchEvents')->once()->andReturn(['organiser' => 'Acme', 'events' => [$event]]);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/humanitix/connect', ['url' => $postedUrl])
        ->assertStatus(200)
        ->assertExactJson([
            'id' => darkMergeAcctId($hostUrl),
            'url' => $hostUrl,
            'organiser' => 'Acme',
            'next' => $stamped,
            'upcoming' => [$stamped],
        ]);

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'humanitix')->firstOrFail();
    expect($row->resource_id)->toBe(darkMergeAcctId($hostUrl));
    expect($row->canonical_key)->toBe(strtolower(trim($hostUrl)));
    expect($row->last_refresh_status)->toBe('ok');
    expect(array_keys($row->payload))->toBe(['url', 'organiser', 'next', 'upcoming', 'hiddenEventIds']);
});

// ── 6/7. Fresha — BOTH capability modes ─────────────────────────────────────

it('DELIBERATELY VACUOUS — flag off: fresha TEAM-mode connect is a 200 with today\'s exact body and pushes nothing', function () {
    config(['partna.connect.deferred' => []]);
    $user = darkMergeUser('dmfreshateam'); // partna, no sector => team mode

    $member = ['employeeId' => 'e1', 'displayName' => 'Jo', 'jobTitle' => null, 'avatarUrl' => null, 'rating' => null];
    $service = ['serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null, 'price' => '$50', 'priceValue' => null, 'currency' => null, 'category' => 'Cuts', 'hasVariants' => false];

    $this->mock(FreshaScraper::class, function ($m) use ($member, $service) {
        $m->shouldReceive('stripLocale')->once()->andReturnUsing(fn ($u) => $u);
        $m->shouldReceive('fetchMenu')->once()->andReturn(['storeName' => 'Ollies', 'team' => [$member], 'services' => [$service]]);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(200)
        ->assertExactJson([
            'url' => 'https://www.fresha.com/a/ollies-salon',
            'mode' => 'team',
            'storeName' => 'Ollies',
            'team' => [$member],
            'services' => [$service],
        ]);

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail();
    expect($row->last_refresh_status)->toBe('ok');
    // The three private bookkeeping keys the deferred path introduces
    // (connectMode / teamMenu / connectPendingAt) must be entirely absent.
    expect(array_keys($row->payload))->toBe(['url', 'selection']);
});

it('DELIBERATELY VACUOUS — flag off: fresha STOREWIDE-mode connect is a 200 with today\'s exact body and pushes nothing', function () {
    // The storewide flag-off case the reviews flagged as asserting only
    // mode/url. Storewide is the mode whose WRITE depends on the fetch
    // (projector sync + a composed selection), so a partial assertion here was
    // the weakest link in the whole merge-safety argument.
    config(['partna.connect.deferred' => []]);
    $user = darkMergeUser('dmfreshastore', 'business', 'barber'); // non-food business => can_book_storewide

    $service = ['serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null, 'price' => '$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Cuts', 'hasVariants' => false];

    $this->mock(FreshaScraper::class, function ($m) use ($service) {
        $m->shouldReceive('stripLocale')->once()->andReturnUsing(fn ($u) => $u);
        $m->shouldReceive('fetchMenu')->once()->andReturn(['storeName' => 'Ollies', 'team' => [], 'services' => [$service]]);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(200)
        ->assertExactJson([
            'url' => 'https://www.fresha.com/a/ollies-salon',
            'mode' => 'storewide',
            'selection' => [
                'url' => 'https://www.fresha.com/a/ollies-salon',
                'storeName' => 'Ollies',
                'mode' => 'storewide',
                'employee' => null,
                'services' => [$service],
                'hiddenServiceIds' => [],
            ],
        ]);

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail();
    expect($row->last_refresh_status)->toBe('ok');
    expect(array_keys($row->payload))->toBe(['url', 'selection', 'raw']);
});

// ── 7/7. POST /api/platforms/events/add — the organiser branch ──────────────

it('DELIBERATELY VACUOUS — flag off: the events/add ORGANISER branch is a 200 with today\'s exact {selection} body and pushes nothing', function () {
    // The second gap the reviews found: this endpoint's body was pinned
    // nowhere. Its contract is {selection: <the unified accounts+events list>},
    // NOT the per-platform connect envelope — the deferred path returns
    // {status, selection, statusUrl} at 202 instead, so pinning the exact
    // flag-off body is what makes those two distinguishable.
    config(['partna.connect.deferred' => []]);
    $user = darkMergeUser('dmeventsadd');
    $orgUrl = 'https://www.eventbrite.com/o/darkmerge-org';
    $event = darkMergeEvent('https://www.eventbrite.com/e/darkmerge-gig');
    $stamped = EventsPayload::withIds([$event])[0];
    $rid = darkMergeAcctId($orgUrl);

    $this->mock(EventbriteScraper::class, function ($m) use ($orgUrl, $event) {
        $m->shouldReceive('normalizeEventUrl')->with($orgUrl)->andReturn(null);
        $m->shouldReceive('normalizeOrgUrl')->with($orgUrl)->andReturn($orgUrl);
        $m->shouldReceive('fetchEvents')->with($orgUrl)->andReturn(['organiser' => 'Acme', 'events' => [$event]]);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $orgUrl])
        ->assertStatus(200)
        ->assertExactJson([
            'selection' => [
                'accounts' => [[
                    'id' => $rid,
                    'platform' => 'eventbrite',
                    'url' => $orgUrl,
                    'organiser' => 'Acme',
                    'next' => $stamped,
                    'upcoming' => [$stamped],
                    'removePath' => "/platforms/eventbrite/accounts/{$rid}",
                ]],
                'events' => [[
                    ...$stamped,
                    'platform' => 'eventbrite',
                    'source' => 'account',
                    'accountId' => $rid,
                    'removePath' => "/platforms/eventbrite/events/{$stamped['id']}",
                ]],
            ],
        ]);

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->firstOrFail();
    expect($row->resource_id)->toBe($rid);
    expect($row->last_refresh_status)->toBe('ok');
    expect(array_keys($row->payload))->toBe(['url', 'organiser', 'next', 'upcoming', 'hiddenEventIds']);
});

it('DELIBERATELY VACUOUS — flag off: the events/add ORGANISER branch pins Humanitix keyed on its RESOLVED host url and pushes nothing', function () {
    // Humanitix's counterpart to the Eventbrite case above, and the more
    // load-bearing of the two: its accountUrl resolver (resolveHostUrl) is a
    // live network fetch AND the row's identity, so the posted url and the
    // stored/returned url differ here — same distinction the humanitix
    // connect case (4/7 section) pins, but this is the ONLY test anywhere
    // that pins it for the events/add facade's organiser branch.
    config(['partna.connect.deferred' => []]);
    $user = darkMergeUser('dmeventsaddhx');
    $postedUrl = 'https://events.humanitix.com/darkmerge-host-page';
    $hostUrl = 'https://events.humanitix.com/host/darkmerge-host';
    $event = darkMergeEvent('https://events.humanitix.com/darkmerge-gig');
    $stamped = EventsPayload::withIds([$event])[0];
    $rid = darkMergeAcctId($hostUrl);

    // The scraper is mocked outright — see the file-level "network fetch"
    // note in the class docblock area above the humanitix connect case (4/7)
    // for why: resolveHostUrl's own HTTP behaviour is HumanitixScraper's unit
    // tests' job, not this proof's.
    $this->mock(HumanitixScraper::class, function ($m) use ($postedUrl, $hostUrl, $event) {
        $m->shouldReceive('normalizeEventUrl')->with($postedUrl)->andReturn(null);
        $m->shouldReceive('resolveHostUrl')->with($postedUrl)->andReturn($hostUrl);
        $m->shouldReceive('fetchEvents')->with($hostUrl)->andReturn(['organiser' => 'Acme', 'events' => [$event]]);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $postedUrl])
        ->assertStatus(200)
        ->assertExactJson([
            'selection' => [
                'accounts' => [[
                    'id' => $rid,
                    'platform' => 'humanitix',
                    'url' => $hostUrl,
                    'organiser' => 'Acme',
                    'next' => $stamped,
                    'upcoming' => [$stamped],
                    'removePath' => "/platforms/humanitix/accounts/{$rid}",
                ]],
                'events' => [[
                    ...$stamped,
                    'platform' => 'humanitix',
                    'source' => 'account',
                    'accountId' => $rid,
                    'removePath' => "/platforms/humanitix/events/{$stamped['id']}",
                ]],
            ],
        ]);

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'humanitix')->firstOrFail();
    expect($row->resource_id)->toBe($rid);
    expect($row->last_refresh_status)->toBe('ok');
    expect(array_keys($row->payload))->toBe(['url', 'organiser', 'next', 'upcoming', 'hiddenEventIds']);
});

// ── The pending row's PUBLIC render ─────────────────────────────────────────

it('a pending row renders publicly with allowlisted keys ONLY — none of the deferred path\'s private bookkeeping leaks', function () {
    // A pending row is written is_active => true DELIBERATELY (so the sitepage
    // keeps rendering whatever the row already carried while the fetch is in
    // flight). That means every pending row IS on the public, CDN-cached wire —
    // so the private keys the deferred path invents must be filtered by
    // PublicIntegrationConnectionResource's per-platform allowlist, not merely
    // "not shown by the dashboard".
    //
    // Fresha is the sharp case: its pending payload carries connectMode,
    // teamMenu and connectPendingAt, and its public allowlist is exactly
    // ['url', 'selection']. Driven through the REAL flag-on endpoints rather
    // than seeded, so this asserts what the controllers actually write.
    config(['partna.connect.deferred' => ['fresha', 'skool', 'eventbrite', 'apple-music']]);
    $user = darkMergeUser('dmpublicpending');
    $ebUrl = 'https://www.eventbrite.com/o/pending-org';

    // Pure/parse-only doubles: any call to a real fetch method throws
    // (Mockery strict mock), which is itself part of the proof.
    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('stripLocale')->andReturnUsing(fn ($u) => $u));
    $this->mock(SkoolScraper::class, fn ($m) => $m->shouldReceive('normalizeUrl')->andReturn('https://www.skool.com/pending-community'));
    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('normalizeOrgUrl')->andReturn($ebUrl));
    $this->mock(AppleSearch::class, function ($m) {});

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])->assertStatus(202);
    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/pending-community'])->assertStatus(202);
    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => $ebUrl])->assertStatus(202);
    actingAsUser($user)->postJson('/api/platforms/apple/music/connect', ['artist' => 'Pending Artist'])->assertStatus(202);

    // Sanity: the STORED fresha payload really does carry the private keys, so
    // the public assertion below is filtering something rather than asserting
    // the absence of something that was never written.
    $fresha = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail();
    expect($fresha->last_refresh_status)->toBe('pending');
    expect($fresha->is_active)->toBeTrue();
    expect(array_keys($fresha->payload))->toBe(['url', 'selection', 'connectMode', 'teamMenu', 'connectPendingAt']);

    $platforms = $this->getJson('/api/public/profiles/dmpublicpending/integrations')
        ->assertOk()
        ->json('data.platforms');

    // fresha: allowlist is ['url', 'selection'] — connectMode / teamMenu /
    // connectPendingAt are gone, and `selection` (carried forward as null on a
    // first connect) is still present, exactly as a completed row would be.
    expect($platforms['fresha'][0]['payload'])->toBe([
        'url' => 'https://www.fresha.com/a/ollies-salon',
        'selection' => null,
    ]);
    expect($platforms['fresha'][0]['lastRefreshedAt'])->toBeNull();

    expect($platforms['skool'][0]['payload'])->toBe(['url' => 'https://www.skool.com/pending-community']);
    expect($platforms['eventbrite'][0]['payload'])->toBe(['url' => $ebUrl]);
    expect($platforms['apple-music'][0]['payload'])->toBe(['input' => 'Pending Artist']);

    // Belt-and-braces: no row's public payload carries ANY key outside its
    // platform's allowlist, expressed as the private-key blacklist the
    // deferred path is capable of producing.
    foreach ($platforms as $rows) {
        foreach ($rows as $row) {
            foreach (['teamMenu', 'connectMode', 'connectPendingAt', 'hiddenEventIds', 'raw'] as $private) {
                expect($row['payload'])->not->toHaveKey($private);
            }
        }
    }
});

// ── Sensitivity: the same endpoints INVERT when the flag names them ─────────

it('flag on: apple music connect inverts to a 202 pending envelope — proving the flag-off cases above are not vacuously green', function () {
    // The contrast case. If the flag check were deleted from AppleController
    // (or defaulted to on), the flag-off case at the top of this file would see
    // THIS body instead of the 200 it asserts and fail. Conversely if the
    // deferred branch were unreachable, this case fails. The two together are
    // what make the dark-merge claim falsifiable rather than decorative.
    config(['partna.connect.deferred' => ['apple-music']]);
    $user = darkMergeUser('dmflagonapple');

    // No expectations at all — ANY AppleSearch call throws, proving the vendor
    // is untouched on the deferred path.
    $this->mock(AppleSearch::class, function ($m) {});

    Queue::fake();

    $id = darkMergeAcctId('Radiohead');
    actingAsUser($user)->postJson('/api/platforms/apple/music/connect', ['artist' => 'Radiohead'])
        ->assertStatus(202)
        ->assertExactJson([
            'input' => 'Radiohead',
            'status' => 'pending',
            'id' => $id,
            'statusUrl' => url('/api/platforms/apple/music/connect/status').'?account='.$id,
        ]);

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'apple-music')->firstOrFail();
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->payload)->toBe(['input' => 'Radiohead']);

    Queue::assertPushed(ConnectFetchJob::class, fn ($job) => $job->connectionId === $row->id && $job->platform === 'apple-music');
});

it('flag on: fresha storewide connect inverts to a 202 pending envelope and never reaches the vendor', function () {
    // The second contrast case, on the hardest surface: storewide's write
    // depends on the fetch, so this is the one where "flag off is inert" is
    // least self-evident.
    config(['partna.connect.deferred' => ['fresha']]);
    $user = darkMergeUser('dmflagonfresha', 'business', 'barber');

    // fetchMenu has no expectation — a call throws.
    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('stripLocale')->once()->andReturnUsing(fn ($u) => $u));

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(202)
        ->assertExactJson([
            'url' => 'https://www.fresha.com/a/ollies-salon',
            'mode' => 'storewide',
            'status' => 'pending',
            'statusUrl' => url('/api/platforms/fresha/connect/status'),
        ]);

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail();
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->payload['connectMode'])->toBe('storewide');

    Queue::assertPushed(ConnectFetchJob::class, fn ($job) => $job->connectionId === $row->id && $job->platform === 'fresha');
});
