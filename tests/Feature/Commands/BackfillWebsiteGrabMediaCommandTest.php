<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Item 5 backfill: legacy GalleryAutoGrabber rows (pre-bridge, item-less)
// become 'website:' provenance media-pool items through the manual lane.
// Fixture shape mirrors MediaUploadBackfillerTest — the slice-1a sibling
// this command deliberately does not overlap with.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupMediaTables();
    Storage::fake('media');
    Queue::fake();
});

function grabRow(string $siteId, array $overrides = [], bool $withVariant = true): string
{
    $id = (string) Str::uuid();
    DB::table('site.site_media')->insert(array_merge([
        'id' => $id, 'site_id' => $siteId, 'bucket' => 'media',
        'path' => "images/{$id}/original.jpg", 'usage' => 'content', 'media_type' => 'image',
        'processing_state' => 'ready', 'is_active' => 1, 'sort_order' => 0,
        'alt_text' => null, 'caption' => null,
        'created_at' => now()->subMonth(), 'updated_at' => now()->subMonth(),
    ], $overrides));

    if ($withVariant) {
        DB::table('site.media_variants')->insert([
            'id' => (string) Str::uuid(), 'media_id' => $id,
            'variant_key' => 'optimized', 'artifact_type' => 'webp', 'disk' => 'media',
            'path' => "variants/{$id}/optimized.webp", 'mime' => 'image/webp',
            'width' => 1200, 'height' => 800, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $id;
}

it('mints a website-provenance item for an item-less legacy gallery row, dated at the row', function () {
    [$pro, $siteId] = poolTenant();
    $mediaId = grabRow($siteId);

    $this->artisan('content:backfill-website-grab-media')
        ->expectsOutputToContain('minted 1')
        ->assertExitCode(0);

    $itemId = DB::table('content.item_anchors')
        ->where('user_id', $pro->id)
        ->where('coord', 'website:'.$mediaId)
        ->value('item_id');
    expect($itemId)->not->toBeNull()
        ->and(DB::table('content.items')->where('id', $itemId)->value('kind'))->toBe('media');

    // Dated at the row's created_at, not the backfill run — a months-old
    // grab must not outrank newer real content in newest order.
    $publishedFrom = DB::table('content.f_published')->where('item_id', $itemId)->value('published_from');
    expect($publishedFrom)->toContain(now()->subMonth()->format('Y-m-d'));
});

it('is idempotent and works the same after the pool-flip migration (content pool)', function () {
    [$pro, $siteId] = poolTenant();
    $mediaId = grabRow($siteId, ['usage' => 'content']);

    $this->artisan('content:backfill-website-grab-media')->assertExitCode(0);
    $this->artisan('content:backfill-website-grab-media')
        ->expectsOutputToContain('1 already bridged')
        ->assertExitCode(0);

    expect(DB::table('content.item_anchors')->where('coord', 'website:'.$mediaId)->count())->toBe(1)
        ->and(DB::table('content.items')->where('user_id', $pro->id)->where('kind', 'media')->count())->toBe(1);
});

it('never re-mints a row already bridged under another manual namespace', function () {
    [$pro, $siteId] = poolTenant();
    $mediaId = grabRow($siteId);

    // The slice-1a backfill got here first: its 'manual:' anchor stands.
    DB::table('content.item_anchors')->insert([
        'user_id' => $pro->id, 'coord' => 'manual:'.$mediaId,
        'item_id' => (string) Str::uuid(), 'bound_at' => now(),
    ]);

    $this->artisan('content:backfill-website-grab-media')
        ->expectsOutputToContain('1 already bridged')
        ->assertExitCode(0);

    expect(DB::table('content.item_anchors')->where('coord', 'website:'.$mediaId)->exists())->toBeFalse();
});

it('skips and counts non-ready and variantless rows', function () {
    [$pro, $siteId] = poolTenant();
    grabRow($siteId);                                                   // eligible
    grabRow($siteId, ['processing_state' => 'failed', 'sort_order' => 1]);   // skipped: not ready
    grabRow($siteId, ['sort_order' => 2], withVariant: false);          // skipped: no webp variant

    // ONE expectation for the one summary line: PendingCommand consumes a
    // written line per expectsOutputToContain, so three substrings can never
    // all match a single info() call.
    $this->artisan('content:backfill-website-grab-media')
        ->expectsOutputToContain('minted 1, 0 already bridged, skipped 1 not-ready, 1 without a webp variant')
        ->assertExitCode(0);
});

it('writes nothing under --dry-run and reports the would-be count', function () {
    [$pro, $siteId] = poolTenant();
    grabRow($siteId);

    $this->artisan('content:backfill-website-grab-media', ['--dry-run' => true])
        ->expectsOutputToContain('would mint 1')
        ->assertExitCode(0);

    expect(DB::table('content.items')->count())->toBe(0);
    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});
