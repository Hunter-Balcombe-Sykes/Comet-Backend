<?php

// CCH-11 (call-site half). IndividualProfilePayloadBuilder::pageOrder() and
// ::buildActions() fold ContentPopularityReader::pageRanksFromActions() /
// ::actionRanksForSite() straight into the payload IndividualProfileController
// caches, with no try/catch and — until this fix — no read of
// lastReadFailed() either. A fault-derived [] (indistinguishable from a
// genuine "nothing ranked yet" site) would ride the full payload cache TTL.
//
// The fix marks the SAME SitepageDataResolverService degraded (the
// markDegraded()/hasDegraded() seam #LIFE-6 already wired for PoolWire), so
// the controller's existing shortenDegraded() rewrite (asserted directly in
// PoolDegradedBuildTest.php) picks it up for free. This suite proves the two
// NEW call sites actually flip that flag — one assertion per call site, both
// the fault case and the genuine-empty control, isolated from each other via
// smart_page_order / actions.mode so a failure in one call site's wiring
// can't hide behind the other's.
//
// Fixture mirrors PoolDegradedBuildTest.php's beforeEach: a full, otherwise
// non-degraded build, so any true from lastBuildDegraded() here can only come
// from the ContentPopularityReader fault this suite deliberately injects
// (absence of analytics.content_popularity_scores), not an unrelated schema
// gap.

use App\Models\Core\Site\Site;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupMediaTables();
    setupIngestTables();
    setupContentTables();
    setupDesignKitsTable();

    try {
        DB::connection('pgsql')->statement("ALTER TABLE site.sites ADD COLUMN architecture_id TEXT NOT NULL DEFAULT 'staple'");
    } catch (Throwable) {
        // Already added by an earlier test in this process.
    }
});

function popularityDegradedTenant(array $settings): array
{
    $pro = createTenant('poprank-'.Str::lower(Str::random(6)));
    $site = Site::where('user_id', $pro->id)->first();
    DB::connection('pgsql')->table('site.sites')->where('id', $site->id)->update(['settings' => json_encode($settings)]);

    return [$pro, $site->fresh()];
}

// ── pageOrder() — smart_page_order on, actions left at their default
//    ('newest'), so buildActions() never touches the popularity reader and
//    any degraded flag can only have come from pageOrder(). ────────────────

it('pageOrder(): marks the build degraded when pageRanksFromActions() answers from a DB fault', function () {
    // analytics.content_popularity_scores deliberately NOT provisioned.
    [$pro, $site] = popularityDegradedTenant(['smart_page_order' => true]);

    $builder = app(IndividualProfilePayloadBuilder::class);
    $builder->build($pro, $site);

    expect($builder->lastBuildDegraded())->toBeTrue();
});

it('pageOrder(): does NOT mark the build degraded on a genuine zero-row popularity read', function () {
    setupContentPopularityScoresTable();
    [$pro, $site] = popularityDegradedTenant(['smart_page_order' => true]);

    $builder = app(IndividualProfilePayloadBuilder::class);
    $builder->build($pro, $site);

    // Non-vacuity guard for the case above: if this ever reads true, the flag
    // is stuck on for every build and the fault-case assertion proves nothing.
    expect($builder->lastBuildDegraded())->toBeFalse();
});

// ── buildActions() — smart_page_order OFF (manual page order takes a
//    different branch that never calls the popularity reader), actions.mode
//    'smart' so buildActions() is the ONLY caller reaching the reader. ─────

it('buildActions(): marks the build degraded when actionRanksForSite() answers from a DB fault', function () {
    // analytics.content_popularity_scores deliberately NOT provisioned.
    [$pro, $site] = popularityDegradedTenant([
        'smart_page_order' => false,
        'actions' => ['mode' => 'smart'],
    ]);

    $builder = app(IndividualProfilePayloadBuilder::class);
    $builder->build($pro, $site);

    expect($builder->lastBuildDegraded())->toBeTrue();
});

it('buildActions(): does NOT mark the build degraded on a genuine zero-row popularity read', function () {
    setupContentPopularityScoresTable();
    [$pro, $site] = popularityDegradedTenant([
        'smart_page_order' => false,
        'actions' => ['mode' => 'smart'],
    ]);

    $builder = app(IndividualProfilePayloadBuilder::class);
    $builder->build($pro, $site);

    expect($builder->lastBuildDegraded())->toBeFalse();
});

it('newest-mode actions never call the popularity reader, so a DB fault there does not degrade the build', function () {
    // Isolation control for the buildActions() cases above: with mode set to
    // 'newest' (the default is smart since 2026-09-02, so it is explicit now)
    // AND smart_page_order off, neither call site reaches the reader at all —
    // proves the fault-case assertions above are actually attributable to
    // the popularity read, not some ambient effect of the missing table.
    [$pro, $site] = popularityDegradedTenant(['smart_page_order' => false, 'actions' => ['mode' => 'newest']]);

    $builder = app(IndividualProfilePayloadBuilder::class);
    $builder->build($pro, $site);

    expect($builder->lastBuildDegraded())->toBeFalse();
});
