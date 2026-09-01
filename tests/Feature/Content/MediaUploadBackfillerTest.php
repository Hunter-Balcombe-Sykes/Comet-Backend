<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Services\Migration\MediaUploadBackfiller;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Slice 1a §3.7: the 25 live gallery/content uploads become media items
// through the slice-0b manual lane — production code, tested, idempotent,
// re-runnable (convergence invariant #4). Never raw writes into content.*.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupMediaTables();
    Storage::fake('media');
    Queue::fake();
});

function uploadRow(string $siteId, array $overrides = [], bool $withVariant = true): string
{
    $id = (string) Str::uuid();
    DB::table('site.site_media')->insert(array_merge([
        'id' => $id, 'site_id' => $siteId, 'bucket' => 'media',
        'path' => "uploads/{$id}.jpg", 'pool' => 'content', 'media_type' => 'image',
        'processing_state' => 'ready', 'is_active' => 1, 'sort_order' => 0,
        'alt_text' => 'An upload', 'caption' => null,
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides));

    if ($withVariant) {
        DB::table('site.media_variants')->insert([
            'id' => (string) Str::uuid(), 'media_id' => $id,
            'variant_key' => 'optimized', 'artifact_type' => 'webp', 'disk' => 'media',
            'path' => "variants/{$id}/optimized.webp", 'mime' => 'image/webp',
            'width' => 2400, 'height' => 1600, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $id;
}

it('backfills a ready gallery upload as a media item with a variant-backed asset', function () {
    [$pro, $siteId] = poolTenant();
    $mediaId = uploadRow($siteId, ['caption' => 'Our shopfront']);

    $result = app(MediaUploadBackfiller::class)->run();

    expect($result['backfilled'])->toBe(1);

    $asset = DB::table('content.media_assets')->where('user_id', $pro->id)->first();
    expect($asset->fingerprint)->toBe('url-'.sha1('upload:'.$mediaId))
        ->and($asset->site_media_id)->toBe($mediaId)
        ->and($asset->dims_confidence)->toBe('measured')
        ->and((int) $asset->width)->toBe(2400);

    $item = DB::table('content.items')->where('user_id', $pro->id)->where('kind', 'media')->first();
    expect($item->headline_cache)->toBe('Our shopfront');

    $coord = DB::table('content.source_items')->value('coord');
    expect($coord)->toBe('manual:'.$mediaId);
});

it('is idempotent across two runs — one item, one asset', function () {
    [$pro, $siteId] = poolTenant();
    uploadRow($siteId);

    $backfiller = app(MediaUploadBackfiller::class);
    $backfiller->run();
    $backfiller->run();

    expect(DB::table('content.items')->where('user_id', $pro->id)->where('kind', 'media')->count())->toBe(1)
        ->and(DB::table('content.media_assets')->where('user_id', $pro->id)->count())->toBe(1);
});

it('skips and counts non-ready and variantless rows, and scopes design/documents/soft-deleted out entirely', function () {
    [$pro, $siteId] = poolTenant();
    uploadRow($siteId);                                                     // eligible
    uploadRow($siteId, ['processing_state' => 'failed']);                   // skipped: not ready
    uploadRow($siteId, ['media_type' => 'video'], withVariant: false);      // skipped: no webp variant
    uploadRow($siteId, ['pool' => 'design']);                               // out of scope entirely
    uploadRow($siteId, ['pool' => 'documents']);                            // out of scope entirely
    uploadRow($siteId, ['deleted_at' => now()]);                            // soft-deleted: out of scope

    $result = app(MediaUploadBackfiller::class)->run();

    // design/documents/soft-deleted rows never enter the query at all — the
    // scope is `pool IN ('content')` (GALLERY_POOLS post-Wave-6) with SiteMedia's SoftDeletes
    // global scope, so they land in NEITHER counter, not even 'failed'.
    // Total accounted-for rows: 3 (1 eligible + 1 not-ready + 1 no-variant),
    // not 6.
    expect($result['backfilled'])->toBe(1)
        ->and($result['skipped_not_ready'])->toBe(1)
        ->and($result['skipped_no_variant'])->toBe(1)
        ->and($result['failed'])->toBe(0);
});

it('does not resurrect a user-removed upload item on re-run', function () {
    [$pro, $siteId] = poolTenant();
    uploadRow($siteId);

    $backfiller = app(MediaUploadBackfiller::class);
    $backfiller->run();
    DB::table('content.items')->where('user_id', $pro->id)->update(['removed_at' => now()]);

    $backfiller->run();

    expect(DB::table('content.items')->where('user_id', $pro->id)->whereNotNull('removed_at')->count())->toBe(1);
});

it('fires all three cache lanes for the touched site, and only for touched sites', function () {
    [$pro, $siteId] = poolTenant();
    uploadRow($siteId);
    // Laravel binds timestamps at SECOND precision (same hazard documented in
    // ManualSourceLaneTest's manualLaneBandcamp() callers) — without backdating,
    // this run's own updated_at can land in the same second as the fixture's
    // and the "changed" assertion below would pass or fail depending on wall-
    // clock luck rather than proving anything.
    DB::table('site.sites')->where('id', $siteId)->update(['updated_at' => now()->subMinute()]);
    $before = DB::table('site.sites')->where('id', $siteId)->value('updated_at');
    $beforeRevision = BuildState::read($siteId)['content_revision'];

    app(MediaUploadBackfiller::class)->run();

    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
    // BuildState::bump() persists as a monotonic increment on
    // site.site_build_state.content_revision (site.site_build_state, not an
    // Eloquent model) — asserted as the persisted effect, not a mock.
    expect(BuildState::read($siteId)['content_revision'])->toBeGreaterThan($beforeRevision);
});

it('writes nothing under --dry-run and reports the would-be counts', function () {
    [$pro, $siteId] = poolTenant();
    uploadRow($siteId);

    $this->artisan('content:backfill-upload-media', ['--dry-run' => true])
        ->expectsOutputToContain('would backfill 1')
        ->assertExitCode(0);

    expect(DB::table('content.items')->count())->toBe(0);
    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});

/**
 * A google_business connection with its ingest source/stream and one landed
 * `media` record projecting through GoogleBusinessMediaProjector — a ref-only
 * media entry (no fetchable URL, kind 'media'). Mirrors manualLaneBandcamp()
 * in ManualSourceLaneTest.php: create the connection (which provisions
 * ingest.sources via SourceProvisioner/IntegrationConnectionObserver), add a
 * stream row, then land a record_versions/record_state pair.
 *
 * @return array{0: array<string, mixed>, 1: string} [source row, stream id]
 */
function mediaLaneGoogleStream(string $userId, string $ref): array
{
    $connection = IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => ['placeId' => 'ChIJtestplaceid0001'],
        'place_id' => 'ChIJtestplaceid0001',
        'is_active' => true,
    ]);

    $source = (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    $streamId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => $streamId, 'source_id' => $source['id'], 'stream_name' => 'media',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $doc = ['ref' => $ref, 'width_px' => 800, 'height_px' => 600];
    DB::table('ingest.record_versions')->insert([
        'stream_id' => $streamId, 'key' => $ref, 'doc_hash' => sha1(json_encode($doc)),
        'doc' => json_encode($doc), 'first_seen_at' => now(), 'is_current' => 1,
    ]);
    $versionId = DB::table('ingest.record_versions')->where('stream_id', $streamId)->where('key', $ref)->value('id');
    DB::table('ingest.record_state')->insert([
        'stream_id' => $streamId, 'key' => $ref, 'current_version_id' => $versionId, 'last_seen_at' => now(),
    ]);

    return [$source, $streamId];
}

// §8.3 regression (spec §5.2): mergeInto() hard-deletes a discarded item
// carrying neither pin nor override; preferOwnerAnchored() should make the
// owner row win, but it has never been exercised against a media-kind merge.
it('keeps every backfilled upload item alive through a Google Business media projection run', function () {
    [$pro, $siteId] = poolTenant();
    uploadRow($siteId);
    uploadRow($siteId, ['sort_order' => 1]);
    app(MediaUploadBackfiller::class)->run();

    $uploadItemIds = DB::table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'media')->pluck('id')->all();
    expect($uploadItemIds)->toHaveCount(2);

    // A connector-lane media projection for the same user. Follow the
    // manualLaneBandcamp() pattern in tests/Feature/Ingest/ManualSourceLaneTest.php
    // to build a google_business connection source + ingest stream whose
    // record projects through GoogleBusinessMediaProjector's shape
    // (ref-only media entry, kind 'media'), then:
    //   $writer->projectStream($source, $streamId, 'media');
    [$source, $streamId] = mediaLaneGoogleStream($pro->id, 'places/ChIJx/photos/AXCi2');
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'media');

    $survivors = DB::table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'media')
        ->whereNull('removed_at')->pluck('id')->all();

    foreach ($uploadItemIds as $id) {
        expect($survivors)->toContain($id);
    }
    // And their frames still resolve on the pool read:
    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');
    $byId = collect($out['library'])->keyBy('id');
    foreach ($uploadItemIds as $id) {
        expect($byId[$id]['frames'][0]['url'] ?? null)->not->toBeNull();
    }
});
