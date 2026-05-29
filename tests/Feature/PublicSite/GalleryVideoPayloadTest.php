<?php

use App\Models\Core\MediaVariant;
use App\Models\Core\Site\SiteMedia;
use App\Services\PublicSite\SitepageDataResolverService;

it('emits both optimized and maximized MP4 urls for a video, no HLS', function () {
    // Bind a deterministic CDN base so MediaVariant->url resolves via the fast
    // path (baseUrl/path) without an S3 client / network — see the accessor.
    config(['filesystems.disks.media.url' => 'https://cdn.test']);

    $media = new SiteMedia([
        'media_type' => SiteMedia::MEDIA_TYPE_VIDEO,
        'alt_text' => 'demo',
        'caption' => null,
        'duration_ms' => 12000,
    ]);
    $media->id = 'vid-1';

    // Build the relation in memory — no DB / ffmpeg needed.
    $media->setRelation('mediaVariants', collect([
        new MediaVariant(['variant_key' => 'optimized', 'artifact_type' => 'mp4', 'path' => 'videos/x/optimized_a.mp4', 'disk' => 'media']),
        new MediaVariant(['variant_key' => 'maximized', 'artifact_type' => 'mp4', 'path' => 'videos/x/maximized_b.mp4', 'disk' => 'media']),
        new MediaVariant(['variant_key' => 'poster', 'artifact_type' => 'poster', 'path' => 'videos/x/poster_c.jpg', 'disk' => 'media']),
    ]));

    $resolver = app(SitepageDataResolverService::class);
    $item = $resolver->buildGalleryItem($media);

    expect($item['kind'])->toBe('video')
        ->and($item['url'])->toContain('optimized_a.mp4')
        ->and($item['url_hd'])->toContain('maximized_b.mp4')
        ->and($item['poster'])->toContain('poster_c.jpg')
        ->and($item['duration_ms'])->toBe(12000);
});
