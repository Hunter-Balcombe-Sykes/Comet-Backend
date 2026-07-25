<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\AppleSearch;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// CA-W3 — wires DefersBespokeConnect (CA-W2, commit 904d51c7) onto Apple Music +
// Apple Podcast, the trait's first consumer. Both slugs share one controller
// (AppleController, via a mutable $activePlatform), so every scenario below is
// proven for BOTH slugs — a fix that only patched one platform's config array
// would leave the other silently broken.
//
// Per ConnectFetchJob's own docblock: never rely on the sync queue driver to
// prove pending/queued behaviour — on sync, a poll immediately after connect
// would return 'ready' (or a real vendor call), never 'pending'. Queue::fake()
// proves dispatch; ConnectFetchJob::handle() is called directly to prove
// completion.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function appleAsyncUser(string $h): User
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

it('DELIBERATELY VACUOUS — flag off leaves apple music + podcast connect byte-identical (rollout safety guard)', function () {
    // Passes against both fixed and unfixed code — config('partna.connect.deferred')
    // defaults to [] in every test (no PARTNA_CONNECT_DEFERRED in phpunit.xml), so
    // this proves nothing about the NEW branch. It exists as the safety net a
    // regression in the flag-off default would trip: if connectFor() ever took
    // the deferred branch when the flag is empty, this would start asserting 202
    // instead of 200 and fail.
    config(['partna.connect.deferred' => []]);
    $user = appleAsyncUser('flagoff1');

    $this->mock(AppleSearch::class, function ($m) {
        $m->shouldReceive('fetchAlbums')->once()->andReturn([
            ['collectionId' => 'a1', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l'],
        ]);
        $m->shouldReceive('fetchEpisodes')->once()->andReturn([
            ['trackId' => 'e1', 'name' => 'Ep', 'thumbnail' => 't', 'description' => 'd', 'link' => 'l'],
        ]);
        $m->shouldReceive('fetchGenre')->andReturn(null);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/apple/music/connect', ['artist' => 'Radiohead'])
        ->assertOk()
        ->assertJsonPath('name', 'Album')
        ->assertJsonPath('input', 'Radiohead');

    actingAsUser($user)->postJson('/api/platforms/apple/podcast/connect', ['show' => 'A Show'])
        ->assertOk()
        ->assertJsonPath('name', 'Ep')
        ->assertJsonPath('input', 'A Show');

    Queue::assertNothingPushed();

    $music = IntegrationConnection::where('user_id', $user->id)->where('platform', 'apple-music')->first();
    $podcast = IntegrationConnection::where('user_id', $user->id)->where('platform', 'apple-podcast')->first();
    expect($music->last_refresh_status)->toBe('ok');
    expect($podcast->last_refresh_status)->toBe('ok');
});

// ── Flag ON → 202 ────────────────────────────────────────────────────────────

it('flag on: apple music connect returns 202 with status/id/input/statusUrl, and the pending row carries ONLY {input}', function () {
    config(['partna.connect.deferred' => ['apple-music']]);
    $user = appleAsyncUser('flagonmusic1');

    // No expectations set on the mock — ANY call to AppleSearch throws
    // (BadMethodCallException), proving the vendor is never touched on the
    // deferred path.
    $this->mock(AppleSearch::class, function ($m) {});

    Queue::fake();

    $response = actingAsUser($user)->postJson('/api/platforms/apple/music/connect', ['artist' => 'Radiohead'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('input', 'Radiohead');

    $id = $response->json('id');
    expect($id)->toStartWith('acct-');
    expect($response->json('statusUrl'))->toBe(url("/api/platforms/apple/music/connect/status?account={$id}"));

    // name/thumbnail/releaseDate/link/latest/highlights are NOT present at all
    // (not even null) — the pending write never derived them.
    foreach (['name', 'thumbnail', 'releaseDate', 'link', 'latest', 'highlights'] as $absentKey) {
        expect($response->json())->not->toHaveKey($absentKey);
    }

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'apple-music')->first();
    expect($row->resource_id)->toBe($id);
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->last_refreshed_at)->toBeNull();
    expect($row->is_active)->toBeTrue();
    // Content assertion, not a mere non-null check: payload is NOT NULL DEFAULT
    // '{}' in Postgres while tests run SQLite — a bug that wrote a null
    // placeholder would pass locally and 500 in production (SQLSTATE 23502).
    expect($row->payload)->toBe(['input' => 'Radiohead']);

    Queue::assertPushed(ConnectFetchJob::class, fn ($job) => $job->connectionId === $row->id && $job->platform === 'apple-music');
});

it('flag on: apple podcast connect returns 202 with status/id/input/statusUrl, and the pending row carries ONLY {input}', function () {
    config(['partna.connect.deferred' => ['apple-podcast']]);
    $user = appleAsyncUser('flagonpodcast1');

    $this->mock(AppleSearch::class, function ($m) {});

    Queue::fake();

    $response = actingAsUser($user)->postJson('/api/platforms/apple/podcast/connect', ['show' => 'Serial'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('input', 'Serial');

    $id = $response->json('id');
    expect($id)->toStartWith('acct-');
    expect($response->json('statusUrl'))->toBe(url("/api/platforms/apple/podcast/connect/status?account={$id}"));

    foreach (['name', 'thumbnail', 'description', 'releaseDate', 'link', 'latest', 'highlights'] as $absentKey) {
        expect($response->json())->not->toHaveKey($absentKey);
    }

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'apple-podcast')->first();
    expect($row->resource_id)->toBe($id);
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->last_refreshed_at)->toBeNull();
    expect($row->is_active)->toBeTrue();
    expect($row->payload)->toBe(['input' => 'Serial']);

    Queue::assertPushed(ConnectFetchJob::class, fn ($job) => $job->connectionId === $row->id && $job->platform === 'apple-podcast');
});

// ── Poll: pending → ready → failed ──────────────────────────────────────────

it('poll: pending row reports pending, then handle() completing the job reports ready with the /selection-identical shape', function () {
    config(['partna.connect.deferred' => ['apple-music']]);
    $user = appleAsyncUser('pollmusic1');

    $this->mock(AppleSearch::class, function ($m) {});
    Queue::fake();

    $response = actingAsUser($user)->postJson('/api/platforms/apple/music/connect', ['artist' => 'Radiohead'])
        ->assertStatus(202);
    $id = $response->json('id');

    actingAsUser($user)->getJson("/api/platforms/apple/music/connect/status?account={$id}")
        ->assertOk()
        ->assertExactJson(['status' => 'pending']);

    // Swap in the real fetch expectations only now — ConnectFetchJob resolves
    // AppleSearch fresh from the container when it runs.
    $this->mock(AppleSearch::class, function ($m) {
        $m->shouldReceive('fetchAlbums')->once()->andReturn([
            ['collectionId' => 'a1', 'name' => 'In Rainbows', 'thumbnail' => 't', 'releaseDate' => '2007-10-10', 'link' => 'l'],
        ]);
        $m->shouldReceive('fetchGenre')->once()->andReturn('Rock');
    });

    $row = IntegrationConnection::where('resource_id', $id)->firstOrFail();
    app()->call([new ConnectFetchJob($row->id, 'apple-music'), 'handle']);

    // /selection returns the SAME shaping helper the status endpoint's
    // 'connection' key must match — assert both come back identical.
    $selection = actingAsUser($user)->getJson('/api/platforms/apple/music/selection')->assertOk()->json('selection');

    actingAsUser($user)->getJson("/api/platforms/apple/music/connect/status?account={$id}")
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('id', $id)
        ->assertJsonPath('connection', $selection);
});

it('poll: a failed apple music fetch (no albums found) surfaces the exact frozen failure message', function () {
    config(['partna.connect.deferred' => ['apple-music']]);
    $user = appleAsyncUser('failmusic1');

    $this->mock(AppleSearch::class, function ($m) {});
    Queue::fake();

    // Free-text input, no regex gate (design §6 decision 1) — a typo'd artist
    // name writes a pending row that resolves to failed once the job runs,
    // exactly like a real miss on a syntactically-valid-looking name.
    $response = actingAsUser($user)->postJson('/api/platforms/apple/music/connect', ['artist' => 'asdkfj'])
        ->assertStatus(202);
    $id = $response->json('id');

    $this->mock(AppleSearch::class, fn ($m) => $m->shouldReceive('fetchAlbums')->once()->andReturn([]));

    $row = IntegrationConnection::where('resource_id', $id)->firstOrFail();
    app()->call([new ConnectFetchJob($row->id, 'apple-music'), 'handle']);

    actingAsUser($user)->getJson("/api/platforms/apple/music/connect/status?account={$id}")
        ->assertOk()
        ->assertExactJson(['status' => 'failed', 'error' => 'Could not find that Apple Music artist or an album.']);
});

it('poll: a failed apple podcast fetch (no episodes found) surfaces the exact frozen failure message', function () {
    config(['partna.connect.deferred' => ['apple-podcast']]);
    $user = appleAsyncUser('failpodcast1');

    $this->mock(AppleSearch::class, function ($m) {});
    Queue::fake();

    $response = actingAsUser($user)->postJson('/api/platforms/apple/podcast/connect', ['show' => 'asdkfj'])
        ->assertStatus(202);
    $id = $response->json('id');

    $this->mock(AppleSearch::class, fn ($m) => $m->shouldReceive('fetchEpisodes')->once()->andReturn([]));

    $row = IntegrationConnection::where('resource_id', $id)->firstOrFail();
    app()->call([new ConnectFetchJob($row->id, 'apple-podcast'), 'handle']);

    actingAsUser($user)->getJson("/api/platforms/apple/podcast/connect/status?account={$id}")
        ->assertOk()
        ->assertExactJson(['status' => 'failed', 'error' => 'Could not find that Apple Podcast or an episode.']);
});

// ── 404, never 403 ───────────────────────────────────────────────────────────

it('poll 404s (never 403) for another user\'s account, and for a nonexistent one', function () {
    $owner = appleAsyncUser('pollowner1');
    $stranger = appleAsyncUser('pollstranger1');

    $row = IntegrationConnection::create([
        'user_id' => $owner->id,
        'platform' => 'apple-music',
        'resource_id' => 'acct-'.substr(sha1('radiohead'), 0, 16),
        'canonical_key' => 'radiohead',
        'payload' => ['input' => 'Radiohead'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    actingAsUser($stranger)->getJson("/api/platforms/apple/music/connect/status?account={$row->resource_id}")
        ->assertStatus(404)
        ->assertJsonPath('message', 'Account not found.');

    actingAsUser($owner)->getJson('/api/platforms/apple/music/connect/status?account=acct-doesnotexist0000')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Account not found.');
});

// ── Stale pending ────────────────────────────────────────────────────────────

it('stale pending (worker vanished > 5 minutes ago) reports failed, not pending forever', function () {
    $user = appleAsyncUser('stalemusic1');

    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'apple-music',
        'resource_id' => 'acct-'.substr(sha1('stale artist'), 0, 16),
        'canonical_key' => 'stale artist',
        'payload' => ['input' => 'Stale Artist'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);
    // Backdate updated_at past the 5-minute staleness window (create() sets it
    // to now()). Deliberately a query-builder mass update, NOT $row->save():
    // Eloquent's save() re-touches updated_at to now() whenever the model uses
    // timestamps, silently discarding a manually forceFill()'d value.
    IntegrationConnection::where('id', $row->id)->update(['updated_at' => now()->subMinutes(10)]);

    $response = actingAsUser($user)->getJson("/api/platforms/apple/music/connect/status?account={$row->resource_id}")
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($response->json('error'))->toBeString()->not->toBeEmpty();

    // Fresh pending (updated just now) is NOT treated as stale.
    $fresh = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'apple-podcast',
        'resource_id' => 'acct-'.substr(sha1('fresh show'), 0, 16),
        'canonical_key' => 'fresh show',
        'payload' => ['input' => 'Fresh Show'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);
    actingAsUser($user)->getJson("/api/platforms/apple/podcast/connect/status?account={$fresh->resource_id}")
        ->assertOk()
        ->assertExactJson(['status' => 'pending']);
});
