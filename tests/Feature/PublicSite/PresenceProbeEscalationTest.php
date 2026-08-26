<?php

// LIFE-1: safeQuery()'s catch caught QueryException and logged a single
// Log::warning breadcrumb — Nightwatch never alerts on Log::warning (see
// CLAUDE.md), so a genuinely broken presence probe silently dropped whole
// page sections from the public sitepage payload forever, with zero paging
// signal. This suite proves BOTH halves of the fix on every case that
// matters: the page still degrades gracefully (never a 500, the fault-hit
// section just isn't present) AND a SUSTAINED run of faults escalates to a
// real Nightwatch issue via the shared EscalatesRepeatedFaults trait, while a
// single blip stays a quiet breadcrumb. Never asserted as "no exception
// escaped" alone — that's what the pre-fix behaviour already satisfied.
//
// Fault injection mirrors PresenceProbeLoggingTest.php: create a tenant, then
// deliberately omit the setup*Table() call the probe's query needs so a real
// QueryException fires — no mocked query builder.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

// All 8 call sites' buckets (platform_complete_* collapses to ONE bucket —
// see the safeQuery docblock) — cleared so a fault count from an earlier
// test/case never leaks into this file's assertions (matches
// FeatureAvailabilityReadSideTest.php's precaution on the sibling call site).
beforeEach(function () {
    foreach ([
        'active_integration_connections',
        'platform_complete',
        'google_business_connection_display_settings',
        'fetched_menu_exists',
        'active_services_exists',
        'live_link_block_exists',
        'ready_gallery_media_exists',
        'curated_gallery_resolve',
    ] as $label) {
        RateLimiter::clear("analytics:fault:sitepage_probe_{$label}");
    }
});

function lifeProbeResolver(): SitepageDataResolverService
{
    return app(SitepageDataResolverService::class);
}

// pinPoolPresence() lives in tests/Pest.php — shared with
// PresenceProbeLoggingTest.php, which needs the exact same trick.

// ── Case 1 — single blip stays quiet AND degrades ───────────────────────

it('keeps a single probe fault a quiet breadcrumb and still degrades the page gracefully', function () {
    $pro = createTenant('probe-esc-single');
    // setupSectionsTables() (not setupContentTables()) — provisions the pool
    // tables the P4 probes need, but leaves content.sources/content.source_items
    // absent, so the services probe (content.items x source_items x sources)
    // genuinely faults.
    setupSectionsTables();
    setupBlocksTable();
    setupMediaTables();

    Exceptions::fake();

    $present = lifeProbeResolver()->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($present)->not->toContain('services');
    Exceptions::assertNothingReported();
});

// ── Case 2 — a sustained run reports exactly once ───────────────────────

it('escalates to Nightwatch exactly once when one probe sustains faults across the threshold, and does not re-fire within the window', function () {
    $pro = createTenant('probe-esc-sustained');
    // setupSectionsTables() (not setupContentTables()) — the services probe's
    // content.sources/content.source_items stay absent, so it genuinely faults.
    // pinPoolPresence() keeps the watch/listen/events pool probes clean (see
    // its docblock) so THIS test's counts are the services probe alone.
    setupSectionsTables();
    setupBlocksTable();
    setupMediaTables();
    pinPoolPresence($pro->site);

    Exceptions::fake();
    $threshold = SitepageDataResolverService::FAULT_THRESHOLD;

    // Every fault short of the threshold stays a breadcrumb.
    for ($i = 1; $i < $threshold; $i++) {
        $present = lifeProbeResolver()->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());
        expect($present)->not->toContain('services');
        Exceptions::assertNothingReported();
    }

    // The threshold-th fault inside the window escalates — exactly once, not
    // one report per fault after (guards the `===` semantics in the trait).
    lifeProbeResolver()->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(QueryException::class);

    // Two more faults inside the SAME window must not re-fire — guards
    // against re-firing on every fault past the 5th.
    lifeProbeResolver()->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());
    lifeProbeResolver()->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());
    Exceptions::assertReportedCount(1);
});

// ── Case 3 — label partition: buckets don't sum ─────────────────────────

it('keeps two different probe labels in separate buckets so their faults never sum toward the same threshold', function () {
    $pro = createTenant('probe-esc-partition');
    // setupSectionsTables() (not setupContentTables()) — the services probe's
    // content.sources/content.source_items stay absent. Combined with no
    // setupBlocksTable() call, BOTH the services probe and the live-link-block
    // probe fault on every call, each landing in its OWN bucket.
    setupSectionsTables();
    setupMediaTables();

    Exceptions::fake();
    $threshold = SitepageDataResolverService::FAULT_THRESHOLD;

    // THRESHOLD-1 calls put THRESHOLD-1 faults on EACH of the two labels — if
    // the counters were shared, 2*(THRESHOLD-1) hits would already have
    // crossed THRESHOLD long before this loop ends.
    for ($i = 0; $i < $threshold - 1; $i++) {
        lifeProbeResolver()->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());
    }

    Exceptions::assertNothingReported();
});

// ── Case 4 — cardinality collapse: platform_complete_* is ONE bucket ────

it('collapses platform_complete_{platform} faults from different platforms into a single escalation bucket', function () {
    $pro = createTenant('probe-esc-cardinality');
    setupContentTables(); // pool presence probes (P4) need the pool tables
    setupBlocksTable();
    setupMediaTables();
    setupServicesTable();

    // Two REAL platform keys already in PLATFORM_TO_PAGE (spotify → listen,
    // youtube → watch), each given a completeness predicate that performs a
    // genuine faulting DB query — proving the collapse against a real
    // QueryException, not a mocked one.
    $registry = new PlatformRegistry;
    $faultingPredicate = fn () => DB::connection('pgsql')->table('life1_cardinality_probe_missing_table')->exists();
    $registry->register(PlatformDescriptor::make('spotify')->label('Spotify')->complete($faultingPredicate));
    $registry->register(PlatformDescriptor::make('youtube')->label('YouTube')->complete($faultingPredicate));
    app()->instance(PlatformRegistry::class, $registry);

    // Raw inserts (not IntegrationConnection::create()/update()) — the Eloquent
    // path fires IntegrationConnectionObserver, which touches the site and warms
    // its public-payload cache, itself calling presentPageIds() against the SAME
    // faulting descriptor and silently eating into the fault budget this test is
    // precisely counting. Raw DB access sidesteps that entirely, matching
    // DisplaySettingsTest's displaySeedConnection() helper.
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'surface_key' => 'spotify.player',
        'routing_class' => 'content',
        'resource_id' => 'spotify',
        'payload' => json_encode([]),
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'surface_key' => 'youtube.channel',
        'routing_class' => 'content',
        'resource_id' => 'youtube',
        'payload' => json_encode([]),
        'is_active' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Exceptions::fake();
    $threshold = SitepageDataResolverService::FAULT_THRESHOLD;

    // THRESHOLD-1 faults, all from the ACTIVE spotify connection.
    for ($i = 0; $i < $threshold - 1; $i++) {
        lifeProbeResolver()->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());
    }
    Exceptions::assertNothingReported();

    // Switch the active connection to a DIFFERENT platform. If the two
    // platforms' faults were bucketed separately, this would just start a
    // fresh count at 1. Because platform_complete_* collapses to one bucket,
    // this is the THRESHOLD-th hit on the SAME counter and escalates.
    DB::connection('pgsql')->table('site.platform_connections')->where('surface_key', 'spotify.player')->update(['is_active' => 0]);
    DB::connection('pgsql')->table('site.platform_connections')->where('surface_key', 'youtube.channel')->update(['is_active' => 1]);

    lifeProbeResolver()->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(QueryException::class);
});

// ── Case 6 — reporter itself throws → still returns normally (P0 net) ───
// (Numbered per the plan; this is the regression net for the only P0-shaped
// risk of this change — a broken exception reporter must never turn a
// fail-open public render into a 500.)

it('never turns a fault into a 500 even when the exception reporter itself throws', function () {
    $pro = createTenant('probe-esc-broken-reporter');
    // setupSectionsTables() (not setupContentTables()) — the services probe's
    // content.sources/content.source_items stay absent, so it faults on every call.
    setupSectionsTables();
    setupBlocksTable();
    setupMediaTables();

    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->andThrow(new RuntimeException('reporter is broken'));
    $handler->shouldIgnoreMissing();
    app()->instance(ExceptionHandler::class, $handler);

    $threshold = SitepageDataResolverService::FAULT_THRESHOLD;

    // Drive well past the threshold — Tier 1's report() call is exactly where
    // an unguarded reporter would throw and escape safeQuery's catch,
    // 500-ing the whole public page. It must not: EscalatesRepeatedFaults
    // wraps report() in reportSafely()'s own try/catch.
    for ($i = 0; $i < $threshold + 3; $i++) {
        $present = lifeProbeResolver()->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());
        expect($present)->not->toContain('services');
    }
});

// ── Case 5 — the load-bearing end-to-end case ───────────────────────────

it('LIFE-1 end-to-end: a services-probe fault degrades /api/public/profiles/{handle} gracefully AND escalates once sustained', function () {
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupDesignKitsTable();
    setupSiteMediaTable();
    setupSubdomainAliasesTable();
    // Two UNRELATED resilience paths (SiteCacheService's public_site_payload
    // warm, RenameSubdomainAction's handle-alias check) also report() on a
    // missing table — set them up so this test's Exceptions::fake() only
    // captures the ONE fault under test (site.services), not incidental noise
    // from other already-existing fail-open paths.
    setupPublicSitePayloadTable();
    setupHandleAliasesTable();
    // setupSectionsTables() (not setupContentTables()) — pool presence probes
    // (P4) must not add faults of their own, so the pool tables ARE
    // provisioned; content.sources/content.source_items stay absent so the
    // services probe (content.items x source_items x sources) genuinely
    // faults inside presentPageIds() on every request below, without the pool
    // probes tripping too.
    setupSectionsTables();
    // A FOURTH unrelated resilience path (CCH-11): ContentPopularityReader's
    // readers now escalate a sustained QueryException instead of silently
    // caching an empty ranking. Left unprovisioned, analytics.content_popularity_scores
    // faults on every request in the loop below and contributes reports of its
    // own — same reasoning as the three paths above: provision it so
    // Exceptions::fake() captures ONLY the services-probe fault under test.
    setupContentPopularityScoresTable();
    // A THIRD unrelated resilience path, newly relevant since slice 5a's C2
    // fix (ddea76982) repointed CloudflarePurgeService's product-handle
    // lookup at content.* instead of site.shop_products: every site save
    // below fires SiteObserver -> CloudflareCachePurgeJob(afterCommit), and
    // that job's handle() ALSO self-dispatches 3 delayed follow-up purges
    // (schedule config('partna.cache.purge_followup_schedule')) which the
    // sync queue driver runs immediately, not after their delay — so ONE
    // site save drives purgeHandle() 4 times. Each call's product-handle
    // subquery joins content.collection_items/collections/f_catalog, and
    // CloudflarePurgeService catches that failure with a RAW report($e)
    // (OBS-101, cdf6f9eaf — already shipped, predates this branch), with NO
    // EscalatesRepeatedFaults dedup. Left unprovisioned, that path alone
    // contributes 4 un-deduped reports per iteration — 20 across the 5-request
    // loop below, on top of the 1 legitimate escalated report from the
    // services probe this test is actually about. Provisioning just these
    // three tables (kept EMPTY — never content.sources/content.source_items)
    // lets that lookup resolve to "no products" instead of throwing, so
    // Exceptions::fake() below captures ONLY the services-probe fault.
    //
    // Hand-written DDL, not setupContentTables() — deliberately: this is a
    // MINIMAL subset that exists ONLY so CloudflarePurgeService's lookup
    // resolves instead of throwing, not a general-purpose content.* stand-in.
    // If content.collections, content.collection_items, or content.f_catalog
    // gain/lose columns in supabase/migrations/, this block needs updating in
    // step — it does not inherit fixes the way setupContentTables() would.
    // external_ref/removed_at kept in step with setupContentTables()'s copy
    // (slice 3b Task 1) — DuplicateStandInDdlGuardTest compares this table's
    // body against tests/Pest.php's byte-for-byte; letting the two diverge
    // makes the shared helper's (earlier-running) body win silently, so this
    // test would believe it runs under its own schema and not.
    // external_ref/removed_at kept in step with setupContentTables()'s copy
    // (slice 3b Task 1) — DuplicateStandInDdlGuardTest compares this table's
    // body against tests/Pest.php's byte-for-byte; letting the two diverge
    // makes the shared helper's (earlier-running) body win silently, so this
    // test would believe it runs under its own schema and not.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS content.collections (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        parent_id TEXT NULL,
        label TEXT NOT NULL,
        kind TEXT NULL,
        external_ref TEXT NULL,
        removed_at TEXT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        is_user_created INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS content.collection_items (
        collection_id TEXT NOT NULL,
        item_id TEXT NOT NULL,
        source_id TEXT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (collection_id, item_id)
    )');
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS content.f_catalog (
        item_id TEXT NOT NULL,
        source_id TEXT NOT NULL,
        release_type TEXT NULL, track_number INTEGER NULL, disc_number INTEGER NULL,
        isrc TEXT NULL, gtin TEXT NULL, sku TEXT NULL, handle TEXT NULL, vendor TEXT NULL,
        variant_ref TEXT NULL,
        updated_at TEXT NOT NULL,
        PRIMARY KEY (item_id, source_id)
    )');
    // Slice 4 extended the SAME purge path: purgeHandle()'s dish lookup now
    // reads content.items + content.item_slugs alongside the legacy
    // site.menu_items, because both menu wires are live and purging one leaves
    // the other stale. Unprovisioned, that lookup throws on every purge and —
    // even with slice 4's EscalatesRepeatedFaults dedup absorbing 19 of the 20
    // — the 5th fault escalates and lands a SECOND report, breaking the
    // exactly-1 assertion this test is about. Same maintenance the 5a C2 fix
    // needed above, for the same reason.
    //
    // Bodies copied BYTE-FOR-BYTE from tests/Pest.php per the note above:
    // DuplicateStandInDdlGuardTest compares them, and a divergence would let
    // the shared helper's earlier-running body win silently.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS content.items (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        kind TEXT NOT NULL CHECK (kind IN (
            \'video\', \'track\', \'release\', \'episode\', \'channel\', \'service\', \'menu_item\',
            \'product\', \'event\', \'link\', \'media\', \'review\', \'document\', \'article\'
        )),
        headline_cache TEXT NULL,
        facets_cache TEXT NOT NULL DEFAULT \'[]\',
        removed_at TEXT NULL,
        review_flag TEXT NULL,
        first_seen_at TEXT NOT NULL,
        last_seen_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS content.item_slugs (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        item_id TEXT NOT NULL,
        slug TEXT NOT NULL,
        is_current INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        retired_at TEXT NULL,
        UNIQUE (user_id, slug)
    )');

    Exceptions::fake();
    $threshold = SitepageDataResolverService::FAULT_THRESHOLD;

    // A fresh handle per request avoids needing to bust the 60s public-profile
    // cache between iterations — each is a genuine cache-miss build.
    for ($i = 1; $i <= $threshold; $i++) {
        $handle = 'life1-e2e-'.$i;
        $user = User::create([
            'handle' => $handle,
            'handle_lc' => strtolower($handle),
            'display_name' => ucfirst($handle),
            'first_name' => ucfirst($handle),
            'account_type' => 'partna',
            'status' => 'active',
            'auth_user_id' => (string) Str::uuid(),
            'primary_email' => "{$handle}@example.test",
        ]);
        // Site::user_id is not fillable (tenancy FK) — go through the
        // relation like ->associate() would, same as
        // IncompleteBookingPagePresenceTest's bookingPresenceSite(). Wrapped
        // in a transaction WITH the pin: SiteObserver::saved() dispatches
        // WarmPublicSiteCacheJob->afterCommit(), which (on the sync queue,
        // with no enclosing transaction) would otherwise run BEFORE
        // pinPoolPresence() — building the public payload once with the pool
        // sections unpinned, genuinely faulting the pool probes too and
        // diluting this test's exactly-1-report assertion below. Committing
        // the pin together with the site means the deferred warm job only
        // ever sees the already-pinned state.
        $site = DB::connection('pgsql')->transaction(function () use ($user, $handle) {
            $site = $user->site()->create(['subdomain' => $handle, 'is_published' => true]);
            pinPoolPresence($site);

            return $site;
        });

        $res = $this->getJson("/api/public/profiles/{$handle}")->assertOk();

        // Graceful degradation: the section that faulted just isn't present,
        // the rest of the payload is intact.
        expect($res->json('data.pageOrder'))->not->toContain('services')
            ->and($res->json('data.pageOrder'))->toContain('home')
            ->and($res->json('data.profile.pools.services'))->toBeNull()
            ->and($res->json('data.profile.site_id'))->not->toBeNull();
    }

    // Observable signal: the sustained run of real, route-level faults
    // crossed the threshold and reported exactly once.
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(QueryException::class);
});

// ── Line-243 owner-intent-inversion fix ─────────────────────────────────
// google_business_connection_display_settings' default used to be `null`,
// collapsing to $gbDisplay = [] on a fault — indistinguishable from a
// legitimate "no display_settings row" read, which absent-means-shown then
// treats as the owner never toggling Menu off. A DB fault therefore
// OVERRODE an explicit "Menu OFF" switch and re-advertised a page the owner
// turned off. The fix: default `false` (never a real return value from this
// query — it returns a model or null), so a fault is distinguishable from a
// legitimate null and suppresses the GB-derived Menu page instead of falling
// through to "shown".

it('suppresses the GB-derived Menu page on a display-settings probe fault, even when a real fetched Menu exists', function () {
    // Menu is Business-only (SitepageId::BUSINESS_ONLY) — a Standard partna
    // account would never show it regardless of the fault, which would make
    // this assertion pass for the wrong reason.
    $pro = createTenant('gb-fault-suppress-menu', ['account_type' => 'business']);
    setupContentTables(); // pool presence probes (P4) need the pool tables

    // A REAL fetched menu exists — absent the fault below, the Menu page
    // would be present (mirrors DisplaySettingsTest's dsFetchedMenu helper).
    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'store_name' => 'Test Menu',
        'last_fetched_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Force the display-settings probe (and the connections probe ahead of
    // it) to genuinely fault, rather than mocking the query away.
    DB::connection('pgsql')->statement('DROP TABLE site.platform_connections');

    $present = lifeProbeResolver()->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    // The owner's real "Menu ON" state (via the fetched Menu row) must NOT
    // resurface just because the toggle-read faulted — the fault suppresses
    // the page rather than falling through to "shown".
    expect($present)->not->toContain('menu');
});

it('still shows the Menu page on a legitimate (non-faulting) absent display_settings row — the fault sentinel does not leak into the normal path', function () {
    $pro = createTenant('gb-no-fault-menu-shown', ['account_type' => 'business']);
    setupContentTables(); // pool presence probes (P4) need the pool tables

    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'surface_key' => 'google_business.listing',
        'routing_class' => 'content',
        'resource_id' => 'res-'.Str::random(6),
        'payload' => json_encode(['name' => 'Cafe']),
        'display_settings' => null,
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'store_name' => 'Test Menu',
        'last_fetched_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $present = lifeProbeResolver()->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($present)->toContain('menu');
});
