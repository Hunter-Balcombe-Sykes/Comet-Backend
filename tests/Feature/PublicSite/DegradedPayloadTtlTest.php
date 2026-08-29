<?php

// #CCH-5. safeQuery() answers a presence probe from a QueryException by
// returning the caller's default — false for every presence probe, i.e. "this
// page section does not exist". rememberLocked() then cached that answer under
// the full payload TTL AND a x10 stale twin, so one second of database trouble
// hid a live section of a professional's public page for the best part of ten
// minutes, with only a Log::warning (which Nightwatch does not alert on) to
// show for it.
//
// Probes are faulted the way PresenceProbeLoggingTest does it — by omitting
// the table the query needs, which is the "missing table in a partial test
// env" case safeQuery's own docblock describes.

use App\Services\Accounts\AccountCapabilities;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Shared helpers, not local DDL: setupSitesTable() already carries
    // architecture_id, and design_kits has its own helper. Building either by
    // hand here would put a second, drifting copy of a canonical tenant table
    // in the suite — which NoLocalCanonicalTableDdlTest exists to stop, because
    // SchemaDriftGuardTest cannot see a locally-built table and a
    // prod-violating seed would pass green.
    setupDesignKitsTable();

    Config::set('partna.throttle.enabled', false);
    Cache::flush();
});

/** The primary payload key plus the x10 stale twin rememberLocked writes beside it. */
function payloadCacheKeys(object $pro): array
{
    $key = app(IndividualProfilePayloadBuilder::class)
        ->cacheKey(strtolower($pro->handle), $pro->site, $pro);

    return [$key, $key.':stale'];
}

it('does not report degradation when every probe answers from the database', function () {
    $pro = createTenant('degrade-clean');
    setupContentTables(); // pool presence probes (P4) need the pool tables
    setupBlocksTable();
    setupMediaTables();
    setupServiceCategoriesTable();
    setupServicesTable();

    $resolver = app(SitepageDataResolverService::class);
    $resolver->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($resolver->hasDegraded())->toBeFalse();
});

it('reports degradation when a probe answers from a fault', function () {
    $pro = createTenant('degrade-faulted');
    // setupSectionsTables() (not the full setupContentTables()) — it provisions
    // content.items + the pool tables the P4 probes need, but deliberately
    // leaves content.sources/content.source_items absent, so the services
    // probe's join (SitepageDataResolverService::presentPageIds()) genuinely
    // faults and the probe answers "no services page" from the exception
    // rather than the DB.
    setupSectionsTables();
    setupBlocksTable();
    setupMediaTables();
    $resolver = app(SitepageDataResolverService::class);
    $resolver->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($resolver->hasDegraded())->toBeTrue();
});

it('expires a degraded payload — and its stale twin — within the short TTL', function () {
    // The defect in one assertion. Both keys must be gone well before the
    // normal 60s primary / 600s stale window, or the "no services page" answer
    // a one-second blip produced keeps hiding the section long after the
    // database recovered.
    config(['partna.public_profile.cache_ttl_seconds' => 60]);
    config(['partna.public_profile.degraded_cache_ttl_seconds' => 10]);

    $pro = createTenant('degrade-ttl');
    // setupSectionsTables() (not setupContentTables()) — content.sources/
    // content.source_items stay absent, so the services probe faults and
    // this build is degraded.
    setupSectionsTables();
    setupBlocksTable();
    setupMediaTables();

    $this->getJson("/api/public/profiles/{$pro->handle}")->assertOk();

    [$key, $staleKey] = payloadCacheKeys($pro);
    expect(Cache::get($key))->not->toBeNull();

    // Past the degraded TTL (+ its jitter headroom), inside the normal one.
    $this->travel(20)->seconds();

    expect(Cache::get($key))->toBeNull()
        ->and(Cache::get($staleKey))->toBeNull();
});

it('leaves a clean payload on the full TTL', function () {
    // The control. Without it, a fix that simply shortened every payload would
    // pass the test above and quietly cost the cache its whole point.
    config(['partna.public_profile.cache_ttl_seconds' => 60]);
    config(['partna.public_profile.degraded_cache_ttl_seconds' => 10]);

    $pro = createTenant('degrade-control');
    setupContentTables(); // pool presence probes (P4) need the pool tables
    setupBlocksTable();
    setupMediaTables();
    setupServiceCategoriesTable();
    setupServicesTable();
    // CCH-11: the popularity readers now mark the build degraded on a genuine
    // QueryException instead of caching an empty ranking as if it were valid.
    // This is the CLEAN-build control, so the analytics table has to exist —
    // without it the read faults for real and the short TTL is correct.
    setupContentPopularityScoresTable();

    $this->getJson("/api/public/profiles/{$pro->handle}")->assertOk();

    [$key] = payloadCacheKeys($pro);
    $this->travel(20)->seconds();

    expect(Cache::get($key))->not->toBeNull();
});

it('keeps the flag scoped to one build rather than leaking across resolvers', function () {
    // The flag is only safe to read after a build because this service is
    // transient. If it were shared, one site's blip would shorten every other
    // site's cache entry for the rest of the process.
    $faulted = createTenant('degrade-scope-a');
    // setupSectionsTables() only — content.sources/content.source_items stay
    // absent, so the services probe genuinely faults for THIS build.
    setupSectionsTables();
    setupBlocksTable();
    setupMediaTables();

    $first = app(SitepageDataResolverService::class);
    $first->presentPageIds($faulted->site, AccountCapabilities::for($faulted), collect());
    expect($first->hasDegraded())->toBeTrue();

    // NOW provision the rest of content.* (content.sources/source_items) —
    // SQLite's :memory: tables persist process-wide, so this only takes
    // effect for builds run AFTER this point, i.e. $clean below.
    setupContentTables(); // pool presence probes (P4) need the pool tables

    $clean = createTenant('degrade-scope-b');
    $second = app(SitepageDataResolverService::class);
    $second->presentPageIds($clean->site, AccountCapabilities::for($clean), collect());

    expect($second)->not->toBe($first)
        ->and($second->hasDegraded())->toBeFalse();
});

it('degrades the design_kit read to the presets layer instead of losing it entirely (#W1-LIFE-4)', function () {
    // loadDesignKit() merges the DB-stored manual layer OVER the pure
    // ProfileDesignPresets layer (profile-derived, no DB read at all) — a
    // fault must drop ONLY the DB layer, never both. sector=restaurant gives
    // a non-empty preset (food_drink bucket, color_accent #e0491f) so
    // "presets survived" is distinguishable from "empty because nothing is
    // authored at all".
    $pro = createTenant('degrade-designkit', ['sector' => 'restaurant']);
    // Undo the shared beforeEach's setupDesignKitsTable() — the design_kits
    // read must genuinely fault (missing table), same idiom as every other
    // probe fault in this file.
    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS site.design_kits');
    setupContentTables(); // pool presence probes (P4) need the pool tables
    setupBlocksTable();
    setupMediaTables();
    setupServiceCategoriesTable();
    setupServicesTable();
    setupContentPopularityScoresTable();

    $builder = app(IndividualProfilePayloadBuilder::class);
    $payload = $builder->build($pro, $pro->site);

    expect($payload)->toHaveKeys(['profile', 'designKit', 'architectureId', 'publicConfig']);
    expect($payload['designKit'])->not->toBe([])
        ->and($payload['designKit']['colors']['accent'] ?? null)->toBe('#e0491f');

    expect($builder->lastBuildDegraded())->toBeTrue();
});

it('degrades the document + design-singleton reads without losing pools or throwing (#W1-LIFE-5)', function () {
    // build() calls getDocument()/getDesignSingletons() unconditionally — no
    // section-block gate — so a genuine SiteMedia read fault there must
    // degrade those fields to their EXISTING empty shape (draft/null) rather
    // than 500ing the whole payload, and the pools engine (a separate,
    // content.*-only read path untouched by this diff) must stay intact.
    config(['partna.public_profile.cache_ttl_seconds' => 60]);
    config(['partna.public_profile.degraded_cache_ttl_seconds' => 10]);

    $pro = createTenant('degrade-content');
    setupContentTables(); // pool presence probes (P4) need the pool tables; pools stay intact
    setupBlocksTable(); // loadSections()/live-link-block probe need this to exist unconditionally
    // Deliberately no setupMediaTables() — getDocument()/getDesignSingletons()
    // (both invoked unconditionally by build(), no section-block gate)
    // genuinely fault on the missing site.site_media table.
    setupServiceCategoriesTable();
    setupServicesTable();
    setupContentPopularityScoresTable();

    $response = $this->getJson("/api/public/profiles/{$pro->handle}")->assertOk();
    $profile = $response->json('data.profile');

    expect($profile)->toHaveKey('pools')
        ->and($profile['pools'])->toBeArray()
        ->and($profile['document'])->toBeNull()
        ->and($profile['brand'])->toBe(['logoFull' => null, 'logoSquare' => null])
        ->and($profile['headshot'])->toBeNull();

    [$key, $staleKey] = payloadCacheKeys($pro);
    expect(Cache::get($key))->not->toBeNull();

    // Past the degraded TTL (+ its jitter headroom), inside the normal one.
    $this->travel(20)->seconds();

    expect(Cache::get($key))->toBeNull()
        ->and(Cache::get($staleKey))->toBeNull();
});
