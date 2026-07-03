<?php

use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\CacheKeyGenerator;

it('builds the platform connection lock key with and without a suffix', function () {
    expect(CacheKeyGenerator::platformConnectionLock('youtube', 'user-1'))
        ->toBe('platforms:youtube:lock:user-1');
    expect(CacheKeyGenerator::platformConnectionLock('shop', 'user-1', 'brand-9'))
        ->toBe('platforms:shop:lock:user-1:brand-9');
});

it('enumerates cache-bust variants in lockstep with the controller filter inputs', function () {
    // Lock the accepted-input allowlists. A deliberate change to either must update this test.
    expect(SiteMedia::GALLERY_POOLS)->toEqual(['gallery', 'content']);
    expect(SiteMedia::MEDIA_TYPE_FILTERS)->toEqual(['image', 'video', 'all']);

    // Enumerator must cover [null (= all pools) + every gallery pool] × every media-type filter.
    $expected = [];
    foreach (array_merge([null], SiteMedia::GALLERY_POOLS) as $pool) {
        foreach (SiteMedia::MEDIA_TYPE_FILTERS as $type) {
            $expected[] = [$pool, $type];
        }
    }

    expect(CacheKeyGenerator::siteImagesViewVariants())
        ->toEqual($expected)
        ->toHaveCount(9);
});
