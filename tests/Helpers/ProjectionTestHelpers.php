<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Shared across tests/Feature/Ingest/ProjectionWriterTest.php and
// ProjectionSyncShapesTest.php (moved here 2026-08-26, CrossFileTestHelperGuardTest):
// a helper declared in one test file and called from another fatals under
// --parallel whenever the calling file lands in a worker that was not also
// assigned the declaring file. Every worker loads tests/Pest.php, which
// require_once's this file, so call sites did not need to change.

if (! function_exists('projectableBandcamp')) {
    /** A user + active bandcamp connection + its ingest source/stream, with $docs landed as current records. */
    function projectableBandcamp(array $docs, ?string $userId = null): array
    {
        $userId ??= createTenant('proj-'.Str::lower(Str::random(6)))->id;

        $connection = IntegrationConnection::create([
            'user_id' => $userId,
            'platform' => 'bandcamp',
            'resource_id' => 'acct-'.substr(sha1(Str::random(8)), 0, 16),
            'payload' => ['url' => 'https://'.Str::lower(Str::random(8)).'.bandcamp.com'],
            'is_active' => true,
        ]);

        $source = (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
        $streamId = (string) Str::uuid();
        DB::table('ingest.streams')->insert([
            'id' => $streamId, 'source_id' => $source['id'], 'stream_name' => 'releases',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($docs as $key => $doc) {
            landCurrentRecord($streamId, (string) $key, $doc);
        }

        return [$userId, $connection, $source, $streamId];
    }
}

if (! function_exists('landCurrentRecord')) {
    function landCurrentRecord(string $streamId, string $key, array $doc): void
    {
        DB::table('ingest.record_versions')->insert([
            'stream_id' => $streamId, 'key' => $key, 'doc_hash' => sha1(json_encode($doc)),
            'doc' => json_encode($doc), 'first_seen_at' => now(), 'is_current' => 1,
        ]);
        $versionId = DB::table('ingest.record_versions')->where('stream_id', $streamId)->where('key', $key)->value('id');
        DB::table('ingest.record_state')->insert([
            'stream_id' => $streamId, 'key' => $key, 'current_version_id' => $versionId,
            'last_seen_at' => now(),
        ]);
    }
}

if (! function_exists('bandcampDoc')) {
    function bandcampDoc(string $title, string $url): array
    {
        return ['title' => $title, 'url' => $url, 'artist' => 'Some Artist', 'release_date' => '2025-05-05', 'art_url' => 'https://f4.bcbits.com/img/a1_10.jpg', 'type' => 'album'];
    }
}

if (! function_exists('projectOne')) {
    /** Writes one projection through the real writeManualItem() seam; returns the item id. */
    function projectOne(string $sourceId, string $userId, string $coord, array $projection): string
    {
        return app(ProjectionWriter::class)->writeManualItem($userId, $coord, $projection);
    }
}
