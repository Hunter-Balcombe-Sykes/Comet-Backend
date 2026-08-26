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
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    // Drop corners from the information_schema mirror so it becomes a REAL
    // SQLite column that is absent from the catalog. This proves the filter
    // keys off information_schema specifically — and gives the guard an
    // assertion-based tooth that does not rely on SQLite rejecting unknown
    // columns. With the intersection: corners is dropped despite the
    // physical column existing. Without it: corners (a valid column) would
    // be written and SQLite would accept it, failing the toBeNull() below.
    // (Was color_bg pre-2026-07-10, then color_text, then theme_mode until
    // the 2026-08-27 plan-02 removals dropped that one too.)
    DB::connection('pgsql')->table('information_schema.columns')
        ->where('table_schema', 'site')
        ->where('table_name', 'design_kits')
        ->where('column_name', 'corners')
        ->delete();

    invokeWriteDesignKit($siteId, [
        'color_accent' => '#abcabc',  // in catalog → written
        'corners' => 'rounded',       // real column, absent from catalog → dropped
    ]);

    $row = DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $siteId)->first();

    expect($row->color_accent)->toBe('#abcabc');
    expect($row->corners)->toBeNull();
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

// TEST-8 / CACHE-1: a design-kit-only update busts the site cache TWICE. The
// controller writes site.design_kits BEFORE calling UpdateSiteAction::execute(),
// so both busts observe post-write state and no suppression wrapper is needed:
//   - Bust 1: execute([])'s non-dirty save → afterCommit 'saved' → SiteObserver
//             → invalidateSite. Safe because the kit row is already on disk.
//   - Bust 2: $site->touch() → dirty save → SiteObserver → invalidateSite. Also
//             rotates updated_at so the public.profile:* key naturally orphans.
//
// Reordering the kit write back after execute() is the regression this guards:
// it would restore the old bug where SiteObserver's afterCommit
// CloudflareCachePurgeJob purged the edge on PRE-write state, leaving the
// router's 24 h HTML cache pinned to the previous design.
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

    // Bust 1 (execute's non-dirty save) + bust 2 (touch), both post-kit-write.
    $spy->shouldHaveReceived('invalidateSite')->times(2);
});

// The edge purge must never be dispatched before site.design_kits is written —
// the router pins sitepage HTML for 24 h, so a purge on pre-write state leaves
// the old design served until some later unrelated mutation purges again. This
// is the mixed-payload path (site fields alongside design_kit), where the old
// ordering had NO post-write purge at all: $site->wasChanged() was true, so the
// touch() that would have re-fired SiteObserver was skipped.
it('dispatches the edge purge only after the design kit is on disk', function () {
    config(['partna.throttle.enabled' => false]);

    $pro = createTenant('purge-after-kit-write');
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $pro->site->id]);

    Queue::fake();

    // A kit column alongside a plain sites-row field, so execute() genuinely
    // dirties the model and wasChanged() returns true.
    actingAsUser($pro)
        ->patchJson('/api/site', [
            'is_published' => false,
            'design_kit' => ['corners' => 'rounded'],
        ])
        ->assertOk();

    // The kit is committed before any purge is dispatched, so the value a purge
    // consumer would re-render is already the new one.
    $kit = DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $pro->site->id)->first();
    expect($kit->corners)->toBe('rounded');

    Queue::assertPushed(CloudflareCachePurgeJob::class, fn ($job) => $job->followUp === false);
});

// (The theme_mode + theme_night_shift_auto persistence test retired
// 2026-08-27 with its columns — plan 02. The stale-dead-key drop it also
// proved rides the selections test below via effect_surface.)

// 2026-08-09 preset-only (migration 20260809090001) — write-surface coverage
// for the three new selection columns. Replaces the semantic text-scale test:
// text_body / text_display / text_desktop_h1 and the other 49 per-token
// columns left the schema, and text_size is the one control that moves all of
// them now.
it('persists the selection columns', function () {
    $siteId = seedSiteWithEmptyKit();

    // effect_surface was dropped 2026-07-15: a stale client still sending a
    // dead key must not break the write — the information_schema allowlist
    // silently drops it.
    invokeWriteDesignKit($siteId, [
        'text_size' => 'large',
        'spacing' => 'spacious',
        'corners' => 'rounded',
        'effect_surface' => 'outline',
    ]);

    $row = DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $siteId)->first();

    expect($row->text_size)->toBe('large')
        ->and($row->spacing)->toBe('spacious')
        ->and($row->corners)->toBe('rounded')
        ->and(property_exists($row, 'effect_surface'))->toBeFalse();
});

it('persists the typography_uppercase boolean and clears it back to null (plan 02 step 3)', function () {
    config(['partna.throttle.enabled' => false]);

    $pro = createTenant('uppercase-write');
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $pro->site->id]);

    actingAsUser($pro)
        ->patchJson('/api/site', ['design_kit' => ['typography_uppercase' => false]])
        ->assertOk();
    $row = DB::connection('pgsql')->table('site.design_kits')->where('site_id', $pro->site->id)->first();
    expect($row->typography_uppercase)->not->toBeNull()
        ->and((bool) $row->typography_uppercase)->toBeFalse();

    // Null = back to the package default (all-caps), same contract as
    // every other kit column.
    actingAsUser($pro)
        ->patchJson('/api/site', ['design_kit' => ['typography_uppercase' => null]])
        ->assertOk();
    $row = DB::connection('pgsql')->table('site.design_kits')->where('site_id', $pro->site->id)->first();
    expect($row->typography_uppercase)->toBeNull();

    // Junk 422s — the boolean rule has teeth.
    actingAsUser($pro)
        ->patchJson('/api/site', ['design_kit' => ['typography_uppercase' => 'SHOUT']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['design_kit.typography_uppercase']);
});

it('accepts every legal selection value over HTTP and stores it', function () {
    // The accept side of UpdateSiteValidationTest's rejection test. That one
    // proves junk 422s; a rule weakened to bare 'string' would pass it and
    // fail nothing. This pins the exact vocabularies the design system's
    // presets.ts ships (TEXT_SIZE / SPACING / CORNER presets), so
    // narrowing a rule below them fails here instead of silently 422ing a
    // legal pick in production. It lives in this file rather than beside its
    // partner because a request that PASSES validation goes on to run
    // writeDesignKit(), which needs the seeded information_schema mirror.
    config(['partna.throttle.enabled' => false]);

    $pro = createTenant('selection-legal');
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $pro->site->id]);

    foreach ([
        'text_size' => ['small', 'medium', 'large'],
        'spacing' => ['default', 'spacious'],
        'corners' => ['default', 'rounded'],
    ] as $column => $values) {
        foreach ($values as $value) {
            actingAsUser($pro)
                ->patchJson('/api/site', ['design_kit' => [$column => $value]])
                ->assertOk();

            $stored = DB::connection('pgsql')->table('site.design_kits')
                ->where('site_id', $pro->site->id)->value($column);

            expect($stored)->toBe($value, "{$column} => {$value} was not stored");
        }
    }
});

it('silently drops the retired theme_mode key over HTTP', function () {
    config(['partna.throttle.enabled' => false]);

    $pro = createTenant('old-theme-mode');
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $pro->site->id]);

    // theme_mode had a validation rule ('in:bleach') until 2026-08-27, when
    // the column died (plan 02). A stale client still sending it now takes
    // the same path as every other dead key: no rule, so validation passes,
    // and the information_schema allowlist drops it before the write.
    actingAsUser($pro)
        ->patchJson('/api/site', [
            'design_kit' => ['theme_mode' => 'dark', 'corners' => 'rounded'],
        ])
        ->assertOk();

    $row = DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $pro->site->id)->first();
    expect($row->corners)->toBe('rounded')
        ->and(property_exists($row, 'theme_mode'))->toBeFalse();
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
        // No silent remap anywhere else either (the effect_surface column the
        // 2026-07-10 rework carried values onto is itself dropped now).
        ->and(property_exists($row, 'effect_surface'))->toBeFalse();
});

// I4: PATCH design_kit.<col> = null is the "reset to auto" affordance —
// writeDesignKit() doesn't filter nulls out (only unset($valid['site_id'])),
// so an explicit null reaches updateOrInsert and clears the stored column.
// Pins that the SAME round-trip (Task 2) then shows the preset value again.

it('PATCH design_kit.<col> = null clears the manual override and the preset re-shows', function () {
    config(['partna.throttle.enabled' => false]);
    setupSubdomainAliasesTable(); // real (non-spied) cache invalidation reads this
    $user = createTenant('reset-to-auto');
    $user->sector = 'restaurant'; // food_drink -> typography_font_family: monument-grotesk
    $user->save();
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $user->site->id]);

    actingAsUser($user)->patchJson('/api/site', [
        'design_kit' => ['typography_font_family' => 'geist'],
    ])->assertOk()
        ->assertJsonPath('site.design_kit.typography_font_family', 'geist')
        ->assertJsonPath('site.design_kit_manual', ['typography_font_family']);

    $response = actingAsUser($user)->patchJson('/api/site', [
        'design_kit' => ['typography_font_family' => null],
    ])->assertOk();

    $response->assertJsonPath('site.design_kit.typography_font_family', 'monument-grotesk');
    $response->assertJsonPath('site.design_kit_manual', []);

    // Defense-in-depth: the column is genuinely NULL in storage, not just
    // masked by preset-merge ordering in the response.
    $row = DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $user->site->id)->first();
    expect($row->typography_font_family)->toBeNull();
});

it('resetting one manual column to auto does not disturb a sibling manual column', function () {
    config(['partna.throttle.enabled' => false]);
    setupSubdomainAliasesTable();
    $user = createTenant('reset-sibling');
    $user->sector = 'restaurant';
    $user->save();
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $user->site->id]);

    actingAsUser($user)->patchJson('/api/site', [
        'design_kit' => ['typography_font_family' => 'geist', 'color_accent' => '#000000'],
    ])->assertOk();

    $response = actingAsUser($user)->patchJson('/api/site', [
        'design_kit' => ['typography_font_family' => null],
    ])->assertOk();

    $response->assertJsonPath('site.design_kit.typography_font_family', 'monument-grotesk'); // reverted
    $response->assertJsonPath('site.design_kit.color_accent', '#000000'); // untouched sibling
    expect($response->json('site.design_kit_manual'))->toEqualCanonicalizing(['color_accent']);
});

it('resetting to null with no sector preset leaves the column fully absent', function () {
    config(['partna.throttle.enabled' => false]);
    setupSubdomainAliasesTable();
    $user = createTenant('reset-nosector');
    // no sector set — ProfileDesignPresets::forUser() returns [] for this user.
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $user->site->id,
        'color_accent' => '#123456',
    ]);

    $response = actingAsUser($user)->patchJson('/api/site', [
        'design_kit' => ['color_accent' => null],
    ])->assertOk();

    expect((array) $response->json('site.design_kit'))->toBe([]);
    expect($response->json('site.design_kit_manual'))->toBe([]);
});
