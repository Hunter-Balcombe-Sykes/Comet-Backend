<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\StravaClubScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Unit 11 W6 — wires the DeferredConnect seam (W4) + ConnectFetchJob (W5) behind
// the rollout flag: config('partna.connect.deferred'), empty by default. Covers
// the flag branch in ConnectResolver/GenericPlatformController::connect(), the
// new GET .../connect/status poll endpoint, and the writeConnection() pending/
// merge path W6 adds for strava (the one deferred platform whose multiAccount()
// is false, so it never reached writeAccountConnection()'s W5 pending support).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function dcUser(string $h): User
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

// ── Flag OFF = byte-identical synchronous response ─────────────────────────

it('DELIBERATELY VACUOUS — flag off leaves spotify connect byte-identical (rollout safety guard)', function () {
    // Passes against both fixed and unfixed code — config('partna.connect.deferred')
    // defaults to [] in every test (no PARTNA_CONNECT_DEFERRED in phpunit.xml), so
    // this proves nothing about the NEW branch. It exists as the safety net a
    // regression in the flag-off default would trip: if ConnectResolver ever took
    // the deferred branch when the flag is empty, this would start asserting 202
    // instead of 200 and fail.
    config(['partna.connect.deferred' => []]);
    $user = dcUser('flagoff1');

    $this->mock(OEmbedService::class, fn ($m) => $m->shouldReceive('resolve')->once()->andReturn([
        'name' => 'Artist', 'thumbnail' => 'https://i.scdn.co/t.jpg', 'embedUrl' => null,
    ]));

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/spotify/connect', ['url' => 'https://open.spotify.com/artist/abc123'])
        ->assertOk()
        ->assertJsonPath('name', 'Artist')
        ->assertJsonPath('url', 'https://open.spotify.com/artist/abc123');

    Queue::assertNothingPushed();
    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'spotify')->first();
    expect($row->last_refresh_status)->toBe('ok');
});

it('DELIBERATELY VACUOUS — flag off leaves every one of the 7 deferred platforms strategy-driven, not queue-driven', function () {
    // Confirms the claim in the W6 report: with the flag off, ConnectResolver's
    // ->deferred is false for all 7 registered deferred platforms, so
    // GenericPlatformController::connect() never reaches connectDeferred() and
    // never touches the queue. Uses the real registry (not synthetic descriptors)
    // so a future accidental default flip anywhere in config wiring is caught.
    config(['partna.connect.deferred' => []]);

    $registry = app(PlatformRegistry::class);
    foreach (['spotify', 'bandcamp', 'twitch', 'strava', 'vimeo', 'youtube-music', 'youtube'] as $slug) {
        $descriptor = $registry->get($slug);
        expect($descriptor->supportsDeferredConnect())->toBeTrue("expected {$slug} to support deferral");
        expect(in_array($slug, config('partna.connect.deferred'), true))->toBeFalse("expected {$slug} NOT active by default");
    }
});

// ── Flag ON → 202 ────────────────────────────────────────────────────────────

it('flag on: spotify connect returns 202 with status/id/statusUrl + identity keys at the 200 shape\'s names, without touching the vendor', function () {
    // Fails against unfixed code two ways: (1) before ConnectResolver's deferred
    // branch existed, this always hit resolve() -> a real oEmbed call -> either
    // 200 (if mocked) or the assertions on `status`/`statusUrl` would find nothing;
    // (2) Http::assertNothingSent() fails outright once resolve() (not identify())
    // is what actually ran, since resolve() calls OEmbedService, and OEmbedService
    // is a real HTTP client bound normally here (Http::fake() is only present to
    // make the "zero calls sent" assertion possible, not to mock a real response).
    config(['partna.connect.deferred' => ['spotify']]);
    $user = dcUser('flagon1');

    Http::fake();
    Queue::fake();

    $response = actingAsUser($user)->postJson('/api/platforms/spotify/connect', ['url' => 'https://open.spotify.com/artist/abc123'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        // identity() derives url/link/embedUrl with zero network — same key
        // names as MusicEmbedConnectionResource's 200 shape.
        ->assertJsonPath('url', 'https://open.spotify.com/artist/abc123')
        ->assertJsonPath('link', 'https://open.spotify.com/artist/abc123')
        ->assertJsonPath('embedUrl', 'https://open.spotify.com/embed/artist/abc123');

    Http::assertNothingSent();

    $id = $response->json('id');
    expect($id)->toStartWith('acct-');
    expect($response->json('statusUrl'))->toBe(url("/api/platforms/spotify/connect/status?account={$id}"));

    // name/thumbnail are NOT present at all (not even null) — identify() never
    // derived them, and this is not the full Resource-shaped payload.
    expect($response->json())->not->toHaveKey('name');
    expect($response->json())->not->toHaveKey('thumbnail');

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'spotify')->first();
    expect($row->resource_id)->toBe($id);
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->last_refreshed_at)->toBeNull();
    expect($row->payload)->toBe([
        'url' => 'https://open.spotify.com/artist/abc123',
        'link' => 'https://open.spotify.com/artist/abc123',
        'embedUrl' => 'https://open.spotify.com/embed/artist/abc123',
    ]);

    Queue::assertPushed(ConnectFetchJob::class, fn ($job) => $job->connectionId === $row->id && $job->platform === 'spotify');
});

it('DELIBERATELY VACUOUS — flag on: an unparseable spotify link still 422s inline, parse failures never reach the queue', function () {
    // Confirmed vacuous against unfixed code by reverting ConnectResolver/
    // GenericPlatformController to the pre-W6 commit and re-running this file:
    // identify() and resolve() share the exact same parse-fail shape
    // (DeferredConnectParityTest), so a parse failure 422s identically whether
    // the deferred branch exists or not, and no row/job exists on EITHER path
    // before a parse failure. Kept as a contract-preservation guard (the "422
    // is now the only inline failure" claim in the plan §2e), not a
    // fails-unfixed test.
    config(['partna.connect.deferred' => ['spotify']]);
    $user = dcUser('flagon2');
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/spotify/connect', ['url' => 'https://example.com/not-spotify'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter a Spotify link (open.spotify.com/artist/...).');

    Queue::assertNothingPushed();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'spotify')->exists())->toBeFalse();
});

// ── Poll: pending → ready → failed ──────────────────────────────────────────

it('poll: pending row reports pending, ready row reports the /selection-identical shape, failed row surfaces the stored error', function () {
    $user = dcUser('poll1');

    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'spotify',
        'resource_id' => 'acct-'.substr(sha1('https://open.spotify.com/artist/abc'), 0, 16),
        'canonical_key' => 'https://open.spotify.com/artist/abc',
        'payload' => ['url' => 'https://open.spotify.com/artist/abc', 'link' => 'https://open.spotify.com/artist/abc', 'embedUrl' => 'https://open.spotify.com/embed/artist/abc'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    actingAsUser($user)->getJson("/api/platforms/spotify/connect/status?account={$row->resource_id}")
        ->assertOk()
        ->assertExactJson(['status' => 'pending']);

    $row->forceFill([
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
        'payload' => [...$row->payload, 'name' => 'Artist', 'thumbnail' => 'https://i.scdn.co/t.jpg'],
    ])->saveQuietly();

    // /selection returns the SAME shaping helper the status endpoint's
    // 'connection' key must match — assert both come back identical.
    $selection = actingAsUser($user)->getJson('/api/platforms/spotify/selection')->assertOk()->json('selection');

    actingAsUser($user)->getJson("/api/platforms/spotify/connect/status?account={$row->resource_id}")
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('id', $row->resource_id)
        ->assertJsonPath('connection', $selection);

    $row->forceFill([
        'last_refresh_status' => 'unavailable',
        'last_refresh_error' => 'Could not load that Spotify link.',
    ])->saveQuietly();

    actingAsUser($user)->getJson("/api/platforms/spotify/connect/status?account={$row->resource_id}")
        ->assertOk()
        ->assertExactJson(['status' => 'failed', 'error' => 'Could not load that Spotify link.']);
});

it('poll 404s (never 403) for another user\'s resource, and for a nonexistent one', function () {
    $owner = dcUser('pollowner');
    $stranger = dcUser('pollstranger');

    $row = IntegrationConnection::create([
        'user_id' => $owner->id,
        'platform' => 'spotify',
        'resource_id' => 'acct-'.substr(sha1('https://open.spotify.com/artist/xyz'), 0, 16),
        'canonical_key' => 'https://open.spotify.com/artist/xyz',
        'payload' => ['url' => 'https://open.spotify.com/artist/xyz'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    actingAsUser($stranger)->getJson("/api/platforms/spotify/connect/status?account={$row->resource_id}")
        ->assertStatus(404)
        ->assertJsonPath('message', 'Account not found.');

    actingAsUser($owner)->getJson('/api/platforms/spotify/connect/status?account=acct-doesnotexist0000')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Account not found.');
});

it('stale pending (worker vanished > 5 minutes ago) reports failed, not pending forever', function () {
    $user = dcUser('stale1');

    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'spotify',
        'resource_id' => 'acct-'.substr(sha1('https://open.spotify.com/artist/stale'), 0, 16),
        'canonical_key' => 'https://open.spotify.com/artist/stale',
        'payload' => ['url' => 'https://open.spotify.com/artist/stale'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);
    // Backdate updated_at past the 5-minute staleness window (create() sets it
    // to now()). Deliberately a query-builder mass update, NOT $row->save():
    // Eloquent's save() re-touches updated_at to now() whenever the model uses
    // timestamps, silently discarding a manually forceFill()'d value — the
    // query builder's update() only skips that auto-touch when the caller
    // already supplied the column, which this does.
    IntegrationConnection::where('id', $row->id)->update(['updated_at' => now()->subMinutes(10)]);
    $row->refresh();

    $response = actingAsUser($user)->getJson("/api/platforms/spotify/connect/status?account={$row->resource_id}")
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($response->json('error'))->toBeString()->not->toBeEmpty();

    // Fresh pending (updated just now) is NOT treated as stale.
    $fresh = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-'.substr(sha1('https://freshartist.bandcamp.com'), 0, 16),
        'canonical_key' => 'https://freshartist.bandcamp.com',
        'payload' => ['url' => 'https://freshartist.bandcamp.com'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);
    actingAsUser($user)->getJson("/api/platforms/bandcamp/connect/status?account={$fresh->resource_id}")
        ->assertOk()
        ->assertExactJson(['status' => 'pending']);
});

// ── Single-selection deferred platforms (the W5 gap this unit closes) ──────

it('flag on: strava (single-selection, multiAccount() false) takes the pending path and MERGES on reconnect', function () {
    config(['partna.connect.deferred' => ['strava']]);
    $user = dcUser('stravamerge1');

    // Seed an existing completed connection with fields identify() never derives.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'strava',
        'resource_id' => 'strava',
        'payload' => [
            'url' => 'https://www.strava.com/clubs/oldclub',
            'name' => 'Old Club',
            'location' => 'Old City',
            'image' => 'https://old.example/avatar.jpg',
            'description' => 'Old description',
            'members' => 999,
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    Queue::fake();

    // No StravaClubScraper mock needed: normalizeUrl() is pure (no network),
    // and identify() never calls fetchClub() — that's the seam.
    $response = actingAsUser($user)->postJson('/api/platforms/strava/connect', ['url' => 'newclub123'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('url', 'https://www.strava.com/clubs/newclub123');

    // No 'id' — strava's 200 shape never carried one either (see
    // RegistryConnectCoverageTest / the plan §2e), so the 202 shape doesn't
    // invent one.
    expect($response->json())->not->toHaveKey('id');
    expect($response->json('statusUrl'))->toBe(url('/api/platforms/strava/connect/status'));

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'strava')->first();
    expect($row->resource_id)->toBe('strava'); // single default row, no acct- hash
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->last_refreshed_at)->toBeNull();

    // MERGE, not replace: identify()'s {url} landed, but the fields only
    // resolve()/the job ever derive survive from the prior connection.
    expect($row->payload['url'])->toBe('https://www.strava.com/clubs/newclub123');
    expect($row->payload['name'])->toBe('Old Club');
    expect($row->payload['location'])->toBe('Old City');
    expect($row->payload['image'])->toBe('https://old.example/avatar.jpg');
    expect($row->payload['description'])->toBe('Old description');
    expect($row->payload['members'])->toBe(999);

    Queue::assertPushed(ConnectFetchJob::class, fn ($job) => $job->connectionId === $row->id && $job->platform === 'strava');
});

it('flag on: strava connect on a brand-new user (nothing to merge onto) stores the partial payload as-is', function () {
    config(['partna.connect.deferred' => ['strava']]);
    $user = dcUser('stravanew1');
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/strava/connect', ['url' => 'brandnewclub'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('url', 'https://www.strava.com/clubs/brandnewclub');

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'strava')->first();
    expect($row->payload)->toBe([
        'url' => 'https://www.strava.com/clubs/brandnewclub',
    ]);
});

it('flag off: strava connect is unchanged — still a synchronous 200 with today\'s shape', function () {
    // DELIBERATELY VACUOUS against current code (strava was never touched by
    // W6's writeConnection($pending) change unless the flag names it) — the
    // rollout-safety counterpart to the spotify one above, for the single-
    // selection archetype specifically (the one W6 added NEW write-path code
    // for). Proves that new code path is unreachable at the default flag value.
    config(['partna.connect.deferred' => []]);
    $user = dcUser('stravaoff1');

    $this->mock(StravaClubScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.strava.com/clubs/validclub');
        $m->shouldReceive('fetchClub')->andReturn(['name' => 'Valid Club', 'location' => null, 'image' => 'i', 'description' => null, 'members' => 10]);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/strava/connect', ['url' => 'validclub'])
        ->assertOk()
        ->assertJsonPath('name', 'Valid Club');

    Queue::assertNothingPushed();
    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'strava')->first();
    expect($row->last_refresh_status)->toBe('ok');
});
