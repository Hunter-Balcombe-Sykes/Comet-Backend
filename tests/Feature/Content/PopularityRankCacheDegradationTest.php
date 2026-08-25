<?php

// CCH-11 (call-site half). ContentPopularityReader::forSite() fails open to []
// on a DB fault — indistinguishable from a genuine "nothing scored yet" site —
// and PoolResolver::popularityRanks() used to fold that straight into its own
// 900s CacheLockService::rememberLocked() with no degraded-awareness. One DB
// blip would then poison the popularity-rank cache for 15 minutes.
//
// The fix wires the reader's lastReadFailed() signal into popularityRanks():
// on a faulted read, CacheLockService::shortenDegraded() immediately
// overwrites what rememberLocked() just wrote (primary + stale) with the
// short partna.public_profile.degraded_cache_ttl_seconds TTL (10s default,
// same seam #LIFE-6 already uses for the pool lane) instead of leaving the
// empty ranking cached for the full 900s.
//
// Fault injection: leave analytics.content_popularity_scores unprovisioned so
// forSite() raises a real QueryException — no mocked query builder. The
// genuine-empty control provisions the table with zero rows.

use App\Models\Core\Site\Site;
use App\Services\Cache\CacheKeyGenerator;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    setupMediaTables();
});

/** One pinned shop item — enough for itemPayloads() to reach the ids!==[] branch that calls popularityRanks(). */
function popularityRankFixture(): array
{
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    poolPin($siteId, 'shop', shopProduct($pro->id, $store, 'Hat'));

    return [$pro, $siteId];
}

it('does not cache a degraded (fault-derived) popularity ranking for the full 900s TTL', function () {
    // analytics.content_popularity_scores deliberately NOT provisioned.
    [, $siteId] = popularityRankFixture();
    $site = Site::query()->findOrFail($siteId);

    app(PoolResolver::class)->resolve($site, 'shop');

    $key = CacheKeyGenerator::sitePopularityRanks($siteId);
    expect(Cache::has($key))->toBeTrue();

    // Degraded TTL is jittered ±20% around 10s (max 12s); the genuine 900s TTL
    // jitters to a minimum of 720s. 20s clears the degraded ceiling while
    // staying nowhere near the genuine floor, so this only passes if the
    // fault write actually got shortened.
    $this->travel(20)->seconds();

    expect(Cache::has($key))->toBeFalse();
});

it('still caches a genuine zero-row popularity ranking at the normal 900s TTL', function () {
    setupContentPopularityScoresTable();
    [, $siteId] = popularityRankFixture();
    $site = Site::query()->findOrFail($siteId);

    app(PoolResolver::class)->resolve($site, 'shop');

    $key = CacheKeyGenerator::sitePopularityRanks($siteId);
    expect(Cache::has($key))->toBeTrue();

    // Past the degraded ceiling (12s) but nowhere near the 900s floor (720s):
    // a genuine empty read must survive here, or caching is effectively
    // disabled and this test's positive case would not distinguish it from
    // the fault case above.
    $this->travel(20)->seconds();

    expect(Cache::has($key))->toBeTrue();
});
