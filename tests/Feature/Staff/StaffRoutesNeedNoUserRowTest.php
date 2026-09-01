<?php

/**
 * Every staff route works off the core.partna_staff row ALONE.
 *
 * Staff UX used to be a user session with a staff overlay — /me needed a
 * core.users row, so staff held one, so nobody ever exercised a staff-only
 * session against the staff surface. Now that a staff account is meant to have
 * no professional profile at all, "the staff routes happen not to need one"
 * has to become an assertion rather than an accident.
 *
 * Three legs, because the failure modes are different:
 *   1. Route shape — no api/staff route may carry `current.pro`. That middleware
 *      403s a session with no core.users row, so ONE such route would lock a
 *      staff-only session out of that endpoint entirely, and reading the file is
 *      not proof (the three staff groups each declare their own stack; the
 *      revocation.strict incident is what happens when you trust the reading).
 *   2. Read end-to-end — a staff-only actor drives GET /staff/me through the
 *      REAL EnsurePartnaStaff, aal2 and revocation gates.
 *   3. Write + audit — RecordStaffAuditEntry attributes to the staff row. It
 *      accepts a null professional, but "accepts null" and "records the actor
 *      when the actor has no user row" are different claims.
 */

use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Http\Middleware\Context\LoadCurrentUser;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupPartnaStaffTable();
    setupSegmentsTables();
    DB::connection('pgsql')->statement('DELETE FROM core.users');
    DB::connection('pgsql')->statement('DELETE FROM core.partna_staff');
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY, staff_id TEXT, staff_email_snapshot TEXT,
        impersonator_staff_id TEXT, impersonator_email_snapshot TEXT, user_id TEXT,
        professional_handle_snapshot TEXT, route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\', status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\', ip_hash TEXT, user_agent TEXT, created_at TEXT
    )');
    DB::connection('pgsql')->statement('DELETE FROM audit.staff_audit_log');
});

/** A persisted staff row whose auth user has NO core.users row. */
function staffOnlyActor(string $role = 'admin'): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->forceFill([
        'id' => (string) Str::uuid(),
        'auth_user_id' => (string) Str::uuid(),
        'role' => $role,
        'primary_email' => 'staff-only@partna.au',
        'name' => 'Staff Only',
    ]);
    $staff->save();

    return $staff;
}

/** Stub ONLY the JWT verifier; EnsurePartnaStaff and the gates run for real. */
function actingAsStaffOnlyJwt(PartnaStaff $staff): void
{
    app()->bind(VerifySupabaseJwt::class, fn () => new class((string) $staff->auth_user_id, (string) $staff->primary_email)
    {
        public function __construct(private readonly string $uid, private readonly string $email) {}

        public function handle(Request $request, Closure $next)
        {
            $request->attributes->set('supabase_uid', $this->uid);
            $request->attributes->set('supabase_claims', [
                'sub' => $this->uid,
                'email' => $this->email,
                'email_verified' => true,
                'aal' => 'aal2',
                'amr' => [['method' => 'totp', 'timestamp' => time()]],
            ]);
            $request->attributes->set('supabase_aal', 'aal2');
            $request->attributes->set('supabase_amr', [['method' => 'totp', 'timestamp' => time()]]);
            $request->attributes->set('supabase_revocation_verified', true);

            return $next($request);
        }
    });
}

/**
 * Every spelling that actually reaches LoadCurrentUser.
 *
 * gatherMiddleware() returns middleware names as WRITTEN on the route —
 * 'current.pro', 'current.pro:staff_session_ok', 'user.api' — never the class
 * they alias to, and MiddlewareNameResolver cannot close that gap here: the
 * aliases are registered on the HTTP kernel in bootstrap/app.php, so inside the
 * test container the Router's own alias map is EMPTY and 'current.pro' resolves
 * to itself.
 *
 * This leg previously compared those strings against LoadCurrentUser::class — a
 * needle that can never appear in that haystack, making the assertion vacuously
 * true. It passed no matter what the routes said. Proved 2026-09-01 under the
 * mutation gate: adding 'current.pro' to the FIRST staff group left this test
 * green while the end-to-end leg below went red. The old "guard the guard"
 * checked the haystack was non-empty but never that the needle was findable,
 * which is the half that was actually missing.
 *
 * So match on the names as written. Three tokens reach LoadCurrentUser today:
 * the alias, the group that appends it, and the bare class for a direct
 * reference. The positive control at the end of the test fails loudly if this
 * set ever goes stale.
 */
function routeLoadsCurrentUser($route): bool
{
    $offendingTokens = ['current.pro', 'user.api', LoadCurrentUser::class];

    foreach ($route->gatherMiddleware() as $name) {
        if (! is_string($name)) {
            continue;
        }

        // Strip middleware parameters: 'current.pro:staff_session_ok' is still
        // LoadCurrentUser, and that parameterised form is the one GET /me uses.
        $token = Str::before($name, ':');

        if (in_array($token, $offendingTokens, true)) {
            return true;
        }
    }

    return false;
}

it('registers no staff route behind current.pro', function () {
    $staffRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->uri(), 'api/staff'));

    $offenders = $staffRoutes
        ->filter(fn ($route) => routeLoadsCurrentUser($route))
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($offenders)->toBe([]);

    // Guard the guard, part 1 — the HAYSTACK. If the prefix ever changes, the
    // assertion above would pass on an empty collection.
    expect($staffRoutes->count())->toBeGreaterThan(50);

    // Guard the guard, part 2 — the NEEDLE. The detector must be able to FIND
    // a route that is behind current.pro, or the assertion above means nothing.
    // This is the leg whose absence let the vacuous version ship.
    $me = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'api/me' && in_array('GET', $route->methods(), true));

    expect($me)->not->toBeNull()
        // GET /me carries the PARAMETERISED form ('current.pro:staff_session_ok'),
        // so this pins parameter-stripping too.
        ->and(routeLoadsCurrentUser($me))->toBeTrue();

    // ...and the 'user.api' group token, which is how the ordinary user routes
    // pick up current.pro. A detector blind to the group would report zero here.
    $nonStaffBehindCurrentPro = collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($route) => str_starts_with((string) $route->uri(), 'api/staff'))
        ->filter(fn ($route) => routeLoadsCurrentUser($route))
        ->count();

    expect($nonStaffBehindCurrentPro)->toBeGreaterThan(10);
});

it('answers GET /staff/me for an actor with no core.users row', function () {
    $staff = staffOnlyActor('admin');
    actingAsStaffOnlyJwt($staff);

    $this->getJson('/api/staff/me')
        ->assertOk()
        ->assertJsonPath('staff.id', (string) $staff->id)
        ->assertJsonPath('staff.role', 'admin')
        ->assertJsonPath('uid', (string) $staff->auth_user_id);

    expect(DB::connection('pgsql')->table('core.users')->count())->toBe(0);
});

it('records a staff write against the staff row when the actor has no user row', function () {
    $staff = staffOnlyActor('admin');
    actingAsStaffOnlyJwt($staff);

    $this->postJson('/api/staff/segments', [
        'name' => 'Audit probe',
        'definition' => ['type' => 'manual'],
    ])->assertSuccessful();

    $entry = DB::connection('pgsql')->table('audit.staff_audit_log')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->staff_id)->toBe((string) $staff->id)
        ->and($entry->staff_email_snapshot)->toBe('staff-only@partna.au')
        // No {professional} binding on this route and no user row for the actor —
        // the actor leg must never be smuggled into the target leg.
        ->and($entry->user_id)->toBeNull();
});
