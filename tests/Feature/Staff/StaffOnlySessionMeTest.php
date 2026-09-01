<?php

/**
 * Staff-only sessions boot the dashboard from GET /api/me.
 *
 * Before 2026-09-01 a core.partna_staff row whose auth user had no core.users
 * row got 403 `bootstrap_required` from LoadCurrentUser — the frontend's cue to
 * push the caller back into signup. That is how the platform's own admin ended
 * up holding a professional profile and a published sitepage he never wanted:
 * the only way to boot the dashboard was to become a professional.
 *
 * These tests pin the three outcomes that ruling produced:
 *   1. staff-only + GET /me            → 200, session_type=staff, professional null
 *   2. staff-only + any other user route → 403 `staff_only_session` (NOT bootstrap_required)
 *   3. no staff row, no user row         → 403 `bootstrap_required`, unchanged
 *
 * The JWT middleware is stubbed (there is no signing key in tests) but
 * LoadCurrentUser runs FOR REAL — stubbing it, as actingAsUser() does, would
 * test nothing here.
 */

use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupPartnaStaffTable();
    DB::connection('pgsql')->statement('DELETE FROM core.users');
    DB::connection('pgsql')->statement('DELETE FROM core.partna_staff');
});

/**
 * Stub ONLY the JWT verifier. Everything downstream — require.email_verified,
 * current.pro, the throttle — runs as it does in production.
 */
function actingWithVerifiedJwt(string $uid, string $email): void
{
    app()->bind(VerifySupabaseJwt::class, fn () => new class($uid, $email)
    {
        public function __construct(private readonly string $uid, private readonly string $email) {}

        public function handle(Request $request, Closure $next)
        {
            $request->attributes->set('supabase_uid', $this->uid);
            $request->attributes->set('supabase_claims', [
                'sub' => $this->uid,
                'email' => $this->email,
                'email_verified' => true,
                'aal' => 'aal1',
            ]);
            $request->attributes->set('supabase_aal', 'aal1');
            $request->attributes->set('supabase_amr', []);
            $request->attributes->set('supabase_revocation_verified', true);

            return $next($request);
        }
    });
}

function makeStaffOnly(string $role = 'admin'): PartnaStaff
{
    $uid = (string) Str::uuid();

    $staff = new PartnaStaff;
    $staff->forceFill([
        'id' => (string) Str::uuid(),
        'auth_user_id' => $uid,
        'role' => $role,
        'primary_email' => 'ceo@example.test',
        'name' => 'Staff Owner',
    ]);
    $staff->save();

    return $staff;
}

it('answers GET /me for a staff-only session with a staff envelope', function () {
    $staff = makeStaffOnly('admin');
    actingWithVerifiedJwt((string) $staff->auth_user_id, (string) $staff->primary_email);

    $response = $this->getJson('/api/me');

    $response->assertOk()
        ->assertJsonPath('session_type', 'staff')
        ->assertJsonPath('professional', null)
        ->assertJsonPath('staff.id', (string) $staff->id)
        ->assertJsonPath('staff.role', 'admin')
        ->assertJsonPath('staff.primary_email', 'ceo@example.test')
        ->assertJsonPath('uid', (string) $staff->auth_user_id);

    // Every professional-shaped key the dashboard reads unconditionally is
    // present and empty — a 200 that omits half the contract is its own bug.
    $response->assertJsonPath('site', null)
        ->assertJsonPath('blocks', [])
        ->assertJsonPath('services', [])
        ->assertJsonPath('customers_count', 0);

    // The staff row must NOT have grown a professional profile as a side effect.
    expect(DB::connection('pgsql')->table('core.users')->count())->toBe(0);
});

it('refuses a staff-only session on a professional route with staff_only_session, not bootstrap_required', function () {
    $staff = makeStaffOnly('support');
    actingWithVerifiedJwt((string) $staff->auth_user_id, (string) $staff->primary_email);

    // PATCH /me deliberately stays in the strict group: there is no profile to update.
    $this->patchJson('/api/me', ['display_name' => 'Nope'])
        ->assertStatus(403)
        ->assertJsonPath('error', 'staff_only_session');
});

it('still answers bootstrap_required for an auth user with neither row', function () {
    actingWithVerifiedJwt((string) Str::uuid(), 'stranger@example.test');

    $this->getJson('/api/me')
        ->assertStatus(403)
        ->assertJsonPath('error', 'bootstrap_required');
});
