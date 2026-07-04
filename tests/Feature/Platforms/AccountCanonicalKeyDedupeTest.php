<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\BandcampScraper;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

// FOUND-14: canonical_key is the normalized (lower+trim) platform-canonical
// identity value stamped on every ACCOUNT row at write time (writeAccountConnection).
// It replaces the old 7-field JSONB payload scan with an indexed lookup, and a
// partial UNIQUE index (user_id, platform, canonical_key) gives the DB — not just
// the app — a dedupe guarantee. Bandcamp exercises the preserveHighlights path
// (Option B folds it onto matchAccountByCanonical, same as Apple/Events).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also creates site.platform_connections
});

function canonicalKeyTestUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** @return list<array<string,mixed>> */
function bandcampFixtureItems(): array
{
    return [
        ['itemId' => 'album-2', 'name' => 'New Record', 'thumbnail' => 'https://f4.bcbits.com/img/2.jpg', 'link' => 'https://artist.bandcamp.com/album/new'],
        ['itemId' => 'album-1', 'name' => 'Old Record', 'thumbnail' => 'https://f4.bcbits.com/img/1.jpg', 'link' => 'https://artist.bandcamp.com/album/old'],
    ];
}

it('stores a normalized canonical_key on the account row when connecting', function () {
    $user = canonicalKeyTestUser('ck1');

    // The scraper's normalized origin carries case + whitespace noise — the trait
    // must normalize (lower+trim) again before storing canonical_key.
    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('normalizeOrigin')->andReturn('  HTTPS://Artist.Bandcamp.com  ');
        $m->shouldReceive('fetchProfile')->andReturn(['name' => 'Mock Artist', 'thumbnail' => null, 'items' => bandcampFixtureItems()]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn ($tiles) => $tiles);
    });

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://artist.bandcamp.com/music'])
        ->assertOk();

    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'bandcamp')->first();
    expect($conn)->not->toBeNull();
    expect($conn->canonical_key)->toBe('https://artist.bandcamp.com');
});

it('reconnecting the same account with different case/whitespace updates in place and preserves highlights', function () {
    $user = canonicalKeyTestUser('ck2');

    // Bind BOTH normalizeOrigin outcomes up front — the SEC-1 saving guard
    // resolves the PlatformRegistry (and eagerly wires scrapers) on the FIRST
    // connect's IntegrationConnection::create(), so late-binding a second mock
    // mid-test would capture the real scraper.
    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('normalizeOrigin')->with('https://artist.bandcamp.com/music')->andReturn('https://artist.bandcamp.com');
        $m->shouldReceive('normalizeOrigin')->with('HTTPS://ARTIST.BANDCAMP.COM/MUSIC')->andReturn('  HTTPS://ARTIST.BANDCAMP.COM  ');
        $m->shouldReceive('fetchProfile')->andReturn(['name' => 'Mock Artist', 'thumbnail' => null, 'items' => bandcampFixtureItems()]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn ($tiles) => $tiles);
    });

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://artist.bandcamp.com/music'])
        ->assertOk()
        ->assertJsonPath('highlights', []);

    actingAsUser($user)->postJson('/api/platforms/bandcamp/highlights', ['itemIds' => ['album-1']])
        ->assertOk()
        ->assertJsonPath('highlights.0.itemId', 'album-1');

    // Reconnect with a differently-cased, whitespace-padded canonical value.
    $before = IntegrationConnection::where('user_id', $user->id)->where('platform', 'bandcamp')->first();

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'HTTPS://ARTIST.BANDCAMP.COM/MUSIC'])
        ->assertOk()
        ->assertJsonPath('highlights.0.itemId', 'album-1');

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'bandcamp')->count())->toBe(1);

    $after = IntegrationConnection::where('user_id', $user->id)->where('platform', 'bandcamp')->first();
    expect($after->id)->toBe($before->id);
    expect($after->canonical_key)->toBe('https://artist.bandcamp.com');
});

it('creates separate rows for two different canonical accounts', function () {
    $user = canonicalKeyTestUser('ck3');

    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('normalizeOrigin')->with('https://one.bandcamp.com/music')->andReturn('https://one.bandcamp.com');
        $m->shouldReceive('normalizeOrigin')->with('https://two.bandcamp.com/music')->andReturn('https://two.bandcamp.com');
        $m->shouldReceive('fetchProfile')->andReturn(['name' => 'Mock Artist', 'thumbnail' => null, 'items' => bandcampFixtureItems()]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn ($tiles) => $tiles);
    });

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://one.bandcamp.com/music'])->assertOk();
    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://two.bandcamp.com/music'])->assertOk();

    $rows = IntegrationConnection::where('user_id', $user->id)->where('platform', 'bandcamp')->get();
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('canonical_key')->sort()->values()->all())->toBe([
        'https://one.bandcamp.com', 'https://two.bandcamp.com',
    ]);
});

it('falls back to matching by stored canonical_key when the resource_id hash does not match a legacy row', function () {
    $user = canonicalKeyTestUser('ck4');

    // Seed a row whose resource_id does NOT follow the acct-<hash> scheme (e.g.
    // a row from before this feature, or a hash-scheme change) but whose
    // canonical_key is already normalized. writeAccountConnection must find it
    // via the canonical_key fallback, not create a duplicate.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-legacyfallback01',
        'payload' => ['url' => 'https://artist.bandcamp.com', 'highlights' => [['itemId' => 'album-1']]],
        'canonical_key' => 'https://artist.bandcamp.com',
    ]);

    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://artist.bandcamp.com');
        $m->shouldReceive('fetchProfile')->andReturn(['name' => 'Mock Artist', 'thumbnail' => null, 'items' => bandcampFixtureItems()]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn ($tiles) => $tiles);
    });

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://artist.bandcamp.com/music'])
        ->assertOk()
        ->assertJsonPath('highlights.0.itemId', 'album-1');

    // Updated the legacy row in place — no second row created.
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'bandcamp')->count())->toBe(1);
    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'bandcamp')->first();
    expect($conn->resource_id)->toBe('acct-legacyfallback01');
});

it('rejects a duplicate active (user, platform, canonical_key) row at the DB level', function () {
    $user = canonicalKeyTestUser('ck5');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-0000000000000001',
        'payload' => [],
        'canonical_key' => 'artist.bandcamp.com',
    ]);

    // A second row under a DIFFERENT resource_id but the SAME (user, platform,
    // canonical_key) must be rejected by the partial unique index — this is the
    // DB-level guarantee the app-level dedupe in writeAccountConnection can't provide
    // on its own (e.g. a concurrent write racing past the in-app check).
    expect(fn () => IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-0000000000000002',
        'payload' => [],
        'canonical_key' => 'artist.bandcamp.com',
    ]))->toThrow(QueryException::class);
});
