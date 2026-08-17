<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use Illuminate\Database\QueryException;
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

// Retirement is one-way, so the flag pairing most likely to be typed by
// someone trying to PREVIEW it must not perform it. The signature declared
// --dry-run from the start but handle() never read it.
it('previews rather than performs when --dry-run is paired with --retire', function () {
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
        'coord' => 'eventbrite:acct-test:dry', 'item_id' => $itemId,
        'kind' => 'event', 'removed_at' => now()->subDay(),
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_text')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'headline' => 'Workshop', 'updated_at' => now(),
    ]);

    $this->artisan('content:repair-event-items --dry-run --retire')
        ->expectsOutputToContain('would retire 1')
        ->assertExitCode(0);

    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->toBeNull();
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

it('--retire actually performs all three invalidations, not just the retirement', function () {
    // The regression this pins is not the retirement — that always worked. It
    // is the half AFTER it. The site lookup carried a `deleted_at` filter for
    // a column site.sites does not have: Postgres raised 42703 and aborted the
    // command with the retirement already committed, so the document bump, the
    // sites.updated_at touch and the edge purge never happened on dev.
    //
    // SQLite hid it completely. An unknown DOUBLE-QUOTED identifier is
    // reinterpreted as a string literal, so the filter compiled to
    // `'deleted_at' is null` — always false, zero sites, no error, loop
    // skipped. The pre-existing test asserted removed_at only and passed.
    // Asserting the INVALIDATION is what makes the bug visible here.
    $pro = createTenant('repair-'.Str::lower(Str::random(6)));
    $siteId = (string) DB::table('site.sites')->where('user_id', $pro->id)->value('id');
    $sourceId = repairSource($pro->id);

    $gone = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $gone, 'user_id' => $pro->id, 'kind' => 'event',
        'headline_cache' => 'Workshop', 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'eventbrite:acct-test:'.$gone, 'item_id' => $gone,
        'kind' => 'event', 'removed_at' => now()->subDay(),
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_text')->insert([
        'item_id' => $gone, 'source_id' => $sourceId,
        'headline' => 'Workshop', 'updated_at' => now(),
    ]);

    DB::table('site.sites')->where('id', $siteId)->update(['updated_at' => now()->subDay()]);
    $before = (string) DB::table('site.sites')->where('id', $siteId)->value('updated_at');
    // BuildState::read() would insert a 0 row on first touch, but nothing has
    // read it yet here, so the column may still be entirely absent — treat
    // absence as revision 0 rather than assuming a row exists.
    $revisionBefore = (int) (DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision') ?? 0);

    $this->artisan('content:repair-event-items --retire')->assertExitCode(0);

    expect(DB::table('content.items')->where('id', $gone)->value('removed_at'))->not->toBeNull()
        // 1. the document build state moved by exactly one bump, not "some
        // positive amount" — an exact delta is the only form that would catch
        // a double-bump or a bump that fires for the wrong site.
        ->and((int) DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBe($revisionBefore + 1)
        // 2. sites.updated_at moved — the 60s payload cache key composes from it
        ->and((string) DB::table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);

    // 3. the edge purge was dispatched for THIS site's subdomain, not merely
    // "some CloudflareCachePurgeJob" — a bare assertPushed also passes if the
    // code ever dispatched the site UUID instead of the subdomain (see
    // PurgeReviewHeadlinePiiTest.php:134 for the same trap on a sibling command).
    Queue::assertPushed(CloudflareCachePurgeJob::class, fn ($job) => $job->handle === $pro->handle);
});

function seedOrphanedEvent(string $userId): string
{
    $sourceId = repairSource($userId);
    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'event',
        'headline_cache' => 'Workshop', 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'eventbrite:acct-test:'.$itemId, 'item_id' => $itemId,
        'kind' => 'event', 'removed_at' => now()->subDay(),
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_text')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'headline' => 'Workshop', 'updated_at' => now(),
    ]);

    return $itemId;
}

it('invalidates each tenant site exactly once when --retire spans multiple tenants', function () {
    // CloudflareCachePurgeJob implements ShouldBeUnique, and the lock is
    // taken at DISPATCH time (PendingDispatch::shouldDispatch()), which
    // Queue::fake() honours — three same-handle dispatches under Queue::fake()
    // elsewhere in this run yielded pushed()->count() of 1, not 3. So a
    // duplicate SAME-subdomain dispatch would be swallowed by the framework,
    // not caught by an assertion here. Two DISTINCT subdomains do get two
    // distinct uniqueId()s and both legitimately push, so a per-subdomain
    // push assertion below is real — but the per-site CARDINALITY claim (that
    // each site was invalidated exactly once, not zero or twice) rests on the
    // exact content_revision deltas, not on the purge push count.
    $proA = createTenant('repair-'.Str::lower(Str::random(6)));
    $proB = createTenant('repair-'.Str::lower(Str::random(6)));
    $siteIdA = (string) DB::table('site.sites')->where('user_id', $proA->id)->value('id');
    $siteIdB = (string) DB::table('site.sites')->where('user_id', $proB->id)->value('id');

    $itemA = seedOrphanedEvent($proA->id);
    $itemB = seedOrphanedEvent($proB->id);

    DB::table('site.sites')->where('id', $siteIdA)->update(['updated_at' => now()->subDay()]);
    DB::table('site.sites')->where('id', $siteIdB)->update(['updated_at' => now()->subDay()]);
    $beforeA = (string) DB::table('site.sites')->where('id', $siteIdA)->value('updated_at');
    $beforeB = (string) DB::table('site.sites')->where('id', $siteIdB)->value('updated_at');
    $revBeforeA = (int) (DB::table('site.site_build_state')->where('site_id', $siteIdA)->value('content_revision') ?? 0);
    $revBeforeB = (int) (DB::table('site.site_build_state')->where('site_id', $siteIdB)->value('content_revision') ?? 0);

    $this->artisan('content:repair-event-items --retire')->assertExitCode(0);

    expect(DB::table('content.items')->where('id', $itemA)->value('removed_at'))->not->toBeNull()
        ->and(DB::table('content.items')->where('id', $itemB)->value('removed_at'))->not->toBeNull()
        ->and((int) DB::table('site.site_build_state')->where('site_id', $siteIdA)->value('content_revision'))
        ->toBe($revBeforeA + 1)
        ->and((int) DB::table('site.site_build_state')->where('site_id', $siteIdB)->value('content_revision'))
        ->toBe($revBeforeB + 1)
        ->and((string) DB::table('site.sites')->where('id', $siteIdA)->value('updated_at'))->not->toBe($beforeA)
        ->and((string) DB::table('site.sites')->where('id', $siteIdB)->value('updated_at'))->not->toBe($beforeB);

    Queue::assertPushed(CloudflareCachePurgeJob::class, fn ($job) => $job->handle === $proA->handle);
    Queue::assertPushed(CloudflareCachePurgeJob::class, fn ($job) => $job->handle === $proB->handle);
});

// PGR-4's own test: failure injection. 'site.sites' proves the ORDERING fix —
// the resolution that used to raise 42703 now runs BEFORE the one-way
// retirement, so a broken read leaves the retirement un-attempted. Dropping
// 'site.site_build_state' instead lets the resolution succeed and lands the
// failure INSIDE the transaction, after the retirement statement but before
// commit — proving the TRANSACTION rolls the retirement back rather than
// leaving it half-applied. Both must fail against the pre-fix code for the
// right reason; see the implementation report for that evidence.
it('rolls back the retirement and dispatches nothing when the invalidation half is broken', function (string $tableToDrop) {
    $pro = createTenant('repair-'.Str::lower(Str::random(6)));
    $itemId = seedOrphanedEvent($pro->id);

    DB::connection('pgsql')->statement("DROP TABLE {$tableToDrop}");

    expect(fn () => $this->artisan('content:repair-event-items --retire')->run())
        ->toThrow(QueryException::class);

    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->toBeNull();
    Queue::assertNothingPushed();
})->with(['site.sites', 'site.site_build_state']);
