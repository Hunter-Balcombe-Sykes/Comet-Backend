<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Http\FetchBudget;
use App\Services\Notifications\Dispatchers\IntegrationNotifier;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Unit 11 W6 — wires the DeferredConnect seam (W4) + ConnectFetchJob (W5) behind
// the rollout flag: config('partna.connect.deferred'), empty by default. Covers
// the flag branch in ConnectResolver/GenericPlatformController::connect() and
// the GET .../connect/status poll endpoint.
//
// It USED to also cover GenericPlatformController's writeConnection() pending/
// merge branch via strava — see the note further down where those three cases
// sat; strava's demotion to link-only removed the last registered platform of
// that archetype.

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

it('DELIBERATELY VACUOUS — flag off leaves every one of the 5 deferred platforms strategy-driven, not queue-driven', function () {
    // Confirms the claim in the W6 report: with the flag off, ConnectResolver's
    // ->deferred is false for all registered deferred platforms, so
    // GenericPlatformController::connect() never reaches connectDeferred() and
    // never touches the queue. Uses the real registry (not synthetic descriptors)
    // so a future accidental default flip anywhere in config wiring is caught.
    //
    // Was 7: twitch and strava dropped off this census when they were demoted
    // to link-only (PD::linkOnly — no ConnectStrategy, so no ->deferredConnect()).
    // The remaining five ARE the registry's whole deferred set; this list is a
    // census, so it must shrink with the registry rather than assert survivors
    // that no longer exist.
    config(['partna.connect.deferred' => []]);

    $registry = app(PlatformRegistry::class);
    foreach (['spotify', 'bandcamp', 'vimeo', 'youtube-music', 'youtube'] as $slug) {
        $descriptor = $registry->get($slug);
        expect($descriptor->supportsDeferredConnect())->toBeTrue("expected {$slug} to support deferral");
        expect(in_array($slug, config('partna.connect.deferred'), true))->toBeFalse("expected {$slug} NOT active by default");
    }

    // ...and the list above really is the WHOLE set, so a platform quietly
    // gaining (or losing) deferral fails here rather than drifting unnoticed.
    $deferred = collect($registry->all())
        ->filter(fn ($d) => $d->supportsDeferredConnect())
        ->keys()->sort()->values()->all();
    // spotify_podcasts joined 2026-09-01 (Item 11f).
    expect($deferred)->toBe(['bandcamp', 'spotify', 'spotify_podcasts', 'vimeo', 'youtube', 'youtube-music']);
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

    // NOT an ordering proof — Queue::fake() means the job never actually runs,
    // so this passes byte-identically whether the dispatch sits inside or
    // outside the lock closure. The self-deadlock guard (#TEST-4) lives in
    // DeferredConnectSelfDeadlockTest.php, which deliberately does NOT fake
    // the queue.
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

// ── Single-selection deferred platforms (the W5 gap this unit closed) ──────
//
// REMOVED (twitch/skool/strava demotion to link-only): three cases lived here —
//   1. 'flag on: strava (single-selection, multiAccount() false) takes the
//      pending path and MERGES on reconnect'
//   2. 'flag on: strava connect on a brand-new user … stores the partial payload as-is'
//   3. 'flag off: strava connect is unchanged — still a synchronous 200'
// They exercised GenericPlatformController's writeConnection(pending: true)
// branch, and strava was chosen because it was the ONLY registered platform
// that was both deferred-capable and single-selection. It is now
// PD::linkOnly() — no ConnectStrategy, no ->deferredConnect() — and no
// surviving platform has that shape (the five deferred platforms are all
// multiAccount()), so there is nothing to re-point them to: driving them
// through bandcamp/spotify would silently test writeAccountConnection()
// instead, which the spotify cases above already cover. Deleted rather than
// redirected under a stale name.
//
// NOTE for whoever owns the async-connect programme: this leaves
// GenericPlatformController.php:168 (writeConnection pending) unreachable by
// any registered platform, and SkoolController's identical branch unrouted.
// The single-selection pending write itself stays covered on the bespoke path
// by DarkMergeProofTest's fresha flag-on case.

it('a first connect that fails terminally leaves NO live row — the poll still reports the error and /accounts does not list it (F26)', function () {
    // Session 3 (2026-08-18): a wrong Apple artist id → 202 → the fetch failed
    // ("Could not find that Apple Music artist") → the row stayed is_active
    // with last_refresh_status=unavailable and showed as a connected
    // "Japanese Breakfast" (accountName from the input path) in the Platforms
    // table, in /accounts, and had an ingest source provisioned.
    config(['partna.connect.deferred' => ['spotify']]);
    $user = dcUser('f26');
    // The deferred fetch runs inline under the sync queue: make oEmbed fail.
    Http::fake(['open.spotify.com/oembed*' => Http::response('', 404)]);

    $response = actingAsUser($user)->postJson('/api/platforms/spotify/connect', ['url' => 'https://open.spotify.com/artist/nobody123'])
        ->assertStatus(202);
    $id = $response->json('id');

    // The row is gone from every live read…
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'spotify')->count())->toBe(0)
        ->and(IntegrationConnection::withTrashed()->where('user_id', $user->id)->where('platform', 'spotify')->count())->toBe(1);
    actingAsUser($user)->getJson('/api/platforms/spotify/accounts')->assertOk()->assertJsonPath('accounts', []);

    // …but the poll that follows the 202 still tells the modal what happened.
    actingAsUser($user)->getJson("/api/platforms/spotify/connect/status?account={$id}")
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('error', fn ($e) => is_string($e) && $e !== '');
});

it('a RE-connect whose fetch fails keeps the previously-good row — only the attempt failed (F26)', function () {
    $user = dcUser('f26b');
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'spotify',
        'resource_id' => 'acct-'.substr(sha1('https://open.spotify.com/artist/some1'), 0, 16),
        'canonical_key' => 'https://open.spotify.com/artist/some1',
        'payload' => ['url' => 'https://open.spotify.com/artist/some1', 'link' => 'https://open.spotify.com/artist/some1', 'embedUrl' => 'https://open.spotify.com/embed/artist/some1', 'name' => 'Somebody'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => now()->subDay(),
    ]);

    Http::fake(['open.spotify.com/oembed*' => Http::response('', 500)]);
    (new ConnectFetchJob($row->id, 'spotify'))->handle(
        app(PlatformRegistry::class),
        app(FetchBudget::class),
        app(IntegrationNotifier::class),
    );

    $again = IntegrationConnection::where('user_id', $user->id)->where('platform', 'spotify')->first();
    expect($again)->not->toBeNull()
        ->and($again->id)->toBe($row->id)
        ->and($again->last_refresh_status)->toBe('unavailable')
        ->and($again->payload['name'] ?? null)->toBe('Somebody');
});
