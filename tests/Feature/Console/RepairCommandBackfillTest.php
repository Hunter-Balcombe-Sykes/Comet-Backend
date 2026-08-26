<?php

/**
 * Coverage for two daily safety-net repair commands reworked from a single
 * ->get() into chunked scans (#SCALE-16 platforms:enrich-pending-cards,
 * #SCALE-17 content:refresh-item-caches) — neither had a test invoking
 * handle() before this file; only scheduler registration was pinned
 * (SchedulerLockExpiryTest.php). Without these, a future edit to either
 * command's chunking can regress silently behind a green suite.
 */

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Services\Notifications\Dispatchers\IntegrationNotifier;
use App\Services\Platforms\LinkCardScraper;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

/** One site.platform_connections row for enrich-pending-cards' WHERE clause. */
function seedPendingConnection(string $userId, bool $withUrl, bool $old, string $status = 'pending'): string
{
    $id = (string) Str::uuid();
    DB::table('site.platform_connections')->insert([
        'id' => $id,
        'user_id' => $userId,
        'surface_key' => 'reviewfamily.'.Str::random(6),
        'routing_class' => 'link',
        'resource_id' => 'res-'.Str::random(8),
        'payload' => $withUrl ? json_encode(['url' => 'https://example.test/'.Str::random(6)]) : json_encode([]),
        'is_active' => 1,
        'last_refresh_status' => $status,
        'last_refreshed_at' => null,
        'created_at' => $old ? now()->subMinutes(30) : now(),
        'updated_at' => now(),
    ]);

    return $id;
}

// Stray space or dropped full stop in the restructured single info() call
// (was two separate branches) is exactly the class of regression this pins —
// assert the whole rendered line, not a substring.
it('reports identical dispatched/total split under --dry-run and a live run, dispatching nothing under --dry-run', function () {
    $userId = createTenant('cap-'.Str::lower(Str::random(6)))->id;

    for ($i = 0; $i < 5; $i++) {
        seedPendingConnection($userId, withUrl: true, old: true);
    }
    seedPendingConnection($userId, withUrl: false, old: true); // no url: counted, never dispatched
    seedPendingConnection($userId, withUrl: true, old: false); // too new: excluded entirely
    seedPendingConnection($userId, withUrl: true, old: true, status: 'ok'); // already refreshed: excluded

    Bus::fake([EnrichLinkCardJob::class]);

    $this->artisan('platforms:enrich-pending-cards', ['--dry-run' => true])
        ->expectsOutput('would dispatch 5 of 6 pending cards')
        ->assertExitCode(0);

    Bus::assertNotDispatched(EnrichLinkCardJob::class);
    expect(DB::table('site.platform_connections')->where('user_id', $userId)->where('last_refresh_status', 'pending')->count())
        ->toBe(7); // untouched: 5 eligible + 1 no-url + 1 too-new

    $this->artisan('platforms:enrich-pending-cards')
        ->expectsOutput('dispatched 5 of 6 pending cards')
        ->assertExitCode(0);

    Bus::assertDispatchedTimes(EnrichLinkCardJob::class, 5);
});

// Pins the keyset-vs-OFFSET justification in the command's own comment: under
// OFFSET paging, a row leaving the `pending` filter mid-scan (here, via the
// job's own last_refresh_status write under the sync queue) shifts every
// later page's window and silently skips rows. chunkById must not regress to
// chunk()/OFFSET, so this fixture spans 3 pages of chunkById(100) for real.
it('does not skip a row across multiple chunkById pages when the job flips it out of the pending filter mid-scan', function () {
    $userId = createTenant('cap2-'.Str::lower(Str::random(6)))->id;

    $eligible = [];
    for ($i = 0; $i < 240; $i++) { // chunkById(100): pages of 100, 100, 40
        $eligible[] = seedPendingConnection($userId, withUrl: true, old: true);
    }

    $this->mock(LinkCardScraper::class, function ($mock) {
        $mock->shouldReceive('snapshot')->andReturn(null);
    });
    $this->mock(IntegrationNotifier::class, function ($mock) {
        $mock->shouldReceive('connected')->andReturnNull();
    });

    // No Bus::fake — the sync queue driver runs EnrichLinkCardJob::handle()
    // for real, so last_refresh_status genuinely flips pending -> ok mid-scan.
    $this->artisan('platforms:enrich-pending-cards')
        ->expectsOutput('dispatched 240 of 240 pending cards')
        ->assertExitCode(0);

    expect(DB::table('site.platform_connections')->whereIn('id', $eligible)->where('last_refresh_status', 'ok')->count())->toBe(240);
    expect(DB::table('site.platform_connections')->whereIn('id', $eligible)->where('last_refresh_status', 'pending')->count())->toBe(0);
});

// Pins the per-user (clone $query) scoping in phase 2: if a stray mutation of
// $query itself (instead of a clone) ever crept back in, one user's WHERE
// would stack onto the next and every user after the first would silently
// match zero rows.
it('refreshes every user\'s items, not just the first, and writes nothing under --dry-run', function () {
    setupIngestTables();
    setupContentTables();

    $itemsByUser = [];
    for ($u = 0; $u < 3; $u++) {
        [$pro] = poolTenant();
        $sourceId = poolSource($pro->id, null);
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $itemId = (string) Str::uuid();
            $ids[] = $itemId;
            DB::connection('pgsql')->table('content.items')->insert([
                'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'article',
                'headline_cache' => null, 'facets_cache' => '[]', 'eligible_cache' => '[]',
                'removed_at' => null,
                'first_seen_at' => now()->subDays(10), 'last_seen_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::connection('pgsql')->table('content.f_text')->insert([
                'item_id' => $itemId, 'source_id' => $sourceId,
                'headline' => "Headline {$u}-{$i}", 'body' => null, 'summary' => null,
                'updated_at' => now(),
            ]);
        }
        $itemsByUser[$pro->id] = $ids;
    }

    $this->artisan('content:refresh-item-caches', ['--dry-run' => true])
        ->expectsOutput('Would refresh 6 item(s) across 3 user(s).')
        ->assertExitCode(0);

    $allIds = array_merge(...array_values($itemsByUser));
    expect(DB::connection('pgsql')->table('content.items')->whereIn('id', $allIds)->whereNull('headline_cache')->count())->toBe(6);

    $this->artisan('content:refresh-item-caches')
        ->expectsOutput('Refreshed 6 item(s) across 3 user(s).')
        ->assertExitCode(0);

    foreach ($itemsByUser as $userId => $ids) {
        expect(DB::connection('pgsql')->table('content.items')->whereIn('id', $ids)->whereNotNull('headline_cache')->count())
            ->toBe(2, "user {$userId} did not get its items refreshed — clone/loop bug");
    }
});
