<?php

// TEST-103 (moved to the applied-schema lane, tranche 3 of COV-LANE): integration
// test for the design-singleton purge-then-create race in
// MediaUploadService::uploadSingleton().
//
// PREMISE CORRECTION (do not "fix" per the audit's original framing): the
// audit claimed a race "could leave two active singleton rows". It can't —
// site_media_design_singleton_purpose_uq (supabase/migrations/
// 20260701210000_collapse_cover_singleton_indexes.sql) is a real partial
// UNIQUE INDEX on (site_id, purpose) WHERE usage='design' AND deleted_at IS
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
// This file used to carry two extra "(SQLite, always runs in CI)" tests that
// fabricated the production partial unique index inside SQLite's in-memory
// schema, because that index doesn't exist there by default:
//
//   DB::connection('pgsql')->statement(
//       "CREATE UNIQUE INDEX IF NOT EXISTS site.site_media_design_singleton_purpose_uq
//           ON site_media (site_id, purpose)
//           WHERE usage = 'design' AND deleted_at IS NULL"
//   );
//
// `CREATE UNIQUE INDEX site.name ON site_media (...)` is SQLite-only syntax —
// SQLite treats an unknown quoted/dotted identifier as an attached-database
// prefix on the INDEX name, so it parses; real Postgres qualifies the TABLE,
// not the index, and rejects this outright with `syntax error at or near "."`.
// Now that this file lives in the applied-schema lane, the real migration has
// already created the actual index on the real table, so all three tests
// below exercise it directly — no fabrication needed, and the coverage is
// stronger, not weaker: they test the REAL constraint instead of a SQLite
// stand-in for it. No coverage was dropped; the two SQLite-only variants are
// gone because their reason to exist (SQLite doesn't have this index) is gone
// too.

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\Exceptions\SingletonConflictException;
use App\Services\Media\ImageVariantService;
use App\Services\Media\MediaUploadService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Schema\Concerns\SeedsAuthUsers;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class, SeedsAuthUsers::class)->in(__FILE__);

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

/** Invoke MediaUploadService::createSingletonRowOrConflict() directly via reflection. */
function invokeCreateSingletonRowOrConflict(MediaUploadService $service, Site $site, string $purpose, UploadedFile $file): SiteMedia
{
    $method = (new ReflectionClass($service))->getMethod('createSingletonRowOrConflict');

    return $method->invoke($service, $site, $purpose, $file);
}

it('createSingletonRow throws an uncaught unique-violation when a purge-then-create race interleaves (TEST-103)', function () {
    $user = $this->seedAuthUser();

    try {
        $site = Site::factory()->create(['user_id' => $user->id]);

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
            ->where('usage', SiteMedia::USAGE_DESIGN)
            ->where('purpose', 'logo_full')
            ->get();

        expect($active)->toHaveCount(1);
    } finally {
        $this->cleanupSeededUser($user);
    }
});

// ── FIX: MediaUploadService::createSingletonRowOrConflict() ────────────────

it('createSingletonRowOrConflict converts the same interleaved race into a typed SingletonConflictException instead of an uncaught exception', function () {
    $user = $this->seedAuthUser();

    try {
        $site = Site::factory()->create(['user_id' => $user->id]);

        $service = app(MediaUploadService::class);
        $file = UploadedFile::fake()->image('logo.png', 200, 200);

        // Requester A: purge (no-op — nothing exists yet), then create. Succeeds.
        invokePurgeExistingSingleton($service, $site, 'logo_full');
        invokeCreateSingletonRow($service, $site, 'logo_full', $file);

        // Requester B: create with no preceding purge — same interleaving as the
        // test above. Pre-fix (calling createSingletonRow directly), this throws
        // an uncaught QueryException/UniqueConstraintViolationException. Post-fix,
        // going through createSingletonRowOrConflict, it's caught and converted to
        // the typed, controller-mappable exception.
        expect(fn () => invokeCreateSingletonRowOrConflict($service, $site, 'logo_full', $file))
            ->toThrow(SingletonConflictException::class);

        // The failed INSERT rolled back in full — not even a trashed row for B,
        // and exactly one (A's) active row survives.
        $allRows = SiteMedia::withTrashed()
            ->where('site_id', $site->id)
            ->where('usage', SiteMedia::USAGE_DESIGN)
            ->where('purpose', 'logo_full')
            ->get();
        expect($allRows)->toHaveCount(1);
    } finally {
        $this->cleanupSeededUser($user);
    }
});

it('uploadSingleton converts a concurrent-replace race into a 409-mappable exception and leaves no orphaned storage for the losing request', function () {
    $user = $this->seedAuthUser();

    try {
        $site = Site::factory()->create(['user_id' => $user->id]);

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
        DB::listen(function () use (&$interleaved, $service, $user, $site, $fileA) {
            if ($interleaved) {
                return;
            }
            $interleaved = true;
            $service->uploadSingleton($user, $site, $fileA, 'logo_full');
        });

        expect(fn () => $service->uploadSingleton($user, $site, $fileB, 'logo_full'))
            ->toThrow(SingletonConflictException::class);

        expect($interleaved)->toBeTrue(); // sanity: the interleave actually fired

        // Winner intact: exactly one active row for this purpose.
        $active = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('usage', SiteMedia::USAGE_DESIGN)
            ->where('purpose', 'logo_full')
            ->get();
        expect($active)->toHaveCount(1);

        // The loser's failed INSERT left no row at all, not even a trashed one.
        $allRows = SiteMedia::withTrashed()
            ->where('site_id', $site->id)
            ->where('usage', SiteMedia::USAGE_DESIGN)
            ->where('purpose', 'logo_full')
            ->get();
        expect($allRows)->toHaveCount(1);
    } finally {
        $this->cleanupSeededUser($user);
    }
});
