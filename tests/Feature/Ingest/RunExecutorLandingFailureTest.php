<?php

use App\Ingest\Connectors\BandcampConnector;
use App\Ingest\Landing\Lander;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Runtime\RunExecutor;
use App\Jobs\Ingest\RunSourceJob;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// WHK-1. land() was the ONE call in execute()'s per-stream loop with no
// try/catch, while its drain() and projectStream() siblings both catch, report
// and degrade. So anything escaping the landing propagated out of the whole
// foreach: every REMAINING stream for the source was skipped, the run row was
// left to the abandoned-run sweep, and RunSourceJob is $tries = 1 so nothing
// re-attempted any of it.
//
// The Lander is faked here on purpose. The real failure is a Postgres-only
// 22P05 (a NUL byte rejected by jsonb) which SQLite cannot reproduce at all —
// that half is proven executably in tests/Postgres/LanderBatchLandingTest.php.
// What THIS lane can prove is the executor's contract: whatever land() does,
// it must not take the run down with it.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Bus::fake([RunSourceJob::class]);
});

function landingFailureSource(): array
{
    $userId = createTenant('land-'.Str::lower(Str::random(6)))->id;
    $connection = IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-'.substr(sha1('landfail'.Str::random(4)), 0, 16),
        'payload' => ['url' => 'https://landfail.bandcamp.com'],
        'is_active' => true,
    ]);

    Http::fake([
        'https://landfail.bandcamp.com/music' => Http::response(
            '<html><ol data-client-items="'.htmlspecialchars(json_encode([[
                'page_url' => '/album/alpha',
                'title' => 'Alpha',
                'artist' => 'Landing Artist',
                'release_date' => '01 Jan 2025 00:00:00 GMT',
                'art_id' => 12345,
                'type' => 'album',
            ]]), ENT_QUOTES).'"></ol></html>',
            200
        ),
    ]);

    return (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
}

it('does not let a landing failure escape the run, and records it as a stream failure', function () {
    $source = landingFailureSource();

    app()->bind(Lander::class, fn () => new class extends Lander
    {
        public function land(string $streamId, string $runId, StreamSpec $spec, array $records, ?Covered $covered, array $redactions = []): array
        {
            throw new RuntimeException('landing blew up');
        }
    });

    $result = app(RunExecutor::class)->execute(
        $source,
        new BandcampConnector,
        BandcampConnector::manifest(),
        'manual',
    );

    // Before WHK-1 this line was never reached — execute() threw.
    expect($result['outcome'])->toBe('error');

    // The stream must earn its backoff, exactly as a failed drain() does.
    $stream = DB::table('ingest.streams')->where('source_id', $source['id'])->first();
    expect((int) $stream->consecutive_failures)->toBe(1);
    expect($stream->health)->toBe('unavailable');
});

it('degrades — rather than fails — a run where the fallback isolated some records but landed others', function () {
    $source = landingFailureSource();

    app()->bind(Lander::class, fn () => new class extends Lander
    {
        public function land(string $streamId, string $runId, StreamSpec $spec, array $records, ?Covered $covered, array $redactions = []): array
        {
            return ['seen' => 3, 'changed' => 2, 'tombstoned' => 0, 'guard_tripped' => false, 'failed' => 1];
        }
    });

    $result = app(RunExecutor::class)->execute(
        $source,
        new BandcampConnector,
        BandcampConnector::manifest(),
        'manual',
    );

    // 'degraded' and not 'ok': two records landed, one did not, and a run that
    // reports 'ok' having silently dropped a record is the outcome this whole
    // finding is about.
    expect($result['outcome'])->toBe('degraded');

    // NOT a stream failure — the fetch and most of the landing worked, so
    // nothing here should push the source towards backoff.
    $stream = DB::table('ingest.streams')->where('source_id', $source['id'])->first();
    expect((int) $stream->consecutive_failures)->toBe(0);
});

it('treats a chunk where every record failed as a stream failure, not a degraded run', function () {
    $source = landingFailureSource();

    app()->bind(Lander::class, fn () => new class extends Lander
    {
        public function land(string $streamId, string $runId, StreamSpec $spec, array $records, ?Covered $covered, array $redactions = []): array
        {
            return ['seen' => 2, 'changed' => 0, 'tombstoned' => 0, 'guard_tripped' => false, 'failed' => 2];
        }
    });

    $result = app(RunExecutor::class)->execute(
        $source,
        new BandcampConnector,
        BandcampConnector::manifest(),
        'manual',
    );

    // Nothing landed at all. In practice that means the database is unwell,
    // not that one caption was malformed — so it earns the backoff a merely
    // degraded run must not.
    expect($result['outcome'])->toBe('error');
    expect((int) DB::table('ingest.streams')->where('source_id', $source['id'])->value('consecutive_failures'))->toBe(1);
});
