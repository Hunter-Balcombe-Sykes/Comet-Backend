<?php

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 0b: owner-authored items land through the SAME writer a connector
// uses. The three things that must be true and were not before this slice:
// a manual item carries identity keys and an anchor (so a connector run
// enriches it instead of minting a blank duplicate beside it); a connector
// run can never merge it away (mergeInto()'s DELETE cascades the facet rows,
// and a manual source has no next run to rewrite them); and the manual source
// outranks every connection on value resolution.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

it('creates exactly one manual source per user, above connection priority', function () {
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;

    $writer = app(ProjectionWriter::class);
    $first = $writer->ensureManualSource($userId);
    $second = $writer->ensureManualSource($userId);

    expect($second)->toBe($first);

    $rows = DB::table('content.sources')->where('user_id', $userId)->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->kind)->toBe('manual')
        ->and($rows[0]->connection_id)->toBeNull()
        // content.sources' DDL comment calls this "max priority: what makes
        // 'the user outranks the machine' a data fact rather than a special
        // case in code". ValueResolver::byPriority() sorts DESC, so 200 is
        // what makes the owner's headline and link beat a connection's 100.
        ->and((int) $rows[0]->priority)->toBe(200);
});

it('raises a manual source left at connection priority by the old writer', function () {
    // The live controller wrote priority 100. Find-or-create alone would
    // return that row unchanged and the C8 guarantee would silently never
    // apply to anyone who had already hand-added.
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;
    $legacyId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $legacyId, 'user_id' => $userId, 'kind' => 'manual',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(ProjectionWriter::class)->ensureManualSource($userId))->toBe($legacyId)
        ->and((int) DB::table('content.sources')->where('id', $legacyId)->value('priority'))->toBe(200);
});
