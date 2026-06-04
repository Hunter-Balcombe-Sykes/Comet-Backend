<?php

use App\Services\Platforms\YoutubeThumbnailResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    // Verdicts are cached for 30 days — flush so each test starts cold and a
    // cache-hit assertion in one test can't be satisfied by another's write.
    Cache::flush();
});

it('uses maxresdefault when the maxres probe returns 200', function () {
    Http::fake([
        'i.ytimg.com/vi/*/maxresdefault.jpg' => Http::response('', 200),
    ]);

    $map = app(YoutubeThumbnailResolver::class)->bestForMany(['abc123']);

    expect($map['abc123'])->toBe('https://i.ytimg.com/vi/abc123/maxresdefault.jpg');
});

it('falls back to hqdefault when the maxres probe 404s', function () {
    Http::fake([
        'i.ytimg.com/vi/*/maxresdefault.jpg' => Http::response('', 404),
    ]);

    $map = app(YoutubeThumbnailResolver::class)->bestForMany(['old456']);

    expect($map['old456'])->toBe('https://i.ytimg.com/vi/old456/hqdefault.jpg');
});

it('caches the verdict so a second resolve of the same id makes no new HTTP call', function () {
    Http::fake([
        'i.ytimg.com/vi/*/maxresdefault.jpg' => Http::response('', 200),
    ]);

    $resolver = app(YoutubeThumbnailResolver::class);

    $first = $resolver->bestForMany(['cached789']);
    $second = $resolver->bestForMany(['cached789']);

    expect($first['cached789'])->toBe('https://i.ytimg.com/vi/cached789/maxresdefault.jpg')
        ->and($second['cached789'])->toBe($first['cached789']);

    // Only the first resolve probed; the second was served entirely from cache.
    Http::assertSentCount(1);
});

it('maps each id correctly in a mixed batch of maxres-present and maxres-absent videos', function () {
    Http::fake([
        'i.ytimg.com/vi/hasmax/maxresdefault.jpg' => Http::response('', 200),
        'i.ytimg.com/vi/nomax/maxresdefault.jpg' => Http::response('', 404),
    ]);

    $map = app(YoutubeThumbnailResolver::class)->bestForMany(['hasmax', 'nomax']);

    expect($map['hasmax'])->toBe('https://i.ytimg.com/vi/hasmax/maxresdefault.jpg')
        ->and($map['nomax'])->toBe('https://i.ytimg.com/vi/nomax/hqdefault.jpg');
});

it('returns an empty array for empty input without making any HTTP call', function () {
    Http::fake();

    $map = app(YoutubeThumbnailResolver::class)->bestForMany([]);

    expect($map)->toBe([]);
    Http::assertNothingSent();
});

it('returns an entry for every requested id and dedupes repeated ids', function () {
    Http::fake([
        'i.ytimg.com/vi/*/maxresdefault.jpg' => Http::response('', 200),
    ]);

    $map = app(YoutubeThumbnailResolver::class)->bestForMany(['dup', 'dup', 'other']);

    expect($map)->toHaveKeys(['dup', 'other'])
        ->and($map['dup'])->toBe('https://i.ytimg.com/vi/dup/maxresdefault.jpg');

    // Deduped: two distinct ids ⇒ exactly two probes, not three.
    Http::assertSentCount(2);
});
