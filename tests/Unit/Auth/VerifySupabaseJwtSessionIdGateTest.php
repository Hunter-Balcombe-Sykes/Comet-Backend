<?php

use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Services\Auth\TokenRevocationService;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

/**
 * SEC-2: a validated JWT with no session_id can never be looked up by
 * TokenRevocationService::isRevoked(), so passing it through defeats "sign
 * out everywhere" and admin force-logout for the token's full TTL. These
 * tests cover the JWKS-verified path directly (the fail-closed default and
 * the require_session_id=false kill-switch); the mirrored auth-server
 * fallback path is covered in tests/Feature/Security/StaffUserControllerFreshAal2Test.php.
 */
beforeEach(function () {
    config([
        'supabase.jwks_url' => 'https://proj.supabase.co/.well-known/jwks.json',
        'supabase.jwt_issuer' => 'https://proj.supabase.co/auth/v1',
        'supabase.jwt_audience' => 'authenticated',
        'supabase.jwks_fail_closed' => true,
        'supabase.require_session_id' => true,
    ]);
});

/**
 * Build a base64url-encoded RS256 JWT signed with $privPem so it passes real
 * JWKS signature verification and reaches the session_id gate.
 */
function buildSessionIdGateRs256Jwt(string $kid, string $privPem, array $claims): string
{
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
    $sigInput = $header.'.'.$payload;
    openssl_sign($sigInput, $sig, $privPem, OPENSSL_ALGO_SHA256);

    return $sigInput.'.'.rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
}

/**
 * Build the JWK entry for an RSA public key (n/e extracted from $privKey).
 */
function buildSessionIdGateRsaJwk(string $kid, OpenSSLAsymmetricKey $privKey): array
{
    $details = openssl_pkey_get_details($privKey);

    return [
        'kty' => 'RSA',
        'kid' => $kid,
        'use' => 'sig',
        'alg' => 'RS256',
        'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
        'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
    ];
}

it('rejects a JWKS-verified token with no session_id with 401 and never reaches the revocation check', function () {
    Log::spy();

    $uid = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    $kid = 'test-kid-'.uniqid();
    $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($privKey, $privPem);

    $jwks = ['keys' => [buildSessionIdGateRsaJwk($kid, $privKey)]];

    $jwt = buildSessionIdGateRs256Jwt($kid, $privPem, [
        'sub' => $uid,
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'authenticated',
        'iat' => time(),
        'exp' => time() + 3600,
        // Deliberately no 'session_id' key.
    ]);

    $cacheLock = Mockery::mock(CacheLockService::class);
    $cacheLock->shouldReceive('rememberLocked')->andReturn($jwks);

    $revocation = Mockery::mock(TokenRevocationService::class);
    // The whole point of SEC-2: a session_id-less token must be rejected
    // BEFORE the revocation oracle is ever consulted — there's nothing to
    // look up, so calling it would be silently meaningless at best.
    $revocation->shouldNotReceive('isRevoked');
    $revocation->shouldNotReceive('trackForUser');

    $middleware = new VerifySupabaseJwt($cacheLock, $revocation);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(401);
    $body = json_decode($response->getContent(), true);
    expect($body)->toMatchArray([
        'error' => 'unauthenticated',
        'code' => 'session_id_missing',
    ]);

    // The rejection must be logged BEFORE it's enforced — this is the only
    // telemetry that would tell us this is firing in production.
    Log::shouldHaveReceived('warning')
        ->atLeast()->once()
        ->withArgs(fn ($message, $context = []) => $message === 'jwt.missing_session_id'
            && ($context['uid'] ?? null) === $uid
        );
});

it('passes a JWKS-verified token with no session_id through when require_session_id is disabled (kill-switch)', function () {
    config(['supabase.require_session_id' => false]);

    Log::spy();

    $uid = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    $kid = 'test-kid-'.uniqid();
    $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($privKey, $privPem);

    $jwks = ['keys' => [buildSessionIdGateRsaJwk($kid, $privKey)]];

    $jwt = buildSessionIdGateRs256Jwt($kid, $privPem, [
        'sub' => $uid,
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'authenticated',
        'iat' => time(),
        'exp' => time() + 3600,
        // Deliberately no 'session_id' key.
    ]);

    $cacheLock = Mockery::mock(CacheLockService::class);
    $cacheLock->shouldReceive('rememberLocked')->andReturn($jwks);

    $revocation = Mockery::mock(TokenRevocationService::class);
    $revocation->shouldNotReceive('isRevoked');
    $revocation->shouldReceive('trackForUser');

    $middleware = new VerifySupabaseJwt($cacheLock, $revocation);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]));

    // Kill-switch proven: same token, same missing claim, but this time it
    // reaches $next() because the flag is off.
    expect($response->getStatusCode())->toBe(200)
        ->and($request->attributes->get('supabase_uid'))->toBe($uid)
        ->and($request->attributes->get('supabase_session_id'))->toBeNull();

    // The warning still fires — disabling the gate must not disable the
    // telemetry that lets us tell the flag is doing something in production.
    Log::shouldHaveReceived('warning')
        ->atLeast()->once()
        ->withArgs(fn ($message, $context = []) => $message === 'jwt.missing_session_id'
            && ($context['uid'] ?? null) === $uid
        );
});
