<?php

// Nightwatch #499 (2026-09-05): a public profile cache miss ran 92 queries
// for ~1.3s of database time. Three of the repeats were structural, not
// data-driven, and each is pinned here so it cannot creep back:
//
//   1. presentPageIds() ran its whole probe loop TWICE per build — once for
//      pageOrder(), once inside ActionCandidates — because the two held
//      different resolver instances. It is memoised per (site, caps,
//      sections) on the instance, and the builder hands its answer to the
//      candidates.
//   2. The presence probe paid a site.sections read and a site.section_items
//      read PER POOL. They come in as one whereIn apiece now.
//   3. PoolWire asked plan() for every pool's LIBRARY_LIMIT-row library and
//      then assembled with withLibrary false, discarding all nine.
//
// Style precedent for capturing SQL via DB::listen():
// tests/Feature/Content/PoolWireLibraryHydrationTest.php.

use App\Services\Accounts\AccountCapabilities;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolWire;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupContentTables();
    setupSectionsTables();
    setupBlocksTable();
    setupServicesTable();
    setupMediaTables();
    Queue::fake();
});

/** Every statement's SQL (lowercased) issued on the pgsql connection during $run(). */
function coldMissSql(callable $run): array
{
    $sql = [];
    DB::connection('pgsql')->listen(function ($q) use (&$sql): void {
        $sql[] = strtolower((string) $q->sql);
    });

    $run();

    return $sql;
}

function coldMissCount(array $sql, string $needle): int
{
    return count(array_filter($sql, fn (string $s) => str_contains($s, $needle)));
}

it('memoises presentPageIds() on the instance: the second ask for the same build issues no query at all', function () {
    $pro = createTenant('budget-memo');
    $resolver = app(SitepageDataResolverService::class);
    $caps = AccountCapabilities::for($pro);
    $sections = collect();

    $first = $resolver->presentPageIds($pro->site, $caps, $sections);
    expect($first)->toContain('home');

    $again = coldMissSql(fn () => $resolver->presentPageIds($pro->site, $caps, $sections));

    expect($again)->toBe([]);
});

it('does NOT serve a memoised answer to a different sections read — a rebuild after a write is a new question', function () {
    $pro = createTenant('budget-memo-miss');
    $resolver = app(SitepageDataResolverService::class);
    $caps = AccountCapabilities::for($pro);

    $resolver->presentPageIds($pro->site, $caps, collect());
    $second = coldMissSql(fn () => $resolver->presentPageIds($pro->site, $caps, collect()));

    expect($second)->not->toBe([]);
});

it('pre-reads the presence probe\'s sections and curation ONCE for every pool, not once per pool', function () {
    $pro = createTenant('budget-preload');
    // Provision every pool section up front so the probe's read is the pure
    // batched path and never the per-pool find-or-create fallback.
    app(PoolResolver::class)->preloadSections($pro->site, array_keys(PoolRegistry::POOLS));

    $sql = coldMissSql(fn () => app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect()));

    // One whereIn on sections (ensureMany's shape), one on section_items —
    // and no singular `"key" = ?` row lookup, which is the shape the
    // per-pool ensure() emits. Matched on the statement head on purpose:
    // the services probe carries a `from "site"."sections"` SUBSELECT of its
    // own, which is not a sections read and must not count here.
    $sectionsMany = 'select * from "site"."sections" where "site_id" = ? and "key" in (';
    $sectionsOne = 'select * from "site"."sections" where "site_id" = ? and "key" = ?';
    expect(coldMissCount($sql, $sectionsMany))->toBe(1)
        ->and(coldMissCount($sql, $sectionsOne))->toBe(0)
        ->and(coldMissCount($sql, 'from "site"."section_items"'))->toBe(1);
});

it('never runs the per-pool library read on the public wire, which assembles without a library anyway', function () {
    $pro = createTenant('budget-library');

    $sql = coldMissSql(fn () => app(PoolWire::class)
        ->forSite($pro->site, app(SitepageDataResolverService::class)));

    // plan()'s library query is the only statement on content.items ordered
    // by last_seen_at with the LIBRARY_LIMIT bound.
    $library = array_filter($sql, fn (string $s) => str_contains($s, 'from "content"."items"')
        && str_contains($s, 'order by "last_seen_at" desc')
        && str_contains($s, 'limit 500'));

    expect($library)->toBe([]);
});

it('runs the presence probe exactly once across a whole payload build', function () {
    $pro = createTenant('budget-build');
    app(PoolResolver::class)->preloadSections($pro->site, array_keys(PoolRegistry::POOLS));

    $sql = coldMissSql(fn () => app(IndividualProfilePayloadBuilder::class)->build($pro, $pro->site));

    // Two readers of site.section_items per build and no more: PoolWire's
    // shared curation pre-read, and the presence probe's. A second probe
    // pass — the pre-fix shape — would make this three.
    expect(coldMissCount($sql, 'from "site"."section_items"'))->toBe(2);
});
