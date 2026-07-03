<?php

// tests/Feature/Platforms/RefreshHostLimitsTest.php
//
// SCALE-3 per-host burst-control + caching tests. All imports for the whole file
// live in this block; Tasks 3–6 append only it() blocks.

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\YoutubeThumbnailResolver;
use Illuminate\Support\Facades\Http;

it('caches a successful iTunes lookup so a repeat resolution issues no second fetch', function () {
    config()->set('partna.refresh.host_limits.itunes.cache_ttl_seconds', 3600);

    // tryFetch MUST be called exactly once for the same path; the 2nd resolution is
    // served from cache. once() is the assertion — a broken cache calls it twice.
    $this->mock(SafeUrlFetcher::class, function ($m) {
        $m->shouldReceive('tryFetch')->once()->andReturn([
            'status' => 200,
            'body' => json_encode(['results' => [['wrapperType' => 'artist', 'artistId' => 42]]]),
        ]);
    });

    $apple = app(AppleSearch::class);
    $resolve = new ReflectionMethod(AppleSearch::class, 'resolveArtistId');
    $resolve->setAccessible(true);

    expect($resolve->invoke($apple, 'Same Artist'))->toBe(42); // network hit → cached
    expect($resolve->invoke($apple, 'Same Artist'))->toBe(42); // served from cache (once() holds)
});

it('does not cache a failed iTunes lookup (retried on the next call)', function () {
    config()->set('partna.refresh.host_limits.itunes.cache_ttl_seconds', 3600);

    $this->mock(SafeUrlFetcher::class, function ($m) {
        $m->shouldReceive('tryFetch')->twice()->andReturn(
            ['status' => 429, 'body' => ''],                                                                        // 1st: fail (must NOT cache)
            ['status' => 200, 'body' => json_encode(['results' => [['wrapperType' => 'artist', 'artistId' => 7]]])], // 2nd: succeeds
        );
    });

    $apple = app(AppleSearch::class);
    $resolve = new ReflectionMethod(AppleSearch::class, 'resolveArtistId');
    $resolve->setAccessible(true);

    expect($resolve->invoke($apple, 'Retry Artist'))->toBeNull(); // 429 → null, not cached
    expect($resolve->invoke($apple, 'Retry Artist'))->toBe(7);    // re-fetched, succeeds
});
