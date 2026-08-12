<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Slice 1a §3.5: media items ship every frame (positional, dimensioned);
// every other kind ships frames: [] — the wire shape does not change with
// kind. thumbnail STAYS a bare string with cover→poster→gallery priority.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupMediaTables();
    Storage::fake('media');
    Queue::fake();
});

function frameAsset(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('content.media_assets')->insert(array_merge([
        'id' => $id, 'user_id' => $userId, 'fingerprint' => 'url-'.sha1($id),
        'source_url' => null, 'storage_path' => null, 'site_media_id' => null,
        'width' => null, 'height' => null, 'created_at' => now(),
    ], $overrides));

    return $id;
}

function frameRow(string $itemId, string $sourceId, string $assetId, string $role, int $position, ?string $alt = null): void
{
    DB::table('content.item_media')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'asset_id' => $assetId, 'role' => $role, 'position' => $position,
        'alt_text' => $alt, 'created_at' => now(),
    ]);
}

it('ships ordered frames with dims for a multi-frame media item, omitting the unresolvable', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $sourceId, 'media', 'Gallery shot', '2026-08-01T00:00:00Z');

    $a = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/a.jpg', 'width' => 800, 'height' => 600]);
    $b = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/b.jpg', 'width' => 640, 'height' => 480]);
    $dead = frameAsset($pro->id); // ref-only Google shape: no url anywhere

    frameRow($itemId, $sourceId, $b, 'gallery', 1, 'second');
    frameRow($itemId, $sourceId, $a, 'cover', 0, 'first');
    frameRow($itemId, $sourceId, $dead, 'gallery', 2);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['frames'])->toBe([
        ['url' => 'https://cdn.example.com/a.jpg', 'width' => 800, 'height' => 600, 'role' => 'cover', 'alt' => 'first'],
        ['url' => 'https://cdn.example.com/b.jpg', 'width' => 640, 'height' => 480, 'role' => 'gallery', 'alt' => 'second'],
    ])->and($item['thumbnail'])->toBe('https://cdn.example.com/a.jpg');
});

it('ships frames: [] for a non-media kind that carries item_media rows', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $sourceId, 'video', 'A video', '2026-08-01T00:00:00Z');
    $asset = frameAsset($pro->id, ['source_url' => 'https://i.ytimg.com/x.jpg']);
    frameRow($itemId, $sourceId, $asset, 'cover', 0);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'watch');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['frames'])->toBe([])
        ->and($item['thumbnail'])->toBe('https://i.ytimg.com/x.jpg'); // unchanged for existing kinds
});

it('keeps cover→poster→gallery role priority for thumbnail, independent of position', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $sourceId, 'media', 'Priority', '2026-08-01T00:00:00Z');

    $gallery = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/gallery.jpg']);
    $poster = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/poster.jpg']);
    frameRow($itemId, $sourceId, $gallery, 'gallery', 0); // positionally FIRST
    frameRow($itemId, $sourceId, $poster, 'poster', 1);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    // Role priority wins for thumbnail; frames stay positional.
    expect($item['thumbnail'])->toBe('https://cdn.example.com/poster.jpg')
        ->and($item['frames'][0]['url'])->toBe('https://cdn.example.com/gallery.jpg');
});

it('resolves an upload-backed frame through the variant pipeline', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $sourceId, 'media', 'Upload', '2026-08-01T00:00:00Z');

    $siteMediaId = (string) Str::uuid();
    DB::table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $siteMediaId,
        'variant_key' => 'optimized', 'artifact_type' => 'webp', 'disk' => 'media',
        'path' => "variants/{$siteMediaId}/optimized.webp", 'mime' => 'image/webp',
        'width' => 2400, 'height' => 1600, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $asset = frameAsset($pro->id, ['site_media_id' => $siteMediaId]);
    frameRow($itemId, $sourceId, $asset, 'cover', 0);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['frames'][0]['url'])->toContain('optimized')
        ->and($item['frames'][0]['width'])->toBe(2400)
        ->and($item['thumbnail'])->toBe($item['frames'][0]['url']); // frames[0] IS what thumbnail resolves to
});
