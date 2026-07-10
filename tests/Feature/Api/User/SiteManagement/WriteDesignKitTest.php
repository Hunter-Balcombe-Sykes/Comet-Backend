<?php

// TEST-7: Direct coverage for UserSiteController::writeDesignKit().
//
// The method enforces three safety invariants that no existing test exercises
// (DesignKitWriteInvalidatesBrandTest writes site.design_kits via raw SQL,
// bypassing the method entirely):
//   1. only columns that actually exist on site.design_kits are written
//      (incoming keys are intersected against information_schema.columns),
//   2. the site_id FK can never be overwritten by a caller, and
//   3. an empty payload is a no-op (no DB interaction at all).
//
// We invoke the private method directly via reflection rather than driving
// PATCH /api/professional/site on purpose. UpdateSiteRequest's allowlist
// pre-strips both unknown keys and site_id (validated() only returns keys with
// explicit rules) before the controller ever runs, so an HTTP-layer test would
// still pass even if these guards were deleted. Calling the method directly is
// the only way the test fails when a guard is removed — which is the whole
// point of the coverage.
//
// writeDesignKit() reads its real column whitelist from information_schema.columns,
// which exists only on PostgreSQL. The SQLite test DB has no such catalog, so we
// attach a stand-in `information_schema` database and mirror the live
// site.design_kits columns into it. The production filter logic then runs
// unchanged against a faithful column list.
//
// Not covered here: the lockForUpdate() row lock that serialises concurrent
// writes compiles to a no-op under SQLite, so the concurrency invariant would
// need a real-Postgres test to verify. These tests cover the three data-shape
// guarantees only.

use App\Http\Controllers\Api\User\SiteManagement\UserSiteController;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    // Flush the column-list cache so each test re-reads from the seeded
    // information_schema mirror (not a stale previous-test result). The cache
    // routes through CacheLockService::rememberLocked, which writes a :stale
    // twin alongside the primary — clear both or SWR serves stale columns.
    $colKey = CacheKeyGenerator::designKitColumns();
    Cache::deleteMultiple([$colKey, $colKey.':stale']);
    setupUsersTable();
    setupSitesTable();
    setupDesignKitsTable();
    seedDesignKitInformationSchema();
});

/**
 * Mirror the live site.design_kits column list into a stand-in
 * information_schema.columns table so writeDesignKit()'s catalog query resolves
 * on SQLite. Column names are read from the real table via PRAGMA, so the mirror
 * can never drift from setupDesignKitsTable().
 */
function seedDesignKitInformationSchema(): void
{
    $conn = DB::connection('pgsql');

    // attachTestSchemas() already attaches 10 schema databases — SQLite's
    // default SQLITE_MAX_ATTACHED limit. Attaching an 11th throws "too many
    // attached databases", so free slots by detaching schemas this test never
    // touches before attaching our information_schema mirror. The connection is
    // purged after each test, so these detaches are local and harmless.
    foreach (['brand', 'retail', 'commerce', 'billing'] as $unused) {
        try {
            $conn->statement("DETACH DATABASE {$unused}");
        } catch (Throwable $e) {
            // not attached — ignore
        }
    }

    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS information_schema");
    } catch (Throwable $e) {
        // Only the "already attached" case is expected. Anything else (e.g. the
        // SQLITE_MAX_ATTACHED limit being hit because the detaches above didn't
        // free a slot) must surface here, not be masked as a confusing
        // "no such table: information_schema.columns" error on the CREATE below.
        if (! str_contains($e->getMessage(), 'already in use')) {
            throw $e;
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS information_schema.columns (
        table_schema TEXT NULL,
        table_name TEXT NULL,
        column_name TEXT NULL
    )');

    // Idempotent reseed (the connection is fresh per test, but be defensive).
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

/** Invoke the private writeDesignKit() method under test via reflection. */
function invokeWriteDesignKit(string $siteId, array $designKit): void
{
    $controller = app(UserSiteController::class);
    $method = (new ReflectionClass($controller))->getMethod('writeDesignKit');
    $method->invoke($controller, $siteId, $designKit);
}

/**
 * Seed a user + site + a single empty design_kits row (the production trigger
 * trg_create_empty_design_kit does this automatically; SQLite has no triggers).
 * Returns the site id.
 */
function seedSiteWithEmptyKit(array $kitOverrides = []): string
{
    $user = User::factory()->create([
        'display_name' => 'Kit Tester', 'handle' => 'kittester', 'handle_lc' => 'kittester',
    ]);
    $site = Site::factory()->create(['user_id' => $user->id]);

    DB::connection('pgsql')->table('site.design_kits')->insert(array_merge(
        ['site_id' => $site->id],
        $kitOverrides,
    ));

    return $site->id;
}

it('creates the design_kits row when none exists (backfill gap)', function () {
    // SCHEMA-1: sites created before the design_kits backfill (or via a trigger
    // bypass — pg_restore, session_replication_role='replica') can be missing
    // their 1:1 kit row. The production trigger trg_create_empty_design_kit
    // does not exist under SQLite, so we reproduce the gap by seeding a site
    // with NO kit row at all. A plain ->update() targets zero rows and returns
    // success, silently discarding the user's design choices. updateOrInsert
    // must create the row instead.
    $user = User::factory()->create([
        'display_name' => 'No Kit', 'handle' => 'nokit', 'handle_lc' => 'nokit',
    ]);
    $siteId = Site::factory()->create(['user_id' => $user->id])->id;

    // Precondition: the row genuinely does not exist.
    expect(DB::connection('pgsql')->table('site.design_kits')->where('site_id', $siteId)->exists())
        ->toBeFalse();

    invokeWriteDesignKit($siteId, ['color_accent' => '#abcabc']);

    $row = DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $siteId)->first();

    expect($row)->not->toBeNull();
    expect($row->color_accent)->toBe('#abcabc');
});

it('persists only columns that exist on site.design_kits', function () {
    $siteId = seedSiteWithEmptyKit();

    // color_accent is a real column; nonexistent_column is not. If the
    // information_schema intersection were removed, the bogus key would reach
    // ->update() and SQLite would throw "no such column: nonexistent_column".
    invokeWriteDesignKit($siteId, [
        'color_accent' => '#abcabc',
        'nonexistent_column' => 'should-be-dropped',
    ]);

    $accent = DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $siteId)->value('color_accent');

    expect($accent)->toBe('#abcabc');
});

it('filters incoming keys against information_schema, not the physical table', function () {
    $siteId = seedSiteWithEmptyKit();

    // Drop color_text from the information_schema mirror so it becomes a REAL
    // SQLite column that is absent from the catalog. This proves the filter
    // keys off information_schema specifically — and gives the guard an
    // assertion-based tooth that does not rely on SQLite rejecting unknown
    // columns. With the intersection: color_text is dropped despite the
    // physical column existing. Without it: color_text (a valid column) would
    // be written and SQLite would accept it, failing the toBeNull() below.
    // (Was color_bg pre-2026-07-10; that column no longer exists at all.)
    DB::connection('pgsql')->table('information_schema.columns')
        ->where('table_schema', 'site')
        ->where('table_name', 'design_kits')
        ->where('column_name', 'color_text')
        ->delete();

    invokeWriteDesignKit($siteId, [
        'color_accent' => '#abcabc',  // in catalog → written
        'color_text' => '#dedede',    // real column, absent from catalog → dropped
    ]);

    $row = DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $siteId)->first();

    expect($row->color_accent)->toBe('#abcabc');
    expect($row->color_text)->toBeNull();
});

it('silently ignores an empty design_kit array', function () {
    $siteId = seedSiteWithEmptyKit(['color_accent' => '#000000']);

    DB::connection('pgsql')->flushQueryLog();
    DB::connection('pgsql')->enableQueryLog();
    invokeWriteDesignKit($siteId, []);
    $queries = DB::connection('pgsql')->getQueryLog();
    DB::connection('pgsql')->disableQueryLog();

    // The empty-array short-circuit returns before any DB interaction — not even
    // the information_schema lookup runs.
    expect($queries)->toBe([]);

    // The existing row is untouched.
    $accent = DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $siteId)->value('color_accent');
    expect($accent)->toBe('#000000');
});

it('strips site_id if a caller attempts to rewrite the FK', function () {
    $siteId = seedSiteWithEmptyKit(['color_accent' => '#111111']);
    $otherId = (string) Str::uuid();

    invokeWriteDesignKit($siteId, [
        'site_id' => $otherId,        // hostile: attempt to repoint the row's FK
        'color_accent' => '#ffffff',  // legitimate change rides alongside it
    ]);

    $row = DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $siteId)->first();

    // The FK is unchanged — unset($valid['site_id']) dropped the hostile value ...
    expect($row)->not->toBeNull();
    expect($row->site_id)->toBe($siteId);
    // ... and the legitimate column still applied.
    expect($row->color_accent)->toBe('#ffffff');

    // The hostile id never created or repointed a row.
    $other = DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $otherId)->first();
    expect($other)->toBeNull();
});

// TEST-8 / CACHE-1: a design-kit-only update busts the site cache TWICE:
//   - Bust 1 (SUPPRESSED): execute([]) runs inside Site::withoutEvents, so the
//             non-dirty save's afterCommit 'saved' event never reaches SiteObserver.
//             Without the wrap this bust would fire BEFORE the design_kits write
//             lands, busting (and rebuilding) the cache on pre-write state.
//   - Bust 2: $site->touch() → dirty save → SiteObserver → invalidateSite. Also
//             rotates updated_at so the public.profile:* key naturally orphans.
//   - Bust 3: explicit invalidateSite() after writeDesignKit — the authoritative
//             post-write invalidation.
//
// Dropping the withoutObservers wrap pushes the count back to 3; removing the
// explicit bust 3 drops it to 1. Either regression fails this assertion.
it('busts the site cache twice on a design-kit-only update via the HTTP endpoint', function () {
    config(['partna.throttle.enabled' => false]);

    $pro = createTenant('double-bust');
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $pro->site->id]);

    $spy = $this->spy(SiteCacheService::class);

    actingAsUser($pro)
        ->patchJson('/api/site', [
            'design_kit' => ['color_accent' => '#ff0000'],
        ])
        ->assertOk();

    // Bust 1 is suppressed (withoutObservers); busts 2 (touch) + 3 (explicit) remain.
    $spy->shouldHaveReceived('invalidateSite')->times(2);
});

// 2026-07-10 theme/surface rework (migration 20260710160000) — write-surface
// coverage for the new columns and retirement of the old values/columns.

it('persists theme_mode, theme_night_shift_auto and effect_surface', function () {
    $siteId = seedSiteWithEmptyKit();

    invokeWriteDesignKit($siteId, [
        'theme_mode' => 'dusk',
        'theme_night_shift_auto' => false,
        'effect_surface' => 'outline',
    ]);

    $row = DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $siteId)->first();

    expect($row->theme_mode)->toBe('dusk')
        ->and($row->effect_surface)->toBe('outline')
        // SQLite stores the boolean as 0 — assert the falsy value is a stored
        // false, not an untouched NULL.
        ->and($row->theme_night_shift_auto)->not->toBeNull()
        ->and((bool) $row->theme_night_shift_auto)->toBeFalse();
});

it('rejects the retired 2-value theme_mode over HTTP with a 422', function () {
    config(['partna.throttle.enabled' => false]);

    $pro = createTenant('old-theme-mode');
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $pro->site->id]);

    // 'dark' was valid pre-rework; the migration remapped stored rows to
    // 'midnight' and the trait now only accepts the 5 palette modes.
    actingAsUser($pro)
        ->patchJson('/api/site', [
            'design_kit' => ['theme_mode' => 'dark'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['design_kit.theme_mode']);
});

it('silently drops the retired effect_style key over HTTP', function () {
    config(['partna.throttle.enabled' => false]);
    // Unlike the spy-based HTTP test above, this one runs the REAL cache
    // invalidation path, which reads the subdomain-alias table.
    setupSubdomainAliasesTable();

    $pro = createTenant('old-effect-style');
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $pro->site->id]);

    // A stale client still sending effect_style must not 422 and must not
    // write anywhere: the key has no validation rule (validated() drops it)
    // and no site.design_kits column (the information_schema allowlist drops
    // it again, defence-in-depth). The sibling accent write proves the
    // design-kit path executed.
    actingAsUser($pro)
        ->patchJson('/api/site', [
            'design_kit' => [
                'effect_style' => 'soft-glass',
                'color_accent' => '#123123',
            ],
        ])
        ->assertOk();

    $row = DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $pro->site->id)->first();

    expect($row->color_accent)->toBe('#123123')
        // No silent remap onto the new column either — carry-over happened
        // once, in the migration.
        ->and($row->effect_surface)->toBeNull();
});
