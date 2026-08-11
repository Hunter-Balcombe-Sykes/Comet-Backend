<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    // --retire dispatches the edge purge; the queue connection is not the
    // subject here.
    Queue::fake();
});

function repairSource(string $userId): string
{
    $id = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => (string) Str::uuid(), 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

// The 2026-07-28 signature: writeFacets() aborted on f_occurrence, so the
// item holds f_link and nothing the projector emits after it. The command
// must find these by absence-of-facets — no marker exists.
it('reports an event item whose facets are incomplete', function () {
    $pro = createTenant('repair-'.Str::lower(Str::random(6)));
    $sourceId = repairSource($pro->id);

    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'event',
        'headline_cache' => null, 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'humanitix:acct-test:broken-event', 'item_id' => $itemId,
        'kind' => 'event', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_link')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'url' => 'https://events.humanitix.com/broken-event', 'updated_at' => now(),
    ]);

    $this->artisan('content:repair-event-items --dry-run')
        ->expectsOutputToContain('incomplete: 1')
        ->assertExitCode(0);

    expect(DB::table('content.items')->where('id', $itemId)->value('headline_cache'))->toBeNull();
});

it('does not report a healthy event item', function () {
    $pro = createTenant('repair-'.Str::lower(Str::random(6)));
    $sourceId = repairSource($pro->id);

    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'event',
        'headline_cache' => 'Beginner sewing workshop', 'facets_cache' => '[]',
        'eligible_cache' => '[]', 'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'eventbrite:acct-test:healthy-event', 'item_id' => $itemId,
        'kind' => 'event', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_text')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'headline' => 'Beginner sewing workshop', 'updated_at' => now(),
    ]);
    DB::table('content.f_occurrence')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'starts_at_local' => '2026-08-24 11:30:00',
        'starts_at_utc' => '2026-08-24 01:30:00',
        'zone_confidence' => 'offset_only', 'is_all_day' => 0,
        'updated_at' => now(),
    ]);

    $this->artisan('content:repair-event-items --dry-run')
        ->expectsOutputToContain('incomplete: 0')
        ->assertExitCode(0);
});

it('retires an event item whose every source item is removed', function () {
    $pro = createTenant('repair-'.Str::lower(Str::random(6)));
    $sourceId = repairSource($pro->id);

    $gone = (string) Str::uuid();
    $kept = (string) Str::uuid();
    foreach ([$gone => now()->subDay(), $kept => null] as $itemId => $removedAt) {
        DB::table('content.items')->insert([
            'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'event',
            'headline_cache' => 'Workshop', 'facets_cache' => '[]', 'eligible_cache' => '[]',
            'first_seen_at' => now(), 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('content.source_items')->insert([
            'id' => (string) Str::uuid(), 'source_id' => $sourceId,
            'coord' => 'eventbrite:acct-test:'.$itemId, 'item_id' => $itemId,
            'kind' => 'event', 'removed_at' => $removedAt,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        DB::table('content.f_text')->insert([
            'item_id' => $itemId, 'source_id' => $sourceId,
            'headline' => 'Workshop', 'updated_at' => now(),
        ]);
    }

    $this->artisan('content:repair-event-items --retire')->assertExitCode(0);

    expect(DB::table('content.items')->where('id', $gone)->value('removed_at'))->not->toBeNull();
    expect(DB::table('content.items')->where('id', $kept)->value('removed_at'))->toBeNull();

    // The source item's own marker is untouched — it is cleared on
    // reappearance, and rewriting it would resurrect a user-deleted row.
    expect(DB::table('content.source_items')->where('item_id', $gone)->value('removed_at'))->not->toBeNull();
});

it('leaves an orphaned item alone without --retire', function () {
    $pro = createTenant('repair-'.Str::lower(Str::random(6)));
    $sourceId = repairSource($pro->id);

    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'event',
        'headline_cache' => 'Workshop', 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'eventbrite:acct-test:orphan', 'item_id' => $itemId,
        'kind' => 'event', 'removed_at' => now()->subDay(),
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_text')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'headline' => 'Workshop', 'updated_at' => now(),
    ]);

    $this->artisan('content:repair-event-items')->assertExitCode(0);

    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->toBeNull();
});
