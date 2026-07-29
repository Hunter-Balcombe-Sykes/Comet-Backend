<?php

// OBS-7: RunExecutor::drain()'s match(true) default arm used to Log::warning
// and keep draining as if nothing happened — invisible to Nightwatch, and an
// incomplete-looking drain silently treated as complete. It now throws, which
// the OUTER catch in execute() (already correct, verified pre-existing) turns
// into a report(), a per-stream 'error' outcome, and backpressure — while
// letting the run's OTHER streams still complete.

use App\Exceptions\Ingest\UnknownConnectorMessageException;
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
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    setupIngestTables();
});

/**
 * A connector with two streams: 'bad' yields a message outside the sealed
 * Record/Covered/Bookmark/Note/Deferred/Unavailable set, 'ok' yields a
 * legitimate Record. Proves the bad stream aborts on its own without taking
 * a healthy sibling stream down with it.
 */
class StubUnknownMessageConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('stub_test'),
            identifierKind: 'handle',
            hosts: ['example.test'],
            streams: [
                'bad' => new StreamSpec(name: 'bad', target: 'none', profile: SourceProfile::Mirror),
                'ok' => new StreamSpec(name: 'ok', target: 'none', profile: SourceProfile::Mirror),
            ],
        );
    }

    public function pull(Pull $pull, Io $io): iterable
    {
        if ($pull->stream->name === 'bad') {
            // Anonymous object implementing none of the sealed Message
            // subtypes — the exact shape a connector bug would produce.
            yield new class
            {
                public string $garbage = 'not a Message';
            };

            return;
        }

        yield new Record('ok', 'key-1', ['field' => 'value']);
    }
}

it('reports UnknownConnectorMessageException when a connector yields an unregistered message type', function () {
    Exceptions::fake();

    $source = ['id' => (string) Str::uuid(), 'user_id' => null, 'source_key' => 'stub_test', 'identifier' => 'stub-1'];

    app(RunExecutor::class)->execute($source, new StubUnknownMessageConnector, StubUnknownMessageConnector::manifest(), 'manual');

    Exceptions::assertReported(
        fn (UnknownConnectorMessageException $e) => str_contains($e->getMessage(), 'StubUnknownMessageConnector')
    );
});

it('the run does not abort — the offending stream errors but a sibling stream still completes', function () {
    Exceptions::fake();

    $source = ['id' => (string) Str::uuid(), 'user_id' => null, 'source_key' => 'stub_test', 'identifier' => 'stub-1'];

    $result = app(RunExecutor::class)->execute($source, new StubUnknownMessageConnector, StubUnknownMessageConnector::manifest(), 'manual');

    expect($result['streams']['bad'])->toBe('error')
        ->and($result['streams']['ok'])->toBe('ok');

    $run = DB::table('ingest.runs')->where('id', $result['run_id'])->first();
    $detail = json_decode((string) $run->detail, true);
    expect($detail['streams']['bad'])->toBe('error')
        ->and($detail['streams']['ok'])->toBe('ok');
});

it('records the offending stream failure as backpressure (consecutive_failures)', function () {
    Exceptions::fake();

    $source = ['id' => (string) Str::uuid(), 'user_id' => null, 'source_key' => 'stub_test', 'identifier' => 'stub-1'];

    app(RunExecutor::class)->execute($source, new StubUnknownMessageConnector, StubUnknownMessageConnector::manifest(), 'manual');

    $badStreamFailures = DB::table('ingest.streams')
        ->where('source_id', $source['id'])
        ->where('stream_name', 'bad')
        ->value('consecutive_failures');

    expect((int) $badStreamFailures)->toBe(1);
});
