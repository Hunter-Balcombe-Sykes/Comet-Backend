<?php

use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Fake the media disk so Storage::disk('media')->url() doesn't try to
    // fetch AWS credentials from the EC2 metadata service in tests.
    Storage::fake('media');
});

it('resolves document.preview_url from path to full CDN URL', function () {
    $service = app(SiteCacheService::class);
    $method = (new ReflectionClass($service))->getMethod('resolveImageVariantUrlsInSite');

    $site = [
        'gallery' => [],
        'content_images' => [],
        'gallery_videos' => [],
        'content_videos' => [],
        'document' => [
            'id' => 'doc-1',
            'title' => 'Schedule',
            'preview_url' => 'documents/pro-1/media-1/original.pdf',
        ],
    ];

    $resolved = $method->invoke($service, $site, 'site-1');

    // Storage::disk('media')->url(path) returns a full URL based on MEDIA_DISK_URL
    // (or falls back to s3 endpoint). Either way it's longer than the raw path
    // and contains the path as a substring.
    expect($resolved['document']['preview_url'])->toContain('documents/pro-1/media-1/original.pdf');
    expect(strlen($resolved['document']['preview_url']))->toBeGreaterThan(strlen('documents/pro-1/media-1/original.pdf'));
});

it('leaves document as null when no document exists', function () {
    $service = app(SiteCacheService::class);
    $method = (new ReflectionClass($service))->getMethod('resolveImageVariantUrlsInSite');

    $site = [
        'gallery' => [],
        'content_images' => [],
        'gallery_videos' => [],
        'content_videos' => [],
        'document' => null,
    ];

    $resolved = $method->invoke($service, $site, 'site-1');

    expect($resolved['document'])->toBeNull();
});

it('does not break when document key is missing from site array', function () {
    $service = app(SiteCacheService::class);
    $method = (new ReflectionClass($service))->getMethod('resolveImageVariantUrlsInSite');

    $site = [
        'gallery' => [],
        'content_images' => [],
        'gallery_videos' => [],
        'content_videos' => [],
    ];

    $resolved = $method->invoke($service, $site, 'site-1');

    expect($resolved)->toBeArray();
});
