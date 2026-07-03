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

it('chunks the ytimg maxres probes to the configured pool concurrency', function () {
    config()->set('partna.refresh.host_limits.youtube_thumbnails.pool_concurrency', 2);
    Http::fake(['i.ytimg.com/vi/*/maxresdefault.jpg' => Http::response('', 200)]);

    // The private chunked-pool helper must exist — proves the cap is wired, not that
    // the functional result happens to match on the current unbounded code.
    expect(method_exists(YoutubeThumbnailResolver::class, 'pooledHead'))->toBeTrue();

    $ids = ['aaa', 'bbb', 'ccc', 'ddd', 'eee']; // 5 misses, cap 2 → 3 chunks
    $result = app(YoutubeThumbnailResolver::class)->bestForMany($ids);

    expect($result)->toHaveCount(5);
    foreach ($ids as $id) {
        expect($result[$id])->toBe("https://i.ytimg.com/vi/{$id}/maxresdefault.jpg");
    }
    Http::assertSentCount(5); // every id probed across chunks
});

it('caps fetchMany concurrency via a chunked pool helper', function () {
    config()->set('partna.refresh.host_limits.fetch_many.pool_concurrency', 2);
    // Literal public IP bypasses assertSafe()'s DNS resolution → hermetic. 8.8.8.8 is
    // a public address (passes NO_PRIV_RANGE|NO_RES_RANGE); Http::fake stubs the GET.
    Http::fake(['8.8.8.8/*' => Http::response('<html>ok</html>', 200)]);

    expect(method_exists(SafeUrlFetcher::class, 'pooledGet'))->toBeTrue();

    $urls = [
        'https://8.8.8.8/1', 'https://8.8.8.8/2', 'https://8.8.8.8/3',
        'https://8.8.8.8/4', 'https://8.8.8.8/5',
    ];
    $out = app(SafeUrlFetcher::class)->fetchMany($urls);

    // All 5 resolved despite a cap of 2 (chunked, not dropped).
    expect(array_keys($out))->toEqualCanonicalizing($urls);
    foreach ($urls as $u) {
        expect($out[$u]['status'])->toBe(200);
    }
});

it('skips billed re-resolution of photos that already carry a url, and pools the rest', function () {
    config()->set('services.google_maps.server_api_key', 'test-key');
    config()->set('partna.refresh.host_limits.google_places.pool_concurrency', 2);

    Http::fake([
        'places.googleapis.com/*/media*' => Http::response(['photoUri' => 'https://lh3.example/resolved.jpg'], 200),
    ]);

    $svc = app(GoogleBusinessService::class);
    $ref = new ReflectionMethod(GoogleBusinessService::class, 'resolvePhotoUrls');
    $ref->setAccessible(true);

    $photos = [
        ['ref' => 'places/x/photos/a', 'url' => 'https://cached.example/a.jpg'], // already resolved
        ['ref' => 'places/x/photos/b'],                                          // needs resolving
        ['ref' => 'places/x/photos/c'],                                          // needs resolving
    ];

    $out = $ref->invoke($svc, 'test-key', 'ChIJx', $photos);

    // Already-resolved photo untouched; the other two resolved.
    expect($out[0]['url'])->toBe('https://cached.example/a.jpg')
        ->and($out[1]['url'])->toBe('https://lh3.example/resolved.jpg')
        ->and($out[2]['url'])->toBe('https://lh3.example/resolved.jpg');

    // Only the 2 unresolved photos were billed (the cached one sent nothing).
    Http::assertSentCount(2);
    expect(method_exists(GoogleBusinessService::class, 'resolvePhotoUrls'))->toBeTrue();
});

it('reuses a prior photo url when the ref is unchanged (no re-bill), resolves changed refs', function () {
    config()->set('services.google_maps.server_api_key', 'test-key');

    // The Place-Details response returns two photo refs: one seen before, one new.
    // NOTE ordering: the `/media` pattern is listed FIRST — Http::fake matches the
    // first pattern, and the details glob (`v1/places/*`) would otherwise also match
    // the media URL (`v1/places/ChIJx/photos/NEW/media`). Media-first disambiguates.
    Http::fake([
        'places.googleapis.com/*/media*' => Http::response(['photoUri' => 'https://lh3.example/new.jpg'], 200),
        'places.googleapis.com/v1/places/*' => Http::response([
            'id' => 'ChIJx',
            'photos' => [
                ['name' => 'places/ChIJx/photos/STABLE', 'widthPx' => 100, 'heightPx' => 100],
                ['name' => 'places/ChIJx/photos/NEW', 'widthPx' => 100, 'heightPx' => 100],
            ],
        ], 200),
    ]);

    $svc = app(GoogleBusinessService::class);
    $prior = [
        ['ref' => 'places/ChIJx/photos/STABLE', 'url' => 'https://lh3.example/stable.jpg'],
    ];

    $details = $svc->fetchPlaceDetails('ChIJx', $prior);

    $byRef = collect($details['photos'])->keyBy('ref');
    expect($byRef['places/ChIJx/photos/STABLE']['url'])->toBe('https://lh3.example/stable.jpg') // reused, not re-billed
        ->and($byRef['places/ChIJx/photos/NEW']['url'])->toBe('https://lh3.example/new.jpg');    // freshly resolved

    // Only the NEW ref hit the billed media endpoint.
    Http::assertSentCount(2); // 1 details + 1 media (STABLE skipped)
});
