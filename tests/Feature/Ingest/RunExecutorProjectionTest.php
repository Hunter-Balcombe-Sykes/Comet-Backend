<?php

use App\Ingest\Connectors\BandcampConnector;
use App\Ingest\Runtime\RunExecutor;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Landing → Projection in ONE run (plan §4): the executor that lands a
// changed record version must also invoke its projector, so content rows
// exist the moment the fetch finishes — no separate sweep, no window where
// ingest.record_versions is populated and content.items is not. Uses the real
// BandcampConnector against a faked HTTP layer, the real Lander, the real
// ProjectionWriter.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

function bandcampMusicPage(array $items): string
{
    $blob = htmlspecialchars(json_encode(array_map(fn (array $i) => [
        'page_url' => $i['path'],
        'title' => $i['title'],
        'artist' => 'Executor Artist',
        'release_date' => $i['date'],
        'art_id' => 12345,
        'type' => 'album',
    ], $items)), ENT_QUOTES);

    return '<html><ol data-client-items="'.$blob.'"></ol></html>';
}

it('lands and projects in the same run, then writes nothing new on an unchanged second run', function () {
    $userId = createTenant('exec-'.Str::lower(Str::random(6)))->id;
    $connection = IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-'.substr(sha1('exec'), 0, 16),
        'payload' => ['url' => 'https://executor.bandcamp.com'],
        'is_active' => true,
    ]);
    $source = (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    expect($source)->not->toBeEmpty();

    Http::fake([
        'https://executor.bandcamp.com/music' => Http::response(bandcampMusicPage([
            ['path' => '/album/alpha', 'title' => 'Alpha', 'date' => '01 Jan 2025 00:00:00 GMT'],
            ['path' => '/album/beta', 'title' => 'Beta', 'date' => '02 Feb 2025 00:00:00 GMT'],
        ]), 200),
    ]);

    $executor = app(RunExecutor::class);
    $first = $executor->execute($source, new BandcampConnector, BandcampConnector::manifest(), 'manual');

    expect($first['outcome'])->toBe('ok')
        ->and(DB::table('ingest.record_versions')->count())->toBe(2)
        // The load-bearing assertion: projection ran INSIDE the run.
        ->and(DB::table('content.items')->where('user_id', $userId)->where('kind', 'release')->count())->toBe(2)
        ->and(DB::table('content.source_items')->whereNull('removed_at')->count())->toBe(2)
        ->and(DB::table('content.f_link')->count())->toBe(2);

    $itemsBefore = DB::table('content.items')->orderBy('id')->pluck('id')->all();

    $second = $executor->execute($source, new BandcampConnector, BandcampConnector::manifest(), 'manual');

    expect($second['outcome'])->toBe('ok')
        ->and(DB::table('ingest.record_versions')->count())->toBe(2)
        ->and(DB::table('content.items')->orderBy('id')->pluck('id')->all())->toBe($itemsBefore);
});

it('records a projection failure as an anomaly without failing the fetch', function () {
    $userId = createTenant('anom-'.Str::lower(Str::random(6)))->id;
    $connection = IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-'.substr(sha1('anom'), 0, 16),
        'payload' => ['url' => 'https://anomaly.bandcamp.com'],
        'is_active' => true,
    ]);
    $source = (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();

    Http::fake([
        'https://anomaly.bandcamp.com/music' => Http::response(bandcampMusicPage([
            ['path' => '/album/only', 'title' => 'Only', 'date' => '01 Jan 2025 00:00:00 GMT'],
        ]), 200),
    ]);

    // Sabotage the projection layer only: dropping the content mirror makes
    // every content write throw while landing stays intact.
    DB::connection('pgsql')->statement('DROP TABLE content.source_items');

    $result = app(RunExecutor::class)->execute($source, new BandcampConnector, BandcampConnector::manifest(), 'manual');

    expect($result['outcome'])->toBe('ok')
        ->and(DB::table('ingest.record_versions')->count())->toBe(1)
        ->and(DB::table('ingest.anomalies')->where('kind', 'projection')->count())->toBe(1);

    // Restore for the suite's shared in-memory schema.
    setupContentTables();
});
