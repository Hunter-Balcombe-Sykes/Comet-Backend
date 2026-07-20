<?php

use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Services\Auth\TokenRevocationService;
use App\Services\Cache\CacheLockService;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->originalLeeway = JWT::$leeway;

    config([
        'supabase.jwks_url' => 'https://proj.supabase.co/.well-known/jwks.json',
        'supabase.jwt_issuer' => 'https://proj.supabase.co/auth/v1',
        'supabase.jwt_audience' => 'authenticated',
        'supabase.jwks_fail_closed' => false,
    ]);
});

afterEach(function () {
    JWT::$leeway = $this->originalLeeway;
});

/**
 * Build a base64url-encoded RS256 JWT signed with $privPem.
 */
function buildRs256Jwt(string $kid, string $privPem, array $claims): string
{
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
    $sigInput = $header.'.'.$payload;
    openssl_sign($sigInput, $sig, $privPem, OPENSSL_ALGO_SHA256);

    return $sigInput.'.'.rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
}

/**
 * Build the JWK entry for an RSA public key (n/e extracted from $privKey).
 *
 * @return array{kty: string, kid: string, use: string, alg: string, n: string, e: string}
 */
function buildRsaJwk(string $kid, OpenSSLAsymmetricKey $privKey): array
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

// ── Leeway restoration ───────────────────────────────────────────────────────

it('restores JWT::$leeway to its prior value after successful JWKS verification', function () {
    $kid = 'test-kid-'.uniqid();
    $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($privKey, $privPem);

    $jwks = ['keys' => [buildRsaJwk($kid, $privKey)]];

    $jwt = buildRs256Jwt($kid, $privPem, [
        'sub' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'authenticated',
        'iat' => time(),
        'exp' => time() + 3600,
        // session_id present — this test targets leeway restoration, not the
        // SEC-2 no-session_id gate (covered in VerifySupabaseJwtSessionIdGateTest).
        'session_id' => 'sess-leeway',
    ]);

    $cacheLock = Mockery::mock(CacheLockService::class);
    $cacheLock->shouldReceive('rememberLocked')->andReturn($jwks);

    $revocation = Mockery::mock(TokenRevocationService::class);
    $revocation->shouldReceive('isRevoked')->andReturn(false);
    $revocation->shouldReceive('trackForUser');

    $middleware = new VerifySupabaseJwt($cacheLock, $revocation);

    JWT::$leeway = 42; // sentinel — must survive unchanged

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(200)
        ->and(JWT::$leeway)->toBe(42);
});

it('restores JWT::$leeway even when JWT::decode throws a signature error', function () {
    $kid = 'test-kid-'.uniqid();
    // JWKS holds the real public key...
    $realKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    // ...but the JWT is signed with a different private key → signature invalid
    $wrongKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($wrongKey, $wrongPem);

    $jwks = ['keys' => [buildRsaJwk($kid, $realKey)]];

    $jwt = buildRs256Jwt($kid, $wrongPem, [
        'sub' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'authenticated',
        'iat' => time(),
        'exp' => time() + 3600,
    ]);

    $cacheLock = Mockery::mock(CacheLockService::class);
    $cacheLock->shouldReceive('rememberLocked')->andReturn($jwks);

    $revocation = Mockery::mock(TokenRevocationService::class);
    $revocation->shouldReceive('isRevoked')->andReturn(false);
    $revocation->shouldReceive('trackForUser');

    // Auth-server fallback will fail too (no URL configured)
    config(['supabase.url' => '', 'supabase.anon_key' => '']);

    $middleware = new VerifySupabaseJwt($cacheLock, $revocation);

    JWT::$leeway = 42; // sentinel — must survive even when decode throws

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(401)
        ->and(JWT::$leeway)->toBe(42); // try/finally must have restored it
});
