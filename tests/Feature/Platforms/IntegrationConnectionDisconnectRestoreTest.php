<?php

// P1-PILOT audit unit 3 — IntegrationConnectionObserver::deleted()/restored().
//
// CCH-4: saved() rolls site.updated_at (so the public.profile:{handle}:{ts}
// Redis cache key rotates — see the class docblock) whenever a
// completeness-gated platform's connection meaningfully changes, but
// deleted()/restored() never did. A disconnect or restore of a fresha/shop
// connection could leave the public payload cache advertising the wrong
// page presence until something unrelated touched the site. Fixed by adding
// the same hasCompletenessPredicate() gate + touch() to both hooks.
//
// LIFE-11: cleanupMirroredMedia() was the only best-effort deleted()
// side-effect in the class with no try/catch — an InstagramPayload parse
// error or a dispatch failure propagated out of deleted() and skipped
// retireEventSlugsOnDelete()/syncIngestSource() that follow it. Fixed by
// wrapping it like every sibling best-effort method in the same class.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function icdrUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function icdrSiteUpdatedAt(User $user): ?string
{
    return DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('updated_at');
}

/** Backdate site.updated_at past any touch() the create/delete above may have fired, isolating the NEXT lifecycle hook's effect. */
function icdrBackdateSite(User $user): void
{
    DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->update(['updated_at' => '2020-01-01 00:00:00']);
}

/**
 * Reload $conn with no cached relations. create()'s own saved() hook already
 * touched this connection's user->site once (fresha is completeness-gated),
 * which memoizes BOTH relations on the in-memory model — reusing that same
 * $conn for delete()/restore() would touch() the STALE cached Site instance
 * instead of a fresh read of the just-backdated row.
 */
function icdrFresh(IntegrationConnection $conn): IntegrationConnection
{
    return $conn->fresh();
}

const ICDR_BACKDATE = '2020-01-01 00:00:00';

// ── CCH-4: deleted() ─────────────────────────────────────────────────────

it('rolls site.updated_at when a completeness-gated connection is deleted (fresha)', function () {
    $user = createTenant('icdr-del-fresha');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/acme', 'selection' => null],
        'is_active' => true,
    ]);
    icdrBackdateSite($user);
    $conn = icdrFresh($conn);

    $conn->delete();

    expect(icdrSiteUpdatedAt($user))->not->toBe(ICDR_BACKDATE);
});

it('does not roll site.updated_at when a non-completeness-gated connection is deleted (strava)', function () {
    $user = createTenant('icdr-del-strava');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'strava',
        'resource_id' => 'strava-club',
        'payload' => ['name' => 'A Club'],
        'is_active' => true,
    ]);
    icdrBackdateSite($user);
    $conn = icdrFresh($conn);

    $conn->delete();

    expect(icdrSiteUpdatedAt($user))->toBe(ICDR_BACKDATE);
});

// ── CCH-4: restored() ────────────────────────────────────────────────────

it('rolls site.updated_at when a completeness-gated connection is restored (fresha)', function () {
    $user = createTenant('icdr-res-fresha');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/acme', 'selection' => null],
        'is_active' => true,
    ]);
    $conn->delete();
    icdrBackdateSite($user);
    $conn = icdrFresh($conn);

    $conn->restore();

    expect(icdrSiteUpdatedAt($user))->not->toBe(ICDR_BACKDATE);
});

it('does not roll site.updated_at when a non-completeness-gated connection is restored (strava)', function () {
    $user = createTenant('icdr-res-strava');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'strava',
        'resource_id' => 'strava-club',
        'payload' => ['name' => 'A Club'],
        'is_active' => true,
    ]);
    $conn->delete();
    icdrBackdateSite($user);
    $conn = icdrFresh($conn);

    $conn->restore();

    expect(icdrSiteUpdatedAt($user))->toBe(ICDR_BACKDATE);
});

// ── LIFE-11: cleanupMirroredMedia() must not abort the rest of deleted() ──

it('does not abort the rest of deleted() when the mirrored-media cleanup dispatch fails', function () {
    setupIngestTables();
    Exceptions::fake();

    $user = icdrUser('icdr-life11');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['username' => 'x', '_folder' => 'platforms/instagram/OLD'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    // syncIngestSource() auto-provisioned this row on create (instagram is
    // CostClass::Actor, so auto_sync landed false already) — flip it true so
    // the delete-time setAutoSync(false) call below has something observable
    // to prove it actually ran.
    DB::connection('pgsql')->table('ingest.sources')
        ->where('connection_id', $conn->id)->where('source_key', 'instagram')
        ->update(['auto_sync' => 1]);

    // Force DeleteMirroredMediaJob::dispatch() to blow up synchronously
    // inside cleanupMirroredMedia() — mirrors InstagramR2CleanupTest's JOB-4
    // pattern for updated()'s identical dispatch call.
    config(['queue.default' => 'invalid-connection-xyz']);

    expect(fn () => $conn->delete())->not->toThrow(Throwable::class);

    expect($conn->fresh()->trashed())->toBeTrue();
    Exceptions::assertReported(fn (InvalidArgumentException $e) => str_contains($e->getMessage(), 'invalid-connection-xyz'));

    // The LAST call in deleted() — syncIngestSource()'s trashed()-branch —
    // still ran and reached the DB despite cleanupMirroredMedia's dispatch
    // failure two calls earlier: auto_sync flipped back off.
    expect((int) DB::connection('pgsql')->table('ingest.sources')
        ->where('connection_id', $conn->id)->where('source_key', 'instagram')
        ->value('auto_sync'))->toBe(0);
});
