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
// #PGR-36 (2026-08-18) closed the follow-up #PGR-6 left open: the three
// lane-1-only paths noted above — ManualOverrideController::bumpSites,
// ItemMerger::bumpSites and SectionItemController::upsert()/destroy() — plus
// the builder lane (SectionController, SectionGroupController, PageController,
// owner-included for uniformity) now all route through SiteCacheLanes::bust()
// too. ItemMerger has NO production caller (no `new ItemMerger`, no
// `ItemMerger::class`, no container resolution anywhere in app/) — so this
// static guard is its ONLY coverage. Do not delete its entry as untested dead
// weight; there is no route to exercise it behaviourally.
//
// What this does NOT catch: a brand-new controller/command that writes a
// pool and hand-rolls its own lanes instead of adopting SiteCacheLanes —
// it is not in FILES below, so the guard is silent about it.

const POOL_CACHE_LANE_FILES = [
    'app/Http/Controllers/Api/Content/PoolController.php',
    'app/Http/Controllers/Api/Content/PoolItemCreateController.php',
    'app/Http/Controllers/Api/Content/ItemController.php',
    'app/Http/Controllers/Api/Content/ItemLinkController.php',
    'app/Console/Commands/ProvisionShopPinsCommand.php',
    'app/Http/Controllers/Api/Content/ManualOverrideController.php',
    'app/Services/Content/ItemMerger.php',
    'app/Http/Controllers/Api/Site/SectionItemController.php',
    'app/Http/Controllers/Api/Site/SectionController.php',
    'app/Http/Controllers/Api/Site/SectionGroupController.php',
    'app/Http/Controllers/Api/Site/PageController.php',
];

it('resolves to exactly eleven known pool-cache-lane files, all present on disk', function () {
    // Non-vacuity: prove the list isn't empty and every path actually
    // exists BEFORE the negated assertions below run — a bad path or an
    // empty list would otherwise let those pass by finding nothing to search.
    expect(POOL_CACHE_LANE_FILES)->toHaveCount(11);

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
