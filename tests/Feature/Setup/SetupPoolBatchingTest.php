<?php

use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// The setup dialog resolves seven content pools. Resolving them one at a
// time paid PoolResolver's per-pool pre-reads seven times over; PoolWire
// solved the same N+1 for the public lane in 2026-08-24. These tests pin
// the batched shape by COUNTING the pre-reads, because the wire output is
// identical either way and so proves nothing on its own.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
    setupIngestTables();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    AccountCapabilities::flushCache();
    Queue::fake();
});

/** Every SELECT this request issued against site.section_items. */
function setupCurationSelects(callable $run): array
{
    $seen = [];
    DB::listen(function ($query) use (&$seen) {
        $sql = $query->sql;
        if (str_contains($sql, 'section_items') && str_starts_with(trim(strtolower($sql)), 'select')) {
            $seen[] = $sql;
        }
    });

    $run();

    return $seen;
}

it('reads every pool\'s curation in one query, not one per pool', function () {
    $pro = createTenant('setup-batch');
    seedContentItem($pro->id, ['kind' => 'video']);

    $selects = setupCurationSelects(function () use ($pro) {
        actingAsUser($pro)->getJson('/api/site/setup')->assertOk();
    });

    // One whereIn over every section, via PoolResolver::preloadCuration.
    // Before batching this was seven separate section-scoped selects.
    expect($selects)->toHaveCount(1);
});
