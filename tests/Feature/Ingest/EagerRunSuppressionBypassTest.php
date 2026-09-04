<?php

// 2026-09-04 (simondoylehair): three transient vendor blips at connect time
// tripped the 3-strike stream suppression, and every later run — the eager
// retry included — then skipped the stream while reporting 'ok'. The
// scheduler's writeback read that as a qualifying outcome and discharged
// needs_eager_run with zero content ever landed, so a signup's pool stayed
// empty until a human intervened. A source still owing its eager run now gets
// through suppression; a genuine failure re-suppresses via
// recordStreamFailure() as usual, so the bypass cannot loop.

use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Record;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Ingest\Runtime\RunExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupIngestTables();
});

class StubBypassConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('stub_test'),
            identifierKind: 'handle',
            hosts: ['example.test'],
            streams: [
                'ok' => new StreamSpec(name: 'ok', target: 'none', profile: SourceProfile::Mirror),
            ],
        );
    }

    public function pull(Pull $pull, Io $io): iterable
    {
        yield new Record('ok', 'key-1', ['field' => 'value']);
    }
}

function suppressedBypassSource(bool $eager): array
{
    $sourceId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => (string) Str::uuid(),
        'source_id' => $sourceId,
        'stream_name' => 'ok',
        'consecutive_failures' => 3,
        'health' => 'unavailable',
        'suppressed_until' => now()->addHours(4),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'id' => $sourceId,
        'user_id' => null,
        'source_key' => 'stub_test',
        'identifier' => 'stub-1',
        'needs_eager_run' => $eager,
    ];
}

it('still suppresses an ordinary scheduled run', function () {
    $source = suppressedBypassSource(eager: false);

    $result = app(RunExecutor::class)->execute($source, new StubBypassConnector, StubBypassConnector::manifest(), 'schedule');

    expect($result['streams']['ok'])->toBe('suppressed');
    $run = DB::table('ingest.runs')->where('id', $result['run_id'])->first();
    expect((int) $run->records_seen)->toBe(0);
});

it('lets a source still owing its eager run through the suppression', function () {
    $source = suppressedBypassSource(eager: true);

    $result = app(RunExecutor::class)->execute($source, new StubBypassConnector, StubBypassConnector::manifest(), 'schedule');

    expect($result['streams']['ok'])->toBe('ok');
    $run = DB::table('ingest.runs')->where('id', $result['run_id'])->first();
    expect((int) $run->records_seen)->toBe(1);
});
