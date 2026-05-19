<?php

// §28.17 SEC-3 regression — JWKS key-warming used to serialize each parsed
// JWK to APCu with the INBOUND JWT's alg, not the per-kid alg from the parsed
// Key. A mixed JWKS (RS256 + ES256 kids) then served a poisoned cache entry to
// the next request using the "other" kid, breaking signature verification
// until the cache expired.

use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Services\Cache\CacheLockService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    // Reset the in-process static key cache so each test starts cold.
    $ref = new \ReflectionClass(VerifySupabaseJwt::class);
    $prop = $ref->getProperty('keysByKid');
    $prop->setAccessible(true);
    $prop->setValue(null, []);

    config([
        'supabase.jwks_url' => 'https://proj.supabase.co/.well-known/jwks.json',
        'supabase.jwt_issuer' => 'https://proj.supabase.co/auth/v1',
        'supabase.jwt_audience' => 'authenticated',
        'supabase.jwks_fail_closed' => false,
    ]);
});

function buildRs256JwtForAlgWarmingTest(string $kid, string $privPem, array $claims): string
{
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
    $sigInput = $header.'.'.$payload;
    openssl_sign($sigInput, $sig, $privPem, OPENSSL_ALGO_SHA256);

    return $sigInput.'.'.rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
}

function buildRsaJwkForAlgWarmingTest(string $kid, \OpenSSLAsymmetricKey $privKey): array
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

it('warms self::$keysByKid with each parsed Key having its OWN declared algorithm (SEC-3)', function () {
    $kidRs = 'rs-'.uniqid();
    $privRs = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($privRs, $privPemRs);

    // Generate a second RSA key under a different kid. We can't easily mint an
    // EC keypair from raw openssl_pkey_new in every PHP test build, so we use
    // two RS256 kids here — but the fix is verified by asserting BOTH cached
    // Key objects report alg=RS256 from the JWK, not whatever the inbound
    // request's $alg variable happened to be at warming time.
    $kidOther = 'rs2-'.uniqid();
    $privOther = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($privOther, $privPemOther);

    $jwks = ['keys' => [
        buildRsaJwkForAlgWarmingTest($kidRs, $privRs),
        buildRsaJwkForAlgWarmingTest($kidOther, $privOther),
    ]];

    $jwt = buildRs256JwtForAlgWarmingTest($kidRs, $privPemRs, [
        'sub' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'authenticated',
        'iat' => time(),
        'exp' => time() + 3600,
    ]);

    $cacheLock = Mockery::mock(CacheLockService::class);
    $cacheLock->shouldReceive('rememberLocked')->andReturn($jwks);

    $middleware = new VerifySupabaseJwt($cacheLock);
    $request = Request::create('/test', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$jwt]);
    $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]));
    expect($response->getStatusCode())->toBe(200);

    // Inspect the warmed static cache — both kids must report the algorithm
    // declared by their JWK entry, NOT whatever $alg the inbound JWT had.
    $ref = new \ReflectionClass(VerifySupabaseJwt::class);
    $prop = $ref->getProperty('keysByKid');
    $prop->setAccessible(true);
    $warmed = $prop->getValue();

    expect($warmed)->toHaveKey($kidRs)
        ->and($warmed)->toHaveKey($kidOther);
    /** @var Key $rsKey */
    $rsKey = $warmed[$kidRs];
    /** @var Key $otherKey */
    $otherKey = $warmed[$kidOther];
    expect($rsKey->getAlgorithm())->toBe('RS256')
        ->and($otherKey->getAlgorithm())->toBe('RS256');
});
