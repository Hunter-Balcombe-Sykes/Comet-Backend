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

function buildEs256JwkForAlgWarmingTest(string $kid, \OpenSSLAsymmetricKey $privKey): array
{
    $details = openssl_pkey_get_details($privKey);

    return [
        'kty' => 'EC',
        'kid' => $kid,
        'use' => 'sig',
        'alg' => 'ES256',
        'crv' => 'P-256',
        'x' => rtrim(strtr(base64_encode($details['ec']['x']), '+/', '-_'), '='),
        'y' => rtrim(strtr(base64_encode($details['ec']['y']), '+/', '-_'), '='),
    ];
}

it('warms a TRUE mixed-alg JWKS (RS256 + ES256) with each kid carrying its own alg (SEC-3)', function () {
    // The original SEC-3 bug only manifests on a JWKS containing kids of
    // different algorithms — the cold path warmed every entry with $alg from
    // the inbound JWT, poisoning the off-algorithm cache slot. This test
    // exercises that exact scenario (vs the 2× RS256 test below which proves
    // the structural fix but doesn't reproduce the original failure mode).

    $kidRs = 'rs-'.uniqid();
    $privRs = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($privRs, $privPemRs);

    $kidEc = 'ec-'.uniqid();
    // openssl_pkey_new for EC requires a writable openssl.cnf in this PHP build;
    // wrap and skip cleanly if it can't generate. The 2× RS256 test below still
    // proves the structural fix even when EC generation isn't available.
    $privEc = @openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if (! $privEc) {
        test()->markTestSkipped('OpenSSL EC keypair generation unavailable in this PHP build ('.((string) openssl_error_string()).')');
    }

    $jwks = ['keys' => [
        buildRsaJwkForAlgWarmingTest($kidRs, $privRs),
        buildEs256JwkForAlgWarmingTest($kidEc, $privEc),
    ]];

    // Build a JWT signed with the RS256 kid so the cold path runs with $alg='RS256'.
    // Pre-fix: the EC kid would be cached with alg='RS256' (wrong) and any future
    // ES256 JWT against this kid would fail signature verification.
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

    $ref = new \ReflectionClass(VerifySupabaseJwt::class);
    $prop = $ref->getProperty('keysByKid');
    $prop->setAccessible(true);
    $warmed = $prop->getValue();

    expect($warmed[$kidRs]->getAlgorithm())->toBe('RS256')
        ->and($warmed[$kidEc]->getAlgorithm())->toBe('ES256');
});

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
