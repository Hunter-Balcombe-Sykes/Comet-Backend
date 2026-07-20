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
use App\Services\Media\MediaUploadService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

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
