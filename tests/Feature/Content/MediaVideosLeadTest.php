<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// Item 2 (2026-09-01, media surface): the media pool's auto arm runs per
// CLASS — up to N videos AND up to N images per source, newest-first inside
// each class — and the resolved deck leads with every video ahead of every
// image. "Up to", never padded: a source with zero videos fills images only,
// with no gap.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupMediaTables();
    Storage::fake('media');
    Queue::fake();
});

// File-local fixtures (single-file callers only — the cross-suite ones live
// in tests/Helpers/PoolTestHelpers.php). A "video" media item is the lane's
// own shape: a cover still plus a MIRRORED role=video frame, so it both
// matches the SQL class arm and ships a playable frame the deck partition
// classifies on.

function videoMediaItem(string $userId, string $sourceId, string $headline, string $publishedAt): string
{
    $item = poolItem($userId, $sourceId, 'media', $headline, $publishedAt);
    $cover = frameAsset($userId, ['source_url' => 'https://cdn.example.com/'.sha1($item).'.jpg', 'width' => 1080, 'height' => 1350]);
    $video = frameAsset($userId, ['storage_path' => 'content-media/u/'.sha1($item).'.mp4', 'mime_type' => 'video/mp4']);
    frameRow($item, $sourceId, $cover, 'cover', 0);
    frameRow($item, $sourceId, $video, 'video', 1);

    return $item;
}

function imageMediaItem(string $userId, string $sourceId, string $headline, string $publishedAt): string
{
    $item = poolItem($userId, $sourceId, 'media', $headline, $publishedAt);
    $cover = frameAsset($userId, ['source_url' => 'https://cdn.example.com/'.sha1($item).'.jpg', 'width' => 1080, 'height' => 1350]);
    frameRow($item, $sourceId, $cover, 'cover', 0);

    return $item;
}

it('selects up to 5 videos AND up to 5 images per source, every video ahead of every image, newest-first per class', function () {
    [$pro] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id, 'instagram.profile'));

    // Seven videos, then seven images that are ALL newer than every video —
    // if recency still led the deck, images would sweep the front.
    foreach (range(1, 7) as $d) {
        videoMediaItem($pro->id, $source, "V{$d}", sprintf('2026-08-%02dT00:00:00Z', $d));
    }
    foreach (range(1, 7) as $d) {
        imageMediaItem($pro->id, $source, "I{$d}", sprintf('2026-08-%02dT00:00:00Z', $d + 9));
    }

    $data = poolGet($pro, 'media');

    // 5 + 5, videos leading, newest-first within each class.
    expect(poolHeadlines($data))->toBe(['V7', 'V6', 'V5', 'V4', 'V3', 'I7', 'I6', 'I5', 'I4', 'I3']);
    // The owner's stated invariant, pinned verbatim: with 2+ videos the
    // first two deck positions are videos, newest first.
    expect($data['selection'][0]['frames'][1]['kind'] ?? null)->toBe('video')
        ->and($data['selection'][1]['frames'][1]['kind'] ?? null)->toBe('video');
    // The library holds everything either way.
    expect(count($data['library']))->toBe(14);
});

it('degrades to images only with no gap when the source has zero videos', function () {
    [$pro] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id, 'instagram.profile'));

    imageMediaItem($pro->id, $source, 'Old', '2026-08-01T00:00:00Z');
    imageMediaItem($pro->id, $source, 'Mid', '2026-08-02T00:00:00Z');
    imageMediaItem($pro->id, $source, 'New', '2026-08-03T00:00:00Z');

    $data = poolGet($pro, 'media');

    // Three images, newest first — "up to 5", never padded, no error.
    expect(poolHeadlines($data))->toBe(['New', 'Mid', 'Old']);
    foreach ($data['selection'] as $item) {
        expect($item['frames'][0]['kind'])->toBe('image');
    }
});

it('leads with a video across sources — the class windows are per source, the deck order is global', function () {
    [$pro] = poolTenant();
    $ig = poolSource($pro->id, poolConnection($pro->id, 'instagram.profile'));
    $gb = poolSource($pro->id, poolConnection($pro->id, 'google_business.location'));

    // The Google stills are newer than the one reel; the reel still leads.
    videoMediaItem($pro->id, $ig, 'Reel', '2026-08-01T00:00:00Z');
    imageMediaItem($pro->id, $gb, 'GB new', '2026-08-05T00:00:00Z');
    imageMediaItem($pro->id, $gb, 'GB old', '2026-08-04T00:00:00Z');

    expect(poolHeadlines(poolGet($pro, 'media')))->toBe(['Reel', 'GB new', 'GB old']);
});
