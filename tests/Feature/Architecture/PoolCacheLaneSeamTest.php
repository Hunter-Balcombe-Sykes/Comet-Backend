<?php

// #PGR-6: these five files used to hand-roll the three cache lanes
// (BuildState::bump + site.sites.updated_at + CloudflareCachePurgeJob) and
// dropping one silently served stale content for the TTL. They now all
// route through App\Site\Documents\SiteCacheLanes::bust(), the single seam
// that fires all three. A cheap grep-shaped guard, not the 430-line
// registry CollectionWriteInvalidationGuardTest runs — this only needs to
// catch a REGRESSION back to hand-rolling on a KNOWN, closed list of files,
// not discover new writers.
//
// What this does NOT catch: a brand-new controller/command that writes a
// pool and hand-rolls its own lanes instead of adopting SiteCacheLanes —
// it is not in FILES below, so the guard is silent about it. It also does
// not catch the three lane-1-only paths the owner ruled OUT of #PGR-6's
// scope on 2026-08-17 — ManualOverrideController::bumpSites,
// ItemMerger::bumpSites and SectionItemController::upsert()/destroy() —
// those are a deliberately excluded follow-up (P2), not a gap in this test.

const POOL_CACHE_LANE_FILES = [
    'app/Http/Controllers/Api/Content/PoolController.php',
    'app/Http/Controllers/Api/Content/PoolItemCreateController.php',
    'app/Http/Controllers/Api/Content/ItemController.php',
    'app/Http/Controllers/Api/Content/ItemLinkController.php',
    'app/Console/Commands/ProvisionShopPinsCommand.php',
];

it('resolves to exactly five known pool-cache-lane files, all present on disk', function () {
    // Non-vacuity: prove the list isn't empty and every path actually
    // exists BEFORE the negated assertions below run — a bad path or an
    // empty list would otherwise let those pass by finding nothing to search.
    expect(POOL_CACHE_LANE_FILES)->toHaveCount(5);

    foreach (POOL_CACHE_LANE_FILES as $relative) {
        expect(base_path($relative))->toBeFile();
    }
});

it('never hand-rolls the cache lanes directly in the pool-cache-lane files', function () {
    foreach (POOL_CACHE_LANE_FILES as $relative) {
        $contents = (string) file_get_contents(base_path($relative));

        $this->assertStringNotContainsString(
            'CloudflareCachePurgeJob::dispatch(',
            $contents,
            "{$relative} dispatches the edge purge directly instead of through SiteCacheLanes::bust().",
        );
        $this->assertStringNotContainsString(
            'BuildState::bump(',
            $contents,
            "{$relative} bumps build state directly instead of through SiteCacheLanes::bust().",
        );
        // The positive half: proves the negated checks above aren't just
        // passing because the file lost the whole invalidation call.
        $this->assertStringContainsString(
            'SiteCacheLanes::bust(',
            $contents,
            "{$relative} no longer routes through the shared cache-lane seam at all.",
        );
    }
});
