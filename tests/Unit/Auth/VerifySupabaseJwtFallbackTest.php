<?php

use App\Exceptions\Auth\JwksUnavailableException;
use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Services\Auth\TokenRevocationService;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

// Build a minimal test JWT. Uses HS256 so the JWKS path rejects it at the
// algorithm check (before key resolution) — a token-level failure. Claims
// are readable in the payload.
function makeTestJwt(array $claims = []): string
{
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');

    return $header.'.'.$payload.'.fakesignature';
}

// Build a JWT with an asymmetric alg + kid so it passes the algorithm gate
// and reaches signing-key resolution → fetchJwks(). Used to exercise the
// JWKS-infrastructure failure path (as opposed to a token-level rejection).
function makeRs256TestJwt(array $claims = []): string
{
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'kid' => 'test-kid', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');

    return $header.'.'.$payload.'.fakesignature';
}

beforeEach(function () {
    // The middleware throttles JWKS-failure reports via a cache key; flush so
    // each test sees an un-throttled first failure.
    Cache::flush();

    config([
        'supabase.url' => 'https://proj.supabase.co',
        'supabase.anon_key' => 'anon-key',
        'supabase.jwks_url' => 'https://proj.supabase.co/.well-known/jwks.json',
        'supabase.jwt_issuer' => 'https://proj.supabase.co/auth/v1',
        'supabase.jwt_audience' => 'authenticated',
        'supabase.jwks_fail_closed' => false,
    ]);

    // Force JWKS to fail so every test exercises the fallback path.
    $cacheLock = Mockery::mock(CacheLockService::class);
    $cacheLock->shouldReceive('rememberLocked')
        ->andThrow(new RuntimeException('JWKS unavailable'));

    $revocation = Mockery::mock(TokenRevocationService::class);
    $revocation->shouldReceive('isRevoked')->andReturn(false);
    $revocation->shouldReceive('trackForUser');

    $this->middleware = new VerifySupabaseJwt($cacheLock, $revocation);
    $this->next = fn ($req) => response()->json(['ok' => true]);
});

// ── UUID validation ──────────────────────────────────────────────────────────

it('rejects auth-server response when user id is not a UUID', function () {
    $jwt = makeTestJwt([
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'authenticated',
    ]);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => 'not-a-uuid'], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
});

it('rejects auth-server response when user id is an empty string', function () {
    $jwt = makeTestJwt(['iss' => 'https://proj.supabase.co/auth/v1', 'aud' => 'authenticated']);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => ''], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
});

// ── Issuer validation ────────────────────────────────────────────────────────

it('rejects auth-server path when JWT issuer does not match config', function () {
    $jwt = makeTestJwt([
        'iss' => 'https://other-project.supabase.co/auth/v1',
        'aud' => 'authenticated',
    ]);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
});

// ── Audience validation ──────────────────────────────────────────────────────

it('rejects auth-server path when JWT audience does not match config', function () {
    $jwt = makeTestJwt([
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'wrong-audience',
    ]);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
});

// ── Misconfiguration fails closed (F2 AUTH-1) ────────────────────────────────

it('rejects every token when the configured issuer is blank', function () {
    config(['supabase.jwt_issuer' => '']);

    $jwt = makeTestJwt(['iss' => 'https://proj.supabase.co/auth/v1', 'aud' => 'authenticated']);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
    // Auth-Server is never consulted: claimsMatchConfig bails before the call.
    Http::assertNothingSent();
});

it('rejects every token when the configured audience is blank', function () {
    config(['supabase.jwt_audience' => '']);

    $jwt = makeTestJwt(['iss' => 'https://proj.supabase.co/auth/v1', 'aud' => 'authenticated']);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
    Http::assertNothingSent();
});

// ── Fail-closed feature flag ─────────────────────────────────────────────────

it('refuses fallback and returns 503 on a JWKS outage when jwks_fail_closed is true', function () {
    config(['supabase.jwks_fail_closed' => true]);

    // RS256 token reaches signing-key resolution → fetchJwks() → the mocked
    // rememberLocked throw → a genuine JWKS-infrastructure failure.
    $jwt = makeRs256TestJwt(['iss' => 'https://proj.supabase.co/auth/v1', 'aud' => 'authenticated']);

    // Auth-server should never be called.
    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(503);
    expect(json_decode($response->getContent(), true)['error'])->toBe('jwks_unavailable');
    Http::assertNothingSent();
});

it('returns 401 (not 503) for a token-level failure when jwks_fail_closed is true', function () {
    config(['supabase.jwks_fail_closed' => true]);

    // HS256 fails at the algorithm gate — a token-level failure, never a
    // JWKS outage. Fail-closed mode must still answer 401 so the client
    // re-authenticates rather than retrying a doomed request against a 503.
    $jwt = makeTestJwt(['iss' => 'https://proj.supabase.co/auth/v1', 'aud' => 'authenticated']);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
    expect(json_decode($response->getContent(), true)['error'])->toBe('unauthenticated');
    Http::assertNothingSent();
});

// ── JWKS infra failures are reported to Nightwatch (F2 AUTH-2) ───────────────

it('reports a JWKS-infrastructure failure to the exception handler', function () {
    Exceptions::fake();

    $jwt = makeRs256TestJwt(['iss' => 'https://proj.supabase.co/auth/v1', 'aud' => 'authenticated']);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $this->middleware->handle($request, $this->next);

    Exceptions::assertReported(JwksUnavailableException::class);
});

it('does NOT report a token-level failure to the exception handler', function () {
    Exceptions::fake();

    // HS256 fails at the algorithm gate — fetchJwks() is never reached, so
    // a bad/forged token can never spam Nightwatch alerts.
    $jwt = makeTestJwt(['iss' => 'https://proj.supabase.co/auth/v1', 'aud' => 'authenticated']);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $this->middleware->handle($request, $this->next);

    Exceptions::assertNothingReported();
});

// ── Fallback logging ─────────────────────────────────────────────────────────

it('logs a warning when auth-server fallback is invoked', function () {
    Log::spy();

    $uid = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    $jwt = makeTestJwt([
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'authenticated',
    ]);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => $uid], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $this->middleware->handle($request, $this->next);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => str_contains($msg, 'falling back to auth server'))
        ->once();
});

// ── Happy path: fallback succeeds ───────────────────────────────────────────

it('passes request through when auth-server returns valid UUID with correct claims', function () {
    $uid = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    $jwt = makeTestJwt([
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'authenticated',
    ]);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => $uid], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(200)
        ->and($request->attributes->get('supabase_uid'))->toBe($uid);
});
