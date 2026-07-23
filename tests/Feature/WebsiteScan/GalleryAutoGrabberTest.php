<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\WebsiteScan\GalleryAutoGrabber;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSiteMediaTable();
});

function gagSeedSiteMedia(Site $site, string $processingState = SiteMedia::PROCESSING_STATE_READY, bool $isActive = true): SiteMedia
{
    $media = (new SiteMedia([
        'pool' => SiteMedia::POOL_GALLERY,
        'bucket' => 'test-bucket',
        'path' => 'images/existing.jpg',
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => $processingState,
        'is_active' => $isActive,
    ]))->site()->associate($site);
    $media->save();

    return $media;
}

/**
 * rejection() never touches $this->fetcher/$this->uploads — reflectable on a
 * constructor-less instance, same pattern as LogoAutoGrabberTest's
 * upsizeUrl()/svgIsSafe() reflection helpers.
 */
function gagRejection(int $width, int $height): ?string
{
    $grabber = (new ReflectionClass(GalleryAutoGrabber::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($grabber, 'rejection');

    return $method->invoke($grabber, $width, $height);
}

// ── rejection() ──────────────────────────────────────────────────────────────

it('accepts a well-sized landscape photo', function () {
    expect(gagRejection(1200, 800))->toBeNull();
});

it('accepts a well-sized portrait photo', function () {
    expect(gagRejection(800, 1200))->toBeNull();
});

it('rejects an image with a zero dimension', function () {
    expect(gagRejection(0, 800))->toBe('empty');
});

it('rejects an image smaller than the minimum long edge on both axes', function () {
    expect(gagRejection(200, 150))->toBe('too-small');
});

it('accepts an image whose long edge clears the minimum even if the short edge is small', function () {
    // 1600 long edge clears MIN_LONG_EDGE_PX even though 100 is tiny — matches
    // LogoAutoGrabber's own max()-based (not min()-based) long-edge convention.
    expect(gagRejection(1600, 100))->not->toBe('too-small');
});

it('rejects an extremely wide panorama', function () {
    expect(gagRejection(3000, 400))->toBe('extreme-aspect');
});

it('rejects an extremely tall sliver', function () {
    expect(gagRejection(400, 3000))->toBe('extreme-aspect');
});

it('accepts a square image', function () {
    expect(gagRejection(800, 800))->toBeNull();
});

// ── grabIfEmpty() skip gate ──────────────────────────────────────────────────

it('returns no decisions and attempts no fetch when the gallery already has an active photo', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    gagSeedSiteMedia($site);

    // No Http::fake() here — the skip gate must return before any fetch, so
    // an unfaked real HTTP call would surface as a hard failure, not a
    // silent pass.
    $decisions = app(GalleryAutoGrabber::class)->grabIfEmpty($user, $site, ['https://example.com/photo.jpg']);

    expect($decisions)->toBe([]);
});

it('does not skip when the only existing gallery row is failed/inactive', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    gagSeedSiteMedia($site, SiteMedia::PROCESSING_STATE_FAILED);

    Http::fake(['example.com/*' => Http::response('', 404)]);

    // A non-empty decisions array (even a rejection) proves the skip gate
    // didn't trip on the failed row — an empty array here would be
    // indistinguishable from "skipped".
    $decisions = app(GalleryAutoGrabber::class)->grabIfEmpty($user, $site, ['https://example.com/photo.jpg']);

    expect($decisions)->toHaveCount(1);
    expect($decisions[0]['outcome'])->toBe('rejected:unfetchable');
});

it('returns no decisions when given no candidate URLs at all', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();

    $decisions = app(GalleryAutoGrabber::class)->grabIfEmpty($user, $site, []);

    expect($decisions)->toBe([]);
});
