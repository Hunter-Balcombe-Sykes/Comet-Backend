<?php

use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\DB;

it('uses cases_open_queue_idx for the queue read path', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('EXPLAIN plan assertions require PostgreSQL.');
    }

    ModerationCase::factory()->count(50)->create();

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
})->group('postgres');

it('uses cases_target_open_idx for the case-merge lookup', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('EXPLAIN plan assertions require PostgreSQL.');
    }

    ModerationCase::factory()->count(50)->create();

    $plan = DB::selectOne(<<<'SQL'
        EXPLAIN (FORMAT JSON)
        SELECT id FROM moderation.cases
        WHERE reportable_type = 'Site'
          AND reportable_id   = '00000000-0000-0000-0000-000000000001'
          AND status IN ('open', 'triaged', 'under_review')
    SQL);
    expect(json_encode($plan))->toContain('cases_target_open_idx');
})->group('postgres');
