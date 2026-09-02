<?php

/** @phpstan-ignore-all */

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    config(['partna.throttle.enabled' => false]); // PATCH /api/site carries throttle:authenticated
    setupUsersTable();
    setupSitesTable();
    // ActionCandidates reads the pools for item actions (was: the `custom:`
    // action family (convergence Phase 6), so site.sections must exist.
    setupSectionsTables();
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
    setupDesignKitsTable();
    seedCacheTestDesignKitInformationSchema();
});

/**
 * Mirror the live site.design_kits column list into a stand-in
 * information_schema.columns table so UserSiteController::writeDesignKit()'s
 * catalog query resolves on SQLite. Column names are read from the real table
 * via PRAGMA, so the mirror can never drift from setupDesignKitsTable().
 *
 * Deliberately NOT the global seedDesignKitInformationSchema() from
 * tests/Feature/Api/User/SiteManagement/WriteDesignKitTest.php — that
 * function is only defined when that file happens to load, so calling it
 * from a path-targeted run of this file alone would fatal on "undefined
 * function", and declaring a second global with the SAME name would fatal on
 * "cannot redeclare" whenever both files load together (e.g. a full suite
 * run). Distinctly named, file-local copy instead.
 */
function seedCacheTestDesignKitInformationSchema(): void
{
    $conn = DB::connection('pgsql');

    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS information_schema");
    } catch (Throwable $e) {
        if (! str_contains($e->getMessage(), 'already in use')) {
            throw $e;
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS information_schema.columns (
        table_schema TEXT NULL,
        table_name TEXT NULL,
        column_name TEXT NULL
    )');

    $conn->table('information_schema.columns')
        ->where('table_schema', 'site')
        ->where('table_name', 'design_kits')
        ->delete();

    foreach ($conn->select('PRAGMA site.table_info(design_kits)') as $col) {
        $conn->table('information_schema.columns')->insert([
            'table_schema' => 'site',
            'table_name' => 'design_kits',
            'column_name' => $col->name,
        ]);
    }
}

// ── CCH-4 Test 1 ──────────────────────────────────────────────────────────────
// Confirms that a design-kit-only PATCH advances sites.updated_at so the next
// public.profile:{handle}:{ts} cache key is different from the old one.
// Without $site->touch() the timestamp is stale and the old cache key continues
// to serve the pre-design-kit payload for up to 60 s.
//
// This test goes through the full HTTP controller path (PATCH /api/site) so
// that deleting the touch() block in UserSiteController::update() turns it
// RED — proven by mutation (delete the block, run the test, confirm it fails;
// restore, confirm it passes again). An earlier version of this test called
// UpdateSiteAction::execute() directly and replicated the controller's touch()
// logic in the test body itself — which stayed green under that same deletion,
// because the touch happened in the test, not the controller.

it('advances updated_at on a design-kit-only update so the public profile cache key rotates', function () {
    // Backdate updated_at by 5 minutes so the touch() advance is unambiguous
    // even at second-level precision (unix timestamps, no sub-second risk).
    $past = now()->subMinutes(5)->toDateTimeString();
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'dktest',
        'handle_lc' => 'dktest',
        'display_name' => 'DK Tester',
        'first_name' => 'DK Tester',
        'primary_email' => 'dktest@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $past,
        'updated_at' => $past,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'dktest',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $past,
        'updated_at' => $past,
    ]);

    // Seed an empty design_kits row (the production trigger does this automatically;
    // SQLite tests have no triggers so we insert it manually).
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $siteId,
        'created_at' => $past,
        'updated_at' => $past,
    ]);

    // Load the user with site eager-loaded.
    $pro = User::query()->with('site')->findOrFail($userId);
    $originalTs = $pro->site->updated_at->timestamp;
    $originalSettings = $pro->site->settings;

    // The real request path: a design-kit-only PATCH, no site-column fields.
    // corners is a real site.design_kits column (setupDesignKitsTable()) and a
    // legal value under DesignKitValidationRules::designKitRules().
    actingAsUser($pro)
        ->patchJson('/api/site', ['design_kit' => ['corners' => 'sharp']])
        ->assertOk();

    $freshSite = Site::query()->findOrFail($siteId);
    $updatedTs = $freshSite->updated_at->timestamp;

    expect($updatedTs)->toBeGreaterThan($originalTs,
        "The PATCH should have advanced updated_at from $past (ts=$originalTs) to now (ts=$updatedTs)"
    );

    // Proves this really was a design-kit write, not an empty-payload
    // short-circuit that happened to still touch the row for some other reason.
    $kitCorners = DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $siteId)->value('corners');
    expect($kitCorners)->toBe('sharp');

    // Proves nothing else dirtied the row alongside the kit write.
    expect($freshSite->settings)->toBe($originalSettings);
});

// ── CCH-4 Test 2 ──────────────────────────────────────────────────────────────
// Confirms that invalidateSitePayload() deletes handle.resolve:{handle} and
// handle.resolve:{handle}:stale from the cache. Without these busts the 30 s
// resolve cache would keep serving the old updated_at_ts, causing the profile
// controller to construct the old public.profile:* key and hit the stale payload.

it('busts handle.resolve cache keys via invalidateSitePayload', function () {
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'resolvetest',
        'handle_lc' => 'resolvetest',
        'display_name' => 'Resolvetest',
        'first_name' => 'Resolvetest',
        'primary_email' => 'resolvetest@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'resolvetest',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Seed the handle.resolve keys as they would appear after a real public request.
    $resolveKey = 'handle.resolve:resolvetest';
    $staleKey = 'handle.resolve:resolvetest:stale';

    Cache::put($resolveKey, ['pro_id' => $userId, 'site_id' => $siteId, 'updated_at_ts' => time()], now()->addSeconds(30));
    Cache::put($staleKey, ['pro_id' => $userId, 'site_id' => $siteId, 'updated_at_ts' => time()], now()->addMinutes(5));

    expect(Cache::has($resolveKey))->toBeTrue();
    expect(Cache::has($staleKey))->toBeTrue();

    $site = Site::query()->findOrFail($siteId);

    app(SiteCacheService::class)->invalidateSitePayload($site);

    // Both resolve keys must be gone so the next public request re-reads the
    // site row and constructs a fresh public.profile:* key with the new timestamp.
    expect(Cache::has($resolveKey))->toBeFalse();
    expect(Cache::has($staleKey))->toBeFalse();
});

// ── CCH-4 Test 3 (regression guard) ──────────────────────────────────────────
// invalidateSite() delegates to invalidateSitePayload() + invalidateSiteImages().
// Verify the resolve-key bust is triggered by the full invalidateSite() call so
// the controller path (which calls invalidateSite, not invalidateSitePayload)
// is also covered.

it('busts handle.resolve cache keys via the full invalidateSite call', function () {
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'fullbust',
        'handle_lc' => 'fullbust',
        'display_name' => 'Fullbust',
        'first_name' => 'Fullbust',
        'primary_email' => 'fullbust@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'fullbust',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $resolveKey = 'handle.resolve:fullbust';
    $staleKey = 'handle.resolve:fullbust:stale';

    Cache::put($resolveKey, ['pro_id' => $userId, 'updated_at_ts' => time()], now()->addSeconds(30));
    Cache::put($staleKey, ['pro_id' => $userId, 'updated_at_ts' => time()], now()->addMinutes(5));

    expect(Cache::has($resolveKey))->toBeTrue();
    expect(Cache::has($staleKey))->toBeTrue();

    $site = Site::query()->findOrFail($siteId);
    app(SiteCacheService::class)->invalidateSite($site);

    expect(Cache::has($resolveKey))->toBeFalse();
    expect(Cache::has($staleKey))->toBeFalse();
});

// ── Timestamp floor: key format ──────────────────────────────────────────────

it('builds the handle resolve floor key from the lowercased handle', function () {
    expect(CacheKeyGenerator::handleResolveFloor('FloorCase'))
        ->toBe('handle.resolve.floor:floorcase');
});

// ── Timestamp floor: invalidateSitePayload writes it ─────────────────────────
// The floor is what makes a stale handle.resolve entry harmless: the controller
// takes max(resolved ts, floor), so a re-installed pre-write timestamp can no
// longer hold the public.profile:* key back.

it('writes the site updated_at timestamp to the resolve floor on invalidation', function () {
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'floorwrite',
        'handle_lc' => 'floorwrite',
        'display_name' => 'Floorwrite',
        'first_name' => 'Floorwrite',
        'primary_email' => 'floorwrite@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'floorwrite',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $site = Site::query()->findOrFail($siteId);

    app(SiteCacheService::class)->invalidateSitePayload($site);

    expect((int) Cache::get('handle.resolve.floor:floorwrite'))
        ->toBe($site->updated_at->timestamp);
});

// ── Timestamp floor: monotonicity ────────────────────────────────────────────
// invalidateSitePayload() has callers that can hold a Site instance whose
// updated_at predates a concurrent save (ServiceCategoryObserver, UserCacheService's
// catch-all via the memoized $professional->site relation, ClaimSiteService,
// deletion paths). A blind Cache::put from one of those would regress a higher
// floor written moments earlier and re-open the exact race this fixes.

it('never regresses an existing higher resolve floor', function () {
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $past = now()->subMinutes(5)->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'floormono',
        'handle_lc' => 'floormono',
        'display_name' => 'Floormono',
        'first_name' => 'Floormono',
        'primary_email' => 'floormono@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $past,
        'updated_at' => $past,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'floormono',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $past,
        'updated_at' => $past,
    ]);

    // A newer save already raised the floor.
    $higher = now()->addMinutes(10)->timestamp;
    Cache::put('handle.resolve.floor:floormono', $higher, now()->addSeconds(600));

    // A caller holding the 5-minutes-stale Site instance invalidates.
    $staleSite = Site::query()->findOrFail($siteId);
    app(SiteCacheService::class)->invalidateSitePayload($staleSite);

    expect((int) Cache::get('handle.resolve.floor:floormono'))->toBe($higher);
});

// ── Timestamp floor: TTL comes from config, not a literal ────────────────────

it('takes the resolve floor TTL from config', function () {
    config()->set('partna.public_profile.resolve_floor_ttl', 1234);

    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'floorttl',
        'handle_lc' => 'floorttl',
        'display_name' => 'Floorttl',
        'first_name' => 'Floorttl',
        'primary_email' => 'floorttl@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'floorttl',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Hydrate the model BEFORE spying — a query can touch the cache, and a spy
    // installed around it would record puts this assertion doesn't care about.
    $site = Site::query()->findOrFail($siteId);

    Cache::spy();

    app(SiteCacheService::class)->invalidateSitePayload($site);

    // The TTL must be the config value, not a literal 600 in the service.
    Cache::shouldHaveReceived('put')
        ->withArgs(fn ($key, $value, $ttl) => $key === 'handle.resolve.floor:floorttl' && $ttl === 1234)
        ->once();
});

// ── The race, end to end ─────────────────────────────────────────────────────
// Reproduces the actual production sequence: a reader queries the DB pre-commit,
// the write commits and invalidates, THEN the in-flight reader's Cache::put lands
// and re-installs the pre-write timestamp with a fresh 30s lease. Without the
// floor the controller builds the OLD payload key and serves the pre-change
// render for up to 30s — long enough for the edge purge to land and re-pin it
// for the full 24h edge TTL.

it('resolves the post-write payload key even when a stale resolve entry is re-installed', function () {
    // This test goes through the full HTTP controller path, which reaches into
    // blocks/media/services/content-selection beyond this file's beforeEach —
    // see IndividualProfileControllerTest's beforeEach for the same set.
    setupBlocksTable();
    setupMediaTables();
    setupServiceCategoriesTable();
    setupServicesTable();

    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $past = now()->subMinutes(5)->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'racecase',
        'handle_lc' => 'racecase',
        'display_name' => 'Race Case',
        'first_name' => 'Race Case',
        'primary_email' => 'racecase@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $past,
        'updated_at' => $past,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'racecase',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $past,
        'updated_at' => $past,
    ]);

    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $siteId,
        'created_at' => $past,
        'updated_at' => $past,
    ]);

    $staleTs = Site::query()->findOrFail($siteId)->updated_at->timestamp;

    // The write commits and rotates updated_at.
    $site = Site::query()->findOrFail($siteId);
    $site->touch();
    $freshTs = $site->fresh()->updated_at->timestamp;
    expect($freshTs)->toBeGreaterThan($staleTs);

    // Invalidation runs (SiteObserver::saved, afterCommit).
    app(SiteCacheService::class)->invalidateSitePayload($site->fresh());

    // The in-flight reader's Cache::put lands AFTER the delete — the race.
    Cache::put(
        CacheKeyGenerator::handleResolve('racecase'),
        ['pro_id' => $userId, 'site_id' => $siteId, 'updated_at_ts' => $staleTs],
        now()->addSeconds(30),
    );

    $response = $this->getJson('/api/public/profiles/racecase');
    $response->assertOk();

    // The post-write key must be the one that got populated; the pre-write key
    // must never have been built.
    expect(Cache::has(CacheKeyGenerator::publicProfile('racecase', $freshTs)))->toBeTrue()
        ->and(Cache::has(CacheKeyGenerator::publicProfile('racecase', $staleTs)))->toBeFalse();
});
