<?php

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Http\FetchBudget;
use App\Services\Platforms\YoutubeThumbnailResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

it('caches hq verdicts with a short recheck TTL, not the 30-day maxres TTL', function () {
    config()->set('partna.refresh.host_limits.youtube_thumbnails.hq_recheck_ttl_seconds', 21600);
    Http::fake(['i.ytimg.com/vi/*/maxresdefault.jpg' => Http::response('', 404)]);

    Cache::spy();

    app(YoutubeThumbnailResolver::class)->bestForMany(['ttl-hq-1']);

    // 21600s × [0.8, 1.2] = [17280, 25920] — well under 30 days (2,592,000s).
    Cache::shouldHaveReceived('put')->withArgs(
        fn ($k, $v, $ttl) => $v === 'hq' && is_int($ttl) && $ttl < 30000,
    )->once();
});

it('caches maxres verdicts with the long CACHE_DAYS TTL', function () {
    config()->set('partna.refresh.host_limits.youtube_thumbnails.hq_recheck_ttl_seconds', 21600);
    Http::fake(['i.ytimg.com/vi/*/maxresdefault.jpg' => Http::response('', 200)]);

    Cache::spy();

    app(YoutubeThumbnailResolver::class)->bestForMany(['ttl-max-1']);

    // 30 × 86400 × [0.8, 1.2] = [2,073,600, 3,110,400].
    Cache::shouldHaveReceived('put')->withArgs(
        fn ($k, $v, $ttl) => $v === 'maxres' && is_int($ttl) && $ttl > 2000000,
    )->once();
});

// ── FetchBudget — Unit 11 W1 (post-review) ────────────────────────────────
//
// pooledHead() bypasses SafeUrlFetcher (see the class docblock — i.ytimg.com
// URLs are hardcoded, no SSRF surface) but must still respect a budget
// opened around the whole connect. On exhaustion it stops firing further
// pool rounds and returns the partial batch — never throws, matching
// bestForMany()'s "never throws, a failed probe is a fallback" contract.

it('degrades un-probed ids to hqdefault instead of throwing when the budget runs out mid-pool', function () {
    // Against code with no FetchBudget wired into pooledHead(), every id
    // gets probed regardless of any open budget — all three i.ytimg.com
    // requests fire, and the assertSentCount(1) below fails (3, not 1).
    config()->set('partna.refresh.host_limits.youtube_thumbnails.pool_concurrency', 1);

    Http::fake(function () {
        usleep(60_000); // burns the 50ms budget below, before round 2 fires

        return Http::response('', 404); // genuinely probed, no maxres
    });

    $out = app(FetchBudget::class)->open(0.05, fn () => app(YoutubeThumbnailResolver::class)
        ->bestForMany(['probed-a', 'skipped-b', 'skipped-c']));

    // Every id still gets a usable (hqdefault) URL — never null, never throws.
    expect($out['probed-a'])->toBe('https://i.ytimg.com/vi/probed-a/hqdefault.jpg')
        ->and($out['skipped-b'])->toBe('https://i.ytimg.com/vi/skipped-b/hqdefault.jpg')
        ->and($out['skipped-c'])->toBe('https://i.ytimg.com/vi/skipped-c/hqdefault.jpg');

    Http::assertSentCount(1); // only round 1 (probed-a) ever fired
});

it('caches a genuinely-probed non-200 verdict but NOT an id the budget skipped entirely', function () {
    // Distinguishes "asked YouTube and got a non-200" (a real fact worth
    // caching) from "budget ran out before we ever asked" (not a fact about
    // the video — caching it would pin an un-probed id to hqdefault for the
    // recheck TTL). Against code using `$responses[$id] ?? null` alone
    // (unable to tell the two apart), EVERY miss id gets cached as 'hq' —
    // including the skipped ones — so the assertion that skipped-b/c have NO
    // cache entry fails.
    config()->set('partna.refresh.host_limits.youtube_thumbnails.pool_concurrency', 1);

    Http::fake(function () {
        usleep(60_000);

        return Http::response('', 404);
    });

    app(FetchBudget::class)->open(0.05, fn () => app(YoutubeThumbnailResolver::class)
        ->bestForMany(['probed-a', 'skipped-b', 'skipped-c']));

    expect(Cache::get(CacheKeyGenerator::youtubeThumbnailVerdict('probed-a')))->toBe('hq')
        ->and(Cache::get(CacheKeyGenerator::youtubeThumbnailVerdict('skipped-b')))->toBeNull()
        ->and(Cache::get(CacheKeyGenerator::youtubeThumbnailVerdict('skipped-c')))->toBeNull();
});

it('logs once (not per skipped id) when the budget runs out mid-pool', function () {
    config()->set('partna.refresh.host_limits.youtube_thumbnails.pool_concurrency', 1);
    Log::spy();

    Http::fake(function () {
        usleep(60_000);

        return Http::response('', 404);
    });

    app(FetchBudget::class)->open(0.05, fn () => app(YoutubeThumbnailResolver::class)
        ->bestForMany(['probed-a', 'skipped-b', 'skipped-c']));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $ctx) => $message === 'youtube_thumbnails.pooled_head.budget_exhausted'
            && $ctx['dropped'] === 2
            && $ctx['remaining_seconds'] <= 0);
});
