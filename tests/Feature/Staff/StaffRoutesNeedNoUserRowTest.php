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

it('registers no staff route behind current.pro', function () {
    $router = app('router');

    $offenders = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->uri(), 'api/staff'))
        ->filter(fn ($route) => in_array(
            LoadCurrentUser::class,
            $router->gatherRouteMiddleware($route),
            true,
        ))
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($offenders)->toBe([]);

    // Guard the guard: if the prefix ever changes, an empty haystack would make
    // the assertion above vacuously true.
    $staffRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->uri(), 'api/staff'));
    expect($staffRoutes->count())->toBeGreaterThan(50);
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
