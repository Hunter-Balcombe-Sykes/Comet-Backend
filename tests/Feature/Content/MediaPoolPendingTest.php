<?php

use App\Models\Core\Site\Site;
use App\Services\Media\MediaMirror;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// `pending` answers "are bytes genuinely still coming for this item?" from the
// row's own mirror state. It used to answer it by matching the source url
// against the Meta CDN hosts, which is a different question with a different
// answer for the three non-Meta platforms the mirror lane owns.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupMediaTables();
    Storage::fake('media');
    Queue::fake();
});

/** An in-flight owned asset: eligible, no bytes yet, retries left. */
function pendingItem(string $userId, string $sourceId, array $assetOverrides): string
{
    $itemId = poolItem($userId, $sourceId, 'media', 'Pending', '2026-08-01T00:00:00Z');
    $asset = frameAsset($userId, array_merge([
        'mirror_eligible' => true,
        'mirror_attempts' => 0,
    ], $assetOverrides));
    frameRow($itemId, $sourceId, $asset, 'cover', 0);

    return $itemId;
}

function pendingFlag(string $siteId, string $itemId): ?bool
{
    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    return $item['pending'] ?? null;
}

it('reports pending for a meta row whose bytes are genuinely still coming', function () {
    // Held back by MediaUrlResolver until the mirror lands, eligible, retries
    // left: the one shape that is honestly still loading. 6 such rows on dev,
    // 2026-09-05.
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);

    $meta = pendingItem($pro->id, $sourceId, ['source_url' => 'https://scontent.cdninstagram.com/v/one.jpg']);

    expect(pendingFlag($siteId, $meta))->toBeTrue();
});

it('never claims a url-less row is loading, because dispatch cannot fetch it', function () {
    // mirror_eligible comes from the REF, so an owned entry with no usable url
    // mints eligible + source_url NULL. dispatchMirrors skips it before the
    // attempts counter moves, so it can never be fetched and never stops being
    // "pending" — a permanent skeleton, which is the thing this flag must not be.
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);

    $noUrl = pendingItem($pro->id, $sourceId, ['source_url' => null]);

    expect(pendingFlag($siteId, $noUrl))->toBeFalse();
});

it('leaves a still-rendering non-meta asset un-pending', function () {
    // Measured on dev 2026-09-05: all 25 non-Meta in-flight assets carry a
    // source_url, and MediaUrlResolver blocks raw passthrough for the two Meta
    // hosts ONLY (unservableMetaImage). So a TikTok cover renders its vendor
    // link immediately and is correctly NOT "still coming" — something is on
    // screen. Whether those three platforms should also be held back until the
    // bytes are ours is a separate product decision, deliberately not taken here.
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);

    $tiktok = pendingItem($pro->id, $sourceId, ['source_url' => 'https://p16-sign.tiktokcdn-us.com/o/x.jpeg']);

    expect(pendingFlag($siteId, $tiktok))->toBeFalse();
});

it('stops reporting pending once retries are exhausted', function () {
    // A skeleton that never resolves is worse than an honest empty frame:
    // storage_path never becomes non-null for a link that cannot be fetched.
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);

    $dead = pendingItem($pro->id, $sourceId, [
        'source_url' => 'https://scontent.cdninstagram.com/v/gone.jpg',
        'mirror_attempts' => MediaMirror::maxAttempts(),
    ]);

    expect(pendingFlag($siteId, $dead))->toBeFalse();
});

it('reports false for a borrowed asset, which is never coming', function () {
    // A Google Places photo is correctly never mirrored — the licence forbids
    // storing it. "Not coming" and "still coming" must not look the same.
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);

    $borrowed = pendingItem($pro->id, $sourceId, [
        'source_url' => 'https://lh3.googleusercontent.com/places/x',
        'mirror_eligible' => false,
    ]);

    expect(pendingFlag($siteId, $borrowed))->toBeFalse();
});

it('short-circuits to false when a cover already resolves', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);

    $itemId = poolItem($pro->id, $sourceId, 'media', 'Resolved', '2026-08-01T00:00:00Z');
    $landed = frameAsset($pro->id, [
        'source_url' => 'https://scontent.cdninstagram.com/v/one.jpg',
        'storage_path' => 'content-media/ab/cd.webp',
        'mirror_eligible' => true,
    ]);
    $inFlight = frameAsset($pro->id, [
        'source_url' => 'https://scontent.cdninstagram.com/v/two.jpg',
        'mirror_eligible' => true,
        'mirror_attempts' => 0,
    ]);
    frameRow($itemId, $sourceId, $landed, 'cover', 0);
    frameRow($itemId, $sourceId, $inFlight, 'gallery', 1);

    expect(pendingFlag($siteId, $itemId))->toBeFalse();
});

it('keeps pending off the public wire', function () {
    // DASHBOARD_ONLY_ITEM_KEYS. Changing what pending MEANS is safe only
    // because nothing public reads it.
    expect(PoolResolver::DASHBOARD_ONLY_ITEM_KEYS)->toContain('pending');
});
