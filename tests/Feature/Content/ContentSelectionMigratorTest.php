<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Services\Migration\ContentSelectionMigrator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 1b D10. Three upload selections carry across as pins; the eighty-six
// google-photo and ig rows are counted as dropped, on the record, with their
// site ids. Nothing is DELETED here — site.content_selection goes in slice 7,
// so a bad run stays recoverable.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupContentSelectionTable();
    Bus::fake();
});

function csTenant(): array
{
    $user = createTenant('cs-'.Str::lower(Str::random(6)));
    $siteId = (string) DB::table('site.sites')->where('user_id', $user->id)->value('id');

    return [$user, $siteId];
}

function csSelection(string $siteId, string $entryType, int $position, ?string $mediaId = null, ?string $externalRef = null): void
{
    DB::table('site.content_selection')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'position' => $position,
        'entry_type' => $entryType,
        'media_id' => $mediaId,
        'external_ref' => $externalRef,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** A backfilled upload item, exactly as 1a's MediaUploadBackfiller leaves it. */
function csUploadItem(string $userId, string $siteMediaId): string
{
    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:'.$siteMediaId, [
        'kind' => 'media',
        'headline' => 'Shopfront',
        'media' => [[
            'role' => 'cover',
            'site_media_id' => $siteMediaId,
            'width' => 1200, 'height' => 800, 'mime_type' => 'image/webp',
        ]],
    ]);

    return (string) DB::table('content.media_assets')
        ->where('site_media_id', $siteMediaId)->value('id');
}

it('migrates an upload selection to a pool pin', function () {
    [$user, $siteId] = csTenant();
    $siteMediaId = (string) Str::uuid();
    csUploadItem($user->id, $siteMediaId);
    csSelection($siteId, 'upload', 1, mediaId: $siteMediaId);

    $result = app(ContentSelectionMigrator::class)->run();

    $itemId = DB::table('content.source_items')->where('coord', 'manual:'.$siteMediaId)->value('item_id');

    expect($result['migrated'])->toBe(1)
        ->and(DB::table('site.section_items')->where('item_id', $itemId)->where('state', 'pinned')->exists())->toBeTrue();
});

it('counts google selections as dropped and pins nothing', function () {
    // D10: auto-seeded by maybeSeedFromGoogle(), already resolving to nothing
    // because refs rotate, and under D5 there is no destination anyway.
    [$user, $siteId] = csTenant();
    csSelection($siteId, 'google-photo', 1, externalRef: 'places/ChIJx/photos/AWCwydold');

    $result = app(ContentSelectionMigrator::class)->run();

    expect($result['dropped_google'])->toBe(1)
        ->and($result['migrated'])->toBe(0)
        ->and(DB::table('site.section_items')->count())->toBe(0);
});

it('counts ig selections as dropped — they carry no identifier to migrate by', function () {
    [$user, $siteId] = csTenant();
    csSelection($siteId, 'ig-post', 1);
    csSelection($siteId, 'ig-reel', 2);

    $result = app(ContentSelectionMigrator::class)->run();

    expect($result['dropped_ig'])->toBe(2)
        ->and(DB::table('site.section_items')->count())->toBe(0);
});

it('never deletes a content_selection row', function () {
    // The migration is ADDITIVE. site.content_selection is dropped in slice 7,
    // not here — so a bad run is recoverable.
    [$user, $siteId] = csTenant();
    csSelection($siteId, 'google-photo', 1, externalRef: 'places/ChIJx/photos/AWCwydold');

    app(ContentSelectionMigrator::class)->run();

    expect(DB::table('site.content_selection')->count())->toBe(1);
});

it('is idempotent across two runs', function () {
    [$user, $siteId] = csTenant();
    $siteMediaId = (string) Str::uuid();
    csUploadItem($user->id, $siteMediaId);
    csSelection($siteId, 'upload', 1, mediaId: $siteMediaId);

    app(ContentSelectionMigrator::class)->run();
    app(ContentSelectionMigrator::class)->run();

    $itemId = DB::table('content.source_items')->where('coord', 'manual:'.$siteMediaId)->value('item_id');

    expect(DB::table('site.section_items')->where('item_id', $itemId)->count())->toBe(1);
});

it('writes nothing on a dry run', function () {
    [$user, $siteId] = csTenant();
    $siteMediaId = (string) Str::uuid();
    csUploadItem($user->id, $siteMediaId);
    csSelection($siteId, 'upload', 1, mediaId: $siteMediaId);

    $result = app(ContentSelectionMigrator::class)->run(dryRun: true);

    expect($result['migrated'])->toBe(1)
        ->and(DB::table('site.section_items')->count())->toBe(0);
});

it('bumps all three cache lanes for a touched site', function () {
    // No CI check enforces this — BuildState's docblock claims one that does
    // not exist (parent §9.1). Asserted directly.
    [$user, $siteId] = csTenant();
    $siteMediaId = (string) Str::uuid();
    csUploadItem($user->id, $siteMediaId);
    csSelection($siteId, 'upload', 1, mediaId: $siteMediaId);

    DB::table('site.sites')->where('id', $siteId)->update(['updated_at' => now()->subDay()]);
    $before = DB::table('site.sites')->where('id', $siteId)->value('updated_at');
    $revBefore = DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision');

    app(ContentSelectionMigrator::class)->run();

    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($before)
        ->and(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))->not->toBe($revBefore);
    Bus::assertDispatched(CloudflareCachePurgeJob::class);
});

it('does not touch cache lanes for a site with nothing to migrate', function () {
    // Dropping is not a change. Purging the edge for eighty-six no-ops would
    // be a self-inflicted cache stampede across every Google-only site.
    [$user, $siteId] = csTenant();
    csSelection($siteId, 'google-photo', 1, externalRef: 'places/ChIJx/photos/AWCwydold');

    app(ContentSelectionMigrator::class)->run();

    Bus::assertNotDispatched(CloudflareCachePurgeJob::class);
});

it('skips an upload selection whose item was never backfilled, and counts it', function () {
    [$user, $siteId] = csTenant();
    csSelection($siteId, 'upload', 1, mediaId: (string) Str::uuid());

    $result = app(ContentSelectionMigrator::class)->run();

    expect($result['skipped_no_item'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and($result['migrated'])->toBe(0);
});

it('preserves the owner ordering the selection carried', function () {
    // position is the owner's chosen order. Collapsing it would silently
    // reshuffle a curated gallery.
    [$user, $siteId] = csTenant();
    $first = (string) Str::uuid();
    $second = (string) Str::uuid();
    csUploadItem($user->id, $first);
    csUploadItem($user->id, $second);
    csSelection($siteId, 'upload', 5, mediaId: $second);
    csSelection($siteId, 'upload', 2, mediaId: $first);

    app(ContentSelectionMigrator::class)->run();

    $firstItem = DB::table('content.source_items')->where('coord', 'manual:'.$first)->value('item_id');
    $secondItem = DB::table('content.source_items')->where('coord', 'manual:'.$second)->value('item_id');

    $firstKey = DB::table('site.section_items')->where('item_id', $firstItem)->value('sort_key');
    $secondKey = DB::table('site.section_items')->where('item_id', $secondItem)->value('sort_key');

    expect((float) $firstKey)->toBeLessThan((float) $secondKey);
});
