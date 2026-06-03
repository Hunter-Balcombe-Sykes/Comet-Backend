<?php

// TEST-2: PostgreSQL-only integration test for writeDesignKit() lock-guard invariant.
//
// Under SQLite, lockForUpdate() is a no-op (no row-level locking), so the
// existing WriteDesignKitTest.php cannot verify the concurrency guard. This
// file targets a real PostgreSQL connection and runs only when one is available.
//
// What is verified:
//   "disjoint-column writes do not lose each other's data"
//   Two sequential writes with disjoint column payloads (color_accent and
//   typography_font_family) both persist after the second commit. This is the
//   minimal readable invariant that lockForUpdate + updateOrInsert must uphold.
//
// What is NOT verified (and why):
//   True simultaneous contention (two processes blocked at the same time) is
//   not feasible within a single PHP process — we cannot hold one connection's
//   transaction open while the second is executing without threads or async IO.
//   A second independent connection is opened and used to hold a BEGIN +
//   lockForUpdate for a period, but because PHP in a single thread cannot block
//   on the second connection's lock while the first is still running, we cannot
//   deterministically test the "first writer blocks second" behaviour here.
//
//   What we CAN and DO test:
//     1. The updateOrInsert path runs against real Postgres without error.
//     2. Disjoint column writes do not clobber each other (no lost update).
//     3. A second connection that begins a transaction, reads the row with
//        lockForUpdate, then commits, does not corrupt the data written by
//        the first connection — confirming the two paths serialise cleanly.
//
//   A true multi-process race test would require pcntl_fork() or a test
//   harness that can run two PHP processes and coordinate them via a semaphore.
//   That is out of scope here and documented as a known gap.
//
// Skip guard:
//   Tests bail immediately with markTestSkipped when the 'pgsql' connection is
//   SQLite (the default in CI). This mirrors the pattern in
//   tests/Feature/Database/CheckConstraintsTest.php.
//
// To run against a real Supabase dev DB:
//   DB_CONNECTION=pgsql DB_HOST=... php artisan test tests/Feature/Api/User/SiteManagement/WriteDesignKitConcurrencyTest.php

use App\Http\Controllers\Api\User\SiteManagement\UserSiteController;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Skip the test when the 'pgsql' connection driver is not PostgreSQL.
 * Named with a unique prefix to avoid redeclare collision across files.
 */
function writeKitConcurrencyIsPostgres(): bool
{
    return DB::connection('pgsql')->getDriverName() === 'pgsql';
}

beforeEach(function () {
    if (! writeKitConcurrencyIsPostgres()) {
        return; // Skip guard runs inside each test — nothing to set up.
    }

    // Bust the column-list cache so each test re-fetches from the real catalog.
    $colKey = CacheKeyGenerator::designKitColumns();
    Cache::deleteMultiple([$colKey, $colKey.':stale']);
});

// ---------------------------------------------------------------------------
// Helper: invoke the private writeDesignKit() method directly via reflection.
// Mirrors the helper in WriteDesignKitTest.php.
// ---------------------------------------------------------------------------
function invokeWriteDesignKitPgsql(string $siteId, array $designKit): void
{
    $controller = app(UserSiteController::class);
    $method = (new ReflectionClass($controller))->getMethod('writeDesignKit');
    $method->setAccessible(true);
    $method->invoke($controller, $siteId, $designKit);
}

/**
 * Create a user + site on the real Postgres DB and return the site id.
 * The production trigger trg_create_empty_design_kit fires automatically so
 * we do NOT need to insert the design_kits row manually.
 */
function seedRealPgSite(string $handleSuffix = ''): string
{
    $user = User::factory()->create([
        'display_name' => 'PG Concurrency Tester',
        'handle' => 'pgconc'.($handleSuffix ?: ''),
        'handle_lc' => 'pgconc'.($handleSuffix ?: ''),
    ]);

    $site = Site::factory()->create(['user_id' => $user->id]);

    return $site->id;
}

// ---------------------------------------------------------------------------
// TEST 1: Disjoint-column writes both persist (no lost update).
//
// Write color_accent first, then typography_font_family. After both writes,
// both columns must hold their written values — confirming that the second
// updateOrInsert does not overwrite/null the first write's column.
// ---------------------------------------------------------------------------
it('persists disjoint design_kit column writes without a lost update (pgsql only)', function () {
    if (! writeKitConcurrencyIsPostgres()) {
        $this->markTestSkipped('lockForUpdate concurrency guard requires PostgreSQL.');
    }

    $siteId = seedRealPgSite('a');

    // First write: set only color_accent.
    invokeWriteDesignKitPgsql($siteId, ['color_accent' => '#aabbcc']);

    // Second write: set only typography_font_family.
    invokeWriteDesignKitPgsql($siteId, ['typography_font_family' => 'Inter']);

    $row = DB::connection('pgsql')
        ->table('site.design_kits')
        ->where('site_id', $siteId)
        ->first();

    // Both values must survive — the second write must not null the first.
    expect($row)->not->toBeNull();
    expect($row->color_accent)->toBe('#aabbcc');
    expect($row->typography_font_family)->toBe('Inter');
});

// ---------------------------------------------------------------------------
// TEST 2: A second independent connection that acquires a lockForUpdate on the
// same row within its own transaction, then commits, does not corrupt data
// written by the primary connection.
//
// This is the closest we can get to a real concurrency test in a single-process
// PHP context. The sequence is:
//   1. Primary writes color_accent (commits).
//   2. Secondary connection opens a transaction and locks the row with
//      lockForUpdate.
//   3. Secondary commits (releases lock).
//   4. Primary writes typography_font_family (commits).
//   5. Assert: both columns hold their written values.
//
// This verifies that a lock → commit cycle by an observer does not corrupt the
// row, and that the primary's second write lands correctly after the lock is
// released. It does NOT prove that two concurrent writes serialise correctly
// (see header comment for why that requires multi-process testing).
// ---------------------------------------------------------------------------
it('data survives an interleaved read-lock on the design_kit row (pgsql only)', function () {
    if (! writeKitConcurrencyIsPostgres()) {
        $this->markTestSkipped('lockForUpdate concurrency guard requires PostgreSQL.');
    }

    $siteId = seedRealPgSite('b');

    // Step 1: Primary write — sets color_accent and commits.
    invokeWriteDesignKitPgsql($siteId, ['color_accent' => '#112233']);

    // Step 2–3: A second connection runs its own transaction, acquires a
    // lockForUpdate on the row (simulating what a concurrent writeDesignKit
    // call would do before the app_backend lock is released), then commits.
    // Because this is single-threaded, the lock is held only until the
    // closure returns — there is no true blocking. The intent is to prove
    // that the SELECT ... FOR UPDATE + COMMIT round-trip against real Postgres
    // does not corrupt the row.
    DB::connection('pgsql')->transaction(function () use ($siteId) {
        DB::connection('pgsql')
            ->table('site.design_kits')
            ->where('site_id', $siteId)
            ->lockForUpdate()
            ->first(); // acquire lock, then let the transaction commit (no write)
    });

    // Step 4: Primary second write — sets typography_font_family.
    invokeWriteDesignKitPgsql($siteId, ['typography_font_family' => 'Lato']);

    $row = DB::connection('pgsql')
        ->table('site.design_kits')
        ->where('site_id', $siteId)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->color_accent)->toBe('#112233');
    expect($row->typography_font_family)->toBe('Lato');
});

// ---------------------------------------------------------------------------
// TEST 3: Verify the design_kits row was created by the production trigger.
//         (Regression: if trg_create_empty_design_kit is absent on the test
//         database, invokeWriteDesignKitPgsql falls back to upsert — this test
//         confirms the trigger path by asserting the row exists BEFORE any write.)
// ---------------------------------------------------------------------------
it('trigger pre-creates the design_kits row on site insert (pgsql only)', function () {
    if (! writeKitConcurrencyIsPostgres()) {
        $this->markTestSkipped('Postgres trigger test requires PostgreSQL.');
    }

    $siteId = seedRealPgSite('c');

    // The trigger fires on INSERT into site.sites. The design_kits row must
    // already exist without any explicit insert from test code.
    $row = DB::connection('pgsql')
        ->table('site.design_kits')
        ->where('site_id', $siteId)
        ->first();

    expect($row)->not->toBeNull('trg_create_empty_design_kit did not fire — is the trigger installed?');
    // All var columns should be null (empty kit).
    expect($row->color_accent)->toBeNull();
});
