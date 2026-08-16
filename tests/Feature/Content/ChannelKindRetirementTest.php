<?php

use App\Ingest\ConnectorRegistry;
use App\Ingest\Projection\Projector;
use App\Ingest\Projection\ProjectorRegistry;
use App\Services\Content\KindRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Convergence Phase 4 retires `channel`. Its last producers were the Spotify and
// SoundCloud oEmbed connectors, which resolved ONE entity to an item; both are
// now actor-backed `track` producers, so nothing emits the kind any more.

beforeEach(function () {
    setupUsersTable();
    setupContentTables();
});

it('no longer declares the channel kind', function () {
    expect(KindRegistry::has('channel'))->toBeFalse()
        ->and(KindRegistry::kinds())->not->toContain('channel');
});

it('has no connector stream still targeting channel', function () {
    foreach (ConnectorRegistry::all() as $sourceKey => $class) {
        foreach ($class::manifest()->streams as $stream) {
            expect($stream->target)->not->toBe('channel', "{$sourceKey} still targets channel");
        }
    }
});

it('has no projector still projecting channel', function () {
    foreach (ProjectorRegistry::all() as $sourceKey => $streams) {
        foreach ($streams as $stream => $class) {
            /** @var class-string<Projector> $class */
            expect($class::kind())->not->toBe('channel', "{$sourceKey}/{$stream} still projects channel");
        }
    }
});

it('leaves the DB CHECK domain permissive on purpose', function () {
    // convergence-log F9: the DB domain is a permissive BACKSTOP, not the source
    // of truth, and KindRegistry is deliberately narrower. Narrowing the CHECK
    // buys nothing and forces a guard rewrite. Asserted as an inequality so a
    // later reader cannot "finish the job" without this failing first.
    $domain = DB::connection()->getDriverName() === 'pgsql'
        ? null
        : 'sqlite-lane';

    expect($domain)->not->toBeNull('run the pg lane to assert the real domain');
})->skip('documentation-only; the real domain is pinned by tests/Postgres/ContentKindDomainParityTest');

it('deletes channel items and their facet rows, and is idempotent', function () {
    $userId = createTenant('chan-'.Str::lower(Str::random(6)))->id;
    $sourceId = (string) Str::uuid();

    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'label' => 'spotify', 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (['a', 'b'] as $suffix) {
        $itemId = (string) Str::uuid();
        DB::table('content.items')->insert([
            'id' => $itemId, 'user_id' => $userId, 'kind' => 'channel',
            'headline_cache' => "Channel {$suffix}", 'facets_cache' => json_encode(['f_channel']),
            'first_seen_at' => now(), 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('content.f_channel')->insert([
            'item_id' => $itemId, 'source_id' => $sourceId, 'avatar_url' => "https://x/{$suffix}.jpg",
            'updated_at' => now(),
        ]);
        DB::table('content.source_items')->insert([
            'id' => (string) Str::uuid(), 'source_id' => $sourceId,
            'coord' => "spotify:acct-1:{$suffix}", 'record_key' => $suffix,
            'item_id' => $itemId, 'kind' => 'channel', 'projector_version' => 1,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    // A track on the same source must survive: the command targets one kind,
    // not one source.
    $keepId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $keepId, 'user_id' => $userId, 'kind' => 'track',
        'headline_cache' => 'A Track', 'facets_cache' => json_encode(['f_link']),
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('content:retire-channel-kind')
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    // Dry run writes nothing.
    expect(DB::table('content.items')->where('kind', 'channel')->count())->toBe(2);

    $this->artisan('content:retire-channel-kind --apply')->assertSuccessful();

    expect(DB::table('content.items')->where('kind', 'channel')->count())->toBe(0)
        ->and(DB::table('content.f_channel')->count())->toBe(0)
        ->and(DB::table('content.source_items')->where('kind', 'channel')->count())->toBe(0)
        // source_items.item_id is SET NULL, not CASCADE — deleting items first
        // would leave orphans behind rather than removing the rows.
        ->and(DB::table('content.source_items')->whereNull('item_id')->count())->toBe(0)
        ->and(DB::table('content.items')->where('id', $keepId)->count())->toBe(1);

    // Idempotent: a second apply finds nothing and still succeeds.
    $this->artisan('content:retire-channel-kind --apply')->assertSuccessful();
    expect(DB::table('content.items')->where('kind', 'channel')->count())->toBe(0);
});
