<?php

// TEST-103: PostgreSQL-only integration test for the design-singleton
// purge-then-create race in MediaUploadService::uploadSingleton().
//
// PREMISE CORRECTION (do not "fix" per the audit's original framing): the
// audit claimed a race "could leave two active singleton rows". It can't —
// site_media_design_singleton_purpose_uq (supabase/migrations/
// 20260701210000_collapse_cover_singleton_indexes.sql) is a real partial
// UNIQUE INDEX on (site_id, purpose) WHERE pool='design' AND deleted_at IS
// NULL, so Postgres itself refuses a second live row. Asserting "exactly one
// active row survives" (the audit's prescribed test) would pass trivially and
// prove nothing new.
//
// THE REAL GAP: uploadSingleton() calls purgeExistingSingleton() (a plain
// soft-delete, no lock) BEFORE createSingletonRow()'s transaction, which is
// the only place that takes a per-site pg_advisory_xact_lock. Because the
// purge sits outside that lock, two concurrent uploads for the same
// site+purpose can both pass purgeExistingSingleton() before either commits a
// new row, then serialize at createSingletonRow(): the first INSERT succeeds,
// the second hits the unique index and throws a QueryException that NOTHING
// catches (uploadSingleton() only wraps imageService->storeOriginal(), not
// createSingletonRow()). That's an unhandled 500 for the losing request — a
// worse outcome than transient duplication would have been, and one the
// audit's proposed test would never have caught.
//
// Single-threaded PHP can't hold two real concurrent transactions open (see
// the same limitation documented in WriteDesignKitConcurrencyTest.php), so
// this reproduces the interleaving deterministically by invoking the two
// private steps out of order via reflection: purge once, create once
// (requester A, succeeds), then create again with NO intervening purge
// (requester B, whose purge in the real race already ran before A's create
// committed). That is exactly the sequence of DB operations the real race
// produces — it does not require simulating true parallelism to expose the
// defect.
//
// Skip guard: bails with markTestSkipped when the 'pgsql' connection is
// SQLite (the default in CI — tests/Pest.php redirects 'pgsql' to in-memory
// SQLite for the whole suite, per tests/Feature/Database/CheckConstraintsTest.php
// and WriteDesignKitConcurrencyTest.php). This test ALWAYS SKIPS in
// composer test / CI — it has zero CI protection and only runs against a
// real Postgres connection (e.g. the dev Supabase DB).
//
// To run against a real Supabase dev DB:
//   DB_CONNECTION=pgsql DB_HOST=... php artisan test tests/Feature/Media/DesignSingletonMediaConcurrencyTest.php

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Media\Exceptions\SingletonConflictException;
use App\Services\Media\ImageVariantService;
use App\Services\Media\MediaUploadService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Skip the test when the 'pgsql' connection driver is not PostgreSQL.
 * Named with a unique prefix to avoid redeclare collision across files.
 */
function designSingletonConcurrencyIsPostgres(): bool
{
    return DB::connection('pgsql')->getDriverName() === 'pgsql';
}

/** Invoke MediaUploadService::purgeExistingSingleton() directly via reflection. */
function invokePurgeExistingSingleton(MediaUploadService $service, Site $site, string $purpose): void
{
    $method = (new ReflectionClass($service))->getMethod('purgeExistingSingleton');
    $method->invoke($service, $site, $purpose);
}

/** Invoke MediaUploadService::createSingletonRow() directly via reflection. */
function invokeCreateSingletonRow(MediaUploadService $service, Site $site, string $purpose, UploadedFile $file): SiteMedia
{
    $method = (new ReflectionClass($service))->getMethod('createSingletonRow');

    return $method->invoke($service, $site, $purpose, $file);
}

it('createSingletonRow throws an uncaught unique-violation when a purge-then-create race interleaves (pgsql only, TEST-103)', function () {
    if (! designSingletonConcurrencyIsPostgres()) {
        $this->markTestSkipped('the singleton unique-index race guard requires PostgreSQL.');
    }

    $pro = User::factory()->create(['handle' => 'singletonrace', 'handle_lc' => 'singletonrace']);
    $site = Site::factory()->create(['user_id' => $pro->id]);

    $service = app(MediaUploadService::class);
    $file = UploadedFile::fake()->image('logo.png', 200, 200);

    // Requester A: purge (no-op — nothing exists yet), then create. Succeeds
    // and leaves exactly one active `logo_full` row.
    invokePurgeExistingSingleton($service, $site, 'logo_full');
    invokeCreateSingletonRow($service, $site, 'logo_full', $file);

    // Requester B: in the real race, B's purge ran BEFORE A's create
    // committed (purgeExistingSingleton() is outside createSingletonRow()'s
    // advisory-locked transaction), so by the time B reaches create(), A's
    // row is already active and B's own purge found nothing to remove. This
    // reproduces that exact state — no purge call precedes this create — and
    // the resulting unique-index violation propagates uncaught.
    expect(fn () => invokeCreateSingletonRow($service, $site, 'logo_full', $file))
        ->toThrow(QueryException::class);

    // The DB-level invariant held — no duplicate active row — but that was
    // never in doubt (the index guarantees it). The defect this test pins is
    // the uncaught exception on the LOSING request, which callers see as an
    // unhandled 500, not the row count.
    $active = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('purpose', 'logo_full')
        ->get();

    expect($active)->toHaveCount(1);
});

// ── FIX: MediaUploadService::createSingletonRowOrConflict() ────────────────
//
// The test above pins the raw DB-level fact but ALWAYS SKIPS in CI (SQLite,
// via tests/Pest.php's redirect) because setupMediaTables() never mirrors
// site_media_design_singleton_purpose_uq — SQLite has no unique constraint on
// site_media at all by default, so nothing there could ever conflict. The
// tests below add that ONE index locally, inside their own fresh in-memory
// schema, rather than editing the shared setupMediaTables() helper (40+ other
// test files call it; a global constraint change is out of scope and risks
// breaking unrelated fixtures). Laravel's SQLite grammar recognises "UNIQUE
// constraint failed" and throws the SAME Illuminate\Database\
// UniqueConstraintViolationException Postgres does (see
// Illuminate\Database\SQLiteConnection::isUniqueConstraintError), so this is
// a faithful reproduction of the production defect, not a stand-in for a
// different one — and these tests run on every `composer test` / CI pass.

/** Mirror the production partial unique index into this test's own SQLite schema only. */
function addDesignSingletonUniqueIndexForTest(): void
{
    // SQLite qualifies the INDEX itself with the attached-schema name
    // ("site."), not the table it's built on — the reverse of Postgres'
    // "CREATE INDEX ... ON site.site_media" shape.
    DB::connection('pgsql')->statement(
        "CREATE UNIQUE INDEX IF NOT EXISTS site.site_media_design_singleton_purpose_uq
            ON site_media (site_id, purpose)
            WHERE pool = 'design' AND deleted_at IS NULL"
    );
}

/** Invoke MediaUploadService::createSingletonRowOrConflict() directly via reflection. */
function invokeCreateSingletonRowOrConflict(MediaUploadService $service, Site $site, string $purpose, UploadedFile $file): SiteMedia
{
    $method = (new ReflectionClass($service))->getMethod('createSingletonRowOrConflict');

    return $method->invoke($service, $site, $purpose, $file);
}

it('createSingletonRowOrConflict converts the same interleaved race into a typed SingletonConflictException instead of an uncaught exception (SQLite, always runs in CI)', function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
    addDesignSingletonUniqueIndexForTest();

    $pro = User::factory()->create(['handle' => 'singletonraceci', 'handle_lc' => 'singletonraceci']);
    $site = Site::factory()->create(['user_id' => $pro->id]);

    $service = app(MediaUploadService::class);
    $file = UploadedFile::fake()->image('logo.png', 200, 200);

    // Requester A: purge (no-op — nothing exists yet), then create. Succeeds.
    invokePurgeExistingSingleton($service, $site, 'logo_full');
    invokeCreateSingletonRow($service, $site, 'logo_full', $file);

    // Requester B: create with no preceding purge — same interleaving as the
    // pgsql-only test above. Pre-fix (calling createSingletonRow directly),
    // this throws an uncaught QueryException/UniqueConstraintViolationException.
    // Post-fix, going through createSingletonRowOrConflict, it's caught and
    // converted to the typed, controller-mappable exception.
    expect(fn () => invokeCreateSingletonRowOrConflict($service, $site, 'logo_full', $file))
        ->toThrow(SingletonConflictException::class);

    // The failed INSERT rolled back in full — not even a trashed row for B,
    // and exactly one (A's) active row survives.
    $allRows = SiteMedia::withTrashed()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('purpose', 'logo_full')
        ->get();
    expect($allRows)->toHaveCount(1);
});

it('uploadSingleton converts a concurrent-replace race into a 409-mappable exception and leaves no orphaned storage for the losing request (SQLite, always runs in CI)', function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
    addDesignSingletonUniqueIndexForTest();

    $pro = createTenant('singletonraceintegration');
    $site = $pro->site;

    Queue::fake();

    // storeOriginal() must fire EXACTLY once — for the winner. If the losing
    // request's flow ever reached storage (the orphan risk the fix must
    // avoid), this Mockery expectation fails the test outright (verified by
    // Mockery::close() in TestCase::tearDown).
    $imageService = Mockery::mock(ImageVariantService::class);
    $imageService->shouldReceive('storeOriginal')->once()->andReturn('images/winner/original.png');
    $imageService->shouldReceive('deleteVariants')->andReturnNull();
    $imageService->shouldReceive('resolvedDiskName')->andReturn('test_disk');
    app()->instance(ImageVariantService::class, $imageService);

    $service = app(MediaUploadService::class);
    $fileA = UploadedFile::fake()->image('winner.png', 200, 200);
    $fileB = UploadedFile::fake()->image('loser.png', 200, 200);

    // Interleave via a query hook instead of reflection: this exercises the
    // real, unmodified public uploadSingleton() end-to-end for BOTH sides.
    // Requester A's ENTIRE upload runs the instant requester B's very first
    // query fires (B's own purge SELECT — the first statement its call
    // issues). At that instant A does not exist yet, so A's purge is a
    // no-op, A's create commits cleanly, and A stores its file. Control then
    // returns to B, whose already-fetched "nothing to purge" result is now
    // stale — B's create collides with A's just-committed row. That is
    // exactly the DB operation order the real race produces.
    $interleaved = false;
    DB::listen(function () use (&$interleaved, $service, $pro, $site, $fileA) {
        if ($interleaved) {
            return;
        }
        $interleaved = true;
        $service->uploadSingleton($pro, $site, $fileA, 'logo_full');
    });

    expect(fn () => $service->uploadSingleton($pro, $site, $fileB, 'logo_full'))
        ->toThrow(SingletonConflictException::class);

    expect($interleaved)->toBeTrue(); // sanity: the interleave actually fired

    // Winner intact: exactly one active row for this purpose.
    $active = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('purpose', 'logo_full')
        ->get();
    expect($active)->toHaveCount(1);

    // The loser's failed INSERT left no row at all, not even a trashed one.
    $allRows = SiteMedia::withTrashed()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('purpose', 'logo_full')
        ->get();
    expect($allRows)->toHaveCount(1);
});
