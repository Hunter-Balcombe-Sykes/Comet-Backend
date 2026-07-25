<?php

/**
 * Bundle B17 — #P2-02: Fresh-AAL2 gate on StaffUserController high-risk actions.
 *
 * Covers three endpoints that require a FRESH MFA verify within the staff window
 * (config partna.mfa.fresh_window_seconds, default 300s):
 *   PATCH  /api/staff/professionals/{id}/status  (updateStatus)
 *   POST   /api/staff/professionals/bulk-status  (bulkUpdateStatus)
 *   DELETE /api/staff/professionals/{id}/force   (forceDestroy)
 *
 * Also covers #P2-01: a verified JWT with no session_id emits Log::warning
 * with message 'jwt.missing_session_id'.
 */

use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\Auth\TokenRevocationService;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// Shared setup helpers
// ---------------------------------------------------------------------------

beforeEach(function () {
    setupPartnaStaffTable();
    setupUsersTable();
    // The staff.audit middleware (RecordStaffAuditEntry) runs after the response
    // and writes to audit.staff_audit_log — set up the table so terminate() does
    // not throw on SQLite, regardless of which HTTP status the gate returns.
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        user_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\',
        status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\',
        ip_hash TEXT,
        user_agent TEXT,
        created_at TEXT
    )');
});

/**
 * Build a PartnaStaff instance with role=admin (unsaved — we only need the model
 * for actingAsStaff + the EnsurePartnaAdmin middleware role check).
 */
function makeStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    // EnsurePartnaAdmin::handle checks $staff->isAdmin() on the partna_staff request
    // attribute. Set role so the admin gate passes and we reach the controller gate.
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

/**
 * Insert a User row suitable for use as a route-model-bound $professional.
 */
function makeProfessional(): User
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    $handle = 'pro-'.Str::random(6);
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return User::withTrashed()->findOrFail($id);
}

// ---------------------------------------------------------------------------
// #P2-02 — updateStatus gate tests
// ---------------------------------------------------------------------------

describe('updateStatus — fresh-AAL2 gate', function () {
    it('rejects with 401 + mfa_fresh_required when amr has stale MFA (3600s ago)', function () {
        $staff = makeStaff();
        $pro = makeProfessional();

        actingAsStaff($staff, aal2ClaimsWithFreshTotp(3600))
            ->patchJson("/api/staff/professionals/{$pro->id}/status", ['status' => 'suspended'])
            ->assertStatus(401)
            ->assertJsonFragment(['code' => 'mfa_fresh_required']);
    });

    it('rejects with 401 + mfa_fresh_required when there is no MFA amr entry at all', function () {
        $staff = makeStaff();
        $pro = makeProfessional();

        // Override amr to an empty array (no MFA entry).
        actingAsStaff($staff, ['aal' => 'aal2', 'amr' => []])
            ->patchJson("/api/staff/professionals/{$pro->id}/status", ['status' => 'suspended'])
            ->assertStatus(401)
            ->assertJsonFragment(['code' => 'mfa_fresh_required']);
    });

    it('allows the request when MFA is fresh (just verified)', function () {
        $staff = makeStaff();
        $pro = makeProfessional();

        // Default actingAsStaff provides a totp entry timestamped at time() — fresh.
        actingAsStaff($staff)
            ->patchJson("/api/staff/professionals/{$pro->id}/status", ['status' => 'suspended'])
            ->assertStatus(200);
    });

    it('allows the request when MFA was verified 0 seconds ago (explicit fresh)', function () {
        $staff = makeStaff();
        $pro = makeProfessional();

        actingAsStaff($staff, aal2ClaimsWithFreshTotp(0))
            ->patchJson("/api/staff/professionals/{$pro->id}/status", ['status' => 'suspended'])
            ->assertStatus(200);
    });
});

// ---------------------------------------------------------------------------
// #P2-02 — bulkUpdateStatus gate tests
// ---------------------------------------------------------------------------

describe('bulkUpdateStatus — fresh-AAL2 gate', function () {
    it('rejects with 401 + mfa_fresh_required when MFA is stale (3600s)', function () {
        $staff = makeStaff();

        actingAsStaff($staff, aal2ClaimsWithFreshTotp(3600))
            ->postJson('/api/staff/professionals/bulk-status', [
                'ids' => [(string) Str::uuid()],
                'status' => 'suspended',
            ])
            ->assertStatus(401)
            ->assertJsonFragment(['code' => 'mfa_fresh_required']);
    });

    it('rejects with 401 + mfa_fresh_required when amr has no MFA entry', function () {
        $staff = makeStaff();

        actingAsStaff($staff, ['aal' => 'aal2', 'amr' => []])
            ->postJson('/api/staff/professionals/bulk-status', [
                'ids' => [(string) Str::uuid()],
                'status' => 'suspended',
            ])
            ->assertStatus(401)
            ->assertJsonFragment(['code' => 'mfa_fresh_required']);
    });

    it('allows the request when MFA is fresh (default actingAsStaff)', function () {
        $staff = makeStaff();
        $pro = makeProfessional();

        // Default actingAsStaff → fresh totp. Pass a real ID so the DB update succeeds.
        actingAsStaff($staff)
            ->postJson('/api/staff/professionals/bulk-status', [
                'ids' => [$pro->id],
                'status' => 'suspended',
            ])
            ->assertStatus(200);
    });

    it('allows the request when MFA was verified 0 seconds ago (explicit fresh)', function () {
        $staff = makeStaff();
        $pro = makeProfessional();

        actingAsStaff($staff, aal2ClaimsWithFreshTotp(0))
            ->postJson('/api/staff/professionals/bulk-status', [
                'ids' => [$pro->id],
                'status' => 'suspended',
            ])
            ->assertStatus(200);
    });
});

// ---------------------------------------------------------------------------
// #P2-02 — forceDestroy gate tests
// ---------------------------------------------------------------------------

describe('forceDestroy — fresh-AAL2 gate', function () {
    beforeEach(function () {
        Http::fake(['*/auth/v1/admin/users/*' => Http::response('', 200)]);
    });

    it('rejects with 401 + mfa_fresh_required when MFA is stale (3600s)', function () {
        $staff = makeStaff();
        $pro = makeProfessional();

        actingAsStaff($staff, aal2ClaimsWithFreshTotp(3600))
            ->deleteJson("/api/staff/professionals/{$pro->id}/force", ['reason' => 'Force delete — ticket #123'])
            ->assertStatus(401)
            ->assertJsonFragment(['code' => 'mfa_fresh_required']);
    });

    it('rejects with 401 + mfa_fresh_required when there is no MFA amr entry', function () {
        $staff = makeStaff();
        $pro = makeProfessional();

        actingAsStaff($staff, ['aal' => 'aal2', 'amr' => []])
            ->deleteJson("/api/staff/professionals/{$pro->id}/force", ['reason' => 'Force delete — ticket #123'])
            ->assertStatus(401)
            ->assertJsonFragment(['code' => 'mfa_fresh_required']);
    });

    it('allows the request (passes the gate) when MFA is fresh', function () {
        $staff = makeStaff();
        $pro = makeProfessional();

        // Default actingAsStaff provides a fresh totp entry. The delete itself may
        // fail with 502 if the Supabase auth-delete call fails, but it will NOT be 401.
        $response = actingAsStaff($staff)
            ->deleteJson("/api/staff/professionals/{$pro->id}/force", ['reason' => 'Force delete — ticket #123']);

        expect($response->status())->not->toBe(401);
    });

    it('allows the request when MFA was verified 0 seconds ago (explicit fresh)', function () {
        $staff = makeStaff();
        $pro = makeProfessional();

        $response = actingAsStaff($staff, aal2ClaimsWithFreshTotp(0))
            ->deleteJson("/api/staff/professionals/{$pro->id}/force", ['reason' => 'Force delete — ticket #123']);

        expect($response->status())->not->toBe(401);
    });
});

// ---------------------------------------------------------------------------
// #P2-01 — VerifySupabaseJwt: log warning when session_id is missing
// ---------------------------------------------------------------------------

describe('VerifySupabaseJwt — missing session_id warning (#P2-01)', function () {
    beforeEach(function () {
        // Configure the middleware to fall through to the auth-server fallback
        // (JWKS fail-closed = false). The token uses HS256 so the JWKS path
        // always rejects it, landing us on the fallback path with extractJwtPayloadClaims.
        config([
            'supabase.url' => 'https://proj.supabase.co',
            'supabase.anon_key' => 'anon-key',
            'supabase.jwks_url' => 'https://proj.supabase.co/.well-known/jwks.json',
            'supabase.jwt_issuer' => 'https://proj.supabase.co/auth/v1',
            'supabase.jwt_audience' => 'authenticated',
            'supabase.jwks_fail_closed' => false,
        ]);

        $cacheLock = Mockery::mock(CacheLockService::class);
        $cacheLock->shouldReceive('rememberLocked')
            ->andThrow(new RuntimeException('JWKS unavailable'));

        $revocation = Mockery::mock(TokenRevocationService::class);
        $revocation->shouldReceive('isRevoked')->andReturn(false);
        $revocation->shouldReceive('trackForUser');

        $this->middleware = new VerifySupabaseJwt($cacheLock, $revocation);
    });

    it('emits Log::warning with jwt.missing_session_id when the fallback token has no session_id', function () {
        $uid = (string) Str::uuid();

        // Build an HS256 JWT with no session_id claim — JWKS path will throw,
        // auth-server fallback will succeed, then extractJwtPayloadClaims yields
        // no session_id → warning should fire.
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'sub' => $uid,
            'iss' => 'https://proj.supabase.co/auth/v1',
            'aud' => 'authenticated',
            // Deliberately no 'session_id' key
        ])), '+/', '-_'), '=');
        $jwt = $header.'.'.$payload.'.fakesignature';

        Http::fake([
            'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => $uid], 200),
        ]);

        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
        ]);

        Log::spy();

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['ok' => true]));

        // The JWKS failure also emits a warning — we assert ours fired at least once.
        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->withArgs(fn ($message, $context = []) => $message === 'jwt.missing_session_id'
                && isset($context['uid'])
                && $context['uid'] === $uid
            );

        // SEC-2: the warning above is now paired with a 401 rejection — a
        // session_id-less token can never be revoked, so it must not reach
        // $next(). require_session_id defaults true (not overridden here).
        expect($response->getStatusCode())->toBe(401)
            ->and($response->getData(true))->toMatchArray([
                'error' => 'unauthenticated',
                'code' => 'session_id_missing',
            ]);
    });

    it('passes through when require_session_id is disabled (incident kill-switch)', function () {
        config(['supabase.require_session_id' => false]);

        $uid = (string) Str::uuid();

        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'sub' => $uid,
            'iss' => 'https://proj.supabase.co/auth/v1',
            'aud' => 'authenticated',
            // Deliberately no 'session_id' key
        ])), '+/', '-_'), '=');
        $jwt = $header.'.'.$payload.'.fakesignature';

        Http::fake([
            'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => $uid], 200),
        ]);

        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
        ]);

        Log::spy();

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['ok' => true]));

        // The warning still fires — the flag only changes whether we reject,
        // never whether we log.
        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->withArgs(fn ($message, $context = []) => $message === 'jwt.missing_session_id'
                && isset($context['uid'])
                && $context['uid'] === $uid
            );

        expect($response->getStatusCode())->toBe(200);
    });
});
