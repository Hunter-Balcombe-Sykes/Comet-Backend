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

// frameAsset/frameRow now live in tests/Helpers/PoolTestHelpers.php —
// ShopPoolPayloadTest builds frames too, and a helper declared here is
// undefined in any --parallel worker not assigned this file.

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
        ['url' => 'https://cdn.example.com/a.jpg', 'width' => 800, 'height' => 600, 'role' => 'cover', 'kind' => 'image', 'poster' => null, 'alt' => 'first'],
        ['url' => 'https://cdn.example.com/b.jpg', 'width' => 640, 'height' => 480, 'role' => 'gallery', 'kind' => 'image', 'poster' => null, 'alt' => 'second'],
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

// ── The webp-variant invariant (negative case) ──────────────────────────────
// CLAUDE.md names three invariants scripts/launch-check/k6/seed.sql hard-codes,
// one being "a media item needs a matching site.media_variants (webp) row or
// its URL resolves empty". Its negative-case guard lived in two gallery-engine
// tests that slice 7 unit E deleted with the legacy gallery lane. These two
// re-arm it on the surviving pool lane: an upload-backed asset (site_media_id
// set, no storage_path, no source_url) with no webp variant row is
// unresolvable, and MediaUrlResolver omits it from its result entirely
// (resolveOne() returns null).

it('filters an upload-backed frame with no webp variant out of the media payload', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $sourceId, 'media', 'Half processed', '2026-08-01T00:00:00Z');

    // Servable: an upload WITH its optimized webp rendition.
    $readyMediaId = (string) Str::uuid();
    DB::table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $readyMediaId,
        'variant_key' => 'optimized', 'artifact_type' => 'webp', 'disk' => 'media',
        'path' => "variants/{$readyMediaId}/optimized.webp", 'mime' => 'image/webp',
        'width' => 1200, 'height' => 800, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $ready = frameAsset($pro->id, ['site_media_id' => $readyMediaId]);

    // Unservable: an upload with NO media_variants row at all. Given the
    // `cover` role at position 0, so it would win BOTH the positional
    // frames[0] slot and the cover→poster→gallery thumbnail priority if it
    // resolved — a filter that silently kept it would be obvious below.
    $pendingMediaId = (string) Str::uuid();
    $pending = frameAsset($pro->id, ['site_media_id' => $pendingMediaId]);

    frameRow($itemId, $sourceId, $pending, 'cover', 0, 'still processing');
    frameRow($itemId, $sourceId, $ready, 'gallery', 1, 'ready');

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    // Exactly one frame survives, and it is the variant-backed one — not an
    // entry with an empty url, and not a null placeholder.
    expect($item['frames'])->toHaveCount(1)
        ->and($item['frames'][0]['role'])->toBe('gallery')
        ->and($item['frames'][0]['alt'])->toBe('ready')
        ->and($item['frames'][0]['url'])->toContain('optimized')
        // Thumbnail falls THROUGH the unresolvable cover to the servable
        // gallery frame — role priority loses to unresolvability.
        ->and($item['thumbnail'])->toBe($item['frames'][0]['url']);

    // Nothing anywhere in the payload references the variant-less upload.
    expect(json_encode($out, JSON_THROW_ON_ERROR))->not->toContain($pendingMediaId);
});

it('ships an empty gallery, not a broken one, when every frame lacks a webp variant', function () {
    // The all-unresolvable case. The ITEM is deliberately NOT dropped from the
    // payload — MediaUrlResolver's contract is "unrenderable assets degrade to
    // an empty gallery, not broken images", and the item still carries a
    // headline the sitepage can render. Pinned here so the difference between
    // frame-level filtering and item-level filtering stays a decision.
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $sourceId, 'media', 'All pending', '2026-08-01T00:00:00Z');

    $asset = frameAsset($pro->id, ['site_media_id' => (string) Str::uuid()]);
    frameRow($itemId, $sourceId, $asset, 'cover', 0);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item)->not->toBeNull()
        ->and($item['frames'])->toBe([])
        ->and($item['thumbnail'])->toBeNull();
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

it('emits a reel as a video frame with the cover as its poster (R7)', function () {
    [$pro, $siteId] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id, 'instagram.profile'));
    $item = poolItem($pro->id, $source, 'media', 'Reel', '2026-08-01T00:00:00Z');
    $cover = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/cover.jpg', 'width' => 1080, 'height' => 1350]);
    // storage_path set: a MIRRORED reel serves from owned bytes, never the
    // vendor URL (an unmirrored one serves from source only while its oe
    // pre-flight passes — see the progressive/expired pair below).
    $video = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/reel.mp4', 'storage_path' => 'content-media/u/reel.mp4', 'mime_type' => 'video/mp4', 'width' => null, 'height' => null]);
    DB::table('content.item_media')->insert([
        ['id' => (string) Str::uuid(), 'item_id' => $item, 'source_id' => $source, 'asset_id' => $cover, 'role' => 'cover', 'position' => 0, 'created_at' => now()],
        ['id' => (string) Str::uuid(), 'item_id' => $item, 'source_id' => $source, 'asset_id' => $video, 'role' => 'video', 'position' => 1, 'created_at' => now()],
    ]);

    $frames = collect(poolGet($pro, 'media')['selection'])->firstWhere('id', $item)['frames'];
    expect($frames)->toHaveCount(2)
        ->and($frames[0]['kind'])->toBe('image')
        ->and($frames[1]['kind'])->toBe('video')
        // The MIRRORED bytes, not the vendor URL: a reel that reached R2
        // serves from our storage, which is the whole point of mirroring a
        // signed-and-expiring link.
        ->and($frames[1]['url'])->toContain('content-media/u/reel.mp4')
        ->and($frames[1]['poster'])->toBe('https://cdn.example.com/cover.jpg');
});

// Item 7 (2026-09-01, progressive media): an unmirrored video whose signed
// URL is still LIVE serves from source while its mirror drains — the swap to
// owned bytes lands on a later rebuild. The old unconditional drop survives
// only for URLs the oe pre-flight proves dead (the R3 class), below.
it('serves an unmirrored video from its fresh source URL, poster wired, and logs host-only', function () {
    Log::spy();
    [$pro, $siteId] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id, 'instagram.profile'));
    $item = poolItem($pro->id, $source, 'media', 'Reel', '2026-08-01T00:00:00Z');
    $cover = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/cover.jpg', 'width' => 1080, 'height' => 1350]);
    // 0x7A000000 = 2034 — signed, far from lapsing. No storage_path, no
    // site_media_id: the mirror has not landed yet.
    $video = frameAsset($pro->id, ['source_url' => 'https://instagram.fxyz1-1.fna.fbcdn.net/fresh.mp4?oe=7A000000', 'mime_type' => 'video/mp4']);
    DB::table('content.item_media')->insert([
        ['id' => (string) Str::uuid(), 'item_id' => $item, 'source_id' => $source, 'asset_id' => $cover, 'role' => 'cover', 'position' => 0, 'created_at' => now()],
        ['id' => (string) Str::uuid(), 'item_id' => $item, 'source_id' => $source, 'asset_id' => $video, 'role' => 'video', 'position' => 1, 'created_at' => now()],
    ]);

    $frames = collect(poolGet($pro, 'media')['selection'])->firstWhere('id', $item)['frames'];
    expect($frames)->toHaveCount(2)
        ->and($frames[1]['kind'])->toBe('video')
        ->and($frames[1]['url'])->toBe('https://instagram.fxyz1-1.fna.fbcdn.net/fresh.mp4?oe=7A000000')
        ->and($frames[1]['poster'])->toBe('https://cdn.example.com/cover.jpg');
    // The risk window is instrumented — host only, never the signed URL.
    Log::shouldHaveReceived('info')
        ->with('pool.video.progressive_serve', ['host' => 'instagram.fxyz1-1.fna.fbcdn.net'])
        ->atLeast()->once();
});

// The R3 tail (2026-08-28): a reel whose signed URL provably lapsed (oe= in
// the past) stays dropped — serving it is a <video> that never plays, a
// frozen black card on the gallery. The frame degrades away; the cover still
// carries the card. This is Item 7's gate holding, not the old blanket drop.
it('still drops an unmirrored video whose signed URL has expired, keeping the still', function () {
    [$pro, $siteId] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id, 'instagram.profile'));
    $item = poolItem($pro->id, $source, 'media', 'Reel', '2026-08-01T00:00:00Z');
    $cover = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/cover.jpg', 'width' => 1080, 'height' => 1350]);
    // 0x60000000 = 2021 — long lapsed; the pre-flight refuses it.
    $video = frameAsset($pro->id, ['source_url' => 'https://instagram.fxyz1-1.fna.fbcdn.net/dead.mp4?oe=60000000', 'mime_type' => 'video/mp4']);
    DB::table('content.item_media')->insert([
        ['id' => (string) Str::uuid(), 'item_id' => $item, 'source_id' => $source, 'asset_id' => $cover, 'role' => 'cover', 'position' => 0, 'created_at' => now()],
        ['id' => (string) Str::uuid(), 'item_id' => $item, 'source_id' => $source, 'asset_id' => $video, 'role' => 'video', 'position' => 1, 'created_at' => now()],
    ]);

    $frames = collect(poolGet($pro, 'media')['selection'])->firstWhere('id', $item)['frames'];
    expect($frames)->toHaveCount(1)
        ->and($frames[0]['kind'])->toBe('image')
        ->and($frames[0]['url'])->toBe('https://cdn.example.com/cover.jpg');
});
