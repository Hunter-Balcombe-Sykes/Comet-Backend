<?php

// SCALE-20 gave batch imports a per-run tombstone memo; its own comment
// declares the tripwire: wiring an importer into a live path requires
// invalidation or narrower scope first, because a dismissal landing mid-run
// (a DIFFERENT request/process — in-memory invalidation cannot reach it) is
// invisible for the whole run. A TTL narrows the staleness window back to
// approximately the pre-memo per-link window without giving up the batch
// query savings.

use App\Routing\PlacementPolicy;
use App\Routing\Projection;
use App\Routing\RoutingContext;
use App\Routing\Verdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

function tmtBandcampProjection(): Projection
{
    return new Projection(
        surfaceKey: 'bandcamp.artist',
        detectorId: 'test',
        captures: [],
        confidence: 90,
        margin: 100,
        identifier: 'kimcosmik',
        reason: null,
    );
}

it('honours a tombstone inserted after the memo was primed, once the ttl lapses', function () {
    $pro = createTenant('memo-ttl');
    $policy = new PlacementPolicy;
    $ctx = new RoutingContext($pro, 'bio_harvest', false, (string) Str::uuid());

    // Prime the memo: no tombstones yet, so the link clears the tombstone
    // gate. Since 2026-09-03 an indirect, unconfirmed origin like
    // 'bio_harvest' never reaches Place any more (only isConfirmedByUser()
    // does) — Choose is this test's "not tombstoned" signal now; the memo
    // behaviour under test is unaffected, it sits entirely above this check.
    expect($policy->decide(tmtBandcampProjection(), $ctx)->verdict)->toBe(Verdict::Choose);

    // A concurrent dismiss lands (a different request in production).
    DB::table('routing.item_tombstones')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'source_ref' => 'bandcamp.artist:kimcosmik',
        'scope' => 'this_source',
        'reason' => 'dismissed mid-run',
        'created_at' => now(),
    ]);

    // Within the TTL the memo may still answer stale — not asserted either
    // way. After the TTL it MUST see the refusal.
    $policy->agePrimedMemoForTest();

    expect($policy->decide(tmtBandcampProjection(), $ctx)->verdict)->toBe(Verdict::Reject);
});

it('still answers from the memo inside the ttl for the same run', function () {
    // The batch savings SCALE-20 bought must survive the TTL: two decisions
    // in quick succession for one run issue one tombstone query, not two.
    $pro = createTenant('memo-warm');
    $policy = new PlacementPolicy;
    $ctx = new RoutingContext($pro, 'bio_harvest', false, (string) Str::uuid());

    $queries = 0;
    DB::connection('pgsql')->listen(function ($q) use (&$queries) {
        if (str_contains($q->sql, 'item_tombstones')) {
            $queries++;
        }
    });

    $policy->decide(tmtBandcampProjection(), $ctx);
    $policy->decide(tmtBandcampProjection(), $ctx);

    expect($queries)->toBe(1);
});
