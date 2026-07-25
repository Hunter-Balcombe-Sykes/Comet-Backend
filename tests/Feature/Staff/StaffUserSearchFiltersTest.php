<?php

/**
 * OV-A — StaffUserController::index gains sector + account_type filters and
 * q matches sector text.
 *
 * TEST-105: converted from an in-process `app(StaffUserController::class)->index($request)`
 * call to real HTTP requests. index() has NO authorizeForUser call (see its
 * docblock — the PII gate is the enforcement point, not a per-row Gate check),
 * so the route middleware stack (routes/api/staff.php:35-40 — supabase.jwt,
 * require.email_verified, staff, require.aal2, throttle:staff, staff.audit)
 * is the ONLY protection on this endpoint. An in-process call skips that
 * entire stack, so it could never notice if the stack were removed.
 *
 * TEST-104: adds coverage for the `q` ILIKE path (handle/email/display_name/
 * sector/etc). ILIKE is Postgres-only syntax with no SQLite equivalent, so
 * this coverage is gated behind a real 'pgsql' driver check and skips under
 * the SQLite test mirror — same convention as CheckConstraintsTest.php and
 * WriteDesignKitConcurrencyTest.php. It gives ZERO protection in the normal
 * `composer test` / CI run; it only runs when pointed at a real Postgres DB.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    DB::connection('pgsql')->statement('DELETE FROM core.users');
});

function ovaSearchUser(array $overrides = []): string
{
    $id = (string) Str::uuid();
    $attrs = array_merge([
        'id' => $id,
        'handle' => 'search-'.Str::random(8),
        'display_name' => 'Search Target',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides);

    // Derived AFTER the merge so an override that changes 'handle' (several
    // call sites below do) still yields a consistent handle_lc/first_name,
    // rather than baking a mismatch from the base handle.
    $attrs['handle_lc'] ??= strtolower($attrs['handle']);
    $attrs['first_name'] ??= ucfirst($attrs['handle']);

    DB::connection('pgsql')->table('core.users')->insert($attrs);

    return $id;
}

/** Unsaved support-role staff — index() has no per-row Gate check, so any staff role reaches it. */
function ovaSearchStaffActor(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_SUPPORT;
    $staff->primary_email = 'ova-search-staff@partna.au';

    return $staff;
}

/**
 * Real HTTP request against GET /api/staff/professionals through the full
 * middleware stack (TEST-105). Returns the ids of matching professionals.
 */
function ovaSearchIds(array $query = []): array
{
    $response = actingAsStaff(ovaSearchStaffActor())
        ->getJson('/api/staff/professionals?'.http_build_query($query));

    $response->assertOk();

    return array_column($response->json('professionals'), 'id');
}

function ovaSearchIsPostgres(): bool
{
    return DB::connection('pgsql')->getDriverName() === 'pgsql';
}

it('filters by sector exactly', function () {
    $dj = ovaSearchUser(['sector' => 'dj']);
    ovaSearchUser(['sector' => 'hairdresser']);

    $ids = ovaSearchIds(['sector' => 'dj']);

    expect($ids)->toBe([$dj]);
});

it('filters by account_type; staff is no longer an accepted value', function () {
    ovaSearchUser(['account_type' => 'partna']);
    $biz = ovaSearchUser(['account_type' => 'business']);
    ovaSearchUser(['account_type' => 'partna']);

    expect(ovaSearchIds(['account_type' => 'business']))->toBe([$biz])
        // 'staff' is no longer an accepted filter → treated like any unknown value → ignored.
        ->and(ovaSearchIds(['account_type' => 'staff']))->toHaveCount(3)
        ->and(ovaSearchIds(['account_type' => 'bogus']))->toHaveCount(3);
});

// ---------------------------------------------------------------------------
// TEST-105 — proves the route middleware stack (not a per-row Gate check) is
// what protects this endpoint. index() itself has no authorizeForUser call —
// if 'staff' were dropped from the route's middleware list in
// routes/api/staff.php, this request would sail through to the controller
// and return 200, which would fail the assertStatus(403) below. Passing aal2
// claims explicitly isolates the assertion to the staff check: require.aal2
// runs AFTER staff in the middleware list, so it can't be what's rejecting
// this request.
// ---------------------------------------------------------------------------

it('rejects a non-staff authenticated actor at the real staff middleware (not the actingAsStaff stub)', function () {
    $intruderId = ovaSearchUser([
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'intruder-'.Str::random(6),
    ]);
    $intruder = User::query()->findOrFail($intruderId);

    $response = actingAsUser($intruder, [
        'aal' => 'aal2',
        'amr' => [['method' => 'totp', 'timestamp' => time()]],
    ])->getJson('/api/staff/professionals');

    $response->assertStatus(403);
    expect($response->json('error'))->toBe('staff_required');
});

it('rejects an unauthenticated request with 401', function () {
    $response = $this->getJson('/api/staff/professionals');

    $response->assertStatus(401);
});

// ---------------------------------------------------------------------------
// TEST-104 — q ILIKE path (handle/email/display_name/phone/first_name/
// last_name/sector/subdomain). ILIKE is Postgres-only syntax; SQLite has no
// equivalent and no fake for it, so this is gated behind a real pgsql driver
// and SKIPS under composer test / CI. It asserts on returned data (which ids
// match which q value), not merely "no exception thrown".
// ---------------------------------------------------------------------------

it('q matches handle, email, display_name, and sector case-insensitively (pgsql only)', function () {
    if (! ovaSearchIsPostgres()) {
        $this->markTestSkipped('The q ILIKE path requires PostgreSQL; SQLite has no ILIKE equivalent.');
    }

    $byHandle = ovaSearchUser(['handle' => 'zqneedle-handle', 'sector' => 'sector-noise-a']);
    $byEmail = ovaSearchUser(['primary_email' => 'zqneedle-email@example.test', 'sector' => 'sector-noise-b']);
    $byDisplayName = ovaSearchUser(['display_name' => 'Zqneedle Display Name', 'sector' => 'sector-noise-c']);
    $bySector = ovaSearchUser(['sector' => 'zqneedle-sector']);
    ovaSearchUser(['sector' => 'totally-unrelated', 'handle' => 'bystander-'.Str::random(6)]);

    // Case-insensitive substring match against handle.
    expect(ovaSearchIds(['q' => 'ZQNEEDLE-HANDLE']))->toBe([$byHandle]);

    // Matches primary_email.
    expect(ovaSearchIds(['q' => 'zqneedle-email']))->toBe([$byEmail]);

    // Matches display_name, including the embedded space.
    expect(ovaSearchIds(['q' => 'Zqneedle Display']))->toBe([$byDisplayName]);

    // Matches sector — the OV-A addition to the q path.
    expect(ovaSearchIds(['q' => 'zqneedle-sector']))->toBe([$bySector]);

    // A query that matches nothing returns an empty result, not every row.
    expect(ovaSearchIds(['q' => 'zqneedle-does-not-exist-anywhere']))->toBe([]);
});
