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

// #CCH-1 — these four analytics keys used to be built inline at each call site.
// The generator's output must stay byte-identical to the old literals (representative
// inputs below) — a changed format silently invalidates in-flight dedup/debounce state.

it('builds the ingest-debounce key byte-identical to the old inline literal', function () {
    expect(CacheKeyGenerator::analyticsIngestDebounce('user-1'))
        ->toBe('analytics:ingest-debounce:user-1');
});

it('builds the click dedup key byte-identical to the old inline literal', function () {
    expect(CacheKeyGenerator::analyticsClickDedup('block-9', 'visitor-abc'))
        ->toBe('analytics:dedup:click:block-9:visitor-abc');
});

it('builds the section dedup key byte-identical to the old inline literal', function () {
    expect(CacheKeyGenerator::analyticsSectionDedup('site-1', 'about', 'visitor-abc'))
        ->toBe('analytics:dedup:section:site-1:about:visitor-abc');
});

it('builds the item dedup key byte-identical to the old inline literal', function () {
    expect(CacheKeyGenerator::analyticsItemDedup('site-1', 'shop_product', 'prod-42', 'visitor-abc'))
        ->toBe('analytics:dedup:item:site-1:shop_product:prod-42:visitor-abc');
});
