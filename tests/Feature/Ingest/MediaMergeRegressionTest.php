<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Parent spec §8.3, extended for slice 1b.
//
// mergeInto() ends in a hard DELETE of a discarded item carrying neither a pin
// nor an override, and resolveItems() unions EVERY live source_item for
// (user_id, kind) across all sources — not just the stream being projected.
// So a connector run can destroy rows another lane wrote.
//
// 1a proved preferOwnerAnchored() protects owner-authored rows against ONE
// connector source. After this slice there are TWO connector sources on the
// media kind at once, which has never been exercised.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Bus::fake();
});

/**
 * A connector stream with one landed record, ready to project.
 *
 * @return array{0: array<string, mixed>, 1: string} [ingest source row, stream id]
 */
function mergeLaneStream(string $userId, string $platform, string $streamName, string $key, array $doc): array
{
    $connection = IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => $platform,
        'resource_id' => $platform.'-'.Str::random(6),
        'payload' => $platform === 'google-business' ? ['placeId' => 'ChIJtestplaceid0001'] : ['username' => 'someone'],
        'place_id' => $platform === 'google-business' ? 'ChIJtestplaceid0001' : null,
        'is_active' => true,
    ]);

    $source = (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    $streamId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => $streamId, 'source_id' => $source['id'], 'stream_name' => $streamName,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    mergeLaneLand($streamId, $key, $doc);

    return [$source, $streamId];
}

/** Land (or replace) one record on an existing stream. */
function mergeLaneLand(string $streamId, string $key, array $doc): void
{
    DB::table('ingest.record_versions')->insert([
        'stream_id' => $streamId, 'key' => $key, 'doc_hash' => sha1(json_encode($doc)),
        'doc' => json_encode($doc), 'first_seen_at' => now(), 'is_current' => 1,
    ]);
    $versionId = DB::table('ingest.record_versions')
        ->where('stream_id', $streamId)->where('key', $key)->orderByDesc('id')->value('id');
    DB::table('ingest.record_state')->insert([
        'stream_id' => $streamId, 'key' => $key, 'current_version_id' => $versionId, 'last_seen_at' => now(),
    ]);
}

/** Owner-authored upload items, as 1a's backfiller leaves them. */
function mergeLaneUploads(string $userId, int $count): array
{
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $siteMediaId = (string) Str::uuid();
        app(ProjectionWriter::class)->writeManualItem($userId, 'manual:'.$siteMediaId, [
            'kind' => 'media',
            'headline' => 'Upload '.$i,
            'media' => [[
                'role' => 'cover', 'site_media_id' => $siteMediaId,
                'width' => 1200, 'height' => 800, 'mime_type' => 'image/webp',
            ]],
        ]);
        $ids[] = (string) DB::table('content.source_items')
            ->where('coord', 'manual:'.$siteMediaId)->value('item_id');
    }

    return $ids;
}

function mergeLaneLiveMediaIds(string $userId): array
{
    return DB::table('content.items')->where('user_id', $userId)
        ->where('kind', 'media')->whereNull('removed_at')->pluck('id')->map(fn ($i) => (string) $i)->all();
}

it('leaves uploads and instagram alive after a google run', function () {
    $user = createTenant('mrg-'.Str::lower(Str::random(6)));

    $uploadIds = mergeLaneUploads($user->id, 3);

    [$igSource, $igStream] = mergeLaneStream($user->id, 'instagram', 'media', 'ABC123', [
        'shortcode' => 'ABC123',
        'images' => ['https://scontent.cdninstagram.com/v/one.jpg'],
        'caption' => 'A post',
    ]);
    app(ProjectionWriter::class)->projectStream($igSource, $igStream, 'media');

    $afterIg = mergeLaneLiveMediaIds($user->id);
    expect($afterIg)->toHaveCount(4);

    [$gSource, $gStream] = mergeLaneStream($user->id, 'google-business', 'media', 'places/ChIJx/photos/AWCwydone', [
        'ref' => 'places/ChIJx/photos/AWCwydone', 'width_px' => 800, 'height_px' => 600,
    ]);
    app(ProjectionWriter::class)->projectStream($gSource, $gStream, 'media');

    $survivors = mergeLaneLiveMediaIds($user->id);

    foreach ($uploadIds as $id) {
        expect($survivors)->toContain($id);
    }
    foreach ($afterIg as $id) {
        expect($survivors)->toContain($id);
    }
});

it('churns google media across two runs with rotated refs, and does not touch owned items', function () {
    // Spec §2.1: refs rotate every fetch, so the second run presents entirely
    // unrecognised coords. The google set is EXPECTED to be replaced. What
    // must not happen is the owned items going with it.
    $user = createTenant('mrg-'.Str::lower(Str::random(6)));
    $uploadIds = mergeLaneUploads($user->id, 3);

    [$gSource, $gStream] = mergeLaneStream($user->id, 'google-business', 'media', 'places/ChIJx/photos/AWCwydrun1', [
        'ref' => 'places/ChIJx/photos/AWCwydrun1', 'width_px' => 800, 'height_px' => 600,
    ]);
    app(ProjectionWriter::class)->projectStream($gSource, $gStream, 'media');

    // Second fetch: same photo, brand-new resource name.
    DB::table('ingest.record_state')->where('stream_id', $gStream)->delete();
    mergeLaneLand($gStream, 'places/ChIJx/photos/AWCwydrun2', [
        'ref' => 'places/ChIJx/photos/AWCwydrun2', 'width_px' => 800, 'height_px' => 600,
    ]);
    app(ProjectionWriter::class)->projectStream($gSource, $gStream, 'media');

    $survivors = mergeLaneLiveMediaIds($user->id);

    foreach ($uploadIds as $id) {
        expect($survivors)->toContain($id);
    }
});
