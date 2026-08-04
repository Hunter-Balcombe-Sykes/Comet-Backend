<?php

// Test-quality repair (tranche 2 of the COV-LANE assurance-coverage unit): this
// file asserted the planner picks a partial index with NEITHER `SET
// enable_seqscan = off` NOR an ANALYZE. On a pre-beta, near-empty moderation.cases
// table the planner's cost estimate happily picks a Seq Scan regardless of
// whether the index actually applies — telling us nothing — and worse, the
// choice depends on stale/accumulated statistics in this lane's persistent,
// shared database: the file passed standalone and failed when run in sequence
// after other tests had already inserted rows. It also leaked 50 factory rows
// per test with no cleanup, which would poison whatever ran after it.
//
// Fix mirrors tests/Schema/QueryPlanTest.php: `enable_seqscan = off` forces
// Postgres to use any index that CAN service the predicate (disabled, not
// forbidden — a genuinely non-matching index would still fall back to Seq
// Scan), reset in afterEach so the session-level GUC never leaks to a sibling
// test file sharing the same connection. Row cleanup is explicit because
// neither SchemaTestCase nor this lane wraps tests in a transaction.
//
// moderation.cases carries a SECOND twist QueryPlanTest's tables don't:
// cases_open_queue_idx and cases_target_open_idx share the exact same
// partial predicate (`status IN (...)`), so right after a fresh 50-row
// insert — before autovacuum's async ANALYZE has run — the planner's
// row-count estimate for the table is stale/near-zero and it picks between
// the two near-arbitrarily instead of favouring cases_open_queue_idx for its
// sort-matching column order. Reproduced empirically: enable_seqscan=off
// alone still flaked depending on what stats a previous run had left behind.
// An explicit ANALYZE after the insert removes that dependency on
// autovacuum's timing entirely.

use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

beforeEach(function () {
    DB::statement('SET enable_seqscan = off');
});

afterEach(function () {
    DB::statement('SET enable_seqscan = on');
});

it('uses cases_open_queue_idx for the queue read path', function () {
    $cases = ModerationCase::factory()->count(50)->create();

    try {
        DB::statement('ANALYZE moderation.cases');

        $plan = DB::selectOne(<<<'SQL'
            EXPLAIN (FORMAT JSON)
            SELECT id FROM moderation.cases
            WHERE status IN ('open', 'triaged', 'under_review')
            ORDER BY severity DESC, priority ASC, created_at ASC
            LIMIT 25
        SQL);
        $planText = json_encode($plan);

        // Asserts the partial index is used rather than a seqscan.
        expect($planText)->toContain('cases_open_queue_idx');
    } finally {
        ModerationCase::whereIn('id', $cases->pluck('id'))->delete();
    }
})->group('postgres');

it('uses cases_target_open_idx for the case-merge lookup', function () {
    $cases = ModerationCase::factory()->count(50)->create();

    try {
        DB::statement('ANALYZE moderation.cases');

        $plan = DB::selectOne(<<<'SQL'
            EXPLAIN (FORMAT JSON)
            SELECT id FROM moderation.cases
            WHERE reportable_type = 'Site'
              AND reportable_id   = '00000000-0000-0000-0000-000000000001'
              AND status IN ('open', 'triaged', 'under_review')
        SQL);
        expect(json_encode($plan))->toContain('cases_target_open_idx');
    } finally {
        ModerationCase::whereIn('id', $cases->pluck('id'))->delete();
    }
})->group('postgres');
