<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupAuthFactorEventsTable();

    config([
        'supabase.admin.base_url' => 'https://test.supabase.co/auth/v1/admin',
        'supabase.service_role_key' => 'sr_test_key',
        'partna.mfa.unenroll_fresh_window_seconds' => 60,
    ]);
});

it('rejects unenroll when session is aal1', function () {
    $pro = createAffiliateTenant();

    actingAsUser($pro) // aal1
        ->deleteJson('/api/account/mfa/factors/'.Str::uuid())
        ->assertStatus(401)
        ->assertJsonPath('message', fn ($msg) => str_contains((string) $msg, 'MFA'));
});

it('rejects unenroll when most-recent totp is older than 60s', function () {
    $pro = createAffiliateTenant();

    actingAsUser($pro, aal2ClaimsWithFreshTotp(90)) // 90s old
        ->deleteJson('/api/account/mfa/factors/'.Str::uuid())
        ->assertStatus(401);
});

it('calls Supabase Admin API and records unenroll event when within 60s', function () {
    Http::fake([
        'test.supabase.co/*' => Http::response(['ok' => true], 200),
    ]);

    $pro = createAffiliateTenant();
    $factorId = (string) Str::uuid();

    actingAsUser($pro, aal2ClaimsWithFreshTotp(30)) // 30s old, inside 60s
        ->deleteJson("/api/account/mfa/factors/{$factorId}")
        ->assertOk();

    Http::assertSent(function ($request) use ($pro, $factorId) {
        return str_contains($request->url(), "/users/{$pro->auth_user_id}/factors/{$factorId}")
            && $request->method() === 'DELETE'
            && $request->hasHeader('Authorization', 'Bearer sr_test_key');
    });

    $event = DB::connection('pgsql')->table('audit.auth_factor_events')
        ->where('user_id', $pro->auth_user_id)
        ->where('event_type', 'unenroll')
        ->first();
    expect($event)->not->toBeNull();
    expect($event->factor_id)->toBe($factorId);
});

it('surfaces Supabase Admin API failure as 502', function () {
    Http::fake([
        'test.supabase.co/*' => Http::response(['error' => 'not found'], 404),
    ]);

    $pro = createAffiliateTenant();

    actingAsUser($pro, aal2ClaimsWithFreshTotp(30))
        ->deleteJson('/api/account/mfa/factors/'.Str::uuid())
        ->assertStatus(502);
});

// API-8 contract guards — these assert the exact FE-visible shape is preserved
// after routing through ApiController helpers.

it('API-8 contract: fresh-AAL2 rejection returns 401 with code=mfa_fresh_required (aal1 session)', function () {
    $pro = createAffiliateTenant('mfa-aal1-contract');

    $response = actingAsUser($pro) // aal1 — no amr totp entry
        ->deleteJson('/api/account/mfa/factors/'.Str::uuid())
        ->assertStatus(401);

    // FE reads `code` to distinguish MFA-gate errors from generic 401s.
    $response->assertJsonPath('code', 'mfa_fresh_required');
    $response->assertJsonStructure(['message', 'code']);
});

it('API-8 contract: stale-AAL2 rejection returns 401 with code=mfa_fresh_required', function () {
    $pro = createAffiliateTenant('mfa-stale-contract');

    $response = actingAsUser($pro, aal2ClaimsWithFreshTotp(90)) // 90s old, outside 60s window
        ->deleteJson('/api/account/mfa/factors/'.Str::uuid())
        ->assertStatus(401);

    $response->assertJsonPath('code', 'mfa_fresh_required');
});
